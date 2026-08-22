<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Angka capaian IKU pada satu periode (RF-38) — satu baris per (iku_id, periode_id),
 * BUKAN per kegiatan, karena satu IKU boleh punya banyak kegiatan (RF-19) yang
 * berbagi satu set Target PK/Target TW/Realisasi/Capaian % yang sama, diisi Tim SAKIP
 * saat verifikasi. Bukti dukung capaian tetap melekat per Kegiatan (lihat Kegiatan::berkas()),
 * bukan di sini, karena RF-23 mewajibkan bukti capaian per kegiatan.
 */
class Capaian extends Model
{
    use HasFactory;

    protected $table = 'capaian';

    protected $fillable = [
        'iku_id',
        'periode_id',
        'analisis_capaian',
        'target_pk',
        'target_tw',
        'realisasi',
        'persentase_capaian',
    ];

    protected function casts(): array
    {
        return [
            'target_pk' => 'decimal:2',
            'target_tw' => 'decimal:2',
            'realisasi' => 'decimal:2',
            'persentase_capaian' => 'decimal:2',
        ];
    }

    public function masterIku(): BelongsTo
    {
        return $this->belongsTo(MasterIku::class, 'iku_id');
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class);
    }

    /**
     * Riwayat perubahan status alur verifikasi milik Capaian (IKU+periode) ini —
     * satu baris per aksi (diajukan/diverifikasi/dikembalikan/disetujui), terbaru
     * lebih dulu. Kegiatan tambahan yang diajukan belakangan pada IKU+bulan yang
     * sama otomatis tergabung ke riwayat ini juga, karena keduanya berbagi satu
     * baris Capaian yang sama (unique iku_id+periode_id).
     */
    public function riwayatStatus(): HasMany
    {
        return $this->hasMany(RiwayatStatusCapaian::class)->latest('id');
    }

    /**
     * Catat satu perubahan status ke riwayat (RF baru: jejak audit siapa/status/waktu).
     * Dipanggil sekali per aksi alur verifikasi (ajukan/verifikasi/kembalikan/setujui),
     * bukan per kegiatan, supaya satu aksi yang menyentuh banyak kegiatan sekaligus
     * (RF-37/RF-38 — satu IKU boleh punya banyak kegiatan) tetap tercatat sebagai satu
     * poin riwayat, bukan duplikat.
     */
    public function catatStatus(string $status, ?User $user, ?string $catatan = null): RiwayatStatusCapaian
    {
        return $this->riwayatStatus()->create([
            'status' => $status,
            'user_id' => $user?->id,
            'catatan' => $catatan,
        ]);
    }

    /**
     * Rumus capaian kinerja triwulanan resmi (Kertas Kerja Pengukuran Kinerja
     * Triwulanan, perubahan Triwulan II 2026), dipakai baik di VerifikasiCapaian
     * (per IKU+bulan) maupun DasborCapaian (agregat per IKU+triwulan):
     *
     * a. target>0, realisasi>0, rasio<=batas  → capaian = rasio (realisasi/target)
     * b. target>0, realisasi>0, rasio>batas   → capaian = batas (dibatasi/di-cap)
     * c. target=0, realisasi>0                → capaian = batas
     * d. target=0, realisasi=0                → capaian = null (strip "-", belum ada capaian)
     * e. target>0, realisasi=0                → capaian = null (strip "-", belum ada capaian)
     *
     * Batas (default 120) diambil dari PengaturanCapaian::ambil() — dipakai SEKALIGUS
     * untuk aturan (b) dan (c) sesuai rumus resmi, supaya tetap konsisten walau nanti
     * angkanya diubah Tim SAKIP lewat halaman Pengaturan Rumus Capaian, tanpa perlu
     * mengubah kode ini. Satuan IKU (persen/poin/dst., lihat MasterIku::satuan) TIDAK
     * mempengaruhi rumus ini — murni label tampilan, target & realisasi tetap
     * dibandingkan sebagai rasio apa pun satuannya.
     */
    public static function hitungPersentase(mixed $target, mixed $realisasi): ?float
    {
        if (! is_numeric($target) || ! is_numeric($realisasi)) {
            return null;
        }

        $target = (float) $target;
        $realisasi = (float) $realisasi;

        if ($realisasi <= 0.0) {
            // Mencakup aturan (d) target=0&realisasi=0 dan (e) target>0&realisasi=0 —
            // keduanya berarti belum ada capaian untuk dilaporkan (strip).
            return null;
        }

        $batas = (float) PengaturanCapaian::ambil()->batas_maksimal_persen;

        if ($target <= 0.0) {
            // Aturan (c): target=0, realisasi>0.
            return $batas;
        }

        // Aturan (a)/(b): rasio realisasi/target, dibatasi maksimal $batas.
        return min(round(($realisasi / $target) * 100, 2), $batas);
    }

    /**
     * Realisasi kumulatif year-to-date satu IKU, dijumlahkan dari SELURUH periode
     * (bulan) berstatus terverifikasi/disetujui, dari triwulan I sampai
     * $triwulanSampai, pada tahun yang sama — dipakai sebagai pembilang "Capaian
     * Kumulatif" (lihat sheet resmi: kolom "Realisasi Kumulatif" TW III sudah
     * mencakup TW I+II+III, bukan cuma realisasi TW III sendiri).
     *
     * $kecualikanPeriodeId membuang satu periode dari sisi TERSIMPAN-nya — dipakai
     * VerifikasiCapaian supaya periode yang SEDANG diverifikasi (realisasinya belum
     * tentu tersimpan/status kegiatannya belum "diverifikasi") bisa ditambahkan
     * terpisah dari nilai yang sedang diketik live, tanpa dihitung dobel.
     */
    public static function realisasiKumulatif(int $ikuId, int $tahun, int $triwulanSampai, ?int $kecualikanPeriodeId = null): float
    {
        $periodeIdsTerverifikasi = Kegiatan::where('iku_id', $ikuId)
            ->whereHas('periode', fn ($q) => $q->where('tahun', $tahun)->where('triwulan', '<=', $triwulanSampai))
            ->whereIn('status_dokumen', [Kegiatan::STATUS_DIVERIFIKASI, Kegiatan::STATUS_DISETUJUI])
            ->pluck('periode_id')
            ->unique();

        if ($kecualikanPeriodeId !== null) {
            $periodeIdsTerverifikasi = $periodeIdsTerverifikasi->reject(fn ($id) => $id === $kecualikanPeriodeId);
        }

        if ($periodeIdsTerverifikasi->isEmpty()) {
            return 0.0;
        }

        return (float) static::where('iku_id', $ikuId)->whereIn('periode_id', $periodeIdsTerverifikasi)->sum('realisasi');
    }

    /**
     * Semua kegiatan pendukung IKU pada periode yang sama (RF-37) — kegiatan tidak
     * disimpan ulang teksnya di sini, cukup ditarik otomatis lewat iku_id+periode_id.
     */
    public function kegiatanList(): HasMany
    {
        return $this->hasMany(Kegiatan::class, 'iku_id', 'iku_id')
            ->where('periode_id', $this->periode_id);
    }
}
