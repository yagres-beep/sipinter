<?php

namespace App\Services;

/**
 * Rumus Capaian Kinerja Triwulanan, sesuai Kertas Kerja Pengukuran Kinerja
 * Triwulanan resmi — dikumpulkan di satu tempat (murni fungsi matematis, tanpa
 * ketergantungan Eloquent/DB) supaya bisa dites & dipakai ulang di luar konteks
 * model tertentu.
 *
 * Ini BUKAN satu-satunya jalur rumus di aplikasi: App\Models\Capaian dan
 * App\Models\CapaianTahunan (dipakai di seluruh alur Verifikasi/Dasbor Capaian
 * yang sudah berjalan) mendelegasikan perhitungannya ke sini — lihat
 * Capaian::hitungPersentase()/predikatSakip() dan CapaianTahunan::rasioNilai().
 * Service ini jadi SATU sumber rumus; model-model tsb hanya menjembatani nama/tipe
 * API lama (mis. null vs "-") supaya kode yang sudah memakainya tidak perlu ikut
 * berubah.
 *
 * Rumus Penilaian Kinerja Organisasi (PKO) — Normalisasi Capaian PK, Koreksi
 * Predikat, Nilai Akhir Capaian PK, Total/Rata-rata Capaian PK, Predikat PKO —
 * SENGAJA TIDAK ADA di sini (dihapus dari cakupan aplikasi ini seluruhnya, lihat
 * riwayat git kelas ini/PkoCalculatorService sebelum dihapus bila perlu rujukan).
 * Perhitungan berhenti sampai Capaian Kinerja (Rumus 2.3/2.4 pada Kertas Kerja),
 * TIDAK berlanjut ke Penilaian Kinerja Organisasi.
 */
class CapaianCalculatorService
{
    /**
     * Penanda "tidak dinilai" (capaian belum bisa dihitung — target & realisasi
     * belum keduanya terisi) — dipakai APA ADANYA (string "-"), BUKAN 0, supaya
     * tidak menyeret turun rata-rata pada agregasi (rataRataCapaianTriwulanan(),
     * rataRataCapaianSetahun(), subtotalPerSasaran(), dst).
     */
    public const TIDAK_DINILAI = '-';

    /**
     * Rumus 2.2 (Nilai Tampil Kumulatif, Tipe A "%") — Pembilang (X) ÷ Penyebut (Y)
     * × 100 — menurunkan persentase dari pasangan X/Y mentah (dipakai IKU bertipe %
     * dengan metode_capaian 'rasio', mis. Alokasi/Realisasi Kumulatif). Guard
     * pembagi nol/null → 0, BUKAN exception, karena X/Y kumulatif boleh belum
     * terisi sama sekali di awal tahun.
     */
    public static function hitungPersentase(?float $x, ?float $y): float
    {
        if ($y === null || $y == 0.0) {
            return 0.0;
        }

        return ($x ?? 0.0) / $y * 100;
    }

    /**
     * Rumus 2.3 & 2.4 (Capaian Kinerja terhadap Target Triwulanan / Setahun) —
     * mesin bersama untuk KEDUA rumus, dibedakan hanya oleh nilai $alokasi yang
     * dioper pemanggil (alokasiTampil untuk 2.3, targetTampil untuk 2.4):
     *
     * a. alokasi=0, realisasi>0        → $batasCapaian (dianggap capaian penuh)
     * b. alokasi=0, realisasi<=0       → TIDAK_DINILAI ("-", belum ada capaian)
     * c. alokasi>0, realisasi<=0       → TIDAK_DINILAI ("-", belum ada capaian)
     * d. alokasi>0, realisasi>0        → realisasi÷alokasi×100, dibatasi maksimal $batasCapaian
     *
     * $batasCapaian default 120 sesuai Kertas Kerja, TAPI dibuat sebagai parameter
     * (bukan konstanta) supaya App\Models\Capaian bisa meneruskan nilai yang bisa
     * dikonfigurasi Tim SAKIP lewat halaman Pengaturan Rumus Capaian
     * (App\Models\PengaturanCapaian::batas_maksimal_persen).
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
     * Rumus 2.1 (Predikat SAKIP) — dari Nilai SAKIP (0-100, dari Inspektorat).
     * Ditampilkan sebagai info di header Dasbor Capaian SAJA — TIDAK dipakai dalam
     * perhitungan apa pun (beda dari versi lama yang menentukan % koreksi PKO).
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
     * Bagian 3 bullet 1 (Rata-rata capaian per TW, vs target triwulanan) — rata-rata
     * Capaian Kinerja indikator jenis IKU (BUKAN Proksi — pemanggil wajib sudah
     * menyaring itu SEBELUM memanggil fungsi ini, lihat App\Models\MasterIku::
     * JENIS_IKU/JENIS_PROKSI) — mengabaikan nilai TIDAK_DINILAI ("-") MAUPUN nilai 0,
     * supaya IKU yang belum sempat direalisasikan tidak menyeret turun rata-rata IKU
     * lain yang sudah tercapai. TIDAK_DINILAI ("-") dikembalikan bila tidak ada satu
     * pun nilai valid untuk dirata-ratakan.
     *
     * @param  array<int, float|string>  $capaianList
     */
    public static function rataRataCapaianTriwulanan(array $capaianList): float|string
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
     * Bagian 3 bullet 2 (Rata-rata capaian per TW, vs target setahun) — BEDA
     * sengaja dari rataRataCapaianTriwulanan() di atas: bila SEMUA IKU bernilai
     * TIDAK_DINILAI ("-") → TIDAK_DINILAI; selain itu (jumlah capaian SELURUH IKU,
     * "-" dihitung 0 dalam jumlah) ÷ (jumlah TOTAL indikator IKU dalam daftar) —
     * pembagi TETAP jumlah total elemen $capaianList, BUKAN jumlah nilai valid
     * (beda dari rataRataCapaianTriwulanan() yang membagi dengan jumlah nilai valid
     * saja). Pemanggil wajib sudah menyaring Proksi sebelum memanggil ini.
     *
     * @param  array<int, float|string>  $capaianList
     */
    public static function rataRataCapaianSetahun(array $capaianList): float|string
    {
        if ($capaianList === [] || array_filter($capaianList, fn ($v) => $v !== self::TIDAK_DINILAI) === []) {
            return self::TIDAK_DINILAI;
        }

        $jumlah = array_sum(array_map(
            fn ($v) => $v === self::TIDAK_DINILAI ? 0.0 : (float) $v,
            $capaianList
        ));

        return $jumlah / count($capaianList);
    }

    /**
     * Bagian 3 bullet 3 (Subtotal per kelompok sasaran) — AVERAGEIF Capaian Kinerja
     * indikator jenis IKU dalam SATU sasaran (pemanggil mengelompokkan per
     * MasterIku::sasaran & menyaring jenis_iku SEBELUM memanggil ini) — mengabaikan
     * TIDAK_DINILAI ("-"), TAPI nilai 0 TETAP ikut dirata-ratakan (beda dari
     * rataRataCapaianTriwulanan() di atas — 0 di sini berarti "sudah dinilai,
     * capaiannya nol", bukan "belum dinilai"). TIDAK_DINILAI dikembalikan bila
     * kelompok kosong.
     *
     * @param  array<int, float|string>  $capaianIndikatorDalamSasaran
     */
    public static function subtotalPerSasaran(array $capaianIndikatorDalamSasaran): float|string
    {
        $valid = array_filter($capaianIndikatorDalamSasaran, fn ($v) => $v !== self::TIDAK_DINILAI);

        if ($valid === []) {
            return self::TIDAK_DINILAI;
        }

        return array_sum($valid) / count($valid);
    }
}
