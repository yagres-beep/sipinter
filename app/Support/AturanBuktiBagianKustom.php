<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Aturan bukti dukung satu Bagian Kustom: perlu/tidak, jumlah berkas, format, ukuran,
 * dan petunjuk. Dipakai Isian Kegiatan, Isian Bagian Kustom, builder, dan pemeriksa
 * berkas lama — satu sumber aturan, selalu diperiksa di server.
 *
 * Aturan TIDAK pernah menghapus atau mengubah berkas yang sudah tersimpan. Berkas lama
 * yang tidak cocok dengan aturan baru hanya diberi peringatan.
 */
final class AturanBuktiBagianKustom
{
    public const MODE_TIDAK = 'tidak';

    public const MODE_OPSIONAL = 'opsional';

    public const MODE_WAJIB = 'wajib';

    public const MODE = [
        self::MODE_TIDAK => 'Tidak memakai bukti dukung',
        self::MODE_OPSIONAL => 'Opsional',
        self::MODE_WAJIB => 'Wajib per poin yang diisi',
    ];

    public const SAAT_SIMPAN = 'simpan';

    public const SAAT_AJUKAN = 'ajukan';

    public const SAAT_VERIFIKASI = 'verifikasi';

    /**
     * Kapan jumlah minimal bukti diperiksa. "Ajukan" & "verifikasi" hanya berlaku pada
     * bagian beralur verifikasi di menu selain Isian Kegiatan (BagianKustom::aturanBuktiDi()).
     */
    public const SAAT = [
        self::SAAT_SIMPAN => 'Saat disimpan',
        self::SAAT_AJUKAN => 'Sebelum diajukan',
        self::SAAT_VERIFIKASI => 'Sebelum disetujui pemeriksa',
    ];

    /**
     * Format yang didukung penuh (unggah lokal → Google Drive → pratinjau/unduh).
     *
     * @var array<string, array{label: string, ekstensi: array<int, string>, mime: string}>
     */
    public const FORMAT = [
        'pdf' => ['label' => 'PDF', 'ekstensi' => ['pdf'], 'mime' => 'application/pdf'],
        'jpg' => ['label' => 'JPG/JPEG', 'ekstensi' => ['jpg', 'jpeg'], 'mime' => 'image/jpeg'],
        'png' => ['label' => 'PNG', 'ekstensi' => ['png'], 'mime' => 'image/png'],
        'docx' => ['label' => 'Word (DOCX)', 'ekstensi' => ['docx'], 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['label' => 'Excel (XLSX)', 'ekstensi' => ['xlsx'], 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ];

    public const JUMLAH_MAKS = 20;

    public const UKURAN_MAKS_MB = 100;

    /**
     * Aturan dalam bentuk seragam.
     *
     * @param  array<string, mixed>|null  $tersimpan  isi bagian_kustom.aturan_bukti
     * @param  bool  $buktiWajibLama  kolom lama bagian_kustom.bukti_wajib (dipakai bila $tersimpan NULL)
     * @return array{mode: string, min: int, maks: ?int, format: array<int, string>, ukuran_maks_mb: ?int, petunjuk: ?string, diatur: bool}
     */
    public static function normalkan(?array $tersimpan, bool $buktiWajibLama): array
    {
        if ($tersimpan === null) {
            // Perilaku lama: PDF, wajib minimal satu bila bukti_wajib, tanpa batas lain.
            return [
                'mode' => $buktiWajibLama ? self::MODE_WAJIB : self::MODE_OPSIONAL,
                'min' => $buktiWajibLama ? 1 : 0,
                'maks' => null,
                'format' => ['pdf'],
                'ukuran_maks_mb' => null,
                'petunjuk' => null,
                'saat' => self::SAAT_SIMPAN,
                'diatur' => false,
            ];
        }

        $mode = array_key_exists((string) ($tersimpan['mode'] ?? ''), self::MODE) ? (string) $tersimpan['mode'] : self::MODE_OPSIONAL;
        $format = array_values(array_intersect(array_keys(self::FORMAT), (array) ($tersimpan['format'] ?? [])));
        $min = max(0, min(self::JUMLAH_MAKS, (int) ($tersimpan['min'] ?? 0)));
        $maks = filled($tersimpan['maks'] ?? null) ? max(1, min(self::JUMLAH_MAKS, (int) $tersimpan['maks'])) : null;

        if ($mode === self::MODE_WAJIB) {
            $min = max(1, $min);
        }

        if ($maks !== null && $maks < $min) {
            $maks = $min;
        }

        return [
            'mode' => $mode,
            'min' => $mode === self::MODE_TIDAK ? 0 : $min,
            'maks' => $mode === self::MODE_TIDAK ? null : $maks,
            'format' => $format === [] ? ['pdf'] : $format,
            'ukuran_maks_mb' => filled($tersimpan['ukuran_maks_mb'] ?? null) ? max(1, min(self::UKURAN_MAKS_MB, (int) $tersimpan['ukuran_maks_mb'])) : null,
            'petunjuk' => filled($tersimpan['petunjuk'] ?? null) ? trim((string) $tersimpan['petunjuk']) : null,
            'saat' => $mode === self::MODE_WAJIB && array_key_exists((string) ($tersimpan['saat'] ?? ''), self::SAAT) ? (string) $tersimpan['saat'] : self::SAAT_SIMPAN,
            'diatur' => true,
        ];
    }

    /**
     * Aturan untuk pemeriksaan SAAT SIMPAN: bila jumlah minimal baru diperiksa saat
     * diajukan/disetujui, poin boleh disimpan dulu tanpa bukti (format, ukuran, dan
     * jumlah maksimal tetap diperiksa).
     *
     * @param  array<string, mixed>  $aturan
     * @return array<string, mixed>
     */
    public static function untukSimpan(array $aturan): array
    {
        if ($aturan['mode'] === self::MODE_WAJIB && ($aturan['saat'] ?? self::SAAT_SIMPAN) !== self::SAAT_SIMPAN) {
            $aturan['mode'] = self::MODE_OPSIONAL;
            $aturan['min'] = 0;
        }

        return $aturan;
    }

    /**
     * Pesan bila jumlah bukti tersimpan poin belum memenuhi minimal (dipakai saat
     * mengajukan & menyetujui), null bila cukup.
     *
     * @param  array<string, mixed>  $aturan
     */
    public static function galatMinimal(array $aturan, int $jumlah): ?string
    {
        if ($aturan['mode'] !== self::MODE_WAJIB || $jumlah >= $aturan['min']) {
            return null;
        }

        return $aturan['min'] > 1
            ? "bukti dukung minimal {$aturan['min']} berkas (saat ini {$jumlah})"
            : 'bukti dukung belum diunggah';
    }

    /** Bentuk yang disimpan ke kolom JSON (tanpa kunci turunan). */
    public static function untukDisimpan(array $aturan): array
    {
        $n = self::normalkan($aturan, false);
        unset($n['diatur']);

        // Bawaan (saat disimpan) tidak ditulis — bentuk data lama tetap sama.
        if ($n['saat'] === self::SAAT_SIMPAN) {
            unset($n['saat']);
        }

        return $n;
    }

    public static function dipakai(array $aturan): bool
    {
        return $aturan['mode'] !== self::MODE_TIDAK;
    }

    /** @return array<int, string> semua ekstensi yang diizinkan */
    public static function ekstensi(array $aturan): array
    {
        return array_values(array_merge(...array_map(fn ($f) => self::FORMAT[$f]['ekstensi'], $aturan['format'])));
    }

    /** Nilai atribut accept untuk input berkas. */
    public static function accept(array $aturan): string
    {
        return implode(',', array_merge(
            array_map(fn ($f) => self::FORMAT[$f]['mime'], $aturan['format']),
            array_map(fn ($e) => '.'.$e, self::ekstensi($aturan)),
        ));
    }

    public static function labelFormat(array $aturan): string
    {
        return implode(', ', array_map(fn ($f) => self::FORMAT[$f]['label'], $aturan['format']));
    }

    /** Ringkasan satu baris, mis. "Wajib · 1–3 berkas · PDF, PNG · maks 10 MB". */
    public static function ringkasan(array $aturan): string
    {
        if (! self::dipakai($aturan)) {
            return 'Tidak memakai bukti dukung';
        }

        $bagian = [$aturan['mode'] === self::MODE_WAJIB ? 'Wajib' : 'Opsional'];

        if ($aturan['min'] > 1 || $aturan['maks'] !== null) {
            $bagian[] = ($aturan['maks'] !== null ? $aturan['min'].'–'.$aturan['maks'] : 'minimal '.$aturan['min']).' berkas';
        }

        $bagian[] = self::labelFormat($aturan);

        if ($aturan['ukuran_maks_mb'] !== null) {
            $bagian[] = 'maks '.$aturan['ukuran_maks_mb'].' MB per berkas';
        }

        if ($aturan['mode'] === self::MODE_WAJIB && ($aturan['saat'] ?? self::SAAT_SIMPAN) !== self::SAAT_SIMPAN) {
            $bagian[] = 'wajib '.mb_strtolower(self::SAAT[$aturan['saat']]);
        }

        return implode(' · ', $bagian);
    }

    /**
     * Aturan validasi Laravel untuk SATU berkas unggahan.
     *
     * @return array<int, string>
     */
    public static function aturanBerkas(array $aturan): array
    {
        $rules = ['file', 'mimes:'.implode(',', self::ekstensi($aturan))];

        if ($aturan['ukuran_maks_mb'] !== null) {
            $rules[] = 'max:'.($aturan['ukuran_maks_mb'] * 1024);
        }

        return $rules;
    }

    /**
     * Pesan galat satu berkas (format/ukuran), null bila sah. Dipakai komponen yang
     * memeriksa berkas sendiri, tanpa validator Livewire.
     */
    public static function galatBerkas(array $aturan, UploadedFile $berkas): ?string
    {
        $nama = $berkas->getClientOriginalName();
        $ekstensi = strtolower((string) $berkas->getClientOriginalExtension());
        $tebakan = strtolower((string) $berkas->guessExtension());
        $boleh = self::ekstensi($aturan);

        // Ekstensi nama DAN isi berkas harus cocok — mengganti nama .exe menjadi .pdf
        // tidak lolos.
        $setara = fn (string $e) => $e === 'jpeg' ? 'jpg' : $e;

        if (! in_array($ekstensi, $boleh, true) || ! in_array($setara($tebakan), array_map($setara, $boleh), true)) {
            return "Berkas \"{$nama}\" tidak diizinkan. Format yang diterima: ".self::labelFormat($aturan).'.';
        }

        if ($aturan['ukuran_maks_mb'] !== null && $berkas->getSize() > $aturan['ukuran_maks_mb'] * 1024 * 1024) {
            return "Berkas \"{$nama}\" melebihi batas {$aturan['ukuran_maks_mb']} MB.";
        }

        return null;
    }

    /**
     * Pesan galat jumlah berkas satu poin yang diisi, null bila sah.
     *
     * $lama = berkas yang sudah tersimpan, $baru = berkas yang akan ditambahkan. Batas
     * maksimal hanya menghalangi PENAMBAHAN — poin yang berkas lamanya sudah melebihi
     * batas baru (aturan diubah belakangan) tetap bisa disimpan tanpa unggahan baru.
     */
    public static function galatJumlah(array $aturan, int $lama, int $baru): ?string
    {
        if (! self::dipakai($aturan)) {
            return $baru > 0 ? 'Bagian ini tidak memakai bukti dukung.' : null;
        }

        $total = $lama + $baru;

        if ($aturan['mode'] === self::MODE_WAJIB && $total < $aturan['min']) {
            return $aturan['min'] > 1
                ? "Bukti dukung wajib minimal {$aturan['min']} berkas untuk tiap poin yang diisi (saat ini {$total})."
                : 'Bukti dukung wajib diunggah untuk tiap poin yang diisi.';
        }

        if ($aturan['mode'] === self::MODE_OPSIONAL && $total > 0 && $total < $aturan['min']) {
            return "Bila melampirkan bukti, minimal {$aturan['min']} berkas (saat ini {$total}).";
        }

        if ($baru > 0 && $aturan['maks'] !== null && $total > $aturan['maks']) {
            return "Bukti dukung maksimal {$aturan['maks']} berkas per poin (saat ini {$lama} tersimpan + {$baru} baru).";
        }

        return null;
    }

    /** Berkas tersimpan cocok dengan format aturan saat ini (dari nama berkasnya). */
    public static function namaCocok(array $aturan, string $namaFile): bool
    {
        return in_array(strtolower(pathinfo($namaFile, PATHINFO_EXTENSION)), self::ekstensi($aturan), true);
    }

    /** Ekstensi berkas unggahan yang disimpan (huruf kecil, "jpeg" tetap "jpeg"). */
    public static function ekstensiUnggahan(UploadedFile $berkas): string
    {
        $e = strtolower((string) $berkas->getClientOriginalExtension());

        return $e !== '' ? $e : 'pdf';
    }

    /** MIME untuk unggahan Drive dari nama berkas tersimpan. */
    public static function mimeDariNama(string $namaFile): string
    {
        $e = strtolower(pathinfo($namaFile, PATHINFO_EXTENSION));

        foreach (self::FORMAT as $f) {
            if (in_array($e, $f['ekstensi'], true)) {
                return $f['mime'];
            }
        }

        return 'application/pdf';
    }
}
