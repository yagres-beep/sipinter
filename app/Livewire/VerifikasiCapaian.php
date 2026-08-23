<?php

namespace App\Livewire;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\CapaianTahunan;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi;
use App\Models\RtlEvaluasi;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Tim SAKIP — Detail Verifikasi per IKU+periode (RF-36 s.d. RF-40a).
 *
 * Beroperasi pada satu Capaian (satu IKU pada satu periode), bukan satu Kegiatan,
 * karena satu IKU boleh punya banyak kegiatan (RF-19) yang berbagi satu set angka
 * capaian dan diverifikasi bersamaan. Berkas yang diperiksa di sini mencakup bukti
 * capaian (per kegiatan) dan bukti realisasi RTL triwulan sebelumnya sekaligus
 * (RF-39) — kendala-solusi tidak lagi punya bukti dukung sendiri. Tim SAKIP juga dapat mengoreksi langsung
 * teks yang diketik ketua tim (uraian kegiatan, kendala, solusi, realisasi RTL) bila
 * ada salah ketik — koreksi disimpan bersamaan saat "Verifikasi Selesai" ditekan.
 */
class VerifikasiCapaian extends Component
{
    public Capaian $capaian;

    public ?string $analisis_capaian = null;

    /**
     * Nilai form Target Tahunan + Alokasi/Realisasi Triwulanan — diikat lewat
     * wire:model (properti FLAT, bukan atribut model relasi, karena Livewire di sini
     * tidak mendukung wire:model langsung ke atribut model — "Can't set model
     * properties directly"), disinkronkan ke CapaianTahunan lewat
     * capaianTahunanTerkini() sebelum ditampilkan/disimpan. Tim SAKIP cukup mengisi
     * ini SEKALI per tahun, tidak diketik ulang tiap bulan seperti Target PK/Target
     * TW lama.
     */
    public $target_tahunan = null;

    /**
     * Pembilang (X)/Penyebut (Y) mentah Target Tahunan — HANYA dipakai bila
     * MasterIku::pakaiRasio(), sebagai pengganti $target_tahunan di atas. Pasangan
     * X/Y TERPISAH dari x_alokasi_tw1..4/y_alokasi_tw1..4 di bawah (Target Tahunan
     * bukan sama dengan Alokasi Target TW IV — keduanya diisi independen oleh Tim
     * SAKIP, lihat CapaianTahunan::targetTahunan()).
     */
    public $x_target = null;

    public $y_target = null;

    public $alokasi_tw1 = null;

    public $alokasi_tw2 = null;

    public $alokasi_tw3 = null;

    public $alokasi_tw4 = null;

    public $realisasi_tw1 = null;

    public $realisasi_tw2 = null;

    public $realisasi_tw3 = null;

    public $realisasi_tw4 = null;

    /**
     * Pembilang (X)/Penyebut (Y) mentah kumulatif per triwulan — HANYA dipakai bila
     * MasterIku::pakaiRasio() (IKU bertipe %), sebagai pengganti alokasi_tw1..4 /
     * realisasi_tw1..4 di atas (lihat CapaianTahunan::alokasiKumulatif()). Untuk IKU
     * bertipe Non % kedelapan properti ini tetap ada tapi tidak pernah dibaca/tampil.
     */
    public $x_alokasi_tw1 = null;

    public $x_alokasi_tw2 = null;

    public $x_alokasi_tw3 = null;

    public $x_alokasi_tw4 = null;

    public $y_alokasi_tw1 = null;

    public $y_alokasi_tw2 = null;

    public $y_alokasi_tw3 = null;

    public $y_alokasi_tw4 = null;

    public $x_realisasi_tw1 = null;

    public $x_realisasi_tw2 = null;

    public $x_realisasi_tw3 = null;

    public $x_realisasi_tw4 = null;

    public $y_realisasi_tw1 = null;

    public $y_realisasi_tw2 = null;

    public $y_realisasi_tw3 = null;

    public $y_realisasi_tw4 = null;

    /**
     * Catatan per berkas, dikunci pada id berkas.
     *
     * @var array<int, string|null>
     */
    public array $catatanBerkas = [];

    /**
     * Alasan Tim SAKIP membuka kembali isian yang sudah "disetujui" — opsional,
     * dipakai oleh bukaKembali().
     */
    public string $catatanBukaKembali = '';

    /**
     * Koreksi teks kegiatan (uraian_kegiatan), dikunci pada id kegiatan.
     *
     * @var array<int, string>
     */
    public array $koreksiKegiatan = [];

    /**
     * Koreksi teks kendala &amp; solusi, dikunci pada id kendala_solusi.
     *
     * @var array<int, array{kendala: string, solusi: ?string}>
     */
    public array $koreksiKendala = [];

    /**
     * Catatan penolakan per pasangan kendala &amp; solusi, dikunci pada id
     * kendala_solusi — pola sama persis dengan $catatanBerkas.
     *
     * @var array<int, string|null>
     */
    public array $catatanKendala = [];

    /**
     * Koreksi teks realisasi RTL triwulan sebelumnya, dikunci pada id rtl_evaluasi.
     *
     * @var array<int, string>
     */
    public array $koreksiRtlRealisasi = [];

    /**
     * Cache dalam satu siklus request (di-reset otomatis tiap request baru) — DB
     * remote (Supabase, Seoul) makan ~400ms per query, dan tiap koleksi ini dipakai
     * ulang di banyak tempat (mount, render, verifikasiSelesai, kembalikanKeKetuaTim)
     * dalam request yang sama; tanpa cache ini query yang sama terulang berkali-kali.
     */
    protected ?\Illuminate\Support\Collection $cacheKegiatanList = null;

    protected ?\Illuminate\Support\Collection $cacheKendalaSolusiList = null;

    protected ?\Illuminate\Support\Collection $cacheRtlSebelumnya = null;

    protected ?\Illuminate\Support\Collection $cacheBerkasKegiatan = null;

    protected ?\Illuminate\Support\Collection $cacheBagianKustomList = null;

    protected ?\Illuminate\Support\Collection $cacheBerkasBagianKustom = null;

    /**
     * TIDAK public dengan sengaja (beda dari $capaian) — Livewire di sini tidak bisa
     * menghidrasi ulang instance CapaianTahunan yang BELUM tersimpan (firstOrNew)
     * lewat siklus request-ke-request, jadi cukup dimuat ulang tiap request lewat
     * capaianTahunanTerkini() dan disimpan di cache ini SELAMA satu request saja
     * (pola sama seperti cacheKegiatanList dkk. di atas).
     */
    protected ?CapaianTahunan $cacheCapaianTahunan = null;

    public function mount(Capaian $capaian): void
    {
        $this->capaian = $capaian->load(['masterIku', 'periode']);

        $this->analisis_capaian = $capaian->analisis_capaian;

        $capaianTahunan = $this->capaianTahunanTersimpan();
        $this->target_tahunan = $capaianTahunan->target_tahunan;
        $this->x_target = $capaianTahunan->x_target;
        $this->y_target = $capaianTahunan->y_target;
        $this->alokasi_tw1 = $capaianTahunan->alokasi_tw1;
        $this->alokasi_tw2 = $capaianTahunan->alokasi_tw2;
        $this->alokasi_tw3 = $capaianTahunan->alokasi_tw3;
        $this->alokasi_tw4 = $capaianTahunan->alokasi_tw4;
        $this->realisasi_tw1 = $capaianTahunan->realisasi_tw1;
        $this->realisasi_tw2 = $capaianTahunan->realisasi_tw2;
        $this->realisasi_tw3 = $capaianTahunan->realisasi_tw3;
        $this->realisasi_tw4 = $capaianTahunan->realisasi_tw4;
        $this->x_alokasi_tw1 = $capaianTahunan->x_alokasi_tw1;
        $this->x_alokasi_tw2 = $capaianTahunan->x_alokasi_tw2;
        $this->x_alokasi_tw3 = $capaianTahunan->x_alokasi_tw3;
        $this->x_alokasi_tw4 = $capaianTahunan->x_alokasi_tw4;
        $this->y_alokasi_tw1 = $capaianTahunan->y_alokasi_tw1;
        $this->y_alokasi_tw2 = $capaianTahunan->y_alokasi_tw2;
        $this->y_alokasi_tw3 = $capaianTahunan->y_alokasi_tw3;
        $this->y_alokasi_tw4 = $capaianTahunan->y_alokasi_tw4;
        $this->x_realisasi_tw1 = $capaianTahunan->x_realisasi_tw1;
        $this->x_realisasi_tw2 = $capaianTahunan->x_realisasi_tw2;
        $this->x_realisasi_tw3 = $capaianTahunan->x_realisasi_tw3;
        $this->x_realisasi_tw4 = $capaianTahunan->x_realisasi_tw4;
        $this->y_realisasi_tw1 = $capaianTahunan->y_realisasi_tw1;
        $this->y_realisasi_tw2 = $capaianTahunan->y_realisasi_tw2;
        $this->y_realisasi_tw3 = $capaianTahunan->y_realisasi_tw3;
        $this->y_realisasi_tw4 = $capaianTahunan->y_realisasi_tw4;

        foreach ($this->berkasList() as $berkas) {
            $this->catatanBerkas[$berkas->id] = $berkas->catatan;
        }

        foreach ($this->kegiatanList() as $kegiatan) {
            $this->koreksiKegiatan[$kegiatan->id] = $kegiatan->uraian_kegiatan;
        }

        foreach ($this->kendalaSolusiList() as $ks) {
            $this->koreksiKendala[$ks->id] = ['kendala' => $ks->kendala, 'solusi' => $ks->solusi];
            $this->catatanKendala[$ks->id] = $ks->catatan;
        }

        foreach ($this->rtlEvaluasiSebelumnya() as $poin) {
            $this->koreksiRtlRealisasi[$poin->id] = $poin->realisasi;
        }
    }

    /**
     * Seluruh kegiatan pendukung IKU pada periode ini (RF-37), ditarik otomatis
     * dari isian ketua tim — Tim SAKIP tidak perlu mengetik ulang.
     */
    public function kegiatanList()
    {
        return $this->cacheKegiatanList ??= Kegiatan::where('iku_id', $this->capaian->iku_id)
            ->where('periode_id', $this->capaian->periode_id)
            ->orderBy('id')
            ->get();
    }

    /**
     * Halaman ini bisa diakses untuk MELIHAT isian berstatus apa pun (RF-36 lama
     * hanya izinkan "diajukan" — sekarang VerifikasiList juga menampilkan riwayat
     * diverifikasi/dikembalikan/disetujui, jadi tautan "Lihat →" ke sini harus tetap
     * bisa dibuka), tapi MENGUBAHNYA (tandai berkas, "Verifikasi Selesai",
     * "Kembalikan ke Ketua Tim", "Simpan Sementara") tetap hanya boleh selama
     * Capaian-nya masih "diajukan" ATAU "sedang ditangani" — dicek di sini (dipakai
     * di view utk readonly/sembunyikan tombol) DAN di tiap method pengubah (defense
     * in depth, bukan cuma sembunyi UI).
     *
     * "sedang ditangani" TERMASUK di sini (bukan hanya "diajukan") supaya Tim SAKIP
     * bisa kembali melanjutkan menandai berkas setelah sebelumnya "Simpan Sementara"
     * — checkpoint itu HARUS tetap bisa disunting, bukan status akhir yang mengunci.
     *
     * Didasarkan pada Capaian::status (SATU status per IKU+bulan), bukan lagi
     * menyimpulkan dari status_dokumen tiap Kegiatan — supaya tidak pernah ada isian
     * yang "sebagian diajukan, sebagian bukan" secara ambigu di halaman ini.
     */
    public function bisaDiverifikasi(): bool
    {
        return in_array($this->capaian->status, [Capaian::STATUS_DIAJUKAN, Capaian::STATUS_SEDANG_DITANGANI], true);
    }

    /**
     * Setelah disetujui (masuk notula final), isian ini dikunci total — Ketua Tim
     * tidak bisa menambah kegiatan apa pun sampai Tim SAKIP membuka kembali secara
     * eksplisit lewat bukaKembali() di bawah, menariknya ke status "dikembalikan"
     * (kegiatan yang sudah disetujui sebelumnya tetap terkunci-edit, hanya kegiatan
     * BARU yang bisa ditambahkan Ketua Tim — lihat App\Livewire\PengisianKegiatan).
     */
    public function bisaDibukaKembali(): bool
    {
        return $this->capaian->status === Capaian::STATUS_DISETUJUI;
    }

    public function bukaKembali(): void
    {
        if (! $this->bisaDibukaKembali()) {
            return;
        }

        $this->capaian->catatStatus(Capaian::STATUS_DIKEMBALIKAN, auth()->user(), $this->catatanBukaKembali ?: null);

        session()->flash('status', 'Isian dibuka kembali — Ketua Tim sekarang bisa menambahkan kegiatan.');

        $this->redirectRoute('verifikasi.index');
    }

    public function kendalaSolusiList()
    {
        return $this->cacheKendalaSolusiList ??= KendalaSolusi::where('iku_id', $this->capaian->iku_id)
            ->where('periode_id', $this->capaian->periode_id)
            ->orderBy('id')
            ->get();
    }

    /**
     * Poin bagian kustom (mis. Manajemen Risiko) milik IKU+periode ini — ditarik
     * otomatis dari isian ketua tim, sama seperti kegiatan & kendala-solusi.
     */
    public function bagianKustomList()
    {
        return $this->cacheBagianKustomList ??= BagianKustomPoin::with('bagianKustom')
            ->where('iku_id', $this->capaian->iku_id)
            ->where('periode_id', $this->capaian->periode_id)
            ->orderBy('id')
            ->get();
    }

    /**
     * Poin RTL yang ditetapkan pada triwulan SEBELUMNYA untuk dilaksanakan pada
     * triwulan periode capaian ini (sama persis dengan sumber yang dipakai Ketua Tim
     * di Bagian 4 "Evaluasi RTL Triwulan Sebelumnya" — lihat PengisianKegiatan::
     * rtlTriwulanBerjalan()), untuk dicek kesesuaian realisasinya — tanpa skor
     * persentase (cukup periksa sesuai/tidak).
     */
    public function rtlEvaluasiSebelumnya()
    {
        if ($this->cacheRtlSebelumnya !== null) {
            return $this->cacheRtlSebelumnya;
        }

        $periode = $this->capaian->periode;

        return $this->cacheRtlSebelumnya = RtlEvaluasi::with('berkas')
            ->where('iku_id', $this->capaian->iku_id)
            ->whereHas('periode', fn ($q) => $q->where('tahun', $periode->tahun)->where('triwulan', $periode->triwulan))
            ->get();
    }

    /**
     * Seluruh bukti capaian milik kegiatan-kegiatan IKU+periode ini, dikelompokkan
     * per kegiatan DALAM SATU QUERY (bukan satu query per kegiatan) — dipakai baik
     * untuk tampilan per-kegiatan maupun validasi "semua berkas sudah ditandai".
     */
    protected function berkasKegiatanTerkelompok(): \Illuminate\Support\Collection
    {
        if ($this->cacheBerkasKegiatan !== null) {
            return $this->cacheBerkasKegiatan;
        }

        return $this->cacheBerkasKegiatan = Berkas::where('ref_type', Kegiatan::class)
            ->whereIn('ref_id', $this->kegiatanList()->pluck('id'))
            ->get()
            ->groupBy('ref_id');
    }

    protected function berkasBagianKustomTerkelompok(): \Illuminate\Support\Collection
    {
        if ($this->cacheBerkasBagianKustom !== null) {
            return $this->cacheBerkasBagianKustom;
        }

        return $this->cacheBerkasBagianKustom = Berkas::where('ref_type', BagianKustomPoin::class)
            ->whereIn('ref_id', $this->bagianKustomList()->pluck('id'))
            ->get()
            ->groupBy('ref_id');
    }

    /**
     * Bukti dukung milik satu poin bagian kustom tertentu.
     */
    public function berkasUntukBagianKustom(int $poinId)
    {
        return $this->berkasBagianKustomTerkelompok()->get($poinId, collect());
    }

    /**
     * Bukti capaian milik satu kegiatan tertentu — dipakai untuk mengelompokkan
     * berkas per kegiatan pada tampilan (RF-39).
     */
    public function berkasUntukKegiatan(int $kegiatanId)
    {
        return $this->berkasKegiatanTerkelompok()->get($kegiatanId, collect());
    }

    /**
     * Gabungan berkas lintas kegiatan (bukti capaian) dan evaluasi RTL (bukti
     * realisasi) milik IKU+periode ini (RF-39) — dipakai untuk validasi "semua
     * berkas sudah ditandai" tanpa peduli pengelompokan tampilan. Kendala-solusi
     * tidak lagi punya bukti dukung (RF-27 dicabut) — lihat App\Models\KendalaSolusi.
     */
    public function berkasList()
    {
        $berkasCapaian = $this->berkasKegiatanTerkelompok()->flatten();
        $berkasRtl = $this->rtlEvaluasiSebelumnya()->flatMap->berkas;
        $berkasBagianKustom = $this->berkasBagianKustomTerkelompok()->flatten();

        return $berkasCapaian->concat($berkasRtl)->concat($berkasBagianKustom)->sortBy('created_at')->values();
    }

    /**
     * Satu berkas boleh ditandai ulang Tim SAKIP hanya bila Capaian-nya masih
     * "diajukan" DAN — khusus bukti kegiatan — kegiatan pemiliknya sendiri belum
     * pernah diproses sebelumnya (masih "diajukan"). Berkas evaluasi RTL dan bagian
     * kustom belum punya status lifecycle sendiri (lihat catatan di
     * kegiatanBisaDikoreksi()), jadi cukup mengikuti status besar Capaian untuk saat ini.
     */
    public function berkasBisaDiverifikasi(int $berkasId): bool
    {
        if (! $this->bisaDiverifikasi()) {
            return false;
        }

        $berkas = $this->berkasList()->firstWhere('id', $berkasId);

        if (! $berkas || $berkas->ref_type !== Kegiatan::class) {
            return true;
        }

        $kegiatan = $this->kegiatanList()->firstWhere('id', $berkas->ref_id);

        return ! $kegiatan || $this->kegiatanBisaDikoreksi($kegiatan);
    }

    public function tandaiSesuai(int $berkasId): void
    {
        if (! $this->berkasBisaDiverifikasi($berkasId)) {
            return;
        }

        Berkas::whereKey($berkasId)->update([
            'status_verifikasi' => 'terverifikasi',
            'catatan' => $this->catatanBerkas[$berkasId] ?? null,
        ]);
    }

    public function tandaiTolak(int $berkasId): void
    {
        if (! $this->berkasBisaDiverifikasi($berkasId)) {
            return;
        }

        $catatan = trim((string) ($this->catatanBerkas[$berkasId] ?? ''));

        if ($catatan === '') {
            $this->addError('catatanBerkas.'.$berkasId, 'Catatan wajib diisi saat menandai berkas "Tidak Sesuai" — supaya Ketua Tim tahu apa yang perlu diperbaiki.');

            return;
        }

        Berkas::whereKey($berkasId)->update([
            'status_verifikasi' => 'ditolak',
            'catatan' => $catatan,
        ]);
    }

    /**
     * Pasangan kendala &amp; solusi boleh ditandai ulang Tim SAKIP hanya selagi
     * Capaian-nya masih "diajukan"/"sedang ditangani" — sama seperti berkasBisaDiverifikasi()
     * tapi tanpa nuansa per-kegiatan (kendala &amp; solusi tidak terikat status_dokumen
     * kegiatan tertentu, cuma iku_id+periode_id milik Capaian ini).
     */
    public function kendalaBisaDiverifikasi(int $kendalaId): bool
    {
        return $this->bisaDiverifikasi() && $this->kendalaSolusiList()->contains('id', $kendalaId);
    }

    public function tandaiKendalaSesuai(int $kendalaId): void
    {
        if (! $this->kendalaBisaDiverifikasi($kendalaId)) {
            return;
        }

        KendalaSolusi::whereKey($kendalaId)->update([
            'status_verifikasi' => 'terverifikasi',
            'catatan' => $this->catatanKendala[$kendalaId] ?? null,
        ]);
    }

    public function tandaiKendalaTolak(int $kendalaId): void
    {
        if (! $this->kendalaBisaDiverifikasi($kendalaId)) {
            return;
        }

        $catatan = trim((string) ($this->catatanKendala[$kendalaId] ?? ''));

        if ($catatan === '') {
            $this->addError('catatanKendala.'.$kendalaId, 'Catatan wajib diisi saat menandai pasangan ini "Tidak Sesuai" — supaya Ketua Tim tahu apa yang perlu diperbaiki.');

            return;
        }

        KendalaSolusi::whereKey($kendalaId)->update([
            'status_verifikasi' => 'ditolak',
            'catatan' => $catatan,
        ]);
    }

    protected function rules(): array
    {
        return [
            'analisis_capaian' => ['nullable', 'string'],
            'koreksiKegiatan.*' => ['required', 'string', 'max:1000'],
            'koreksiKendala.*.kendala' => ['required', 'string'],
            'koreksiKendala.*.solusi' => ['nullable', 'string'],
            'koreksiRtlRealisasi.*' => ['nullable', 'string'],
            'target_tahunan' => ['nullable', 'numeric', 'min:0'],
            'x_target' => ['nullable', 'numeric', 'min:0'],
            'y_target' => ['nullable', 'numeric', 'min:0'],
            'alokasi_tw1' => ['nullable', 'numeric', 'min:0'],
            'alokasi_tw2' => ['nullable', 'numeric', 'min:0'],
            'alokasi_tw3' => ['nullable', 'numeric', 'min:0'],
            'alokasi_tw4' => ['nullable', 'numeric', 'min:0'],
            'realisasi_tw1' => ['nullable', 'numeric', 'min:0'],
            'realisasi_tw2' => ['nullable', 'numeric', 'min:0'],
            'realisasi_tw3' => ['nullable', 'numeric', 'min:0'],
            'realisasi_tw4' => ['nullable', 'numeric', 'min:0'],
            'x_alokasi_tw1' => ['nullable', 'numeric', 'min:0'],
            'x_alokasi_tw2' => ['nullable', 'numeric', 'min:0'],
            'x_alokasi_tw3' => ['nullable', 'numeric', 'min:0'],
            'x_alokasi_tw4' => ['nullable', 'numeric', 'min:0'],
            'y_alokasi_tw1' => ['nullable', 'numeric', 'min:0'],
            'y_alokasi_tw2' => ['nullable', 'numeric', 'min:0'],
            'y_alokasi_tw3' => ['nullable', 'numeric', 'min:0'],
            'y_alokasi_tw4' => ['nullable', 'numeric', 'min:0'],
            'x_realisasi_tw1' => ['nullable', 'numeric', 'min:0'],
            'x_realisasi_tw2' => ['nullable', 'numeric', 'min:0'],
            'x_realisasi_tw3' => ['nullable', 'numeric', 'min:0'],
            'x_realisasi_tw4' => ['nullable', 'numeric', 'min:0'],
            'y_realisasi_tw1' => ['nullable', 'numeric', 'min:0'],
            'y_realisasi_tw2' => ['nullable', 'numeric', 'min:0'],
            'y_realisasi_tw3' => ['nullable', 'numeric', 'min:0'],
            'y_realisasi_tw4' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'analisis_capaian' => 'analisis capaian',
            'target_tahunan' => 'Target Tahunan',
            'x_target' => 'Pembilang (X) Target Tahunan',
            'y_target' => 'Penyebut (Y) Target Tahunan',
            'alokasi_tw1' => 'Alokasi Target TW I',
            'alokasi_tw2' => 'Alokasi Target TW II',
            'alokasi_tw3' => 'Alokasi Target TW III',
            'alokasi_tw4' => 'Alokasi Target TW IV',
            'realisasi_tw1' => 'Realisasi TW I',
            'realisasi_tw2' => 'Realisasi TW II',
            'realisasi_tw3' => 'Realisasi TW III',
            'realisasi_tw4' => 'Realisasi TW IV',
            'x_alokasi_tw1' => 'Pembilang (X) Alokasi TW I',
            'x_alokasi_tw2' => 'Pembilang (X) Alokasi TW II',
            'x_alokasi_tw3' => 'Pembilang (X) Alokasi TW III',
            'x_alokasi_tw4' => 'Pembilang (X) Alokasi TW IV',
            'y_alokasi_tw1' => 'Penyebut (Y) Alokasi TW I',
            'y_alokasi_tw2' => 'Penyebut (Y) Alokasi TW II',
            'y_alokasi_tw3' => 'Penyebut (Y) Alokasi TW III',
            'y_alokasi_tw4' => 'Penyebut (Y) Alokasi TW IV',
            'x_realisasi_tw1' => 'Pembilang (X) Realisasi TW I',
            'x_realisasi_tw2' => 'Pembilang (X) Realisasi TW II',
            'x_realisasi_tw3' => 'Pembilang (X) Realisasi TW III',
            'x_realisasi_tw4' => 'Pembilang (X) Realisasi TW IV',
            'y_realisasi_tw1' => 'Penyebut (Y) Realisasi TW I',
            'y_realisasi_tw2' => 'Penyebut (Y) Realisasi TW II',
            'y_realisasi_tw3' => 'Penyebut (Y) Realisasi TW III',
            'y_realisasi_tw4' => 'Penyebut (Y) Realisasi TW IV',
        ];
    }

    /**
     * Muat baris CapaianTahunan milik IKU+tahun ini APA ADANYA dari DB (sekali per
     * request, lihat $cacheCapaianTahunan) — TANPA menimpa nilainya dari properti
     * form flat. Dipakai HANYA di mount() untuk mengisi properti flat dari nilai
     * tersimpan; di luar mount() pakai capaianTahunanTerkini() di bawah.
     *
     * masterIku SENGAJA di-set langsung dari $this->capaian->masterIku (sudah
     * di-load di mount(), bukan lazy-load ulang) — dipakai oleh
     * alokasiKumulatif()/realisasiKumulatif() untuk cek MasterIku::pakaiRasio()
     * tanpa query tambahan.
     */
    protected function capaianTahunanTersimpan(): CapaianTahunan
    {
        if ($this->cacheCapaianTahunan !== null) {
            return $this->cacheCapaianTahunan;
        }

        $model = CapaianTahunan::firstOrNew([
            'iku_id' => $this->capaian->iku_id,
            'tahun' => $this->capaian->periode->tahun,
        ]);

        $model->setRelation('masterIku', $this->capaian->masterIku);

        return $this->cacheCapaianTahunan = $model;
    }

    /**
     * capaianTahunanTersimpan() disinkronkan dari properti form flat (fill(), BELUM
     * save()) — dipakai baik untuk ditampilkan (render(), supaya kolom
     * kumulatif/Capaian % di blade ikut bereaksi live saat diketik) maupun sesaat
     * sebelum disimpan (simpanPerubahan()/simpanSementara()/verifikasiSelesai()/
     * kembalikanKeKetuaTim()).
     *
     * HANYA kolom alokasi/realisasi (langsung MAUPUN rasio X/Y) milik TRIWULAN
     * periode Capaian ini SENDIRI yang disinkronkan — kolom TW lain SENGAJA
     * dibiarkan apa adanya dari capaianTahunanTersimpan() (tidak ikut fill()),
     * supaya nilainya tidak bisa tertimpa dari sesi verifikasi bulan lain (satu
     * CapaianTahunan dibagikan SELURUH bulan dalam satu IKU+tahun — lihat
     * App\Models\CapaianTahunan) — baik karena properti komponen ini kebetulan
     * masih menyimpan nilai TW lain dari load awal, maupun payload yang
     * dimanipulasi. Blade juga menyembunyikan/mengunci kolom TW lain sebagai
     * <input>, tapi pengecekan di sini tetap dipertahankan (defense in depth,
     * bukan cuma sembunyi UI — pola yang sama dipakai kegiatanBisaDikoreksi()).
     * target_tahunan/x_target/y_target TETAP disinkronkan tanpa syarat karena
     * keduanya bukan milik TW tertentu (sekali per tahun, lihat dokumentasi
     * $target_tahunan di atas).
     */
    protected function capaianTahunanTerkini(): CapaianTahunan
    {
        $model = $this->capaianTahunanTersimpan();
        $tw = (int) $this->capaian->periode->triwulan;

        $fill = [
            'target_tahunan' => $this->target_tahunan,
            'x_target' => $this->x_target,
            'y_target' => $this->y_target,
        ];

        foreach (['alokasi_tw', 'realisasi_tw', 'x_alokasi_tw', 'y_alokasi_tw', 'x_realisasi_tw', 'y_realisasi_tw'] as $prefix) {
            $fill["{$prefix}{$tw}"] = $this->{"{$prefix}{$tw}"};
        }

        $model->fill($fill);

        return $model;
    }

    /**
     * Satu kegiatan boleh disunting Tim SAKIP selagi statusnya SENDIRI masih
     * "diajukan" (belum pernah diverifikasi/disetujui pada batch sebelumnya) — TIDAK
     * LAGI bergantung pada status BESAR Capaian (bisaDiverifikasi() dicabut dari
     * sini), supaya Tim SAKIP tetap bisa mengoreksi kegiatan yang masih "diajukan"
     * walau Capaian-nya sendiri sudah "diverifikasi"/"disetujui" (mis. ada kegiatan
     * baru yang menyusul dan menarik status Capaian berubah lagi). Pengecekan
     * status_dokumen kegiatan itu sendiri TETAP dipertahankan sebagai pertahanan
     * berlapis: kegiatan yang sudah diproses pada batch sebelumnya tidak boleh
     * diam-diam ikut tersunting lagi hanya karena ada kegiatan BARU di Capaian yang
     * sama (lihat simpanKoreksiTeks()).
     */
    public function kegiatanBisaDikoreksi(Kegiatan $kegiatan): bool
    {
        return $kegiatan->status_dokumen === Kegiatan::STATUS_DIAJUKAN;
    }

    /**
     * Simpan koreksi teks (bila ada perubahan) ke kegiatan, kendala-solusi, dan
     * realisasi RTL — dipanggil bersamaan dengan verifikasi/pengembalian supaya
     * Tim SAKIP tidak perlu tombol simpan terpisah untuk tiap koreksi kecil.
     */
    protected function simpanKoreksiTeks(): void
    {
        foreach ($this->koreksiKegiatan as $id => $teks) {
            $kegiatan = $this->kegiatanList()->firstWhere('id', (int) $id);

            // Pertahanan berlapis (bukan cuma readonly di UI): kegiatan yang sudah
            // diverifikasi/disetujui pada batch SEBELUMNYA tidak boleh ikut tersunting
            // hanya karena ada kegiatan BARU yang membuat Capaian ini "diajukan" lagi.
            if (! $kegiatan || ! $this->kegiatanBisaDikoreksi($kegiatan)) {
                continue;
            }

            $kegiatan->update(['uraian_kegiatan' => $teks]);
        }

        foreach ($this->koreksiKendala as $id => $data) {
            KendalaSolusi::whereKey($id)->update([
                'kendala' => $data['kendala'],
                'solusi' => $data['solusi'] ?: null,
            ]);
        }

        foreach ($this->koreksiRtlRealisasi as $id => $teks) {
            if (filled($teks)) {
                RtlEvaluasi::whereKey($id)->update(['realisasi' => $teks]);
            }
        }
    }

    /**
     * Simpan Analisis Capaian + Target Tahunan/Alokasi/Realisasi Triwulanan + koreksi
     * teks TANPA mengubah Capaian::status — dipakai Tim SAKIP untuk mengedit isian
     * pada status APA PUN (draft/sedang ditangani/diajukan/diverifikasi/dikembalikan/
     * disetujui), bukan hanya saat "diajukan"/"sedang ditangani" seperti tombol
     * simpanSementara()/verifikasiSelesai()/kembalikanKeKetuaTim() di bawah (ketiga
     * method itu tetap dikhususkan untuk transisi status alur kerja).
     */
    public function simpanPerubahan(): void
    {
        $this->validate();

        DB::transaction(function () {
            $this->capaian->update(['analisis_capaian' => $this->analisis_capaian]);
            $this->capaianTahunanTerkini()->save();
            $this->simpanKoreksiTeks();
        });

        session()->flash('status', 'Perubahan disimpan.');
    }

    /**
     * Checkpoint sementara di tengah pemeriksaan — dipakai saat sebagian berkas
     * sudah ditandai Sesuai/Tidak Sesuai (dengan catatan) tapi Tim SAKIP belum
     * memutuskan hasil akhirnya. TIDAK mensyaratkan seluruh berkas sudah ditandai
     * (beda dari verifikasiSelesai()/kembalikanKeKetuaTim() di bawah) dan TIDAK
     * menyentuh status_dokumen kegiatan mana pun — isian tetap bisa dilanjutkan
     * Tim SAKIP kapan saja selagi masih "sedang ditangani" (lihat bisaDiverifikasi()).
     * Status hanya dicatat SEKALI ke Riwayat Status saat pertama kali beralih dari
     * "diajukan" — simpan sementara berikutnya tidak menambah baris riwayat baru
     * selama isian belum berpindah status lagi.
     */
    public function simpanSementara(): void
    {
        if (! $this->bisaDiverifikasi()) {
            return;
        }

        $this->validate();

        DB::transaction(function () {
            $this->capaian->update(['analisis_capaian' => $this->analisis_capaian]);
            $this->capaianTahunanTerkini()->save();
            $this->simpanKoreksiTeks();

            if ($this->capaian->status !== Capaian::STATUS_SEDANG_DITANGANI) {
                $this->capaian->catatStatus(Capaian::STATUS_SEDANG_DITANGANI, auth()->user());
            }
        });

        session()->flash('status', 'Progres pemeriksaan disimpan sementara — Anda bisa melanjutkan kapan saja.');
    }

    public function verifikasiSelesai(): void
    {
        if (! $this->bisaDiverifikasi()) {
            return;
        }

        $berkas = $this->berkasList();
        $kendala = $this->kendalaSolusiList();

        if ($berkas->contains(fn ($b) => $b->status_verifikasi === 'menunggu') || $kendala->contains(fn ($k) => $k->status_verifikasi === 'menunggu')) {
            $this->addError('berkas', 'Seluruh berkas dan pasangan kendala & solusi harus ditandai "Sesuai" atau "Tidak Sesuai" terlebih dahulu.');

            return;
        }

        if ($berkas->contains(fn ($b) => $b->status_verifikasi === 'ditolak') || $kendala->contains(fn ($k) => $k->status_verifikasi === 'ditolak')) {
            $this->addError('berkas', 'Terdapat berkas atau pasangan kendala & solusi yang ditolak — gunakan tombol "Kembalikan ke Ketua Tim", bukan "Verifikasi Selesai".');

            return;
        }

        $this->validate();

        try {
            DB::transaction(function () {
                $this->capaian->update(['analisis_capaian' => $this->analisis_capaian]);
                $this->capaianTahunanTerkini()->save();
                $this->simpanKoreksiTeks();

                foreach ($this->kegiatanList() as $kegiatan) {
                    if ($kegiatan->status_dokumen === Kegiatan::STATUS_DIAJUKAN) {
                        $kegiatan->verifikasi();
                    }
                }

                $this->capaian->catatStatus(Kegiatan::STATUS_DIVERIFIKASI, auth()->user());
            });
        } catch (InvalidStatusTransitionException $e) {
            $this->addError('berkas', $e->getMessage());

            return;
        }

        session()->flash('status', 'Verifikasi selesai. Isian ditandai "diverifikasi".');

        $this->redirectRoute('verifikasi.index');
    }

    /**
     * Kegiatan yang buktinya SENDIRI ditolak dikembalikan (dikembalikan →
     * Ketua Tim wajib perbaiki bukti itu), sedangkan kegiatan lain pada IKU+periode
     * yang sama namun buktinya sendiri sudah diterima ikut DIVERIFIKASI (bukan ikut
     * "dikembalikan" begitu saja) — supaya bukti yang sebenarnya sudah benar tidak
     * pernah tampil "✓ Sesuai" di bawah label kegiatan yang kelihatannya
     * "dikembalikan" (sumber kebingungan sebelumnya), dan Ketua Tim hanya perlu
     * membenahi kegiatan yang benar-benar bermasalah. Status BESAR Capaian tetap
     * "dikembalikan" karena isian periode ini belum selesai seluruhnya.
     */
    public function kembalikanKeKetuaTim(): void
    {
        if (! $this->bisaDiverifikasi()) {
            return;
        }

        $berkas = $this->berkasList();
        $kendala = $this->kendalaSolusiList();

        if ($berkas->contains(fn ($b) => $b->status_verifikasi === 'menunggu') || $kendala->contains(fn ($k) => $k->status_verifikasi === 'menunggu')) {
            $this->addError('berkas', 'Seluruh berkas dan pasangan kendala & solusi harus ditandai "Sesuai" atau "Tidak Sesuai" terlebih dahulu.');

            return;
        }

        $adaBerkasDitolak = $berkas->contains(fn ($b) => $b->status_verifikasi === 'ditolak');
        $adaKendalaDitolak = $kendala->contains(fn ($k) => $k->status_verifikasi === 'ditolak');

        if (! $adaBerkasDitolak && ! $adaKendalaDitolak) {
            $this->addError('berkas', 'Tandai minimal satu berkas atau pasangan kendala & solusi sebagai "Tidak Sesuai" beserta catatan sebelum mengembalikan isian.');

            return;
        }

        try {
            DB::transaction(function () {
                $this->capaian->update(['analisis_capaian' => $this->analisis_capaian]);
                $this->capaianTahunanTerkini()->save();
                $this->simpanKoreksiTeks();

                foreach ($this->kegiatanList() as $kegiatan) {
                    if ($kegiatan->status_dokumen !== Kegiatan::STATUS_DIAJUKAN) {
                        continue;
                    }

                    $berkasKegiatanIni = $this->berkasUntukKegiatan($kegiatan->id);
                    $buktiSendiriDitolak = $berkasKegiatanIni->contains(fn ($b) => $b->status_verifikasi === 'ditolak');

                    if ($buktiSendiriDitolak) {
                        $kegiatan->kembalikan();
                    } else {
                        $kegiatan->verifikasi();
                    }
                }

                // Catatan riwayat mengambil alasan penolakan dari berkas MAUPUN pasangan
                // kendala & solusi yang ditandai "Tolak" — sudah divalidasi minimal satu
                // ada (di salah satu keduanya) sebelum sampai di sini.
                $catatan = $this->berkasList()
                    ->where('status_verifikasi', 'ditolak')
                    ->pluck('catatan')
                    ->concat($this->kendalaSolusiList()->where('status_verifikasi', 'ditolak')->pluck('catatan'))
                    ->filter()
                    ->unique()
                    ->implode(' | ');

                $this->capaian->catatStatus(Kegiatan::STATUS_DIKEMBALIKAN, auth()->user(), $catatan ?: null);
            });
        } catch (InvalidStatusTransitionException $e) {
            $this->addError('berkas', $e->getMessage());

            return;
        }

        session()->flash('status', 'Isian dikembalikan ke Ketua Tim untuk diperbaiki.');

        $this->redirectRoute('verifikasi.index');
    }

    public function render()
    {
        $kegiatanList = $this->kegiatanList();
        $kendalaSolusiList = $this->kendalaSolusiList();

        $bagianKustomList = $this->bagianKustomList();

        return view('livewire.verifikasi-capaian', [
            'kegiatanList' => $kegiatanList,
            'kendalaSolusiList' => $kendalaSolusiList,
            'rtlSebelumnya' => $this->rtlEvaluasiSebelumnya(),
            'berkasPerKegiatan' => $kegiatanList->mapWithKeys(fn ($k) => [$k->id => $this->berkasUntukKegiatan($k->id)]),
            'bagianKustomList' => $bagianKustomList,
            'berkasPerBagianKustom' => $bagianKustomList->mapWithKeys(fn ($p) => [$p->id => $this->berkasUntukBagianKustom($p->id)]),
            'bisaDiverifikasi' => $this->bisaDiverifikasi(),
            'bisaDibukaKembali' => $this->bisaDibukaKembali(),
            'riwayatStatus' => $this->capaian->riwayatStatus()->with('user')->get(),
            'capaianTahunan' => $this->capaianTahunanTerkini(),
        ]);
    }
}
