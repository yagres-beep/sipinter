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
            // "bulan" SENGAJA tidak lagi jadi tingkat hierarki sendiri — folder Bulan
            // sekarang jadi subfolder DI DALAM kategori tertentu saja (lihat kategori
            // di bawah, subfolder_per_bulan), karena tidak semua kategori butuh
            // dipecah per bulan (mis. Evaluasi-RTL cukup satu folder per triwulan).
            'hierarki' => [
                ['level' => self::LEVEL_TAHUN, 'aktif' => true],
                ['level' => self::LEVEL_TRIWULAN, 'aktif' => true],
                ['level' => self::LEVEL_IKU, 'aktif' => true],
            ],
            'kategori' => [
                ['nama' => 'Capaian', 'wajib' => true, 'subfolder_per_kegiatan' => true, 'subfolder_per_bulan' => true],
                ['nama' => 'Solusi', 'wajib' => false, 'subfolder_per_kegiatan' => false, 'subfolder_per_bulan' => false],
                ['nama' => 'Evaluasi-RTL', 'wajib' => false, 'subfolder_per_kegiatan' => false, 'subfolder_per_bulan' => false],
                ['nama' => 'Bukti-Dukung-SAKIP', 'wajib' => true, 'subfolder_per_kegiatan' => false, 'subfolder_per_bulan' => true],
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
        return static::query()->oldest('id')->first()
            ?? static::query()->firstOrCreate([], [
                'pola_json' => self::polaDefault(),
                'template_notula_penanda' => '',
            ]);
    }

    // hierarkiAktif(), kategoriList(), kategoriDenganSubfolderKegiatan() ada di trait
    // PolaFolderHierarki — dipakai bersama dengan IkuFolderConfig (pola override per-IKU).

    /**
     * Dipanggil BagianKustomManager saat bagian kustom baru dibuat (mis. "Manajemen
     * Risiko") — otomatis menambahkan kategori folder dengan nama yang sama ke pola
     * GLOBAL, supaya bukti dukung bagian itu punya folder kategori yang terlihat &
     * bisa diatur (urutan, subfolder per kegiatan) di menu Struktur Folder, bukan
     * cuma dibuat "diam-diam" di Drive saat unggahan pertama. Tidak menyentuh pola
     * per-IKU (IkuFolderConfig) yang sudah ada — Tim SAKIP menambahkannya manual di
     * situ kalau memang IKU tertentu perlu pola berbeda.
     */
    public static function tambahKategoriDariBagianKustom(string $namaBagian): void
    {
        $config = static::current();
        $pola = $config->pola_json;

        $sudahAda = collect($pola['kategori'] ?? [])
            ->contains(fn ($k) => mb_strtolower($k['nama']) === mb_strtolower($namaBagian));

        if ($sudahAda) {
            return;
        }

        $pola['kategori'][] = ['nama' => $namaBagian, 'wajib' => false, 'subfolder_per_kegiatan' => false];

        $config->update(['pola_json' => $pola]);
    }
}
