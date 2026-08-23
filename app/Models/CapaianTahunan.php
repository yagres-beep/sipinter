<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Target Tahunan + Alokasi Target/Realisasi per triwulan SATU IKU pada SATU tahun
 * (satu baris per iku_id+tahun) — diisi Tim SAKIP SEKALI per tahun langsung dari
 * halaman Verifikasi per-IKU (App\Livewire\VerifikasiCapaian), menggantikan cara
 * lama yang mengharuskan Target PK/Target TW diketik ulang di tiap Capaian bulanan.
 *
 * KEPUTUSAN PRODUK (disengaja BEDA dari sheet resmi "LK_Kabkot" yang mengisi tiap
 * kolom TW dengan nilai kumulatif langsung): alokasi_twN/realisasi_twN (IKU bertipe
 * Non %, 'langsung') dan x_alokasi_twN/y_alokasi_twN/x_realisasi_twN/y_realisasi_twN
 * (IKU bertipe %, 'rasio') adalah nilai MENTAH TRIWULAN ITU SENDIRI (bukan kumulatif)
 * — Tim SAKIP cukup mengisi kontribusi triwulan berjalan, TIDAK perlu melihat/menghitung
 * ulang nilai triwulan-triwulan sebelumnya. Nilai kumulatif (dijumlahkan TW I s.d. TW
 * yang diminta) dihitung OTOMATIS oleh alokasiKumulatif()/realisasiKumulatif() di
 * bawah. Untuk IKU bertipe %, Target Tahunan sendiri JUGA rasio X/Y (x_target/
 * y_target) TAPI diisi SEKALI untuk satu tahun penuh (tidak per-TW, boleh diperbarui
 * kapan pun) — lihat targetTahunan().
 */
class CapaianTahunan extends Model
{
    use HasFactory;

    protected $table = 'capaian_tahunan';

    protected $fillable = [
        'iku_id',
        'tahun',
        'target_tahunan',
        'x_target',
        'y_target',
        'alokasi_tw1',
        'alokasi_tw2',
        'alokasi_tw3',
        'alokasi_tw4',
        'realisasi_tw1',
        'realisasi_tw2',
        'realisasi_tw3',
        'realisasi_tw4',
        'x_alokasi_tw1',
        'x_alokasi_tw2',
        'x_alokasi_tw3',
        'x_alokasi_tw4',
        'y_alokasi_tw1',
        'y_alokasi_tw2',
        'y_alokasi_tw3',
        'y_alokasi_tw4',
        'x_realisasi_tw1',
        'x_realisasi_tw2',
        'x_realisasi_tw3',
        'x_realisasi_tw4',
        'y_realisasi_tw1',
        'y_realisasi_tw2',
        'y_realisasi_tw3',
        'y_realisasi_tw4',
    ];

    protected function casts(): array
    {
        $decimal = [
            'target_tahunan', 'x_target', 'y_target',
            'alokasi_tw1', 'alokasi_tw2', 'alokasi_tw3', 'alokasi_tw4',
            'realisasi_tw1', 'realisasi_tw2', 'realisasi_tw3', 'realisasi_tw4',
            'x_alokasi_tw1', 'x_alokasi_tw2', 'x_alokasi_tw3', 'x_alokasi_tw4',
            'y_alokasi_tw1', 'y_alokasi_tw2', 'y_alokasi_tw3', 'y_alokasi_tw4',
            'x_realisasi_tw1', 'x_realisasi_tw2', 'x_realisasi_tw3', 'x_realisasi_tw4',
            'y_realisasi_tw1', 'y_realisasi_tw2', 'y_realisasi_tw3', 'y_realisasi_tw4',
        ];

        return array_fill_keys($decimal, 'decimal:2');
    }

    public function masterIku(): BelongsTo
    {
        return $this->belongsTo(MasterIku::class, 'iku_id');
    }

    /**
     * Alokasi Target Kumulatif dari TW I s.d. triwulan $tw (1-4), sesuai kolom
     * "Alokasi Target (Kumulatif)" pada Kertas Kerja Pengukuran Kinerja Triwulanan
     * resmi — DIJUMLAHKAN dari nilai mentah per-TW (lihat docblock kelas ini: Tim
     * SAKIP hanya mengisi kontribusi triwulan berjalan, kumulatifnya dihitung di
     * sini). Dua cara hitung tergantung MasterIku::metode_capaian:
     * - 'rasio' (IKU bertipe %): jumlah X÷jumlah Y×100 dari x_alokasi_tw1..$tw /
     *   y_alokasi_tw1..$tw — 0.0 bila jumlah Y masih 0 (belum ada apa-apa), BUKAN
     *   exception, biar hilirnya (capaianTriwulanan()/capaianSetahun()) yang
     *   menentukan "-" lewat Capaian::hitungPersentase().
     * - 'langsung' (default): jumlah nilai mentah alokasi_tw1..$tw.
     */
    public function alokasiKumulatif(int $tw): float
    {
        if ($this->masterIku?->pakaiRasio()) {
            return $this->rasioKumulatif('x_alokasi', 'y_alokasi', $tw);
        }

        return collect(range(1, $tw))
            ->sum(fn ($n) => (float) ($this->{"alokasi_tw{$n}"} ?? 0));
    }

    /**
     * Realisasi Kumulatif dari TW I s.d. triwulan $tw — sesuai kolom "Realisasi
     * (Kumulatif)" pada kertas kerja resmi. Dijumlahkan dari nilai mentah per-TW,
     * cabang 'rasio'/'langsung' sama seperti alokasiKumulatif() di atas.
     */
    public function realisasiKumulatif(int $tw): float
    {
        if ($this->masterIku?->pakaiRasio()) {
            return $this->rasioKumulatif('x_realisasi', 'y_realisasi', $tw);
        }

        return collect(range(1, $tw))
            ->sum(fn ($n) => (float) ($this->{"realisasi_tw{$n}"} ?? 0));
    }

    /**
     * Target Tahunan IKU ini — DIISI SEKALI untuk satu tahun penuh (bukan per-TW,
     * boleh diperbarui kapan pun), TIDAK dijumlahkan seperti alokasi/realisasi di
     * atas. Untuk 'rasio' (IKU bertipe %) dihitung dari x_target/y_target (pasangan
     * X/Y TERPISAH dari X/Y per-TW manapun). Untuk 'langsung', nilai target_tahunan
     * apa adanya.
     */
    public function targetTahunan(): float
    {
        if ($this->masterIku?->pakaiRasio()) {
            return $this->rasioNilai($this->x_target, $this->y_target);
        }

        return (float) ($this->target_tahunan ?? 0);
    }

    /**
     * Jumlah X÷jumlah Y×100 dari TW I s.d. $tw, dipakai bersama oleh
     * alokasiKumulatif()/realisasiKumulatif() untuk IKU bertipe % — $prefixX/$prefixY
     * masing-masing "x_alokasi"/"y_alokasi" atau "x_realisasi"/"y_realisasi".
     */
    protected function rasioKumulatif(string $prefixX, string $prefixY, int $tw): float
    {
        $x = collect(range(1, $tw))->sum(fn ($n) => (float) ($this->{"{$prefixX}_tw{$n}"} ?? 0));
        $y = collect(range(1, $tw))->sum(fn ($n) => (float) ($this->{"{$prefixY}_tw{$n}"} ?? 0));

        return $this->rasioNilai($x, $y);
    }

    /**
     * X÷Y×100 — dipakai targetTahunan() (langsung, tanpa jumlah) dan rasioKumulatif()
     * (setelah dijumlahkan) untuk IKU bertipe %.
     */
    protected function rasioNilai(mixed $x, mixed $y): float
    {
        $x = (float) ($x ?? 0);
        $y = (float) ($y ?? 0);

        return $y > 0 ? round($x / $y * 100, 2) : 0.0;
    }

    /**
     * Capaian Kinerja Terhadap Target Triwulanan pada triwulan $tw — Realisasi
     * Kumulatif ÷ Alokasi Target Kumulatif TW itu sendiri, lewat rumus resmi
     * Capaian::hitungPersentase() (batas maksimal, aturan strip "-", dst. tidak
     * diduplikasi di sini).
     */
    public function capaianTriwulanan(int $tw): ?float
    {
        return Capaian::hitungPersentase($this->alokasiKumulatif($tw), $this->realisasiKumulatif($tw));
    }

    /**
     * Capaian Kinerja Terhadap Target Setahun pada triwulan $tw — Realisasi
     * Kumulatif TW itu ÷ Target Tahunan penuh.
     */
    public function capaianSetahun(int $tw): ?float
    {
        return Capaian::hitungPersentase($this->targetTahunan(), $this->realisasiKumulatif($tw));
    }
}
