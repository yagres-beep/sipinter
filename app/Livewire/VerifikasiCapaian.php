<?php

namespace App\Livewire;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\Capaian;
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
 * capaian (per kegiatan), bukti solusi (kendala-solusi), dan bukti realisasi RTL
 * triwulan sebelumnya sekaligus (RF-39). Tim SAKIP juga dapat mengoreksi langsung
 * teks yang diketik ketua tim (uraian kegiatan, kendala, solusi, realisasi RTL) bila
 * ada salah ketik — koreksi disimpan bersamaan saat "Verifikasi Selesai" ditekan.
 */
class VerifikasiCapaian extends Component
{
    public Capaian $capaian;

    public ?string $analisis_capaian = null;

    public $target_pk = null;

    public $target_tw = null;

    public $realisasi = null;

    public $persentase_capaian = null;

    /**
     * Catatan per berkas, dikunci pada id berkas.
     *
     * @var array<int, string|null>
     */
    public array $catatanBerkas = [];

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

    protected ?\Illuminate\Support\Collection $cacheBerkasKendala = null;

    protected ?\Illuminate\Support\Collection $cacheBagianKustomList = null;

    protected ?\Illuminate\Support\Collection $cacheBerkasBagianKustom = null;

    public function mount(Capaian $capaian): void
    {
        $this->capaian = $capaian->load(['masterIku', 'periode']);

        $this->analisis_capaian = $capaian->analisis_capaian;
        $this->target_pk = $capaian->target_pk;
        $this->target_tw = $capaian->target_tw;
        $this->realisasi = $capaian->realisasi;
        $this->persentase_capaian = $capaian->persentase_capaian;

        foreach ($this->berkasList() as $berkas) {
            $this->catatanBerkas[$berkas->id] = $berkas->catatan;
        }

        foreach ($this->kegiatanList() as $kegiatan) {
            $this->koreksiKegiatan[$kegiatan->id] = $kegiatan->uraian_kegiatan;
        }

        foreach ($this->kendalaSolusiList() as $ks) {
            $this->koreksiKendala[$ks->id] = ['kendala' => $ks->kendala, 'solusi' => $ks->solusi];
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
     * Poin RTL yang ditetapkan pada triwulan SEBELUMNYA periode capaian ini, untuk
     * dicek kesesuaian realisasinya — tanpa skor persentase (cukup periksa sesuai/tidak).
     */
    public function rtlEvaluasiSebelumnya()
    {
        if ($this->cacheRtlSebelumnya !== null) {
            return $this->cacheRtlSebelumnya;
        }

        $periode = $this->capaian->periode;
        $triwulanSekarang = $periode->triwulan;

        [$tahunTarget, $triwulanTarget] = $triwulanSekarang === 1
            ? [$periode->tahun - 1, 4]
            : [$periode->tahun, $triwulanSekarang - 1];

        return $this->cacheRtlSebelumnya = RtlEvaluasi::with('berkas')
            ->where('iku_id', $this->capaian->iku_id)
            ->whereHas('periode', fn ($q) => $q->where('tahun', $tahunTarget)->where('triwulan', $triwulanTarget))
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

    protected function berkasKendalaTerkelompok(): \Illuminate\Support\Collection
    {
        if ($this->cacheBerkasKendala !== null) {
            return $this->cacheBerkasKendala;
        }

        return $this->cacheBerkasKendala = Berkas::where('ref_type', KendalaSolusi::class)
            ->whereIn('ref_id', $this->kendalaSolusiList()->pluck('id'))
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
     * Bukti solusi milik satu pasangan kendala-solusi tertentu.
     */
    public function berkasUntukKendala(int $kendalaSolusiId)
    {
        return $this->berkasKendalaTerkelompok()->get($kendalaSolusiId, collect());
    }

    /**
     * Gabungan berkas lintas kegiatan (bukti capaian), kendala-solusi (bukti solusi),
     * dan evaluasi RTL (bukti realisasi) milik IKU+periode ini (RF-39) — dipakai untuk
     * validasi "semua berkas sudah ditandai" tanpa peduli pengelompokan tampilan.
     */
    public function berkasList()
    {
        $berkasCapaian = $this->berkasKegiatanTerkelompok()->flatten();
        $berkasSolusi = $this->berkasKendalaTerkelompok()->flatten();
        $berkasRtl = $this->rtlEvaluasiSebelumnya()->flatMap->berkas;
        $berkasBagianKustom = $this->berkasBagianKustomTerkelompok()->flatten();

        return $berkasCapaian->concat($berkasSolusi)->concat($berkasRtl)->concat($berkasBagianKustom)->sortBy('created_at')->values();
    }

    public function updatedRealisasi(): void
    {
        $this->hitungPersentase();
    }

    public function updatedTargetTw(): void
    {
        $this->hitungPersentase();
    }

    protected function hitungPersentase(): void
    {
        if (is_numeric($this->target_tw) && (float) $this->target_tw > 0 && is_numeric($this->realisasi)) {
            $this->persentase_capaian = round(((float) $this->realisasi / (float) $this->target_tw) * 100, 2);
        }
    }

    public function tandaiSesuai(int $berkasId): void
    {
        Berkas::whereKey($berkasId)->update([
            'status_verifikasi' => 'terverifikasi',
            'catatan' => $this->catatanBerkas[$berkasId] ?? null,
        ]);
    }

    public function tandaiTolak(int $berkasId): void
    {
        Berkas::whereKey($berkasId)->update([
            'status_verifikasi' => 'ditolak',
            'catatan' => $this->catatanBerkas[$berkasId] ?? null,
        ]);
    }

    protected function rules(): array
    {
        return [
            'analisis_capaian' => ['nullable', 'string'],
            'target_pk' => ['required', 'numeric', 'min:0'],
            'target_tw' => ['required', 'numeric', 'min:0'],
            'realisasi' => ['required', 'numeric', 'min:0'],
            'persentase_capaian' => ['required', 'numeric', 'min:0'],
            'koreksiKegiatan.*' => ['required', 'string', 'max:1000'],
            'koreksiKendala.*.kendala' => ['required', 'string'],
            'koreksiKendala.*.solusi' => ['nullable', 'string'],
            'koreksiRtlRealisasi.*' => ['nullable', 'string'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'analisis_capaian' => 'analisis capaian',
            'target_pk' => 'Target PK',
            'target_tw' => 'Target TW',
            'realisasi' => 'realisasi',
            'persentase_capaian' => 'capaian %',
        ];
    }

    /**
     * Simpan koreksi teks (bila ada perubahan) ke kegiatan, kendala-solusi, dan
     * realisasi RTL — dipanggil bersamaan dengan verifikasi/pengembalian supaya
     * Tim SAKIP tidak perlu tombol simpan terpisah untuk tiap koreksi kecil.
     */
    protected function simpanKoreksiTeks(): void
    {
        foreach ($this->koreksiKegiatan as $id => $teks) {
            Kegiatan::whereKey($id)->update(['uraian_kegiatan' => $teks]);
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

    public function verifikasiSelesai(): void
    {
        $berkas = $this->berkasList();

        if ($berkas->contains(fn ($b) => $b->status_verifikasi === 'menunggu')) {
            $this->addError('berkas', 'Seluruh berkas harus ditandai "Sesuai" atau "Tolak" terlebih dahulu.');

            return;
        }

        if ($berkas->contains(fn ($b) => $b->status_verifikasi === 'ditolak')) {
            $this->addError('berkas', 'Terdapat berkas yang ditolak — gunakan tombol "Kembalikan ke Ketua Tim", bukan "Verifikasi Selesai".');

            return;
        }

        $this->validate();

        try {
            DB::transaction(function () {
                $this->capaian->update([
                    'analisis_capaian' => $this->analisis_capaian,
                    'target_pk' => $this->target_pk,
                    'target_tw' => $this->target_tw,
                    'realisasi' => $this->realisasi,
                    'persentase_capaian' => $this->persentase_capaian,
                ]);

                $this->simpanKoreksiTeks();

                foreach ($this->kegiatanList() as $kegiatan) {
                    if ($kegiatan->status_dokumen === Kegiatan::STATUS_DIAJUKAN) {
                        $kegiatan->verifikasi();
                    }
                }
            });
        } catch (InvalidStatusTransitionException $e) {
            $this->addError('berkas', $e->getMessage());

            return;
        }

        session()->flash('status', 'Verifikasi selesai. Isian ditandai "diverifikasi".');

        $this->redirectRoute('verifikasi.index');
    }

    public function kembalikanKeKetuaTim(): void
    {
        if (! $this->berkasList()->contains(fn ($b) => $b->status_verifikasi === 'ditolak')) {
            $this->addError('berkas', 'Tandai minimal satu berkas sebagai "Tolak" beserta catatan sebelum mengembalikan isian.');

            return;
        }

        try {
            DB::transaction(function () {
                $this->simpanKoreksiTeks();

                foreach ($this->kegiatanList() as $kegiatan) {
                    if ($kegiatan->status_dokumen === Kegiatan::STATUS_DIAJUKAN) {
                        $kegiatan->kembalikan();
                    }
                }
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
            'berkasPerKendala' => $kendalaSolusiList->mapWithKeys(fn ($ks) => [$ks->id => $this->berkasUntukKendala($ks->id)]),
            'bagianKustomList' => $bagianKustomList,
            'berkasPerBagianKustom' => $bagianKustomList->mapWithKeys(fn ($p) => [$p->id => $this->berkasUntukBagianKustom($p->id)]),
        ]);
    }
}
