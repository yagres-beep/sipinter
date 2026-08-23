<?php

namespace App\Models;

use App\Exceptions\InvalidStatusTransitionException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Kegiatan extends Model
{
    use HasFactory;

    protected $table = 'kegiatan';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_DIAJUKAN = 'diajukan';

    public const STATUS_DIVERIFIKASI = 'diverifikasi';

    public const STATUS_DIKEMBALIKAN = 'dikembalikan';

    public const STATUS_DISETUJUI = 'disetujui';

    /**
     * Alur status dokumen sesuai SIPINTER_Flowchart.html (swimlane Ketua Tim / Tim SAKIP / Kepala):
     *
     *   draft ──ajukan()──> diajukan ──verifikasi()──> diverifikasi ──setujui()──> disetujui
     *                           │                            (dikonfirmasi lewat kompilasi notula
     *                           └──kembalikan()──> dikembalikan ──ajukan()──┘ oleh Kepala — lihat catatan setujui())
     *
     * "Berkas sesuai?" pada diagram adalah keputusan Tim SAKIP saat status masih diajukan:
     * Ya → diverifikasi, Tidak → dikembalikan. Ketua Tim memperbaiki isian yang dikembalikan
     * lalu mengajukan ulang (dikembalikan → diajukan).
     *
     * @var array<string, list<string>>
     */
    protected const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_DIAJUKAN],
        self::STATUS_DIAJUKAN => [self::STATUS_DIVERIFIKASI, self::STATUS_DIKEMBALIKAN],
        self::STATUS_DIKEMBALIKAN => [self::STATUS_DIAJUKAN],
        self::STATUS_DIVERIFIKASI => [self::STATUS_DISETUJUI],
        self::STATUS_DISETUJUI => [],
    ];

    protected $fillable = [
        'iku_id',
        'rtl_evaluasi_id',
        'periode_id',
        'uraian_kegiatan',
        'jenis',
        'tahapan_survei',
        'nama_folder_auto',
        'drive_folder_id',
        'status_dokumen',
    ];

    public function masterIku(): BelongsTo
    {
        return $this->belongsTo(MasterIku::class, 'iku_id');
    }

    /**
     * Poin RTL triwulan berjalan yang "dilaksanakan" oleh kegiatan ini — diisi saat
     * uraian kegiatan dipilih dari dropdown rencana RTL (lihat PengisianKegiatan).
     */
    public function rtlEvaluasi(): BelongsTo
    {
        return $this->belongsTo(RtlEvaluasi::class, 'rtl_evaluasi_id');
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class, 'periode_id');
    }

    /**
     * Angka capaian dibagikan seluruh kegiatan pada IKU+periode yang sama (RF-38) —
     * bukan relasi Eloquent langsung karena kuncinya (iku_id, periode_id) bukan
     * kegiatan_id. Dipakai di tempat yang butuh angka capaian dari satu kegiatan.
     */
    public function capaian(): ?Capaian
    {
        return Capaian::where('iku_id', $this->iku_id)
            ->where('periode_id', $this->periode_id)
            ->first();
    }

    /**
     * Bukti capaian (PDF) milik kegiatan ini sendiri (RF-23) — wajib per kegiatan,
     * boleh lebih dari satu berkas.
     */
    public function berkas(): MorphMany
    {
        return $this->morphMany(Berkas::class, 'ref', 'ref_type', 'ref_id');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status_dokumen] ?? [], true);
    }

    /**
     * Rincian jumlah kegiatan per status_dokumen dalam satu Capaian (IKU+bulan) —
     * mis. 3 diverifikasi + 2 dikembalikan — dipakai di tabel dasbor/daftar verifikasi
     * SUPAYA status besar satu Capaian (Capaian::status, satu-satunya yang menentukan
     * alur bisa-diverifikasi/tidak) tetap bisa ditelusuri sampai ke rincian per
     * kegiatannya tanpa harus membuka detail. Hanya status yang benar-benar ada
     * (jumlah > 0) yang disertakan, diurutkan sesuai alur (draft→...→disetujui) supaya
     * tampilannya konsisten di semua tabel.
     *
     * @param  \Illuminate\Support\Collection<int, self>  $kegiatanSatuIkuPeriode
     * @return \Illuminate\Support\Collection<string, int>
     */
    public static function rincianStatus($kegiatanSatuIkuPeriode)
    {
        $jumlahPerStatus = $kegiatanSatuIkuPeriode->countBy('status_dokumen');

        return collect([
            self::STATUS_DRAFT,
            self::STATUS_DIAJUKAN,
            self::STATUS_DIVERIFIKASI,
            self::STATUS_DIKEMBALIKAN,
            self::STATUS_DISETUJUI,
        ])->mapWithKeys(fn ($status) => [$status => $jumlahPerStatus->get($status, 0)])
            ->filter(fn ($jumlah) => $jumlah > 0);
    }

    protected function transitionTo(string $status): void
    {
        if (! $this->canTransitionTo($status)) {
            throw new InvalidStatusTransitionException($this->status_dokumen, $status);
        }

        $this->update(['status_dokumen' => $status]);
    }

    /**
     * Ketua Tim mengajukan isian (draft/dikembalikan → diajukan).
     */
    public function ajukan(): void
    {
        $this->transitionTo(self::STATUS_DIAJUKAN);
    }

    /**
     * Tim SAKIP menandai isian terverifikasi setelah seluruh berkas sesuai (diajukan → diverifikasi).
     */
    public function verifikasi(): void
    {
        $this->transitionTo(self::STATUS_DIVERIFIKASI);
    }

    /**
     * Tim SAKIP mengembalikan isian ke Ketua Tim karena ada berkas tidak sesuai (diajukan → dikembalikan).
     */
    public function kembalikan(): void
    {
        $this->transitionTo(self::STATUS_DIKEMBALIKAN);
    }

    /**
     * Isian ikut disetujui saat notula triwulanan yang memuatnya disetujui Kepala (diverifikasi → disetujui).
     *
     * Belum ada pemicu konkret di controller — disediakan agar model siap dipakai begitu
     * alur kompilasi & persetujuan notula (RF-41 s.d. RF-44a) dibangun.
     */
    public function setujui(): void
    {
        $this->transitionTo(self::STATUS_DISETUJUI);
    }
}
