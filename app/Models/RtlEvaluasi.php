<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class RtlEvaluasi extends Model
{
    use HasFactory;

    protected $table = 'rtl_evaluasi';

    public const STATUS_COCOK = 'cocok';

    public const STATUS_PERLU_DITINJAU = 'perlu_ditinjau';

    public const STATUS_TIDAK_COCOK = 'tidak_cocok';

    /**
     * Ambang batas kemiripan teks (0-100, dari PHP similar_text()) untuk RF-35:
     * >= AMBANG_COCOK        -> "cocok"
     * >= AMBANG_PERLU_DITINJAU (tapi < AMBANG_COCOK) -> "perlu ditinjau"
     * di bawah itu            -> "tidak cocok"
     * Ketua tim tetap bisa menimpa hasil ini secara manual (lihat RtlEvaluasi Livewire).
     */
    public const AMBANG_COCOK = 70;

    public const AMBANG_PERLU_DITINJAU = 30;

    protected $fillable = [
        'iku_id',
        'periode_id',
        'rtl_teks',
        'berlaku_bulan',
        'pic',
        'batas_waktu',
        'realisasi',
        'status_cocok',
        'status_verifikasi',
        'catatan',
    ];

    protected function casts(): array
    {
        return [
            'batas_waktu' => 'date',
        ];
    }

    public function masterIku(): BelongsTo
    {
        return $this->belongsTo(MasterIku::class, 'iku_id');
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class, 'periode_id');
    }

    /**
     * Bukti dukung realisasi RTL (RF-30), disimpan di Drive kategori "Evaluasi-RTL".
     * Bersifat opsional (tidak seperti bukti solusi yang wajib) dan boleh lebih dari satu.
     */
    public function berkas(): MorphMany
    {
        return $this->morphMany(Berkas::class, 'ref', 'ref_type', 'ref_id');
    }

    /**
     * "Dievaluasi" sekarang berarti minimal satu bukti realisasi sudah diunggah —
     * tidak lagi berdasarkan teks realisasi/status kecocokan (dihapus dari alur
     * pengisian, poin cukup ditampilkan + tempat unggah bukti saja).
     */
    public function sudahDievaluasi(): bool
    {
        return $this->relationLoaded('berkas') ? $this->berkas->isNotEmpty() : $this->berkas()->exists();
    }

    /**
     * RF-35: cocokkan teks $realisasi terhadap rtl_teks poin ini, kembalikan salah
     * satu dari 3 status. Murni fungsi teks (tidak menyimpan apa pun) — pemanggil
     * yang memutuskan menyimpan hasilnya atau membiarkan ketua tim menimpanya manual.
     */
    public function sarankanStatusCocok(string $realisasi): string
    {
        similar_text($this->rtl_teks, $realisasi, $persen);

        return match (true) {
            $persen >= self::AMBANG_COCOK => self::STATUS_COCOK,
            $persen >= self::AMBANG_PERLU_DITINJAU => self::STATUS_PERLU_DITINJAU,
            default => self::STATUS_TIDAK_COCOK,
        };
    }
}
