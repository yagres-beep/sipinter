<?php

namespace App\Livewire;

use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\LogAktivitas;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Services\FolderStructureService;
use App\Support\AturanBuktiBagianKustom;
use App\Support\JejakAktivitasBagianKustom;
use App\Support\LanjutanBagianKustom;
use App\Support\MenuBagianKustom;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Isian bagian kustom di menu selain Isian Kegiatan — dipilih per tahun & bulan, lalu
 * per bagian diisi beberapa poin (tiap poin = satu IKU + uraian + kolom tambahan +
 * bukti dukung sesuai aturan bagiannya). Bagian mana saja yang tampil diatur di Data
 * Master > Bagian Kustom.
 *
 * Data dipisah per konteks menu + peran (lihat BagianKustomPoin::scopeKonteks()):
 * Tim SAKIP, Kepala, dan Ketua Tim tidak saling melihat atau menimpa poin, walau
 * bagiannya sama. Peran PEMBACA sebuah bagian hanya melihat isiannya (semua konteks),
 * tanpa tombol maupun aksi ubah.
 */
class IsianBagianKustom extends Component
{
    use WithFileUploads;

    /**
     * Kunci menu tempat komponen ini dipasang (lihat App\Support\MenuBagianKustom).
     * Ditetapkan server saat mount dan dikunci — tidak bisa diubah lewat request.
     */
    #[Locked]
    public string $menu = 'isian_bagian_kustom';

    public int $tahun;

    public int $bulan;

    /**
     * Baris poin per bagian, dikunci pada id bagian: [id, iku_id, teks, kolom,
     * lanjutan_dari_id]. lanjutan_dari_id hanya usulan dari layar — diperiksa ulang
     * di server saat simpan (LanjutanBagianKustom::sumberSah()).
     *
     * @var array<int, array<int, array{id: ?int, iku_id: ?string, teks: string, kolom: array<string, string>, lanjutan_dari_id?: ?int}>>
     */
    public array $baris = [];

    /**
     * Berkas bukti BARU per baris: [id bagian][indeks baris] => daftar unggahan.
     *
     * @var array<int, array<int, array<int, mixed>>>
     */
    public array $bukti = [];

    /**
     * Poin yang dimuat ke layar, per bagian: [id poin => sidik jari isinya]. Hanya
     * poin di sini yang boleh diubah/dihapus saat menyimpan, dan hanya bila isinya
     * belum diubah pengguna lain sejak dimuat.
     *
     * @var array<int, array<int, string>>
     */
    #[Locked]
    public array $dimuat = [];

    /** Periode tempat $dimuat diambil — simpan ditolak bila periode layar sudah berbeda. */
    #[Locked]
    public ?int $periodeDimuatId = null;

    public function mount(string $menu = 'isian_bagian_kustom'): void
    {
        // Menu harus menu katalog yang memang memakai komponen ini, dan bila halaman
        // dibuka lewat rute menu katalog, harus sama dengan menu rute tersebut.
        abort_unless(array_key_exists($menu, MenuBagianKustom::katalog()) && $menu !== MenuBagianKustom::PENGISIAN, 404);

        $menuRute = MenuBagianKustom::dariRoute(request()->route()?->getName());
        abort_if($menuRute !== null && $menuRute !== $menu, 403);

        $this->menu = $menu;
        $this->tahun = (int) now()->year;
        $this->bulan = (int) now()->month;

        $this->muat();
    }

    public function updatedTahun(): void
    {
        $this->muat();
    }

    public function updatedBulan(): void
    {
        $this->muat();
    }

    protected function peran(): ?string
    {
        return BagianKustom::peranDariNama(auth()->user()?->namaRole());
    }

    /**
     * Bagian yang tampil untuk peran yang login di menu ini. Kosong bila peran tidak
     * punya akses ke menu ini.
     *
     * @return Collection<int, BagianKustom>
     */
    protected function bagianUntukSaya()
    {
        $peran = $this->peran();

        if ($peran === null || ! in_array($peran, MenuBagianKustom::peranDiizinkan($this->menu), true)) {
            return collect();
        }

        return BagianKustom::daftarUntuk($peran, $this->menu);
    }

    /**
     * Bagian yang hanya boleh DIBACA peran yang login di menu ini.
     *
     * @return Collection<int, BagianKustom>
     */
    protected function bagianBacaUntukSaya()
    {
        $peran = $this->peran();

        if ($peran === null || ! in_array($peran, MenuBagianKustom::peranDiizinkan($this->menu), true)) {
            return collect();
        }

        return BagianKustom::daftarAktif()->filter(fn (BagianKustom $b) => $b->bisaDibacaDi($peran, $this->menu))->values();
    }

    /**
     * Bagian yang boleh diubah pengguna saat ini — dicek ulang dari peran terkini di
     * setiap aksi, jadi pengguna yang kehilangan hak akses saat halaman masih terbuka
     * tetap ditolak.
     */
    protected function bagianDiizinkanAtau403(int $bagianId): BagianKustom
    {
        $bagian = $this->bagianUntukSaya()->firstWhere('id', $bagianId);

        if (! $bagian) {
            JejakAktivitasBagianKustom::ditolak(BagianKustom::find($bagianId), 'peran pengguna tidak berwenang mengisi bagian ini di menu '.MenuBagianKustom::label($this->menu), ['menu' => $this->menu]);
            abort(403);
        }

        return $bagian;
    }

    /**
     * Tolak (403 + catatan log) bila peran yang login tidak punya hak $aksi di bagian ini
     * (matriks hak akses di Data Master). Dibaca dari definisi terkini setiap aksi.
     */
    protected function pastikanBoleh(BagianKustom $bagian, string $aksi, array $konteks = []): void
    {
        if ($bagian->bolehAksi($this->peran(), $aksi)) {
            return;
        }

        JejakAktivitasBagianKustom::ditolak($bagian, 'peran pengguna tidak punya hak "'.(BagianKustom::AKSI[$aksi] ?? $aksi).'" di bagian ini', ['menu' => $this->menu] + $konteks);
        abort(403);
    }

    /**
     * Periksa hak setiap perubahan yang akan disimpan sebelum apa pun ditulis: poin baru
     * (buat), poin lama yang isinya berubah (ubah), poin lama yang dibuang dari daftar
     * (hapus), dan berkas baru (unggah bukti).
     *
     * @param  array<int, string>  $dimuat
     */
    protected function pastikanHakSimpan(BagianKustom $bagian, Periode $periode, array $dimuat): void
    {
        $tersimpan = $dimuat === [] ? collect() : BagianKustomPoin::whereKey(array_keys($dimuat))->get()->keyBy('id');
        $triwulan = BagianKustom::triwulanDariBulan($this->bulan);
        $idDipertahankan = [];
        $konteks = ['periode_id' => $periode->id];

        foreach ($this->baris[$bagian->id] ?? [] as $i => $row) {
            $adaBerkasBaru = collect((array) ($this->bukti[$bagian->id][$i] ?? []))->contains(fn ($f) => $f instanceof \Illuminate\Http\UploadedFile);

            if ($adaBerkasBaru) {
                $this->pastikanBoleh($bagian, 'unggah_bukti', $konteks);
            }

            if (filled($row['id'] ?? null)) {
                $poin = $tersimpan->get((int) $row['id']);

                if (! $this->terisi($row)) {
                    continue;
                }

                $idDipertahankan[] = (int) $row['id'];

                if ($poin && (
                    (int) $row['iku_id'] !== (int) $poin->iku_id
                    || trim((string) $row['teks']) !== trim((string) $poin->teks)
                    || $bagian->bersihkanNilaiKolom($row['kolom'] ?? [], $poin->nilai_kolom, $this->tahun, $triwulan) != array_filter((array) ($poin->nilai_kolom ?? []), fn ($v) => trim((string) $v) !== '')
                )) {
                    $this->pastikanBoleh($bagian, 'ubah', $konteks + ['perubahan' => ['poin_id' => $poin->id]]);
                }
            } elseif ($this->terisi($row)) {
                $this->pastikanBoleh($bagian, 'buat', $konteks);
            }
        }

        if (array_diff(array_keys($dimuat), $idDipertahankan) !== []) {
            $this->pastikanBoleh($bagian, 'hapus', $konteks);
        }
    }

    protected function periode(): ?Periode
    {
        return Periode::where('tahun', $this->tahun)->where('bulan', $this->bulan)->first();
    }

    protected function barisKosong(BagianKustom $bagian): array
    {
        // lanjutan_dari_id hanya ditambahkan pada baris lanjutan (lihat lanjutkan()).
        return ['id' => null, 'iku_id' => null, 'teks' => '', 'kolom' => $bagian->nilaiKolomLengkap([])];
    }

    protected function terisi(array $row): bool
    {
        return trim((string) $row['teks']) !== ''
            || collect($row['kolom'] ?? [])->contains(fn ($v) => BagianKustom::nilaiFormTerisi($v));
    }

    public function muat(): void
    {
        $periode = $this->periode();
        $peran = $this->peran();
        $this->baris = [];
        $this->bukti = [];
        $this->dimuat = [];
        $this->periodeDimuatId = $periode?->id;

        foreach ($this->bagianUntukSaya() as $bagian) {
            $daftar = $periode
                ? BagianKustomPoin::konteks($this->menu, $peran)
                    ->where('bagian_kustom_id', $bagian->id)
                    ->where('periode_id', $periode->id)
                    ->orderBy('id')
                    ->get()
                : collect();

            $this->dimuat[$bagian->id] = $daftar->mapWithKeys(fn (BagianKustomPoin $p) => [$p->id => $p->sidikJari()])->all();

            $this->baris[$bagian->id] = $daftar->isEmpty()
                ? [$this->barisKosong($bagian)]
                : $daftar->map(fn (BagianKustomPoin $p) => [
                    'id' => $p->id,
                    'iku_id' => (string) $p->iku_id,
                    'teks' => (string) $p->teks,
                    'kolom' => $bagian->nilaiKolomUntukForm($p->nilai_kolom),
                ] + ($p->lanjutan_dari_id ? ['lanjutan_dari_id' => $p->lanjutan_dari_id] : []))->values()->all();
        }
    }

    public function tambahBaris(int $bagianId): void
    {
        $bagian = $this->bagianDiizinkanAtau403($bagianId);
        $this->pastikanBoleh($bagian, 'buat');

        $this->baris[$bagianId][] = $this->barisKosong($bagian);
    }

    public function hapusBaris(int $bagianId, int $index): void
    {
        $bagian = $this->bagianDiizinkanAtau403($bagianId);

        // Baris baru (belum tersimpan) selalu boleh dibuang dari layar.
        if (filled($this->baris[$bagianId][$index]['id'] ?? null)) {
            $this->pastikanBoleh($bagian, 'hapus');
        }

        unset($this->baris[$bagianId][$index]);
        $this->baris[$bagianId] = array_values($this->baris[$bagianId] ?? []) ?: [$this->barisKosong($bagian)];

        // Unggahan baru ikut bergeser bersama barisnya.
        $bukti = $this->bukti[$bagianId] ?? [];
        unset($bukti[$index]);
        $geser = [];

        foreach ($bukti as $i => $daftar) {
            $geser[$i > $index ? $i - 1 : $i] = $daftar;
        }

        $this->bukti[$bagianId] = $geser;
    }

    public function hapusBuktiBaru(int $bagianId, int $index, int $fileIndex): void
    {
        $this->bagianDiizinkanAtau403($bagianId);

        unset($this->bukti[$bagianId][$index][$fileIndex]);
        $this->bukti[$bagianId][$index] = array_values($this->bukti[$bagianId][$index] ?? []);
    }

    /**
     * Tambahkan poin periode sebelumnya sebagai isian awal poin BARU di periode ini
     * (belum tersimpan sampai tombol Simpan ditekan). Poin asal tidak diubah.
     */
    public function lanjutkan(int $bagianId, int $poinId): void
    {
        $bagian = $this->bagianDiizinkanAtau403($bagianId);
        $this->pastikanBoleh($bagian, 'buat');
        $periode = $this->periode();

        if (! $periode) {
            return;
        }

        if (collect($this->baris[$bagianId] ?? [])->contains(fn ($r) => (int) ($r['lanjutan_dari_id'] ?? 0) === $poinId)) {
            return;
        }

        $sumber = LanjutanBagianKustom::sumberSah($bagian, $poinId, $this->menu, $this->peran(), $periode);

        if (! $sumber) {
            $this->addError("baris.{$bagianId}", 'Poin tersebut tidak bisa dilanjutkan (sudah dilanjutkan ke periode ini atau bukan milik konteks Anda). Halaman dimuat ulang.');
            $this->muat();

            return;
        }

        $baru = [
            'id' => null,
            'iku_id' => (string) $sumber->iku_id,
            'teks' => (string) $sumber->teks,
            'kolom' => $bagian->nilaiKolomUntukForm($sumber->nilai_kolom),
            'lanjutan_dari_id' => $sumber->id,
        ];

        // Satu baris kosong bawaan diganti, bukan ditambah di bawahnya.
        $daftar = collect($this->baris[$bagianId] ?? [])->filter(fn ($r) => filled($r['id'] ?? null) || $this->terisi($r))->values()->all();
        $this->bukti[$bagianId] = count($daftar) === count($this->baris[$bagianId] ?? []) ? ($this->bukti[$bagianId] ?? []) : [];
        $this->baris[$bagianId] = [...$daftar, $baru];
    }

    public function lanjutkanSemua(int $bagianId): void
    {
        $bagian = $this->bagianDiizinkanAtau403($bagianId);
        $periode = $this->periode();

        if (! $periode) {
            return;
        }

        foreach (LanjutanBagianKustom::objekTerbuka($bagian, $this->menu, $this->peran(), $periode) as $sumber) {
            $this->lanjutkan($bagianId, $sumber->id);
        }
    }

    /**
     * Simpan satu bagian. Baris yang kosong tidak disimpan; poin yang DIMUAT layar ini
     * lalu dihapus dari daftar ikut dihapus dari database (kecuali yang sudah punya
     * bukti dukung atau sudah dilanjutkan ke periode lain). Poin di luar yang dimuat —
     * milik peran/menu lain, atau yang baru ditambahkan pengguna lain — tidak pernah
     * disentuh.
     */
    public function simpan(int $bagianId): void
    {
        $bagian = $this->bagianDiizinkanAtau403($bagianId);
        $peran = $this->peran();

        $this->resetErrorBag();

        $periode = $this->periode();

        if (! $periode) {
            $this->addError("baris.{$bagianId}", 'Periode bulan ini belum tersedia di sistem — hubungi admin.');

            return;
        }

        // Daftar poin yang dimuat harus berasal dari periode yang sama dengan yang
        // akan disimpan — mencegah poin satu bulan tersimpan/terhapus di bulan lain.
        if ($this->periodeDimuatId !== $periode->id || ! array_key_exists($bagianId, $this->dimuat)) {
            $this->muat();
            $this->addError("baris.{$bagianId}", 'Data di layar sudah tidak sesuai periode yang dipilih dan telah dimuat ulang. Periksa lalu simpan kembali.');

            return;
        }

        $dimuat = $this->dimuat[$bagianId];

        // Id poin hanya boleh id yang dimuat server untuk konteks ini, dan tidak ganda.
        $idDikirim = collect($this->baris[$bagianId] ?? [])->pluck('id')->filter(fn ($id) => $id !== null && $id !== '')->map(fn ($id) => (int) $id);

        if ($idDikirim->diff(array_keys($dimuat))->isNotEmpty() || $idDikirim->duplicates()->isNotEmpty()) {
            JejakAktivitasBagianKustom::ditolak($bagian, 'konteks data tidak sesuai (id poin di luar daftar yang dimuat untuk menu & peran ini, atau ganda)', [
                'menu' => $this->menu,
                'periode_id' => $periode->id,
                'perubahan' => ['id_poin_dikirim' => $idDikirim->values()->all()],
            ]);
            abort(403);
        }

        $this->pastikanHakSimpan($bagian, $periode, $dimuat);

        $aturan = $bagian->aturanBuktiDi($this->menu);
        $jumlahBuktiLama = Berkas::where('ref_type', BagianKustomPoin::class)->whereIn('ref_id', array_keys($dimuat))
            ->selectRaw('ref_id, count(*) as jumlah')->groupBy('ref_id')->pluck('jumlah', 'ref_id');
        $sumberLanjutan = [];
        $valid = true;

        foreach ($this->baris[$bagianId] ?? [] as $i => $row) {
            $berkasBaru = array_values(array_filter((array) ($this->bukti[$bagianId][$i] ?? []), fn ($f) => $f instanceof \Illuminate\Http\UploadedFile));

            if (! $this->terisi($row)) {
                if ($berkasBaru !== []) {
                    $this->addError("bukti.{$bagianId}.{$i}", 'Isi uraian poin ini dulu sebelum melampirkan bukti dukung.');
                    $valid = false;
                }

                continue;
            }

            if (blank($row['iku_id']) || ! MasterIku::whereKey($row['iku_id'])->exists()) {
                $this->addError("baris.{$bagianId}.{$i}.iku_id", 'Pilih IKU untuk poin ini.');
                $valid = false;
            }

            if (trim((string) $row['teks']) === '') {
                $this->addError("baris.{$bagianId}.{$i}.teks", 'Isi '.mb_strtolower($bagian->labelUraianEfektif()).' untuk poin ini.');
                $valid = false;
            }

            // Hanya kolom yang berlaku pada periode ini (aktif & sudah mulai berlaku).
            foreach ($bagian->kolomBerlaku($this->tahun, BagianKustom::triwulanDariBulan($this->bulan)) as $kolom) {
                $galat = BagianKustom::pesanGalatKolom($kolom, $row['kolom'][$kolom['kunci']] ?? '');

                if ($galat !== null) {
                    $this->addError("baris.{$bagianId}.{$i}.kolom.{$kolom['kunci']}", $galat);
                    $valid = false;
                }
            }

            // Bukti dukung: format & ukuran tiap berkas, lalu jumlahnya (lama + baru).
            foreach ($berkasBaru as $file) {
                if (AturanBuktiBagianKustom::dipakai($aturan) && ($galat = AturanBuktiBagianKustom::galatBerkas($aturan, $file)) !== null) {
                    $this->addError("bukti.{$bagianId}.{$i}", $galat);
                    $valid = false;
                }
            }

            $lama = filled($row['id'] ?? null) ? (int) ($jumlahBuktiLama[(int) $row['id']] ?? 0) : 0;

            if (($galat = AturanBuktiBagianKustom::galatJumlah($aturan, $lama, count($berkasBaru))) !== null) {
                $this->addError("bukti.{$bagianId}.{$i}", $galat);
                $valid = false;
            }

            // Lanjutan dari periode sebelumnya: hanya untuk poin baru, diperiksa ulang.
            if (blank($row['id'] ?? null) && filled($row['lanjutan_dari_id'] ?? null)) {
                $sumberId = (int) $row['lanjutan_dari_id'];
                $sumber = in_array($sumberId, array_keys($sumberLanjutan), true)
                    ? null
                    : LanjutanBagianKustom::sumberSah($bagian, $sumberId, $this->menu, $peran, $periode);

                if (! $sumber) {
                    $this->addError("baris.{$bagianId}.{$i}.teks", 'Poin asal dari periode sebelumnya sudah dilanjutkan atau tidak tersedia. Hapus baris ini lalu muat ulang halaman.');
                    $valid = false;
                } else {
                    $sumberLanjutan[$sumberId] = $sumber;
                }
            }
        }

        if (! $valid) {
            return;
        }

        $userId = auth()->id();
        $korelasi = LogAktivitas::korelasiBaru();
        $berkasTersimpan = [];
        $pathLokal = [];
        $tidakDihapus = 0;

        // Catatan log aktivitas ditulis di dalam transaksi yang sama: ikut batal bila
        // penyimpanan batal (bentrok atau galat).
        try {
            $berhasil = DB::transaction(function () use ($bagian, $bagianId, $periode, $peran, $dimuat, $userId, $korelasi, $sumberLanjutan, &$berkasTersimpan, &$pathLokal, &$tidakDihapus) {
                // Kunci baris yang dimuat layar ini, lalu pastikan belum diubah/dihapus
                // pengguna lain sejak dimuat — bila sudah, batalkan seluruh penyimpanan
                // supaya perubahan pengguna lain tidak tertimpa atau terhapus.
                $tersimpan = BagianKustomPoin::konteks($this->menu, $peran)
                    ->where('bagian_kustom_id', $bagian->id)
                    ->where('periode_id', $periode->id)
                    ->whereIn('id', array_keys($dimuat))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($dimuat as $id => $sidikJari) {
                    $poin = $tersimpan->get($id);

                    if (! $poin || $poin->sidikJari() !== $sidikJari) {
                        return false;
                    }
                }

                $idDisimpan = [];

                foreach ($this->baris[$bagianId] as $i => $row) {
                    if (! $this->terisi($row)) {
                        continue;
                    }

                    $poin = filled($row['id']) ? $tersimpan->get((int) $row['id']) : null;

                    $data = [
                        'iku_id' => (int) $row['iku_id'],
                        'teks' => trim((string) $row['teks']),
                        // Kolom nonaktif / belum berlaku tidak tampil di layar — nilai
                        // tersimpannya dipertahankan, bukan dihapus.
                        'nilai_kolom' => $bagian->bersihkanNilaiKolom($row['kolom'] ?? [], $poin?->nilai_kolom, $this->tahun, BagianKustom::triwulanDariBulan($this->bulan)),
                        'diubah_oleh' => $userId,
                    ];

                    if ($poin) {
                        $sebelum = JejakAktivitasBagianKustom::potret($poin);
                        $poin->update($data);
                        JejakAktivitasBagianKustom::poinDiubah($bagian, $poin, $sebelum, $korelasi);
                    } else {
                        $sumber = filled($row['lanjutan_dari_id'] ?? null) ? ($sumberLanjutan[(int) $row['lanjutan_dari_id']] ?? null) : null;

                        $poin = BagianKustomPoin::create($data + [
                            'bagian_kustom_id' => $bagian->id,
                            'periode_id' => $periode->id,
                            'menu' => $this->menu,
                            'peran_pengisi' => $peran,
                            'diisi_oleh' => $userId,
                            // Tidak ada alur verifikasi untuk isian di luar Isian Kegiatan — langsung dianggap sah.
                            'status_verifikasi' => 'terverifikasi',
                        ] + ($sumber ? LanjutanBagianKustom::atribut($sumber) : []));

                        $sumber
                            ? JejakAktivitasBagianKustom::poinDilanjutkan($bagian, $poin, $sumber, $korelasi)
                            : JejakAktivitasBagianKustom::poinDibuat($bagian, $poin, $korelasi);
                    }

                    $idDisimpan[] = $poin->id;

                    foreach (array_values((array) ($this->bukti[$bagianId][$i] ?? [])) as $file) {
                        if (! $file instanceof \Illuminate\Http\UploadedFile) {
                            continue;
                        }

                        $path = $file->store('bukti-bagian-kustom', 'local');
                        $pathLokal[] = $path;
                        $namaDasar = FolderStructureService::namaOtomatis(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 150)
                            ?: FolderStructureService::namaOtomatis($bagian->nama.' '.$poin->teks, 150);

                        $berkas = Berkas::create([
                            'ref_id' => $poin->id,
                            'ref_type' => BagianKustomPoin::class,
                            'kategori' => 'bagian_kustom',
                            'nama_file' => $namaDasar.'.'.AturanBuktiBagianKustom::ekstensiUnggahan($file),
                            'path' => $path,
                            // Sama dengan poinnya: menu ini tanpa alur verifikasi.
                            'status_verifikasi' => 'terverifikasi',
                        ]);

                        JejakAktivitasBagianKustom::buktiDiunggah($bagian, $poin, $berkas, false, $korelasi);
                        $berkasTersimpan[] = [$berkas, $poin];
                    }
                }

                // Hanya poin yang dimuat layar ini dan sengaja dihapus/dikosongkan pengguna.
                // Poin yang punya bukti dukung atau sudah dilanjutkan tidak dihapus.
                $idDihapus = array_diff(array_keys($dimuat), $idDisimpan);

                if ($idDihapus !== []) {
                    $dihapus = BagianKustomPoin::konteks($this->menu, $peran)
                        ->where('bagian_kustom_id', $bagian->id)
                        ->where('periode_id', $periode->id)
                        ->whereIn('id', $idDihapus)
                        ->whereDoesntHave('berkas')
                        ->whereDoesntHave('lanjutan')
                        ->get();

                    $tidakDihapus = count($idDihapus) - $dihapus->count();

                    BagianKustomPoin::whereKey($dihapus->modelKeys())->delete();

                    foreach ($dihapus as $poin) {
                        JejakAktivitasBagianKustom::poinDihapus($bagian, $poin, $korelasi);
                    }
                }

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // Poin asal yang sama baru saja dilanjutkan pengguna lain ke periode ini.
            $berhasil = false;
        } catch (\Throwable $e) {
            foreach ($pathLokal as $p) {
                Storage::disk('local')->delete($p);
            }

            throw $e;
        }

        if (! $berhasil) {
            foreach ($pathLokal as $p) {
                Storage::disk('local')->delete($p);
            }

            JejakAktivitasBagianKustom::ditolak($bagian, 'isian sudah diubah pengguna lain sejak halaman dimuat (bentrok penyimpanan); tidak ada data yang diubah', [
                'menu' => $this->menu,
                'periode_id' => $periode->id,
                'korelasi' => $korelasi,
            ]);

            // Poin BARU yang sedang diketik tetap dipertahankan di layar; perubahan pada
            // poin lama perlu diulang di atas data terbaru. Unggahan baru dipilih ulang.
            $barisBaru = collect($this->baris[$bagianId] ?? [])->filter(fn ($row) => blank($row['id']) && $this->terisi($row))->values()->all();

            $this->muat();

            if ($barisBaru !== []) {
                $lama = collect($this->baris[$bagianId] ?? [])->filter(fn ($row) => filled($row['id']))->values()->all();
                $this->baris[$bagianId] = [...$lama, ...$barisBaru];
            }

            $this->addError("baris.{$bagianId}", 'Isian "'.$bagian->nama.'" sudah diubah pengguna lain sejak halaman dimuat, jadi perubahan Anda belum disimpan. Data terbaru sudah dimuat ulang (poin baru yang Anda ketik tetap ada; pilih ulang berkas bukti bila ada) — periksa lalu simpan kembali.');

            return;
        }

        // Salinan Google Drive dibuat SETELAH transaksi berhasil. Gagal unggah tidak
        // membatalkan apa pun: berkas aman di server dan disusulkan berkas:sinkron-drive.
        $gagalDrive = 0;

        foreach ($berkasTersimpan as [$berkas, $poin]) {
            try {
                $berkas->setRelation('ref', $poin)->unggahKeDrive();
            } catch (\Throwable $e) {
                $gagalDrive++;
                Log::warning("Gagal mengunggah bukti Bagian Kustom {$berkas->kode} ke Google Drive: ".$e->getMessage());
            }
        }

        $this->muat();
        session()->flash('status-'.$bagianId, 'Isian "'.$bagian->nama.'" tersimpan.'
            .($berkasTersimpan !== [] ? ' '.count($berkasTersimpan).' bukti dukung diunggah.' : '')
            .($gagalDrive > 0 ? " {$gagalDrive} berkas belum tersalin ke Google Drive — tersimpan di server dan akan disalin otomatis." : '')
            .($tidakDihapus > 0 ? " {$tidakDihapus} poin tidak dihapus karena sudah punya bukti dukung atau sudah dilanjutkan ke periode lain." : ''));
    }

    /**
     * Hapus satu bukti dukung tersimpan milik poin di layar ini. Salinan Drive dipindah
     * ke Sampah (bisa dipulihkan), bukan dihapus permanen. Ditolak bila membuat poin
     * kurang dari jumlah minimal bukti wajib.
     */
    public function hapusBukti(int $bagianId, int $poinId, int $berkasId): void
    {
        $bagian = $this->bagianDiizinkanAtau403($bagianId);
        $this->pastikanBoleh($bagian, 'hapus_bukti');
        $periode = $this->periode();

        if (! $periode || $this->periodeDimuatId !== $periode->id || ! array_key_exists($poinId, $this->dimuat[$bagianId] ?? [])) {
            JejakAktivitasBagianKustom::ditolak($bagian, 'penghapusan bukti dukung untuk poin di luar daftar yang dimuat', ['menu' => $this->menu]);
            abort(403);
        }

        $aturan = $bagian->aturanBuktiDi($this->menu);
        $path = null;

        try {
            $hasil = DB::transaction(function () use ($bagian, $poinId, $berkasId, $periode, $aturan, &$path) {
                $poin = BagianKustomPoin::konteks($this->menu, (string) $this->peran())
                    ->where('bagian_kustom_id', $bagian->id)
                    ->where('periode_id', $periode->id)
                    ->lockForUpdate()
                    ->find($poinId);

                abort_unless($poin, 403);

                $berkas = Berkas::where('ref_type', BagianKustomPoin::class)->where('ref_id', $poin->id)->find($berkasId);

                if (! $berkas) {
                    return 'Bukti dukung tersebut sudah tidak ada.';
                }

                $sisa = Berkas::where('ref_type', BagianKustomPoin::class)->where('ref_id', $poin->id)->count() - 1;

                if ($aturan['mode'] === AturanBuktiBagianKustom::MODE_WAJIB && $sisa < $aturan['min']) {
                    return "Bukti ini tidak bisa dihapus: bagian ini mewajibkan minimal {$aturan['min']} bukti per poin. Unggah bukti pengganti lebih dulu, lalu hapus yang ini.";
                }

                JejakAktivitasBagianKustom::buktiDihapusPengisi($bagian, $poin, $berkas);
                $path = $berkas->path;
                $berkas->delete();

                // Drive terakhir: bila gagal, seluruh transaksi batal dan bukti tetap ada.
                $berkas->buangSalinanDrive(keSampah: true);

                return null;
            });
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning("Gagal menghapus bukti Bagian Kustom {$berkasId}: ".$e->getMessage());
            $this->addError("baris.{$bagianId}", 'Bukti dukung gagal dihapus (Google Drive tidak bisa dihubungi). Tidak ada yang berubah — coba lagi nanti.');

            return;
        }

        if ($hasil !== null) {
            $this->addError("baris.{$bagianId}", $hasil);

            return;
        }

        if ($path) {
            Storage::disk('local')->delete($path);
        }

        session()->flash('status-'.$bagianId, 'Bukti dukung dihapus. Salinan Google Drive-nya dipindah ke Sampah.');
    }

    public function render()
    {
        $bulanNama = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $periode = $this->periode();
        $peran = $this->peran();
        $bagianList = $this->bagianUntukSaya();
        $bagianBaca = $this->bagianBacaUntukSaya();

        $idDimuat = collect($this->dimuat)->flatMap(fn ($d) => array_keys($d))->all();
        $buktiTersimpan = $idDimuat === [] ? collect() : Berkas::where('ref_type', BagianKustomPoin::class)->whereIn('ref_id', $idDimuat)->orderBy('id')->get()->groupBy('ref_id');

        $idSumber = collect($this->baris)->flatten(1)->pluck('lanjutan_dari_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();
        $sumberLanjutan = $idSumber === [] ? collect() : BagianKustomPoin::with('periode')->whereKey($idSumber)->get()->keyBy('id');

        $objekTerbuka = [];
        $tersimpanPeriode = [];
        $bacaan = [];

        if ($periode) {
            foreach ($bagianList as $bagian) {
                $objekTerbuka[$bagian->id] = LanjutanBagianKustom::objekTerbuka($bagian, $this->menu, $peran, $periode);
                $tersimpanPeriode[$bagian->id] = BagianKustomPoin::konteks($this->menu, (string) $peran)
                    ->with(['masterIku', 'berkas'])->where('bagian_kustom_id', $bagian->id)->where('periode_id', $periode->id)->orderBy('id')->get();
            }

            // Pembaca: semua konteks pengisi bagian ini pada periode terpilih.
            foreach ($bagianBaca as $bagian) {
                $bacaan[$bagian->id] = BagianKustomPoin::with(['masterIku', 'berkas'])
                    ->where('bagian_kustom_id', $bagian->id)->where('periode_id', $periode->id)->orderBy('id')->get();
            }
        }

        return view('livewire.isian-bagian-kustom', [
            'bagianList' => $bagianList,
            'bagianBaca' => $bagianBaca,
            'ikuList' => MasterIku::daftarUrutKode(),
            'periodeAda' => $periode !== null,
            'namaBulan' => $bulanNama,
            'namaPeran' => BagianKustom::NAMA_PERAN[$peran] ?? '',
            'namaMenu' => MenuBagianKustom::label($this->menu),
            'tipeKolom' => BagianKustom::TIPE_KOLOM,
            'tahunPilihan' => range((int) now()->year - 2, (int) now()->year + 1),
            'buktiTersimpan' => $buktiTersimpan,
            'sumberLanjutan' => $sumberLanjutan,
            'objekTerbuka' => $objekTerbuka,
            'tersimpanPeriode' => $tersimpanPeriode,
            'bacaan' => $bacaan,
            'hakSaya' => $bagianList->mapWithKeys(fn (BagianKustom $b) => [$b->id => $b->petaAksi($peran)])->all(),
        ]);
    }
}
