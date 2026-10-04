<?php

namespace App\Models;

use App\Services\FolderStructureService;
use App\Services\GoogleDriveService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Berkas extends Model
{
    use HasFactory;

    protected $table = 'berkas';

    protected $fillable = [
        'ref_id',
        'ref_type',
        'kategori',
        'nama_file',
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
        });
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
