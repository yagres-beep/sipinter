<?php

namespace App\Services;

use App\Models\MasterIku;
use Illuminate\Support\Collection;

/**
 * Validator murni untuk import Master IKU dari Excel (spek bagian 6.2) — TANPA
 * ketergantungan library Excel maupun DB write. Menerima baris mentah (array kolom
 * A-S, 0-based — lihat App\Exports\MasterIkuTemplateSheet::headings() untuk urutan
 * pastinya) & mengembalikan hasil terstruktur per baris: valid + data siap simpan
 * (dipecah master_iku/capaian_tahunan, lihat validasiSatuBaris()), ATAU daftar
 * pesan error.
 *
 * App\Imports\MasterIkuImport hanya membaca sheet & memanggil ini (tidak menyimpan
 * apa pun sendiri); App\Livewire\MasterIku memanggil alur itu untuk PRATINJAU
 * sebelum benar-benar commit ke DB (lihat pratinjauExcel()/konfirmasiImpor()).
 *
 * Catatan desain penting (Tipe A "%"): spek section 0 mendefinisikan Y (penyebut)
 * sebagai KONSTAN untuk satu tahun (diisi sekali), sedangkan skema CapaianTahunan
 * yang SUDAH ADA & sudah dites (lihat CapaianTahunanTest) menyimpan y_alokasi_tw1..4
 * PER TRIWULAN lalu MENJUMLAHKANNYA (rasioKumulatif()). Supaya kumulatif tetap benar
 * (X kumulatif ÷ Y konstan) TANPA mengubah rumus CapaianTahunan yang sudah dites,
 * validator ini menaruh SELURUH nilai Y konstan di y_alokasi_tw1 (TW I) dan 0 di
 * TW II-IV — sehingga sum(y_alokasi_tw1..N) = Y konstan untuk N triwulan mana pun,
 * persis meniru "Y konstan" spek lewat mekanisme kumulatif yang sudah ada.
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
        $namaIndikator = $ambil(3);
        $jenisPeriodeRaw = $ambil(4);
        $jenisNilaiRaw = $ambil(5);
        $satuan = $ambil(6);
        $targetTahunanRaw = $ambil(7);
        $deskripsiX = $ambil(8);
        $targetXRaw = $ambil(9);
        $deskripsiY = $ambil(10);
        $targetYRaw = $ambil(11);
        $alokasiRaw = [$ambil(12), $ambil(13), $ambil(14), $ambil(15)];

        if ($kodeIndikatorMentah === '' && $namaIndikator === ''
            && $namaSasaran === '' && implode('', $alokasiRaw) === '') {
            return [null, ['__kosong__']];
        }

        $errors = [];

        // --- 1. Wajib isi -----------------------------------------------------
        foreach ([
            'Nama Sasaran' => $namaSasaran,
            'Kode Indikator' => $kodeIndikatorMentah, 'Indikator Kinerja' => $namaIndikator,
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
                'sasaran' => $namaSasaran,
                'indikator' => $namaIndikator,
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
                // Lihat docblock kelas: Y konstan (spek) ditaruh seluruhnya di TW I
                // supaya kumulatif Y (mekanisme CapaianTahunan yang sudah ada) sama
                // dengan Y konstan pada triwulan mana pun.
                'x_alokasi_tw1' => $alokasi[1], 'x_alokasi_tw2' => $alokasi[2],
                'x_alokasi_tw3' => $alokasi[3], 'x_alokasi_tw4' => $alokasi[4],
                'y_alokasi_tw1' => $penyebutY, 'y_alokasi_tw2' => 0.0,
                'y_alokasi_tw3' => 0.0, 'y_alokasi_tw4' => 0.0,
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
}
