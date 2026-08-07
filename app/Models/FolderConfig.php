<?php

namespace App\Models;

use App\Models\Concerns\PolaFolderHierarki;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris SINGLETON (hanya boleh ada 1) berisi pola struktur folder Drive
 * yang dapat dikonfigurasi Tim SAKIP tanpa mengubah kode (RF-14, RF-15).
 *
 * pola_json berbentuk:
 * {
 *   "hierarki": [
 *     {"level": "tahun", "aktif": true},
 *     {"level": "triwulan", "aktif": true},
 *     {"level": "bulan", "aktif": true},
 *     {"level": "iku", "aktif": true}
 *   ],
 *   "kategori": [
 *     {"nama": "Capaian", "wajib": true, "subfolder_per_kegiatan": true},
 *     {"nama": "Solusi", "wajib": false, "subfolder_per_kegiatan": false},
 *     {"nama": "Evaluasi-RTL", "wajib": false, "subfolder_per_kegiatan": false},
 *     {"nama": "Bukti-Dukung-SAKIP", "wajib": true, "subfolder_per_kegiatan": false}
 *   ]
 * }
 *
 * - "hierarki" = urutan tingkatan folder SEBELUM masuk ke folder kategori (RF-11).
 *   "tahun" SENGAJA selalu dipaksa aktif & di posisi pertama (lihat hierarkiAktif())
 *   karena RF-16 ("buat folder tahun lebih awal") mengasumsikan tahun sebagai
 *   pintu masuk paling atas. Triwulan/bulan/IKU boleh diaktif/nonaktifkan atau
 *   diurutkan ulang oleh Tim SAKIP lewat RF-15.
 * - "kategori" = folder setingkat di dalam folder IKU (RF-12). "wajib" = true
 *   berarti kategori tidak boleh dihapus/dinonaktifkan Tim SAKIP (Capaian &
 *   Bukti-Dukung-SAKIP). "subfolder_per_kegiatan" = true berarti di DALAM
 *   kategori ini dibuatkan subfolder per kegiatan (RF-13) — bawaannya hanya
 *   Capaian, karena itulah yang diminta RF-13 secara eksplisit.
 */
class FolderConfig extends Model
{
    use HasFactory, PolaFolderHierarki;

    protected $table = 'folder_config';

    public const LEVEL_TAHUN = 'tahun';

    public const LEVEL_TRIWULAN = 'triwulan';

    public const LEVEL_BULAN = 'bulan';

    public const LEVEL_IKU = 'iku';

    /**
     * Kategori yang WAJIB ada dan tidak boleh dihapus Tim SAKIP (RF-12).
     */
    public const KATEGORI_WAJIB = ['Capaian', 'Bukti-Dukung-SAKIP'];

    protected $fillable = [
        'pola_json',
        'template_notula_penanda',
        'template_notula_path',
        'template_notula_nama_asli',
    ];

    protected function casts(): array
    {
        return [
            'pola_json' => 'array',
        ];
    }

    public static function polaDefault(): array
    {
        return [
            'hierarki' => [
                ['level' => self::LEVEL_TAHUN, 'aktif' => true],
                ['level' => self::LEVEL_TRIWULAN, 'aktif' => true],
                ['level' => self::LEVEL_BULAN, 'aktif' => true],
                ['level' => self::LEVEL_IKU, 'aktif' => true],
            ],
            'kategori' => [
                ['nama' => 'Capaian', 'wajib' => true, 'subfolder_per_kegiatan' => true],
                ['nama' => 'Solusi', 'wajib' => false, 'subfolder_per_kegiatan' => false],
                ['nama' => 'Evaluasi-RTL', 'wajib' => false, 'subfolder_per_kegiatan' => false],
                ['nama' => 'Bukti-Dukung-SAKIP', 'wajib' => true, 'subfolder_per_kegiatan' => false],
            ],
        ];
    }

    /**
     * Konfigurasi bersifat singleton — seluruh aplikasi memakai SATU pola yang sama.
     * Baris default dibuat otomatis kalau belum ada (mis. sebelum FolderConfigSeeder
     * sempat dijalankan), supaya pemanggil tidak perlu peduli soal seeding.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'pola_json' => self::polaDefault(),
            'template_notula_penanda' => '',
        ]);
    }

    // hierarkiAktif(), kategoriList(), kategoriDenganSubfolderKegiatan() ada di trait
    // PolaFolderHierarki — dipakai bersama dengan IkuFolderConfig (pola override per-IKU).
}
