<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class MasterIku extends Model
{
    use HasFactory;

    protected $table = 'master_iku';

    protected $fillable = [
        'kode',
        'indikator',
        'tim',
        'penanggung_jawab',
        'sasaran',
    ];

    /**
     * Bagian angka dari kode (mis. "1131" dari "IKU-1131") — dipakai di tabel Daftar
     * Master IKU supaya kolom Kode ringkas, tanpa mengubah nilai "kode" tersimpan
     * (tetap dipakai apa adanya di tempat lain: dropdown, ekspor, dsb).
     */
    protected function nomorKode(): Attribute
    {
        return Attribute::make(
            get: fn () => preg_replace('/^\D+/', '', $this->kode) ?: $this->kode,
        );
    }

    /**
     * Daftar Master IKU terurut kode, di-cache 1 jam — dipakai untuk dropdown pilihan
     * IKU (Isian Kegiatan, RTL, Kendala &amp; Solusi) yang di-render tiap request. DB
     * remote (Supabase, Seoul) makan ~400ms per query, dan daftar IKU jarang berubah
     * (hanya lewat halaman Master IKU) — lihat lupakanCache(), dipanggil di tiap
     * jalur tulis (save/delete/impor) supaya dropdown tidak pernah menampilkan data basi.
     * JANGAN dipakai di halaman Master IKU sendiri (App\Livewire\MasterIku) — halaman
     * itu tetap query langsung supaya perubahan sendiri selalu terlihat instan.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function daftarUrutKode()
    {
        return Cache::remember('master-iku-dropdown', 3600, fn () => static::orderBy('kode')->get());
    }

    public static function lupakanCache(): void
    {
        Cache::forget('master-iku-dropdown');
    }

    public function kegiatan(): HasMany
    {
        return $this->hasMany(Kegiatan::class, 'iku_id');
    }

    public function kendalaSolusi(): HasMany
    {
        return $this->hasMany(KendalaSolusi::class, 'iku_id');
    }

    public function rtlEvaluasi(): HasMany
    {
        return $this->hasMany(RtlEvaluasi::class, 'iku_id');
    }

    /**
     * Penugasan manual (override/tambahan) di luar penugasan otomatis via tim.
     */
    public function penugasanManual(): HasMany
    {
        return $this->hasMany(IkuPenugasan::class, 'iku_id');
    }

    /**
     * Anggota tim yang DIKECUALIKAN dari penanggung jawab otomatis IKU ini —
     * dipakai saat satu tim beranggotakan beberapa Ketua Tim tapi hanya sebagian
     * yang benar-benar bertanggung jawab atas IKU tertentu (RF: "hilangkan dari
     * pilihan tanpa mengeluarkan dari timnya"). Tidak mengubah keanggotaan tim.
     */
    public function pengecualianOtomatis(): HasMany
    {
        return $this->hasMany(IkuPengecualian::class, 'iku_id');
    }

    /**
     * Ketua Tim yang otomatis bertanggung jawab atas IKU ini (RF: "via tim") —
     * dihitung dari keanggotaan tim (user_tim.tim === master_iku.tim), BUKAN
     * disimpan tersendiri, supaya selalu ikut berubah begitu keanggotaan tim
     * berubah — dikurangi anggota yang sengaja dikecualikan (lihat
     * pengecualianOtomatis()) untuk IKU ini saja.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function penanggungJawabOtomatis()
    {
        $dikecualikan = $this->pengecualianOtomatis()->pluck('user_id');

        return User::whereHas('timList', fn ($q) => $q->where('tim', $this->tim))
            ->whereNotIn('id', $dikecualikan)
            ->get();
    }

    /**
     * Gabungan penanggung jawab otomatis (via tim) + manual, tanpa duplikat.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function semuaPenanggungJawab()
    {
        $manual = $this->penugasanManual()->with('user')->get()->pluck('user');

        return $this->penanggungJawabOtomatis()->concat($manual)->unique('id')->values();
    }
}
