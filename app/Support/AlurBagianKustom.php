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

    /** Batas jumlah tahap pemeriksaan berjenjang (mis. Tim SAKIP → Kepala). */
    public const TAHAP_MAKS = 3;

    public const LABEL_TAHAP_BAWAAN = 'Pemeriksaan';

    /**
     * Pengaturan alur dalam bentuk seragam. NULL = perilaku lama.
     *
     * Tahapan pemeriksaan (opsional): daftar berurutan [{label, pemeriksa}]. Poin yang
     * diajukan menunggu tahap 1; "Setujui" di tahap t < terakhir memindahkannya ke tahap
     * t+1, di tahap terakhir menjadikannya Disetujui. Konfigurasi lama tanpa 'tahapan' =
     * satu tahap dengan pemeriksa = 'pemeriksa' (perilaku lama, tidak berubah).
     * 'pemeriksa' hasil normalkan = gabungan pemeriksa semua tahap.
     *
     * label_status (opsional): label tampilan per status; identitas status tetap.
     *
     * @param  array<string, mixed>|null  $tersimpan  isi bagian_kustom.alur
     * @param  array<int, string>  $peranPengisi  peran pengisi bagian (pengaju bawaan)
     * @return array{mode: string, pengaju: array<int, string>, pemeriksa: array<int, string>, tahapan: array<int, array{label: string, pemeriksa: array<int, string>}>, label_status: array<string, string>, ubah_setelah_disetujui: bool, notula: string, diatur: bool}
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

        $tahapan = [];

        foreach (array_slice(array_values((array) ($tersimpan['tahapan'] ?? [])), 0, self::TAHAP_MAKS) as $i => $t) {
            if (! is_array($t)) {
                continue;
            }

            $peranTahap = array_values(array_intersect($peranDikenal, (array) ($t['pemeriksa'] ?? [])));

            if ($peranTahap === []) {
                continue;
            }

            $tahapan[] = [
                'label' => filled($t['label'] ?? null) ? mb_substr(trim((string) $t['label']), 0, 60) : self::LABEL_TAHAP_BAWAAN.' '.($i + 1),
                'pemeriksa' => $peranTahap,
            ];
        }

        if ($tahapan === []) {
            $tahapan = [['label' => self::LABEL_TAHAP_BAWAAN, 'pemeriksa' => $pemeriksa]];
        } else {
            $pemeriksa = array_values(array_unique(array_merge(...array_column($tahapan, 'pemeriksa'))));
        }

        $labelStatus = self::LABEL_STATUS;

        foreach ((array) ($tersimpan['label_status'] ?? []) as $status => $label) {
            if (array_key_exists((string) $status, self::LABEL_STATUS) && filled($label)) {
                $labelStatus[$status] = mb_substr(trim((string) $label), 0, 60);
            }
        }

        return [
            'mode' => $mode,
            'pengaju' => $pengaju,
            'pemeriksa' => $pemeriksa,
            'tahapan' => $tahapan,
            'label_status' => $labelStatus,
            'ubah_setelah_disetujui' => (bool) ($tersimpan['ubah_setelah_disetujui'] ?? false),
            'notula' => array_key_exists((string) ($tersimpan['notula'] ?? ''), self::NOTULA) ? (string) $tersimpan['notula'] : self::NOTULA_SEMUA,
            'diatur' => $tersimpan !== [],
        ];
    }

    public static function jumlahTahap(array $alur): int
    {
        return max(1, count($alur['tahapan'] ?? []));
    }

    /** Tahap pemeriksaan yang sedang ditunggu poin yang diajukan (1 = pertama). */
    public static function tahapPoin(BagianKustomPoin $poin, array $alur): int
    {
        return max(1, min(self::jumlahTahap($alur), (int) ($poin->tahap_periksa ?? 1)));
    }

    /**
     * Peran yang boleh memeriksa satu tahap.
     *
     * @return array<int, string>
     */
    public static function pemeriksaTahap(array $alur, int $tahap): array
    {
        return $alur['tahapan'][$tahap - 1]['pemeriksa'] ?? [];
    }

    /**
     * Peran ini boleh melakukan $aksi (setujui/kembalikan) pada poin ini SEKARANG:
     * - poin diajukan: hanya pemeriksa tahap yang sedang ditunggu;
     * - poin disetujui (kembalikan): hanya pemeriksa tahap terakhir.
     */
    public static function bolehPeriksa(array $alur, ?string $peran, BagianKustomPoin $poin, string $aksi): bool
    {
        if ($peran === null || ! self::pakaiVerifikasi($alur)) {
            return false;
        }

        $status = self::statusPoin($poin);

        if (! self::bolehTransisi($status, $aksi)) {
            return false;
        }

        $tahap = $status === self::DISETUJUI ? self::jumlahTahap($alur) : self::tahapPoin($poin, $alur);

        return in_array($peran, self::pemeriksaTahap($alur, $tahap), true);
    }

    /**
     * Status tujuan & tahap berikutnya untuk satu aksi pemeriksa.
     *
     * @return array{status_verifikasi: string, tahap_periksa: ?int, selesai: bool}
     */
    public static function hasilPeriksa(array $alur, BagianKustomPoin $poin, string $aksi): array
    {
        if ($aksi === 'kembalikan') {
            return ['status_verifikasi' => 'ditolak', 'tahap_periksa' => null, 'selesai' => true];
        }

        $tahap = self::tahapPoin($poin, $alur);

        return $tahap < self::jumlahTahap($alur)
            // Masih ada tahap berikutnya: tetap "diajukan", pindah ke tahap selanjutnya.
            ? ['status_verifikasi' => 'menunggu', 'tahap_periksa' => $tahap + 1, 'selesai' => false]
            : ['status_verifikasi' => 'terverifikasi', 'tahap_periksa' => null, 'selesai' => true];
    }

    /** Pengajuan masih bisa ditarik pengaju: belum disetujui di tahap mana pun. */
    public static function bisaDitarik(BagianKustomPoin $poin, array $alur): bool
    {
        return self::statusPoin($poin) === self::DIAJUKAN && self::tahapPoin($poin, $alur) === 1;
    }

    /** Label status satu poin, termasuk tahap yang sedang ditunggu (alur berjenjang). */
    public static function labelPoin(BagianKustomPoin $poin, array $alur): string
    {
        $status = self::statusPoin($poin);
        $label = self::labelStatus($status, $alur);

        if ($status === self::DIAJUKAN && self::jumlahTahap($alur) > 1) {
            $tahap = self::tahapPoin($poin, $alur);
            $label .= ' · '.($alur['tahapan'][$tahap - 1]['label'] ?? self::LABEL_TAHAP_BAWAAN).' ('.$tahap.'/'.self::jumlahTahap($alur).')';
        }

        return $label;
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

        return $alur['label_status'][$status] ?? self::LABEL_STATUS[$status] ?? $status;
    }
}
