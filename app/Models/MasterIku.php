<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class MasterIku extends Model
{
    use HasFactory;

    protected $table = 'master_iku';

    protected $fillable = [
        'kode',
        'indikator',
        'tim',
        'sasaran',
        'dasar_hitung',
        'basis_data',
        'satuan',
        'metode_capaian',
        'formula_capaian',
        'jenis_periode',
        'deskripsi_x',
        'deskripsi_y',
    ];

    /**
     * Alokasi Target/Realisasi diisi langsung sebagai angka (perilaku lama, default).
     */
    public const METODE_LANGSUNG = 'langsung';

    /**
     * Alokasi Target/Realisasi diisi lewat Pembilang (X)/Penyebut (Y) mentah per
     * triwulan, persentasenya dihitung otomatis X÷Y×100 — sesuai Kertas Kerja
     * Pengukuran Kinerja Triwulanan resmi untuk IKU bertipe %. Lihat
     * App\Models\CapaianTahunan::alokasiKumulatif()/realisasiKumulatif().
     */
    public const METODE_RASIO = 'rasio';

    public const SATUAN_PERSEN = 'Persen';

    public const SATUAN_POIN = 'Poin';

    public function pakaiRasio(): bool
    {
        return $this->metode_capaian === self::METODE_RASIO;
    }

    /**
     * Satuan SELALU mengikuti Metode Perhitungan (Rasio -> Persen, Langsung -> Poin)
     * -- dipaksakan di sini (bukan dibiarkan pilihan bebas terpisah) supaya tidak
     * bisa lagi terjadi kombinasi ganjil seperti IKU 'langsung' bersatuan "Persen"
     * yang sebelumnya ditemukan di data (mis. Indeks Pelayanan Publik, Nilai SAKIP
     * -- keduanya sebenarnya "Poin" per Kertas Kerja resmi, cuma salah input manual).
     * Dipasang lewat event Eloquent (bukan cuma disetel manual di tiap pemanggil)
     * supaya berlaku di SEMUA jalur tulis: form Master IKU, dropdown Jenis Nilai di
     * Target Tahunan (App\Livewire\TargetTahunan), maupun impor Excel.
     */
    protected static function booted(): void
    {
        static::saving(function (self $iku) {
            $iku->satuan = $iku->metode_capaian === self::METODE_RASIO
                ? self::SATUAN_PERSEN
                : self::SATUAN_POIN;
        });

        // Kolom lama master_iku.tim (satu string, dulu SATU-SATUNYA tempat tim
        // penanggung jawab tersimpan) dipertahankan sebagai jalur tulis ringkas untuk
        // pemanggil yang belum/tidak perlu memilih banyak tim sekaligus (mis. import
        // Excel App\Services\MasterIkuImportValidator, fixture pengujian) -- begitu
        // kolom ini disertakan/berubah pada satu penyimpanan, isinya (boleh dipisah
        // koma/titik-koma untuk lebih dari satu nama) otomatis disinkronkan jadi
        // baris-baris iku_tim (lihat timList()/namaTimList()), yang SEKARANG jadi
        // sumber kebenaran untuk penanggungJawabOtomatis()/daftarTimGabungan().
        // Jalur tulis BARU (App\Livewire\PenugasanIku::tambahTim()/hapusTim(),
        // App\Livewire\MasterIku multi-pilih tim) menulis LANGSUNG ke iku_tim tanpa
        // lewat kolom ini sama sekali -- aman, tidak saling menimpa, karena hook ini
        // hanya jalan kalau atribut 'tim' benar-benar ikut disimpan.
        static::saved(function (self $iku) {
            // isDirty() (BUKAN wasChanged()) -- pada event 'saved', syncOriginal()
            // belum sempat dipanggil untuk INSERT (Eloquent hanya memanggil
            // syncChanges() di performUpdate(), tidak di performInsert(), jadi
            // wasChanged() SELALU false setelah create()) — isDirty() tetap
            // membandingkan ke $original SEBELUM disinkronkan, benar untuk create()
            // MAUPUN update().
            if (! $iku->isDirty('tim')) {
                return;
            }

            $daftarTim = collect(preg_split('/[,;]/', (string) $iku->tim))
                ->map(fn ($t) => trim($t))
                ->filter()
                ->unique();

            $iku->timList()->whereNotIn('tim', $daftarTim)->delete();

            foreach ($daftarTim as $tim) {
                IkuTim::firstOrCreate(['iku_id' => $iku->id, 'tim' => $tim]);
            }

            static::lupakanCache();
        });
    }

    /**
     * Klasifikasi murni informasional, sesuai kolom "Jenis (Triwulanan atau Tahunan)"
     * Kertas Kerja resmi — ditampilkan di Daftar Master IKU (lihat pakaiTriwulanan()
     * dipakai di master-iku.blade.php), TIDAK mempengaruhi rumus Capaian Kinerja
     * apa pun: Capaian Terhadap Target Triwulanan (Rumus 2.3) & Setahun (Rumus 2.4)
     * tetap dihitung untuk SEMUA IKU apa pun nilai kolom ini.
     */
    public const JENIS_PERIODE_TRIWULANAN = 'triwulanan';

    public const JENIS_PERIODE_TAHUNAN = 'tahunan';

    public function pakaiTriwulanan(): bool
    {
        return $this->jenis_periode === self::JENIS_PERIODE_TRIWULANAN;
    }

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
     * @return Collection<int, self>
     */
    public static function daftarUrutKode()
    {
        return Cache::remember('master-iku-dropdown', 3600, fn () => static::orderBy('kode')->get());
    }

    public static function lupakanCache(): void
    {
        Cache::forget('master-iku-dropdown');
        Cache::forget('master-iku-tim-gabungan');
    }

    /**
     * Daftar nama tim gabungan — tim penanggung jawab di Master IKU (master_iku.tim)
     * digabung keanggotaan tim pengguna (user_tim.tim), tanpa duplikat &amp; terurut.
     * Dipakai untuk dropdown/saran "pilih tim" di beberapa tempat (Master IKU, PIC
     * Tindak Lanjut di Isian Kegiatan &amp; Verifikasi) supaya sumber tim yang sudah
     * ada di struktur organisasi selalu konsisten, tidak diketik ulang tiap tempat.
     *
     * Di-cache 5 menit (dilupakan lewat lupakanCache() tiap kali Master IKU
     * disimpan/dihapus/diimpor) — dipakai di render() PengisianKegiatan/
     * VerifikasiCapaian yang jalan di SETIAP aksi Livewire (termasuk yang sepele
     * seperti addBlock()), jadi tanpa cache ini tiap klik membayar 2 query DB
     * remote hanya untuk daftar saran dropdown yang nyaris tidak pernah berubah.
     * Perubahan keanggotaan tim (user_tim) di luar jalur Master IKU tidak
     * memicu lupakanCache() secara eksplisit — TTL 5 menit cukup sebagai jaring
     * pengaman, tidak fatal bila sedikit basi untuk sekadar daftar saran.
     *
     * @return list<string>
     */
    public static function daftarTimGabungan(): array
    {
        return Cache::remember('master-iku-tim-gabungan', 300, fn () => IkuTim::distinct()->pluck('tim')
            ->merge(UserTim::pluck('tim'))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all());
    }

    /**
     * Tim penanggung jawab IKU ini -- SATU IKU boleh punya LEBIH DARI SATU tim
     * (lihat App\Models\IkuTim). Sumber kebenaran untuk penanggungJawabOtomatis()
     * di bawah, menggantikan kolom lama master_iku.tim (masih ada, disinkronkan
     * otomatis ke sini lewat booted(), lihat komentarnya).
     */
    public function timList(): HasMany
    {
        return $this->hasMany(IkuTim::class, 'iku_id');
    }

    /**
     * @return list<string>
     */
    public function namaTimList(): array
    {
        return $this->relationLoaded('timList')
            ? $this->timList->pluck('tim')->all()
            : $this->timList()->pluck('tim')->all();
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

    public function bagianKustomPoin(): HasMany
    {
        return $this->hasMany(BagianKustomPoin::class, 'iku_id');
    }

    /**
     * Daftar item Rincian N (lihat App\Models\RincianN) milik IKU ini, SELURUH
     * tahun -- pemanggil yang butuh satu tahun tertentu tetap perlu ->where('tahun', ...)
     * sendiri (mis. App\Livewire\TargetTahunan/VerifikasiCapaian).
     */
    public function rincianN(): HasMany
    {
        return $this->hasMany(RincianN::class, 'iku_id');
    }

    /**
     * Label relasi yang masih terisi untuk IKU ini — dipakai untuk memperingatkan
     * SEBELUM Tim SAKIP mencoba menghapus (bukan cuma menangkap galat setelah gagal),
     * karena kegiatan/kendala-solusi/evaluasi-RTL/poin bagian kustom sengaja dikunci
     * restrictOnDelete ke master_iku (lihat migrasinya) supaya riwayat isian lama
     * tidak ikut hilang diam-diam kalau Master IKU-nya dihapus. Kosong berarti aman
     * dihapus.
     *
     * @return list<string>
     */
    public function relasiYangMenghalangiHapus(): array
    {
        $labelPerRelasi = [
            'kegiatan' => 'kegiatan',
            'kendalaSolusi' => 'kendala & solusi',
            'rtlEvaluasi' => 'evaluasi RTL',
            'bagianKustomPoin' => 'poin bagian kustom',
        ];

        return collect($labelPerRelasi)
            ->filter(fn ($label, $relasi) => $this->{$relasi}()->exists())
            ->values()
            ->all();
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
     * dihitung dari keanggotaan tim (user_tim.tim ada di antara namaTimList() IKU
     * ini, BOLEH lebih dari satu tim -- lihat timList()), BUKAN disimpan tersendiri,
     * supaya selalu ikut berubah begitu keanggotaan tim berubah — dikurangi anggota
     * yang sengaja dikecualikan (lihat pengecualianOtomatis()) untuk IKU ini saja.
     *
     * @return Collection<int, User>
     */
    public function penanggungJawabOtomatis()
    {
        $namaTim = $this->namaTimList();

        if ($namaTim === []) {
            return collect();
        }

        $dikecualikan = $this->pengecualianOtomatis()->pluck('user_id');

        return User::whereHas('timList', fn ($q) => $q->whereIn('tim', $namaTim))
            ->whereNotIn('id', $dikecualikan)
            ->get();
    }

    /**
     * Gabungan penanggung jawab otomatis (via tim) + manual, tanpa duplikat.
     *
     * @return Collection<int, User>
     */
    public function semuaPenanggungJawab()
    {
        $manual = $this->penugasanManual()->with('user')->get()->pluck('user');

        return $this->penanggungJawabOtomatis()->concat($manual)->unique('id')->values();
    }
}
