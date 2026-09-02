<?php

namespace App\Models;

use App\Services\CapaianCalculatorService;
use App\Services\FormulaCapaianService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Target Tahunan + Alokasi Target/Realisasi per triwulan SATU IKU pada SATU tahun
 * (satu baris per iku_id+tahun) — diisi Tim SAKIP SEKALI per tahun langsung dari
 * halaman Verifikasi per-IKU (App\Livewire\VerifikasiCapaian), menggantikan cara
 * lama yang mengharuskan Target PK/Target TW diketik ulang di tiap Capaian bulanan.
 *
 * TIGA pola pengisian berbeda per kolom TW (lihat masing-masing docblock rumus di
 * bawah untuk alasannya):
 * - alokasi_twN/realisasi_twN (IKU 'langsung') dan x_realisasi_twN (IKU 'rasio' --
 *   PEMBILANG realisasi saja): nilai MENTAH TRIWULAN ITU SENDIRI, DIJUMLAHKAN
 *   otomatis TW I s.d. TW yang diminta oleh alokasiKumulatif()/realisasiKumulatif()
 *   — KEPUTUSAN PRODUK sengaja beda dari sheet resmi "LK_Kabkot" supaya Tim SAKIP
 *   cukup mengisi kontribusi triwulan berjalan, tidak perlu menjumlah ulang
 *   triwulan-triwulan sebelumnya.
 * - x_alokasi_twN/y_alokasi_twN (IKU 'rasio'): SUDAH KUMULATIF sejak diisi (Tim
 *   SAKIP mengetik langsung angka kumulatif TW I s.d. TW tsb, PERSIS seperti kolom
 *   M-P sheet "LK_Kabkot" resmi) — alokasiKumulatif() membacanya APA ADANYA, TIDAK
 *   dijumlahkan lagi. Target Tahunan IKU 'rasio' pun mengikuti pola ini: SELALU sama
 *   dengan Alokasi Kumulatif TW IV (x_alokasi_tw4/y_alokasi_tw4), BUKAN pasangan
 *   x_target/y_target terpisah (kolom lama, tidak dipakai lagi) — lihat
 *   targetTahunan(). IKU 'langsung' TETAP mengisi target_tahunan terpisah.
 * - y_realisasi_twN (IKU 'rasio' -- PENYEBUT realisasi saja): SAMA seperti
 *   y_alokasi_twN -- konstan sepanjang tahun, diulang sama di keempat TW (BUKAN
 *   nol berjenjang), dibaca APA ADANYA per TW oleh realisasiKumulatif() TANPA
 *   dijumlahkan, sesuai kolom Q-T "Realisasi (Kumulatif)" sheet resmi (Y-nya
 *   selalu konstan pada baris Penyebut, sama seperti Alokasi).
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
     * resmi. Dua cara hitung tergantung MasterIku::metode_capaian:
     * - 'rasio' (IKU bertipe %): x_alokasi_tw{$tw} ÷ y_alokasi_tw{$tw} × 100, DIBACA
     *   APA ADANYA -- TIDAK dijumlahkan lintas TW (beda dari realisasiKumulatif() di
     *   bawah). x_alokasi_tw1..4/y_alokasi_tw1..4 SUDAH kumulatif sejak diisi Tim
     *   SAKIP (App\Livewire\TargetTahunan), PERSIS seperti kolom M-P/Alokasi X pada
     *   sheet "LK_Kabkot" resmi (mis. TW I=1, TW II=1, TW III=2, TW IV=3 -- BUKAN
     *   kontribusi 1,0,1,1 yang perlu dijumlahkan) -- 0.0 bila y_alokasi_tw{$tw}
     *   masih 0/kosong, BUKAN exception, biar hilirnya (capaianTriwulanan()/
     *   capaianSetahun()) yang menentukan "-" lewat Capaian::hitungPersentase().
     * - 'langsung' (default): jumlah nilai mentah alokasi_tw1..$tw (TETAP dijumlahkan
     *   -- Tim SAKIP mengisi kontribusi triwulan berjalan saja untuk metode ini,
     *   TIDAK berubah oleh RF ini).
     */
    public function alokasiKumulatif(int $tw): float
    {
        if ($this->masterIku?->pakaiRasio()) {
            return $this->rasioNilai($this->{"x_alokasi_tw{$tw}"}, $this->{"y_alokasi_tw{$tw}"});
        }

        return collect(range(1, $tw))
            ->sum(fn ($n) => (float) ($this->{"alokasi_tw{$n}"} ?? 0));
    }

    /**
     * Realisasi Kumulatif dari TW I s.d. triwulan $tw — sesuai kolom "Realisasi
     * (Kumulatif)" pada kertas kerja resmi. Untuk 'rasio', X dan Y dibaca dengan DUA
     * cara BERBEDA (beda dari alokasiKumulatif() di atas yang membaca X MAUPUN Y
     * langsung per TW):
     * - X (x_realisasi_tw{n}): nilai MENTAH triwulan itu sendiri (App\Livewire\
     *   VerifikasiCapaian mengisinya otomatis dari jumlah item App\Models\RincianN
     *   yang direalisasikan PADA triwulan itu saja, lihat syncRincianN()) — TETAP
     *   DIJUMLAHKAN lintas TW I s.d. $tw.
     * - Y (y_realisasi_tw{n}): SAMA seperti Alokasi Y (konstan sepanjang tahun,
     *   diulang sama di keempat TW oleh App\Livewire\TargetTahunan::simpan()) —
     *   dibaca LANGSUNG per TW, TIDAK dijumlahkan (menjumlah nilai yang sudah
     *   konstan tidak masuk akal & bikin isian per-TW yang tampil di layar terlihat
     *   nol di TW II-IV padahal sudah konstan sejak awal, sesuai kolom Q-T "Realisasi
     *   (Kumulatif)" sheet "LK_Kabkot" resmi yang juga konstan, bukan nol berjenjang).
     * 'langsung': jumlah nilai mentah realisasi_tw1..$tw, TIDAK berubah.
     */
    public function realisasiKumulatif(int $tw): float
    {
        if ($this->masterIku?->pakaiRasio()) {
            $x = collect(range(1, $tw))->sum(fn ($n) => (float) ($this->{"x_realisasi_tw{$n}"} ?? 0));

            return $this->rasioNilai($x, $this->{"y_realisasi_tw{$tw}"});
        }

        return collect(range(1, $tw))
            ->sum(fn ($n) => (float) ($this->{"realisasi_tw{$n}"} ?? 0));
    }

    /**
     * Rumus 2.2 (Nilai Tampil — targetTampil) — Target Tahunan IKU ini. Untuk
     * 'rasio' (IKU bertipe %) SELALU = Alokasi Target Kumulatif TW IV
     * (alokasiKumulatif(4), yakni x_alokasi_tw4 ÷ y_alokasi_tw4 × 100) -- BUKAN lagi
     * pasangan x_target/y_target yang diketik terpisah (kolom lama, TIDAK dipakai
     * lagi di sini, dibiarkan apa adanya di DB) -- sesuai sheet "LK_Kabkot" resmi
     * yang mendefinisikan Target ($K12=$K13/$K14×100) sama persis dengan Alokasi
     * Kumulatif TW IV ($P12=$P13/$P14×100, $K13=$P13 & $K14=$P14): SATU tempat
     * isian (Alokasi X/Y TW IV di App\Livewire\TargetTahunan), bukan dua yang bisa
     * tidak sinkron. Untuk 'langsung', nilai target_tahunan apa adanya (tetap
     * diisi terpisah, TIDAK dijumlahkan seperti alokasi/realisasi di atas).
     */
    public function targetTahunan(): float
    {
        if ($this->masterIku?->pakaiRasio()) {
            return $this->alokasiKumulatif(4);
        }

        return (float) ($this->target_tahunan ?? 0);
    }


    /**
     * Rumus 2.2 (Nilai Tampil — toPersen) — X÷Y×100 — dipakai targetTahunan()
     * (langsung, tanpa jumlah) dan rasioKumulatif() (setelah dijumlahkan) untuk
     * IKU bertipe %.
     */
    protected function rasioNilai(mixed $x, mixed $y): float
    {
        $x = (float) ($x ?? 0);
        $y = (float) ($y ?? 0);

        return round(CapaianCalculatorService::hitungPersentase($x, $y), 2);
    }

    /**
     * Rumus 2.3 (Capaian Kinerja Terhadap Target Triwulanan) pada triwulan $tw —
     * Realisasi Kumulatif ÷ Alokasi Target Kumulatif TW itu sendiri, lewat rumus
     * resmi Capaian::hitungPersentase() (batas maksimal, aturan strip "-", dst.
     * tidak diduplikasi di sini) -- ATAU rumus kustom bila IKU 'langsung' ini
     * mengisi MasterIku::formula_capaian (lihat capaianFormula()).
     */
    public function capaianTriwulanan(int $tw): ?float
    {
        $alokasi = $this->alokasiKumulatif($tw);
        $realisasi = $this->realisasiKumulatif($tw);

        return $this->capaianFormula($alokasi, $realisasi) ?? Capaian::hitungPersentase($alokasi, $realisasi);
    }

    /**
     * Rumus 2.4 (Capaian Kinerja Terhadap Target Setahun) pada triwulan $tw —
     * Realisasi Kumulatif TW itu ÷ Target Tahunan penuh -- ATAU rumus kustom bila
     * IKU 'langsung' ini mengisi MasterIku::formula_capaian.
     */
    public function capaianSetahun(int $tw): ?float
    {
        $target = $this->targetTahunan();
        $realisasi = $this->realisasiKumulatif($tw);

        return $this->capaianFormula($target, $realisasi) ?? Capaian::hitungPersentase($target, $realisasi);
    }

    /**
     * Rumus kustom (App\Services\FormulaCapaianService), HANYA dipakai bila
     * MasterIku::metode_capaian === 'langsung' DAN MasterIku::formula_capaian
     * terisi -- null (dilewati, jatuh ke rumus baku Capaian::hitungPersentase())
     * untuk SEMUA IKU 'rasio' dan SELURUH IKU 'langsung' yang belum mengisi rumus
     * kustom (yaitu SEMUA indikator yang sudah ada hari ini, tidak ada yang
     * berubah perilakunya kecuali eksplisit diisi Tim SAKIP).
     */
    protected function capaianFormula(float $alokasi, float $realisasi): ?float
    {
        $iku = $this->masterIku;

        if (! $iku || $iku->pakaiRasio() || blank($iku->formula_capaian)) {
            return null;
        }

        $batas = (float) PengaturanCapaian::ambil()->batas_maksimal_persen;

        return round(FormulaCapaianService::evaluasi($iku->formula_capaian, $alokasi, $realisasi, $batas), 2);
    }
}
