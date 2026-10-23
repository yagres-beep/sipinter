<?php

namespace App\Livewire;

use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\LogAktivitas;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Support\JejakAktivitasBagianKustom;
use App\Support\MenuBagianKustom;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Isian bagian kustom di menu selain Isian Kegiatan — dipilih per tahun & bulan, lalu
 * per bagian diisi beberapa poin (tiap poin = satu IKU + uraian + kolom tambahan).
 * Bagian mana saja yang tampil diatur di Data Master > Bagian Kustom.
 *
 * Data dipisah per konteks menu + peran (lihat BagianKustomPoin::scopeKonteks()):
 * Tim SAKIP, Kepala, dan Ketua Tim tidak saling melihat atau menimpa poin, walau
 * bagiannya sama.
 */
class IsianBagianKustom extends Component
{
    /**
     * Kunci menu tempat komponen ini dipasang (lihat App\Support\MenuBagianKustom).
     * Ditetapkan server saat mount dan dikunci — tidak bisa diubah lewat request.
     */
    #[Locked]
    public string $menu = 'isian_bagian_kustom';

    public int $tahun;

    public int $bulan;

    /**
     * Baris poin per bagian, dikunci pada id bagian: [id, iku_id, teks, kolom].
     *
     * @var array<int, array<int, array{id: ?int, iku_id: ?string, teks: string, kolom: array<string, string>}>>
     */
    public array $baris = [];

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
     * Bagian yang boleh diubah pengguna saat ini — dicek ulang dari peran terkini di
     * setiap aksi, jadi pengguna yang kehilangan hak akses saat halaman masih terbuka
     * tetap ditolak.
     */
    protected function bagianDiizinkanAtau403(int $bagianId): BagianKustom
    {
        $bagian = $this->bagianUntukSaya()->firstWhere('id', $bagianId);
        abort_unless($bagian, 403);

        return $bagian;
    }

    protected function periode(): ?Periode
    {
        return Periode::where('tahun', $this->tahun)->where('bulan', $this->bulan)->first();
    }

    protected function barisKosong(BagianKustom $bagian): array
    {
        return ['id' => null, 'iku_id' => null, 'teks' => '', 'kolom' => $bagian->nilaiKolomLengkap([])];
    }

    protected function terisi(array $row): bool
    {
        return trim((string) $row['teks']) !== ''
            || collect($row['kolom'] ?? [])->contains(fn ($v) => trim((string) $v) !== '');
    }

    public function muat(): void
    {
        $periode = $this->periode();
        $peran = $this->peran();
        $this->baris = [];
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
                ])->values()->all();
        }
    }

    public function tambahBaris(int $bagianId): void
    {
        $bagian = $this->bagianDiizinkanAtau403($bagianId);

        $this->baris[$bagianId][] = $this->barisKosong($bagian);
    }

    public function hapusBaris(int $bagianId, int $index): void
    {
        $bagian = $this->bagianDiizinkanAtau403($bagianId);

        unset($this->baris[$bagianId][$index]);
        $this->baris[$bagianId] = array_values($this->baris[$bagianId] ?? []) ?: [$this->barisKosong($bagian)];
    }

    /**
     * Simpan satu bagian. Baris yang kosong tidak disimpan; poin yang DIMUAT layar ini
     * lalu dihapus dari daftar ikut dihapus dari database (kecuali yang sudah punya
     * bukti dukung). Poin di luar yang dimuat — milik peran/menu lain, atau yang baru
     * ditambahkan pengguna lain — tidak pernah disentuh.
     */
    public function simpan(int $bagianId): void
    {
        if (! $this->bagianUntukSaya()->contains('id', $bagianId)) {
            JejakAktivitasBagianKustom::ditolak(BagianKustom::find($bagianId), 'peran pengguna tidak berwenang mengisi bagian ini di menu '.MenuBagianKustom::label($this->menu), ['menu' => $this->menu]);
        }

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

        $valid = true;

        foreach ($this->baris[$bagianId] ?? [] as $i => $row) {
            if (! $this->terisi($row)) {
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
        }

        if (! $valid) {
            return;
        }

        $userId = auth()->id();
        $korelasi = LogAktivitas::korelasiBaru();

        // Catatan log aktivitas ditulis di dalam transaksi yang sama: ikut batal bila
        // penyimpanan batal (bentrok atau galat).
        $berhasil = DB::transaction(function () use ($bagian, $bagianId, $periode, $peran, $dimuat, $userId, $korelasi) {
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

            foreach ($this->baris[$bagianId] as $row) {
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
                    $poin = BagianKustomPoin::create($data + [
                        'bagian_kustom_id' => $bagian->id,
                        'periode_id' => $periode->id,
                        'menu' => $this->menu,
                        'peran_pengisi' => $peran,
                        'diisi_oleh' => $userId,
                        // Tidak ada alur verifikasi untuk isian di luar Isian Kegiatan — langsung dianggap sah.
                        'status_verifikasi' => 'terverifikasi',
                    ]);
                    JejakAktivitasBagianKustom::poinDibuat($bagian, $poin, $korelasi);
                }

                $idDisimpan[] = $poin->id;
            }

            // Hanya poin yang dimuat layar ini dan sengaja dihapus/dikosongkan pengguna.
            $idDihapus = array_diff(array_keys($dimuat), $idDisimpan);

            if ($idDihapus !== []) {
                $dihapus = BagianKustomPoin::konteks($this->menu, $peran)
                    ->where('bagian_kustom_id', $bagian->id)
                    ->where('periode_id', $periode->id)
                    ->whereIn('id', $idDihapus)
                    ->whereDoesntHave('berkas')
                    ->get();

                BagianKustomPoin::whereKey($dihapus->modelKeys())->delete();

                foreach ($dihapus as $poin) {
                    JejakAktivitasBagianKustom::poinDihapus($bagian, $poin, $korelasi);
                }
            }

            return true;
        });

        if (! $berhasil) {
            JejakAktivitasBagianKustom::ditolak($bagian, 'isian sudah diubah pengguna lain sejak halaman dimuat (bentrok penyimpanan); tidak ada data yang diubah', [
                'menu' => $this->menu,
                'periode_id' => $periode->id,
                'korelasi' => $korelasi,
            ]);

            // Poin BARU yang sedang diketik tetap dipertahankan di layar; perubahan pada
            // poin lama perlu diulang di atas data terbaru.
            $barisBaru = collect($this->baris[$bagianId] ?? [])->filter(fn ($row) => blank($row['id']) && $this->terisi($row))->values()->all();

            $this->muat();

            if ($barisBaru !== []) {
                $lama = collect($this->baris[$bagianId] ?? [])->filter(fn ($row) => filled($row['id']))->values()->all();
                $this->baris[$bagianId] = [...$lama, ...$barisBaru];
            }

            $this->addError("baris.{$bagianId}", 'Isian "'.$bagian->nama.'" sudah diubah pengguna lain sejak halaman dimuat, jadi perubahan Anda belum disimpan. Data terbaru sudah dimuat ulang (poin baru yang Anda ketik tetap ada) — periksa lalu simpan kembali.');

            return;
        }

        $this->muat();
        session()->flash('status-'.$bagianId, 'Isian "'.$bagian->nama.'" tersimpan.');
    }

    public function render()
    {
        $bulanNama = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        return view('livewire.isian-bagian-kustom', [
            'bagianList' => $this->bagianUntukSaya(),
            'ikuList' => MasterIku::daftarUrutKode(),
            'periodeAda' => $this->periode() !== null,
            'namaBulan' => $bulanNama,
            'namaPeran' => BagianKustom::NAMA_PERAN[$this->peran()] ?? '',
            'namaMenu' => MenuBagianKustom::label($this->menu),
            'tipeKolom' => BagianKustom::TIPE_KOLOM,
            'tahunPilihan' => range((int) now()->year - 2, (int) now()->year + 1),
        ]);
    }
}
