<?php

namespace App\Services;

use App\Models\FolderConfig;
use App\Models\IkuFolderConfig;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\StorageAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Menyusun struktur folder otomatis di Google Drive (RF-11 s.d. RF-17), di atas
 * GoogleDriveService yang generik. Kelas ini yang tahu ATURAN BISNIS SIPINTER
 * (urutan Tahun/Triwulan/Bulan/IKU, kategori wajib, subfolder per kegiatan, dsb),
 * sedangkan GoogleDriveService hanya tahu cara bicara ke Drive API.
 *
 * Pemetaan nilai enum `berkas.kategori` (snake_case, dipakai di database) ke nama
 * folder Drive (Title-Case, dilihat manusia). Empat kategori ini WAJIB tersedia
 * secara sistem (lihat FolderConfig::KATEGORI_WAJIB untuk Capaian & Bukti-Dukung-SAKIP
 * yang tidak boleh dihapus); Tim SAKIP tetap bisa menambah kategori LAIN lewat RF-15,
 * tapi keempat nama baku ini dipakai tetap agar Berkas selalu tahu ke folder mana ia
 * harus tersimpan meski Tim SAKIP mengatur ulang daftar kategori.
 */
class FolderStructureService
{
    public const KATEGORI_KE_FOLDER = [
        'capaian' => 'Capaian',
        'solusi' => 'Solusi',
        'evaluasi_rtl' => 'Evaluasi-RTL',
        'bukti_dukung_sakip' => 'Bukti-Dukung-SAKIP',
    ];

    public function __construct(protected GoogleDriveService $drive) {}

    // ================================================================
    // RF-17 — penamaan otomatis. Method STATIS & murni (tidak menyentuh
    // Drive/DB) supaya mudah diuji dan dipakai ulang di tempat lain.
    // ================================================================

    /**
     * Rapikan teks masukan pengguna menjadi nama folder/berkas yang aman & ringkas.
     *
     * Catatan istilah: RF-17 menyebutnya "di-slugify", tapi contoh jalur folder resmi
     * di SRS & mockup ("[Pelaksanaan] pencacahan rumah tangga Sakernas Mei 2026") tetap
     * memakai huruf besar/kecil asli dan spasi — BUKAN slug URL ber-tanda-hubung. Jadi di
     * sini "slugify" diartikan sebagai "dibersihkan agar aman jadi nama folder/berkas"
     * (buang karakter terlarang di Windows/Drive, rapikan spasi ganda), sambil tetap
     * mempertahankan keterbacaan aslinya, lalu dipotong ke maksimum $maxLength karakter.
     */
    public static function namaOtomatis(string $teks, int $maxLength = 100): string
    {
        // Karakter terlarang diganti SPASI (bukan dihapus polos) supaya kata di kedua
        // sisinya tidak menempel jadi satu, mis. "Rumah/Tangga" -> "Rumah Tangga",
        // bukan "RumahTangga". Spasi berlebih dari penggantian ini dirapikan setelahnya.
        $bersih = preg_replace('/[\\\\\/:*?"<>|]/', ' ', $teks) ?? $teks;
        $bersih = trim(preg_replace('/\s+/', ' ', $bersih) ?? $bersih);

        return Str::limit($bersih, $maxLength, '');
    }

    /**
     * Tambahkan penomoran berurutan (-2, -3, dst.) bila $namaDasar sudah dipakai salah
     * satu dari $namaSudahAda — dipakai untuk subfolder kegiatan maupun nama berkas.
     *
     * @param  list<string>  $namaSudahAda  nama yang sudah ada di folder tujuan (termasuk ekstensi bila berkas).
     */
    public static function namaUnik(string $namaDasar, array $namaSudahAda, string $ekstensi = ''): string
    {
        $namaSudahAdaLower = array_map('strtolower', $namaSudahAda);
        $kandidat = $namaDasar.$ekstensi;

        if (! in_array(strtolower($kandidat), $namaSudahAdaLower, true)) {
            return $kandidat;
        }

        for ($nomor = 2; ; $nomor++) {
            $kandidat = "{$namaDasar}-{$nomor}{$ekstensi}";

            if (! in_array(strtolower($kandidat), $namaSudahAdaLower, true)) {
                return $kandidat;
            }
        }
    }

    // ================================================================
    // RF-11 & RF-16 — folder Tahun (level paling atas)
    // ================================================================

    /**
     * Folder tahun langsung di bawah root folder akun storage AKTIF. "Lazy" secara
     * alami lewat findOrCreateFolder(): dipanggil berkali-kali dari mana saja tetap
     * aman, tidak pernah membuat folder tahun ganda.
     */
    public function resolveTahunFolder(StorageAccount $akun, int $tahun): string
    {
        if (! $akun->drive_folder_id) {
            throw new RuntimeException(
                "Akun storage {$akun->email_gmail_institusi} belum diisi ID folder Drive-nya. ".
                'Lengkapi dahulu di menu Akun & Storage.'
            );
        }

        return $this->drive->findOrCreateFolder((string) $tahun, $akun->drive_folder_id);
    }

    /**
     * RF-16: sediakan folder tahun tanpa menunggu ada unggahan (mis. menyiapkan
     * folder tahun depan sebelum tahun berjalan berakhir). Dipanggil manual oleh
     * Tim SAKIP dari menu Konfigurasi.
     */
    public function buatFolderTahunLebihAwal(int $tahun): string
    {
        return $this->resolveTahunFolder($this->akunAktifAtauGagal(), $tahun);
    }

    // ================================================================
    // RF-11 & RF-12 — Triwulan → Bulan → IKU → kategori
    // ================================================================

    /**
     * Susun/temukan seluruh folder induk sampai ke folder KATEGORI (mis. ".../2026/
     * Triwulan II/Juni/IKU 1131/Capaian"). Level triwulan/bulan/IKU mengikuti urutan
     * & status aktif di FolderConfig (RF-15); "tahun" selalu diproses lebih dulu.
     */
    public function resolveKategoriFolder(Periode $periode, MasterIku $iku, string $namaKategori): string
    {
        $akun = $this->akunAktifAtauGagal();
        $config = $this->configUntukIku($iku);

        $folderId = $this->resolveTahunFolder($akun, $periode->tahun);

        foreach ($config->hierarkiAktif() as $level) {
            if ($level === FolderConfig::LEVEL_TAHUN) {
                continue; // sudah ditangani resolveTahunFolder() di atas.
            }

            $namaLevel = match ($level) {
                FolderConfig::LEVEL_TRIWULAN => 'Triwulan '.(['I', 'II', 'III', 'IV'][$periode->triwulan - 1] ?? $periode->triwulan),
                FolderConfig::LEVEL_BULAN => Carbon::create($periode->tahun, $periode->bulan, 1)->locale('id')->translatedFormat('F'),
                // Nama folder IKU mengikuti apa adanya nilai kolom "Kode" yang diisi Tim
                // SAKIP di Master IKU (RF-08) — sistem tidak memformat ulang nilainya.
                FolderConfig::LEVEL_IKU => $iku->kode,
                // Tingkat kustom (RF-15) — namanya sendiri dipakai apa adanya sebagai
                // folder tetap (sama untuk semua periode/IKU), bukan nilai yang dihitung.
                default => $level,
            };

            if ($namaLevel !== null) {
                $folderId = $this->drive->findOrCreateFolder($namaLevel, $folderId);
            }
        }

        return $this->drive->findOrCreateFolder($namaKategori, $folderId);
    }

    // ================================================================
    // RF-13 & RF-14 — subfolder per kegiatan (khusus kategori Capaian)
    // ================================================================

    /**
     * Folder milik SATU kegiatan di dalam folder kategori (biasanya "Capaian").
     *
     * Lazy (RF-14): baru benar-benar dibuat di Drive saat pertama kali dipanggil,
     * yaitu ketika berkas pertama kegiatan ini diunggah. ID hasilnya disimpan ke
     * kegiatan.drive_folder_id supaya pemanggilan berikutnya tinggal dipakai ulang.
     *
     * Sengaja TIDAK memakai findOrCreateFolder (cari berdasar kecocokan nama) seperti
     * level lain, karena dua kegiatan BISA menghasilkan nama_folder_auto yang identik
     * (mis. uraian kegiatan kebetulan sama persis) — padahal RF-13 mengharuskan tiap
     * kegiatan tetap punya folder sendiri-sendiri. Karena itu identitas foldernya
     * disimpan per baris kegiatan, bukan dicocokkan ulang lewat nama setiap saat.
     */
    public function resolveKegiatanFolder(Kegiatan $kegiatan, string $kategoriFolderId): string
    {
        if ($kegiatan->drive_folder_id) {
            return $kegiatan->drive_folder_id;
        }

        $namaDasar = self::namaOtomatis($kegiatan->nama_folder_auto ?: $kegiatan->uraian_kegiatan);
        $namaSudahAda = $this->drive->namesInFolder($kategoriFolderId);
        $namaUnik = self::namaUnik($namaDasar, $namaSudahAda);

        $folderId = $this->drive->createFolder($namaUnik, $kategoriFolderId);

        $kegiatan->update(['drive_folder_id' => $folderId]);

        return $folderId;
    }

    // ================================================================
    // Titik masuk utama: unggah satu berkas bukti dukung untuk sebuah kegiatan
    // ================================================================

    /**
     * Resolve seluruh hierarki folder (lazy, RF-11 s.d. RF-14), tentukan nama berkas
     * otomatis (RF-17), unggah ke Drive storage aktif, lalu catat pemakaian kuotanya
     * (RF-10c). Ini titik masuk PALING GENERIK — dipakai kategori apa pun (capaian,
     * solusi, evaluasi_rtl, bukti_dukung_sakip), baik yang terikat ke satu kegiatan
     * maupun tidak.
     *
     * @param  string  $kategoriEnum  salah satu dari App\Models\Berkas::kategori.
     * @param  Kegiatan|null  $kegiatan  isi HANYA bila kategorinya "Capaian" (satu-satunya
     *              kategori dengan subfolder_per_kegiatan default, RF-13). Kategori lain
     *              seperti Solusi (RF-27) & Evaluasi-RTL (RF-30) tidak terikat ke satu
     *              kegiatan tertentu, jadi cukup kirim null di sini.
     * @param  string|null  $namaFolderOverride  nama folder Drive tujuan, MENGGANTI hasil
     *              lookup KATEGORI_KE_FOLDER[$kategoriEnum] — dipakai bagian kustom (RF baru)
     *              yang namanya dinamis (mis. "Manajemen Risiko"), bukan salah satu dari 4
     *              kategori baku, tapi berkas.kategori di DB tetap nilai enum tetap ('bagian_kustom').
     * @return array{drive_file_id: string, storage_account_id: ?int, nama_file: string}
     */
    public function unggahBerkas(Periode $periode, MasterIku $iku, string $kategoriEnum, string $localPath, ?Kegiatan $kegiatan = null, string $ekstensi = '.pdf', string $mimeType = 'application/pdf', ?string $namaFolderOverride = null): array
    {
        $namaKategori = $namaFolderOverride
            ?? self::KATEGORI_KE_FOLDER[$kategoriEnum]
            ?? throw new RuntimeException("Kategori berkas tidak dikenal: {$kategoriEnum}");

        $kategoriFolderId = $this->resolveKategoriFolder($periode, $iku, $namaKategori);

        $config = $this->configUntukIku($iku);
        $tujuanFolderId = ($kegiatan && in_array($namaKategori, $config->kategoriDenganSubfolderKegiatan(), true))
            ? $this->resolveKegiatanFolder($kegiatan, $kategoriFolderId)
            : $kategoriFolderId;

        // RF-17: "nama berkas capaian mengikuti nama kegiatan/capaian-nya" — identitas
        // kegiatan sudah terwakili oleh folder di atas (lihat resolveKegiatanFolder),
        // jadi nama BERKAS itu sendiri cukup mengikuti kategorinya, bukan nama asli
        // file dari komputer pengguna. Cocok dengan contoh jalur di SRS: ".../bukti-capaian.pdf".
        $kategoriSlug = Str::slug($namaKategori);
        $namaDasar = str_starts_with($kategoriSlug, 'bukti') ? $kategoriSlug : "bukti-{$kategoriSlug}";

        $namaSudahAda = $this->drive->namesInFolder($tujuanFolderId);
        $namaBerkas = self::namaUnik($namaDasar, $namaSudahAda, $ekstensi);

        $hasil = $this->drive->uploadFile($localPath, $namaBerkas, $tujuanFolderId, $mimeType);

        $akun = StorageAccount::aktif();
        $akun?->tambahKuotaTerpakai($hasil['size_bytes']);

        return [
            'drive_file_id' => $hasil['id'],
            'storage_account_id' => $akun?->id,
            'nama_file' => $namaBerkas,
        ];
    }

    /**
     * Alias nyaman untuk unggahan kategori "Capaian" yang SELALU terikat ke satu
     * kegiatan — dipakai oleh PengisianKegiatan.
     */
    public function unggahBerkasKegiatan(Kegiatan $kegiatan, string $kategoriEnum, string $localPath, string $ekstensi = '.pdf', string $mimeType = 'application/pdf'): array
    {
        return $this->unggahBerkas($kegiatan->periode, $kegiatan->masterIku, $kategoriEnum, $localPath, $kegiatan, $ekstensi, $mimeType);
    }

    // ================================================================
    // RF-44a — arsip notula gabungan (level TRIWULAN, bukan per-IKU)
    // ================================================================

    /**
     * Notula adalah dokumen level TRIWULAN (rekap seluruh IKU), bukan milik satu IKU
     * seperti kategori bukti dukung lain — jadi jalurnya cuma Tahun/Triwulan/Notula,
     * TIDAK lewat level Bulan/IKU/kategori seperti unggahBerkas().
     */
    public function unggahArsipNotula(Periode $periode, string $localPath, string $namaBerkas): array
    {
        $akun = $this->akunAktifAtauGagal();

        $folderTahun = $this->resolveTahunFolder($akun, $periode->tahun);
        $folderTriwulan = $this->drive->findOrCreateFolder(
            'Triwulan '.(['I', 'II', 'III', 'IV'][$periode->triwulan - 1] ?? $periode->triwulan),
            $folderTahun
        );
        $folderNotula = $this->drive->findOrCreateFolder('Notula', $folderTriwulan);

        $namaSudahAda = $this->drive->namesInFolder($folderNotula);
        $namaUnik = self::namaUnik(pathinfo($namaBerkas, PATHINFO_FILENAME), $namaSudahAda, '.'.pathinfo($namaBerkas, PATHINFO_EXTENSION));

        $hasil = $this->drive->uploadFile($localPath, $namaUnik, $folderNotula, 'application/pdf');

        $akun->tambahKuotaTerpakai($hasil['size_bytes']);

        return [
            'drive_file_id' => $hasil['id'],
            'storage_account_id' => $akun->id,
            'nama_file' => $namaUnik,
        ];
    }

    protected function akunAktifAtauGagal(): StorageAccount
    {
        return StorageAccount::aktif()
            ?? throw new RuntimeException('Belum ada storage aktif. Tambahkan & aktifkan akun di menu Akun & Storage terlebih dahulu.');
    }

    /**
     * Pola folder yang berlaku untuk SATU IKU tertentu — pola KHUSUS IKU ini bila ada
     * baris override di iku_folder_config, jatuh ke pola GLOBAL (FolderConfig) bila
     * tidak. Dipakai resolveKategoriFolder() & unggahBerkas() supaya IKU yang diberi
     * pola khusus (mis. tambahan folder Bulan di dalam Capaian) diperlakukan berbeda
     * tanpa mengubah pola IKU lain.
     */
    protected function configUntukIku(MasterIku $iku): FolderConfig|IkuFolderConfig
    {
        return IkuFolderConfig::where('iku_id', $iku->id)->first() ?? FolderConfig::current();
    }

    // ================================================================
    // Alat manual — buat SATU folder tambahan di lokasi bebas (mis. di
    // tengah tahun berjalan), TIDAK terikat pola hierarki/kategori yang
    // dikonfigurasi. Semua level bersifat opsional: isi yang relevan saja.
    // ================================================================

    /**
     * @param  int  $tahun  wajib — folder tahun selalu jadi titik masuk paling atas.
     * @param  int|null  $triwulan  1-4, lewati bila tidak perlu tingkat Triwulan.
     * @param  int|null  $bulan  1-12, lewati bila tidak perlu tingkat Bulan.
     * @param  MasterIku|null  $iku  lewati bila folder baru tidak spesifik ke satu IKU.
     * @param  string|null  $kategoriNama  nama folder kategori tujuan (mis. "Capaian"), lewati bila folder baru langsung di tingkat di atasnya.
     * @param  string  $namaFolderBaru  nama folder yang akan dibuat/ditemukan di lokasi hasil resolve level-level di atas.
     * @return string ID folder baru (atau folder yang sudah ada dengan nama sama di lokasi itu).
     */
    public function buatFolderKustom(
        int $tahun,
        ?int $triwulan,
        ?int $bulan,
        ?MasterIku $iku,
        ?string $kategoriNama,
        string $namaFolderBaru,
    ): string {
        $akun = $this->akunAktifAtauGagal();
        $folderId = $this->resolveTahunFolder($akun, $tahun);

        if ($triwulan) {
            $namaTriwulan = 'Triwulan '.(['I', 'II', 'III', 'IV'][$triwulan - 1] ?? $triwulan);
            $folderId = $this->drive->findOrCreateFolder($namaTriwulan, $folderId);
        }

        if ($bulan) {
            $namaBulan = Carbon::create($tahun, $bulan, 1)->locale('id')->translatedFormat('F');
            $folderId = $this->drive->findOrCreateFolder($namaBulan, $folderId);
        }

        if ($iku) {
            $folderId = $this->drive->findOrCreateFolder($iku->kode, $folderId);
        }

        if ($kategoriNama) {
            $folderId = $this->drive->findOrCreateFolder($kategoriNama, $folderId);
        }

        return $this->drive->findOrCreateFolder($namaFolderBaru, $folderId);
    }
}
