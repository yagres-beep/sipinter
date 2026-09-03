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
use App\Models\RiwayatStatusCapaian;
use App\Models\RtlEvaluasi as RtlEvaluasiModel;
use App\Services\FolderStructureService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Livewire\Component;
use Livewire\WithFileUploads;

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
     * PIC Tindak Lanjut — tim (BUKAN perorangan) penanggung jawab, boleh lebih dari
     * satu, ditambah/dihapus satu per satu lewat chip (lihat tambahRtlBaruPic()/
     * hapusRtlBaruPic()) sama pola UX-nya dengan App\Livewire\AkunAktif::tambahTim().
     * Diisi otomatis dari tim penanggung jawab IKU terpilih (lihat
     * pilihPicOtomatis()) tapi BOLEH diubah bebas oleh Ketua Tim -- pilih dari
     * daftar tim yang ada (lihat daftarTimPic()) ATAU ketik nama tim baru sendiri.
     * TIDAK wajib diisi di sini — kalau dikosongkan, Tim SAKIP yang wajib
     * mengisi/mengonfirmasinya saat verifikasi (lihat
     * App\Livewire\VerifikasiCapaian::verifikasiSelesai()), supaya Ketua Tim tidak
     * pernah terhalang mengajukan isian hanya gara-gara tim IKU ini belum
     * dikonfigurasi (MasterIku::tim kosong).
     *
     * @var list<string>
     */
    public array $rtlBaruPicTerpilih = [];

    /** Input "tambah tim" untuk rtlBaruPicTerpilih -- lihat tambahRtlBaruPic(). */
    public string $rtlBaruPicBaru = '';

    public string $rtlBaruBatasWaktu = '';

    /**
     * Cache dalam satu siklus request saja (di-reset otomatis tiap request baru karena
     * properti non-public tidak ikut disinkronkan Livewire) — beberapa query berat
     * (RTL berjalan, IKU terpilih, dst.) dipakai ulang di banyak tempat saat render()
     * DAN formLengkap() dipanggil di request yang sama; tanpa cache ini query yang sama
     * terulang belasan kali per klik/blur dan bikin halaman terasa lambat.
     */
    protected ?Collection $cacheRtlBerjalan = null;

    protected ?bool $cacheRtlBerikutnyaSudahAda = null;

    protected ?Collection $cacheRtlBerikutnyaDitolak = null;

    protected ?Collection $cacheRtlBerikutnyaAktif = null;

    protected ?Collection $cacheKendalaAktif = null;

    protected ?MasterIku $cacheIkuTerpilih = null;

    protected bool $cacheIkuTerpilihDihitung = false;

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
    protected ?Lock $requestLock = null;

    public function boot(): void
    {
        // TTL kunci (40 detik) sengaja lebih panjang dari batas tunggu block() (25 detik)
        // supaya kunci tidak pernah kedaluwarsa sendiri sementara masih benar-benar dipegang.
        $this->requestLock = Cache::lock('pengisian-kegiatan-lock:'.session()->getId(), 40);

        try {
            $this->requestLock->block(25);
        } catch (LockTimeoutException $e) {
            // Kunci basi tertinggal dari request sebelumnya yang mati mendadak (mis. timeout
            // DB/Google Drive) sehingga dehydrate() tidak sempat memanggil release(). Daripada
            // membiarkan exception ini menjadi halaman 500 kosong, lewati kunci untuk request
            // ini saja — risikonya cuma snapshot sedikit basi, jauh lebih baik daripada crash.
            Log::warning('Kunci pengisian-kegiatan basi, dilewati.', ['session' => session()->getId()]);
            $this->requestLock = null;
            $this->dispatch('notify', type: 'warning', message: 'Sistem sedang sibuk memproses aksi sebelumnya — jika perubahan tidak muncul, coba ulangi.');
        }
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
            $this->muatBagianKustomBlocks();
            $this->pilihPicOtomatis();
            $this->muatRtlBaruBlocks();
        }
    }

    protected function emptyBlock(): array
    {
        return [
            'id' => null,
            'status_dokumen' => null,
            'rtl_evaluasi_id' => null,
            'uraian_kegiatan' => '',
            'jenis' => '',
            'tahapan_survei' => '',
            'bukti' => [],
            'existing_bukti' => [],
            'catatan_bukti_dihapus' => null,
        ];
    }

    /**
     * Status kegiatan yang sudah masuk proses Tim SAKIP — blok dengan status ini
     * ditampilkan hanya-baca di form (lihat muatBlocksKegiatan()) supaya Ketua Tim
     * tidak menimpa data yang sedang/sudah diverifikasi. Hanya 'draft' & 'dikembalikan'
     * yang tetap bisa diedit/diajukan ulang lewat form ini.
     *
     * Juga dipakai untuk mengunci blok Bagian Kustom lewat statusCapaianSaatIni()
     * (lihat muatBagianKustomBlocks()/riwayatBagianKustom()) — makanya
     * Capaian::STATUS_SEDANG_DITANGANI ikut disertakan di sini walau bukan nilai
     * status_dokumen Kegiatan, supaya Bagian Kustom tetap terkunci selama Tim
     * SAKIP masih menangani (bukan cuma saat sudah 'diajukan').
     *
     * @var list<string>
     */
    protected const STATUS_KEGIATAN_TERKUNCI = [
        Kegiatan::STATUS_DIAJUKAN,
        Kegiatan::STATUS_DIVERIFIKASI,
        Kegiatan::STATUS_DISETUJUI,
        Capaian::STATUS_SEDANG_DITANGANI,
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
            'id' => null,
            'rtl_teks' => '',
        ];
    }

    protected function emptyBagianKustomBlock(): array
    {
        return [
            'id' => null,
            'teks' => '',
            'bukti' => [],
            'existing_bukti' => [],
            'catatan_bukti_dihapus' => null,
        ];
    }

    /**
     * Daftar bagian kustom aktif — dipakai untuk merender bagian tambahan di form
     * (mis. Manajemen Risiko) dan untuk validasi/penyimpanannya.
     *
     * @return Collection<int, BagianKustom>
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
        if ($this->formTerkunciDisetujui() || $this->formTerkunciSedangDitangani()) {
            return;
        }

        $this->blocks[] = $this->emptyBlock();
    }

    /**
     * Blok yang belum tersimpan (id === null) cukup dibuang dari array in-memory.
     * Blok yang SUDAH punya baris Kegiatan di DB hanya boleh dihapus PERMANEN
     * selagi statusnya masih 'draft' atau 'dikembalikan' (belum diajukan/dikunci
     * Tim SAKIP, lihat STATUS_KEGIATAN_TERKUNCI) — mis. Kepala mengembalikan isian
     * (NotulaService::kembalikanIsian()) karena satu kegiatan memang salah/tidak
     * relevan dan Ketua Tim perlu membuangnya sama sekali, bukan cuma menyunting
     * teksnya. Baris Kegiatan & seluruh berkas buktinya (file lokal + baris DB)
     * ikut dihapus di sini — TIDAK cukup dibuang dari $this->blocks saja, karena
     * muatBlocksKegiatan() akan memuat ulang SEMUA baris Kegiatan milik IKU+periode
     * ini dari DB tiap kali form dibuka, jadi kegiatan itu akan "muncul lagi" kalau
     * baris DB-nya sendiri tidak ikut dihapus.
     */
    public function removeBlock(int $index): void
    {
        $block = $this->blocks[$index] ?? null;

        if (! $block) {
            return;
        }

        if ($block['id'] === null) {
            unset($this->blocks[$index]);
            $this->blocks = array_values($this->blocks);

            return;
        }

        if ($this->formTerkunciDisetujuiFresh() || $this->formTerkunciSedangDitanganiFresh()) {
            return;
        }

        if (in_array($block['status_dokumen'], self::STATUS_KEGIATAN_TERKUNCI, true)) {
            return;
        }

        $kegiatan = Kegiatan::with('berkas')->find($block['id']);

        if (! $kegiatan) {
            return;
        }

        foreach ($kegiatan->berkas as $berkas) {
            if ($berkas->path) {
                Storage::disk('local')->delete($berkas->path);
            }
        }

        $kegiatan->berkas()->delete();
        $kegiatan->delete();

        unset($this->blocks[$index]);
        $this->blocks = array_values($this->blocks);

        if (empty($this->blocks)) {
            $this->blocks = [$this->emptyBlock()];
        }

        $this->lupakanCachePeriodeIku();
    }

    /**
     * Menautkan sebuah blok kegiatan ke poin RTL triwulan berjalan lewat dropdown
     * (bukan lagi lewat pencocokan teks otomatis) — begitu dipilih, uraian kegiatan
     * ikut terisi dari teks RTL-nya sebagai titik awal, tapi Ketua Tim tetap bebas
     * menyunting teksnya sesudahnya; rtl_evaluasi_id TETAP tertaut walau teksnya
     * berubah (baru lepas lagi kalau dropdown dikembalikan ke "Ketik bebas").
     * RTL yang sudah dipilih di blok lain otomatis hilang dari opsi dropdown blok
     * ini (lihat rtlIdTerpilihDiBlocks()), jadi satu poin RTL tidak bisa dobel dipakai.
     */
    public function pilihRtlUntukBlock(int $index, $rtlId): void
    {
        if (! isset($this->blocks[$index])) {
            return;
        }

        $rtlId = $rtlId !== '' && $rtlId !== null ? (int) $rtlId : null;

        if ($rtlId === null) {
            $this->blocks[$index]['rtl_evaluasi_id'] = null;

            return;
        }

        $poin = $this->rtlTriwulanBerjalan()->firstWhere('id', $rtlId);

        if (! $poin) {
            return;
        }

        $this->blocks[$index]['rtl_evaluasi_id'] = $poin->id;
        $this->blocks[$index]['uraian_kegiatan'] = $poin->rtl_teks;
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
     * yang lama). Berkas yang sudah "terverifikasi" (Sesuai) TIDAK boleh dihapus/diedit
     * dari sini — sekali Tim SAKIP menandainya Sesuai, berkas itu terkunci permanen
     * bagi Ketua Tim, walau kegiatan induknya sendiri masih terbuka untuk berkas lain
     * yang ditolak (lihat "Rincian N" — status per-berkas, bukan per-kegiatan). Dicek
     * di sini, bukan cuma disembunyikan tombolnya di blade — defense in depth, pola
     * yang sama dipakai VerifikasiCapaian::tandaiTolak() dkk.
     * File fisik lokal ikut dihapus; salinan di Google Drive (bila sempat tersalin)
     * TIDAK ikut dihapus (belum ada mekanisme hapus-dari-Drive di GoogleDriveService).
     */
    public function hapusBuktiLama(int $blockIndex, int $berkasId): void
    {
        $block = $this->blocks[$blockIndex] ?? null;

        if (! $block || ! $block['id']) {
            return;
        }

        if (in_array($block['status_dokumen'], self::STATUS_KEGIATAN_TERKUNCI, true)) {
            return;
        }

        $berkas = Berkas::where('id', $berkasId)
            ->where('ref_type', Kegiatan::class)
            ->where('ref_id', $block['id'])
            ->first();

        if (! $berkas || $berkas->status_verifikasi === 'terverifikasi') {
            return;
        }

        // Catatan penolakan disalin ke Kegiatan-nya SENDIRI sebelum berkasnya benar-benar
        // hilang — begitu berkas ini dihapus, baris & catatannya lenyap dari tabel
        // `berkas`, jadi Tim SAKIP tidak akan tahu lagi APA yang tadinya salah saat
        // memeriksa bukti pengganti. Pengingat ini dikosongkan lagi begitu Kegiatan
        // ditandai "diverifikasi" (lihat Kegiatan::verifikasi()).
        if ($berkas->catatan) {
            Kegiatan::whereKey($block['id'])->update(['catatan_bukti_dihapus' => $berkas->catatan]);
            $this->blocks[$blockIndex]['catatan_bukti_dihapus'] = $berkas->catatan;
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

    /**
     * Hapus SATU berkas bukti dukung bagian kustom (mis. Manajemen Risiko) yang SUDAH
     * TERSIMPAN dan ditandai "Tidak Sesuai" oleh Tim SAKIP — pasangan dari
     * hapusBuktiLama() di atas, tapi untuk BagianKustomPoin. Poin dicocokkan ke
     * iku_id+periode_id form ini supaya Ketua Tim tidak bisa menghapus bukti milik
     * IKU/periode lain lewat manipulasi parameter. File fisik lokal ikut dihapus;
     * salinan di Google Drive TIDAK ikut dihapus (sama seperti hapusBuktiLama()).
     */
    public function hapusBuktiLamaBagianKustom(int $poinId, int $berkasId): void
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        if (! $periode || in_array($this->statusCapaianSaatIni(), self::STATUS_KEGIATAN_TERKUNCI, true)) {
            return;
        }

        $poin = BagianKustomPoin::where('id', $poinId)
            ->where('iku_id', $this->iku_id)
            ->where('periode_id', $periode->id)
            ->first();

        if (! $poin) {
            return;
        }

        $berkas = Berkas::where('id', $berkasId)
            ->where('ref_type', BagianKustomPoin::class)
            ->where('ref_id', $poin->id)
            ->first();

        if (! $berkas || $berkas->status_verifikasi === 'terverifikasi') {
            return;
        }

        // Sama seperti hapusBuktiLama() untuk Kegiatan — salin catatan penolakan ke
        // poin ini sendiri sebelum berkasnya hilang. Dikosongkan lagi begitu poin
        // ditandai "Sesuai" (lihat VerifikasiCapaian::tandaiBagianKustomSesuai()).
        if ($berkas->catatan) {
            $poin->update(['catatan_bukti_dihapus' => $berkas->catatan]);
        }

        if ($berkas->path) {
            Storage::disk('local')->delete($berkas->path);
        }

        $berkas->delete();

        Cache::forget($this->cacheKeyPeriodeIku("riwayat-bagian-{$poin->bagian_kustom_id}"));

        // Cerminkan penghapusan ke state in-memory (dimuat lewat muatBagianKustomBlocks())
        // supaya blade langsung mencerminkannya tanpa reload — pola sama seperti
        // hapusBuktiLama() di atas untuk existing_bukti milik Kegiatan.
        foreach ($this->bagianKustomBlocks[$poin->bagian_kustom_id] ?? [] as $i => $blok) {
            if (($blok['id'] ?? null) !== $poin->id) {
                continue;
            }

            $this->bagianKustomBlocks[$poin->bagian_kustom_id][$i]['existing_bukti'] = collect($blok['existing_bukti'])
                ->reject(fn ($file) => $file['id'] === $berkasId)
                ->values()
                ->all();

            if ($berkas->catatan) {
                $this->bagianKustomBlocks[$poin->bagian_kustom_id][$i]['catatan_bukti_dihapus'] = $berkas->catatan;
            }
        }
    }

    /**
     * Hapus SATU berkas bukti realisasi RTL triwulan berjalan yang SUDAH TERSIMPAN dan
     * ditandai "Tidak Sesuai" oleh Tim SAKIP — pasangan dari hapusBuktiLama() di atas,
     * tapi untuk RtlEvaluasi. $rtlId dicocokkan ke rtlTriwulanBerjalan() (sudah di-scope
     * ke iku_id+tahun+triwulan form ini) supaya tidak bisa menghapus bukti milik
     * IKU/periode lain. Cache in-memory & lintas-request 'rtl-berjalan' ikut dibuang
     * supaya $poin->berkas di blade langsung mencerminkan penghapusan tanpa reload.
     */
    public function hapusBuktiLamaEvaluasi(int $rtlId, int $berkasId): void
    {
        if (in_array($this->statusCapaianSaatIni(), self::STATUS_KEGIATAN_TERKUNCI, true)) {
            return;
        }

        $poin = $this->rtlTriwulanBerjalan()->firstWhere('id', $rtlId);

        if (! $poin) {
            return;
        }

        $berkas = Berkas::where('id', $berkasId)
            ->where('ref_type', RtlEvaluasiModel::class)
            ->where('ref_id', $poin->id)
            ->first();

        if (! $berkas || $berkas->status_verifikasi === 'terverifikasi') {
            return;
        }

        // Sama seperti hapusBuktiLama() untuk Kegiatan — salin catatan penolakan ke
        // poin RTL ini sendiri sebelum berkasnya hilang. Dikosongkan lagi begitu poin
        // ditandai "Sesuai" (lihat VerifikasiCapaian::tandaiRtlSesuai()).
        if ($berkas->catatan) {
            $poin->update(['catatan_bukti_dihapus' => $berkas->catatan]);
        }

        if ($berkas->path) {
            Storage::disk('local')->delete($berkas->path);
        }

        $berkas->delete();

        $this->cacheRtlBerjalan = null;
        Cache::forget($this->cacheKeyPeriodeIku('rtl-berjalan'));
    }

    public function addKendalaBlock(): void
    {
        if ($this->formTerkunciSedangDitangani()) {
            return;
        }

        $this->kendalaBlocks[] = $this->emptyKendalaBlock();
    }

    /**
     * Hanya blok yang belum tersimpan (id === null) yang boleh dihapus dari sini —
     * sama seperti removeBlock()/removeRtlBlock(): pasangan yang sudah punya baris
     * KendalaSolusi di DB (draft sendiri ATAU ditolak yang sedang diperbaiki) tidak
     * bisa dihapus lewat tombol ini, supaya tidak diam-diam tertinggal yatim di DB
     * (tidak ter-update maupun terhapus saat form disimpan, lihat
     * simpanBagianIsian()) — cukup dikosongkan teksnya bila memang ingin dibatalkan.
     */
    public function removeKendalaBlock(int $index): void
    {
        if (($this->kendalaBlocks[$index]['id'] ?? null) !== null) {
            return;
        }

        unset($this->kendalaBlocks[$index]);
        $this->kendalaBlocks = array_values($this->kendalaBlocks);
    }

    public function addBagianKustomBlock(int $bagianId): void
    {
        if ($this->formTerkunciSedangDitangani()) {
            return;
        }

        $this->bagianKustomBlocks[$bagianId][] = $this->emptyBagianKustomBlock();
    }

    /**
     * Hanya poin yang belum tersimpan (id === null) yang boleh dihapus dari sini —
     * sama seperti removeBlock() untuk Kegiatan: poin yang sudah tersimpan di DB
     * hanya boleh diedit teksnya, tidak dihapus (lihat muatBagianKustomBlocks()).
     */
    public function removeBagianKustomBlock(int $bagianId, int $index): void
    {
        if (($this->bagianKustomBlocks[$bagianId][$index]['id'] ?? null) !== null) {
            return;
        }

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
        if ($this->formTerkunciSedangDitangani()) {
            return;
        }

        $this->rtlBaru[] = $this->emptyRtlBlock();
    }

    public function removeRtlBlock(int $index): void
    {
        if (($this->rtlBaru[$index]['id'] ?? null) !== null) {
            return;
        }

        unset($this->rtlBaru[$index]);
        $this->rtlBaru = array_values($this->rtlBaru);
    }

    public function updatedIkuId(): void
    {
        $this->muatFormEvaluasi();
        $this->muatBlocksKegiatan();
        $this->muatKendalaBlocks();
        $this->muatBagianKustomBlocks();
        $this->pilihPicOtomatis();
        $this->muatRtlBaruBlocks();
    }

    public function updatedBulan(): void
    {
        $this->muatFormEvaluasi();
        $this->muatBlocksKegiatan();
        $this->muatKendalaBlocks();
        $this->muatBagianKustomBlocks();
        $this->muatRtlBaruBlocks();
    }

    public function updatedTahun(): void
    {
        $this->muatFormEvaluasi();
        $this->muatBlocksKegiatan();
        $this->muatKendalaBlocks();
        $this->muatBagianKustomBlocks();
        $this->muatRtlBaruBlocks();
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
        foreach (['riwayat', 'kendala-aktif', 'rtl-berjalan', 'rtl-berikutnya-ada', 'rtl-berikutnya-aktif', 'rtl-berjalan-terpakai', 'capaian-status', 'catatan-penolakan', 'pengembalian-terakhir', 'periode'] as $bagian) {
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
     * @return Collection<int, Collection<int, KendalaSolusiModel>>
     */
    protected function riwayatKendalaSolusi()
    {
        if (! $this->iku_id) {
            return collect();
        }

        return Cache::remember($this->cacheKeyPeriodeIku('riwayat'), self::CACHE_TTL_DETIK, function () {
            $triwulanSekarang = $this->triwulanDari($this->bulan);
            $periodeSaatIni = $this->periodeSaatIni();

            // Pasangan periode INI SENDIRI yang sudah "terverifikasi" biasanya boleh ikut
            // tampil di sini (sudah pindah dari kendalaBlocks yang editable ke riwayat
            // hanya-baca). TAPI hanya bila verifikasiTerlihat() — kalau Tim SAKIP baru
            // menandainya di tengah pemeriksaan (Capaian masih "diajukan"/"sedang
            // ditangani"), pasangan itu TETAP dianggap belum final dan harus tetap
            // muncul di kendalaBlocks (lihat muatKendalaBlocks()), bukan di sini —
            // supaya tidak bocor sebagai "sudah diterima" sebelum benar-benar final.
            $verifikasiTerlihat = $this->verifikasiTerlihat();

            return KendalaSolusiModel::with('periode')
                ->where('iku_id', $this->iku_id)
                ->whereHas('periode', function ($q) use ($triwulanSekarang) {
                    $q->where('tahun', $this->tahun)->where('triwulan', '<=', $triwulanSekarang);
                })
                ->when($periodeSaatIni, function ($q) use ($periodeSaatIni, $verifikasiTerlihat) {
                    if ($verifikasiTerlihat) {
                        $q->where(fn ($q2) => $q2->where('periode_id', '!=', $periodeSaatIni->id)
                            ->orWhere('status_verifikasi', 'terverifikasi'));
                    } else {
                        $q->where('periode_id', '!=', $periodeSaatIni->id);
                    }
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
     * @return Collection<int, Collection<int, BagianKustomPoin>>
     */
    protected function riwayatBagianKustom(BagianKustom $bagian)
    {
        if (! $this->iku_id) {
            return collect();
        }

        $daftar = Cache::remember($this->cacheKeyPeriodeIku("riwayat-bagian-{$bagian->id}"), self::CACHE_TTL_DETIK, function () use ($bagian) {
            $triwulanSekarang = $this->triwulanDari($this->bulan);
            $periodeSaatIni = $this->periodeSaatIni();
            $terkunci = in_array($this->statusCapaianSaatIni(), self::STATUS_KEGIATAN_TERKUNCI, true);

            return BagianKustomPoin::with(['periode', 'berkas'])
                ->where('iku_id', $this->iku_id)
                ->where('bagian_kustom_id', $bagian->id)
                ->whereHas('periode', function ($q) use ($triwulanSekarang) {
                    $q->where('tahun', $this->tahun)->where('triwulan', '<=', $triwulanSekarang);
                })
                // Poin periode INI SENDIRI yang belum terkunci ditampilkan sebagai blok
                // EDITABLE lewat bagianKustomBlocks (lihat muatBagianKustomBlocks()),
                // bukan di sini — supaya tidak tampil dobel (sekali hanya-baca, sekali
                // bisa disunting). Pola sama seperti riwayatKendalaSolusi() di atas.
                ->when($periodeSaatIni && ! $terkunci, function ($q) use ($periodeSaatIni) {
                    $q->where('periode_id', '!=', $periodeSaatIni->id);
                })
                ->get()
                ->sortBy(fn ($item) => $item->periode->triwulan)
                ->groupBy(fn ($item) => $item->periode->triwulan);
        });

        // Kasus di atas ($terkunci tapi status masih "diajukan"/"sedang_ditangani",
        // BELUM verifikasiTerlihat()) tetap menyertakan poin periode ini di riwayat —
        // masking di sini (SETELAH Cache::remember, bukan di dalamnya, supaya nilai
        // asli tetap yang disimpan ke cache) mencegah status_verifikasi/catatan
        // Tim SAKIP yang masih berjalan ikut bocor lewat jalur riwayat ini.
        if (! $this->verifikasiTerlihat() && ($periodeSaatIni = $this->periodeSaatIni())) {
            foreach ($daftar->flatten() as $poin) {
                if ($poin->periode_id !== $periodeSaatIni->id) {
                    continue;
                }

                foreach ($poin->berkas as $berkas) {
                    $berkas->status_verifikasi = 'menunggu';
                    $berkas->catatan = null;
                }
            }
        }

        return $daftar;
    }

    /**
     * Catatan penolakan Tim SAKIP untuk IKU+periode terpilih, dikumpulkan lintas
     * SEMUA kategori berkas (bukti kegiatan, bukti solusi, bukti evaluasi RTL, bukti
     * bagian kustom) — dipakai untuk banner ringkasan di puncak form saat isian
     * periode ini berstatus dikembalikan. Pola pengumpulan mirip
     * VerifikasiCapaian::berkasList(), tapi di-scope ke iku_id+periode (bukan
     * capaian_id) karena komponen ini belum tentu sedang menampilkan satu Capaian.
     *
     * @return Collection<int, string>
     */
    protected function catatanPenolakan()
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        // Banner ini merangkum ALASAN penolakan Tim SAKIP — baru boleh tampil setelah
        // siklus pemeriksaannya benar-benar final (lihat verifikasiTerlihat()), supaya
        // tidak menampilkan alasan yang masih bisa berubah selagi Tim SAKIP masih
        // memeriksa (belum tentu jadi "Kembalikan ke Ketua Tim" beneran).
        if (! $periode || ! $this->verifikasiTerlihat()) {
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
     * Baris riwayat status "dikembalikan" TERAKHIR milik Capaian (IKU+periode) ini —
     * dipakai banner di puncak form untuk memberitahu Ketua Tim SIAPA yang
     * mengembalikan (Kepala lewat NotulaService::kembalikanIsian(), ATAU Tim SAKIP
     * lewat VerifikasiCapaian::kembalikanKeKetuaTim()/bukaKembali()) beserta
     * catatannya — sebelumnya banner ini SELALU berbunyi "oleh Tim SAKIP" tanpa
     * pernah mengecek siapa penggunanya, jadi salah atribusi setiap kali Kepala yang
     * mengembalikan langsung dari halaman Persetujuan Notula (RF-44), dan catatan
     * Kepala (kolom `catatan` pada riwayat_status_capaian) tidak pernah ditampilkan
     * sama sekali karena catatanPenolakan() di atas hanya mengumpulkan catatan
     * per-berkas/kendala, bukan catatan level-Capaian ini.
     *
     * Sama seperti catatanPenolakan(), baru boleh terlihat setelah verifikasiTerlihat()
     * supaya tidak membocorkan hasil pemeriksaan yang masih berjalan.
     */
    protected function pengembalianTerakhir(): ?RiwayatStatusCapaian
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        if (! $periode || ! $this->verifikasiTerlihat()) {
            return null;
        }

        return Cache::remember($this->cacheKeyPeriodeIku('pengembalian-terakhir'), self::CACHE_TTL_DETIK, function () use ($periode) {
            $capaianId = Capaian::where('iku_id', $this->iku_id)->where('periode_id', $periode->id)->value('id');

            if (! $capaianId) {
                return null;
            }

            return RiwayatStatusCapaian::where('capaian_id', $capaianId)
                ->where('status', Capaian::STATUS_DIKEMBALIKAN)
                ->with('user')
                ->latest('id')
                ->first();
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
     * Tim SAKIP menandai berkas/kendala Sesuai/Tidak Sesuai satu per satu SELAGI
     * masih memeriksa (lihat App\Livewire\VerifikasiCapaian::tandaiSesuai() dkk.) —
     * itu langsung mengubah status_verifikasi & catatan di DB pada saat itu juga,
     * SEBELUM Tim SAKIP menekan "Verifikasi Selesai"/"Kembalikan ke Ketua Tim".
     * Tanpa gerbang ini, Ketua Tim yang membuka halaman ini di tengah proses
     * pemeriksaan akan melihat tanda "Tidak Sesuai"+catatan yang masih berubah-ubah
     * (bisa saja belum final, Tim SAKIP masih bisa menandainya ulang) — membingungkan
     * karena badge status besar Capaian sendiri masih "Diajukan"/belum dikembalikan.
     *
     * Hasil pemeriksaan Tim SAKIP baru boleh terlihat Ketua Tim setelah SATU siklus
     * pemeriksaan benar-benar selesai (allow-list eksplisit, bukan daftar
     * kecuali — status baru di masa depan default TERSEMBUNYI sampai ditambahkan
     * sengaja ke sini): "dikembalikan" (perlu diperbaiki), "diverifikasi"/"disetujui"
     * (diterima). Dipakai bersama maskBerkas() di seluruh method muat*()/riwayat*()
     * di bawah supaya satu aturan ini konsisten di semua tempat status_verifikasi/
     * catatan ditampilkan ke Ketua Tim.
     */
    protected function verifikasiTerlihat(): bool
    {
        return in_array($this->statusCapaianSaatIni(), [
            Capaian::STATUS_DIKEMBALIKAN,
            Capaian::STATUS_DIVERIFIKASI,
            Capaian::STATUS_DISETUJUI,
        ], true);
    }

    /**
     * Sembunyikan status_verifikasi & catatan satu berkas (array bentuk existing_bukti,
     * lihat muatBlocksKegiatan()/muatBagianKustomBlocks()) selagi verifikasiTerlihat()
     * masih false — dipakai supaya seluruh tempat yang menyusun existing_bukti tidak
     * perlu mengulang pengecekan yang sama.
     *
     * @param  array{id: int, nama_file: string, status_verifikasi: string, catatan: ?string}  $berkas
     * @return array{id: int, nama_file: string, status_verifikasi: string, catatan: ?string}
     */
    protected function maskBerkas(array $berkas): array
    {
        if ($this->verifikasiTerlihat()) {
            return $berkas;
        }

        $berkas['status_verifikasi'] = 'menunggu';
        $berkas['catatan'] = null;

        return $berkas;
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
     * Selagi Capaian berstatus "sedang ditangani" (Tim SAKIP sudah mulai menandai
     * sebagian bukti/kendala-solusi & "Simpan Sementara", lihat
     * Capaian::STATUS_SEDANG_DITANGANI), isian ini dikunci hanya-baca TOTAL bagi
     * Ketua Tim — tidak bisa menambah kegiatan/kendala/RTL/Bagian Kustom baru maupun
     * menyimpan draf/mengajukan — supaya data yang sedang dipegang & di-cache Tim
     * SAKIP (lihat App\Livewire\VerifikasiCapaian::kegiatanList() dkk., di-cache per
     * request) tidak diam-diam berubah di bawahnya selagi diperiksa. BEDA dari
     * formTerkunciDisetujui(): begitu Tim SAKIP selesai (Verifikasi Selesai/
     * Kembalikan ke Ketua Tim), status berpindah ke diverifikasi/dikembalikan dan
     * form ini otomatis terbuka lagi (dikembalikan) atau tetap terkunci lewat
     * STATUS_KEGIATAN_TERKUNCI per-blok seperti biasa (diverifikasi).
     *
     * Dipakai untuk UI (sembunyikan/nonaktifkan tombol) — SELALU dicek ULANG tanpa
     * cache di simpanDraft()/ajukanIsian() sebelum benar-benar menyimpan apa pun,
     * sama seperti formTerkunciDisetujui() di atas (defense in depth).
     */
    public function formTerkunciSedangDitangani(): bool
    {
        return $this->statusCapaianSaatIni() === Capaian::STATUS_SEDANG_DITANGANI;
    }

    /**
     * Versi TANPA cache dari formTerkunciSedangDitangani() — satu-satunya yang boleh
     * dipakai sebagai gerbang sebelum benar-benar menyimpan (simpanDraft()/ajukanIsian()).
     */
    protected function formTerkunciSedangDitanganiFresh(): bool
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        if (! $periode) {
            return false;
        }

        $status = Capaian::where('iku_id', $this->iku_id)->where('periode_id', $periode->id)->value('status');

        return $status === Capaian::STATUS_SEDANG_DITANGANI;
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
            'rtl_evaluasi_id' => $kegiatan->rtl_evaluasi_id,
            'uraian_kegiatan' => $kegiatan->uraian_kegiatan,
            'jenis' => $kegiatan->jenis,
            'tahapan_survei' => $kegiatan->tahapan_survei ?? '',
            'bukti' => [],
            'existing_bukti' => $kegiatan->berkas->map(fn (Berkas $b) => $this->maskBerkas([
                'id' => $b->id,
                'nama_file' => $b->nama_file,
                'status_verifikasi' => $b->status_verifikasi,
                'catatan' => $b->catatan,
            ]))->all(),
            'catatan_bukti_dihapus' => $kegiatan->catatan_bukti_dihapus,
        ])->values()->all();
    }

    /**
     * Pasangan kendala &amp; solusi milik periode ini SENDIRI yang BOLEH DIEDIT
     * Ketua Tim — draft yang belum pernah diajukan (status_dokumen=draft), atau
     * yang DITOLAK Tim SAKIP dan perlu diperbaiki. Pasangan yang SUDAH diajukan
     * (status_dokumen=diajukan) dan belum ditolak final TIDAK ikut dimuat ke sini
     * — begitu diajukan, teksnya terkunci hanya-baca sampai Tim SAKIP memutuskan
     * (lihat kendalaAktif(), sama persis pola RTL berikutnya di
     * rtlBerikutnyaAktif()/muatRtlBaruBlocks()), supaya Ketua Tim tidak diam-diam
     * menyunting pasangan yang sedang/sudah diperiksa. Dipanggil sejajar dengan
     * muatBlocksKegiatan() supaya isian lama (draft/ditolak) tetap ada saat form
     * dibuka ulang, bukan selalu kosong.
     *
     * Pasangan yang sudah "terverifikasi" (diterima) TIDAK ikut dimuat ke sini
     * sama sekali (beda dari Kegiatan), cukup tampil di riwayatKendalaSolusi()
     * sebagai riwayat hanya-baca — KECUALI selagi verifikasiTerlihat() masih false
     * (Tim SAKIP belum menyelesaikan SATU siklus pemeriksaan penuh), pasangan yang
     * SUDAH ditandai "terverifikasi" di tengah jalan tetap dianggap "aktif"
     * (terkunci lewat kendalaAktif(), BUKAN dimuat sebagai blok editable di sini)
     * supaya tidak ada pasangan yang tiba-tiba "menghilang" (lalu muncul di
     * riwayatKendalaSolusi() sebagai sudah diterima) sebelum pemeriksaannya
     * benar-benar final.
     */
    protected function muatKendalaBlocks(): void
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        if (! $periode) {
            $this->kendalaBlocks = [$this->emptyKendalaBlock()];

            return;
        }

        $query = KendalaSolusiModel::where('iku_id', $this->iku_id)->where('periode_id', $periode->id);

        if ($this->verifikasiTerlihat()) {
            $query->where('status_verifikasi', '!=', 'terverifikasi');
        }

        $daftar = $query->orderBy('id')->get();

        // Pasangan yang sudah diajukan (status_dokumen=diajukan) dan belum final
        // ditolak Tim SAKIP dikeluarkan dari sini — tampil terkunci lewat
        // kendalaAktif() di blade, bukan sebagai blok editable.
        $editable = $daftar->reject(fn (KendalaSolusiModel $ks) => $ks->status_dokumen === KendalaSolusiModel::STATUS_DIAJUKAN && ! $this->verifikasiTerlihat());

        if ($editable->isEmpty()) {
            // Satu blok kosong bawaan HANYA untuk pengisian pertama kali ($daftar
            // benar-benar kosong) — begitu ada pasangan lain yang sudah diajukan
            // (tampil terkunci lewat kendalaAktif()), biarkan $kendalaBlocks kosong
            // supaya blade cukup menampilkan tombol "+ Tambah Pasangan" saja, tanpa
            // kotak input kosong yang nongol begitu saja di bawahnya.
            $this->kendalaBlocks = $daftar->isEmpty() ? [$this->emptyKendalaBlock()] : [];

            return;
        }

        $this->kendalaBlocks = $editable->map(fn (KendalaSolusiModel $ks) => [
            'id' => $ks->id,
            'kendala' => $ks->kendala,
            'solusi' => $ks->solusi ?? '',
            'status_verifikasi' => $this->verifikasiTerlihat() ? $ks->status_verifikasi : 'menunggu',
            'catatan' => $this->verifikasiTerlihat() ? $ks->catatan : null,
        ])->values()->all();
    }

    /**
     * Pasangan kendala &amp; solusi periode ini yang SUDAH diajukan ke Tim SAKIP dan
     * belum diputuskan final — ditampilkan terkunci hanya-baca di blade (badge
     * "Menunggu Verifikasi Tim SAKIP"/"Terverifikasi Tim SAKIP" sesuai status_
     * verifikasi apa adanya), pola identik dengan RtlEvaluasi berikutnya di
     * rtlBerikutnyaAktif(). Selalu kosong begitu verifikasiTerlihat() true — pada
     * titik itu tiap pasangan sudah final: pindah ke riwayatKendalaSolusi()
     * (terverifikasi) atau kembali jadi blok editable di muatKendalaBlocks()
     * (ditolak, perlu diperbaiki) — jadi tidak ada lagi yang perlu tampil "aktif"
     * di sini.
     *
     * @return Collection<int, KendalaSolusiModel>
     */
    protected function kendalaAktif()
    {
        if ($this->cacheKendalaAktif !== null) {
            return $this->cacheKendalaAktif;
        }

        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        if (! $periode || $this->verifikasiTerlihat()) {
            return $this->cacheKendalaAktif = collect();
        }

        return $this->cacheKendalaAktif = Cache::remember(
            $this->cacheKeyPeriodeIku('kendala-aktif'),
            self::CACHE_TTL_DETIK,
            fn () => KendalaSolusiModel::where('iku_id', $this->iku_id)
                ->where('periode_id', $periode->id)
                ->where('status_dokumen', KendalaSolusiModel::STATUS_DIAJUKAN)
                ->orderBy('id')
                ->get()
        );
    }

    /**
     * Muat poin bagian kustom (mis. Manajemen Risiko) yang sudah tersimpan untuk
     * IKU+periode terpilih ke $this->bagianKustomBlocks supaya isian yang pernah
     * dibuat (termasuk yang dikembalikan Tim SAKIP) bisa DIEDIT lagi saat form dibuka
     * ulang — sebelumnya poin lama sama sekali tidak bisa disunting, hanya tampil
     * hanya-baca di riwayatBagianKustom() tanpa jalan untuk memperbaikinya.
     *
     * BagianKustomPoin tidak punya status_verifikasi sendiri (beda dari Kegiatan/
     * KendalaSolusi) — jadi "boleh diedit?" ditentukan dari status Capaian keseluruhan
     * periode ini: selama belum diajukan/diverifikasi/disetujui (STATUS_KEGIATAN_TERKUNCI),
     * poin periode ini dimuat ke sini sebagai editable. Begitu terkunci, poin TIDAK
     * dimuat ke sini (kembali ke satu blok kosong) dan tetap tampil hanya-baca lewat
     * riwayatBagianKustom() — lihat pengecualian yang sejalan di sana supaya tidak
     * dobel tampil.
     */
    protected function muatBagianKustomBlocks(): void
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;
        $terkunci = in_array($this->statusCapaianSaatIni(), self::STATUS_KEGIATAN_TERKUNCI, true);

        foreach ($this->bagianKustomAktif() as $bagian) {
            if (! $periode || $terkunci) {
                $this->bagianKustomBlocks[$bagian->id] = [$this->emptyBagianKustomBlock()];

                continue;
            }

            $daftar = BagianKustomPoin::with('berkas')
                ->where('iku_id', $this->iku_id)
                ->where('bagian_kustom_id', $bagian->id)
                ->where('periode_id', $periode->id)
                ->orderBy('id')
                ->get();

            if ($daftar->isEmpty()) {
                $this->bagianKustomBlocks[$bagian->id] = [$this->emptyBagianKustomBlock()];

                continue;
            }

            $this->bagianKustomBlocks[$bagian->id] = $daftar->map(fn (BagianKustomPoin $poin) => [
                'id' => $poin->id,
                'teks' => $poin->teks,
                'bukti' => [],
                'existing_bukti' => $poin->berkas->map(fn (Berkas $b) => $this->maskBerkas([
                    'id' => $b->id,
                    'nama_file' => $b->nama_file,
                    'status_verifikasi' => $b->status_verifikasi,
                    'catatan' => $b->catatan,
                ]))->all(),
                'catatan_bukti_dihapus' => $poin->catatan_bukti_dihapus,
            ])->values()->all();
        }
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

        $rtlBerjalan = Cache::remember(
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

        // Bukti realisasi RTL diperiksa Tim SAKIP bersamaan dengan siklus verifikasi
        // IKU+periode berjalan (lihat VerifikasiCapaian::rtlEvaluasiSebelumnya()) —
        // masking di sini SETELAH Cache::remember (bukan di dalamnya) supaya nilai asli
        // tetap disimpan ke cache, sama seperti pola di riwayatBagianKustom() di atas.
        if (! $this->verifikasiTerlihat()) {
            foreach ($rtlBerjalan as $poin) {
                foreach ($poin->berkas as $berkas) {
                    $berkas->status_verifikasi = 'menunggu';
                    $berkas->catatan = null;
                }
            }
        }

        return $this->cacheRtlBerjalan = $rtlBerjalan;
    }

    /**
     * ID rtl_evaluasi (triwulan berjalan) yang SEDANG tertaut ke salah satu blok
     * kegiatan di form ini — baik kegiatan lama yang dimuat dari DB maupun pilihan
     * dropdown yang baru saja dibuat, belum tentu tersimpan (lihat pilihRtlUntukBlock()).
     * Dihitung langsung dari $this->blocks (bukan query DB) supaya SELALU sinkron
     * dengan apa yang sedang terlihat di form, termasuk perubahan yang belum disimpan.
     * Dipakai bersama oleh rtlBerjalanOptions() dan poinRtlBerjalanBelumTerlaksana().
     */
    protected function rtlIdTerpilihDiBlocks(): Collection
    {
        return collect($this->blocks)
            ->pluck('rtl_evaluasi_id')
            ->filter()
            ->unique()
            ->values();
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
     * status "sudah dipilih di salah satu blok kegiatan?" — dipakai sebagai opsi dropdown
     * pemilihan RTL di Bagian 2 (lewat pilihRtlUntukBlock()) dan badge di Bagian 4.
     *
     * @return Collection<int, array{poin: RtlEvaluasiModel, terpakai: bool}>
     */
    protected function rtlBerjalanOptions()
    {
        $rtlBerjalan = $this->rtlTriwulanBerjalan();

        if ($rtlBerjalan->isEmpty()) {
            return collect();
        }

        $terpilihIds = $this->rtlIdTerpilihDiBlocks();

        return $rtlBerjalan->map(fn ($poin) => [
            'poin' => $poin,
            'terpakai' => $terpilihIds->contains($poin->id),
        ]);
    }

    /**
     * Poin RTL triwulan berjalan yang BELUM dipilih lewat dropdown RTL di blok kegiatan
     * mana pun — murni informatif (badge "Belum terlaksana sbg kegiatan" di Bagian 4);
     * SEJAK aturan jumlah minimal diterapkan (lihat buatValidator()), poin di sini boleh
     * tetap tidak dipilih dan pengajuan tetap bisa jalan selama syarat jumlah & bukti
     * evaluasi terpenuhi — kegiatan tidak wajib memakai kata-kata RTL persis lagi.
     *
     * @return Collection<int, RtlEvaluasiModel>
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

        $terpilihIds = $this->rtlIdTerpilihDiBlocks();

        return $rtlBerjalan->reject(fn ($poin) => $terpilihIds->contains($poin->id))->values();
    }

    /**
     * Cari poin RTL triwulan berjalan yang teksnya cocok persis (case-insensitive) dengan
     * uraian kegiatan — jaring pengaman untuk kegiatan lama yang uraiannya diketik ulang
     * sama persis dengan RTL tanpa lewat dropdown (lihat rtlEvaluasiIdUntukBlock()).
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
     * rtl_evaluasi_id final untuk sebuah blok kegiatan saat disimpan — utamakan pilihan
     * eksplisit dari dropdown RTL (blocks.*.rtl_evaluasi_id, diisi lewat pilihRtlUntukBlock()
     * dan TETAP tertaut walau uraian kegiatan-nya lalu disunting), baru jatuh ke pencocokan
     * teks persis sebagai jaring pengaman bila field itu kosong. ID yang tersimpan divalidasi
     * ulang terhadap rtlTriwulanBerjalan() supaya tidak ada ID basi/di luar triwulan berjalan
     * yang lolos tersimpan.
     */
    protected function rtlEvaluasiIdUntukBlock(array $block): ?int
    {
        $idTerpilih = $block['rtl_evaluasi_id'] ?? null;

        if ($idTerpilih && $this->rtlTriwulanBerjalan()->contains('id', $idTerpilih)) {
            return (int) $idTerpilih;
        }

        return $this->cariRtlBerjalanCocok($block['uraian_kegiatan']);
    }

    /**
     * Bawaan PIC Tindak Lanjut — SELURUH tim penanggung jawab IKU terpilih, karena
     * satu IKU boleh ditugaskan ke lebih dari satu tim (lihat
     * App\Models\MasterIku::namaTimList()). Tetap boleh diubah bebas oleh Ketua Tim
     * lewat chip (lihat tambahRtlBaruPic()/hapusRtlBaruPic()).
     */
    protected function pilihPicOtomatis(): void
    {
        $this->rtlBaruPicTerpilih = $this->ikuTerpilih()?->namaTimList() ?? [];
    }

    /**
     * Tambah satu tim ke rtlBaruPicTerpilih — nilainya diambil dari rtlBaruPicBaru
     * (wire:model), boleh dari saran daftarTimPic() atau nama tim baru yang diketik
     * bebas. Sama pola UX-nya dengan App\Livewire\AkunAktif::tambahTim().
     */
    public function tambahRtlBaruPic(): void
    {
        $tim = trim($this->rtlBaruPicBaru);

        if ($tim === '' || in_array($tim, $this->rtlBaruPicTerpilih, true)) {
            $this->rtlBaruPicBaru = '';

            return;
        }

        $this->rtlBaruPicTerpilih[] = $tim;
        $this->rtlBaruPicBaru = '';
    }

    public function hapusRtlBaruPic(string $tim): void
    {
        $this->rtlBaruPicTerpilih = array_values(array_diff($this->rtlBaruPicTerpilih, [$tim]));
    }

    /**
     * Saran PIC Tindak Lanjut — lihat MasterIku::daftarTimGabungan().
     *
     * @return list<string>
     */
    protected function daftarTimPic(): array
    {
        return MasterIku::daftarTimGabungan();
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

    /**
     * "Sudah ada" berarti ada poin RTL berikutnya yang masih AKTIF (menunggu atau
     * sudah terverifikasi Tim SAKIP) — poin yang DITOLAK (lihat rtlBerikutnyaDitolak())
     * SENGAJA tidak dihitung di sini, supaya Bagian 5 otomatis terbuka lagi untuk
     * diperbaiki begitu Tim SAKIP menandai "Tidak Sesuai" (lihat
     * VerifikasiCapaian::tandaiRtlBerikutnyaTolak()), bukan terkunci selamanya. Sama
     * seperti catatanPenolakan(), penolakan baru dianggap FINAL (Bagian 5 terbuka)
     * setelah verifikasiTerlihat() — selagi Tim SAKIP masih memeriksa (belum tentu
     * jadi "Kembalikan ke Ketua Tim" beneran), tanda "Tidak Sesuai" sementara tidak
     * langsung membuka form ini.
     */
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

                // status_dokumen != draft SENGAJA disertakan — RTL Baru yang baru
                // tersimpan lewat "Simpan Draft" (belum diajukan ke Tim SAKIP sama
                // sekali) TIDAK dianggap "sudah ditetapkan" di sini, supaya Bagian 5
                // tetap terbuka & bisa diedit selama isian keseluruhan masih draft —
                // sama seperti Kegiatan/Kendala & Solusi yang tetap bisa diedit selagi
                // berstatus draft. Baru dianggap "sudah ditetapkan" (hanya-baca) begitu
                // benar-benar diajukan (lihat simpanBagianIsian()).
                $query = RtlEvaluasiModel::where('iku_id', $this->iku_id)
                    ->where('status_dokumen', RtlEvaluasiModel::STATUS_DIAJUKAN)
                    ->whereHas('periode', fn ($q) => $q->where('tahun', $target['tahun'])->where('triwulan', $target['triwulan']));

                if ($this->verifikasiTerlihat()) {
                    $query->where('status_verifikasi', '!=', 'ditolak');
                }

                return $query->exists();
            }
        );
    }

    /**
     * Poin RTL berikutnya yang tersimpan sebagai DRAFT milik sendiri (belum pernah
     * diajukan ke Tim SAKIP sama sekali) — dipakai muatRtlBaruBlocks() supaya teks
     * yang sudah diketik & disimpan lewat "Simpan Draft" tetap tampil & bisa
     * dilanjutkan mengeditnya, bukan hilang jadi blok kosong lagi begitu form dibuka
     * ulang. Lihat catatan di rtlTriwulanBerikutnyaSudahAda() soal kenapa draft tidak
     * dianggap "sudah ditetapkan".
     *
     * @return Collection<int, RtlEvaluasiModel>
     */
    protected function rtlBerikutnyaDraftSendiri()
    {
        if (! $this->iku_id) {
            return collect();
        }

        $target = $this->targetTriwulanBerikutnya();

        return RtlEvaluasiModel::where('iku_id', $this->iku_id)
            ->where('status_dokumen', RtlEvaluasiModel::STATUS_DRAFT)
            ->whereHas('periode', fn ($q) => $q->where('tahun', $target['tahun'])->where('triwulan', $target['triwulan']))
            ->orderBy('id')
            ->get();
    }

    /**
     * Poin RTL berikutnya yang DITOLAK FINAL Tim SAKIP (lihat VerifikasiCapaian::
     * tandaiRtlBerikutnyaTolak(), digerbang verifikasiTerlihat() sama seperti
     * rtlTriwulanBerikutnyaSudahAda() di atas) — dipakai muatRtlBaruBlocks() untuk
     * membuka kembali Bagian 5 berisi poin-poin ini (siap diperbaiki & diajukan
     * ulang) dan blade untuk menampilkan alasan penolakannya.
     *
     * @return Collection<int, RtlEvaluasiModel>
     */
    protected function rtlBerikutnyaDitolak()
    {
        if ($this->cacheRtlBerikutnyaDitolak !== null) {
            return $this->cacheRtlBerikutnyaDitolak;
        }

        if (! $this->iku_id || ! $this->verifikasiTerlihat()) {
            return $this->cacheRtlBerikutnyaDitolak = collect();
        }

        $target = $this->targetTriwulanBerikutnya();

        return $this->cacheRtlBerikutnyaDitolak = RtlEvaluasiModel::where('iku_id', $this->iku_id)
            ->where('status_verifikasi', 'ditolak')
            ->whereHas('periode', fn ($q) => $q->where('tahun', $target['tahun'])->where('triwulan', $target['triwulan']))
            ->orderBy('id')
            ->get();
    }

    /**
     * Poin RTL berikutnya yang AKTIF (menunggu ATAU sudah terverifikasi Tim SAKIP —
     * kebalikan dari rtlBerikutnyaDitolak() di atas) — dipakai blade untuk MENAMPILKAN
     * isi rencana yang sudah ditetapkan begitu rtlTriwulanBerikutnyaSudahAda() true,
     * bukan cuma badge "Sudah ditetapkan" tanpa detail (beda dari bagian lain di form
     * ini yang semuanya tetap menampilkan isian sebelumnya).
     *
     * @return Collection<int, RtlEvaluasiModel>
     */
    protected function rtlBerikutnyaAktif()
    {
        if ($this->cacheRtlBerikutnyaAktif !== null) {
            return $this->cacheRtlBerikutnyaAktif;
        }

        if (! $this->iku_id) {
            return $this->cacheRtlBerikutnyaAktif = collect();
        }

        return $this->cacheRtlBerikutnyaAktif = Cache::remember(
            $this->cacheKeyPeriodeIku('rtl-berikutnya-aktif'),
            self::CACHE_TTL_DETIK,
            function () {
                $target = $this->targetTriwulanBerikutnya();

                return RtlEvaluasiModel::where('iku_id', $this->iku_id)
                    ->where('status_dokumen', RtlEvaluasiModel::STATUS_DIAJUKAN)
                    ->where('status_verifikasi', '!=', 'ditolak')
                    ->whereHas('periode', fn ($q) => $q->where('tahun', $target['tahun'])->where('triwulan', $target['triwulan']))
                    ->orderBy('id')
                    ->get();
            }
        );
    }

    /**
     * Muat ulang $rtlBaru — kosong (satu blok baru) secara bawaan, ATAU diisi dari
     * poin-poin yang ditolak Tim SAKIP, ATAU (bila tidak ada yang ditolak) dari draft
     * milik sendiri yang sudah tersimpan lewat "Simpan Draft" tapi belum diajukan
     * (lengkap dengan id-nya, supaya penyimpanan berikutnya meng-UPDATE baris lama,
     * bukan membuat duplikat baru — lihat langkah 4 di simpanBagianIsian()). Dipanggil
     * di titik yang sama dengan muatBagianKustomBlocks() (mount/updatedIkuId/
     * updatedBulan/updatedTahun).
     *
     * Satu blok kosong bawaan HANYA disiapkan otomatis untuk pengisian PERTAMA KALI
     * (belum ada batch RTL berikutnya yang diajukan sama sekali) — begitu batch sudah
     * ditetapkan (rtlTriwulanBerikutnyaSudahAda()), $rtlBaru dibiarkan KOSONG (blade
     * cukup menampilkan tombol "+ Tambah Poin RTL" saja, sama seperti Kendala & Solusi
     * di muatKendalaBlocks()) — supaya tidak ada kotak input kosong yang nongol
     * begitu saja di bawah daftar poin yang sudah terkunci, padahal belum tentu
     * Ketua Tim mau menambah poin baru saat itu juga.
     */
    protected function muatRtlBaruBlocks(): void
    {
        $this->rtlBaruBatasWaktu = $this->akhirTriwulanBerikutnya()->toDateString();

        $ditolak = $this->rtlBerikutnyaDitolak();

        if ($ditolak->isNotEmpty()) {
            $this->rtlBaru = $ditolak->map(fn ($poin) => ['id' => $poin->id, 'rtl_teks' => $poin->rtl_teks])->values()->all();
            $this->muatPicTersimpan($ditolak->first()->pic);

            return;
        }

        $draftSendiri = $this->rtlBerikutnyaDraftSendiri();

        if ($draftSendiri->isNotEmpty()) {
            $this->rtlBaru = $draftSendiri->map(fn ($poin) => ['id' => $poin->id, 'rtl_teks' => $poin->rtl_teks])->values()->all();
            $this->muatPicTersimpan($draftSendiri->first()->pic);

            return;
        }

        $this->rtlBaru = $this->rtlTriwulanBerikutnyaSudahAda() ? [] : [$this->emptyRtlBlock()];
    }

    /**
     * Pulihkan PIC Tindak Lanjut yang sudah pernah dipilih & disimpan (draft
     * sendiri/ditolak Tim SAKIP) — HARUS dipanggil SETELAH pilihPicOtomatis()
     * (lihat mount()/updatedIkuId()), supaya pilihan Ketua Tim sebelumnya tidak
     * hilang tertimpa bawaan nama tim IKU begitu form dibuka/dimuat ulang. Kalau
     * PIC tersimpan memang sengaja dikosongkan dulu, biarkan bawaan otomatis dari
     * pilihPicOtomatis() yang tetap tampil.
     */
    protected function muatPicTersimpan(?string $pic): void
    {
        if ($pic !== null && trim($pic) !== '') {
            // rtl_evaluasi.pic tetap satu kolom teks bebas (bukan tabel relasi) --
            // dipisah koma/titik-koma di sini untuk dimuat balik sebagai chip, sama
            // pola pisahnya dengan App\Models\MasterIku::booted() (sinkron ke iku_tim).
            $this->rtlBaruPicTerpilih = collect(preg_split('/[,;]/', $pic))
                ->map(fn ($t) => trim($t))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }
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
        // bila bagiannya dikonfigurasi bukti_wajib (bisa dimatikan Tim SAKIP per bagian)
        // DAN belum punya bukti lama tersimpan (existing_bukti, dimuat di
        // muatBagianKustomBlocks()) — poin yang sedang diedit ulang tidak dipaksa
        // mengunggah bukti BARU lagi cuma untuk memperbaiki teksnya, sama seperti
        // aturan blocks.*.bukti untuk Kegiatan di atas.
        foreach ($this->bagianKustomAktif() as $bagian) {
            $rules["bagianKustomBlocks.{$bagian->id}.*.teks"] = ['nullable', 'string'];

            foreach ($this->bagianKustomBlocks[$bagian->id] ?? [] as $i => $blok) {
                $adaBuktiLama = ! empty($blok['existing_bukti']);

                $rules["bagianKustomBlocks.{$bagian->id}.{$i}.bukti"] = ($bagian->bukti_wajib && ! $adaBuktiLama)
                    ? ['required_with:bagianKustomBlocks.'.$bagian->id.'.'.$i.'.teks', 'array']
                    : ['nullable', 'array'];
            }

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
        //
        // rtlBaruPicTerpilih SENGAJA nullable/opsional di sini (bukan required) — PIC
        // boleh dikosongkan Ketua Tim; Tim SAKIP yang wajib mengisi/mengonfirmasinya
        // sebelum verifikasi selesai (lihat VerifikasiCapaian::verifikasiSelesai()).
        // Sebelumnya wajib di sini padahal field-nya dikunci hanya-baca & terisi
        // otomatis dari MasterIku::tim — begitu tim IKU belum dikonfigurasi (tim
        // kosong), Ketua Tim tidak akan pernah bisa mengaktifkan tombol "Ajukan ke
        // Tim SAKIP" sama sekali.
        if ($this->rtlBaruBisaDiisi() && ! $this->rtlTriwulanBerikutnyaSudahAda()) {
            $rules['rtlBaru'] = ['required', 'array', 'min:1'];
            $rules['rtlBaru.*.rtl_teks'] = ['required', 'string'];
            $rules['rtlBaruPicTerpilih'] = ['nullable', 'array'];
            $rules['rtlBaruPicTerpilih.*'] = ['string', 'max:255'];
            $rules['rtlBaruBatasWaktu'] = ['required', 'date'];
        } elseif ($this->rtlBaruBisaDiisi()) {
            // RTL triwulan berikutnya sudah pernah ditetapkan (lihat blade: poin lama
            // tampil hanya-baca) — menambah poin BARU ke batch yang sama di sini
            // bersifat OPSIONAL (Ketua Tim boleh mengajukan perubahan lain tanpa wajib
            // menambah RTL), beda dari penetapan pertama kali di atas yang wajib.
            $rules['rtlBaru.*.rtl_teks'] = ['nullable', 'string'];
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
            'rtlBaruPicTerpilih' => 'PIC Tindak Lanjut',
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
    protected function buatValidator(): Validator
    {
        $data = [
            'tahun' => $this->tahun,
            'bulan' => $this->bulan,
            'iku_id' => $this->iku_id,
            'blocks' => $this->blocks,
            'kendalaBlocks' => $this->kendalaBlocks,
            'evaluasi' => $this->evaluasi,
            'rtlBaru' => $this->rtlBaru,
            'rtlBaruPicTerpilih' => $this->rtlBaruPicTerpilih,
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

            // Kendala & Solusi boleh dikosongkan tiap bulan (lihat info banner di blade),
            // TAPI minimal satu pasangan wajib sudah tercatat untuk triwulan ini begitu
            // sampai bulan TERAKHIR triwulan — baik yang diisi bulan ini (kendalaBlocks)
            // maupun yang sudah diajukan di bulan-bulan sebelumnya triwulan yang sama
            // (riwayatKendalaSolusi(), sudah di-scope per triwulan lewat groupBy di sana).
            if ($this->bulanKeDari($this->bulan) === 3) {
                $adaDiFormIni = collect($this->kendalaBlocks)
                    ->contains(fn ($blok) => filled($blok['kendala'] ?? null))
                    || $this->kendalaAktif()->isNotEmpty();

                $adaDiBulanSebelumnya = $this->riwayatKendalaSolusi()
                    ->get($this->triwulanDari($this->bulan), collect())
                    ->isNotEmpty();

                if (! $adaDiFormIni && ! $adaDiBulanSebelumnya) {
                    $validator->errors()->add(
                        'kendalaBlocks',
                        'Minimal satu pasangan Kendala & Solusi wajib diisi sebelum diajukan pada bulan terakhir triwulan ini.'
                    );
                }
            }

            // RF-30 (diperbarui): kegiatan TIDAK wajib memakai kata-kata RTL persis lagi —
            // boleh dipilih lewat dropdown RTL (menaut otomatis) ATAU diketik bebas di luar
            // rencana RTL triwulan sebelumnya, ASALKAN jumlah kegiatan yang diisi triwulan
            // ini minimal SAMA BANYAK dengan jumlah poin RTL yang diajukan triwulan
            // sebelumnya — dan (dicek terpisah di atas) bukti evaluasi RTL sudah lengkap
            // semua. Hanya digerbang pada bulan TERAKHIR triwulan, sama seperti aturan lain.
            if ($this->bulanKeDari($this->bulan) === 3) {
                $jumlahRtl = $this->rtlTriwulanBerjalan()->count();

                if ($jumlahRtl > 0) {
                    $jumlahKegiatanTerisi = collect($this->blocks)
                        ->filter(fn ($block) => filled($block['uraian_kegiatan'] ?? null))
                        ->count();

                    if ($jumlahKegiatanTerisi < $jumlahRtl) {
                        $validator->errors()->add(
                            'blocks',
                            "Jumlah kegiatan yang diisi ({$jumlahKegiatanTerisi}) masih kurang dari jumlah poin RTL yang diajukan triwulan sebelumnya ({$jumlahRtl}) — minimal harus sama banyak sebelum diajukan pada bulan terakhir triwulan ini."
                        );
                    }
                }
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

        if ($this->formTerkunciSedangDitanganiFresh()) {
            $this->dispatch('notify', type: 'error', message: 'Isian ini sedang ditangani Tim SAKIP dan tidak bisa diubah sampai verifikasi selesai.');

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
        } catch (ValidationException $e) {
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

        $iku = MasterIku::findOrFail($this->iku_id);
        $folderService = app(FolderStructureService::class);

        // Diisi setiap kali satu berkas GAGAL disinkron ke Drive — sama seperti
        // ajukanIsian(), lihat catatan di sana.
        $driveGagal = [];

        DB::transaction(function () use ($periode, $iku, $folderService, &$driveGagal) {
            $this->simpanBagianIsian($periode, $iku, $folderService, $driveGagal, ajukan: false);
        });

        session()->flash('status', 'Draf berhasil disimpan — kegiatan, kendala & solusi, evaluasi RTL, dan RTL berikutnya yang sudah diisi ikut tersimpan. Lengkapi bagian yang belum lengkap lalu ajukan ke Tim SAKIP saat siap.');

        if (! empty($driveGagal)) {
            session()->flash('driveGagal', $driveGagal);
        }

        $this->lupakanCachePeriodeIku();

        // Reload halaman penuh — lihat catatan senada di akhir ajukanIsian(). Query
        // string iku_id/tahun/bulan SENGAJA disertakan supaya mount() langsung memuat
        // ulang IKU yang sama (lihat mount()) — tanpa ini, draf yang baru saja
        // tersimpan (kendala/solusi, evaluasi RTL, RTL berikutnya) akan tampak "hilang"
        // begitu halaman terbuka lagi, padahal sebenarnya sudah tersimpan di database,
        // hanya belum dimuat ulang ke form karena IKU belum otomatis terpilih lagi.
        $this->redirect(route('pengisian.index', [
            'iku_id' => $this->iku_id,
            'tahun' => $this->tahun,
            'bulan' => $this->bulan,
        ]));
    }

    /**
     * Logika penyimpanan bersama antara simpanDraft() dan ajukanIsian() — supaya
     * mengklik "Simpan Draft" TIDAK diam-diam membuang kendala & solusi, evaluasi
     * RTL, RTL berikutnya, dan bukti yang sudah diisi/diunggah (bug produksi:
     * hanya kolom Kegiatan yang tersimpan, sisanya baru benar-benar tersimpan saat
     * "Ajukan ke Tim SAKIP" diklik, padahal pengguna mengira "Simpan Draft" sudah
     * menyimpan SEMUA isian di halaman).
     *
     * Saat $ajukan === false (dipanggil dari simpanDraft()): kegiatan/kendala &
     * solusi/evaluasi RTL/RTL berikutnya/bagian kustom yang sudah diisi tetap
     * disimpan & bukti tetap diunggah, TAPI status kegiatan/capaian TIDAK
     * dipindahkan ke "diajukan" — supaya isian tetap dianggap draft dan tidak
     * masuk antrean verifikasi Tim SAKIP sebelum benar-benar diajukan.
     */
    protected function simpanBagianIsian(Periode $periode, MasterIku $iku, FolderStructureService $folderService, array &$driveGagal, bool $ajukan): void
    {
        $capaian = Capaian::firstOrCreate([
            'iku_id' => $this->iku_id,
            'periode_id' => $periode->id,
        ]);

        $adaKegiatanDiajukan = false;
        $adaPerbaikanLainDiajukan = false;

        // 1) Kegiatan + bukti capaian (bukti melekat langsung ke kegiatan, RF-23)
        foreach ($this->blocks as &$block) {
            // Blok yang sudah diajukan/diverifikasi/disetujui ditampilkan hanya-baca
            // di form (lihat muatBlocksKegiatan()) — tidak boleh ikut tertimpa di sini.
            if (in_array($block['status_dokumen'] ?? null, self::STATUS_KEGIATAN_TERKUNCI, true)) {
                continue;
            }

            // Blok kosong (baru ditambah lewat "Tambah Kegiatan" tapi belum diisi sama
            // sekali) dilewati saat draft — beda dari ajukanIsian() yang memvalidasi
            // SEMUA blok wajib terisi, draft boleh punya blok kosong tertinggal.
            if (! $ajukan && trim($block['uraian_kegiatan']) === '') {
                continue;
            }

            $tahapan = $block['jenis'] === 'survei_sensus' ? $block['tahapan_survei'] : null;

            $namaFolderAuto = Str::limit(
                ($tahapan ? '['.ucfirst($tahapan).'] ' : '').$block['uraian_kegiatan'],
                100,
                ''
            );

            if ($block['id']) {
                // UPDATE kegiatan draft/dikembalikan yang sudah ada, bukan create baru —
                // supaya menyimpan/mengajukan ulang isian yang sudah ada tidak membuat
                // baris duplikat. drive_folder_id SENGAJA tidak disentuh (dipakai ulang
                // oleh FolderStructureService, lihat catatan di model Kegiatan).
                $kegiatan = Kegiatan::findOrFail($block['id']);
                $kegiatan->update([
                    'rtl_evaluasi_id' => $this->rtlEvaluasiIdUntukBlock($block),
                    'uraian_kegiatan' => $block['uraian_kegiatan'],
                    'jenis' => $block['jenis'],
                    'tahapan_survei' => $tahapan,
                    'nama_folder_auto' => $namaFolderAuto,
                ]);
            } else {
                $kegiatan = Kegiatan::create([
                    'iku_id' => $this->iku_id,
                    'rtl_evaluasi_id' => $this->rtlEvaluasiIdUntukBlock($block),
                    'periode_id' => $periode->id,
                    'uraian_kegiatan' => $block['uraian_kegiatan'],
                    'jenis' => $block['jenis'],
                    'tahapan_survei' => $tahapan,
                    'nama_folder_auto' => $namaFolderAuto,
                    'status_dokumen' => Kegiatan::STATUS_DRAFT,
                ]);
                $block['id'] = $kegiatan->id;
            }

            if ($ajukan) {
                // draft→diajukan maupun dikembalikan→diajukan, keduanya diizinkan
                // (lihat Kegiatan::TRANSITIONS) — tidak perlu logic tambahan di sini.
                $kegiatan->ajukan();
                $adaKegiatanDiajukan = true;
            }

            // RF-17: nama berkas & folder mengikuti uraian kegiatan (bukan tahapan
            // survei — itu cuma prefix folder, lihat namaFolderAuto di atas), supaya
            // langsung dikenali dari daftar berkas tanpa harus dibuka satu-satu.
            $namaBerkasDasar = 'Kegiatan '.$block['uraian_kegiatan'];

            foreach ($block['bukti'] as $file) {
                $path = $file->store('bukti-capaian', 'local');

                $berkas = Berkas::create([
                    'ref_id' => $kegiatan->id,
                    'ref_type' => Kegiatan::class,
                    'kategori' => 'capaian',
                    'nama_file' => $namaBerkasDasar.'.pdf',
                    'path' => $path,
                    'status_verifikasi' => 'menunggu',
                ]);

                // Dibungkus try/catch supaya penyimpanan TETAP berhasil walau Drive
                // belum terkonfigurasi — berkas tetap aman di disk lokal sebagai cadangan.
                try {
                    $this->streamProgresUnggah($file->getClientOriginalName(), 'bukti kegiatan');
                    $localFullPath = Storage::disk('local')->path($path);
                    $hasilDrive = $folderService->unggahBerkasKegiatan($kegiatan, 'capaian', $localFullPath, namaBerkasOverride: $namaBerkasDasar);
                    $berkas->update($hasilDrive);
                    $this->notifikasiHasilUnggah($file->getClientOriginalName(), 'bukti kegiatan', true);
                } catch (\Throwable $e) {
                    Log::warning('Gagal mengunggah berkas ke Google Drive, disimpan lokal saja: '.$e->getMessage());
                    $driveGagal[] = "{$file->getClientOriginalName()} (bukti kegiatan: {$block['uraian_kegiatan']})";
                    $this->notifikasiHasilUnggah($file->getClientOriginalName(), 'bukti kegiatan', false, $e->getMessage());
                }
            }

            // Berkas yang baru saja tersimpan di atas dipindah ke existing_bukti supaya
            // tidak dicoba diunggah ulang bila simpanBagianIsian() dipanggil lagi dalam
            // request yang sama (tidak terjadi saat ini, tapi jaga-jaga) dan supaya form
            // yang dimuat ulang tidak menampilkan input unggah kosong lagi tanpa alasan.
            $block['bukti'] = [];
        }
        unset($block);

        // 2) Kendala & Solusi (blok kosong dilewati — bagian ini opsional per periode).
        // UPDATE, bukan create, bila blok ini punya id (pasangan lama yang DRAFT
        // milik sendiri ATAU ditolak Tim SAKIP dan sedang diperbaiki — lihat
        // muatKendalaBlocks()), supaya tidak duplikat. status_verifikasi & catatan
        // SENGAJA TIDAK direset ke "menunggu" — tanda "Tidak Sesuai" + alasannya
        // tetap tampil ke Tim SAKIP sampai mereka sendiri menandainya ulang
        // (tandaiKendalaSesuai()/tandaiKendalaTolak()), supaya mereka masih ingat
        // apa yang salah sebelumnya saat memeriksa perbaikan ini. Baru kosong
        // begitu ditandai "Sesuai". status_dokumen HANYA disentuh saat benar-benar
        // mengajukan ($ajukan) — pola sama persis dengan RTL berikutnya di langkah
        // 4 di bawah — supaya pasangan ini langsung terkunci hanya-baca (lihat
        // kendalaAktif()) begitu diajukan, bukan tetap bisa diedit bebas.
        foreach ($this->kendalaBlocks as &$block) {
            if (trim($block['kendala']) === '' && trim($block['solusi']) === '') {
                continue;
            }

            if ($block['id'] ?? null) {
                $dataUpdate = [
                    'kendala' => $block['kendala'],
                    'solusi' => $block['solusi'] ?: null,
                ];

                if ($ajukan) {
                    $dataUpdate['status_dokumen'] = KendalaSolusiModel::STATUS_DIAJUKAN;
                }

                KendalaSolusiModel::whereKey($block['id'])->update($dataUpdate);
            } else {
                $model = KendalaSolusiModel::create([
                    'iku_id' => $this->iku_id,
                    'periode_id' => $periode->id,
                    'kendala' => $block['kendala'],
                    'solusi' => $block['solusi'] ?: null,
                    'status_dokumen' => $ajukan ? KendalaSolusiModel::STATUS_DIAJUKAN : KendalaSolusiModel::STATUS_DRAFT,
                ]);
                $block['id'] = $model->id;
            }

            $adaPerbaikanLainDiajukan = true;
        }
        unset($block);

        // 3) Evaluasi RTL triwulan sebelumnya — cukup bukti realisasi (SEMUA poin wajib
        // punya bukti bila sedang mengajukan pada bulan terakhir triwulan, dicek di
        // buatValidator()); baris tanpa bukti baru dilewati begitu saja di sini.
        foreach ($this->evaluasi as $rtlId => &$data) {
            if (empty($data['bukti'])) {
                continue;
            }

            $poin = RtlEvaluasiModel::with(['periode', 'masterIku'])->findOrFail($rtlId);
            $namaBerkasDasar = 'Evaluasi RTL '.$poin->rtl_teks;
            $adaPerbaikanLainDiajukan = true;

            // status_verifikasi & catatan SENGAJA TIDAK direset ke "menunggu" di sini
            // walau bukti baru baru saja diunggah — tanda "Tidak Sesuai" + alasannya
            // TETAP tampil ke Tim SAKIP sampai mereka sendiri yang menandainya ulang
            // (tandaiRtlSesuai()/tandaiRtlTolak()), supaya mereka masih ingat apa yang
            // salah sebelumnya saat memeriksa bukti baru ini. Baru kosong begitu Tim
            // SAKIP menandai "Sesuai". Sama seperti bukti Kegiatan (Berkas lama yang
            // "ditolak" juga tidak pernah direset otomatis di sini).

            foreach ($data['bukti'] as $file) {
                $path = $file->store('bukti-evaluasi-rtl', 'local');

                $berkas = Berkas::create([
                    'ref_id' => $poin->id,
                    'ref_type' => RtlEvaluasiModel::class,
                    'kategori' => 'evaluasi_rtl',
                    'nama_file' => $namaBerkasDasar.'.pdf',
                    'path' => $path,
                    'status_verifikasi' => 'menunggu',
                ]);

                try {
                    $this->streamProgresUnggah($file->getClientOriginalName(), 'bukti evaluasi RTL');
                    $localFullPath = Storage::disk('local')->path($path);
                    $hasilDrive = $folderService->unggahBerkas($poin->periode, $poin->masterIku, 'evaluasi_rtl', $localFullPath, namaBerkasOverride: $namaBerkasDasar);
                    $berkas->update($hasilDrive);
                    $this->notifikasiHasilUnggah($file->getClientOriginalName(), 'bukti evaluasi RTL', true);
                } catch (\Throwable $e) {
                    Log::warning('Gagal mengunggah bukti evaluasi RTL ke Google Drive, disimpan lokal saja: '.$e->getMessage());
                    $driveGagal[] = "{$file->getClientOriginalName()} (bukti evaluasi RTL)";
                    $this->notifikasiHasilUnggah($file->getClientOriginalName(), 'bukti evaluasi RTL', false, $e->getMessage());
                }
            }

            $data['bukti'] = [];
        }
        unset($data);

        // 4) RTL triwulan berikutnya — hanya boleh diisi di bulan terakhir triwulan
        // berjalan (dicek jugu di buatValidator() saat mengajukan). TIDAK LAGI
        // digerbang pada "belum pernah ditetapkan": begitu batch pertama sudah
        // ditetapkan, poin-poin lamanya tampil hanya-baca di blade (tidak pernah
        // masuk $rtlBaru lagi kecuali ditolak Tim SAKIP), tapi Ketua Tim tetap boleh
        // menambah poin BARU ke batch yang sama sebelum Tim SAKIP menyelesaikan
        // pemeriksaan — sama seperti Kegiatan/Kendala & Solusi/Bagian Kustom yang
        // juga tetap bisa ditambah selagi Capaian masih "diajukan" (lihat addBlock()).
        // Poin dengan teks masih kosong dilewati saat draft (belum ada validator yang
        // mewajibkannya di sini).
        if ($this->rtlBaruBisaDiisi()) {
            $sudahAda = $this->rtlTriwulanBerikutnyaSudahAda();

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

            // PIC dipilih bebas oleh Ketua Tim lewat chip (tambahRtlBaruPic(), lihat
            // blade) -- rtl_evaluasi.pic tetap satu kolom teks, jadi beberapa tim
            // digabung dipisah koma di sini (dimuat balik jadi chip lagi lewat
            // muatPicTersimpan()). Boleh benar-benar dikosongkan (hapus semua chip) --
            // pilihPicOtomatis() sudah membawakan SELURUH tim penanggung jawab IKU ini
            // (App\Models\MasterIku::namaTimList()) sebagai bawaan begitu form dibuka,
            // jadi array kosong di sini berarti Ketua Tim sengaja menghapusnya.
            $picTim = $this->rtlBaruPicTerpilih !== [] ? implode(', ', $this->rtlBaruPicTerpilih) : null;

            // Batch sudah ditetapkan sebelumnya — poin BARU yang ditambahkan harus
            // ikut PIC batch yang sama (bukan bawaan IKU/dropdown yang tidak
            // ditampilkan lagi di blade untuk kasus ini), supaya satu batch RTL tidak
            // punya PIC berbeda-beda antar poinnya.
            if ($sudahAda) {
                $picAktif = $this->rtlBerikutnyaAktif()->first()?->pic;

                if (filled($picAktif)) {
                    $picTim = $picAktif;
                }
            }

            foreach ($this->rtlBaru as &$blok) {
                if (trim($blok['rtl_teks'] ?? '') === '') {
                    continue;
                }

                // Blok dengan id terisi berarti poin lama — baik yang DITOLAK Tim SAKIP
                // (dimuat lewat rtlBerikutnyaDitolak()) MAUPUN draft milik sendiri yang
                // belum pernah diajukan (dimuat lewat rtlBerikutnyaDraftSendiri(), lihat
                // muatRtlBaruBlocks()) — di-UPDATE di tempat, BUKAN dibuat baris duplikat.
                // Pola sama seperti BagianKustomPoin di langkah 5 di bawah.
                if (! empty($blok['id'])) {
                    $dataUpdate = [
                        'rtl_teks' => $blok['rtl_teks'],
                        'berlaku_bulan' => $berlakuBulan,
                        'pic' => $picTim,
                        'batas_waktu' => $this->rtlBaruBatasWaktu,
                    ];

                    // status_dokumen/status_verifikasi/catatan HANYA disentuh saat benar-
                    // benar mengajukan ($ajukan) — draft yang disimpan ulang (baik draft
                    // sendiri maupun poin yang ditolak sedang diperbaiki) tetap TIDAK
                    // dianggap "sudah ditetapkan" (lihat rtlTriwulanBerikutnyaSudahAda())
                    // dan alasan penolakan lama (bila ada) tetap tampil sampai benar-benar
                    // diajukan ulang.
                    if ($ajukan) {
                        $dataUpdate['status_dokumen'] = RtlEvaluasiModel::STATUS_DIAJUKAN;
                        $dataUpdate['status_verifikasi'] = 'menunggu';
                        $dataUpdate['catatan'] = null;
                    }

                    RtlEvaluasiModel::whereKey($blok['id'])->update($dataUpdate);

                    continue;
                }

                $rtl = RtlEvaluasiModel::create([
                    'iku_id' => $this->iku_id,
                    'periode_id' => $periodeTarget->id,
                    'rtl_teks' => $blok['rtl_teks'],
                    'berlaku_bulan' => $berlakuBulan,
                    'pic' => $picTim,
                    'batas_waktu' => $this->rtlBaruBatasWaktu,
                    'status_dokumen' => $ajukan ? RtlEvaluasiModel::STATUS_DIAJUKAN : RtlEvaluasiModel::STATUS_DRAFT,
                ]);
                $blok['id'] = $rtl->id;
            }
            unset($blok);
        }

        // 5) Bagian kustom (mis. Manajemen Risiko) — poin kosong dilewati, sama
        // seperti Kendala & Solusi; bukti sudah dipastikan wajib lewat validasi saat
        // mengajukan. UPDATE, bukan create, bila blok ini punya id (poin lama yang
        // dimuat lewat muatBagianKustomBlocks() untuk diperbaiki teksnya) — supaya
        // tidak duplikat, mengikuti pola yang sama dengan Kendala & Solusi di atas.
        foreach ($this->bagianKustomAktif() as $bagian) {
            foreach ($this->bagianKustomBlocks[$bagian->id] ?? [] as $i => $blok) {
                if (trim($blok['teks'] ?? '') === '') {
                    continue;
                }

                if ($blok['id'] ?? null) {
                    $poin = BagianKustomPoin::whereKey($blok['id'])->first();

                    if (! $poin) {
                        continue;
                    }

                    // status_verifikasi & catatan SENGAJA TIDAK direset — sama seperti
                    // Kendala & Solusi/Evaluasi RTL di atas, lihat penjelasan di sana.
                    $poin->update(['teks' => $blok['teks']]);
                } else {
                    $poin = BagianKustomPoin::create([
                        'bagian_kustom_id' => $bagian->id,
                        'iku_id' => $this->iku_id,
                        'periode_id' => $periode->id,
                        'teks' => $blok['teks'],
                    ]);
                    $this->bagianKustomBlocks[$bagian->id][$i]['id'] = $poin->id;
                }

                $adaPerbaikanLainDiajukan = true;
                $namaBerkasDasar = $bagian->nama.' '.$poin->teks;

                foreach ($blok['bukti'] as $file) {
                    $path = $file->store('bukti-bagian-kustom', 'local');

                    $berkas = Berkas::create([
                        'ref_id' => $poin->id,
                        'ref_type' => BagianKustomPoin::class,
                        'kategori' => 'bagian_kustom',
                        'nama_file' => $namaBerkasDasar.'.pdf',
                        'path' => $path,
                        'status_verifikasi' => 'menunggu',
                    ]);

                    try {
                        $this->streamProgresUnggah($file->getClientOriginalName(), "bukti {$bagian->nama}");
                        $localFullPath = Storage::disk('local')->path($path);
                        $hasilDrive = $folderService->unggahBerkas(
                            $periode, $iku, 'bagian_kustom', $localFullPath,
                            namaFolderOverride: $bagian->nama,
                            namaBerkasOverride: $namaBerkasDasar,
                        );
                        $berkas->update($hasilDrive);
                        $this->notifikasiHasilUnggah($file->getClientOriginalName(), "bukti {$bagian->nama}", true);
                    } catch (\Throwable $e) {
                        Log::warning("Gagal mengunggah bukti {$bagian->nama} ke Google Drive, disimpan lokal saja: ".$e->getMessage());
                        $driveGagal[] = "{$file->getClientOriginalName()} (bukti {$bagian->nama})";
                        $this->notifikasiHasilUnggah($file->getClientOriginalName(), "bukti {$bagian->nama}", false, $e->getMessage());
                    }
                }

                $this->bagianKustomBlocks[$bagian->id][$i]['bukti'] = [];
            }
        }

        // Dicek TERAKHIR, setelah SELURUH bagian (Kegiatan, Kendala & Solusi, Evaluasi
        // RTL, Bagian Kustom) selesai diproses di atas — supaya perbaikan pada bagian
        // MANA PUN yang tadinya jadi alasan "dikembalikan" ikut memindahkan Capaian ini
        // balik ke "diajukan", bukan cuma perbaikan pada Kegiatan. Hanya dilakukan saat
        // benar-benar mengajukan ($ajukan) — draft TIDAK boleh memindahkan status
        // capaian/kegiatan keluar dari "draft"/"dikembalikan".
        if ($ajukan && ($adaKegiatanDiajukan || $adaPerbaikanLainDiajukan)) {
            $capaian->catatStatus(Kegiatan::STATUS_DIAJUKAN, auth()->user());
        }
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

        if ($this->formTerkunciSedangDitanganiFresh()) {
            $this->dispatch('notify', type: 'error', message: 'Isian ini sedang ditangani Tim SAKIP dan tidak bisa diubah sampai verifikasi selesai.');

            return;
        }

        try {
            $this->buatValidator()->validate();
        } catch (ValidationException $e) {
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
            $this->simpanBagianIsian($periode, $iku, $folderService, $driveGagal, ajukan: true);
        });

        $this->stream(to: 'progres-unggah', content: '', replace: true);

        // Menyebutkan IKU + triwulan/tahun secara eksplisit — Ketua Tim biasanya mengisi
        // beberapa IKU berturut-turut, jadi pesan generik "berhasil diajukan" saja tidak
        // cukup jelas isian YANG MANA yang baru saja terkirim.
        $angkaRomawiTriwulan = ['I', 'II', 'III', 'IV'][$this->triwulanDari($this->bulan) - 1];
        $labelIsian = "{$iku->kode} — {$iku->indikator} (Triwulan {$angkaRomawiTriwulan} {$this->tahun}, {$this->namaBulanIndo($this->bulan)})";

        session()->flash(
            'status',
            "Isian {$labelIsian} berhasil diajukan ke Tim SAKIP. "
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
            'kendalaAktif' => $this->kendalaAktif(),
            'rtlSebelumnya' => $this->rtlTriwulanBerjalan(),
            'sudahAdaRtlBerikutnya' => $this->rtlTriwulanBerikutnyaSudahAda(),
            'rtlBerikutnyaDitolak' => $this->rtlBerikutnyaDitolak(),
            'rtlBerikutnyaAktif' => $this->rtlBerikutnyaAktif(),
            'labelBerikutnya' => $this->labelTriwulanBerikutnya(),
            'bulanTargetBerikutnya' => collect($this->bulanBulanTarget())->mapWithKeys(fn ($b) => [$b => $this->namaBulanIndo($b)]),
            'rtlBerjalanOptions' => $this->rtlBerjalanOptions(),
            'rtlBerjalanBelumTerlaksana' => $this->poinRtlBerjalanBelumTerlaksana(),
            'daftarTimPic' => $this->daftarTimPic(),
            'bagianKustomAktif' => $bagianKustomAktif,
            'riwayatBagianKustom' => $bagianKustomAktif->mapWithKeys(fn ($b) => [$b->id => $this->riwayatBagianKustom($b)]),
            'statusKegiatanTerkunci' => self::STATUS_KEGIATAN_TERKUNCI,
            'catatanPenolakan' => $this->catatanPenolakan(),
            'pengembalianTerakhir' => $this->pengembalianTerakhir(),
            'adaDikembalikan' => collect($this->blocks)->contains(fn ($b) => ($b['status_dokumen'] ?? null) === Kegiatan::STATUS_DIKEMBALIKAN)
                || collect($this->kendalaBlocks)->contains(fn ($b) => ($b['status_verifikasi'] ?? null) === 'ditolak'),
            'formTerkunciDisetujui' => $this->formTerkunciDisetujui(),
            'formTerkunciSedangDitangani' => $this->formTerkunciSedangDitangani(),
            'evaluasiTerkunci' => in_array($this->statusCapaianSaatIni(), self::STATUS_KEGIATAN_TERKUNCI, true),
        ]);
    }
}
