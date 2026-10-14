<?php

namespace App\Models;

use App\Services\FolderStructureService;
use App\Services\GoogleDriveService;
use App\Support\KodeAcak;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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

        $ref = $this->ref;
        if (! $ref) {
            return false;
        }

        $folderService = app(FolderStructureService::class);
        $localPath = Storage::disk('local')->path($this->path);
        $namaDasar = pathinfo($this->nama_file, PATHINFO_FILENAME);

        $hasil = match (true) {
            $ref instanceof Kegiatan => $folderService->unggahBerkasKegiatan(
                $ref, $this->kategori, $localPath, namaBerkasOverride: $namaDasar, subfolder: $this->subfolder,
            ),
            $ref instanceof RtlEvaluasi => $folderService->unggahBerkas(
                $ref->periode, $ref->masterIku, 'evaluasi_rtl', $localPath, namaBerkasOverride: $namaDasar,
            ),
            $ref instanceof BagianKustomPoin => $folderService->unggahBerkas(
                $ref->periode, $ref->masterIku, 'bagian_kustom', $localPath,
                namaFolderOverride: $ref->bagianKustom->nama,
                namaBerkasOverride: $namaDasar,
            ),
            default => null,
        };

        if ($hasil === null) {
            return false;
        }

        $this->update($hasil);

        return true;
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
}
