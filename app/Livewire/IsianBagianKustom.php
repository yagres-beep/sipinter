<?php

namespace App\Livewire;

use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\LogAktivitas;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Services\FolderStructureService;
use App\Support\AlurBagianKustom;
use App\Support\AturanBuktiBagianKustom;
use App\Support\JejakAktivitasBagianKustom;
use App\Support\KelengkapanBagianKustom;
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

    /**
     * Catatan pemeriksa per poin (wajib saat mengembalikan untuk diperbaiki).
     *
     * @var array<int, string>
     */
    public array $catatanPeriksa = [];

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

        return BagianKustom::daftarUntuk($peran, $this->menu)->filter(fn (BagianKustom $b) => $this->berlakuSekarang($b))->values();
    }

    protected function triwulan(): int
    {
        return BagianKustom::triwulanDariBulan($this->bulan);
    }

    /** Bagian tampil pada tahun & triwulan yang dipilih di layar (pengaturan periode berlaku). */
    protected function berlakuSekarang(BagianKustom $bagian): bool
    {
        return $bagian->berlakuPada($this->tahun, $this->triwulan());
    }

    /**
     * Bagian beralur verifikasi yang boleh DIPERIKSA peran yang login di menu ini.
     *
     * @return Collection<int, BagianKustom>
     */
    protected function bagianPeriksaUntukSaya()
    {
        $peran = $this->peran();

        if ($peran === null || ! in_array($peran, MenuBagianKustom::peranDiizinkan($this->menu), true)) {
            return collect();
        }

        return BagianKustom::daftarAktif()->filter(function (BagianKustom $b) use ($peran) {
            $alur = $b->alurKerja();

            return AlurBagianKustom::pakaiVerifikasi($alur)
                && in_array($peran, $alur['pemeriksa'], true)
                && in_array($this->menu, $b->penempatan ?? [], true)
                && $this->berlakuSekarang($b);
        })->values();
    }

    /** Peran yang login boleh mengajukan isian bagian ini (pengaturan alur). */
    protected function bolehAjukan(BagianKustom $bagian): bool
    {
        $alur = $bagian->alurKerja();

        return AlurBagianKustom::pakaiVerifikasi($alur) && in_array($this->peran(), $alur['pengaju'], true);
    }

    /**
     * Isi baris layar berbeda dari poin tersimpan (IKU, uraian, atau nilai kolom).
     *
     * @param  array<string, mixed>  $row
     */
    protected function barisBerubah(BagianKustom $bagian, array $row, BagianKustomPoin $poin): bool
    {
        return (int) $row['iku_id'] !== (int) $poin->iku_id
            || trim((string) $row['teks']) !== trim((string) $poin->teks)
            || $bagian->bersihkanNilaiKolom($row['kolom'] ?? [], $poin->nilai_kolom, $this->tahun, $this->triwulan()) != array_filter((array) ($poin->nilai_kolom ?? []), fn ($v) => trim((string) $v) !== '');
    }

    /**
     * Pesan bila layar mengubah, menghapus, atau menambah bukti pada poin yang sedang
     * terkunci alur (diajukan, atau disetujui tanpa izin ubah). Null bila aman.
     *
     * @param  array<int, string>  $dimuat
     */
    protected function galatKunciAlur(BagianKustom $bagian, array $dimuat): ?string
    {
        $alur = $bagian->alurKerja();

        if (! AlurBagianKustom::pakaiVerifikasi($alur) || $dimuat === []) {
            return null;
        }

        $tersimpan = BagianKustomPoin::whereKey(array_keys($dimuat))->get()->keyBy('id');
        $dipertahankan = [];

        foreach ($this->baris[$bagian->id] ?? [] as $i => $row) {
            $poin = filled($row['id'] ?? null) ? $tersimpan->get((int) $row['id']) : null;

            if (! $poin) {
                continue;
            }

            $status = AlurBagianKustom::statusPoin($poin);
            $terkunci = ! AlurBagianKustom::bisaDiubahPengisi($status, $alur);

            if ($this->terisi($row)) {
                $dipertahankan[] = $poin->id;
            }

            $berkasBaru = collect((array) ($this->bukti[$bagian->id][$i] ?? []))->contains(fn ($f) => $f instanceof \Illuminate\Http\UploadedFile);

            if ($terkunci && ($berkasBaru || ($this->terisi($row) && $this->barisBerubah($bagian, $row, $poin)))) {
                return 'Poin '.($i + 1).' berstatus "'.AlurBagianKustom::labelStatus($status, $alur).'" sehingga tidak bisa diubah'.($status === AlurBagianKustom::DIAJUKAN ? ' — tarik pengajuan lebih dulu bila perlu memperbaiki.' : '.');
            }
        }

        foreach ($tersimpan as $poin) {
            if (! in_array($poin->id, $dipertahankan, true) && ! AlurBagianKustom::bisaDiubahPengisi(AlurBagianKustom::statusPoin($poin), $alur)) {
                return 'Poin yang sudah diajukan atau disetujui tidak bisa dihapus.';
            }
        }

        return null;
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

        return BagianKustom::daftarAktif()->filter(fn (BagianKustom $b) => $b->bisaDibacaDi($peran, $this->menu) && $this->berlakuSekarang($b))->values();
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

                if ($poin && $this->barisBerubah($bagian, $row, $poin)) {
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

        if (($galatKunci = $this->galatKunciAlur($bagian, $dimuat)) !== null) {
            JejakAktivitasBagianKustom::ditolak($bagian, 'perubahan pada poin yang terkunci alur kerja', ['menu' => $this->menu, 'periode_id' => $periode->id]);
            $this->addError("baris.{$bagianId}", $galatKunci);

            return;
        }

        $alur = $bagian->alurKerja();
        $verifikasi = AlurBagianKustom::pakaiVerifikasi($alur);
        // Jumlah minimal bukti yang baru diperiksa saat diajukan/disetujui tidak
        // menghalangi penyimpanan draf.
        $aturan = AturanBuktiBagianKustom::untukSimpan($bagian->aturanBuktiDi($this->menu));
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

            // Hanya kolom yang berlaku pada periode ini dan tidak disembunyikan syaratnya;
            // termasuk subbagian wajib (aturan yang sama dengan Isian Kegiatan).
            foreach ($bagian->galatNilaiPoin($row['kolom'] ?? [], $this->tahun, $this->triwulan()) as $kunci => $galat) {
                $this->addError("baris.{$bagianId}.{$i}.kolom.{$kunci}", $galat);
                $valid = false;
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
            $berhasil = DB::transaction(function () use ($bagian, $bagianId, $periode, $peran, $dimuat, $userId, $korelasi, $sumberLanjutan, $verifikasi, &$berkasTersimpan, &$pathLokal, &$tidakDihapus) {
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
                        $berubah = $this->barisBerubah($bagian, $row, $poin);
                        $sebelum = JejakAktivitasBagianKustom::potret($poin);

                        // Alur langsung: isian sah begitu disimpan. Alur verifikasi: poin
                        // disetujui yang diubah (bila diizinkan) kembali menjadi draf.
                        if (! $verifikasi && $poin->status_verifikasi !== 'terverifikasi') {
                            $data += ['status_verifikasi' => 'terverifikasi', 'diajukan_pada' => null, 'diajukan_oleh' => null];
                        } elseif ($verifikasi && $berubah && AlurBagianKustom::statusPoin($poin) === AlurBagianKustom::DISETUJUI) {
                            $data += ['status_verifikasi' => 'menunggu', 'diajukan_pada' => null, 'diajukan_oleh' => null];
                        }

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
                            // Alur langsung: langsung sah. Alur verifikasi: draf sampai diajukan.
                            'status_verifikasi' => $verifikasi ? 'menunggu' : 'terverifikasi',
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
                            // Mengikuti alur poinnya: diperiksa bersama poin bila beralur verifikasi.
                            'status_verifikasi' => $verifikasi ? 'menunggu' : 'terverifikasi',
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

                if (! AlurBagianKustom::bisaDiubahPengisi(AlurBagianKustom::statusPoin($poin), $bagian->alurKerja())) {
                    return 'Bukti ini tidak bisa dihapus karena poinnya sedang diajukan atau sudah disetujui.';
                }

                $sisa = Berkas::where('ref_type', BagianKustomPoin::class)->where('ref_id', $poin->id)->count() - 1;

                if ($aturan['mode'] === AturanBuktiBagianKustom::MODE_WAJIB && $aturan['saat'] === AturanBuktiBagianKustom::SAAT_SIMPAN && $sisa < $aturan['min']) {
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

    /* ---------- Alur kerja: ajukan, tarik, setujui, kembalikan ---------- */

    /** Layar berisi perubahan yang belum disimpan untuk bagian ini. */
    protected function adaPerubahanBelumDisimpan(BagianKustom $bagian): bool
    {
        $dimuat = $this->dimuat[$bagian->id] ?? [];
        $tersimpan = $dimuat === [] ? collect() : BagianKustomPoin::whereKey(array_keys($dimuat))->get()->keyBy('id');
        $dipertahankan = [];

        foreach ($this->baris[$bagian->id] ?? [] as $i => $row) {
            if (collect((array) ($this->bukti[$bagian->id][$i] ?? []))->contains(fn ($f) => $f instanceof \Illuminate\Http\UploadedFile)) {
                return true;
            }

            if (filled($row['id'] ?? null)) {
                $poin = $tersimpan->get((int) $row['id']);

                if (! $this->terisi($row) || ($poin && $this->barisBerubah($bagian, $row, $poin))) {
                    return true;
                }

                $dipertahankan[] = (int) $row['id'];
            } elseif ($this->terisi($row)) {
                return true;
            }
        }

        return array_diff(array_keys($dimuat), $dipertahankan) !== [];
    }

    /**
     * Ajukan semua poin draf / dikembalikan milik konteks pengguna (menu + peran) pada
     * periode ini. Isian & bukti diperiksa ulang dari data TERSIMPAN dengan aturan terkini.
     */
    public function ajukan(int $bagianId): void
    {
        $bagian = $this->bagianDiizinkanAtau403($bagianId);
        $this->resetErrorBag();

        if (! $this->bolehAjukan($bagian)) {
            JejakAktivitasBagianKustom::ditolak($bagian, 'peran pengguna tidak berwenang mengajukan isian bagian ini', ['menu' => $this->menu]);
            abort(403);
        }

        $periode = $this->periode();

        if (! $periode || $this->periodeDimuatId !== $periode->id || ! array_key_exists($bagianId, $this->dimuat)) {
            $this->muat();
            $this->addError("baris.{$bagianId}", 'Data di layar sudah tidak sesuai periode yang dipilih dan telah dimuat ulang. Periksa lalu ajukan kembali.');

            return;
        }

        if ($this->adaPerubahanBelumDisimpan($bagian)) {
            $this->addError("baris.{$bagianId}", 'Ada perubahan yang belum disimpan. Klik Simpan lebih dulu, lalu Ajukan.');

            return;
        }

        $aturan = $bagian->aturanBuktiDi($this->menu);
        $dimuat = $this->dimuat[$bagianId];
        $peran = (string) $this->peran();
        $korelasi = LogAktivitas::korelasiBaru();

        $hasil = DB::transaction(function () use ($bagian, $periode, $peran, $dimuat, $aturan, $korelasi) {
            $daftar = BagianKustomPoin::konteks($this->menu, $peran)
                ->where('bagian_kustom_id', $bagian->id)->where('periode_id', $periode->id)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($dimuat as $id => $sidikJari) {
                if (! $daftar->has($id) || $daftar[$id]->sidikJari() !== $sidikJari) {
                    return ['galat' => 'Isian sudah diubah pengguna lain sejak halaman dimuat. Data terbaru sudah dimuat ulang — periksa lalu ajukan kembali.', 'muat' => true];
                }
            }

            $nomor = array_flip($daftar->keys()->all());
            $siap = $daftar->filter(fn (BagianKustomPoin $p) => AlurBagianKustom::bolehTransisi(AlurBagianKustom::statusPoin($p), 'ajukan'));

            if ($siap->isEmpty()) {
                return ['galat' => 'Tidak ada poin berstatus draf atau dikembalikan yang bisa diajukan.'];
            }

            $galat = [];
            $jumlahBukti = Berkas::where('ref_type', BagianKustomPoin::class)->whereIn('ref_id', $siap->keys())
                ->selectRaw('ref_id, count(*) as jumlah')->groupBy('ref_id')->pluck('jumlah', 'ref_id');

            foreach ($siap as $p) {
                $no = 'Poin '.($nomor[$p->id] + 1);

                if (! $p->iku_id || trim((string) $p->teks) === '') {
                    $galat[] = "{$no}: IKU dan ".mb_strtolower($bagian->labelUraianEfektif()).' wajib diisi.';
                }

                foreach ($bagian->galatNilaiPoin($bagian->nilaiKolomUntukForm($p->nilai_kolom), $this->tahun, $this->triwulan()) as $pesan) {
                    $galat[] = "{$no}: {$pesan}";
                }

                if ($aturan['saat'] !== AturanBuktiBagianKustom::SAAT_VERIFIKASI && ($g = AturanBuktiBagianKustom::galatMinimal($aturan, (int) ($jumlahBukti[$p->id] ?? 0))) !== null) {
                    $galat[] = "{$no}: {$g}.";
                }
            }

            if ($galat !== []) {
                return ['galat' => 'Belum bisa diajukan — '.implode(' ', array_slice($galat, 0, 6)).(count($galat) > 6 ? ' (dan '.(count($galat) - 6).' lainnya)' : '')];
            }

            foreach ($siap as $p) {
                $statusSebelum = AlurBagianKustom::statusPoin($p);
                $p->update(['status_verifikasi' => 'menunggu', 'diajukan_pada' => now(), 'diajukan_oleh' => auth()->id()]);
                JejakAktivitasBagianKustom::poinBeralur($bagian, $p, 'diajukan', $statusSebelum, $korelasi);
            }

            return ['jumlah' => $siap->count()];
        });

        if (isset($hasil['galat'])) {
            if ($hasil['muat'] ?? false) {
                $this->muat();
            }

            $this->addError("baris.{$bagianId}", $hasil['galat']);

            return;
        }

        $this->muat();
        session()->flash('status-'.$bagianId, $hasil['jumlah'].' poin "'.$bagian->nama.'" diajukan dan menunggu pemeriksaan. Poin yang diajukan terkunci sampai diperiksa.');
    }

    /** Tarik kembali pengajuan yang belum diperiksa (diajukan → draf) agar bisa diperbaiki. */
    public function tarikPengajuan(int $bagianId): void
    {
        $bagian = $this->bagianDiizinkanAtau403($bagianId);
        $this->resetErrorBag();

        if (! $this->bolehAjukan($bagian)) {
            JejakAktivitasBagianKustom::ditolak($bagian, 'peran pengguna tidak berwenang menarik pengajuan bagian ini', ['menu' => $this->menu]);
            abort(403);
        }

        $periode = $this->periode();

        if (! $periode) {
            return;
        }

        $korelasi = LogAktivitas::korelasiBaru();

        $jumlah = DB::transaction(function () use ($bagian, $periode, $korelasi) {
            $diajukan = BagianKustomPoin::konteks($this->menu, (string) $this->peran())
                ->where('bagian_kustom_id', $bagian->id)->where('periode_id', $periode->id)
                ->lockForUpdate()->get()
                ->filter(fn (BagianKustomPoin $p) => AlurBagianKustom::bolehTransisi(AlurBagianKustom::statusPoin($p), 'tarik'));

            foreach ($diajukan as $p) {
                $p->update(['status_verifikasi' => 'menunggu', 'diajukan_pada' => null, 'diajukan_oleh' => null]);
                JejakAktivitasBagianKustom::poinBeralur($bagian, $p, 'ditarik', AlurBagianKustom::DIAJUKAN, $korelasi);
            }

            return $diajukan->count();
        });

        $this->muat();

        $jumlah > 0
            ? session()->flash('status-'.$bagian->id, "{$jumlah} poin ditarik kembali menjadi draf dan bisa diperbaiki.")
            : $this->addError("baris.{$bagian->id}", 'Tidak ada poin yang sedang diajukan (mungkin sudah diperiksa).');
    }

    public function setujui(int $poinId): void
    {
        $this->periksa($poinId, 'setujui');
    }

    public function kembalikan(int $poinId): void
    {
        $this->periksa($poinId, 'kembalikan');
    }

    /**
     * Pemeriksa menyetujui atau mengembalikan satu poin. Server memastikan: peran
     * termasuk pemeriksa bagian ini, poin milik menu & periode layar ini, transisi status
     * sah, dan (untuk setujui) jumlah minimal bukti terpenuhi.
     */
    protected function periksa(int $poinId, string $aksi): void
    {
        $this->resetErrorBag();
        $poin = BagianKustomPoin::find($poinId);
        $bagian = $poin ? $this->bagianPeriksaUntukSaya()->firstWhere('id', $poin->bagian_kustom_id) : null;
        $periode = $this->periode();

        if (! $poin || ! $bagian || $poin->menu !== $this->menu || ! $periode || (int) $poin->periode_id !== $periode->id) {
            JejakAktivitasBagianKustom::ditolak($poin?->bagianKustom, 'peran pengguna tidak berwenang memeriksa poin ini di menu '.MenuBagianKustom::label($this->menu), ['menu' => $this->menu, 'objek_id' => $poinId]);
            abort(403);
        }

        $catatan = trim((string) ($this->catatanPeriksa[$poinId] ?? ''));

        if ($aksi === 'kembalikan' && $catatan === '') {
            $this->addError("periksa.{$poinId}", 'Tulis catatan perbaikan sebelum mengembalikan poin ini.');

            return;
        }

        if (mb_strlen($catatan) > 1000) {
            $this->addError("periksa.{$poinId}", 'Catatan maksimal 1000 karakter.');

            return;
        }

        $aturan = $bagian->aturanBuktiDi($this->menu);

        $galat = DB::transaction(function () use ($poinId, $aksi, $catatan, $aturan, $bagian) {
            $p = BagianKustomPoin::whereKey($poinId)->lockForUpdate()->first();
            $status = AlurBagianKustom::statusPoin($p);

            if (! AlurBagianKustom::bolehTransisi($status, $aksi)) {
                JejakAktivitasBagianKustom::ditolak($bagian, 'transisi status tidak sah: '.$aksi.' dari "'.$status.'"', ['menu' => $this->menu, 'objek_id' => $p->id]);

                return 'Poin ini berstatus "'.AlurBagianKustom::labelStatus($status, $bagian->alurKerja()).'" sehingga tidak bisa '.($aksi === 'setujui' ? 'disetujui' : 'dikembalikan').'.';
            }

            if ($aksi === 'setujui' && ($g = AturanBuktiBagianKustom::galatMinimal($aturan, $p->berkas()->count())) !== null) {
                return 'Belum bisa disetujui: '.$g.'.';
            }

            [$statusSebelum, $catatanSebelum] = [$p->status_verifikasi, $p->catatan];

            $p->update([
                'status_verifikasi' => $aksi === 'setujui' ? 'terverifikasi' : 'ditolak',
                'catatan' => $aksi === 'setujui' ? null : $catatan,
                'diperiksa_oleh' => auth()->id(),
                'diperiksa_pada' => now(),
            ]);

            if ($aksi === 'setujui') {
                Berkas::where('ref_type', BagianKustomPoin::class)->where('ref_id', $p->id)->where('status_verifikasi', 'menunggu')
                    ->update(['status_verifikasi' => 'terverifikasi']);
            }

            JejakAktivitasBagianKustom::poinDitandai($p, $statusSebelum, $catatanSebelum);

            return null;
        });

        if ($galat !== null) {
            $this->addError("periksa.{$poinId}", $galat);

            return;
        }

        unset($this->catatanPeriksa[$poinId]);
        session()->flash('periksa-'.$bagian->id, $aksi === 'setujui' ? 'Poin disetujui.' : 'Poin dikembalikan ke pengisi dengan catatan perbaikan.');
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

        // Pemeriksa: poin yang sudah diajukan (bukan draf) dari semua pengisi di menu ini.
        $bagianPeriksa = $this->bagianPeriksaUntukSaya();
        $periksaan = [];

        foreach ($bagianPeriksa as $bagian) {
            $periksaan[$bagian->id] = $periode
                ? BagianKustomPoin::with(['masterIku', 'berkas'])
                    ->where('bagian_kustom_id', $bagian->id)->where('periode_id', $periode->id)->where('menu', $this->menu)
                    ->orderBy('id')->get()
                    ->filter(fn (BagianKustomPoin $p) => AlurBagianKustom::statusPoin($p) !== AlurBagianKustom::DRAF)->values()
                : collect();
        }

        // Status alur tiap poin yang dimuat (untuk lencana & penguncian formulir di layar).
        $poinDimuat = collect($tersimpanPeriode)->flatten(1)->keyBy('id');
        $statusPoin = $poinDimuat->map(fn (BagianKustomPoin $p) => AlurBagianKustom::statusPoin($p))->all();

        [$diMenuLain, $alasanKosong] = $this->menu === 'isian_bagian_kustom'
            ? $this->diagnosis($peran, $bagianList->concat($bagianBaca)->concat($bagianPeriksa)->pluck('id')->all())
            : [[], []];

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
            'alurBagian' => $bagianList->concat($bagianPeriksa)->mapWithKeys(fn (BagianKustom $b) => [$b->id => $b->alurKerja()])->all(),
            'bolehAjukanSaya' => $bagianList->mapWithKeys(fn (BagianKustom $b) => [$b->id => $this->bolehAjukan($b)])->all(),
            'statusPoin' => $statusPoin,
            'poinDimuat' => $poinDimuat,
            'bagianPeriksa' => $bagianPeriksa,
            'periksaan' => $periksaan,
            'diMenuLain' => $diMenuLain,
            'alasanKosong' => $alasanKosong,
        ]);
    }

    /**
     * Penjelasan untuk halaman Isian Bagian Kustom: (1) bagian aktif yang dipakai peran
     * ini di MENU LAIN (dengan tautan ke menu itu), dan (2) alasan tidak ada bagian di
     * halaman ini — dibedakan: tidak ada yang aktif, tidak ditempatkan di menu ini,
     * bukan untuk peran ini, atau tidak berlaku pada periode terpilih. Baca-saja.
     *
     * @param  array<int, int>  $idTampil  bagian yang sudah tampil di halaman ini
     * @return array{0: array<int, array{bagian: BagianKustom, tautan: array<int, array<string, string>>}>, 1: array<int, string>}
     */
    protected function diagnosis(?string $peran, array $idTampil): array
    {
        $aktif = BagianKustom::daftarAktif();
        $diMenuLain = [];

        if ($peran !== null) {
            foreach ($aktif as $b) {
                if (in_array($b->id, $idTampil, true) || ! $this->berlakuSekarang($b)) {
                    continue;
                }

                $tautan = KelengkapanBagianKustom::tautanMenu($b, $peran, $this->menu);

                if ($tautan !== []) {
                    $diMenuLain[] = ['bagian' => $b, 'tautan' => $tautan];
                }
            }
        }

        if ($idTampil !== []) {
            return [$diMenuLain, []];
        }

        $namaPeran = BagianKustom::NAMA_PERAN[$peran] ?? 'Anda';
        $diMenuIni = $aktif->filter(fn (BagianKustom $b) => in_array($this->menu, $b->penempatan ?? [], true));
        $alasan = [];

        if ($aktif->isEmpty()) {
            $alasan[] = 'Belum ada bagian kustom yang berstatus aktif.';
        } elseif ($diMenuIni->isEmpty()) {
            $alasan[] = 'Ada '.$aktif->count().' bagian kustom aktif, tetapi tidak ada yang ditempatkan di menu '.MenuBagianKustom::label($this->menu).'.';
        } else {
            $untukSaya = $diMenuIni->filter(fn (BagianKustom $b) => $peran !== null && ($b->tampilDi($peran, $this->menu) || $b->bisaDibacaDi($peran, $this->menu) || KelengkapanBagianKustom::menuPeriksa($b, $peran) !== []));
            $tanpaHak = $diMenuIni->count() - $untukSaya->count();
            $luarPeriode = $untukSaya->reject(fn (BagianKustom $b) => $this->berlakuSekarang($b));

            if ($tanpaHak > 0) {
                $alasan[] = $tanpaHak.' bagian di menu ini tidak diberikan kepada peran '.$namaPeran.' (bukan pengisi, pembaca, maupun pemeriksa).';
            }

            foreach ($luarPeriode as $b) {
                $alasan[] = '"'.$b->nama.'" tidak berlaku pada TW '.['I', 'II', 'III', 'IV'][$this->triwulan() - 1].' '.$this->tahun.' (berlaku: '.$b->labelPeriodeBerlaku().'). Pilih periode lain.';
            }
        }

        return [$diMenuLain, $alasan];
    }
}
