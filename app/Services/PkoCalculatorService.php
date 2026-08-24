<?php

namespace App\Services;

/**
 * Rumus Penilaian Kinerja Organisasi (PKO) & Capaian Kinerja, sesuai Kertas Kerja
 * Pengukuran Kinerja Triwulanan resmi — dikumpulkan di satu tempat (murni fungsi
 * matematis, tanpa ketergantungan Eloquent/DB) supaya bisa dites & dipakai ulang
 * di luar konteks model tertentu.
 *
 * Ini BUKAN satu-satunya jalur rumus di aplikasi: App\Models\Capaian dan
 * App\Models\CapaianTahunan (dipakai di seluruh alur Verifikasi/Dasbor Capaian
 * yang sudah berjalan) mendelegasikan perhitungannya ke sini — lihat
 * Capaian::hitungPersentase()/predikatSakip()/koreksiPredikat()/
 * normalisasiCapaianPk()/nilaiAkhirCapaianPk() dan CapaianTahunan::rasioNilai().
 * Service ini jadi SATU sumber rumus; model-model tsb hanya menjembatani nama/tipe
 * API lama (mis. null vs "-", koreksi dalam persen vs desimal) supaya kode yang
 * sudah memakainya tidak perlu ikut berubah.
 */
class PkoCalculatorService
{
    /**
     * Penanda "tidak dinilai" (capaian belum bisa dihitung — target & realisasi
     * belum keduanya terisi) — dipakai APA ADANYA (string "-"), BUKAN 0, supaya
     * tidak menyeret turun rata-rata pada agregasi (capaianKinerjaIKU(),
     * capaianPerSasaran(), dst).
     */
    public const TIDAK_DINILAI = '-';

    /**
     * Pembilang (X) ÷ Penyebut (Y) × 100 — menurunkan persentase dari pasangan X/Y
     * mentah (dipakai IKU bertipe % dengan metode_capaian 'rasio', mis. Alokasi/
     * Realisasi Kumulatif). Guard pembagi nol/null → 0, BUKAN exception, karena
     * X/Y kumulatif boleh belum terisi sama sekali di awal tahun.
     */
    public static function hitungPersentase(?float $x, ?float $y): float
    {
        if ($y === null || $y == 0.0) {
            return 0.0;
        }

        return ($x ?? 0.0) / $y * 100;
    }

    /**
     * Capaian Kinerja (Realisasi terhadap Alokasi/Target) — dipakai DUA kali sesuai
     * Kertas Kerja resmi: capaian terhadap target TRIWULANAN (pembagi = Alokasi
     * Target Kumulatif triwulan berjalan) dan capaian terhadap target SETAHUN
     * (pembagi = Target Setahun). $batasCapaian default 120 sesuai Kertas Kerja,
     * TAPI dibuat sebagai parameter (bukan konstanta) supaya App\Models\Capaian
     * bisa meneruskan nilai yang bisa dikonfigurasi Tim SAKIP lewat halaman
     * Pengaturan Rumus Capaian (App\Models\PengaturanCapaian::batas_maksimal_persen).
     *
     * a. alokasi=0, realisasi>0        → $batasCapaian (dianggap capaian penuh)
     * b. alokasi=0, realisasi<=0       → TIDAK_DINILAI ("-", belum ada capaian)
     * c. alokasi>0, realisasi<=0       → TIDAK_DINILAI ("-", belum ada capaian)
     * d. alokasi>0, realisasi>0        → realisasi÷alokasi×100, dibatasi maksimal $batasCapaian
     */
    public static function hitungCapaian(float $alokasi, float $realisasi, float $batasCapaian = 120.0): float|string
    {
        if ($alokasi == 0.0 && $realisasi > 0.0) {
            return $batasCapaian;
        }

        if ($alokasi == 0.0 || $realisasi <= 0.0) {
            return self::TIDAK_DINILAI;
        }

        return min($realisasi / $alokasi * 100, $batasCapaian);
    }

    /**
     * Predikat SAKIP dari Nilai SAKIP (0-100, dari Inspektorat) — menentukan %
     * Koreksi Nilai Akhir Capaian PK lewat koreksiPredikat() di bawah.
     */
    public static function predikatSakip(float $nilaiSakip): string
    {
        return match (true) {
            $nilaiSakip > 90 => 'AA/Sangat Memuaskan',
            $nilaiSakip > 80 => 'A/Memuaskan',
            $nilaiSakip > 70 => 'BB/Sangat Baik',
            $nilaiSakip > 60 => 'B/Baik',
            $nilaiSakip > 50 => 'CC/Cukup (Memadai)',
            $nilaiSakip > 30 => 'C/Kurang',
            default => 'D/Sangat Kurang',
        };
    }

    /**
     * Normalisasi Capaian PK — Capaian Setahun TW IV satu IKU dibatasi maksimal
     * $batasNormalisasi (default 110, TERPISAH dari $batasCapaian 120 milik
     * hitungCapaian() — dua plafon berbeda sesuai Kertas Kerja resmi). Nilai
     * TIDAK_DINILAI ("-") diteruskan apa adanya, bukan dianggap 0.
     */
    public static function normalisasiCapaianPK(float|string $capaianTahunan, float $batasNormalisasi = 110.0): float|string
    {
        if ($capaianTahunan === self::TIDAK_DINILAI) {
            return self::TIDAK_DINILAI;
        }

        return min((float) $capaianTahunan, $batasNormalisasi);
    }

    /**
     * % koreksi Nilai Akhir Capaian PK berdasarkan Predikat SAKIP, dalam bentuk
     * DESIMAL (0.10 = 10%) — dipakai SATU angka untuk SELURUH IKU (organisasi).
     */
    public static function koreksiPredikat(string $predikatSakip): float
    {
        return match ($predikatSakip) {
            'AA/Sangat Memuaskan', 'A/Memuaskan' => 0.0,
            'BB/Sangat Baik' => 0.10,
            'B/Baik' => 0.15,
            'CC/Cukup (Memadai)' => 0.20,
            default => 0.30, // C/Kurang, D/Sangat Kurang
        };
    }

    /**
     * Nilai Akhir Capaian PK satu IKU = Normalisasi × (1 − Koreksi Predikat SAKIP).
     */
    public static function nilaiAkhirPK(float $normalisasi, float $koreksi): float
    {
        return $normalisasi * (1 - $koreksi);
    }

    /**
     * Rata-rata Capaian Kinerja indikator jenis IKU (BUKAN Proksi — pemanggil wajib
     * sudah menyaring itu SEBELUM memanggil fungsi ini, lihat App\Models\MasterIku::
     * JENIS_IKU/JENIS_PROKSI) — mengabaikan nilai TIDAK_DINILAI ("-") MAUPUN nilai 0,
     * supaya IKU yang belum sempat direalisasikan tidak menyeret turun rata-rata IKU
     * lain yang sudah tercapai. TIDAK_DINILAI ("-") dikembalikan bila tidak ada satu
     * pun nilai valid untuk dirata-ratakan.
     *
     * @param  array<int, float|string>  $capaianList
     */
    public static function capaianKinerjaIKU(array $capaianList): float|string
    {
        $valid = array_filter(
            $capaianList,
            fn ($v) => $v !== self::TIDAK_DINILAI && (float) $v != 0.0
        );

        if ($valid === []) {
            return self::TIDAK_DINILAI;
        }

        return array_sum($valid) / count($valid);
    }

    /**
     * AVERAGEIF Capaian Kinerja indikator jenis IKU dalam SATU sasaran (pemanggil
     * mengelompokkan per MasterIku::sasaran & menyaring jenis_iku SEBELUM memanggil
     * ini) — mengabaikan TIDAK_DINILAI ("-"), TAPI nilai 0 TETAP ikut dirata-ratakan
     * (beda dari capaianKinerjaIKU() di atas — 0 di sini berarti "sudah dinilai,
     * capaiannya nol", bukan "belum dinilai").
     *
     * @param  array<int, float|string>  $capaianIndikatorDalamSasaran
     */
    public static function capaianPerSasaran(array $capaianIndikatorDalamSasaran): float|string
    {
        $valid = array_filter($capaianIndikatorDalamSasaran, fn ($v) => $v !== self::TIDAK_DINILAI);

        if ($valid === []) {
            return self::TIDAK_DINILAI;
        }

        return array_sum($valid) / count($valid);
    }

    /**
     * Total Capaian PK organisasi — SUM seluruh Nilai Akhir Capaian PK (satu angka
     * per IKU, sudah melalui normalisasiCapaianPK() + nilaiAkhirPK()). Pemanggil
     * bertanggung jawab memastikan daftar ini hanya berisi IKU (bukan Proksi) dan
     * sudah menyaring IKU yang belum punya capaian sama sekali.
     *
     * @param  array<int, float>  $nilaiAkhirList
     */
    public static function totalCapaianPK(array $nilaiAkhirList): float
    {
        return array_sum($nilaiAkhirList);
    }

    /**
     * NKO (Nilai Kinerja Organisasi) Rata-rata — AVERAGE seluruh Nilai Akhir
     * Capaian PK, dasar penentuan predikatPKO() di bawah.
     *
     * @param  array<int, float>  $nilaiAkhirList
     */
    public static function nkoRataRata(array $nilaiAkhirList): float
    {
        return $nilaiAkhirList === [] ? 0.0 : array_sum($nilaiAkhirList) / count($nilaiAkhirList);
    }

    /**
     * Predikat PKO (Penilaian Kinerja Organisasi) dari NKO Rata-rata.
     */
    public static function predikatPKO(float $nko): string
    {
        return match (true) {
            $nko > 100 => 'ISTIMEWA',
            $nko > 80 => 'BAIK',
            $nko > 60 => 'BUTUH PERBAIKAN',
            default => 'BURUK',
        };
    }
}
