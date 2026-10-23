<?php

namespace App\Models;

use App\Services\FolderStructureService;
use App\Services\GoogleDriveService;
use App\Services\SinkronBuktiRtlService;
use App\Support\JejakAktivitasBagianKustom;
use App\Support\KodeAcak;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class Berkas extends Model
{
    use HasFactory;

    protected $table = 'berkas';

    protected $fillable = [
        'kode',
        'ref_id',
        'ref_type',
        'kategori',
        'nama_file',
        // Subfolder khusus di dalam folder kegiatan (mis. "Undangan"); null = langsung
        // di folder kegiatan. Lihat FolderStructureService::unggahBerkas().
        'subfolder',
        // Tahapan tujuan bukti realisasi RTL yang diunggah mandiri (folder
        // Evaluasi-RTL/<poin>/<tahapan>/<subfolder>); null untuk bukti lain.
        'tahapan',
        // Bulan isian tempat bukti realisasi RTL diunggah mandiri (folder Bulan);
        // null = periode poin RTL (bukti lama). Lihat FolderStructureService::unggahBerkasRtl().
        'periode_id',
        'path',
        // drive_file_id  = ID unik berkas ini di Google Drive.
        // storage_account_id = akun Gmail institusi TEMPAT berkas ini fisik tersimpan.
        // Keduanya WAJIB disimpan bersamaan (bukan cukup salah satu) — inilah RF-10b:
        // bila storage aktif berpindah ke akun lain di kemudian hari, berkas LAMA ini
        // tetap tahu harus dibuka dari akun asalnya (storage_account_id), bukan dari
        // akun yang sedang aktif sekarang. Tautan tidak pernah rusak akibat perpindahan akun.
        'drive_file_id',
        'storage_account_id',
        'status_verifikasi',
        'catatan',
        'diunggah_oleh',
    ];

    /**
     * Catat pengunggah otomatis dari user yang sedang login, supaya setiap titik
     * Berkas::create() (Isian Kegiatan, RTL, Kendala & Solusi, dsb.) tidak perlu
     * mengisinya satu per satu. Proses tanpa login (mis. job/console) dibiarkan null.
     */
    protected static function booted(): void
    {
        static::creating(function (Berkas $berkas) {
            $berkas->diunggah_oleh ??= auth()->id();
            $berkas->kode ??= self::buatKode($berkas->kategori);
        });
    }

    /**
     * Awalan kode per kategori — supaya dari URL saja sudah kelihatan jenis buktinya.
     */
    public const PREFIX_KODE = [
        'capaian' => 'CPN',
        'solusi' => 'SLS',
        'evaluasi_rtl' => 'RTL',
        'bukti_dukung_sakip' => 'SKP',
        'notula' => 'NTL',
        'bagian_kustom' => 'BKS',
    ];

    /**
     * Kode publik berkas, dipakai di URL pratinjau (/berkas/{kode}/buka) GANTI id
     * urut — pola KATEGORI-TAHUNBULAN-ACAK, mis. "CPN-202610-K7QM-2X9A". Id urut
     * gampang ditebak (cukup ganti angka di URL untuk mengintip bukti IKU lain),
     * sedangkan 8 karakter acak (29^8 ≈ 500 miliar kombinasi) tidak.
     */
    public static function buatKode(?string $kategori, ?\DateTimeInterface $waktu = null): string
    {
        $prefix = self::PREFIX_KODE[$kategori] ?? 'BKT';
        $periode = ($waktu ?? now())->format('Ym');

        do {
            $acak = KodeAcak::buat(8);
            $kode = "{$prefix}-{$periode}-".substr($acak, 0, 4).'-'.substr($acak, 4);
        } while (static::where('kode', $kode)->exists());

        return $kode;
    }

    public function getRouteKeyName(): string
    {
        return 'kode';
    }

    public function ref(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'ref_type', 'ref_id');
    }

    public function pengunggah(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diunggah_oleh');
    }

    /**
     * Salinan fisik bukti kegiatan ini di folder Evaluasi-RTL poin RTL yang
     * dilaksanakan kegiatannya (lihat App\Services\SinkronBuktiRtlService).
     */
    public function salinanRtl(): HasMany
    {
        return $this->hasMany(SalinanBuktiRtl::class);
    }

    public function riwayatVerifikasi(): HasMany
    {
        return $this->hasMany(RiwayatVerifikasiBerkas::class)->latest('id');
    }

    /**
     * Tandai berkas ini Sesuai ('terverifikasi') / Tidak Sesuai ('ditolak') dan catat
     * perubahannya ke riwayat_verifikasi_berkas — satu-satunya pintu perubahan status
     * berkas oleh Tim SAKIP, jadi status di Kegiatan & Evaluasi RTL (yang membaca
     * berkas yang sama) selalu bisa ditelusuri asalnya. Tidak mencatat apa pun bila
     * status & catatannya tidak berubah.
     */
    public function catatVerifikasi(string $status, ?string $catatan, ?User $oleh): void
    {
        $statusLama = $this->status_verifikasi;

        if ($statusLama === $status && $this->catatan === $catatan) {
            return;
        }

        $this->update(['status_verifikasi' => $status, 'catatan' => $catatan]);

        RiwayatVerifikasiBerkas::create([
            'berkas_id' => $this->id,
            'berkas_kode' => $this->kode,
            'nama_file' => $this->nama_file,
            'status_lama' => $statusLama,
            'status_baru' => $status,
            'catatan' => $catatan,
            'user_id' => $oleh?->id,
        ]);
    }

    /**
     * Nama berkas boleh diubah oleh Tim SAKIP atau pengunggahnya sendiri. Berkas
     * lama (sebelum kolom diunggah_oleh ada) tidak tahu siapa pengunggahnya, jadi
     * untuk itu dipakai penanggung jawab IKU pemilik berkas sebagai gantinya.
     */
    public function bisaDiubahNamaOleh(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->namaRole() === 'Tim SAKIP') {
            return true;
        }

        // Sudah ditandai "Sesuai" Tim SAKIP → hanya-lihat bagi selain Tim SAKIP.
        if ($this->status_verifikasi === 'terverifikasi') {
            return false;
        }

        if ($this->diunggah_oleh !== null) {
            return (int) $this->diunggah_oleh === (int) $user->id;
        }

        $ikuId = $this->ref?->iku_id ?? null;
        $iku = $ikuId ? MasterIku::find($ikuId) : null;

        return $iku !== null && $iku->semuaPenanggungJawab()->contains('id', $user->id);
    }

    /**
     * Ganti nama berkas ini (di database & di Google Drive bila sudah terunggah).
     * Ekstensi asli selalu dipertahankan — pengguna cukup mengetik nama tanpa
     * ekstensi. Gagal rename di Drive TIDAK membatalkan perubahan di aplikasi;
     * pesan galatnya dikembalikan supaya bisa ditampilkan sebagai peringatan.
     *
     * @return array{nama_file: string, peringatan: ?string}
     */
    public function gantiNama(string $namaBaru): array
    {
        $ekstensi = pathinfo($this->nama_file, PATHINFO_EXTENSION);
        $ekstensi = $ekstensi !== '' ? '.'.$ekstensi : '';

        $namaDasar = $namaBaru;
        if ($ekstensi !== '' && str_ends_with(strtolower($namaDasar), strtolower($ekstensi))) {
            $namaDasar = substr($namaDasar, 0, -strlen($ekstensi));
        }

        $namaDasar = FolderStructureService::namaOtomatis($namaDasar, 150);

        if ($namaDasar === '') {
            throw new \InvalidArgumentException('Nama berkas tidak boleh kosong.');
        }

        $namaFile = $namaDasar.$ekstensi;
        $peringatan = null;

        if ($this->drive_file_id && $namaFile !== $this->nama_file) {
            try {
                app(GoogleDriveService::class)->renameFile($this->drive_file_id, $namaFile);
            } catch (\Throwable $e) {
                $peringatan = 'Nama di aplikasi sudah diubah, tapi gagal mengubah nama di Google Drive: '.$e->getMessage();
            }
        }

        $this->update(['nama_file' => $namaFile]);

        // Salinan di Evaluasi-RTL tetap bernama sama dengan berkas sumbernya.
        if ($this->drive_file_id && $this->ref_type === Kegiatan::class) {
            app(SinkronBuktiRtlService::class)->gantiNamaSalinan($this, $namaFile);
        }

        return ['nama_file' => $namaFile, 'peringatan' => $peringatan];
    }

    /**
     * Berkas yang belum punya salinan di Drive (unggahan pertamanya gagal) tapi salinan
     * lokalnya masih ada — calon sinkronUlangKeDrive(). Disk lokal container terhapus
     * tiap deploy ulang, jadi berkas seperti ini harus segera disusulkan ke Drive
     * sebelum itu terjadi (lihat perintah berkas:sinkron-drive).
     */
    public function scopeBelumDiDrive($query)
    {
        return $query->whereNull('drive_file_id')->whereNotNull('path');
    }

    /**
     * Coba unggah ulang berkas ini ke Drive dari salinan lokalnya — ke folder yang
     * sama dengan unggahan pertamanya (lihat titik unggah di PengisianKegiatan &
     * RtlEvaluasi; berkas notula baru dibuat SETELAH sukses di Drive). Melempar exception bila Drive masih gagal;
     * mengembalikan false bila tidak bisa dicoba (salinan lokal sudah hilang,
     * pemiliknya sudah dihapus, atau kategorinya tidak dikenal).
     */
    public function sinkronUlangKeDrive(): bool
    {
        if ($this->drive_file_id || ! $this->path || ! Storage::disk('local')->exists($this->path)) {
            return false;
        }

        if (! $this->ref || ! in_array($this->ref::class, [Kegiatan::class, RtlEvaluasi::class, BagianKustomPoin::class], true)) {
            return false;
        }

        // Unggahan sebelumnya bisa saja SUDAH masuk Drive tapi gagal tercatat di
        // database (koneksi putus setelah Drive selesai) — pakai berkas itu, jangan
        // unggah salinan kedua.
        // Gagal mencari (mis. Drive sedang bermasalah) tidak menghentikan unggahan —
        // unggahannya sendiri yang akan menentukan berhasil/gagal.
        try {
            $sudahAda = app(GoogleDriveService::class)->cariBerkasBerpenanda(FolderStructureService::PENANDA_KODE, $this->kode);
        } catch (\Throwable) {
            $sudahAda = null;
        }

        if ($sudahAda) {
            $this->catatHasilDrive([
                'drive_file_id' => $sudahAda['id'],
                'storage_account_id' => StorageAccount::aktif()?->id,
                'nama_file' => $sudahAda['name'],
            ], susulan: true);
            $this->sinkronkanSalinanRtl();

            return true;
        }

        $this->unggahKeDrive(susulan: true);

        return true;
    }

    /**
     * Catat hasil Drive di baris ini bersama log "tersalin ke Google Drive" (bukti
     * Bagian Kustom) dalam SATU transaksi — log tidak pernah menyebut tersalin bila
     * pencatatannya batal, dan sebaliknya. Bila transaksi ini gagal, berkas Drive yang
     * sudah terunggah tetap bertanda kode berkas ini, jadi percobaan berikutnya
     * memakainya ulang (sinkronUlangKeDrive()), bukan mengunggah salinan kedua.
     *
     * @param  array{drive_file_id: string, storage_account_id: ?int, nama_file: string}  $hasil
     */
    protected function catatHasilDrive(array $hasil, bool $susulan): void
    {
        DB::transaction(function () use ($hasil, $susulan) {
            $this->update($hasil);
            JejakAktivitasBagianKustom::buktiTersinkron($this, $susulan);
        });
    }

    /**
     * Bukti kegiatan yang tertaut ke poin RTL ikut disalin ke folder Evaluasi-RTL
     * begitu sumbernya ada di Drive. Gagal menyalin tidak menggagalkan apa pun (lihat
     * SinkronBuktiRtlService).
     */
    protected function sinkronkanSalinanRtl(): void
    {
        if ($this->ref_type === Kegiatan::class) {
            app(SinkronBuktiRtlService::class)->sinkronkanBerkas($this);
        }
    }

    /**
     * Unggah salinan lokal berkas ini ke folder Drive pemiliknya (Kegiatan, poin
     * evaluasi RTL, atau poin bagian kustom) memakai nama & subfolder yang tercatat
     * di baris ini, lalu catat hasilnya. Diberi penanda kode berkas di Drive supaya
     * unggahan ulang tidak dobel (lihat sinkronUlangKeDrive()). Melempar exception
     * bila Drive gagal — baris tetap ada dengan drive_file_id kosong.
     *
     * $susulan = unggahan ini percobaan ulang (sinkron susulan / tombol "Coba lagi"),
     * hanya memengaruhi keterangan log.
     *
     * @return array{drive_file_id: string, storage_account_id: ?int, nama_file: string}
     */
    public function unggahKeDrive(bool $susulan = false): array
    {
        $ref = $this->ref ?? throw new \RuntimeException('Data pemilik berkas ini sudah tidak ada.');
        $folderService = app(FolderStructureService::class);
        $localPath = Storage::disk('local')->path($this->path);
        $namaDasar = pathinfo($this->nama_file, PATHINFO_FILENAME);

        $hasil = match (true) {
            $ref instanceof Kegiatan => $folderService->unggahBerkasKegiatan(
                $ref, $this->kategori, $localPath, namaBerkasOverride: $namaDasar, subfolder: $this->subfolder, kodeBerkas: $this->kode,
            ),
            $ref instanceof RtlEvaluasi => $folderService->unggahBerkasRtl($ref, $localPath, namaBerkasOverride: $namaDasar, kodeBerkas: $this->kode, tahapan: $this->tahapan, subfolder: $this->subfolder, periode: $this->periode_id ? Periode::find($this->periode_id) : null),
            $ref instanceof BagianKustomPoin => $folderService->unggahBerkasBagianKustom($ref, $localPath, namaBerkasOverride: $namaDasar, kodeBerkas: $this->kode),
            default => throw new \RuntimeException('Jenis berkas ini tidak bisa diunggah ke Drive dari sini.'),
        };

        $this->catatHasilDrive($hasil, $susulan);
        $this->sinkronkanSalinanRtl();

        return $hasil;
    }

    /**
     * Akun Gmail institusi tempat berkas ini fisik tersimpan di Drive (RF-10b).
     * Dicatat per berkas, BUKAN diambil dari StorageAccount::aktif() saat ini,
     * supaya berkas lama tetap tertaut ke akun asalnya walau storage aktif sudah berganti.
     */
    public function storageAccount(): BelongsTo
    {
        return $this->belongsTo(StorageAccount::class);
    }

    /**
     * Tautan Drive untuk membuka/melihat berkas ini (dipakai Tim SAKIP saat
     * verifikasi, lihat RF-40a). Cukup butuh drive_file_id — satu Service Account
     * yang sama bisa membaca berkas dari akun manapun yang pernah membagikan
     * folder kepadanya, jadi tidak masalah walau storage_account ini sudah
     * bukan storage aktif lagi.
     */
    public function driveLink(): ?string
    {
        if (! $this->drive_file_id) {
            return null;
        }

        return app(GoogleDriveService::class)->getFileLink($this->drive_file_id);
    }

    /**
     * Buang salinan fisik berkas ini SEBELUM barisnya dihapus: salinan Drive lebih
     * dulu — dihapus permanen, atau dipindah ke Sampah Drive bila $keSampah (hapus
     * kegiatan) — baru salinan lokal. Bila Drive GAGAL, exception diteruskan dan
     * TIDAK ada yang dihapus, supaya pemanggil bisa membiarkan berkas tetap ada dan
     * menampilkan kegagalannya ke pengguna.
     *
     * Pemanggil yang perlu menyelipkan langkah database di antara keduanya (lihat
     * PengisianKegiatan::hapusBuktiTersimpan()) memakai buangSalinanRtl() lalu
     * buangSalinanDrive() secara terpisah, dan menghapus salinan lokal sendiri.
     */
    public function buangSalinan(bool $keSampah = false): void
    {
        $this->buangSalinanRtl($keSampah);
        $this->buangSalinanDrive($keSampah);

        if ($this->path) {
            Storage::disk('local')->delete($this->path);
        }
    }

    /**
     * Salinan berkas ini di folder Evaluasi-RTL dibuang (permanen atau ke Sampah) dan
     * status salinannya dicatat — selalu LEBIH DULU dari berkas sumber, karena baris
     * salinan ikut terhapus (FK cascade) begitu baris berkas ini dihapus. Bila gagal,
     * exception diteruskan dan berkas sumber tetap utuh.
     */
    public function buangSalinanRtl(bool $keSampah = false): void
    {
        if ($this->ref_type === Kegiatan::class) {
            app(SinkronBuktiRtlService::class)->buangSalinanBerkas($this, $keSampah);
        }
    }

    /** Salinan Drive berkas ini sendiri: dihapus permanen, atau ke Sampah bila $keSampah. */
    public function buangSalinanDrive(bool $keSampah = false): void
    {
        if ($this->drive_file_id) {
            try {
                $drive = app(GoogleDriveService::class);
                $keSampah
                    ? $drive->trashFile($this->drive_file_id, $this->storageAccount)
                    : $drive->deleteFile($this->drive_file_id, $this->storageAccount);
            } catch (\Throwable $e) {
                Log::warning("Gagal membuang berkas {$this->drive_file_id} dari Google Drive: ".$e->getMessage());

                throw $e;
            }
        }
    }
}
