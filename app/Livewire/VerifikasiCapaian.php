<?php

namespace App\Livewire;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\CapaianTahunan;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi;
use App\Models\MasterIku;
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
     * Rincian Output (RO) milik IKU ini — RF baru: satu IKU boleh punya BANYAK RO
     * (mis. beberapa publikasi berbeda), lihat App\Models\RincianOutput. DAFTAR DATAR
     * (bukan dikelompokkan per Kegiatan) karena RO dihitung mandiri per IKU, tidak
     * saling terhubung dan tidak mengikuti jumlah Kegiatan — NotulaBagian1DocxService
     * menggandakan satu baris tabel per RincianOutput pada IKU ini APA ADANYA, tanpa
     * peduli Kegiatan mana yang jadi induknya. kegiatan_id tetap disimpan per baris
     * HANYA karena kolomnya NOT NULL di DB (RincianOutput::kegiatan) dan supaya
     * kegiatanBisaDikoreksi() bisa dicek per baris — dipilih otomatis (tambahRo()),
     * TIDAK PERNAH dipilih atau ditampilkan oleh Tim SAKIP. Kunci array adalah id
     * RincianOutput asli (baris sudah tersimpan, unik lintas Kegiatan) ATAU kunci
     * sementara "baru-..." (baris baru dari tambahRo(), belum pernah disimpan).
     * TERPISAH dari $koreksiKegiatan (uraian_kegiatan bebas-teks yang ditulis
     * petugas) karena penamaan RO resmi tidak selalu sama dengan uraian kegiatan.
     * Hanya terisi Tim SAKIP saat IKU-nya BELUM punya realisasi triwulan berjalan
     * (lihat NotulaBagian1DocxService::isiSatuIku(): tabel RO hanya tampil bila
     * $rekap['realisasi'] masih kosong), tapi tidak wajib.
     *
     * @var array<string, array{id: int|null, kegiatan_id: int, uraian: string|null, volume_ro: string|null, progres_persen: string|null}>
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
     * Koreksi teks RENCANA RTL triwulan berikutnya (Bagian 5, baru ditetapkan Ketua
     * Tim triwulan ini), dikunci pada id rtl_evaluasi — TERPISAH dari
     * $koreksiRtlRealisasi di atas karena keduanya mengoreksi hal berbeda: itu soal
     * REALISASI (hasil RTL triwulan sebelumnya), ini soal teks RENCANA itu sendiri
     * (rtl_teks). Sama seperti $koreksiKendala, selalu bisa disunting & disimpan
     * lewat simpanPerubahan() tanpa syarat bisaDiverifikasi() — lihat
     * simpanKoreksiTeks().
     *
     * @var array<int, string>
     */
    public array $koreksiRtlBerikutnya = [];

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
     * Catatan penolakan RENCANA RTL triwulan berikutnya (Bagian 5, baru ditetapkan
     * Ketua Tim triwulan ini) — dikunci pada id rtl_evaluasi juga, tapi TERPISAH dari
     * $catatanRtl di atas karena keduanya memverifikasi hal berbeda: $catatanRtl soal
     * REALISASI (hasil), ini soal RENCANA (teks) itu sendiri. Lihat
     * rtlBerikutnyaBaruDitetapkan().
     *
     * @var array<int, string|null>
     */
    public array $catatanRtlBerikutnya = [];

    /**
     * PIC Tindak Lanjut untuk rencana RTL triwulan berikutnya (Bagian 5) — SATU nilai
     * untuk seluruh poin dalam batch ini, sama seperti PengisianKegiatan::$rtlBaruPic.
     * Ketua Tim boleh mengosongkannya saat mengajukan (lihat PengisianKegiatan::rules()),
     * tapi Tim SAKIP WAJIB mengisi/mengonfirmasinya di sini sebelum "Verifikasi Selesai"
     * bisa ditekan (lihat verifikasiSelesai()) — supaya rencana yang lolos ke notula
     * final selalu punya PIC yang jelas.
     */
    public ?string $picRtlBerikutnya = null;

    /**
     * Cache dalam satu siklus request (di-reset otomatis tiap request baru) — DB
     * remote (Supabase, Seoul) makan ~400ms per query, dan tiap koleksi ini dipakai
     * ulang di banyak tempat (mount, render, verifikasiSelesai, kembalikanKeKetuaTim)
     * dalam request yang sama; tanpa cache ini query yang sama terulang berkali-kali.
     */
    protected ?Collection $cacheKegiatanList = null;

    protected ?Collection $cacheKendalaSolusiList = null;

    protected ?Collection $cacheRtlSebelumnya = null;

    protected ?Collection $cacheRtlBerikutnya = null;

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
        }

        // Daftar datar lintas SEMUA Kegiatan pada IKU ini — lihat catatan di properti
        // $rincianOutput, RO tidak dikelompokkan per Kegiatan.
        foreach ($this->kegiatanList() as $kegiatan) {
            foreach ($kegiatan->rincianOutput as $ro) {
                $this->rincianOutput[(string) $ro->id] = [
                    'id' => $ro->id,
                    'kegiatan_id' => $ro->kegiatan_id,
                    'uraian' => $ro->uraian,
                    'volume_ro' => $ro->volume_ro,
                    'progres_persen' => $ro->progres_persen,
                ];
            }
        }

        // Supaya Tim SAKIP tidak harus klik "+ Tambah RO" dulu hanya untuk mulai
        // mengisi, satu baris kosong disiapkan otomatis, hanya bila belum ada RO
        // tersimpan sama sekali di seluruh IKU ini.
        if (! $this->rincianOutput) {
            $this->tambahRo();
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

        foreach ($this->rtlBerikutnyaBaruDitetapkan() as $poin) {
            $this->koreksiRtlBerikutnya[$poin->id] = $poin->rtl_teks;
        }

        $this->picRtlBerikutnya = $this->rtlBerikutnyaBaruDitetapkan()->first()?->pic;

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
     * Poin RTL yang BARU DITETAPKAN Ketua Tim triwulan INI untuk dilaksanakan pada
     * triwulan BERIKUTNYA (Bagian 5 "Rencana Tindak Lanjut", lihat
     * PengisianKegiatan::ajukanIsian() langkah 4) — beda dari rtlEvaluasiSebelumnya()
     * di atas yang memverifikasi REALISASI RTL yang ditetapkan triwulan lalu. Di sini
     * yang diverifikasi adalah TEKS RENCANA itu sendiri (belum ada realisasi apa pun,
     * triwulan sasarannya belum berjalan) — supaya rencana ini tidak lolos tanpa
     * pernah diperiksa siapa pun (sebelumnya tidak pernah muncul di layar manapun).
     */
    public function rtlBerikutnyaBaruDitetapkan()
    {
        if ($this->cacheRtlBerikutnya !== null) {
            return $this->cacheRtlBerikutnya;
        }

        $periode = $this->capaian->periode;
        $triwulanBerikutnya = $periode->triwulan === 4 ? 1 : $periode->triwulan + 1;
        $tahunBerikutnya = $periode->triwulan === 4 ? $periode->tahun + 1 : $periode->tahun;

        return $this->cacheRtlBerikutnya = RtlEvaluasi::where('iku_id', $this->capaian->iku_id)
            ->whereHas('periode', fn ($q) => $q->where('tahun', $tahunBerikutnya)->where('triwulan', $triwulanBerikutnya))
            ->orderBy('id')
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

        // catatan_bukti_dihapus (pengingat bukti tertolak yang sudah dihapus Ketua Tim,
        // lihat App\Livewire\PengisianKegiatan::hapusBuktiLamaBagianKustom()) ikut
        // dikosongkan — begitu poin ini "Sesuai", pengingatnya sudah tidak relevan.
        BagianKustomPoin::whereKey($poinId)->update([
            'status_verifikasi' => 'terverifikasi',
            'catatan' => null,
            'catatan_bukti_dihapus' => null,
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
     * di blade) tidak punya apa pun untuk diperiksa. Dicek lewat sudahDievaluasi()
     * (minimal satu bukti terunggah) — BUKAN filled($poin->realisasi), karena kolom
     * teks realisasi itu sendiri sudah dihapus dari alur pengisian Ketua Tim (lihat
     * RtlEvaluasi::sudahDievaluasi()); Ketua Tim sekarang hanya mengunggah bukti.
     */
    public function rtlBisaDiverifikasi(int $rtlId): bool
    {
        if (! $this->bisaDiverifikasi()) {
            return false;
        }

        $poin = $this->rtlEvaluasiSebelumnya()->firstWhere('id', $rtlId);

        return $poin && $poin->sudahDievaluasi();
    }

    public function tandaiRtlSesuai(int $rtlId): void
    {
        if (! $this->rtlBisaDiverifikasi($rtlId)) {
            return;
        }

        $this->catatanRtl[$rtlId] = null;

        // catatan_bukti_dihapus ikut dikosongkan — sama seperti tandaiBagianKustomSesuai().
        RtlEvaluasi::whereKey($rtlId)->update([
            'status_verifikasi' => 'terverifikasi',
            'catatan' => null,
            'catatan_bukti_dihapus' => null,
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

    /**
     * Beda dari rtlBisaDiverifikasi() — TANPA syarat realisasi terisi, karena di sini
     * yang diverifikasi teks RENCANA-nya sendiri (rtlBerikutnyaBaruDitetapkan()),
     * bukan realisasinya (memang belum ada, triwulan sasarannya belum berjalan).
     */
    public function rtlBerikutnyaBisaDiverifikasi(int $rtlId): bool
    {
        return $this->bisaDiverifikasi() && $this->rtlBerikutnyaBaruDitetapkan()->contains('id', $rtlId);
    }

    public function tandaiRtlBerikutnyaSesuai(int $rtlId): void
    {
        if (! $this->rtlBerikutnyaBisaDiverifikasi($rtlId)) {
            return;
        }

        $this->catatanRtlBerikutnya[$rtlId] = null;

        RtlEvaluasi::whereKey($rtlId)->update([
            'status_verifikasi' => 'terverifikasi',
            'catatan' => null,
        ]);
    }

    public function tandaiRtlBerikutnyaTolak(int $rtlId): void
    {
        if (! $this->rtlBerikutnyaBisaDiverifikasi($rtlId)) {
            return;
        }

        $catatan = trim((string) ($this->catatanRtlBerikutnya[$rtlId] ?? ''));

        if ($catatan === '') {
            $this->addError('catatanRtlBerikutnya.'.$rtlId, 'Catatan wajib diisi saat menandai rencana RTL ini "Tidak Sesuai" — supaya Ketua Tim tahu apa yang perlu diperbaiki.');
            $this->dispatch('notify', type: 'error', message: 'Catatan wajib diisi saat menandai rencana RTL "Tidak Sesuai".');

            return;
        }

        RtlEvaluasi::whereKey($rtlId)->update([
            'status_verifikasi' => 'ditolak',
            'catatan' => $catatan,
        ]);
    }

    /**
     * Hapus satu poin rencana RTL berikutnya (Bagian 5) — gerbangnya sama persis
     * dengan rtlBerikutnyaBisaDiverifikasi() (hanya selagi Capaian masih
     * "diajukan"/"sedang ditangani"), supaya Tim SAKIP bisa membuang poin yang salah
     * ketik/duplikat/tidak relevan tanpa harus menandainya "Tidak Sesuai" dulu untuk
     * mengembalikannya ke Ketua Tim. Poin yang sudah terverifikasi/disetujui pada
     * batch sebelumnya TIDAK bisa dihapus lewat sini (bukan bagian dari
     * rtlBerikutnyaBaruDitetapkan() batch berjalan).
     */
    public function hapusRtlBerikutnya(int $rtlId): void
    {
        if (! $this->rtlBerikutnyaBisaDiverifikasi($rtlId)) {
            return;
        }

        RtlEvaluasi::whereKey($rtlId)->delete();

        unset($this->koreksiRtlBerikutnya[$rtlId], $this->catatanRtlBerikutnya[$rtlId]);

        $this->cacheRtlBerikutnya = null;
    }

    protected function rules(): array
    {
        return [
            'analisis_capaian' => ['nullable', 'string'],
            'catatan' => ['nullable', 'string'],
            'koreksiKegiatan.*' => ['required', 'string', 'max:1000'],
            'rincianOutput.*.uraian' => ['nullable', 'string', 'max:255'],
            'rincianOutput.*.volume_ro' => ['nullable', 'string', 'max:255'],
            'rincianOutput.*.progres_persen' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'koreksiKendala.*.kendala' => ['required', 'string'],
            'koreksiKendala.*.solusi' => ['nullable', 'string'],
            'koreksiRtlRealisasi.*' => ['nullable', 'string'],
            'koreksiRtlBerikutnya.*' => ['required', 'string'],
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
            'rincianOutput.*.uraian' => 'rincian output',
            'rincianOutput.*.volume_ro' => 'realisasi volume RO',
            'rincianOutput.*.progres_persen' => 'progres pelaksanaan kegiatan (%)',
            'koreksiRtlBerikutnya.*' => 'rencana RTL berikutnya',
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
     * Tambah satu baris RO kosong — hanya tersimpan ke DB nanti saat
     * "Simpan"/"Verifikasi Selesai"/dst. ditekan (lihat simpanRincianOutput()), sama
     * seperti pola koreksi teks lain di komponen ini. Kunci sementara "baru-..."
     * dipakai supaya baris ini bisa dihapus lagi (hapusRo()) sebelum sempat tersimpan
     * tanpa perlu id asli. kegiatan_id-nya dipilih OTOMATIS (Kegiatan pertama yang
     * masih bisa dikoreksi) — RO tidak terikat Kegiatan tertentu di mata Tim SAKIP,
     * lihat catatan di properti $rincianOutput; kolom itu cuma dibutuhkan DB.
     */
    public function tambahRo(): void
    {
        $kegiatan = $this->kegiatanList()->first(fn ($k) => $this->kegiatanBisaDikoreksi($k));
        if (! $kegiatan) {
            return;
        }

        $kunciBaru = 'baru-'.(string) Str::uuid();
        $this->rincianOutput[$kunciBaru] = ['id' => null, 'kegiatan_id' => $kegiatan->id, 'uraian' => null, 'volume_ro' => null, 'progres_persen' => null];
    }

    /**
     * Hapus satu baris RO — baris yang SUDAH tersimpan (punya id asli) dihapus dari
     * DB seketika (sama seperti tombol aksi langsung lain di komponen ini, mis.
     * tandaiSesuai()); baris yang baru ditambahkan lewat tambahRo() dan belum
     * disimpan cukup dibuang dari state lokal.
     */
    public function hapusRo(string $kunci): void
    {
        $baris = $this->rincianOutput[$kunci] ?? null;
        if (! $baris || ! $this->rincianOutputBisaDikoreksi($baris)) {
            return;
        }

        if ($baris['id']) {
            RincianOutput::whereKey($baris['id'])->delete();
        }

        unset($this->rincianOutput[$kunci]);
    }

    /**
     * Baris RO ini boleh disunting/dihapus Tim SAKIP hanya bila Kegiatan yang
     * jadi induknya di DB (lihat catatan di properti $rincianOutput) masih bisa
     * dikoreksi — pertahanan berlapis yang sama seperti field lain, walau di UI RO
     * tidak pernah ditampilkan terikat ke Kegiatan tertentu.
     */
    public function rincianOutputBisaDikoreksi(array $baris): bool
    {
        $kegiatan = $this->kegiatanList()->firstWhere('id', $baris['kegiatan_id']);

        return $kegiatan && $this->kegiatanBisaDikoreksi($kegiatan);
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

        $this->simpanRincianOutput();

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

        foreach ($this->koreksiRtlBerikutnya as $id => $teks) {
            RtlEvaluasi::whereKey($id)->update(['rtl_teks' => $teks]);
        }

        // PIC Tindak Lanjut boleh belum diisi Ketua Tim (lihat App\Livewire\
        // PengisianKegiatan::rules()) — Tim SAKIP mengisi/mengonfirmasinya di sini,
        // berlaku untuk SELURUH poin RTL berikutnya dalam batch ini sekaligus (satu
        // nilai per batch, sama seperti cara Ketua Tim mengisinya). verifikasiSelesai()
        // sudah memastikan nilainya terisi sebelum sampai ke sini (lihat gerbang di
        // sana); kembalikanKeKetuaTim() tetap menyimpannya kalau sudah sempat diisi,
        // tapi tidak mewajibkannya.
        if ($this->rtlBerikutnyaBaruDitetapkan()->isNotEmpty() && filled($this->picRtlBerikutnya)) {
            RtlEvaluasi::whereIn('id', $this->rtlBerikutnyaBaruDitetapkan()->pluck('id'))
                ->update(['pic' => trim($this->picRtlBerikutnya)]);
        }
    }

    /**
     * Tulis seluruh baris RO (lintas Kegiatan, lihat catatan di properti
     * $rincianOutput) ke DB: baris milik Kegiatan yang sudah tidak bisa dikoreksi
     * dilewati (pertahanan berlapis, sama seperti simpanKoreksiTeks()); baris yang
     * KOSONG (ketiga kolom kosong) dihapus bila sudah tersimpan sebelumnya, atau
     * dilewati bila memang baru ditambahkan lewat tambahRo() dan tidak pernah diisi;
     * baris yang terisi dibuat/diperbarui sesuai ada/tidaknya id asli.
     */
    protected function simpanRincianOutput(): void
    {
        foreach ($this->rincianOutput as $kunci => $baris) {
            if (! $this->rincianOutputBisaDikoreksi($baris)) {
                continue;
            }

            $terisi = filled($baris['uraian'] ?? null) || filled($baris['volume_ro'] ?? null) || filled($baris['progres_persen'] ?? null);

            if (! $terisi) {
                if (! empty($baris['id'])) {
                    RincianOutput::whereKey($baris['id'])->delete();
                    $this->rincianOutput[$kunci]['id'] = null;
                }

                continue;
            }

            $payload = [
                'kegiatan_id' => $baris['kegiatan_id'],
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
                $ro = RincianOutput::create($payload);
                $this->rincianOutput[$kunci]['id'] = $ro->id;
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

    /**
     * Kumpulan label ringkas tiap isian yang statusnya masih "menunggu" (belum
     * ditandai Sesuai/Tidak Sesuai) -- dipakai verifikasiSelesai() &amp;
     * kembalikanKeKetuaTim() supaya pesan error menyebutkan PERSIS isian mana yang
     * kurang, bukan cuma "seluruh isian harus ditandai" yang mengharuskan Tim SAKIP
     * menyisir ulang seluruh halaman satu per satu.
     *
     * @return list<string>
     */
    protected function daftarIsianBelumDitandai(): array
    {
        $daftar = [];

        foreach ($this->berkasList()->where('status_verifikasi', 'menunggu') as $b) {
            $daftar[] = "Berkas \"{$b->nama_file}\"";
        }

        foreach ($this->kegiatanList()->where('status_verifikasi_uraian', 'menunggu') as $k) {
            $daftar[] = 'Uraian kegiatan "'.Str::limit($k->uraian_kegiatan ?: '(kosong)', 40).'"';
        }

        foreach ($this->kendalaSolusiList()->where('status_verifikasi', 'menunggu') as $k) {
            $daftar[] = 'Kendala & solusi "'.Str::limit($k->kendala ?: '(kosong)', 40).'"';
        }

        foreach ($this->bagianKustomList()->where('status_verifikasi', 'menunggu') as $p) {
            $daftar[] = 'Bagian Kustom "'.Str::limit($p->teks ?: '(kosong)', 40).'" ('.$p->bagianKustom->nama.')';
        }

        foreach ($this->rtlEvaluasiSebelumnya()->filter(fn ($p) => $p->sudahDievaluasi())->where('status_verifikasi', 'menunggu') as $p) {
            $daftar[] = 'Realisasi RTL "'.Str::limit($p->rtl_teks ?: '(kosong)', 40).'"';
        }

        foreach ($this->rtlBerikutnyaBaruDitetapkan()->where('status_verifikasi', 'menunggu') as $p) {
            $daftar[] = 'Rencana RTL berikutnya "'.Str::limit($p->rtl_teks ?: '(kosong)', 40).'"';
        }

        return $daftar;
    }

    /**
     * Pesan error lengkap dari daftarIsianBelumDitandai() -- dipakai berbarengan
     * untuk banner @error('berkas') (persisten) &amp; toast notify (6 detik).
     */
    protected function pesanIsianBelumDitandai(array $daftar): string
    {
        return 'Isian berikut belum ditandai "Sesuai"/"Tidak Sesuai": '.implode('; ', $daftar).'.';
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
        $rtl = $this->rtlEvaluasiSebelumnya()->filter(fn ($p) => $p->sudahDievaluasi());
        $rtlBerikutnya = $this->rtlBerikutnyaBaruDitetapkan();

        $belumDitandai = $this->daftarIsianBelumDitandai();

        if ($belumDitandai !== []) {
            $pesan = $this->pesanIsianBelumDitandai($belumDitandai);
            $this->addError('berkas', $pesan);
            $this->dispatch('notify', type: 'error', message: $pesan);

            return;
        }

        if (
            $berkas->contains(fn ($b) => $b->status_verifikasi === 'ditolak')
            || $kendala->contains(fn ($k) => $k->status_verifikasi === 'ditolak')
            || $kegiatanList->contains(fn ($k) => $k->status_verifikasi_uraian === 'ditolak')
            || $bagianKustom->contains(fn ($p) => $p->status_verifikasi === 'ditolak')
            || $rtl->contains(fn ($p) => $p->status_verifikasi === 'ditolak')
            || $rtlBerikutnya->contains(fn ($p) => $p->status_verifikasi === 'ditolak')
        ) {
            $this->addError('berkas', 'Terdapat isian yang ditolak — gunakan tombol "Kembalikan ke Ketua Tim", bukan "Verifikasi Selesai".');
            $this->dispatch('notify', type: 'error', message: 'Ada isian yang ditolak — gunakan "Kembalikan ke Ketua Tim".');

            return;
        }

        // PIC Tindak Lanjut opsional bagi Ketua Tim saat mengajukan, tapi WAJIB
        // dikonfirmasi/diisi Tim SAKIP di sini sebelum verifikasi benar-benar selesai
        // — supaya rencana yang lolos ke notula final selalu punya PIC yang jelas.
        if ($rtlBerikutnya->isNotEmpty() && blank($this->picRtlBerikutnya)) {
            $pesan = 'PIC Tindak Lanjut untuk rencana RTL triwulan berikutnya wajib diisi sebelum verifikasi selesai.';
            $this->addError('picRtlBerikutnya', $pesan);
            $this->dispatch('notify', type: 'error', message: $pesan);

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
        $rtl = $this->rtlEvaluasiSebelumnya()->filter(fn ($p) => $p->sudahDievaluasi());
        $rtlBerikutnya = $this->rtlBerikutnyaBaruDitetapkan();

        $belumDitandai = $this->daftarIsianBelumDitandai();

        if ($belumDitandai !== []) {
            $pesan = $this->pesanIsianBelumDitandai($belumDitandai);
            $this->addError('berkas', $pesan);
            $this->dispatch('notify', type: 'error', message: $pesan);

            return;
        }

        $adaBerkasDitolak = $berkas->contains(fn ($b) => $b->status_verifikasi === 'ditolak');
        $adaKendalaDitolak = $kendala->contains(fn ($k) => $k->status_verifikasi === 'ditolak');
        $adaUraianDitolak = $kegiatanList->contains(fn ($k) => $k->status_verifikasi_uraian === 'ditolak');
        $adaBagianKustomDitolak = $bagianKustom->contains(fn ($p) => $p->status_verifikasi === 'ditolak');
        $adaRtlDitolak = $rtl->contains(fn ($p) => $p->status_verifikasi === 'ditolak');
        $adaRtlBerikutnyaDitolak = $rtlBerikutnya->contains(fn ($p) => $p->status_verifikasi === 'ditolak');

        if (! $adaBerkasDitolak && ! $adaKendalaDitolak && ! $adaUraianDitolak && ! $adaBagianKustomDitolak && ! $adaRtlDitolak && ! $adaRtlBerikutnyaDitolak) {
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
                    ->concat($this->rtlBerikutnyaBaruDitetapkan()->where('status_verifikasi', 'ditolak')->pluck('catatan'))
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
            'rtlBerikutnya' => $this->rtlBerikutnyaBaruDitetapkan(),
            'daftarTimPic' => MasterIku::daftarTimGabungan(),
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
