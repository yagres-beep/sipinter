<?php

namespace App\Livewire;

use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi as KendalaSolusiModel;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\RtlEvaluasi as RtlEvaluasiModel;
use App\Services\FolderStructureService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Ketua Tim — Isian Kegiatan (satu alur, sesuai mockup S4): IKU → Kegiatan →
 * Kendala & Solusi → Evaluasi RTL triwulan sebelumnya → RTL triwulan berikutnya,
 * diajukan bersamaan lewat satu tombol "Ajukan ke Tim SAKIP". Menggabungkan logika
 * yang semula terpisah di PengisianKegiatan, KendalaSolusi, dan RtlEvaluasi.
 */
class PengisianKegiatan extends Component
{
    use WithFileUploads;

    public int $tahun;

    public int $bulan;

    public ?int $iku_id = null;

    /**
     * @var array<int, array{uraian_kegiatan: string, jenis: string, tahapan_survei: ?string, bukti: array}>
     */
    public array $blocks = [];

    /**
     * @var array<int, array{kendala: string, solusi: string, bukti_solusi: array}>
     */
    public array $kendalaBlocks = [];

    /**
     * Poin isian untuk tiap bagian kustom aktif (mis. Manajemen Risiko), dikunci pada
     * id bagian_kustom — satu bagian bisa punya banyak poin, sama seperti kendalaBlocks.
     *
     * @var array<int, array<int, array{teks: string, bukti: array}>>
     */
    public array $bagianKustomBlocks = [];

    /**
     * Form realisasi per poin RTL triwulan sebelumnya yang belum dievaluasi, dikunci pada id baris rtl_evaluasi.
     *
     * @var array<int, array{realisasi: string, status_cocok: string, bukti: array}>
     */
    public array $evaluasi = [];

    /**
     * Blok RTL baru untuk triwulan berikutnya — hanya dipakai bila belum pernah ditetapkan untuk triwulan itu.
     * PIC, batas waktu, dan bulan berlaku (seluruh bulan triwulan berikutnya) berlaku SATU untuk seluruh
     * poin dalam satu triwulan, bukan per poin — cukup ditampilkan sebagai keterangan di judul bagian.
     *
     * @var array<int, array{rtl_teks: string}>
     */
    public array $rtlBaru = [];

    public string $rtlBaruPic = '';

    /**
     * Dipilih dari dropdown penanggung jawab IKU; diisi manual hanya bila memilih "Lainnya".
     */
    public string $rtlBaruPicManual = '';

    public string $rtlBaruBatasWaktu = '';

    /**
     * Cache dalam satu siklus request saja (di-reset otomatis tiap request baru karena
     * properti non-public tidak ikut disinkronkan Livewire) — beberapa query berat
     * (RTL berjalan, IKU terpilih, dst.) dipakai ulang di banyak tempat saat render()
     * DAN formLengkap() dipanggil di request yang sama; tanpa cache ini query yang sama
     * terulang belasan kali per klik/blur dan bikin halaman terasa lambat.
     */
    protected ?\Illuminate\Support\Collection $cacheRtlBerjalan = null;

    protected ?\Illuminate\Support\Collection $cacheRtlSebelumnyaList = null;

    protected ?bool $cacheRtlBerikutnyaSudahAda = null;

    protected ?MasterIku $cacheIkuTerpilih = null;

    protected bool $cacheIkuTerpilihDihitung = false;

    protected ?\Illuminate\Support\Collection $cacheRtlBerjalanTerpakaiIds = null;

    public function mount(): void
    {
        $this->tahun = (int) now()->year;
        $this->bulan = (int) now()->month;
        $this->blocks = [$this->emptyBlock()];
        $this->kendalaBlocks = [$this->emptyKendalaBlock()];
        $this->rtlBaru = [$this->emptyRtlBlock()];
        $this->rtlBaruBatasWaktu = $this->akhirTriwulanBerikutnya()->toDateString();

        foreach ($this->bagianKustomAktif() as $bagian) {
            $this->bagianKustomBlocks[$bagian->id] = [$this->emptyBagianKustomBlock()];
        }
    }

    protected function emptyBlock(): array
    {
        return [
            'uraian_kegiatan' => '',
            'jenis' => '',
            'tahapan_survei' => '',
            'bukti' => [],
        ];
    }

    protected function emptyKendalaBlock(): array
    {
        return [
            'kendala' => '',
            'solusi' => '',
            'bukti_solusi' => [],
        ];
    }

    protected function emptyRtlBlock(): array
    {
        return [
            'rtl_teks' => '',
        ];
    }

    protected function emptyBagianKustomBlock(): array
    {
        return [
            'teks' => '',
            'bukti' => [],
        ];
    }

    /**
     * Daftar bagian kustom aktif — dipakai untuk merender bagian tambahan di form
     * (mis. Manajemen Risiko) dan untuk validasi/penyimpanannya.
     *
     * @return \Illuminate\Support\Collection<int, BagianKustom>
     */
    protected function bagianKustomAktif()
    {
        return BagianKustom::daftarAktif();
    }

    /**
     * Pratinjau nama folder Drive otomatis untuk satu blok kegiatan (RF-17, RF-22) —
     * dihitung sama seperti nama_folder_auto yang benar-benar disimpan saat diajukan,
     * supaya ketua tim tahu persis nama folder sebelum mengajukan.
     */
    public function namaFolderPreview(int $index): string
    {
        $block = $this->blocks[$index] ?? null;

        if (! $block || trim($block['uraian_kegiatan']) === '') {
            return '';
        }

        $tahapan = $block['jenis'] === 'survei_sensus' ? $block['tahapan_survei'] : null;

        return Str::limit(($tahapan ? '['.ucfirst($tahapan).'] ' : '').$block['uraian_kegiatan'], 100, '');
    }

    /**
     * Alur: IKU dulu, baru bagian lain (Kegiatan/Kendala/Evaluasi/RTL) bisa diisi bebas urutannya.
     */
    public function ikuDipilih(): bool
    {
        return filled($this->iku_id);
    }

    /**
     * Di dalam satu blok Kegiatan, tiap kolom terkunci sampai kolom sebelumnya terisi
     * (uraian → jenis → tahapan survei → bukti).
     */
    public function jenisTerkunci(int $index): bool
    {
        return trim($this->blocks[$index]['uraian_kegiatan'] ?? '') === '';
    }

    public function tahapanTerkunci(int $index): bool
    {
        return $this->jenisTerkunci($index) || blank($this->blocks[$index]['jenis'] ?? '');
    }

    public function buktiTerkunci(int $index): bool
    {
        $block = $this->blocks[$index] ?? null;

        if (! $block || blank($block['jenis'] ?? '')) {
            return true;
        }

        if ($block['jenis'] === 'survei_sensus') {
            return blank($block['tahapan_survei'] ?? '');
        }

        return false;
    }

    public function addBlock(): void
    {
        $this->blocks[] = $this->emptyBlock();
    }

    public function removeBlock(int $index): void
    {
        unset($this->blocks[$index]);
        $this->blocks = array_values($this->blocks);
    }

    public function removeBuktiKegiatan(int $blockIndex, int $fileIndex): void
    {
        unset($this->blocks[$blockIndex]['bukti'][$fileIndex]);
        $this->blocks[$blockIndex]['bukti'] = array_values($this->blocks[$blockIndex]['bukti']);
    }

    public function addKendalaBlock(): void
    {
        $this->kendalaBlocks[] = $this->emptyKendalaBlock();
    }

    public function removeKendalaBlock(int $index): void
    {
        unset($this->kendalaBlocks[$index]);
        $this->kendalaBlocks = array_values($this->kendalaBlocks);
    }

    public function removeBuktiSolusi(int $blockIndex, int $fileIndex): void
    {
        unset($this->kendalaBlocks[$blockIndex]['bukti_solusi'][$fileIndex]);
        $this->kendalaBlocks[$blockIndex]['bukti_solusi'] = array_values($this->kendalaBlocks[$blockIndex]['bukti_solusi']);
    }

    public function addBagianKustomBlock(int $bagianId): void
    {
        $this->bagianKustomBlocks[$bagianId][] = $this->emptyBagianKustomBlock();
    }

    public function removeBagianKustomBlock(int $bagianId, int $index): void
    {
        unset($this->bagianKustomBlocks[$bagianId][$index]);
        $this->bagianKustomBlocks[$bagianId] = array_values($this->bagianKustomBlocks[$bagianId]);
    }

    public function removeBuktiBagianKustom(int $bagianId, int $blockIndex, int $fileIndex): void
    {
        unset($this->bagianKustomBlocks[$bagianId][$blockIndex]['bukti'][$fileIndex]);
        $this->bagianKustomBlocks[$bagianId][$blockIndex]['bukti'] = array_values($this->bagianKustomBlocks[$bagianId][$blockIndex]['bukti']);
    }

    public function addRtlBlock(): void
    {
        $this->rtlBaru[] = $this->emptyRtlBlock();
    }

    public function removeRtlBlock(int $index): void
    {
        unset($this->rtlBaru[$index]);
        $this->rtlBaru = array_values($this->rtlBaru);
    }

    public function updatedIkuId(): void
    {
        $this->muatFormEvaluasi();
        $this->pilihPicOtomatis();
    }

    public function updatedBulan(): void
    {
        $this->muatFormEvaluasi();
        $this->rtlBaru = [$this->emptyRtlBlock()];
        $this->rtlBaruBatasWaktu = $this->akhirTriwulanBerikutnya()->toDateString();
    }

    public function updatedTahun(): void
    {
        $this->muatFormEvaluasi();
        $this->rtlBaru = [$this->emptyRtlBlock()];
        $this->rtlBaruBatasWaktu = $this->akhirTriwulanBerikutnya()->toDateString();
    }

    /**
     * RF-35: saat ketua tim mengetik realisasi, sarankan status_cocok otomatis dari
     * kemiripan teks terhadap rtl_teks aslinya. Tetap bisa ditimpa manual lewat dropdown.
     */
    public function updatedEvaluasi($value, $key): void
    {
        if (! str_ends_with($key, '.realisasi')) {
            return;
        }

        $rtlId = (int) explode('.', $key)[0];
        $poin = RtlEvaluasiModel::find($rtlId);

        if ($poin && filled($value)) {
            $this->evaluasi[$rtlId]['status_cocok'] = $poin->sarankanStatusCocok($value);
        }
    }

    protected function triwulanDari(int $bulan): int
    {
        return (int) ceil($bulan / 3);
    }

    protected function bulanKeDari(int $bulan): int
    {
        return $bulan - ($this->triwulanDari($bulan) - 1) * 3;
    }

    protected function isBulanTerlewat(): bool
    {
        return ! ($this->tahun === (int) now()->year && $this->bulan === (int) now()->month);
    }

    /**
     * TTL cache lintas-request (Laravel Cache, BUKAN cache-per-request di atas) untuk
     * data historis/RTL yang tidak berubah selagi Ketua Tim sedang mengisi form ini
     * (evaluasi realisasi & kegiatan baru baru benar-benar tersimpan ke DB saat
     * "Ajukan"/"Simpan Draft" ditekan — lihat lupakanCachePeriodeIku()). Tanpa ini,
     * SETIAP klik (termasuk "Tambah Kegiatan" yang sendirinya tidak butuh query sama
     * sekali) tetap membayar ~6 query ke DB remote (Seoul, ~400-500ms/query) karena
     * Livewire me-render ULANG seluruh komponen di tiap aksi.
     */
    protected const CACHE_TTL_DETIK = 60;

    protected function cacheKeyPeriodeIku(string $bagian): string
    {
        return "pengisian-kegiatan.{$bagian}.{$this->iku_id}.{$this->tahun}.{$this->bulan}";
    }

    /**
     * Dipanggil setelah data IKU+periode ini benar-benar berubah di DB (submit
     * berhasil) supaya request berikutnya tidak melihat cache basi.
     */
    protected function lupakanCachePeriodeIku(): void
    {
        foreach (['riwayat', 'rtl-sebelumnya', 'rtl-berjalan', 'rtl-berikutnya-ada', 'rtl-berjalan-terpakai'] as $bagian) {
            Cache::forget($this->cacheKeyPeriodeIku($bagian));
        }
    }

    /**
     * Riwayat kendala-solusi kumulatif (RF-28): seluruh pasangan milik IKU terpilih,
     * dari triwulan 1 sampai triwulan yang sedang berjalan, tahun yang sama.
     *
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, KendalaSolusiModel>>
     */
    protected function riwayatKendalaSolusi()
    {
        if (! $this->iku_id) {
            return collect();
        }

        return Cache::remember($this->cacheKeyPeriodeIku('riwayat'), self::CACHE_TTL_DETIK, function () {
            $triwulanSekarang = $this->triwulanDari($this->bulan);

            return KendalaSolusiModel::with(['periode', 'berkas'])
                ->where('iku_id', $this->iku_id)
                ->whereHas('periode', function ($q) use ($triwulanSekarang) {
                    $q->where('tahun', $this->tahun)->where('triwulan', '<=', $triwulanSekarang);
                })
                ->get()
                ->sortBy(fn ($item) => $item->periode->triwulan)
                ->groupBy(fn ($item) => $item->periode->triwulan);
        });
    }

    /**
     * Siapkan form evaluasi kosong untuk tiap poin RTL triwulan sebelumnya yang belum dievaluasi.
     */
    protected function muatFormEvaluasi(): void
    {
        $this->evaluasi = [];

        foreach ($this->rtlTriwulanSebelumnya() as $poin) {
            if (! $poin->sudahDievaluasi()) {
                $this->evaluasi[$poin->id] = ['realisasi' => '', 'status_cocok' => '', 'bukti' => []];
            }
        }
    }

    protected function rtlTriwulanSebelumnya()
    {
        if ($this->cacheRtlSebelumnyaList !== null) {
            return $this->cacheRtlSebelumnyaList;
        }

        if (! $this->iku_id) {
            return $this->cacheRtlSebelumnyaList = collect();
        }

        return $this->cacheRtlSebelumnyaList = Cache::remember(
            $this->cacheKeyPeriodeIku('rtl-sebelumnya'),
            self::CACHE_TTL_DETIK,
            function () {
                $triwulanSekarang = $this->triwulanDari($this->bulan);
                [$tahunTarget, $triwulanTarget] = $triwulanSekarang === 1
                    ? [$this->tahun - 1, 4]
                    : [$this->tahun, $triwulanSekarang - 1];

                return RtlEvaluasiModel::with(['periode', 'berkas'])
                    ->where('iku_id', $this->iku_id)
                    ->whereHas('periode', fn ($q) => $q->where('tahun', $tahunTarget)->where('triwulan', $triwulanTarget))
                    ->get();
            }
        );
    }

    protected function rtlTriwulanBerjalan()
    {
        if ($this->cacheRtlBerjalan !== null) {
            return $this->cacheRtlBerjalan;
        }

        if (! $this->iku_id) {
            return $this->cacheRtlBerjalan = collect();
        }

        return $this->cacheRtlBerjalan = Cache::remember(
            $this->cacheKeyPeriodeIku('rtl-berjalan'),
            self::CACHE_TTL_DETIK,
            function () {
                $triwulanSekarang = $this->triwulanDari($this->bulan);

                return RtlEvaluasiModel::with('periode')
                    ->where('iku_id', $this->iku_id)
                    ->whereHas('periode', fn ($q) => $q->where('tahun', $this->tahun)->where('triwulan', $triwulanSekarang))
                    ->get();
            }
        );
    }

    /**
     * ID rtl_evaluasi (triwulan berjalan) yang sudah dipakai sebagai kegiatan — dipakai
     * bersama oleh rtlBerjalanOptions() dan poinRtlBerjalanBelumTerlaksana(), dihitung
     * sekali saja per request.
     */
    protected function rtlBerjalanTerpakaiIds()
    {
        if ($this->cacheRtlBerjalanTerpakaiIds !== null) {
            return $this->cacheRtlBerjalanTerpakaiIds;
        }

        $rtlBerjalan = $this->rtlTriwulanBerjalan();

        if ($rtlBerjalan->isEmpty()) {
            return $this->cacheRtlBerjalanTerpakaiIds = collect();
        }

        return $this->cacheRtlBerjalanTerpakaiIds = Cache::remember(
            $this->cacheKeyPeriodeIku('rtl-berjalan-terpakai'),
            self::CACHE_TTL_DETIK,
            fn () => Kegiatan::whereIn('rtl_evaluasi_id', $rtlBerjalan->pluck('id'))
                ->pluck('rtl_evaluasi_id')
                ->unique()
        );
    }

    /**
     * IKU yang sedang dipilih — dipakai berulang kali (PIC, nama tim, folder service) di
     * request yang sama, jadi cukup satu query per request.
     */
    protected function ikuTerpilih(): ?MasterIku
    {
        if ($this->cacheIkuTerpilihDihitung) {
            return $this->cacheIkuTerpilih;
        }

        $this->cacheIkuTerpilihDihitung = true;

        // Diambil dari daftarUrutKode() yang sudah di-cache (bukan query MasterIku::find
        // baru) — daftar itu dipakai juga untuk dropdown IKU, jadi tidak ada query tambahan.
        return $this->cacheIkuTerpilih = $this->iku_id
            ? MasterIku::daftarUrutKode()->firstWhere('id', $this->iku_id)
            : null;
    }

    /**
     * RTL triwulan berikutnya (bagian 5) hanya boleh diisi pada bulan TERAKHIR triwulan
     * yang sedang berjalan — sebelum itu, ditampilkan hanya-baca dengan info kapan bisa diisi.
     */
    public function rtlBaruBisaDiisi(): bool
    {
        return $this->bulanKeDari($this->bulan) === 3;
    }

    public function labelBulanTerakhirTriwulanIni(): string
    {
        $triwulan = $this->triwulanDari($this->bulan);

        return $this->namaBulanIndo(($triwulan - 1) * 3 + 3);
    }

    /**
     * Poin RTL triwulan berjalan (rencana yang ditetapkan triwulan sebelumnya) beserta
     * status "sudah dipakai sebagai kegiatan?" — dipakai sebagai opsi dropdown uraian kegiatan.
     *
     * @return \Illuminate\Support\Collection<int, array{poin: RtlEvaluasiModel, terpakai: bool}>
     */
    protected function rtlBerjalanOptions()
    {
        $rtlBerjalan = $this->rtlTriwulanBerjalan();

        if ($rtlBerjalan->isEmpty()) {
            return collect();
        }

        $terpakaiIds = $this->rtlBerjalanTerpakaiIds();

        return $rtlBerjalan->map(fn ($poin) => [
            'poin' => $poin,
            'terpakai' => $terpakaiIds->contains($poin->id),
        ]);
    }

    /**
     * Poin RTL triwulan berjalan yang BELUM dipakai sebagai kegiatan apa pun — baik yang
     * tersimpan di database maupun yang uraiannya sudah cocok dengan salah satu blok kegiatan
     * di form yang sedang diisi (akan tersimpan begitu form ini diajukan).
     *
     * @return \Illuminate\Support\Collection<int, RtlEvaluasiModel>
     */
    protected function poinRtlBerjalanBelumTerlaksana()
    {
        if (! $this->rtlBaruBisaDiisi()) {
            return collect();
        }

        $rtlBerjalan = $this->rtlTriwulanBerjalan();

        if ($rtlBerjalan->isEmpty()) {
            return collect();
        }

        $terpakaiIds = $this->rtlBerjalanTerpakaiIds();

        $uraianDiForm = collect($this->blocks)
            ->pluck('uraian_kegiatan')
            ->map(fn ($teks) => trim(mb_strtolower($teks)))
            ->filter();

        return $rtlBerjalan->reject(function ($poin) use ($terpakaiIds, $uraianDiForm) {
            return $terpakaiIds->contains($poin->id)
                || $uraianDiForm->contains(trim(mb_strtolower($poin->rtl_teks)));
        })->values();
    }

    /**
     * Cari poin RTL triwulan berjalan yang teksnya cocok persis (case-insensitive) dengan
     * uraian kegiatan — dipakai saat menyimpan supaya kegiatan tertaut ke rencana RTL-nya.
     */
    protected function cariRtlBerjalanCocok(string $uraianKegiatan): ?int
    {
        $uraian = trim(mb_strtolower($uraianKegiatan));

        foreach ($this->rtlTriwulanBerjalan() as $poin) {
            if (trim(mb_strtolower($poin->rtl_teks)) === $uraian) {
                return $poin->id;
            }
        }

        return null;
    }

    /**
     * Nama tim & daftar penanggung jawab (RF: PIC) untuk IKU terpilih, sumbernya Master IKU
     * (kolom tim) + keanggotaan tim/penugasan manual — bukan diketik bebas.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    protected function picOptions()
    {
        if (! $this->iku_id) {
            return collect();
        }

        // Di-cache per IKU (bukan per IKU+periode seperti cacheKeyPeriodeIku — penanggung
        // jawab tidak tergantung tahun/bulan) supaya tidak query 'iku_penugasan'+'user_tim'
        // di SETIAP render, termasuk aksi yang tidak menyentuh PIC sama sekali.
        return Cache::remember("pengisian-kegiatan.pic-options.{$this->iku_id}", self::CACHE_TTL_DETIK, function () {
            $iku = $this->ikuTerpilih();

            return $iku ? $iku->semuaPenanggungJawab()->pluck('nama')->values() : collect();
        });
    }

    protected function pilihPicOtomatis(): void
    {
        $default = $this->ikuTerpilih()?->penanggungJawabOtomatis()->first()?->nama;

        $this->rtlBaruPic = $default ?? '';
        $this->rtlBaruPicManual = '';
    }

    protected function targetTriwulanBerikutnya(): array
    {
        $triwulanSekarang = $this->triwulanDari($this->bulan);

        return $triwulanSekarang === 4
            ? ['tahun' => $this->tahun + 1, 'triwulan' => 1]
            : ['tahun' => $this->tahun, 'triwulan' => $triwulanSekarang + 1];
    }

    protected function bulanBulanTarget(): array
    {
        $target = $this->targetTriwulanBerikutnya();
        $bulanPertama = ($target['triwulan'] - 1) * 3 + 1;

        return [$bulanPertama, $bulanPertama + 1, $bulanPertama + 2];
    }

    protected function akhirTriwulanBerikutnya(): Carbon
    {
        $target = $this->targetTriwulanBerikutnya();
        $bulanTerakhir = ($target['triwulan'] - 1) * 3 + 3;

        return Carbon::create($target['tahun'], $bulanTerakhir, 1)->endOfMonth();
    }

    protected function namaBulanIndo(int $bulan): string
    {
        return Carbon::create(2000, $bulan, 1)->locale('id')->translatedFormat('F');
    }

    protected function rtlTriwulanBerikutnyaSudahAda(): bool
    {
        if ($this->cacheRtlBerikutnyaSudahAda !== null) {
            return $this->cacheRtlBerikutnyaSudahAda;
        }

        if (! $this->iku_id) {
            return $this->cacheRtlBerikutnyaSudahAda = false;
        }

        return $this->cacheRtlBerikutnyaSudahAda = Cache::remember(
            $this->cacheKeyPeriodeIku('rtl-berikutnya-ada'),
            self::CACHE_TTL_DETIK,
            function () {
                $target = $this->targetTriwulanBerikutnya();

                return RtlEvaluasiModel::where('iku_id', $this->iku_id)
                    ->whereHas('periode', fn ($q) => $q->where('tahun', $target['tahun'])->where('triwulan', $target['triwulan']))
                    ->exists();
            }
        );
    }

    protected function labelTriwulanBerikutnya(): string
    {
        $target = $this->targetTriwulanBerikutnya();

        return 'Triwulan '.['I', 'II', 'III', 'IV'][$target['triwulan'] - 1].' '.$target['tahun'];
    }

    /**
     * Validasi "IKU ini benar-benar ada" TANPA query DB fresh — dicocokkan ke
     * MasterIku::daftarUrutKode() yang sudah di-cache, karena rules() dipanggil di
     * setiap render (lewat formLengkap()) dan biasanya IKU yang dipilih sudah pasti
     * ada di daftar itu (baru query fresh kalau memang belum pernah di-cache sama sekali).
     */
    protected function aturanIkuValid(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! MasterIku::daftarUrutKode()->contains('id', (int) $value)) {
                $fail('IKU yang dipilih tidak valid.');
            }
        };
    }

    protected function rules(): array
    {
        $rules = [
            'tahun' => ['required', 'integer', 'min:2020', 'max:2100'],
            'bulan' => ['required', 'integer', 'min:1', 'max:12'],
            // Bukan 'exists:master_iku,id' dengan sengaja — aturan itu SELALU query DB
            // fresh, dan validator ini (lewat formLengkap()) jalan di SETIAP render,
            // termasuk aksi yang sama sekali tidak butuh DB (mis. "Tambah Kegiatan").
            // Cukup cocokkan ke daftarUrutKode() yang sudah di-cache (lihat MasterIku).
            'iku_id' => ['required', $this->aturanIkuValid()],

            'blocks' => ['required', 'array', 'min:1'],
            'blocks.*.uraian_kegiatan' => ['required', 'string', 'max:1000'],
            'blocks.*.jenis' => ['required', 'in:bukan_survei_sensus,survei_sensus'],
            'blocks.*.tahapan_survei' => [
                'nullable',
                'required_if:blocks.*.jenis,survei_sensus',
                'in:persiapan,pelaksanaan,pengolahan,diseminasi',
            ],
            'blocks.*.bukti' => ['required', 'array', 'min:1'],
            'blocks.*.bukti.*' => ['file', 'mimes:pdf', 'max:10240'],

            'kendalaBlocks.*.kendala' => ['nullable', 'string'],
            'kendalaBlocks.*.solusi' => ['nullable', 'string'],
            // RF-27: solusi diisi -> bukti dukung solusi WAJIB diunggah.
            'kendalaBlocks.*.bukti_solusi' => ['required_with:kendalaBlocks.*.solusi', 'array'],
            'kendalaBlocks.*.bukti_solusi.*' => ['file', 'mimes:pdf', 'max:10240'],
        ];

        // Bagian kustom (mis. Manajemen Risiko): poin kosong dilewati saat disimpan
        // (sama seperti kendalaBlocks), tapi poin yang TERISI wajib punya bukti dukung.
        foreach ($this->bagianKustomAktif() as $bagian) {
            $rules["bagianKustomBlocks.{$bagian->id}.*.teks"] = ['nullable', 'string'];
            $rules["bagianKustomBlocks.{$bagian->id}.*.bukti"] = ['required_with:bagianKustomBlocks.'.$bagian->id.'.*.teks', 'array'];
            $rules["bagianKustomBlocks.{$bagian->id}.*.bukti.*"] = ['file', 'mimes:pdf', 'max:10240'];
        }

        // RF-29/31: minimal SATU poin RTL triwulan sebelumnya yang belum dievaluasi wajib
        // dilaporkan realisasinya (dicek lewat after() di buatValidator()) — status_cocok
        // hanya wajib untuk baris yang realisasinya memang diisi.
        foreach ($this->evaluasi as $rtlId => $data) {
            $rules["evaluasi.{$rtlId}.realisasi"] = ['nullable', 'string'];
            $rules["evaluasi.{$rtlId}.status_cocok"] = ['required_with:evaluasi.'.$rtlId.'.realisasi', 'nullable', 'in:cocok,perlu_ditinjau,tidak_cocok'];
            $rules["evaluasi.{$rtlId}.bukti.*"] = ['file', 'mimes:pdf', 'max:10240'];
        }

        // RF-32/33/34: RTL triwulan berikutnya hanya boleh (dan wajib) ditetapkan pada bulan
        // terakhir triwulan berjalan, kecuali sudah pernah ditetapkan sebelumnya.
        if ($this->rtlBaruBisaDiisi() && ! $this->rtlTriwulanBerikutnyaSudahAda()) {
            $rules['rtlBaru'] = ['required', 'array', 'min:1'];
            $rules['rtlBaru.*.rtl_teks'] = ['required', 'string'];
            $rules['rtlBaruPic'] = ['required', 'string', 'max:255'];
            $rules['rtlBaruPicManual'] = ['required_if:rtlBaruPic,__lainnya__', 'nullable', 'string', 'max:255'];
            $rules['rtlBaruBatasWaktu'] = ['required', 'date'];
        }

        return $rules;
    }

    protected function validationAttributes(): array
    {
        $attrs = [
            'iku_id' => 'IKU',
            'blocks.*.uraian_kegiatan' => 'uraian kegiatan',
            'blocks.*.jenis' => 'jenis kegiatan',
            'blocks.*.tahapan_survei' => 'tahapan survei',
            'blocks.*.bukti' => 'bukti capaian',
            'kendalaBlocks.*.kendala' => 'kendala',
            'kendalaBlocks.*.solusi' => 'solusi',
            'kendalaBlocks.*.bukti_solusi' => 'bukti dukung solusi',
            'rtlBaru.*.rtl_teks' => 'RTL',
            'rtlBaruPic' => 'PIC Tindak Lanjut',
            'rtlBaruPicManual' => 'PIC (manual)',
            'rtlBaruBatasWaktu' => 'batas waktu',
        ];

        foreach ($this->bagianKustomAktif() as $bagian) {
            $attrs["bagianKustomBlocks.{$bagian->id}.*.teks"] = 'poin '.$bagian->nama;
            $attrs["bagianKustomBlocks.{$bagian->id}.*.bukti"] = 'bukti dukung '.$bagian->nama;
        }

        return $attrs;
    }

    protected function messages(): array
    {
        $messages = [
            'blocks.*.tahapan_survei.required_if' => 'Tahapan survei wajib dipilih untuk kegiatan Survei/Sensus.',
            'blocks.*.bukti.required' => 'Bukti capaian (PDF) wajib diunggah untuk tiap kegiatan.',
            'kendalaBlocks.*.bukti_solusi.required_with' => 'Bukti dukung solusi (PDF) wajib diunggah bila kolom solusi diisi.',
            'evaluasi.*.status_cocok.required_with' => 'Status kecocokan wajib dipilih bila realisasi sudah diisi.',
            'rtlBaruPicManual.required_if' => 'Ketik nama PIC karena memilih "Lainnya".',
        ];

        foreach ($this->bagianKustomAktif() as $bagian) {
            $messages["bagianKustomBlocks.{$bagian->id}.*.bukti.required_with"] = 'Bukti dukung wajib diunggah untuk tiap poin '.$bagian->nama.' yang diisi.';
        }

        return $messages;
    }

    /**
     * Bangun validator gabungan (rules dasar + aturan tambahan yang tidak bisa dinyatakan
     * lewat array rules biasa): minimal satu evaluasi RTL sebelumnya wajib diisi, dan seluruh
     * poin RTL triwulan berjalan wajib sudah terlaksana sebelum bulan terakhir triwulan diajukan.
     */
    protected function buatValidator(): \Illuminate\Validation\Validator
    {
        $data = [
            'tahun' => $this->tahun,
            'bulan' => $this->bulan,
            'iku_id' => $this->iku_id,
            'blocks' => $this->blocks,
            'kendalaBlocks' => $this->kendalaBlocks,
            'evaluasi' => $this->evaluasi,
            'rtlBaru' => $this->rtlBaru,
            'rtlBaruPic' => $this->rtlBaruPic,
            'rtlBaruPicManual' => $this->rtlBaruPicManual,
            'rtlBaruBatasWaktu' => $this->rtlBaruBatasWaktu,
            'bagianKustomBlocks' => $this->bagianKustomBlocks,
        ];

        $validator = \Illuminate\Support\Facades\Validator::make(
            $data,
            $this->rules(),
            $this->messages(),
            $this->validationAttributes()
        );

        $validator->after(function ($validator) {
            if (! empty($this->evaluasi) && ! collect($this->evaluasi)->contains(fn ($d) => filled($d['realisasi'] ?? null))) {
                $validator->errors()->add('evaluasi', 'Minimal satu poin evaluasi RTL triwulan sebelumnya wajib diisi sebelum dapat diajukan.');
            }

            $belumTerlaksana = $this->poinRtlBerjalanBelumTerlaksana();

            if ($belumTerlaksana->isNotEmpty()) {
                $daftar = $belumTerlaksana->pluck('rtl_teks')->implode('; ');
                $validator->errors()->add(
                    'blocks',
                    "Masih ada {$belumTerlaksana->count()} poin RTL triwulan berjalan yang belum dilaksanakan sebagai kegiatan: {$daftar}."
                );
            }

            // Bagian kustom "wajib akhir triwulan": minimal satu poin wajib terisi bila
            // sedang mengajukan pada bulan TERAKHIR triwulan (sama seperti syarat RTL baru).
            if ($this->bulanKeDari($this->bulan) === 3) {
                foreach ($this->bagianKustomAktif() as $bagian) {
                    if (! $bagian->wajib_akhir_triwulan) {
                        continue;
                    }

                    $adaTerisi = collect($this->bagianKustomBlocks[$bagian->id] ?? [])
                        ->contains(fn ($blok) => filled($blok['teks'] ?? null));

                    if (! $adaTerisi) {
                        $validator->errors()->add(
                            "bagianKustomBlocks.{$bagian->id}",
                            "Minimal satu poin \"{$bagian->nama}\" wajib diisi sebelum diajukan pada bulan terakhir triwulan ini."
                        );
                    }
                }
            }
        });

        return $validator;
    }

    /**
     * Dipakai untuk mengaktifkan/menonaktifkan tombol "Ajukan ke Tim SAKIP" secara proaktif
     * di tampilan — bukan hanya menunggu klik lalu menampilkan galat.
     */
    protected ?bool $cacheFormLengkap = null;

    public function formLengkap(): bool
    {
        if ($this->cacheFormLengkap !== null) {
            return $this->cacheFormLengkap;
        }

        if (! $this->iku_id) {
            return $this->cacheFormLengkap = false;
        }

        return $this->cacheFormLengkap = $this->buatValidator()->passes();
    }

    /**
     * Nilai PIC final yang benar-benar disimpan — dari dropdown, atau ketikan manual bila
     * memilih "Lainnya".
     */
    protected function picEfektif(): string
    {
        return $this->rtlBaruPic === '__lainnya__' ? $this->rtlBaruPicManual : $this->rtlBaruPic;
    }

    /**
     * Simpan sementara sebagai draft — hanya kegiatan yang divalidasi (IKU + uraian +
     * jenis kegiatan), bukti capaian TIDAK wajib pada tahap ini. Kegiatan tersimpan
     * dengan status "draft" (belum diajukan ke Tim SAKIP); kendala-solusi, evaluasi
     * RTL, dan RTL baru baru divalidasi & disimpan saat benar-benar "Ajukan ke Tim SAKIP".
     */
    public function simpanDraft(): void
    {
        $this->validate([
            'tahun' => ['required', 'integer', 'min:2020', 'max:2100'],
            'bulan' => ['required', 'integer', 'min:1', 'max:12'],
            'iku_id' => ['required', $this->aturanIkuValid()],
            'blocks.*.uraian_kegiatan' => ['required', 'string', 'max:1000'],
            'blocks.*.jenis' => ['required', 'in:bukan_survei_sensus,survei_sensus'],
            'blocks.*.tahapan_survei' => [
                'nullable',
                'required_if:blocks.*.jenis,survei_sensus',
                'in:persiapan,pelaksanaan,pengolahan,diseminasi',
            ],
        ], [], $this->validationAttributes());

        $periode = Periode::firstOrCreate(
            ['tahun' => $this->tahun, 'bulan' => $this->bulan],
            [
                'triwulan' => $this->triwulanDari($this->bulan),
                'bulan_ke' => $this->bulanKeDari($this->bulan),
                'flag_bulan_terlewat' => $this->isBulanTerlewat(),
            ]
        );

        DB::transaction(function () use ($periode) {
            Capaian::firstOrCreate(['iku_id' => $this->iku_id, 'periode_id' => $periode->id]);

            foreach ($this->blocks as $block) {
                $tahapan = $block['jenis'] === 'survei_sensus' ? $block['tahapan_survei'] : null;

                $namaFolderAuto = Str::limit(
                    ($tahapan ? '['.ucfirst($tahapan).'] ' : '').$block['uraian_kegiatan'],
                    100,
                    ''
                );

                Kegiatan::create([
                    'iku_id' => $this->iku_id,
                    'periode_id' => $periode->id,
                    'uraian_kegiatan' => $block['uraian_kegiatan'],
                    'jenis' => $block['jenis'],
                    'tahapan_survei' => $tahapan,
                    'nama_folder_auto' => $namaFolderAuto,
                    'status_dokumen' => Kegiatan::STATUS_DRAFT,
                ]);
            }
        });

        session()->flash('status', 'Draf kegiatan berhasil disimpan. Lengkapi bukti & bagian lain lalu ajukan ke Tim SAKIP saat siap.');
    }

    public function ajukanIsian(): void
    {
        $this->buatValidator()->validate();

        $periode = Periode::firstOrCreate(
            ['tahun' => $this->tahun, 'bulan' => $this->bulan],
            [
                'triwulan' => $this->triwulanDari($this->bulan),
                'bulan_ke' => $this->bulanKeDari($this->bulan),
                'flag_bulan_terlewat' => $this->isBulanTerlewat(),
            ]
        );

        $iku = MasterIku::findOrFail($this->iku_id);
        $folderService = app(FolderStructureService::class);

        DB::transaction(function () use ($periode, $iku, $folderService) {
            // Angka capaian (RF-38) milik IKU+periode, dibagikan seluruh kegiatan di
            // bawahnya — cukup disiapkan kosong di sini, diisi Tim SAKIP saat verifikasi.
            Capaian::firstOrCreate([
                'iku_id' => $this->iku_id,
                'periode_id' => $periode->id,
            ]);

            // 1) Kegiatan + bukti capaian (bukti melekat langsung ke kegiatan, RF-23)
            foreach ($this->blocks as $block) {
                $tahapan = $block['jenis'] === 'survei_sensus' ? $block['tahapan_survei'] : null;

                $namaFolderAuto = Str::limit(
                    ($tahapan ? '['.ucfirst($tahapan).'] ' : '').$block['uraian_kegiatan'],
                    100,
                    ''
                );

                $kegiatan = Kegiatan::create([
                    'iku_id' => $this->iku_id,
                    'rtl_evaluasi_id' => $this->cariRtlBerjalanCocok($block['uraian_kegiatan']),
                    'periode_id' => $periode->id,
                    'uraian_kegiatan' => $block['uraian_kegiatan'],
                    'jenis' => $block['jenis'],
                    'tahapan_survei' => $tahapan,
                    'nama_folder_auto' => $namaFolderAuto,
                    'status_dokumen' => Kegiatan::STATUS_DRAFT,
                ]);

                $kegiatan->ajukan();

                foreach ($block['bukti'] as $file) {
                    $path = $file->store('bukti-capaian', 'local');

                    $berkas = Berkas::create([
                        'ref_id' => $kegiatan->id,
                        'ref_type' => Kegiatan::class,
                        'kategori' => 'capaian',
                        'nama_file' => $file->getClientOriginalName(),
                        'path' => $path,
                        'status_verifikasi' => 'menunggu',
                    ]);

                    // Dibungkus try/catch supaya isian TETAP bisa diajukan walau Drive
                    // belum terkonfigurasi — berkas tetap aman di disk lokal sebagai cadangan.
                    try {
                        $localFullPath = Storage::disk('local')->path($path);
                        $hasilDrive = $folderService->unggahBerkasKegiatan($kegiatan, 'capaian', $localFullPath);
                        $berkas->update($hasilDrive);
                    } catch (RuntimeException $e) {
                        Log::warning('Gagal mengunggah berkas ke Google Drive, disimpan lokal saja: '.$e->getMessage());
                    }
                }
            }

            // 2) Kendala & Solusi (blok kosong dilewati — bagian ini opsional per periode)
            foreach ($this->kendalaBlocks as $block) {
                if (trim($block['kendala']) === '' && trim($block['solusi']) === '') {
                    continue;
                }

                $entry = KendalaSolusiModel::create([
                    'iku_id' => $this->iku_id,
                    'periode_id' => $periode->id,
                    'kendala' => $block['kendala'],
                    'solusi' => $block['solusi'] ?: null,
                ]);

                // RF baru: nama berkas di Drive dibentuk dari teks kendala+solusinya sendiri
                // (bukan "bukti-solusi.pdf" generik) supaya langsung dikenali dari daftar
                // berkas tanpa perlu dibuka satu-satu — lihat FolderStructureService::unggahBerkas().
                $namaBerkasDariTeks = "kendala {$block['kendala']} solusi {$block['solusi']}";

                foreach ($block['bukti_solusi'] as $file) {
                    $path = $file->store('bukti-solusi', 'local');

                    $berkas = Berkas::create([
                        'ref_id' => $entry->id,
                        'ref_type' => KendalaSolusiModel::class,
                        'kategori' => 'solusi',
                        'nama_file' => $file->getClientOriginalName(),
                        'path' => $path,
                        'status_verifikasi' => 'menunggu',
                    ]);

                    try {
                        $localFullPath = Storage::disk('local')->path($path);
                        $hasilDrive = $folderService->unggahBerkas($periode, $iku, 'solusi', $localFullPath, namaBerkasOverride: $namaBerkasDariTeks);
                        $berkas->update($hasilDrive);
                    } catch (RuntimeException $e) {
                        Log::warning('Gagal mengunggah bukti solusi ke Google Drive, disimpan lokal saja: '.$e->getMessage());
                    }
                }
            }

            // 3) Evaluasi RTL triwulan sebelumnya — minimal satu wajib diisi (dicek di
            // buatValidator()), baris yang masih kosong dilewati begitu saja.
            foreach ($this->evaluasi as $rtlId => $data) {
                if (blank($data['realisasi'])) {
                    continue;
                }

                $poin = RtlEvaluasiModel::with(['periode', 'masterIku'])->findOrFail($rtlId);

                $poin->update([
                    'realisasi' => $data['realisasi'],
                    'status_cocok' => $data['status_cocok'],
                ]);

                foreach ($data['bukti'] as $file) {
                    $path = $file->store('bukti-evaluasi-rtl', 'local');

                    $berkas = Berkas::create([
                        'ref_id' => $poin->id,
                        'ref_type' => RtlEvaluasiModel::class,
                        'kategori' => 'evaluasi_rtl',
                        'nama_file' => $file->getClientOriginalName(),
                        'path' => $path,
                        'status_verifikasi' => 'menunggu',
                    ]);

                    try {
                        $localFullPath = Storage::disk('local')->path($path);
                        $hasilDrive = $folderService->unggahBerkas($poin->periode, $poin->masterIku, 'evaluasi_rtl', $localFullPath);
                        $berkas->update($hasilDrive);
                    } catch (RuntimeException $e) {
                        Log::warning('Gagal mengunggah bukti evaluasi RTL ke Google Drive, disimpan lokal saja: '.$e->getMessage());
                    }
                }
            }

            // 4) RTL triwulan berikutnya (hanya bila belum pernah ditetapkan, dan hanya
            // boleh diisi di bulan terakhir triwulan berjalan — dicek jugu di buatValidator()).
            if ($this->rtlBaruBisaDiisi() && ! $this->rtlTriwulanBerikutnyaSudahAda()) {
                $target = $this->targetTriwulanBerikutnya();
                $bulanPertamaTarget = ($target['triwulan'] - 1) * 3 + 1;

                $periodeTarget = Periode::firstOrCreate(
                    ['tahun' => $target['tahun'], 'bulan' => $bulanPertamaTarget],
                    ['triwulan' => $target['triwulan'], 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]
                );

                $picEfektif = $this->picEfektif();

                // RF-33: "RTL untuk Oktober, November, dan Desember" — satu keterangan bulan
                // yang sama untuk SELURUH poin dalam triwulan ini (bukan per poin).
                $namaBulanTarget = collect($this->bulanBulanTarget())->map(fn ($b) => $this->namaBulanIndo($b));
                $berlakuBulan = 'RTL untuk '.$namaBulanTarget->join(', ', ', dan ');

                foreach ($this->rtlBaru as $blok) {
                    RtlEvaluasiModel::create([
                        'iku_id' => $this->iku_id,
                        'periode_id' => $periodeTarget->id,
                        'rtl_teks' => $blok['rtl_teks'],
                        'berlaku_bulan' => $berlakuBulan,
                        'pic' => $picEfektif,
                        'batas_waktu' => $this->rtlBaruBatasWaktu,
                    ]);
                }
            }

            // 5) Bagian kustom (mis. Manajemen Risiko) — poin kosong dilewati, sama
            // seperti Kendala & Solusi; bukti sudah dipastikan wajib lewat validasi di atas.
            foreach ($this->bagianKustomAktif() as $bagian) {
                foreach ($this->bagianKustomBlocks[$bagian->id] ?? [] as $blok) {
                    if (trim($blok['teks'] ?? '') === '') {
                        continue;
                    }

                    $poin = BagianKustomPoin::create([
                        'bagian_kustom_id' => $bagian->id,
                        'iku_id' => $this->iku_id,
                        'periode_id' => $periode->id,
                        'teks' => $blok['teks'],
                    ]);

                    foreach ($blok['bukti'] as $file) {
                        $path = $file->store('bukti-bagian-kustom', 'local');

                        $berkas = Berkas::create([
                            'ref_id' => $poin->id,
                            'ref_type' => BagianKustomPoin::class,
                            'kategori' => 'bagian_kustom',
                            'nama_file' => $file->getClientOriginalName(),
                            'path' => $path,
                            'status_verifikasi' => 'menunggu',
                        ]);

                        try {
                            $localFullPath = Storage::disk('local')->path($path);
                            $hasilDrive = $folderService->unggahBerkas(
                                $periode, $iku, 'bagian_kustom', $localFullPath, namaFolderOverride: $bagian->nama
                            );
                            $berkas->update($hasilDrive);
                        } catch (RuntimeException $e) {
                            Log::warning("Gagal mengunggah bukti {$bagian->nama} ke Google Drive, disimpan lokal saja: ".$e->getMessage());
                        }
                    }
                }
            }
        });

        session()->flash('status', 'Isian kegiatan, kendala & solusi, dan evaluasi RTL berhasil diajukan ke Tim SAKIP.');

        $this->lupakanCachePeriodeIku();

        $this->blocks = [$this->emptyBlock()];
        $this->kendalaBlocks = [$this->emptyKendalaBlock()];
        $this->iku_id = null;
        $this->evaluasi = [];
        $this->rtlBaru = [$this->emptyRtlBlock()];
        $this->rtlBaruPic = '';
        $this->rtlBaruPicManual = '';
        $this->rtlBaruBatasWaktu = $this->akhirTriwulanBerikutnya()->toDateString();

        $this->bagianKustomBlocks = [];
        foreach ($this->bagianKustomAktif() as $bagian) {
            $this->bagianKustomBlocks[$bagian->id] = [$this->emptyBagianKustomBlock()];
        }
    }

    public function render()
    {
        $triwulan = $this->triwulanDari($this->bulan);

        return view('livewire.pengisian-kegiatan', [
            'ikuList' => MasterIku::daftarUrutKode(),
            'triwulan' => $triwulan,
            'bulanKe' => $this->bulanKeDari($this->bulan),
            'flagTerlewat' => $this->isBulanTerlewat(),
            'periodeLabel' => Carbon::create($this->tahun, $this->bulan, 1)->locale('id')->translatedFormat('F Y'),
            'riwayatKendala' => $this->riwayatKendalaSolusi(),
            'rtlSebelumnya' => $this->rtlTriwulanSebelumnya(),
            'rtlBerjalan' => $this->rtlTriwulanBerjalan(),
            'sudahAdaRtlBerikutnya' => $this->rtlTriwulanBerikutnyaSudahAda(),
            'labelBerikutnya' => $this->labelTriwulanBerikutnya(),
            'bulanTargetBerikutnya' => collect($this->bulanBulanTarget())->mapWithKeys(fn ($b) => [$b => $this->namaBulanIndo($b)]),
            'rtlBerjalanOptions' => $this->rtlBerjalanOptions(),
            'rtlBerjalanBelumTerlaksana' => $this->poinRtlBerjalanBelumTerlaksana(),
            'picOptions' => $this->picOptions(),
            'namaTimIku' => $this->ikuTerpilih()?->tim,
            'bagianKustomAktif' => $this->bagianKustomAktif(),
        ]);
    }
}
