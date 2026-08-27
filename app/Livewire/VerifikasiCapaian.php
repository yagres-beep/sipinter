<?php

namespace App\Livewire;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\CapaianTahunan;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi;
use App\Models\RincianN;
use App\Models\RincianOutput;
use App\Models\RtlEvaluasi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
     * Penjelasan/pembahasan lainnya (opsional) — tampil di Bagian I Notula pada baris
     * "Penjelasan/pembahasan lainnya" milik IKU ini, terpisah dari Analisis Capaian
     * Kinerja di atas.
     */
    public ?string $catatan = null;

    /**
     * Rincian Output (RO) per kegiatan — RF baru: satu Kegiatan boleh punya BANYAK RO
     * (mis. satu kegiatan survei menghasilkan beberapa publikasi berbeda), lihat
     * App\Models\RincianOutput. Dikunci [kegiatan_id][kunci_baris] => data baris;
     * $kunci_baris adalah id RincianOutput asli (baris sudah tersimpan) ATAU kunci
     * sementara "baru-..." (baris baru dari tambahRo(), belum pernah disimpan).
     * TERPISAH dari $koreksiKegiatan (uraian_kegiatan bebas-teks yang ditulis
     * petugas) karena penamaan RO resmi tidak selalu sama dengan uraian kegiatan.
     * Hanya terisi Tim SAKIP saat IKU-nya BELUM punya realisasi triwulan berjalan
     * (lihat NotulaBagian1DocxService::isiSatuIku(): tabel RO hanya tampil bila
     * $rekap['realisasi'] masih kosong), tapi tidak wajib.
     *
     * @var array<int, array<string, array{id: int|null, uraian: string|null, volume_ro: string|null, progres_persen: string|null}>>
     */
    public array $rincianOutput = [];

    /**
     * Centang item Rincian N (App\Models\RincianN) yang direalisasikan PADA
     * TRIWULAN INI -- dikunci pada id RincianN, HANYA memuat item yang
     * triwulan_realisasi-nya masih kosong ATAU sudah triwulan ini sendiri (item
     * yang direalisasikan triwulan LAIN tidak pernah masuk sini, ditampilkan
     * read-only di blade). Menggantikan input manual x_realisasi_tw{n} untuk
     * SEMUA IKU bermetode Rasio (MasterIku::pakaiRasio()) -- lihat
     * updatedRincianNPilih()/syncRincianN().
     *
     * @var array<int, bool>
     */
    public array $rincianNPilih = [];

    /**
     * Nilai form Alokasi/Realisasi Triwulanan — diikat lewat wire:model (properti
     * FLAT, bukan atribut model relasi, karena Livewire di sini tidak mendukung
     * wire:model langsung ke atribut model — "Can't set model properties
     * directly"), disinkronkan ke CapaianTahunan lewat capaianTahunanTerkini()
     * sebelum ditampilkan/disimpan.
     *
     * Target Tahunan (target_tahunan/x_target/y_target) TIDAK LAGI diedit dari sini
     * — sekali per tahun per IKU, diisi terpusat di App\Livewire\TargetTahunan
     * (tab "Target Tahunan", Data Master & Konfigurasi) supaya tidak perlu diketik
     * ulang tiap sesi verifikasi bulanan. Di sini nilainya dibaca APA ADANYA dari
     * capaianTahunanTersimpan() (lihat blade, ditampilkan readonly + tautan).
     */
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
     * Catatan penolakan uraian kegiatan, dikunci pada id kegiatan — pola sama
     * persis dengan $catatanBerkas/$catatanKendala.
     *
     * @var array<int, string|null>
     */
    public array $catatanUraian = [];

    /**
     * Catatan penolakan teks poin Bagian Kustom, dikunci pada id bagian_kustom_poin.
     *
     * @var array<int, string|null>
     */
    public array $catatanBagianKustom = [];

    /**
     * Catatan penolakan teks realisasi RTL, dikunci pada id rtl_evaluasi.
     *
     * @var array<int, string|null>
     */
    public array $catatanRtl = [];

    /**
     * Cache dalam satu siklus request (di-reset otomatis tiap request baru) — DB
     * remote (Supabase, Seoul) makan ~400ms per query, dan tiap koleksi ini dipakai
     * ulang di banyak tempat (mount, render, verifikasiSelesai, kembalikanKeKetuaTim)
     * dalam request yang sama; tanpa cache ini query yang sama terulang berkali-kali.
     */
    protected ?Collection $cacheKegiatanList = null;

    protected ?Collection $cacheKendalaSolusiList = null;

    protected ?Collection $cacheRtlSebelumnya = null;

    protected ?Collection $cacheBerkasKegiatan = null;

    protected ?Collection $cacheBagianKustomList = null;

    protected ?Collection $cacheBerkasBagianKustom = null;

    protected ?Collection $cacheRincianNList = null;

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
        $this->catatan = $capaian->catatan;

        $capaianTahunan = $this->capaianTahunanTersimpan();
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
            $this->catatanUraian[$kegiatan->id] = $kegiatan->catatan_uraian;
            $this->rincianOutput[$kegiatan->id] = $kegiatan->rincianOutput
                ->mapWithKeys(fn ($ro) => [(string) $ro->id => [
                    'id' => $ro->id,
                    'uraian' => $ro->uraian,
                    'volume_ro' => $ro->volume_ro,
                    'progres_persen' => $ro->progres_persen,
                ]])
                ->all();
        }

        foreach ($this->kendalaSolusiList() as $ks) {
            $this->koreksiKendala[$ks->id] = ['kendala' => $ks->kendala, 'solusi' => $ks->solusi];
            $this->catatanKendala[$ks->id] = $ks->catatan;
        }

        foreach ($this->bagianKustomList() as $poin) {
            $this->catatanBagianKustom[$poin->id] = $poin->catatan;
        }

        foreach ($this->rtlEvaluasiSebelumnya() as $poin) {
            $this->koreksiRtlRealisasi[$poin->id] = $poin->realisasi;
            $this->catatanRtl[$poin->id] = $poin->catatan;
        }

        $tw = (int) $this->capaian->periode->triwulan;
        foreach ($this->rincianNList() as $n) {
            if ($n->triwulan_realisasi === $tw) {
                $this->rincianNPilih[$n->id] = true;
            }
        }
    }

    /**
     * Item Rincian N (App\Models\RincianN) milik IKU+tahun periode ini -- HANYA
     * bermakna untuk IKU bermetode Rasio (MasterIku::pakaiRasio()), kosong
     * (collection kosong) untuk IKU lain. Di-cache satu request, sama seperti
     * koleksi lain di kelas ini.
     */
    public function rincianNList(): Collection
    {
        if ($this->cacheRincianNList !== null) {
            return $this->cacheRincianNList;
        }

        if (! $this->capaian->masterIku->pakaiRasio()) {
            return $this->cacheRincianNList = collect();
        }

        return $this->cacheRincianNList = RincianN::where('iku_id', $this->capaian->iku_id)
            ->where('tahun', $this->capaian->periode->tahun)
            ->orderBy('id')
            ->get();
    }

    /**
     * Item yang BOLEH dipilih pada triwulan berjalan (belum direalisasikan
     * triwulan mana pun, atau sudah direalisasikan triwulan ini sendiri) --
     * dipakai blade untuk render checklist $rincianNPilih.
     */
    public function rincianNBisaDipilih(): Collection
    {
        $tw = (int) $this->capaian->periode->triwulan;

        return $this->rincianNList()->filter(fn (RincianN $n) => $n->triwulan_realisasi === null || $n->triwulan_realisasi === $tw)->values();
    }

    /**
     * Item yang SUDAH direalisasikan pada triwulan LAIN (sebelum triwulan
     * berjalan) -- ditampilkan read-only di blade, tidak pernah masuk
     * $rincianNPilih maupun bisa diubah dari sesi verifikasi ini.
     */
    public function rincianNTerkunci(): Collection
    {
        $tw = (int) $this->capaian->periode->triwulan;

        return $this->rincianNList()->filter(fn (RincianN $n) => $n->triwulan_realisasi !== null && $n->triwulan_realisasi !== $tw)->values();
    }

    /**
     * Live-recompute x_realisasi_tw{TW aktif} dari jumlah item yang SEDANG
     * dicentang -- dipanggil otomatis Livewire tiap $rincianNPilih berubah (mirip
     * efek wire:model.live pada input manual), supaya kumulatif &amp; Capaian %
     * di blade langsung bereaksi tanpa perlu menyimpan dulu.
     */
    public function updatedRincianNPilih(): void
    {
        $tw = (int) $this->capaian->periode->triwulan;
        $this->{"x_realisasi_tw{$tw}"} = collect($this->rincianNPilih)->filter()->count();
    }

    /**
     * Tulis pilihan $rincianNPilih ke DB (set/lepas triwulan_realisasi item
     * terkait) lalu samakan x_realisasi_tw{TW aktif} dengan jumlah SESUNGGUHNYA
     * di DB setelahnya -- dipanggil dari tiap jalur simpan (bukan hanya
     * updatedRincianNPilih() yang cuma live-preview di memori), TIDAK melakukan
     * apa pun bila IKU ini tidak MasterIku::pakaiRasio().
     */
    protected function syncRincianN(): void
    {
        if (! $this->capaian->masterIku->pakaiRasio()) {
            return;
        }

        $tw = (int) $this->capaian->periode->triwulan;

        foreach ($this->rincianNBisaDipilih() as $n) {
            $dicentang = (bool) ($this->rincianNPilih[$n->id] ?? false);

            if ($dicentang && $n->triwulan_realisasi === null) {
                $n->update(['triwulan_realisasi' => $tw]);
            } elseif (! $dicentang && $n->triwulan_realisasi === $tw) {
                $n->update(['triwulan_realisasi' => null]);
            }
        }

        $this->cacheRincianNList = null;
        $this->{"x_realisasi_tw{$tw}"} = RincianN::where('iku_id', $this->capaian->iku_id)
            ->where('tahun', $this->capaian->periode->tahun)
            ->where('triwulan_realisasi', $tw)
            ->count();
    }

    /**
     * Seluruh kegiatan pendukung IKU pada periode ini (RF-37), ditarik otomatis
     * dari isian ketua tim — Tim SAKIP tidak perlu mengetik ulang.
     */
    /**
     * status_dokumen "draft" SENGAJA dikecualikan — kegiatan itu baru tersimpan lewat
     * "Simpan Draft" Ketua Tim (lihat App\Livewire\PengisianKegiatan::simpanDraft()),
     * belum pernah diajukan ke Tim SAKIP sama sekali. Tanpa filter ini, kegiatan draft
     * yang ditambahkan Ketua Tim pada IKU+periode yang SUDAH terlanjur diajukan
     * (lewat kegiatan lain) akan ikut bocor tampil di sini walau belum "dikirim".
     */
    public function kegiatanList()
    {
        return $this->cacheKegiatanList ??= Kegiatan::with('rincianOutput')
            ->where('iku_id', $this->capaian->iku_id)
            ->where('periode_id', $this->capaian->periode_id)
            ->where('status_dokumen', '!=', Kegiatan::STATUS_DRAFT)
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
        $this->dispatch('notify', type: 'success', message: 'Isian dibuka kembali — Ketua Tim sekarang bisa menambahkan kegiatan.');

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
    protected function berkasKegiatanTerkelompok(): Collection
    {
        if ($this->cacheBerkasKegiatan !== null) {
            return $this->cacheBerkasKegiatan;
        }

        return $this->cacheBerkasKegiatan = Berkas::where('ref_type', Kegiatan::class)
            ->whereIn('ref_id', $this->kegiatanList()->pluck('id'))
            ->get()
            ->groupBy('ref_id');
    }

    protected function berkasBagianKustomTerkelompok(): Collection
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

    /**
     * Catatan SELALU dikosongkan di sini — berkas "Sesuai" tidak pernah punya catatan
     * (kolom itu khusus alasan penolakan, lihat label "Catatan (wajib bila tidak
     * sesuai)" di blade). $this->catatanBerkas[$berkasId] ikut dikosongkan (bukan
     * cuma kolom DB) supaya textarea-nya di form langsung ikut kosong pada render
     * berikutnya — wire:model membaca dari properti ini, bukan dari kolom DB.
     */
    public function tandaiSesuai(int $berkasId): void
    {
        if (! $this->berkasBisaDiverifikasi($berkasId)) {
            return;
        }

        $this->catatanBerkas[$berkasId] = null;

        Berkas::whereKey($berkasId)->update([
            'status_verifikasi' => 'terverifikasi',
            'catatan' => null,
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
            $this->dispatch('notify', type: 'error', message: 'Catatan wajib diisi saat menandai berkas "Tidak Sesuai".');

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

    /**
     * Catatan SELALU dikosongkan di sini — sama seperti tandaiSesuai() untuk berkas
     * di atas, lihat penjelasan di sana.
     */
    public function tandaiKendalaSesuai(int $kendalaId): void
    {
        if (! $this->kendalaBisaDiverifikasi($kendalaId)) {
            return;
        }

        $this->catatanKendala[$kendalaId] = null;

        KendalaSolusi::whereKey($kendalaId)->update([
            'status_verifikasi' => 'terverifikasi',
            'catatan' => null,
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
            $this->dispatch('notify', type: 'error', message: 'Catatan wajib diisi saat menandai pasangan kendala & solusi "Tidak Sesuai".');

            return;
        }

        KendalaSolusi::whereKey($kendalaId)->update([
            'status_verifikasi' => 'ditolak',
            'catatan' => $catatan,
        ]);
    }

    /**
     * Uraian kegiatan boleh ditandai ulang Tim SAKIP dengan syarat sama persis
     * dengan bukti kegiatan (kegiatanBisaDikoreksi()) — keduanya bagian dari
     * kegiatan yang sama, jadi mengikuti status_dokumen kegiatan itu sendiri, bukan
     * hanya status besar Capaian.
     */
    public function uraianBisaDiverifikasi(int $kegiatanId): bool
    {
        $kegiatan = $this->kegiatanList()->firstWhere('id', $kegiatanId);

        return $kegiatan && $this->kegiatanBisaDikoreksi($kegiatan);
    }

    public function tandaiUraianSesuai(int $kegiatanId): void
    {
        if (! $this->uraianBisaDiverifikasi($kegiatanId)) {
            return;
        }

        $this->catatanUraian[$kegiatanId] = null;

        Kegiatan::whereKey($kegiatanId)->update([
            'status_verifikasi_uraian' => 'terverifikasi',
            'catatan_uraian' => null,
        ]);
    }

    public function tandaiUraianTolak(int $kegiatanId): void
    {
        if (! $this->uraianBisaDiverifikasi($kegiatanId)) {
            return;
        }

        $catatan = trim((string) ($this->catatanUraian[$kegiatanId] ?? ''));

        if ($catatan === '') {
            $this->addError('catatanUraian.'.$kegiatanId, 'Catatan wajib diisi saat menandai uraian kegiatan "Tidak Sesuai" — supaya Ketua Tim tahu apa yang perlu diperbaiki.');
            $this->dispatch('notify', type: 'error', message: 'Catatan wajib diisi saat menandai uraian kegiatan "Tidak Sesuai".');

            return;
        }

        Kegiatan::whereKey($kegiatanId)->update([
            'status_verifikasi_uraian' => 'ditolak',
            'catatan_uraian' => $catatan,
        ]);
    }

    /**
     * Poin Bagian Kustom boleh ditandai ulang Tim SAKIP hanya selagi Capaian-nya
     * masih "diajukan"/"sedang ditangani" — sama seperti kendalaBisaDiverifikasi(),
     * poin ini tidak punya status_dokumen sendiri seperti kegiatan.
     */
    public function bagianKustomBisaDiverifikasi(int $poinId): bool
    {
        return $this->bisaDiverifikasi() && $this->bagianKustomList()->contains('id', $poinId);
    }

    public function tandaiBagianKustomSesuai(int $poinId): void
    {
        if (! $this->bagianKustomBisaDiverifikasi($poinId)) {
            return;
        }

        $this->catatanBagianKustom[$poinId] = null;

        BagianKustomPoin::whereKey($poinId)->update([
            'status_verifikasi' => 'terverifikasi',
            'catatan' => null,
        ]);
    }

    public function tandaiBagianKustomTolak(int $poinId): void
    {
        if (! $this->bagianKustomBisaDiverifikasi($poinId)) {
            return;
        }

        $catatan = trim((string) ($this->catatanBagianKustom[$poinId] ?? ''));

        if ($catatan === '') {
            $this->addError('catatanBagianKustom.'.$poinId, 'Catatan wajib diisi saat menandai poin ini "Tidak Sesuai" — supaya Ketua Tim tahu apa yang perlu diperbaiki.');
            $this->dispatch('notify', type: 'error', message: 'Catatan wajib diisi saat menandai poin Bagian Kustom "Tidak Sesuai".');

            return;
        }

        BagianKustomPoin::whereKey($poinId)->update([
            'status_verifikasi' => 'ditolak',
            'catatan' => $catatan,
        ]);
    }

    /**
     * Realisasi RTL hanya bisa diverifikasi bila sudah dilaporkan Ketua Tim
     * (realisasi tidak kosong) — poin yang belum dilaporkan ("— belum dilaporkan —"
     * di blade) tidak punya apa pun untuk diperiksa.
     */
    public function rtlBisaDiverifikasi(int $rtlId): bool
    {
        if (! $this->bisaDiverifikasi()) {
            return false;
        }

        $poin = $this->rtlEvaluasiSebelumnya()->firstWhere('id', $rtlId);

        return $poin && filled($poin->realisasi);
    }

    public function tandaiRtlSesuai(int $rtlId): void
    {
        if (! $this->rtlBisaDiverifikasi($rtlId)) {
            return;
        }

        $this->catatanRtl[$rtlId] = null;

        RtlEvaluasi::whereKey($rtlId)->update([
            'status_verifikasi' => 'terverifikasi',
            'catatan' => null,
        ]);
    }

    public function tandaiRtlTolak(int $rtlId): void
    {
        if (! $this->rtlBisaDiverifikasi($rtlId)) {
            return;
        }

        $catatan = trim((string) ($this->catatanRtl[$rtlId] ?? ''));

        if ($catatan === '') {
            $this->addError('catatanRtl.'.$rtlId, 'Catatan wajib diisi saat menandai realisasi RTL ini "Tidak Sesuai" — supaya Ketua Tim tahu apa yang perlu diperbaiki.');
            $this->dispatch('notify', type: 'error', message: 'Catatan wajib diisi saat menandai realisasi RTL "Tidak Sesuai".');

            return;
        }

        RtlEvaluasi::whereKey($rtlId)->update([
            'status_verifikasi' => 'ditolak',
            'catatan' => $catatan,
        ]);
    }

    protected function rules(): array
    {
        return [
            'analisis_capaian' => ['nullable', 'string'],
            'catatan' => ['nullable', 'string'],
            'koreksiKegiatan.*' => ['required', 'string', 'max:1000'],
            'rincianOutput.*.*.uraian' => ['nullable', 'string', 'max:255'],
            'rincianOutput.*.*.volume_ro' => ['nullable', 'string', 'max:255'],
            'rincianOutput.*.*.progres_persen' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'koreksiKendala.*.kendala' => ['required', 'string'],
            'koreksiKendala.*.solusi' => ['nullable', 'string'],
            'koreksiRtlRealisasi.*' => ['nullable', 'string'],
            'rincianNPilih.*' => ['boolean'],
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
            'catatan' => 'penjelasan/pembahasan lainnya',
            'rincianOutput.*.*.uraian' => 'rincian output',
            'rincianOutput.*.*.volume_ro' => 'realisasi volume RO',
            'rincianOutput.*.*.progres_persen' => 'progres pelaksanaan kegiatan (%)',
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
     * target_tahunan/x_target/y_target SENGAJA TIDAK ikut fill() di sini —
     * keduanya sudah tidak diedit dari halaman ini (lihat App\Livewire\
     * TargetTahunan), jadi nilainya dibiarkan apa adanya dari
     * capaianTahunanTersimpan().
     */
    protected function capaianTahunanTerkini(): CapaianTahunan
    {
        $model = $this->capaianTahunanTersimpan();
        $tw = (int) $this->capaian->periode->triwulan;

        $fill = [];

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
     * Tambah satu baris RO kosong untuk kegiatan ini — hanya tersimpan ke DB nanti
     * saat "Simpan"/"Verifikasi Selesai"/dst. ditekan (lihat simpanRincianOutputKegiatan()),
     * sama seperti pola koreksi teks lain di komponen ini. Kunci sementara "baru-..."
     * dipakai supaya baris ini bisa dihapus lagi (hapusRo()) sebelum sempat tersimpan
     * tanpa perlu id asli.
     */
    public function tambahRo(int $kegiatanId): void
    {
        $kegiatan = $this->kegiatanList()->firstWhere('id', $kegiatanId);
        if (! $kegiatan || ! $this->kegiatanBisaDikoreksi($kegiatan)) {
            return;
        }

        $kunciBaru = 'baru-'.(string) Str::uuid();
        $this->rincianOutput[$kegiatanId][$kunciBaru] = ['id' => null, 'uraian' => null, 'volume_ro' => null, 'progres_persen' => null];
    }

    /**
     * Hapus satu baris RO — baris yang SUDAH tersimpan (punya id asli) dihapus dari
     * DB seketika (sama seperti tombol aksi langsung lain di komponen ini, mis.
     * tandaiSesuai()); baris yang baru ditambahkan lewat tambahRo() dan belum
     * disimpan cukup dibuang dari state lokal.
     */
    public function hapusRo(int $kegiatanId, string $kunci): void
    {
        $kegiatan = $this->kegiatanList()->firstWhere('id', $kegiatanId);
        if (! $kegiatan || ! $this->kegiatanBisaDikoreksi($kegiatan)) {
            return;
        }

        $id = $this->rincianOutput[$kegiatanId][$kunci]['id'] ?? null;
        if ($id) {
            RincianOutput::whereKey($id)->delete();
        }

        unset($this->rincianOutput[$kegiatanId][$kunci]);
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
            $this->simpanRincianOutputKegiatan($kegiatan);
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
     * Tulis seluruh baris RO satu kegiatan ke DB: baris yang KOSONG (ketiga kolom
     * kosong) dihapus bila sudah tersimpan sebelumnya, atau dilewati bila memang baru
     * ditambahkan lewat tambahRo() dan tidak pernah diisi; baris yang terisi
     * dibuat/diperbarui sesuai ada/tidaknya id asli.
     */
    protected function simpanRincianOutputKegiatan(Kegiatan $kegiatan): void
    {
        foreach ($this->rincianOutput[$kegiatan->id] ?? [] as $kunci => $baris) {
            $terisi = filled($baris['uraian'] ?? null) || filled($baris['volume_ro'] ?? null) || filled($baris['progres_persen'] ?? null);

            if (! $terisi) {
                if (! empty($baris['id'])) {
                    RincianOutput::whereKey($baris['id'])->delete();
                    $this->rincianOutput[$kegiatan->id][$kunci]['id'] = null;
                }

                continue;
            }

            $payload = [
                'uraian' => $baris['uraian'] ?: null,
                'volume_ro' => $baris['volume_ro'] ?: null,
                'progres_persen' => filled($baris['progres_persen'] ?? null) ? $baris['progres_persen'] : null,
            ];

            if (! empty($baris['id'])) {
                RincianOutput::whereKey($baris['id'])->update($payload);
            } else {
                // Simpan id baris yang BARU dibuat kembali ke state komponen --
                // tanpa ini, panggilan berikutnya (mis. hapusRo() atau simpan ulang)
                // tidak tahu baris ini sudah tersimpan dan bisa membuat duplikat/gagal
                // menghapusnya dari DB (dikunci masih pakai kunci sementara "baru-...").
                $ro = $kegiatan->rincianOutput()->create($payload);
                $this->rincianOutput[$kegiatan->id][$kunci]['id'] = $ro->id;
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
            $this->capaian->update(['analisis_capaian' => $this->analisis_capaian, 'catatan' => $this->catatan]);
            $this->syncRincianN();
            $this->capaianTahunanTerkini()->save();
            $this->simpanKoreksiTeks();
        });

        session()->flash('status', 'Perubahan disimpan.');
        $this->dispatch('notify', type: 'success', message: 'Perubahan disimpan.');
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
            $this->capaian->update(['analisis_capaian' => $this->analisis_capaian, 'catatan' => $this->catatan]);
            $this->syncRincianN();
            $this->capaianTahunanTerkini()->save();
            $this->simpanKoreksiTeks();

            if ($this->capaian->status !== Capaian::STATUS_SEDANG_DITANGANI) {
                $this->capaian->catatStatus(Capaian::STATUS_SEDANG_DITANGANI, auth()->user());
            }
        });

        session()->flash('status', 'Progres pemeriksaan disimpan sementara — Anda bisa melanjutkan kapan saja.');
        $this->dispatch('notify', type: 'success', message: 'Progres pemeriksaan disimpan sementara.');
    }

    public function verifikasiSelesai(): void
    {
        if (! $this->bisaDiverifikasi()) {
            return;
        }

        $berkas = $this->berkasList();
        $kendala = $this->kendalaSolusiList();
        $kegiatanList = $this->kegiatanList();
        $bagianKustom = $this->bagianKustomList();
        $rtl = $this->rtlEvaluasiSebelumnya()->filter(fn ($p) => filled($p->realisasi));

        if (
            $berkas->contains(fn ($b) => $b->status_verifikasi === 'menunggu')
            || $kendala->contains(fn ($k) => $k->status_verifikasi === 'menunggu')
            || $kegiatanList->contains(fn ($k) => $k->status_verifikasi_uraian === 'menunggu')
            || $bagianKustom->contains(fn ($p) => $p->status_verifikasi === 'menunggu')
            || $rtl->contains(fn ($p) => $p->status_verifikasi === 'menunggu')
        ) {
            $this->addError('berkas', 'Seluruh isian (berkas, uraian kegiatan, kendala & solusi, Bagian Kustom, dan realisasi RTL) harus ditandai "Sesuai" atau "Tidak Sesuai" terlebih dahulu.');
            $this->dispatch('notify', type: 'error', message: 'Seluruh isian harus ditandai terlebih dahulu.');

            return;
        }

        if (
            $berkas->contains(fn ($b) => $b->status_verifikasi === 'ditolak')
            || $kendala->contains(fn ($k) => $k->status_verifikasi === 'ditolak')
            || $kegiatanList->contains(fn ($k) => $k->status_verifikasi_uraian === 'ditolak')
            || $bagianKustom->contains(fn ($p) => $p->status_verifikasi === 'ditolak')
            || $rtl->contains(fn ($p) => $p->status_verifikasi === 'ditolak')
        ) {
            $this->addError('berkas', 'Terdapat isian yang ditolak — gunakan tombol "Kembalikan ke Ketua Tim", bukan "Verifikasi Selesai".');
            $this->dispatch('notify', type: 'error', message: 'Ada isian yang ditolak — gunakan "Kembalikan ke Ketua Tim".');

            return;
        }

        $this->validate();

        try {
            DB::transaction(function () {
                $this->capaian->update(['analisis_capaian' => $this->analisis_capaian, 'catatan' => $this->catatan]);
                $this->syncRincianN();
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
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        session()->flash('status', 'Verifikasi selesai. Isian ditandai "diverifikasi".');
        $this->dispatch('notify', type: 'success', message: 'Verifikasi selesai. Isian ditandai "diverifikasi".');

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
        $kegiatanList = $this->kegiatanList();
        $bagianKustom = $this->bagianKustomList();
        $rtl = $this->rtlEvaluasiSebelumnya()->filter(fn ($p) => filled($p->realisasi));

        if (
            $berkas->contains(fn ($b) => $b->status_verifikasi === 'menunggu')
            || $kendala->contains(fn ($k) => $k->status_verifikasi === 'menunggu')
            || $kegiatanList->contains(fn ($k) => $k->status_verifikasi_uraian === 'menunggu')
            || $bagianKustom->contains(fn ($p) => $p->status_verifikasi === 'menunggu')
            || $rtl->contains(fn ($p) => $p->status_verifikasi === 'menunggu')
        ) {
            $this->addError('berkas', 'Seluruh isian (berkas, uraian kegiatan, kendala & solusi, Bagian Kustom, dan realisasi RTL) harus ditandai "Sesuai" atau "Tidak Sesuai" terlebih dahulu.');
            $this->dispatch('notify', type: 'error', message: 'Seluruh isian harus ditandai terlebih dahulu.');

            return;
        }

        $adaBerkasDitolak = $berkas->contains(fn ($b) => $b->status_verifikasi === 'ditolak');
        $adaKendalaDitolak = $kendala->contains(fn ($k) => $k->status_verifikasi === 'ditolak');
        $adaUraianDitolak = $kegiatanList->contains(fn ($k) => $k->status_verifikasi_uraian === 'ditolak');
        $adaBagianKustomDitolak = $bagianKustom->contains(fn ($p) => $p->status_verifikasi === 'ditolak');
        $adaRtlDitolak = $rtl->contains(fn ($p) => $p->status_verifikasi === 'ditolak');

        if (! $adaBerkasDitolak && ! $adaKendalaDitolak && ! $adaUraianDitolak && ! $adaBagianKustomDitolak && ! $adaRtlDitolak) {
            $this->addError('berkas', 'Tandai minimal satu isian sebagai "Tidak Sesuai" beserta catatan sebelum mengembalikan isian.');
            $this->dispatch('notify', type: 'error', message: 'Tandai minimal satu isian "Tidak Sesuai" sebelum mengembalikan isian.');

            return;
        }

        try {
            DB::transaction(function () {
                $this->capaian->update(['analisis_capaian' => $this->analisis_capaian, 'catatan' => $this->catatan]);
                $this->syncRincianN();
                $this->capaianTahunanTerkini()->save();
                $this->simpanKoreksiTeks();

                foreach ($this->kegiatanList() as $kegiatan) {
                    if ($kegiatan->status_dokumen !== Kegiatan::STATUS_DIAJUKAN) {
                        continue;
                    }

                    $berkasKegiatanIni = $this->berkasUntukKegiatan($kegiatan->id);
                    $buktiSendiriDitolak = $berkasKegiatanIni->contains(fn ($b) => $b->status_verifikasi === 'ditolak')
                        || $kegiatan->status_verifikasi_uraian === 'ditolak';

                    if ($buktiSendiriDitolak) {
                        $kegiatan->kembalikan();
                    } else {
                        $kegiatan->verifikasi();
                    }
                }

                // Catatan riwayat mengambil alasan penolakan dari berkas, uraian kegiatan,
                // kendala & solusi, Bagian Kustom, MAUPUN realisasi RTL yang ditandai
                // "Tolak" — sudah divalidasi minimal satu ada sebelum sampai di sini.
                $catatan = $this->berkasList()
                    ->where('status_verifikasi', 'ditolak')
                    ->pluck('catatan')
                    ->concat($this->kendalaSolusiList()->where('status_verifikasi', 'ditolak')->pluck('catatan'))
                    ->concat($this->kegiatanList()->where('status_verifikasi_uraian', 'ditolak')->pluck('catatan_uraian'))
                    ->concat($this->bagianKustomList()->where('status_verifikasi', 'ditolak')->pluck('catatan'))
                    ->concat($this->rtlEvaluasiSebelumnya()->where('status_verifikasi', 'ditolak')->pluck('catatan'))
                    ->filter()
                    ->unique()
                    ->implode(' | ');

                $this->capaian->catatStatus(Kegiatan::STATUS_DIKEMBALIKAN, auth()->user(), $catatan ?: null);
            });
        } catch (InvalidStatusTransitionException $e) {
            $this->addError('berkas', $e->getMessage());
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        session()->flash('status', 'Isian dikembalikan ke Ketua Tim untuk diperbaiki.');
        $this->dispatch('notify', type: 'success', message: 'Isian dikembalikan ke Ketua Tim untuk diperbaiki.');

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
