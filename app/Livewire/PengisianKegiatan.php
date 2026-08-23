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
     * @var array<int, array{id: ?int, status_dokumen: ?string, uraian_kegiatan: string, jenis: string, tahapan_survei: ?string, bukti: array, existing_bukti: array}>
     */
    public array $blocks = [];

    /**
     * @var array<int, array{kendala: string, solusi: string}>
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

    /**
     * Teks bebas — datalist "daftar-pic-rtl" menawarkan nama dari picOptions() sebagai
     * saran, tapi boleh diketik nama lain di luar itu (mis. staf yang belum tercatat).
     */
    public string $rtlBaruPic = '';

    public string $rtlBaruBatasWaktu = '';

    /**
     * Cache dalam satu siklus request saja (di-reset otomatis tiap request baru karena
     * properti non-public tidak ikut disinkronkan Livewire) — beberapa query berat
     * (RTL berjalan, IKU terpilih, dst.) dipakai ulang di banyak tempat saat render()
     * DAN formLengkap() dipanggil di request yang sama; tanpa cache ini query yang sama
     * terulang belasan kali per klik/blur dan bikin halaman terasa lambat.
     */
    protected ?\Illuminate\Support\Collection $cacheRtlBerjalan = null;

    protected ?bool $cacheRtlBerikutnyaSudahAda = null;

    protected ?MasterIku $cacheIkuTerpilih = null;

    protected bool $cacheIkuTerpilihDihitung = false;

    protected ?\Illuminate\Support\Collection $cacheRtlBerjalanTerpakaiIds = null;

    protected ?Periode $cachePeriodeSaatIni = null;

    protected bool $cachePeriodeSaatIniDihitung = false;

    /**
     * Livewire mengirim SETIAP aksi (blur, klik pill, unggah berkas, dst.) sebagai
     * request AJAX terpisah yang membawa "snapshot" kondisi form versi client saat
     * itu. Kalau pengguna memicu beberapa aksi hampir bersamaan — paling sering
     * kejadian saat mengunggah lebih dari satu berkas bukti berurutan sambil masih
     * mengetik kolom lain — lebih dari satu request bisa diproses dari snapshot yang
     * SUDAH SALING BASI: unggahan berkas Livewire mengirim data mentahnya lewat jalur
     * terpisah dari commit properti biasa, jadi selama transfer berkas berlangsung
     * (bisa beberapa detik), Livewire menganggap TIDAK ADA request aktif untuk
     * komponen ini dan aksi lain bebas jalan duluan. Begitu unggahan itu akhirnya
     * "menyimpan" hasilnya, ia bisa memakai snapshot yang sudah ketinggalan
     * dibanding aksi-aksi lain yang keburu selesai duluan — hasilnya salah satu
     * perubahan tertimpa/hilang begitu balasannya datang belakangan. Ini akar
     * penyebab bug "berkas kedua hilang setelah diunggah" dan "uraian kegiatan
     * hilang saat klik Tambah Kegiatan" yang pernah dilaporkan.
     *
     * Guna mengunci supaya SEMUA request Livewire untuk komponen ini, per sesi
     * pengguna, benar-benar diproses satu per satu di server — request kedua
     * menunggu request pertama selesai (dan snapshot-nya sudah mutakhir) sebelum
     * mulai diproses, bukan langsung jalan dari snapshot yang sudah basi.
     */
    protected ?\Illuminate\Contracts\Cache\Lock $requestLock = null;

    public function boot(): void
    {
        // TTL kunci (40 detik) sengaja lebih panjang dari batas tunggu block() (25 detik)
        // supaya kunci tidak pernah kedaluwarsa sendiri sementara masih benar-benar dipegang.
        $this->requestLock = Cache::lock('pengisian-kegiatan-lock:'.session()->getId(), 40);
        $this->requestLock->block(25);
    }

    public function dehydrate(): void
    {
        $this->requestLock?->release();
    }

    public function mount(): void
    {
        $this->tahun = request()->integer('tahun') ?: (int) now()->year;
        $this->bulan = request()->integer('bulan') ?: (int) now()->month;
        $this->blocks = [$this->emptyBlock()];
        $this->kendalaBlocks = [$this->emptyKendalaBlock()];
        $this->rtlBaru = [$this->emptyRtlBlock()];
        $this->rtlBaruBatasWaktu = $this->akhirTriwulanBerikutnya()->toDateString();

        foreach ($this->bagianKustomAktif() as $bagian) {
            $this->bagianKustomBlocks[$bagian->id] = [$this->emptyBagianKustomBlock()];
        }

        // Deep-link dari baris tabel dasbor (App\Livewire\DasborUtama) — begitu IKU dipilih
        // dari URL, langsung muat evaluasi & PIC otomatis seperti updatedIkuId() supaya
        // pengguna tidak perlu memilih ulang IKU yang sudah jelas diklik.
        $ikuId = request()->integer('iku_id') ?: null;

        if ($ikuId && MasterIku::whereKey($ikuId)->exists()) {
            $this->iku_id = $ikuId;
            $this->muatFormEvaluasi();
            $this->muatBlocksKegiatan();
            $this->muatKendalaBlocks();
            $this->pilihPicOtomatis();
        }
    }

    protected function emptyBlock(): array
    {
        return [
            'id' => null,
            'status_dokumen' => null,
            'uraian_kegiatan' => '',
            'jenis' => '',
            'tahapan_survei' => '',
            'bukti' => [],
            'existing_bukti' => [],
        ];
    }

    /**
     * Status kegiatan yang sudah masuk proses Tim SAKIP — blok dengan status ini
     * ditampilkan hanya-baca di form (lihat muatBlocksKegiatan()) supaya Ketua Tim
     * tidak menimpa data yang sedang/sudah diverifikasi. Hanya 'draft' & 'dikembalikan'
     * yang tetap bisa diedit/diajukan ulang lewat form ini.
     *
     * @var list<string>
     */
    protected const STATUS_KEGIATAN_TERKUNCI = [
        Kegiatan::STATUS_DIAJUKAN,
        Kegiatan::STATUS_DIVERIFIKASI,
        Kegiatan::STATUS_DISETUJUI,
    ];

    protected function emptyKendalaBlock(): array
    {
        return [
            'id' => null,
            'kendala' => '',
            'solusi' => '',
            'status_verifikasi' => null,
            'catatan' => null,
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
        if ($this->formTerkunciDisetujui()) {
            return;
        }

        $this->blocks[] = $this->emptyBlock();
    }

    /**
     * Hanya blok yang belum tersimpan (id === null) yang boleh dihapus dari sini —
     * blok yang sudah punya baris Kegiatan di DB sengaja tidak bisa dihapus lewat
     * tombol ini, supaya tidak menghilang dari form lalu muncul lagi tak berubah
     * saat form dibuka ulang (lihat muatBlocksKegiatan()).
     */
    public function removeBlock(int $index): void
    {
        if (($this->blocks[$index]['id'] ?? null) !== null) {
            return;
        }

        unset($this->blocks[$index]);
        $this->blocks = array_values($this->blocks);
    }

    public function removeBuktiKegiatan(int $blockIndex, int $fileIndex): void
    {
        unset($this->blocks[$blockIndex]['bukti'][$fileIndex]);
        $this->blocks[$blockIndex]['bukti'] = array_values($this->blocks[$blockIndex]['bukti']);
    }

    /**
     * Hapus SATU berkas bukti capaian yang SUDAH TERSIMPAN (existing_bukti) — hanya
     * dipakai saat Tim SAKIP menandainya "Tidak Sesuai", supaya Ketua Tim bisa membuang
     * bukti yang salah lalu mengunggah gantinya (tidak ada jalan lain untuk mengganti
     * bukti lama sebelum ini — sebelumnya hanya bisa MENAMBAH berkas baru di samping
     * yang lama). Berkas yang masih "menunggu"/"terverifikasi" TIDAK boleh dihapus
     * dari sini (dicek di sini, bukan cuma disembunyikan tombolnya di blade —
     * defense in depth, pola yang sama dipakai VerifikasiCapaian::tandaiTolak() dkk).
     * File fisik lokal ikut dihapus; salinan di Google Drive (bila sempat tersalin)
     * TIDAK ikut dihapus (belum ada mekanisme hapus-dari-Drive di GoogleDriveService).
     */
    public function hapusBuktiLama(int $blockIndex, int $berkasId): void
    {
        $block = $this->blocks[$blockIndex] ?? null;

        if (! $block || ! $block['id']) {
            return;
        }

        $berkas = Berkas::where('id', $berkasId)
            ->where('ref_type', Kegiatan::class)
            ->where('ref_id', $block['id'])
            ->where('status_verifikasi', 'ditolak')
            ->first();

        if (! $berkas) {
            return;
        }

        if ($berkas->path) {
            Storage::disk('local')->delete($berkas->path);
        }

        $berkas->delete();

        $this->blocks[$blockIndex]['existing_bukti'] = collect($this->blocks[$blockIndex]['existing_bukti'])
            ->reject(fn ($file) => $file['id'] === $berkasId)
            ->values()
            ->all();
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
        $this->muatBlocksKegiatan();
        $this->muatKendalaBlocks();
        $this->pilihPicOtomatis();
    }

    public function updatedBulan(): void
    {
        $this->muatFormEvaluasi();
        $this->muatBlocksKegiatan();
        $this->muatKendalaBlocks();
        $this->rtlBaru = [$this->emptyRtlBlock()];
        $this->rtlBaruBatasWaktu = $this->akhirTriwulanBerikutnya()->toDateString();
    }

    public function updatedTahun(): void
    {
        $this->muatFormEvaluasi();
        $this->muatBlocksKegiatan();
        $this->muatKendalaBlocks();
        $this->rtlBaru = [$this->emptyRtlBlock()];
        $this->rtlBaruBatasWaktu = $this->akhirTriwulanBerikutnya()->toDateString();
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
        foreach (['riwayat', 'rtl-berjalan', 'rtl-berikutnya-ada', 'rtl-berjalan-terpakai', 'capaian-status', 'catatan-penolakan', 'periode'] as $bagian) {
            Cache::forget($this->cacheKeyPeriodeIku($bagian));
        }

        foreach ($this->bagianKustomAktif() as $bagian) {
            Cache::forget($this->cacheKeyPeriodeIku("riwayat-bagian-{$bagian->id}"));
        }
    }

    /**
     * Riwayat kendala-solusi kumulatif (RF-28): seluruh pasangan milik IKU terpilih,
     * dari triwulan 1 sampai triwulan yang sedang berjalan, tahun yang sama —
     * KECUALI pasangan periode INI SENDIRI yang belum diterima Tim SAKIP (menunggu
     * baru diajukan, atau ditolak perlu diperbaiki), supaya tidak tampil dobel:
     * yang belum diterima ditampilkan sebagai blok yang BISA diedit lewat
     * kendalaBlocks (lihat muatKendalaBlocks()), bukan di sini. Pasangan periode ini
     * yang SUDAH diterima (terverifikasi) tetap tampil di sini seperti biasa —
     * itulah cara pasangan yang sudah diterima terlihat terkunci/hanya-baca.
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
            $periodeSaatIni = $this->periodeSaatIni();

            return KendalaSolusiModel::with('periode')
                ->where('iku_id', $this->iku_id)
                ->whereHas('periode', function ($q) use ($triwulanSekarang) {
                    $q->where('tahun', $this->tahun)->where('triwulan', '<=', $triwulanSekarang);
                })
                ->when($periodeSaatIni, function ($q) use ($periodeSaatIni) {
                    $q->where(fn ($q2) => $q2->where('periode_id', '!=', $periodeSaatIni->id)
                        ->orWhere('status_verifikasi', 'terverifikasi'));
                })
                ->get()
                ->sortBy(fn ($item) => $item->periode->triwulan)
                ->groupBy(fn ($item) => $item->periode->triwulan);
        });
    }

    /**
     * Riwayat kumulatif satu bagian kustom (mis. Manajemen Risiko), pola identik dengan
     * riwayatKendalaSolusi() — bagian_kustom_poin juga tidak punya status/lifecycle
     * sendiri (poin baru ditambahkan tiap submit, bukan diedit), jadi "memuat isian
     * lama" untuk bagian ini berarti menampilkan riwayatnya, bukan edit-in-place.
     *
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, BagianKustomPoin>>
     */
    protected function riwayatBagianKustom(BagianKustom $bagian)
    {
        if (! $this->iku_id) {
            return collect();
        }

        return Cache::remember($this->cacheKeyPeriodeIku("riwayat-bagian-{$bagian->id}"), self::CACHE_TTL_DETIK, function () use ($bagian) {
            $triwulanSekarang = $this->triwulanDari($this->bulan);

            return BagianKustomPoin::with(['periode', 'berkas'])
                ->where('iku_id', $this->iku_id)
                ->where('bagian_kustom_id', $bagian->id)
                ->whereHas('periode', function ($q) use ($triwulanSekarang) {
                    $q->where('tahun', $this->tahun)->where('triwulan', '<=', $triwulanSekarang);
                })
                ->get()
                ->sortBy(fn ($item) => $item->periode->triwulan)
                ->groupBy(fn ($item) => $item->periode->triwulan);
        });
    }

    /**
     * Catatan penolakan Tim SAKIP untuk IKU+periode terpilih, dikumpulkan lintas
     * SEMUA kategori berkas (bukti kegiatan, bukti solusi, bukti evaluasi RTL, bukti
     * bagian kustom) — dipakai untuk banner ringkasan di puncak form saat isian
     * periode ini berstatus dikembalikan. Pola pengumpulan mirip
     * VerifikasiCapaian::berkasList(), tapi di-scope ke iku_id+periode (bukan
     * capaian_id) karena komponen ini belum tentu sedang menampilkan satu Capaian.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    protected function catatanPenolakan()
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        if (! $periode) {
            return collect();
        }

        // Kegiatan yang dihitung di sini SELALU baris yang sudah tersimpan (id
        // terisi) — blok kosong yang baru ditambah lewat addBlock() punya id null
        // dan sudah dibuang lewat filter(), jadi cache di bawah tetap benar walau
        // $this->blocks berubah bentuk tanpa iku/periode ikut berubah.
        //
        // Di-cache seperti data lain di cacheKeyPeriodeIku() — sebelumnya method ini
        // TIDAK di-cache sama sekali padahal dipanggil di SETIAP render() (yaitu di
        // setiap aksi Livewire, termasuk yang sepele seperti addBlock()), jadi tiap
        // klik membayar ~5 query ke DB remote hanya untuk banner catatan penolakan
        // yang isinya nyaris selalu sama dalam rentang TTL yang sama.
        return Cache::remember($this->cacheKeyPeriodeIku('catatan-penolakan'), self::CACHE_TTL_DETIK, function () use ($periode) {
            $kegiatanIds = collect($this->blocks)->pluck('id')->filter();
            $kendalaIds = KendalaSolusiModel::where('iku_id', $this->iku_id)->where('periode_id', $periode->id)->pluck('id');
            $bagianKustomIds = BagianKustomPoin::where('iku_id', $this->iku_id)->where('periode_id', $periode->id)->pluck('id');
            $rtlIds = $this->rtlTriwulanBerjalan()->pluck('id');

            $catatanBerkas = Berkas::where('status_verifikasi', 'ditolak')
                ->where(function ($q) use ($kegiatanIds, $kendalaIds, $bagianKustomIds, $rtlIds) {
                    $q->where(fn ($q2) => $q2->where('ref_type', Kegiatan::class)->whereIn('ref_id', $kegiatanIds))
                        ->orWhere(fn ($q2) => $q2->where('ref_type', KendalaSolusiModel::class)->whereIn('ref_id', $kendalaIds))
                        ->orWhere(fn ($q2) => $q2->where('ref_type', BagianKustomPoin::class)->whereIn('ref_id', $bagianKustomIds))
                        ->orWhere(fn ($q2) => $q2->where('ref_type', RtlEvaluasiModel::class)->whereIn('ref_id', $rtlIds));
                })
                ->pluck('catatan');

            // Kendala & Solusi tidak lagi punya bukti dukung sendiri (RF-27 dicabut) —
            // penolakannya langsung disimpan di kolom catatan miliknya sendiri (lihat
            // App\Livewire\VerifikasiCapaian::tandaiKendalaTolak()), bukan lewat Berkas.
            $catatanKendala = KendalaSolusiModel::whereIn('id', $kendalaIds)
                ->where('status_verifikasi', 'ditolak')
                ->pluck('catatan');

            return $catatanBerkas->concat($catatanKendala)
                ->filter()
                ->unique()
                ->values();
        });
    }

    /**
     * Siapkan form bukti realisasi untuk SEMUA poin RTL triwulan ini (bukan hanya yang
     * belum ada buktinya) — poin yang sudah punya bukti tetap boleh ditambahkan lagi,
     * tidak dikunci hanya-baca seperti alur lama berbasis teks realisasi.
     *
     * RTL yang dievaluasi di sini adalah RTL YANG SAMA dengan sumber dropdown uraian
     * kegiatan (rtlTriwulanBerjalan()) — yaitu poin yang DITETAPKAN pada triwulan
     * sebelumnya untuk DILAKSANAKAN pada triwulan berjalan ini. Sebelumnya bagian ini
     * keliru mengambil dari periode triwulan-1 (satu triwulan terlalu jauh ke belakang,
     * poin yang seharusnya sudah dievaluasi saat mengisi triwulan itu sendiri).
     */
    protected function muatFormEvaluasi(): void
    {
        $this->evaluasi = [];

        foreach ($this->rtlTriwulanBerjalan() as $poin) {
            $this->evaluasi[$poin->id] = ['bukti' => []];
        }
    }

    /**
     * Periode tahun+bulan yang sedang dipilih di form — lookup saja (BEDA dari
     * firstOrCreate di simpanDraft()/ajukanIsian()), supaya membuka form untuk
     * melihat/memuat data tidak ikut membuat baris periode baru yang belum tentu
     * jadi dipakai.
     *
     * Dipanggil berkali-kali dalam satu request yang sama (statusCapaianSaatIni,
     * muatBlocksKegiatan, muatKendalaBlocks, catatanPenolakan, riwayatKendalaSolusi,
     * dst.) — di-cache per-request seperti ikuTerpilih() supaya tiap pemanggilan
     * tidak membayar query DB remote sendiri-sendiri (sebelumnya bisa 6-7 query
     * identik untuk satu aksi "pilih IKU" saja, bikin form terasa sangat lambat
     * memuat isian yang sudah ada).
     */
    protected function periodeSaatIni(): ?Periode
    {
        if ($this->cachePeriodeSaatIniDihitung) {
            return $this->cachePeriodeSaatIni;
        }

        $this->cachePeriodeSaatIniDihitung = true;

        if (! $this->iku_id) {
            return $this->cachePeriodeSaatIni = Periode::where('tahun', $this->tahun)->where('bulan', $this->bulan)->first();
        }

        // Cache::remember lintas-request (bukan cuma memoisasi di atas) — periode
        // hampir tidak pernah berubah selama Ketua Tim mengisi form, tapi sebelumnya
        // di-query ULANG di SETIAP request Livewire (tiap klik/blur), jadi ini yang
        // bikin form terasa sangat lambat memuat isian yang sudah ada.
        //
        // Dibungkus array — Cache::remember() TIDAK BISA membedakan "belum pernah
        // di-cache" dari "sudah di-cache tapi nilainya null" (keduanya baca sebagai
        // null dari store), jadi kalau periode belum ada (nilai asli null) closure-nya
        // akan diulang TIAP request, cache-nya tidak pernah benar-benar kepakai.
        // Membungkusnya dalam array membuat nilai yang disimpan selalu non-null.
        return $this->cachePeriodeSaatIni = Cache::remember(
            $this->cacheKeyPeriodeIku('periode'),
            self::CACHE_TTL_DETIK,
            fn () => ['periode' => Periode::where('tahun', $this->tahun)->where('bulan', $this->bulan)->first()]
        )['periode'];
    }

    /**
     * Status Capaian (satu status per IKU+bulan, lihat App\Models\Capaian) milik
     * IKU+periode yang sedang dipilih di form ini — null bila belum pernah ada isian
     * sama sekali untuk kombinasi ini. Di-cache 60 detik seperti data lain di
     * cacheKeyPeriodeIku() supaya addBlock()/render() yang dipanggil berkali-kali
     * tidak membayar query DB remote tiap klik (lihat lupakanCachePeriodeIku()).
     */
    protected function statusCapaianSaatIni(): ?string
    {
        if (! $this->iku_id) {
            return null;
        }

        return Cache::remember(
            $this->cacheKeyPeriodeIku('capaian-status'),
            self::CACHE_TTL_DETIK,
            function () {
                $periode = $this->periodeSaatIni();

                if (! $periode) {
                    return null;
                }

                return Capaian::where('iku_id', $this->iku_id)->where('periode_id', $periode->id)->value('status');
            }
        );
    }

    /**
     * Sekali Capaian berstatus "disetujui" (sudah masuk notula final yang
     * ditandatangani Kepala), isian ini dikunci TOTAL — Ketua Tim tidak bisa lagi
     * menambah kegiatan maupun menyimpan draf/mengajukan lewat form ini sama sekali,
     * supaya notula yang sudah final tidak diam-diam "terbuka" lagi. Kalau memang
     * perlu revisi, Tim SAKIP harus membuka kembali secara eksplisit dulu (lihat
     * VerifikasiCapaian::bukaKembali()), yang menariknya ke status "dikembalikan".
     *
     * Dipakai untuk UI (sembunyikan/nonaktifkan tombol) — SELALU dicek ULANG tanpa
     * cache di simpanDraft()/ajukanIsian() sebelum benar-benar menyimpan apa pun
     * (defense in depth), karena cache 60 detik di atas bisa saja basi kalau Tim
     * SAKIP baru saja menyetujui dari halaman lain.
     */
    public function formTerkunciDisetujui(): bool
    {
        return $this->statusCapaianSaatIni() === Capaian::STATUS_DISETUJUI;
    }

    /**
     * Versi TANPA cache dari formTerkunciDisetujui() — satu-satunya yang boleh dipakai
     * sebagai gerbang sebelum benar-benar menyimpan (simpanDraft()/ajukanIsian()).
     */
    protected function formTerkunciDisetujuiFresh(): bool
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        if (! $periode) {
            return false;
        }

        $status = Capaian::where('iku_id', $this->iku_id)->where('periode_id', $periode->id)->value('status');

        return $status === Capaian::STATUS_DISETUJUI;
    }

    /**
     * Muat kegiatan (+ berkas) yang sudah ada untuk IKU+periode terpilih ke $this->blocks
     * supaya isian yang pernah dibuat (termasuk yang dikembalikan Tim SAKIP) tampil lagi
     * saat form dibuka ulang — bukan selalu kosong seperti sebelumnya. Kegiatan berstatus
     * 'diajukan'/'diverifikasi'/'disetujui' tetap dimuat tapi ditandai lewat status_dokumen
     * supaya blade merendernya hanya-baca (lihat STATUS_KEGIATAN_TERKUNCI).
     */
    protected function muatBlocksKegiatan(): void
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        if (! $periode) {
            $this->blocks = [$this->emptyBlock()];

            return;
        }

        $kegiatanList = Kegiatan::where('iku_id', $this->iku_id)
            ->where('periode_id', $periode->id)
            ->with('berkas')
            ->orderBy('id')
            ->get();

        if ($kegiatanList->isEmpty()) {
            $this->blocks = [$this->emptyBlock()];

            return;
        }

        $this->blocks = $kegiatanList->map(fn (Kegiatan $kegiatan) => [
            'id' => $kegiatan->id,
            'status_dokumen' => $kegiatan->status_dokumen,
            'uraian_kegiatan' => $kegiatan->uraian_kegiatan,
            'jenis' => $kegiatan->jenis,
            'tahapan_survei' => $kegiatan->tahapan_survei ?? '',
            'bukti' => [],
            'existing_bukti' => $kegiatan->berkas->map(fn (Berkas $b) => [
                'id' => $b->id,
                'nama_file' => $b->nama_file,
                'status_verifikasi' => $b->status_verifikasi,
                'catatan' => $b->catatan,
            ])->all(),
        ])->values()->all();
    }

    /**
     * Pasangan kendala &amp; solusi milik periode ini SENDIRI yang BELUM diterima
     * Tim SAKIP ("menunggu" baru diajukan, atau "ditolak" perlu diperbaiki) — beda
     * dari Kegiatan, pasangan yang sudah "terverifikasi" (diterima) TIDAK ikut
     * dimuat ke sini (terkunci, tidak boleh diedit lagi), cukup tampil di
     * riwayatKendalaSolusi() sebagai riwayat hanya-baca. Dipanggil sejajar dengan
     * muatBlocksKegiatan() supaya isian lama (draft/ditolak) tetap ada saat form
     * dibuka ulang, bukan selalu kosong.
     */
    protected function muatKendalaBlocks(): void
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        if (! $periode) {
            $this->kendalaBlocks = [$this->emptyKendalaBlock()];

            return;
        }

        $daftar = KendalaSolusiModel::where('iku_id', $this->iku_id)
            ->where('periode_id', $periode->id)
            ->where('status_verifikasi', '!=', 'terverifikasi')
            ->orderBy('id')
            ->get();

        if ($daftar->isEmpty()) {
            $this->kendalaBlocks = [$this->emptyKendalaBlock()];

            return;
        }

        $this->kendalaBlocks = $daftar->map(fn (KendalaSolusiModel $ks) => [
            'id' => $ks->id,
            'kendala' => $ks->kendala,
            'solusi' => $ks->solusi ?? '',
            'status_verifikasi' => $ks->status_verifikasi,
            'catatan' => $ks->catatan,
        ])->values()->all();
    }

    public function removeBuktiEvaluasi(int $rtlId, int $fileIndex): void
    {
        unset($this->evaluasi[$rtlId]['bukti'][$fileIndex]);
        $this->evaluasi[$rtlId]['bukti'] = array_values($this->evaluasi[$rtlId]['bukti']);
    }

    /**
     * RTL yang ditetapkan pada triwulan SEBELUMNYA untuk dilaksanakan pada triwulan
     * BERJALAN ini — satu-satunya sumber untuk: (1) dropdown/saran uraian kegiatan,
     * (2) bagian Evaluasi RTL (bukti realisasi), dan (3) validasi "wajib terlaksana
     * semua" di akhir triwulan. Ketiganya sengaja memakai koleksi yang sama persis.
     */
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

                return RtlEvaluasiModel::with(['periode', 'berkas'])
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
     * Saran nama PIC untuk IKU terpilih (datalist di form) — sumbernya Master IKU (kolom
     * tim) + keanggotaan tim/penugasan manual. Hanya saran; rtlBaruPic tetap teks bebas.
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

            'kendalaBlocks.*.kendala' => ['nullable', 'string'],
            'kendalaBlocks.*.solusi' => ['nullable', 'string'],
        ];

        // Bukti capaian per blok kegiatan — wajib minimal 1 HANYA bila blok itu belum
        // punya berkas tersimpan sama sekali (existing_bukti, dimuat di
        // muatBlocksKegiatan()); blok yang sudah punya bukti lama tidak dipaksa
        // mengunggah bukti BARU lagi cuma untuk mengedit teks/mengajukan ulang.
        // Blok terkunci (sudah diajukan/diverifikasi/disetujui) dilewati sama sekali
        // dari loop simpan (lihat simpanDraft()/ajukanIsian()), jadi aturannya cukup
        // dibuat nullable saja di sini.
        foreach ($this->blocks as $i => $block) {
            $terkunci = in_array($block['status_dokumen'] ?? null, self::STATUS_KEGIATAN_TERKUNCI, true);
            $adaBuktiLama = ! empty($block['existing_bukti']);

            $rules["blocks.{$i}.bukti"] = ($terkunci || $adaBuktiLama)
                ? ['nullable', 'array']
                : ['required', 'array', 'min:1'];
            $rules["blocks.{$i}.bukti.*"] = ['file', 'mimes:pdf', 'max:10240'];
        }

        // Bagian kustom (mis. Manajemen Risiko): poin kosong dilewati saat disimpan
        // (sama seperti kendalaBlocks); poin yang TERISI wajib punya bukti dukung HANYA
        // bila bagiannya dikonfigurasi bukti_wajib (bisa dimatikan Tim SAKIP per bagian).
        foreach ($this->bagianKustomAktif() as $bagian) {
            $rules["bagianKustomBlocks.{$bagian->id}.*.teks"] = ['nullable', 'string'];
            $rules["bagianKustomBlocks.{$bagian->id}.*.bukti"] = $bagian->bukti_wajib
                ? ['required_with:bagianKustomBlocks.'.$bagian->id.'.*.teks', 'array']
                : ['nullable', 'array'];
            $rules["bagianKustomBlocks.{$bagian->id}.*.bukti.*"] = ['file', 'mimes:pdf', 'max:10240'];
        }

        // RF-29/31: poin RTL triwulan sebelumnya cukup ditampilkan + tempat unggah bukti —
        // WAJIB SEMUA (bukan cuma satu) sudah punya bukti sebelum bisa diajukan, tapi
        // aturan itu hanya digerbang pada bulan TERAKHIR triwulan (dicek di buatValidator()).
        foreach ($this->evaluasi as $rtlId => $data) {
            $rules["evaluasi.{$rtlId}.bukti.*"] = ['file', 'mimes:pdf', 'max:10240'];
        }

        // RF-32/33/34: RTL triwulan berikutnya hanya boleh (dan wajib) ditetapkan pada bulan
        // terakhir triwulan berjalan, kecuali sudah pernah ditetapkan sebelumnya.
        if ($this->rtlBaruBisaDiisi() && ! $this->rtlTriwulanBerikutnyaSudahAda()) {
            $rules['rtlBaru'] = ['required', 'array', 'min:1'];
            $rules['rtlBaru.*.rtl_teks'] = ['required', 'string'];
            $rules['rtlBaruPic'] = ['required', 'string', 'max:255'];
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
            'rtlBaru.*.rtl_teks' => 'RTL',
            'rtlBaruPic' => 'PIC Tindak Lanjut',
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
            // RF-29/31: SEMUA poin RTL triwulan sebelumnya wajib punya bukti realisasi
            // (sudah tersimpan ATAU baru dipilih di form ini) — tapi HANYA digerbang pada
            // bulan TERAKHIR triwulan berjalan, sama seperti aturan bagian kustom di bawah.
            if ($this->bulanKeDari($this->bulan) === 3) {
                $poinTanpaBukti = $this->rtlTriwulanBerjalan()->reject(function ($poin) {
                    $buktiBaru = $this->evaluasi[$poin->id]['bukti'] ?? [];

                    return $poin->sudahDievaluasi() || ! empty($buktiBaru);
                });

                if ($poinTanpaBukti->isNotEmpty()) {
                    $validator->errors()->add(
                        'evaluasi',
                        "Masih ada {$poinTanpaBukti->count()} poin evaluasi RTL triwulan sebelumnya yang belum diberi bukti realisasi — wajib diunggah semua sebelum diajukan pada bulan terakhir triwulan ini."
                    );
                }
            }

            $belumTerlaksana = $this->poinRtlBerjalanBelumTerlaksana();

            if ($belumTerlaksana->isNotEmpty()) {
                $daftar = $belumTerlaksana->pluck('rtl_teks')->implode('; ');
                $validator->errors()->add(
                    'blocks',
                    "Masih ada {$belumTerlaksana->count()} poin RTL triwulan berjalan yang belum dilaksanakan sebagai kegiatan: {$daftar}."
                );
            }

            // Bagian kustom: minimal satu poin wajib terisi sesuai frekuensi yang
            // dikonfigurasi Tim SAKIP (setiap bulan, atau hanya bulan TERAKHIR triwulan
            // seperti syarat RTL baru) — 'opsional' tidak pernah menggerbang apa pun.
            $bulanKeSekarang = $this->bulanKeDari($this->bulan);

            foreach ($this->bagianKustomAktif() as $bagian) {
                if (! $bagian->wajibDiisiPada($bulanKeSekarang)) {
                    continue;
                }

                $adaTerisi = collect($this->bagianKustomBlocks[$bagian->id] ?? [])
                    ->contains(fn ($blok) => filled($blok['teks'] ?? null));

                if (! $adaTerisi) {
                    $pesanWaktu = $bagian->frekuensi_wajib === 'setiap_bulan'
                        ? 'bulan ini'
                        : 'sebelum diajukan pada bulan terakhir triwulan ini';

                    $validator->errors()->add(
                        "bagianKustomBlocks.{$bagian->id}",
                        "Minimal satu poin \"{$bagian->nama}\" wajib diisi {$pesanWaktu}."
                    );
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
     * Simpan sementara sebagai draft — hanya kegiatan yang divalidasi (IKU + uraian +
     * jenis kegiatan), bukti capaian TIDAK wajib pada tahap ini. Kegiatan tersimpan
     * dengan status "draft" (belum diajukan ke Tim SAKIP); kendala-solusi, evaluasi
     * RTL, dan RTL baru baru divalidasi & disimpan saat benar-benar "Ajukan ke Tim SAKIP".
     */
    public function simpanDraft(): void
    {
        if ($this->formTerkunciDisetujuiFresh()) {
            $this->dispatch('notify', type: 'error', message: 'Isian ini sudah disetujui dan terkunci — hubungi Tim SAKIP untuk membuka kembali bila perlu revisi.');

            return;
        }

        try {
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
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->dispatch('notify', type: 'error', message: 'Draf gagal disimpan — lengkapi data yang wajib diisi lebih dulu.');

            throw $e;
        }

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
                // Blok yang sudah diajukan/diverifikasi/disetujui ditampilkan hanya-baca
                // di form (lihat muatBlocksKegiatan()) — tidak boleh ikut tertimpa di sini.
                if (in_array($block['status_dokumen'] ?? null, self::STATUS_KEGIATAN_TERKUNCI, true)) {
                    continue;
                }

                $tahapan = $block['jenis'] === 'survei_sensus' ? $block['tahapan_survei'] : null;

                $namaFolderAuto = Str::limit(
                    ($tahapan ? '['.ucfirst($tahapan).'] ' : '').$block['uraian_kegiatan'],
                    100,
                    ''
                );

                if ($block['id']) {
                    // UPDATE, bukan create — supaya kegiatan draft/dikembalikan yang sudah
                    // ada tidak diduplikasi tiap kali draf disimpan ulang. status_dokumen &
                    // drive_folder_id SENGAJA tidak disentuh (drive_folder_id dipakai ulang
                    // oleh FolderStructureService, lihat catatan di model Kegiatan).
                    Kegiatan::whereKey($block['id'])->update([
                        'uraian_kegiatan' => $block['uraian_kegiatan'],
                        'jenis' => $block['jenis'],
                        'tahapan_survei' => $tahapan,
                        'nama_folder_auto' => $namaFolderAuto,
                    ]);
                } else {
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
            }
        });

        session()->flash('status', 'Draf kegiatan berhasil disimpan. Lengkapi bukti & bagian lain lalu ajukan ke Tim SAKIP saat siap.');

        $this->lupakanCachePeriodeIku();

        // Reload halaman penuh — lihat catatan senada di akhir ajukanIsian().
        $this->redirect(route('pengisian.index'));
    }

    /**
     * Tampilkan "sedang mengunggah X…" SAAT ITU JUGA, sebelum panggilan ke Drive API
     * (yang bisa memakan waktu beberapa detik per berkas) dimulai — dipakai supaya Ketua
     * Tim tidak cuma melihat tombol "Mengirim…" polos selama proses lambat, tapi tahu
     * persis berkas mana yang sedang diproses.
     *
     * Dikirim lewat $this->stream() (fitur bawaan Livewire untuk mendorong pembaruan
     * HTML ke browser SEBELUM action selesai, lewat response HTTP yang di-flush
     * bertahap) — BUKAN $this->dispatch(), karena event dispatch baru sampai ke
     * browser bersama HTML/efek akhir setelah SELURUH ajukanIsian() selesai (termasuk
     * seluruh sisa berkas & transaksi DB), jadi terasa seperti "diam saja" selama
     * proses berjalan meski sebenarnya sedang bekerja.
     */
    protected function streamProgresUnggah(string $namaFile, string $konteks): void
    {
        $this->stream(
            to: 'progres-unggah',
            content: '<span class="progres-unggah-item">📤 Mengunggah ke Google Drive: <b>'.e($namaFile).'</b> ('.e($konteks).')…</span>',
            replace: true,
        );
    }

    /**
     * Satu toast per berkas yang selesai diproses ke Google Drive (RF baru) — dipanggil
     * langsung di dalam transaksi ajukanIsian() supaya Ketua Tim tahu PERSIS berkas mana
     * yang berhasil/gagal saat itu juga, bukan cuma ringkasan jumlah di akhir (lihat
     * $driveGagal, yang tetap dipertahankan untuk rincian di flash banner). Beda dari
     * streamProgresUnggah(): toast ini baru benar-benar tampil di browser di AKHIR
     * request (lihat catatan di atas), jadi tetap berguna sebagai rekap, bukan sebagai
     * indikator real-time.
     */
    protected function notifikasiHasilUnggah(string $namaFile, string $konteks, bool $berhasil, ?string $alasan = null): void
    {
        $this->dispatch(
            'notify',
            type: $berhasil ? 'success' : 'error',
            message: $berhasil
                ? "✅ {$namaFile} ({$konteks}) tersalin ke Google Drive."
                : "⚠️ {$namaFile} ({$konteks}) GAGAL tersalin ke Google Drive, disimpan lokal saja".($alasan ? ': '.Str::limit($alasan, 120) : '.')
        );
    }

    public function ajukanIsian(): void
    {
        if ($this->formTerkunciDisetujuiFresh()) {
            $this->dispatch('notify', type: 'error', message: 'Isian ini sudah disetujui dan terkunci — hubungi Tim SAKIP untuk membuka kembali bila perlu revisi.');

            return;
        }

        try {
            $this->buatValidator()->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->dispatch('notify', type: 'error', message: 'Belum bisa diajukan — masih ada data wajib yang belum lengkap.');

            throw $e;
        }

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

        // Diisi setiap kali satu berkas GAGAL disinkron ke Drive (jaringan, kredensial
        // Drive belum/tidak lagi valid, dst.) — berkasnya tetap aman tersimpan lokal dan
        // isian TETAP berhasil diajukan, tapi Ketua Tim perlu tahu supaya tidak mengira
        // semuanya sudah tersalin ke Drive padahal belum (lihat flash 'driveGagal' di bawah).
        $driveGagal = [];

        DB::transaction(function () use ($periode, $iku, $folderService, &$driveGagal) {
            // Angka capaian (RF-38) milik IKU+periode, dibagikan seluruh kegiatan di
            // bawahnya — cukup disiapkan kosong di sini, diisi Tim SAKIP saat verifikasi.
            // Unique iku_id+periode_id berarti kegiatan tambahan yang diajukan belakangan
            // pada IKU+bulan yang sama menemukan baris Capaian YANG SAMA di sini, jadi
            // riwayat statusnya (catatStatus() di bawah) otomatis tergabung satu poin.
            $capaian = Capaian::firstOrCreate([
                'iku_id' => $this->iku_id,
                'periode_id' => $periode->id,
            ]);

            // Dicatat true hanya bila benar-benar ada kegiatan yang berpindah status ke
            // "diajukan" di request ini — supaya mengklik "Ajukan" saat semua blok sudah
            // terkunci (tidak ada perubahan sama sekali) tidak menambah riwayat kosong.
            $adaKegiatanDiajukan = false;

            // 1) Kegiatan + bukti capaian (bukti melekat langsung ke kegiatan, RF-23)
            foreach ($this->blocks as $block) {
                // Blok yang sudah diajukan/diverifikasi/disetujui ditampilkan hanya-baca
                // di form (lihat muatBlocksKegiatan()) — tidak boleh ikut tertimpa di sini.
                if (in_array($block['status_dokumen'] ?? null, self::STATUS_KEGIATAN_TERKUNCI, true)) {
                    continue;
                }

                $tahapan = $block['jenis'] === 'survei_sensus' ? $block['tahapan_survei'] : null;

                $namaFolderAuto = Str::limit(
                    ($tahapan ? '['.ucfirst($tahapan).'] ' : '').$block['uraian_kegiatan'],
                    100,
                    ''
                );

                if ($block['id']) {
                    // UPDATE kegiatan draft/dikembalikan yang sudah ada, bukan create baru
                    // — supaya mengajukan ulang isian yang dikembalikan Tim SAKIP tidak
                    // membuat baris duplikat. drive_folder_id SENGAJA tidak disentuh, lihat
                    // catatan senada di simpanDraft().
                    $kegiatan = Kegiatan::findOrFail($block['id']);
                    $kegiatan->update([
                        'rtl_evaluasi_id' => $this->cariRtlBerjalanCocok($block['uraian_kegiatan']),
                        'uraian_kegiatan' => $block['uraian_kegiatan'],
                        'jenis' => $block['jenis'],
                        'tahapan_survei' => $tahapan,
                        'nama_folder_auto' => $namaFolderAuto,
                    ]);
                } else {
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
                }

                // draft→diajukan maupun dikembalikan→diajukan, keduanya diizinkan
                // (lihat Kegiatan::TRANSITIONS) — tidak perlu logic tambahan di sini.
                $kegiatan->ajukan();
                $adaKegiatanDiajukan = true;

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
                    // \Throwable (bukan cuma RuntimeException) sengaja dipakai supaya galat
                    // dari Google API sendiri (mis. Google\Service\Exception saat token/scope
                    // bermasalah) juga tertangkap di sini, bukan membatalkan SELURUH transaksi
                    // pengajuan hanya karena Drive sedang bermasalah.
                    try {
                        $this->streamProgresUnggah($file->getClientOriginalName(), 'bukti kegiatan');
                        $localFullPath = Storage::disk('local')->path($path);
                        $hasilDrive = $folderService->unggahBerkasKegiatan($kegiatan, 'capaian', $localFullPath);
                        $berkas->update($hasilDrive);
                        $this->notifikasiHasilUnggah($file->getClientOriginalName(), 'bukti kegiatan', true);
                    } catch (\Throwable $e) {
                        Log::warning('Gagal mengunggah berkas ke Google Drive, disimpan lokal saja: '.$e->getMessage());
                        $driveGagal[] = "{$file->getClientOriginalName()} (bukti kegiatan: {$block['uraian_kegiatan']})";
                        $this->notifikasiHasilUnggah($file->getClientOriginalName(), 'bukti kegiatan', false, $e->getMessage());
                    }
                }
            }

            if ($adaKegiatanDiajukan) {
                $capaian->catatStatus(Kegiatan::STATUS_DIAJUKAN, auth()->user());
            }

            // 2) Kendala & Solusi (blok kosong dilewati — bagian ini opsional per periode).
            // UPDATE, bukan create, bila blok ini punya id (pasangan lama yang ditolak
            // Tim SAKIP dan sedang diperbaiki — lihat muatKendalaBlocks()), supaya tidak
            // duplikat; status_verifikasi ditarik balik ke "menunggu" karena diajukan
            // ulang, mengikuti pola yang sama dengan Kegiatan::ajukan().
            foreach ($this->kendalaBlocks as $block) {
                if (trim($block['kendala']) === '' && trim($block['solusi']) === '') {
                    continue;
                }

                if ($block['id'] ?? null) {
                    KendalaSolusiModel::whereKey($block['id'])->update([
                        'kendala' => $block['kendala'],
                        'solusi' => $block['solusi'] ?: null,
                        'status_verifikasi' => 'menunggu',
                        'catatan' => null,
                    ]);
                } else {
                    KendalaSolusiModel::create([
                        'iku_id' => $this->iku_id,
                        'periode_id' => $periode->id,
                        'kendala' => $block['kendala'],
                        'solusi' => $block['solusi'] ?: null,
                    ]);
                }
            }

            // 3) Evaluasi RTL triwulan sebelumnya — cukup bukti realisasi (SEMUA poin wajib
            // punya bukti bila sedang mengajukan pada bulan terakhir triwulan, dicek di
            // buatValidator()); baris tanpa bukti baru dilewati begitu saja di sini.
            foreach ($this->evaluasi as $rtlId => $data) {
                if (empty($data['bukti'])) {
                    continue;
                }

                $poin = RtlEvaluasiModel::with(['periode', 'masterIku'])->findOrFail($rtlId);

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
                        $this->streamProgresUnggah($file->getClientOriginalName(), 'bukti evaluasi RTL');
                        $localFullPath = Storage::disk('local')->path($path);
                        $hasilDrive = $folderService->unggahBerkas($poin->periode, $poin->masterIku, 'evaluasi_rtl', $localFullPath);
                        $berkas->update($hasilDrive);
                        $this->notifikasiHasilUnggah($file->getClientOriginalName(), 'bukti evaluasi RTL', true);
                    } catch (\Throwable $e) {
                        Log::warning('Gagal mengunggah bukti evaluasi RTL ke Google Drive, disimpan lokal saja: '.$e->getMessage());
                        $driveGagal[] = "{$file->getClientOriginalName()} (bukti evaluasi RTL)";
                        $this->notifikasiHasilUnggah($file->getClientOriginalName(), 'bukti evaluasi RTL', false, $e->getMessage());
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
                        'pic' => trim($this->rtlBaruPic),
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
                            $this->streamProgresUnggah($file->getClientOriginalName(), "bukti {$bagian->nama}");
                            $localFullPath = Storage::disk('local')->path($path);
                            $hasilDrive = $folderService->unggahBerkas(
                                $periode, $iku, 'bagian_kustom', $localFullPath, namaFolderOverride: $bagian->nama
                            );
                            $berkas->update($hasilDrive);
                            $this->notifikasiHasilUnggah($file->getClientOriginalName(), "bukti {$bagian->nama}", true);
                        } catch (\Throwable $e) {
                            Log::warning("Gagal mengunggah bukti {$bagian->nama} ke Google Drive, disimpan lokal saja: ".$e->getMessage());
                            $driveGagal[] = "{$file->getClientOriginalName()} (bukti {$bagian->nama})";
                            $this->notifikasiHasilUnggah($file->getClientOriginalName(), "bukti {$bagian->nama}", false, $e->getMessage());
                        }
                    }
                }
            }
        });

        $this->stream(to: 'progres-unggah', content: '', replace: true);

        session()->flash(
            'status',
            'Isian kegiatan, kendala & solusi, dan evaluasi RTL berhasil diajukan ke Tim SAKIP. '
                .(empty($driveGagal)
                    ? 'Semua berkas bukti berhasil disalin ke Google Drive.'
                    : 'Sebagian berkas bukti BELUM tersalin ke Google Drive — lihat rincian di bawah.')
        );

        if (! empty($driveGagal)) {
            session()->flash('driveGagal', $driveGagal);
            $this->dispatch('notify', type: 'warning', message: 'Diajukan ke Tim SAKIP, tapi '.count($driveGagal).' berkas belum tersalin ke Google Drive — lihat rincian di bawah.');
        } else {
            $this->dispatch('notify', type: 'success', message: 'Berhasil diajukan ke Tim SAKIP. Semua berkas bukti tersalin ke Google Drive.');
        }

        $this->lupakanCachePeriodeIku();

        // Muat ulang HALAMAN PENUH (bukan cuma me-reset properti komponen) supaya
        // tampilan benar-benar persis seperti pertama kali menu ini dibuka (mount()
        // jalan lagi dari nol) — termasuk state Alpine (toasts, x-data lain) dan
        // input file HTML yang tidak selalu ikut ter-reset kalau cuma properti
        // Livewire-nya yang dikosongkan. Flash 'status'/'driveGagal' di atas tetap
        // tersimpan lewat session dan tampil di halaman baru setelah reload.
        $this->redirect(route('pengisian.index'));
    }

    public function render()
    {
        $triwulan = $this->triwulanDari($this->bulan);
        $bagianKustomAktif = $this->bagianKustomAktif();

        return view('livewire.pengisian-kegiatan', [
            'ikuList' => MasterIku::daftarUrutKode(),
            'triwulan' => $triwulan,
            'bulanKe' => $this->bulanKeDari($this->bulan),
            'flagTerlewat' => $this->isBulanTerlewat(),
            'periodeLabel' => Carbon::create($this->tahun, $this->bulan, 1)->locale('id')->translatedFormat('F Y'),
            'riwayatKendala' => $this->riwayatKendalaSolusi(),
            'rtlSebelumnya' => $this->rtlTriwulanBerjalan(),
            'sudahAdaRtlBerikutnya' => $this->rtlTriwulanBerikutnyaSudahAda(),
            'labelBerikutnya' => $this->labelTriwulanBerikutnya(),
            'bulanTargetBerikutnya' => collect($this->bulanBulanTarget())->mapWithKeys(fn ($b) => [$b => $this->namaBulanIndo($b)]),
            'rtlBerjalanOptions' => $this->rtlBerjalanOptions(),
            'rtlBerjalanBelumTerlaksana' => $this->poinRtlBerjalanBelumTerlaksana(),
            'picOptions' => $this->picOptions(),
            'namaTimIku' => $this->ikuTerpilih()?->tim,
            'bagianKustomAktif' => $bagianKustomAktif,
            'riwayatBagianKustom' => $bagianKustomAktif->mapWithKeys(fn ($b) => [$b->id => $this->riwayatBagianKustom($b)]),
            'statusKegiatanTerkunci' => self::STATUS_KEGIATAN_TERKUNCI,
            'catatanPenolakan' => $this->catatanPenolakan(),
            'adaDikembalikan' => collect($this->blocks)->contains(fn ($b) => ($b['status_dokumen'] ?? null) === Kegiatan::STATUS_DIKEMBALIKAN)
                || collect($this->kendalaBlocks)->contains(fn ($b) => ($b['status_verifikasi'] ?? null) === 'ditolak'),
            'formTerkunciDisetujui' => $this->formTerkunciDisetujui(),
        ]);
    }
}
