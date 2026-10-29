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
 * - persentase: seperti angka, satuan "%".
 * - tautan: URL http(s) apa adanya.
 * - hitung: hasil rumus (angka kanonik), dihitung server saat disimpan — tidak diketik.
 * - tabel: JSON array baris, tiap baris {kunci_subkolom: nilai}, mis.
 *   [{"nama":"Rapat","jumlah":"3"}]. Subkolom diatur per kolom (tabel_kolom).
 *
 * Format tampilan tidak pernah mengubah nilai tersimpan.
 */
final class JenisKolomBagianKustom
{
    public const TIPE = [
        'teks' => 'Teks pendek',
        'teks_panjang' => 'Teks panjang',
        'angka' => 'Angka',
        'persentase' => 'Persentase (%)',
        'tanggal' => 'Tanggal',
        'pilihan' => 'Pilihan tunggal (daftar)',
        'pilihan_ganda' => 'Pilihan ganda (centang)',
        'tautan' => 'Tautan (URL)',
        'hitung' => 'Perhitungan otomatis (rumus)',
        'tabel' => 'Tabel (baris bisa ditambah)',
    ];

    /**
     * Jenis yang bisa dipakai Katalog Variabel (definisi bersama antarbagian). Tabel,
     * rumus, persentase, dan tautan diatur langsung per kolom bagian.
     */
    public const TIPE_KATALOG = ['teks', 'teks_panjang', 'angka', 'tanggal', 'pilihan', 'pilihan_ganda'];

    /** @return array<string, string> */
    public static function tipeKatalog(): array
    {
        return array_intersect_key(self::TIPE, array_flip(self::TIPE_KATALOG));
    }

    /** Jenis subkolom yang bisa dipakai di dalam kolom tabel. */
    public const TIPE_SUBKOLOM = [
        'teks' => 'Teks',
        'angka' => 'Angka',
        'tanggal' => 'Tanggal',
        'pilihan' => 'Pilihan',
    ];

    public const SUBKOLOM_MAKS = 10;

    public const BARIS_TABEL_MAKS = 50;

    /** Jenis bernilai angka: format, batas, ringkasan, dan rumus berlaku. */
    public static function numerik(string $tipe): bool
    {
        return in_array($tipe, ['angka', 'persentase', 'hitung'], true);
    }

    /**
     * Kolom persentase/hitung diperlakukan sebagai kolom angka oleh FormatAngkaKolom.
     *
     * @param  array<string, mixed>  $kolom
     * @return array<string, mixed>
     */
    public static function sebagaiAngka(array $kolom): array
    {
        return self::numerik((string) ($kolom['tipe'] ?? '')) ? ['tipe' => 'angka'] + $kolom : $kolom;
    }

    /**
     * Subkolom tabel dalam bentuk seragam.
     *
     * @param  mixed  $daftar
     * @return array<int, array{kunci: string, label: string, tipe: string, wajib: bool, opsi: array<int, string>}>
     */
    public static function normalkanSubkolom(mixed $daftar): array
    {
        $hasil = [];

        foreach (array_slice(array_values((array) $daftar), 0, self::SUBKOLOM_MAKS) as $s) {
            if (! is_array($s) || ! filled($s['kunci'] ?? null) || ! filled($s['label'] ?? null)) {
                continue;
            }

            $tipe = array_key_exists((string) ($s['tipe'] ?? ''), self::TIPE_SUBKOLOM) ? (string) $s['tipe'] : 'teks';

            $hasil[] = [
                'kunci' => (string) $s['kunci'],
                'label' => trim((string) $s['label']),
                'tipe' => $tipe,
                'wajib' => (bool) ($s['wajib'] ?? false),
                'opsi' => $tipe === 'pilihan' ? array_values(array_filter(array_map(fn ($o) => trim((string) $o), (array) ($s['opsi'] ?? [])), fn ($o) => $o !== '')) : [],
            ];
        }

        return $hasil;
    }

    /**
     * Baris tabel dari nilai tersimpan/formulir (JSON). Bukan JSON daftar = tanpa baris.
     *
     * @return array<int, array<string, string>>
     */
    public static function barisTabel(mixed $nilai): array
    {
        $data = is_array($nilai) ? $nilai : json_decode(trim((string) $nilai), true);

        if (! is_array($data) || ! array_is_list($data)) {
            return [];
        }

        $hasil = [];

        foreach ($data as $baris) {
            if (! is_array($baris)) {
                continue;
            }

            $hasil[] = collect($baris)->filter(fn ($v, $k) => is_string($k) && ! is_array($v))->map(fn ($v) => trim((string) $v))->all();
        }

        return $hasil;
    }

    /** @param  array<string, string>  $baris */
    private static function barisTerisi(array $baris): bool
    {
        return collect($baris)->contains(fn ($v) => trim((string) $v) !== '');
    }

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
    public const PANJANG_MAKS_BAWAAN = ['teks' => 500, 'teks_panjang' => 5000, 'tautan' => 2000];

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

        if ($teks === '' || $teks === '[]') {
            return false;
        }

        // Tabel: JSON daftar baris — terisi bila ada sel yang berisi (baris kosong yang
        // baru ditambahkan di layar belum dihitung sebagai isian).
        if ($teks[0] === '[' && is_array($json = json_decode($teks, true)) && $json !== [] && is_array($json[0] ?? null)) {
            return collect(self::barisTabel($json))->contains(fn ($b) => self::barisTerisi($b));
        }

        return true;
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
            'angka', 'persentase', 'hitung' => FormatAngkaKolom::untukInput($tersimpan, self::sebagaiAngka($kolom)),
            'tabel' => ($baris = self::barisTabel($tersimpan)) === [] ? '' : (string) json_encode($baris, JSON_UNESCAPED_UNICODE),
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

        if ($tipe === 'tabel') {
            return self::tabelUntukDisimpan($nilai, $kolom);
        }

        $teks = is_array($nilai) ? '' : (string) $nilai;

        return match ($tipe) {
            'angka', 'persentase', 'hitung' => FormatAngkaKolom::untukDisimpan($teks, self::sebagaiAngka($kolom)),
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

        if ($tipe === 'tabel') {
            return self::galatTabel($kolom, $nilai);
        }

        if (is_array($nilai)) {
            return "Isian \"{$label}\" tidak sah.";
        }

        $teks = trim((string) $nilai);

        return match ($tipe) {
            'pilihan' => in_array($teks, $opsi, true) ? null : "Pilih salah satu opsi untuk \"{$label}\".",
            'angka', 'persentase' => FormatAngkaKolom::pesanGalat($teks, self::sebagaiAngka($kolom)) ?? self::galatRentangAngka(self::sebagaiAngka($kolom), $teks),
            // Dihitung server dari kolom lain — tidak pernah diketik pengguna.
            'hitung' => null,
            'tanggal' => self::tanggalSah($teks) ? null : "\"{$label}\" harus berupa tanggal yang sah.",
            'tautan' => self::tautanSah($teks) ? self::galatPanjang($kolom, $nilai) : "\"{$label}\" harus berupa tautan lengkap yang diawali http:// atau https://.",
            default => self::galatPanjang($kolom, $nilai),
        };
    }

    public static function tautanSah(string $teks): bool
    {
        return (bool) preg_match('#^https?://[^\s/$.?\#][^\s]*$#i', $teks) && filter_var($teks, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Galat isian tabel: jumlah baris (min/maks) dan isi tiap sel sesuai subkolomnya.
     * Baris yang seluruhnya kosong diabaikan (tidak disimpan).
     *
     * @param  array<string, mixed>  $kolom
     */
    private static function galatTabel(array $kolom, mixed $nilai): ?string
    {
        $label = (string) ($kolom['label'] ?? 'Kolom');
        $subkolom = self::normalkanSubkolom($kolom['tabel_kolom'] ?? []);
        $baris = array_values(array_filter(self::barisTabel($nilai), fn ($b) => self::barisTerisi($b)));
        $min = (int) ($kolom['baris_min'] ?? 0);
        $maks = min(self::BARIS_TABEL_MAKS, (int) ($kolom['baris_maks'] ?? self::BARIS_TABEL_MAKS) ?: self::BARIS_TABEL_MAKS);

        if (count($baris) < $min) {
            return "Tabel \"{$label}\" minimal {$min} baris (saat ini ".count($baris).').';
        }

        if (count($baris) > $maks) {
            return "Tabel \"{$label}\" maksimal {$maks} baris.";
        }

        foreach ($baris as $i => $b) {
            $no = $i + 1;

            foreach ($subkolom as $s) {
                $v = trim((string) ($b[$s['kunci']] ?? ''));

                if ($v === '') {
                    if ($s['wajib']) {
                        return "Tabel \"{$label}\" baris {$no}: \"{$s['label']}\" wajib diisi.";
                    }

                    continue;
                }

                $salah = match ($s['tipe']) {
                    'angka' => FormatAngkaKolom::urai($v, null) === null ? 'harus berupa angka' : null,
                    'tanggal' => self::tanggalSah($v) ? null : 'harus berupa tanggal yang sah',
                    'pilihan' => in_array($v, $s['opsi'], true) ? null : 'harus salah satu pilihan yang tersedia',
                    default => mb_strlen($v) > 1000 ? 'maksimal 1000 karakter' : null,
                };

                if ($salah !== null) {
                    return "Tabel \"{$label}\" baris {$no}: \"{$s['label']}\" {$salah}.";
                }
            }
        }

        return null;
    }

    /**
     * Nilai tabel yang disimpan: hanya subkolom yang dikenal, sel di-trim, angka kanonik,
     * baris kosong dibuang. Tanpa baris berisi = ''.
     *
     * @param  array<string, mixed>  $kolom
     */
    private static function tabelUntukDisimpan(mixed $nilai, array $kolom): string
    {
        $subkolom = self::normalkanSubkolom($kolom['tabel_kolom'] ?? []);
        $hasil = [];

        foreach (self::barisTabel($nilai) as $b) {
            $baris = [];

            foreach ($subkolom as $s) {
                $v = trim(str_replace("\r\n", "\n", (string) ($b[$s['kunci']] ?? '')));

                if ($s['tipe'] === 'angka' && $v !== '') {
                    $v = FormatAngkaKolom::urai($v, null) ?? $v;
                }

                $baris[$s['kunci']] = $v;
            }

            if (self::barisTerisi($baris)) {
                $hasil[] = $baris;
            }
        }

        return $hasil === [] ? '' : (string) json_encode($hasil, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Teks tampilan tabel satu baris per baris data, mis.
     * "1) Kegiatan: Rapat; Jumlah: 3 | 2) Kegiatan: Sosialisasi; Jumlah: 1".
     *
     * @param  array<string, mixed>  $kolom
     */
    public static function tampilTabel(string $nilai, array $kolom): string
    {
        $subkolom = self::normalkanSubkolom($kolom['tabel_kolom'] ?? []);
        $teks = [];

        foreach (array_values(array_filter(self::barisTabel($nilai), fn ($b) => self::barisTerisi($b))) as $i => $b) {
            $sel = [];

            foreach ($subkolom as $s) {
                $v = trim((string) ($b[$s['kunci']] ?? ''));

                if ($v !== '') {
                    $sel[] = $s['label'].': '.($s['tipe'] === 'tanggal' ? self::tampilTanggal($v, 'panjang') : ($s['tipe'] === 'angka' ? str_replace('.', ',', $v) : $v));
                }
            }

            if ($sel !== []) {
                $teks[] = ($i + 1).') '.implode('; ', $sel);
            }
        }

        return implode(' | ', $teks);
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
            'angka', 'persentase', 'hitung' => FormatAngkaKolom::tampil($nilai, self::sebagaiAngka($kolom)),
            'tabel' => self::tampilTabel($nilai, $kolom),
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

        // Pilihan ganda & tabel disimpan sebagai JSON — tidak dibaca sebagai jenis lain.
        if (in_array($tipeLama, ['pilihan_ganda', 'tabel'], true) || $tipeBaru === 'tabel') {
            return false;
        }

        return match ($tipeBaru) {
            'teks', 'teks_panjang' => true,
            'angka', 'persentase', 'hitung' => FormatAngkaKolom::urai($nilai, null) !== null,
            'tautan' => self::tautanSah($nilai),
            'tanggal' => self::tanggalSah($nilai),
            'pilihan' => in_array($nilai, (array) ($kolomBaru['opsi'] ?? []), true),
            'pilihan_ganda' => in_array($nilai, (array) ($kolomBaru['opsi'] ?? []), true),
            default => false,
        };
    }
}
