<?php

namespace App\Support;

use App\Models\BagianKustomPoin;

/**
 * Alur kerja isian Bagian Kustom di menu SELAIN Isian Kegiatan (Isian Kegiatan selalu
 * mengikuti alur pengajuan & verifikasi Isian Kegiatan sendiri).
 *
 * Dua mode:
 * - langsung   : isian sah begitu disimpan (perilaku lama, bawaan).
 * - verifikasi : draf → diajukan → disetujui, atau dikembalikan untuk diperbaiki lalu
 *                diajukan ulang. Pengaju & pemeriksa diatur per bagian.
 *
 * Status disimpan di kolom lama status_verifikasi (enum tidak diubah) + diajukan_pada:
 *   draf = menunggu tanpa diajukan_pada, diajukan = menunggu dengan diajukan_pada,
 *   disetujui = terverifikasi, dikembalikan = ditolak.
 *
 * Semua transisi diperiksa di server lewat bolehTransisi().
 */
final class AlurBagianKustom
{
    public const MODE_LANGSUNG = 'langsung';

    public const MODE_VERIFIKASI = 'verifikasi';

    public const MODE = [
        self::MODE_LANGSUNG => 'Langsung sah saat disimpan (tanpa pemeriksaan)',
        self::MODE_VERIFIKASI => 'Diajukan lalu diperiksa (setujui / kembalikan)',
    ];

    public const DRAF = 'draf';

    public const DIAJUKAN = 'diajukan';

    public const DISETUJUI = 'disetujui';

    public const DIKEMBALIKAN = 'dikembalikan';

    public const LABEL_STATUS = [
        self::DRAF => 'Draf',
        self::DIAJUKAN => 'Diajukan — menunggu pemeriksaan',
        self::DISETUJUI => 'Disetujui',
        self::DIKEMBALIKAN => 'Dikembalikan untuk diperbaiki',
    ];

    /** Kelas badge tampilan per status (pola badge SIPINTER). */
    public const BADGE_STATUS = [
        self::DRAF => 'b-draft',
        self::DIAJUKAN => 'b-tunggu',
        self::DISETUJUI => 'b-approve',
        self::DIKEMBALIKAN => 'b-tolak',
    ];

    /**
     * Transisi yang sah: aksi => [status asal yang diizinkan, status tujuan].
     * Aksi ajukan & tarik milik peran pengaju; setujui & kembalikan milik pemeriksa.
     */
    public const TRANSISI = [
        'ajukan' => [[self::DRAF, self::DIKEMBALIKAN], self::DIAJUKAN],
        'tarik' => [[self::DIAJUKAN], self::DRAF],
        'setujui' => [[self::DIAJUKAN], self::DISETUJUI],
        'kembalikan' => [[self::DIAJUKAN, self::DISETUJUI], self::DIKEMBALIKAN],
    ];

    public const NOTULA_SEMUA = 'semua';

    public const NOTULA_DISETUJUI = 'disetujui';

    public const NOTULA = [
        self::NOTULA_SEMUA => 'Semua isian yang tersimpan',
        self::NOTULA_DISETUJUI => 'Hanya isian yang sudah disetujui / ditandai Sesuai',
    ];

    /**
     * Pengaturan alur dalam bentuk seragam. NULL = perilaku lama.
     *
     * @param  array<string, mixed>|null  $tersimpan  isi bagian_kustom.alur
     * @param  array<int, string>  $peranPengisi  peran pengisi bagian (pengaju bawaan)
     * @return array{mode: string, pengaju: array<int, string>, pemeriksa: array<int, string>, ubah_setelah_disetujui: bool, notula: string, diatur: bool}
     */
    public static function normalkan(?array $tersimpan, array $peranPengisi): array
    {
        $tersimpan ??= [];
        $mode = array_key_exists((string) ($tersimpan['mode'] ?? ''), self::MODE) ? (string) $tersimpan['mode'] : self::MODE_LANGSUNG;
        $peranDikenal = array_keys(\App\Models\BagianKustom::NAMA_PERAN);

        // Pengaju selalu bagian dari peran pengisi; tanpa pengaturan = semua pengisi.
        $pengaju = array_key_exists('pengaju', $tersimpan)
            ? array_values(array_intersect($peranPengisi, (array) $tersimpan['pengaju']))
            : array_values($peranPengisi);

        $pemeriksa = array_key_exists('pemeriksa', $tersimpan)
            ? array_values(array_intersect($peranDikenal, (array) $tersimpan['pemeriksa']))
            : [\App\Models\BagianKustom::PERAN_TIM_SAKIP];

        return [
            'mode' => $mode,
            'pengaju' => $pengaju,
            'pemeriksa' => $pemeriksa,
            'ubah_setelah_disetujui' => (bool) ($tersimpan['ubah_setelah_disetujui'] ?? false),
            'notula' => array_key_exists((string) ($tersimpan['notula'] ?? ''), self::NOTULA) ? (string) $tersimpan['notula'] : self::NOTULA_SEMUA,
            'diatur' => $tersimpan !== [],
        ];
    }

    public static function pakaiVerifikasi(array $alur): bool
    {
        return $alur['mode'] === self::MODE_VERIFIKASI;
    }

    /** Status alur satu poin, dibaca dari kolom tersimpan (tidak bergantung mode). */
    public static function statusPoin(BagianKustomPoin $poin): string
    {
        return match ($poin->status_verifikasi) {
            'terverifikasi' => self::DISETUJUI,
            'ditolak' => self::DIKEMBALIKAN,
            default => $poin->diajukan_pada ? self::DIAJUKAN : self::DRAF,
        };
    }

    public static function bolehTransisi(string $statusAsal, string $aksi): bool
    {
        return isset(self::TRANSISI[$aksi]) && in_array($statusAsal, self::TRANSISI[$aksi][0], true);
    }

    /**
     * Isi poin boleh diubah/dihapus pengisi pada status ini. Mode langsung: selalu.
     * Mode verifikasi: draf & dikembalikan boleh; diajukan tidak; disetujui hanya bila
     * pengaturan mengizinkan (lalu kembali menjadi draf).
     */
    public static function bisaDiubahPengisi(string $status, array $alur): bool
    {
        if (! self::pakaiVerifikasi($alur)) {
            return true;
        }

        return match ($status) {
            self::DRAF, self::DIKEMBALIKAN => true,
            self::DISETUJUI => $alur['ubah_setelah_disetujui'],
            default => false,
        };
    }

    /** Label status untuk tampilan — mode langsung menyebut isian yang sah "Tersimpan (sah)". */
    public static function labelStatus(string $status, array $alur): string
    {
        if (! self::pakaiVerifikasi($alur) && $status === self::DISETUJUI) {
            return 'Tersimpan (sah)';
        }

        return self::LABEL_STATUS[$status] ?? $status;
    }
}
