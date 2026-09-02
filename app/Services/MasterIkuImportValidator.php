<?php

namespace App\Services;

use App\Models\MasterIku;
use Illuminate\Support\Collection;

/**
 * Validator murni untuk import Master IKU dari Excel (spek bagian 6.2) — TANPA
 * ketergantungan library Excel maupun DB write. Menerima baris mentah (array kolom
 * A-V, 0-based — lihat App\Exports\MasterIkuTemplateSheet::headings() untuk urutan
 * pastinya) & mengembalikan hasil terstruktur per baris: valid + data siap simpan
 * (dipecah master_iku/capaian_tahunan, lihat validasiSatuBaris()), ATAU daftar
 * pesan error.
 *
 * App\Imports\MasterIkuImport hanya membaca sheet & memanggil ini (tidak menyimpan
 * apa pun sendiri); App\Livewire\MasterIku memanggil alur itu untuk PRATINJAU
 * sebelum benar-benar commit ke DB (lihat pratinjauExcel()/konfirmasiImpor()).
 *
 * Catatan desain penting (Tipe A "%"): kolom "Alokasi Target TW I-IV" pada spek
 * import ini adalah KONTRIBUSI MENTAH tiap triwulan (dijumlahkan wajib = Target X,
 * lihat aturan bersyarat di bawah) — beda dari App\Models\CapaianTahunan::
 * alokasiKumulatif() yang SEKARANG membaca x_alokasi_tw{n}/y_alokasi_tw{n} sebagai
 * angka KUMULATIF langsung (TIDAK dijumlahkan lagi, lihat docblock kelas itu).
 * Validator ini yang menjumlahkan running-sum-nya SEKALI SAAT IMPOR (lihat
 * hitungKumulatifBerjalan() di bawah) sebelum ditulis ke x_alokasi_tw1..4, supaya
 * hasilnya tetap sama seperti sebelumnya tanpa mengubah rumus CapaianTahunan.
 * Y (penyebut) spek section 0 KONSTAN untuk satu tahun (diisi sekali) — ditaruh
 * SAMA di y_alokasi_tw1..4 (bukan lagi Y konstan di TW I & 0 di TW II-IV), PERSIS
 * meniru "Y konstan" spek lewat pembacaan langsung (tanpa jumlah) yang sekarang
 * dipakai alokasiKumulatif().
 */
class MasterIkuImportValidator
{
    public const TOLERANSI = 0.01;

    /** @var array<string,string> */
    private const OPSI_JENIS_PERIODE = [
        'triwulanan' => MasterIku::JENIS_PERIODE_TRIWULANAN,
        'tahunan' => MasterIku::JENIS_PERIODE_TAHUNAN,
    ];

    /** @var array<string,string> */
    private const OPSI_JENIS_NILAI = [
        '%' => MasterIku::METODE_RASIO,
        'non %' => MasterIku::METODE_LANGSUNG,
    ];

    /**
     * @param  list<array<int, mixed>>  $rows  baris mentah kolom A-S (index 0-18)
     * @param  int  $nomorBarisAwal  nomor baris Excel (1-based) untuk elemen pertama $rows
     * @param  bool  $modeUpsert  true: kodeIndikator yang sudah ada di DB boleh diperbarui. false (insert-only): ditolak.
     * @param  iterable<string>  $kodeSudahAdaDiDb  kode indikator yang SUDAH ada di database (mentah, akan dinormalisasi)
     * @return list<array{baris: int, valid: bool, data: array{kode: string, master_iku: array, capaian_tahunan: array}|null, errors: list<string>}>
     */
    public static function validasiSemua(array $rows, int $nomorBarisAwal, bool $modeUpsert, iterable $kodeSudahAdaDiDb): array
    {
        $kodeDiDb = collect($kodeSudahAdaDiDb)->map(fn ($k) => self::normalisasiKode((string) $k))->flip();

        $hasil = [];
        $kodeTerlihat = []; // kode ditemui dalam FILE ini sendiri (bukan DB) => nomor baris pertamanya, untuk pesan duplikat.

        foreach ($rows as $i => $row) {
            $nomorBaris = $nomorBarisAwal + $i;

            [$data, $errors] = self::validasiSatuBaris($row, $nomorBaris, $modeUpsert, $kodeDiDb, $kodeTerlihat);

            if ($errors === ['__kosong__']) {
                // Baris kosong total (sisa baris Excel di akhir) — dilewati diam-diam,
                // bukan dilaporkan sebagai baris error.
                continue;
            }

            $hasil[] = [
                'baris' => $nomorBaris,
                'valid' => $errors === [],
                'data' => $errors === [] ? $data : null,
                'errors' => $errors,
            ];
        }

        return $hasil;
    }

    /**
     * Kode dinormalisasi ke angka/kodenya saja (tanpa awalan huruf, mis. "IKU-1131"
     * -> "1131") — sama seperti App\Livewire\MasterIku::save() supaya kode yang
     * diimpor lewat Excel konsisten dengan yang ditambah/diubah manual lewat form.
     */
    public static function normalisasiKode(string $kode): string
    {
        $kode = trim($kode);

        return preg_replace('/^\D+/', '', $kode) ?: $kode;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  Collection<string, int>  $kodeDiDb
     * @param  array<string, int>  $kodeTerlihat  dimodifikasi lewat referensi — akumulasi kode => baris pertama ditemui dalam file ini
     * @return array{0: array{kode: string, master_iku: array, capaian_tahunan: array}|null, 1: list<string>}
     */
    protected static function validasiSatuBaris(array $row, int $nomorBaris, bool $modeUpsert, Collection $kodeDiDb, array &$kodeTerlihat): array
    {
        $ambil = fn (int $kolom): string => trim((string) ($row[$kolom] ?? ''));

        $namaSasaran = $ambil(1);
        $kodeIndikatorMentah = $ambil(2);
        $timMentah = $ambil(3);
        $namaIndikator = $ambil(4);
        $dasarHitung = $ambil(5);
        $basisData = $ambil(6);
        $jenisPeriodeRaw = $ambil(7);
        $jenisNilaiRaw = $ambil(8);
        $satuan = $ambil(9);
        $targetTahunanRaw = $ambil(10);
        $deskripsiX = $ambil(11);
        $targetXRaw = $ambil(12);
        $deskripsiY = $ambil(13);
        $targetYRaw = $ambil(14);
        $alokasiRaw = [$ambil(15), $ambil(16), $ambil(17), $ambil(18)];

        if ($kodeIndikatorMentah === '' && $namaIndikator === ''
            && $namaSasaran === '' && implode('', $alokasiRaw) === '') {
            return [null, ['__kosong__']];
        }

        $errors = [];

        // --- 1. Wajib isi -----------------------------------------------------
        foreach ([
            'Nama Sasaran' => $namaSasaran,
            'Kode Indikator' => $kodeIndikatorMentah,
            'Penanggung Jawab (Tim)' => $timMentah,
            'Indikator Kinerja' => $namaIndikator,
            'Jenis Periode' => $jenisPeriodeRaw,
            'Jenis Nilai' => $jenisNilaiRaw, 'Satuan' => $satuan, 'Target Tahunan' => $targetTahunanRaw,
        ] as $label => $nilai) {
            if ($nilai === '') {
                $errors[] = "Baris {$nomorBaris}: kolom {$label} wajib diisi.";
            }
        }

        // --- 2. Dropdown (case-insensitive, trim) ------------------------------
        $jenisPeriode = self::OPSI_JENIS_PERIODE[strtolower($jenisPeriodeRaw)] ?? null;
        if ($jenisPeriodeRaw !== '' && $jenisPeriode === null) {
            $errors[] = "Baris {$nomorBaris}: kolom Jenis Periode harus \"Triwulanan\" atau \"Tahunan\".";
        }

        $metodeCapaian = self::OPSI_JENIS_NILAI[strtolower($jenisNilaiRaw)] ?? null;
        if ($jenisNilaiRaw !== '' && $metodeCapaian === null) {
            $errors[] = "Baris {$nomorBaris}: kolom Jenis Nilai harus \"%\" atau \"Non %\".";
        }
        $tipePersen = $metodeCapaian === MasterIku::METODE_RASIO;

        // --- angka --------------------------------------------------------------
        $angka = function (string $label, string $raw) use (&$errors, $nomorBaris): ?float {
            if ($raw === '') {
                return null;
            }

            $normalised = str_replace(',', '.', $raw);

            if (! is_numeric($normalised)) {
                $errors[] = "Baris {$nomorBaris}: kolom {$label} harus berupa angka.";

                return null;
            }

            $nilai = (float) $normalised;

            if ($nilai < 0) {
                $errors[] = "Baris {$nomorBaris}: kolom {$label} tidak boleh negatif.";

                return null;
            }

            return $nilai;
        };

        $targetTahunan = $angka('Target Tahunan', $targetTahunanRaw);
        $targetX = $angka('Target X (Pembilang)', $targetXRaw);
        $penyebutY = $angka('Target Y (Penyebut)', $targetYRaw);

        $labelTw = ['Alokasi Target TW I', 'Alokasi Target TW II', 'Alokasi Target TW III', 'Alokasi Target TW IV'];
        $alokasi = [];
        foreach ($alokasiRaw as $idx => $raw) {
            // 6. Sel kosong pada alokasi dianggap 0.
            $alokasi[$idx + 1] = $raw === '' ? 0.0 : ($angka($labelTw[$idx], $raw) ?? 0.0);
        }

        // --- 4/5. Aturan bersyarat Tipe A ("%") vs Tipe B ("Non %") --------------
        if ($jenisNilaiRaw !== '' && $metodeCapaian !== null) {
            if ($tipePersen) {
                foreach ([
                    'Deskripsi X (Pembilang)' => $deskripsiX, 'Target X (Pembilang)' => $targetXRaw,
                    'Deskripsi Y (Penyebut)' => $deskripsiY, 'Target Y (Penyebut)' => $targetYRaw,
                ] as $label => $nilai) {
                    if ($nilai === '') {
                        $errors[] = "Baris {$nomorBaris}: kolom {$label} wajib diisi untuk Jenis Nilai \"%\".";
                    }
                }

                if ($targetX !== null && $targetX <= 0) {
                    $errors[] = "Baris {$nomorBaris}: kolom Target X (Pembilang) harus lebih besar dari 0 untuk Jenis Nilai \"%\".";
                }
                if ($penyebutY !== null && $penyebutY <= 0) {
                    $errors[] = "Baris {$nomorBaris}: kolom Target Y (Penyebut) harus lebih besar dari 0 untuk Jenis Nilai \"%\".";
                }

                if ($targetX !== null) {
                    $jumlahAlokasi = array_sum($alokasi);
                    if (abs($jumlahAlokasi - $targetX) > self::TOLERANSI) {
                        $errors[] = "Baris {$nomorBaris}: jumlah Alokasi Target TW I-IV ({$jumlahAlokasi}) harus sama dengan Target X (Pembilang) ({$targetX}).";
                    }
                }

                if ($targetX !== null && $targetX > 0 && $penyebutY !== null && $penyebutY > 0 && $targetTahunan !== null) {
                    $targetHitung = round($targetX / $penyebutY * 100, 2);
                    if (abs($targetHitung - $targetTahunan) > self::TOLERANSI) {
                        $errors[] = "Baris {$nomorBaris}: kolom Target Tahunan ({$targetTahunan}%) harus sama dengan Target X ÷ Target Y × 100 ({$targetHitung}%).";
                    }
                }
            } else {
                foreach ([
                    'Deskripsi X (Pembilang)' => $deskripsiX, 'Target X (Pembilang)' => $targetXRaw,
                    'Deskripsi Y (Penyebut)' => $deskripsiY, 'Target Y (Penyebut)' => $targetYRaw,
                ] as $label => $nilai) {
                    if ($nilai !== '') {
                        $errors[] = "Baris {$nomorBaris}: kolom {$label} harus dikosongkan untuk Jenis Nilai \"Non %\".";
                    }
                }

                if ($targetTahunan !== null) {
                    $jumlahAlokasi = array_sum($alokasi);
                    if (abs($jumlahAlokasi - $targetTahunan) > self::TOLERANSI) {
                        $errors[] = "Baris {$nomorBaris}: jumlah Alokasi Target TW I-IV ({$jumlahAlokasi}) harus sama dengan Target Tahunan ({$targetTahunan}).";
                    }
                }
            }
        }

        // --- 3. Kode indikator unik dalam file & (opsional) belum ada di DB -----
        $kodeIndikator = $kodeIndikatorMentah !== '' ? self::normalisasiKode($kodeIndikatorMentah) : null;

        if ($kodeIndikator !== null) {
            if (isset($kodeTerlihat[$kodeIndikator])) {
                $errors[] = "Baris {$nomorBaris}: Kode Indikator \"{$kodeIndikator}\" duplikat di dalam file ini (baris {$kodeTerlihat[$kodeIndikator]}).";
            } else {
                $kodeTerlihat[$kodeIndikator] = $nomorBaris;
            }

            if (! $modeUpsert && $kodeDiDb->has($kodeIndikator)) {
                $errors[] = "Baris {$nomorBaris}: Kode Indikator \"{$kodeIndikator}\" sudah ada di database — pilih mode Upsert untuk memperbaruinya.";
            }
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        $data = [
            'kode' => $kodeIndikator,
            'master_iku' => [
                'kode' => $kodeIndikator,
                'tim' => $timMentah,
                'sasaran' => $namaSasaran,
                'indikator' => $namaIndikator,
                'dasar_hitung' => $dasarHitung ?: null,
                'basis_data' => $basisData ?: null,
                'jenis_periode' => $jenisPeriode,
                'satuan' => $satuan,
                'metode_capaian' => $metodeCapaian,
                'deskripsi_x' => $tipePersen ? $deskripsiX : null,
                'deskripsi_y' => $tipePersen ? $deskripsiY : null,
            ],
            'capaian_tahunan' => $tipePersen ? [
                'target_tahunan' => null,
                'x_target' => $targetX,
                'y_target' => $penyebutY,
                // x_alokasi_tw{n} ditulis KUMULATIF (running sum dari kontribusi mentah
                // $alokasi di atas, lihat docblock kelas) -- alokasiKumulatif() membacanya
                // apa adanya, tidak menjumlah lagi. y_alokasi_tw{n} ditulis SAMA di
                // keempat TW (Y konstan) -- lihat docblock kelas.
                ...self::alokasiKumulatifDariKontribusi($alokasi),
                'y_alokasi_tw1' => $penyebutY, 'y_alokasi_tw2' => $penyebutY,
                'y_alokasi_tw3' => $penyebutY, 'y_alokasi_tw4' => $penyebutY,
            ] : [
                'target_tahunan' => $targetTahunan,
                'x_target' => null,
                'y_target' => null,
                'alokasi_tw1' => $alokasi[1], 'alokasi_tw2' => $alokasi[2],
                'alokasi_tw3' => $alokasi[3], 'alokasi_tw4' => $alokasi[4],
            ],
        ];

        return [$data, []];
    }

    /**
     * Ubah kontribusi mentah TW I-IV (dari kolom "Alokasi Target TW I-IV" spek
     * import, $alokasi[1..4]) jadi angka KUMULATIF running-sum -- sesuai format yang
     * sekarang dibaca APA ADANYA oleh App\Models\CapaianTahunan::alokasiKumulatif()
     * (lihat docblock kelas ini). Jumlah akhirnya (TW IV) SELALU sama dengan Target
     * X (sudah divalidasi di atas), jadi transformasi ini tidak mengubah makna data,
     * cuma bentuk penyimpanannya.
     *
     * @param  array<int, float>  $alokasi  1-based, TW I-IV
     * @return array{x_alokasi_tw1: float, x_alokasi_tw2: float, x_alokasi_tw3: float, x_alokasi_tw4: float}
     */
    private static function alokasiKumulatifDariKontribusi(array $alokasi): array
    {
        $berjalan = 0.0;
        $kumulatif = [];

        foreach ([1, 2, 3, 4] as $tw) {
            $berjalan += $alokasi[$tw];
            $kumulatif["x_alokasi_tw{$tw}"] = round($berjalan, 2);
        }

        return $kumulatif;
    }
}
