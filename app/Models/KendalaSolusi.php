<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KendalaSolusi extends Model
{
    use HasFactory;

    protected $table = 'kendala_solusi';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_DIAJUKAN = 'diajukan';

    protected $fillable = [
        'iku_id',
        'periode_id',
        'kendala',
        'solusi',
        'status_dokumen',
        'status_verifikasi',
        'catatan',
    ];

    public function masterIku(): BelongsTo
    {
        return $this->belongsTo(MasterIku::class, 'iku_id');
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class, 'periode_id');
    }

    /**
     * Rincian jumlah Kendala &amp; Solusi per status_verifikasi dalam satu Capaian
     * (IKU+bulan) — pola sama persis dengan RtlEvaluasi::rincianStatusVerifikasi(),
     * dipakai di tabel dasbor/daftar verifikasi supaya kendala-solusi yang ditolak
     * Tim SAKIP kelihatan rinciannya juga, bukan cuma lewat badge besar Capaian::status.
     *
     * @param  \Illuminate\Support\Collection<int, self>  $kendalaSatuIkuPeriode
     * @return \Illuminate\Support\Collection<string, int>
     */
    public static function rincianStatusVerifikasi($kendalaSatuIkuPeriode)
    {
        $jumlahPerStatus = $kendalaSatuIkuPeriode->countBy('status_verifikasi');

        return collect([
            'menunggu',
            'terverifikasi',
            'ditolak',
        ])->mapWithKeys(fn ($status) => [$status => $jumlahPerStatus->get($status, 0)])
            ->filter(fn ($jumlah) => $jumlah > 0);
    }
}
