<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Aturan per jenis kolom Bagian Kustom: bentuk nilai di formulir, cara menyimpan,
 * validasi, dan teks tampilan. Dipakai Isian Kegiatan, Isian Bagian Kustom,
 * Verifikasi, pratinjau, dan notula supaya satu nilai selalu diperlakukan sama.
 *
 * Nilai tersimpan selalu berupa teks di bagian_kustom_poin.nilai_kolom[kunci]:
 * - teks, teks_panjang, pilihan: apa adanya.
 * - angka: lihat FormatAngkaKolom (kanonik bila formatnya diatur).
 * - tanggal: "YYYY-MM-DD".
 * - pilihan_ganda: JSON array, mis. ["Rendah","Tinggi"]. Teks biasa (data lama dari
 *   kolom pilihan tunggal) dibaca sebagai satu pilihan.
 *
 * Format tampilan tidak pernah mengubah nilai tersimpan.
 */
final class JenisKolomBagianKustom
{
    public const TIPE = [
        'teks' => 'Teks pendek',
        'teks_panjang' => 'Teks panjang',
        'angka' => 'Angka',
        'tanggal' => 'Tanggal',
        'pilihan' => 'Pilihan tunggal (daftar)',
        'pilihan_ganda' => 'Pilihan ganda (centang)',
    ];

    public const FORMAT_TANGGAL = [
        'panjang' => '26 Oktober 2026',
        'pendek' => '26/10/2026',
        'iso' => '2026-10-26',
    ];

    private const NAMA_BULAN = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    public static function adaTipe(string $tipe): bool
    {
        return array_key_exists($tipe, self::TIPE);
    }

    public static function teks(string $tipe): bool
    {
        return $tipe === 'teks' || $tipe === 'teks_panjang';
    }

    /** Batas panjang bawaan bila "panjang maksimal" tidak diatur. */
    public const PANJANG_MAKS_BAWAAN = ['teks' => 500, 'teks_panjang' => 5000];

    /**
     * Nilai kosong di formulir. Semua jenis memakai teks di formulir — pilihan ganda
     * juga (JSON yang diatur kotak centang di Blade), supaya logika blok kosong yang
     * membandingkan teks tetap berlaku.
     *
     * @param  array<string, mixed>  $kolom
     */
    public static function kosong(array $kolom): string
    {
        return '';
    }

    /** Nilai formulir sudah diisi ("[]" dari kotak centang kosong = belum). */
    public static function terisi(mixed $nilai): bool
    {
        if (is_array($nilai)) {
            return collect($nilai)->contains(fn ($v) => trim((string) $v) !== '');
        }

        $teks = trim((string) $nilai);

        return $teks !== '' && $teks !== '[]';
    }

    /**
     * Pilihan yang tersimpan pada kolom pilihan ganda.
     *
     * @return array<int, string>
     */
    public static function daftarPilihan(?string $tersimpan): array
    {
        $nilai = trim((string) $tersimpan);

        if ($nilai === '') {
            return [];
        }

        $json = json_decode($nilai, true);

        if (is_array($json)) {
            return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $json), fn ($v) => $v !== ''));
        }

        return [$nilai];
    }

    /**
     * Nilai tersimpan dalam bentuk yang diisikan ke formulir.
     *
     * @param  array<string, mixed>  $kolom
     */
    public static function untukForm(?string $tersimpan, array $kolom): string
    {
        return match ($kolom['tipe'] ?? 'teks') {
            'pilihan_ganda' => ($daftar = self::daftarPilihan($tersimpan)) === [] ? '' : (string) json_encode($daftar, JSON_UNESCAPED_UNICODE),
            'angka' => FormatAngkaKolom::untukInput($tersimpan, $kolom),
            default => (string) $tersimpan,
        };
    }

    /**
     * Nilai yang disimpan dari input formulir yang sudah lolos validasi. Kosong = ''.
     *
     * @param  array<string, mixed>  $kolom
     */
    public static function untukDisimpan(mixed $nilai, array $kolom): string
    {
        $tipe = $kolom['tipe'] ?? 'teks';

        if ($tipe === 'pilihan_ganda') {
            // Urutan mengikuti urutan opsi di pengaturan, tanpa duplikat.
            $dipilih = is_array($nilai) ? array_map(fn ($v) => trim((string) $v), $nilai) : self::daftarPilihan((string) $nilai);
            $urut = array_values(array_filter((array) ($kolom['opsi'] ?? []), fn ($o) => in_array($o, $dipilih, true)));

            return $urut === [] ? '' : (string) json_encode($urut, JSON_UNESCAPED_UNICODE);
        }

        $teks = is_array($nilai) ? '' : (string) $nilai;

        return match ($tipe) {
            'angka' => FormatAngkaKolom::untukDisimpan($teks, $kolom),
            // Baris baru dipertahankan untuk teks panjang; spasi tepi dibuang.
            'teks_panjang' => trim(str_replace("\r\n", "\n", $teks)),
            default => trim($teks),
        };
    }

    /**
     * Pesan galat satu nilai formulir, null bila sah. Pesan khusus dari pengaturan
     * kolom ("pesan") menggantikan pesan bawaan untuk aturan selain wajib.
     *
     * @param  array<string, mixed>  $kolom  hasil BagianKustom::normalkanKolom()
     */
    public static function pesanGalat(array $kolom, mixed $nilai): ?string
    {
        $label = (string) ($kolom['label'] ?? 'Kolom');

        if (! self::terisi($nilai)) {
            return ($kolom['wajib'] ?? false) ? "Kolom \"{$label}\" wajib diisi." : null;
        }

        $galat = self::galatIsi($kolom, $nilai);

        if ($galat === null) {
            return null;
        }

        return filled($kolom['pesan'] ?? null) ? (string) $kolom['pesan'] : $galat;
    }

    /**
     * @param  array<string, mixed>  $kolom
     */
    private static function galatIsi(array $kolom, mixed $nilai): ?string
    {
        $label = (string) ($kolom['label'] ?? 'Kolom');
        $tipe = $kolom['tipe'] ?? 'teks';
        $opsi = (array) ($kolom['opsi'] ?? []);

        if ($tipe === 'pilihan_ganda') {
            $dipilih = is_array($nilai) ? $nilai : self::daftarPilihan((string) $nilai);

            foreach ($dipilih as $v) {
                if (trim((string) $v) !== '' && ! in_array(trim((string) $v), $opsi, true)) {
                    return "Pilihan \"{$v}\" tidak tersedia untuk \"{$label}\".";
                }
            }

            return null;
        }

        if (is_array($nilai)) {
            return "Isian \"{$label}\" tidak sah.";
        }

        $teks = trim((string) $nilai);

        return match ($tipe) {
            'pilihan' => in_array($teks, $opsi, true) ? null : "Pilih salah satu opsi untuk \"{$label}\".",
            'angka' => FormatAngkaKolom::pesanGalat($teks, $kolom) ?? self::galatRentangAngka($kolom, $teks),
            'tanggal' => self::tanggalSah($teks) ? null : "\"{$label}\" harus berupa tanggal yang sah.",
            default => self::galatPanjang($kolom, $nilai),
        };
    }

    /**
     * @param  array<string, mixed>  $kolom
     */
    private static function galatRentangAngka(array $kolom, string $teks): ?string
    {
        $pemisah = FormatAngkaKolom::diatur($kolom) ? FormatAngkaKolom::pemisahDari($kolom) : null;
        $angka = FormatAngkaKolom::urai($teks, $pemisah);
        $label = (string) ($kolom['label'] ?? 'Kolom');

        if ($angka === null) {
            return null;
        }

        $min = $kolom['min'] ?? null;
        $maks = $kolom['maks'] ?? null;

        if ($min !== null && (float) $angka < (float) $min) {
            return "\"{$label}\" minimal {$min}.";
        }

        if ($maks !== null && (float) $angka > (float) $maks) {
            return "\"{$label}\" maksimal {$maks}.";
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $kolom
     */
    private static function galatPanjang(array $kolom, mixed $nilai): ?string
    {
        $panjang = mb_strlen(trim(str_replace("\r\n", "\n", (string) $nilai)));
        $label = (string) ($kolom['label'] ?? 'Kolom');
        $min = $kolom['panjang_min'] ?? null;
        $maks = $kolom['panjang_maks'] ?? self::PANJANG_MAKS_BAWAAN[$kolom['tipe'] ?? 'teks'] ?? null;

        if ($min !== null && $panjang < $min) {
            return "\"{$label}\" minimal {$min} karakter.";
        }

        if ($maks !== null && $panjang > $maks) {
            return "\"{$label}\" maksimal {$maks} karakter.";
        }

        return null;
    }

    public static function tanggalSah(string $teks): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $teks, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Teks tampilan satu nilai tersimpan. Kosong tetap kosong; nilai yang tidak cocok
     * dengan jenis kolomnya (mis. data lama) ditampilkan apa adanya.
     *
     * @param  array<string, mixed>  $kolom
     */
    public static function tampil(?string $tersimpan, array $kolom): string
    {
        $nilai = trim((string) $tersimpan);

        if ($nilai === '') {
            return '';
        }

        return match ($kolom['tipe'] ?? 'teks') {
            'angka' => FormatAngkaKolom::tampil($nilai, $kolom),
            'pilihan_ganda' => implode(', ', self::daftarPilihan($nilai)),
            'tanggal' => self::tampilTanggal($nilai, (string) ($kolom['format_tanggal'] ?? 'panjang')),
            default => $nilai,
        };
    }

    public static function tampilTanggal(string $nilai, string $format): string
    {
        if (! self::tanggalSah($nilai)) {
            return $nilai;
        }

        $t = Carbon::createFromFormat('Y-m-d', $nilai);

        return match ($format) {
            'pendek' => $t->format('d/m/Y'),
            'iso' => $nilai,
            default => $t->day.' '.self::NAMA_BULAN[$t->month].' '.$t->year,
        };
    }

    /**
     * Nilai tersimpan tetap bermakna bila jenis kolom diganti ke $tipeBaru.
     *
     * @param  array<string, mixed>  $kolomBaru
     */
    public static function cocokDenganTipe(string $tersimpan, string $tipeLama, array $kolomBaru): bool
    {
        $nilai = trim($tersimpan);
        $tipeBaru = $kolomBaru['tipe'] ?? 'teks';

        if ($nilai === '' || $tipeLama === $tipeBaru) {
            return true;
        }

        // Pilihan ganda disimpan sebagai JSON — tidak dibaca sebagai teks/angka/tanggal.
        if ($tipeLama === 'pilihan_ganda') {
            return false;
        }

        return match ($tipeBaru) {
            'teks', 'teks_panjang' => true,
            'angka' => FormatAngkaKolom::urai($nilai, null) !== null,
            'tanggal' => self::tanggalSah($nilai),
            'pilihan' => in_array($nilai, (array) ($kolomBaru['opsi'] ?? []), true),
            'pilihan_ganda' => in_array($nilai, (array) ($kolomBaru['opsi'] ?? []), true),
            default => false,
        };
    }
}
