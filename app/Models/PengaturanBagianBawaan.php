<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Label tampilan bagian bawaan Isian Kegiatan yang diganti Tim SAKIP (lihat
 * App\Support\RegistriBagian). Kolom kosong = label bawaan dari kode.
 *
 * Hanya dibaca layar Isian Kegiatan (menu lompat, judul, petunjuk). Notula, DOCX,
 * nama folder Drive, relasi data, dan alur verifikasi tidak membaca tabel ini.
 */
class PengaturanBagianBawaan extends Model
{
    protected $table = 'pengaturan_bagian_bawaan';

    protected $fillable = ['kode', 'label_menu', 'judul', 'petunjuk', 'masuk_notula', 'judul_notula', 'notula_periode', 'aktif', 'catatan', 'diubah_oleh'];

    protected function casts(): array
    {
        return [
            'masuk_notula' => 'boolean',
            'aktif' => 'boolean',
        ];
    }

    private const KUNCI_CACHE = 'pengaturan-bagian-bawaan';

    public function pengubah(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diubah_oleh');
    }

    /**
     * Semua pengaturan, dikunci kode bagian. Di-cache; dilupakan setiap kali disimpan.
     * Tabel belum ada (migrasi belum jalan) = kosong, jadi layar tetap memakai label
     * bawaan dan tidak error.
     *
     * @return array<string, array{label_menu: ?string, judul: ?string, petunjuk: ?string, masuk_notula: bool, judul_notula: ?string, notula_periode: string, aktif: bool, catatan: ?string}>
     */
    public static function peta(): array
    {
        return Cache::remember(self::KUNCI_CACHE, 3600, function () {
            if (! Schema::hasTable('pengaturan_bagian_bawaan')) {
                return [];
            }

            return static::query()->get()->mapWithKeys(fn (self $p) => [$p->kode => [
                'label_menu' => $p->label_menu,
                'judul' => $p->judul,
                'petunjuk' => $p->petunjuk,
                'masuk_notula' => (bool) $p->masuk_notula,
                'judul_notula' => $p->judul_notula,
                'notula_periode' => (string) ($p->notula_periode ?: 'triwulan'),
                'aktif' => (bool) $p->aktif,
                'catatan' => $p->catatan,
            ]])->all();
        });
    }

    public static function lupakanCache(): void
    {
        Cache::forget(self::KUNCI_CACHE);
    }
}
