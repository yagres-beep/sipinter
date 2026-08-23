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

    /**
     * Status alur verifikasi SATU-SATUNYA yang ditampilkan ke pengguna (dasbor, daftar
     * verifikasi, detail) — nilai sama persis dengan Kegiatan::STATUS_* (kecuali
     * SEDANG_DITANGANI, khusus milik Capaian) tapi didefinisikan terpisah supaya
     * Capaian tidak bergantung pada class Kegiatan hanya untuk konstanta.
     * Satu Capaian TIDAK PERNAH punya lebih dari satu status pada satu waktu; lihat
     * catatStatus() untuk satu-satunya jalur yang boleh mengubahnya.
     *
     * Ringkasan alur: draft (Ketua Tim simpan draf) -> diajukan (Ketua Tim ajukan) ->
     * sedang ditangani (Tim SAKIP sudah mulai menandai sebagian bukti/kendala-solusi
     * & simpan sementara) -> diverifikasi (SEMUA bukti & kendala-solusi "Sesuai") atau
     * dikembalikan (ADA yang "Tidak Sesuai") -> disetujui (masuk Notula final).
     */
    public const STATUS_DRAFT = 'draft';

    /**
     * Checkpoint sementara: Tim SAKIP sudah mulai menandai sebagian berkas/kendala &
     * solusi Sesuai/Tidak Sesuai (lewat "Simpan Sementara") tapi belum memutuskan
     * hasil akhirnya (Verifikasi Selesai atau Kembalikan ke Ketua Tim) — lihat
     * App\Livewire\VerifikasiCapaian::simpanSementara(). Tetap dianggap "bisa
     * diverifikasi" (isian masih bisa disunting Tim SAKIP), BEDA dari STATUS_DRAFT
     * yang berarti isian milik Ketua Tim yang belum pernah diajukan sama sekali.
     */
    public const STATUS_SEDANG_DITANGANI = 'sedang_ditangani';

    public const STATUS_DIAJUKAN = 'diajukan';

    public const STATUS_DIVERIFIKASI = 'diverifikasi';

    public const STATUS_DIKEMBALIKAN = 'dikembalikan';

    public const STATUS_DISETUJUI = 'disetujui';

    protected $fillable = [
        'iku_id',
        'periode_id',
        'status',
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
     * Catat satu perubahan status ke riwayat (RF baru: jejak audit siapa/status/waktu)
     * SEKALIGUS memutakhirkan kolom status Capaian ini sendiri — inilah SATU-SATUNYA
     * jalur yang boleh mengubah status, supaya kolom `status` dan riwayatnya tidak
     * pernah berbeda. Dipanggil sekali per aksi alur verifikasi (ajukan/verifikasi/
     * kembalikan/setujui/buka kembali), bukan per kegiatan, supaya satu aksi yang
     * menyentuh banyak kegiatan sekaligus (RF-37/RF-38 — satu IKU boleh punya banyak
     * kegiatan) tetap tercatat sebagai SATU poin riwayat & SATU status, bukan duplikat
     * atau campur-aduk.
     */
    public function catatStatus(string $status, ?User $user, ?string $catatan = null): RiwayatStatusCapaian
    {
        $this->update(['status' => $status]);

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
     * Predikat SAKIP dari Nilai SAKIP (angka dari Inspektorat, App\Models\NilaiSakip)
     * — sesuai Kertas Kerja Pengukuran Kinerja Triwulanan, dipakai untuk menentukan
     * % koreksi pada rumus Penilaian Kinerja Organisasi (PKO) di bawah.
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
     * % koreksi Nilai Akhir Capaian PK berdasarkan Predikat SAKIP — dipakai SATU
     * angka untuk SELURUH IKU (organisasi), bukan per-IKU.
     */
    public static function koreksiPredikat(string $predikat): float
    {
        return match ($predikat) {
            'AA/Sangat Memuaskan', 'A/Memuaskan' => 0.0,
            'BB/Sangat Baik' => 10.0,
            'B/Baik' => 15.0,
            'CC/Cukup (Memadai)' => 20.0,
            default => 30.0, // C/Kurang, D/Sangat Kurang
        };
    }

    /**
     * Normalisasi Capaian PK (1) — Capaian Setahun TW IV satu IKU (CapaianTahunan::
     * capaianSetahun(4)) dibatasi maksimal PengaturanCapaian::batas_normalisasi_pko
     * (default 110, TERPISAH dari batas_maksimal_persen 120 yang dipakai
     * hitungPersentase()) — null bila belum ada Capaian Setahun TW IV sama sekali
     * (strip "-", bukan dianggap 0).
     */
    public static function normalisasiCapaianPk(?float $capaianSetahunTw4): ?float
    {
        if ($capaianSetahunTw4 === null) {
            return null;
        }

        return min($capaianSetahunTw4, (float) PengaturanCapaian::ambil()->batas_normalisasi_pko);
    }

    /**
     * Nilai Akhir Capaian PK satu IKU = Normalisasi (1) × (100% − Koreksi Predikat
     * SAKIP (2)) — dijumlah/dirata-ratakan seluruh IKU di App\Livewire\DasborCapaian::
     * hitungPko() untuk menghasilkan Total/Rata-rata Capaian PK (NKO) organisasi.
     */
    public static function nilaiAkhirCapaianPk(?float $normalisasi, float $koreksiPersen): ?float
    {
        return $normalisasi === null ? null : round($normalisasi * (1 - $koreksiPersen / 100), 2);
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
