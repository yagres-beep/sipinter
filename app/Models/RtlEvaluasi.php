<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class RtlEvaluasi extends Model
{
    use HasFactory;

    protected $table = 'rtl_evaluasi';

    public const STATUS_COCOK = 'cocok';

    public const STATUS_PERLU_DITINJAU = 'perlu_ditinjau';

    public const STATUS_TIDAK_COCOK = 'tidak_cocok';

    /**
     * Status dokumen RTL Baru (rencana triwulan berikutnya) — TERPISAH dari
     * status_verifikasi (hasil review Tim SAKIP, hanya relevan setelah diajukan).
     * Lihat PengisianKegiatan::simpanBagianIsian() & rtlTriwulanBerikutnyaSudahAda().
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_DIAJUKAN = 'diajukan';

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
        'status_dokumen',
        'realisasi',
        'status_cocok',
        'status_verifikasi',
        'catatan',
        'catatan_bukti_dihapus',
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
     * Kegiatan (Bagian 2 Isian Kegiatan) yang melaksanakan poin RTL ini — tertaut lewat
     * kegiatan.rtl_evaluasi_id saat uraian kegiatan dipilih dari dropdown RTL. Kegiatan
     * yang dipisah ke beberapa tahapan tersimpan sebagai beberapa baris dengan
     * rtl_evaluasi_id yang sama, jadi semua tahapannya ikut terbawa di sini.
     */
    public function kegiatan(): HasMany
    {
        return $this->hasMany(Kegiatan::class, 'rtl_evaluasi_id')->orderBy('id');
    }

    /**
     * Bukti capaian milik kegiatan yang tertaut ke poin ini (lihat kegiatan()) — inilah
     * bukti realisasi RTL yang "tersinkron" dari Bagian 2: baris berkas & berkas Drive
     * yang SAMA (ref tetap ke Kegiatan), bukan salinan, jadi diunggah/dihapus sekali
     * saja dan tampil di kedua bagian.
     */
    public function buktiKegiatan(): HasManyThrough
    {
        return $this->hasManyThrough(Berkas::class, Kegiatan::class, 'rtl_evaluasi_id', 'ref_id')
            ->where('berkas.ref_type', Kegiatan::class);
    }

    /**
     * Seluruh bukti capaian kegiatan tertaut (lihat buktiKegiatan()) dari relasi
     * kegiatan.berkas, berpasangan dengan kegiatannya supaya tampilan bisa menyebut
     * tahapan/folder asal tiap berkas.
     *
     * @return \Illuminate\Support\Collection<int, array{berkas: Berkas, kegiatan: Kegiatan}>
     */
    public function buktiDariKegiatan()
    {
        return $this->kegiatan
            ->flatMap(fn (Kegiatan $k) => $k->berkas->map(fn (Berkas $b) => ['berkas' => $b, 'kegiatan' => $k]))
            ->values();
    }

    /**
     * Letak salinan fisik bukti kegiatan tertaut di folder Evaluasi-RTL poin ini
     * (lihat App\Services\SinkronBuktiRtlService) — hanya letak, bukan status.
     */
    public function salinanBukti(): HasMany
    {
        return $this->hasMany(SalinanBuktiRtl::class);
    }

    public const KESESUAIAN_KOSONG = 'kosong';

    public const KESESUAIAN_SESUAI = 'sesuai';

    public const KESESUAIAN_TIDAK_SESUAI = 'tidak_sesuai';

    public const KESESUAIAN_MENUNGGU = 'menunggu';

    /**
     * Ringkasan kesesuaian sekumpulan bukti realisasi, dibaca langsung dari
     * status_verifikasi tiap berkas (satu sumber status — bukti dari kegiatan memakai
     * status berkas kegiatannya): "sesuai" hanya bila SEMUA sudah Sesuai, "tidak
     * sesuai" bila ada satu saja yang Tidak Sesuai, selain itu "menunggu". TERPISAH
     * dari status_verifikasi poin RTL (penilaian realisasi oleh Tim SAKIP) — bukti
     * Sesuai tidak pernah otomatis membuat RTL-nya Sesuai.
     *
     * @param  iterable<Berkas>  $berkas
     */
    public static function kesesuaianBukti(iterable $berkas): string
    {
        $status = collect($berkas)->pluck('status_verifikasi');

        return match (true) {
            $status->isEmpty() => self::KESESUAIAN_KOSONG,
            $status->contains('ditolak') => self::KESESUAIAN_TIDAK_SESUAI,
            $status->every(fn ($s) => $s === 'terverifikasi') => self::KESESUAIAN_SESUAI,
            default => self::KESESUAIAN_MENUNGGU,
        };
    }

    /**
     * "Dievaluasi" berarti minimal satu bukti realisasi sudah ada — diunggah langsung
     * di Evaluasi RTL, ATAU bukti capaian kegiatan yang tertaut ke poin ini (lihat
     * buktiKegiatan()), supaya bukti yang sama tidak perlu diunggah dua kali. Tidak
     * lagi berdasarkan teks realisasi/status kecocokan.
     */
    public function sudahDievaluasi(): bool
    {
        $adaBuktiSendiri = $this->relationLoaded('berkas') ? $this->berkas->isNotEmpty() : $this->berkas()->exists();

        if ($adaBuktiSendiri) {
            return true;
        }

        return $this->relationLoaded('kegiatan')
            ? $this->kegiatan->contains(fn (Kegiatan $k) => $k->relationLoaded('berkas') ? $k->berkas->isNotEmpty() : $k->berkas()->exists())
            : $this->buktiKegiatan()->exists();
    }

    /**
     * Padanan query dari sudahDievaluasi() — poin yang sudah punya bukti realisasi
     * (sendiri atau dari kegiatan tertaut). Teks realisasi lama (sebelum alur diganti
     * jadi unggah bukti saja) tetap ikut dihitung supaya data lama tidak tiba-tiba
     * hilang dari dasbor/daftar verifikasi.
     */
    public function scopeYangSudahDievaluasi(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereHas('berkas')
            ->orWhereHas('buktiKegiatan')
            ->orWhere(fn ($q2) => $q2->whereNotNull('realisasi')->where('realisasi', '!=', '')));
    }

    public function scopeYangBelumDievaluasi(Builder $query): Builder
    {
        return $query->whereDoesntHave('berkas')
            ->whereDoesntHave('buktiKegiatan')
            ->where(fn ($q) => $q->whereNull('realisasi')->orWhere('realisasi', ''));
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

    /**
     * Rincian jumlah poin RTL per status_verifikasi (menunggu/terverifikasi/ditolak)
     * dalam satu Capaian (IKU+bulan) — sama persis polanya dengan
     * Kegiatan::rincianStatus(), dipakai di tabel dasbor supaya badge status besar
     * Capaian (mis. "Dikembalikan") tetap bisa ditelusuri sampai ke bukti RTL yang
     * jadi penyebabnya, bukan hanya bukti Kegiatan — sebelumnya rincian di dasbor
     * hanya menghitung Kegiatan::status_dokumen, jadi Capaian bisa tampil
     * "Dikembalikan" padahal semua Kegiatan-nya sendiri sudah "Diverifikasi" (poin
     * penolakan sebenarnya ada di realisasi RTL, tidak kelihatan sama sekali).
     *
     * @param  \Illuminate\Support\Collection<int, self>  $rtlSatuIkuPeriode
     * @return \Illuminate\Support\Collection<string, int>
     */
    public static function rincianStatusVerifikasi($rtlSatuIkuPeriode)
    {
        $jumlahPerStatus = $rtlSatuIkuPeriode->countBy('status_verifikasi');

        return collect([
            'menunggu',
            'terverifikasi',
            'ditolak',
        ])->mapWithKeys(fn ($status) => [$status => $jumlahPerStatus->get($status, 0)])
            ->filter(fn ($jumlah) => $jumlah > 0);
    }
}
