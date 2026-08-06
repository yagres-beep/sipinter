<?php

namespace App\Livewire;

use App\Models\Berkas;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\RtlEvaluasi as RtlEvaluasiModel;
use App\Services\FolderStructureService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Ketua Tim — Rencana Tindak Lanjut & Evaluasinya (RF-29 s.d. RF-35).
 *
 * Dua bagian yang TIDAK saling tergantung pada bulan yang sama:
 *  1. Evaluasi poin RTL TRIWULAN SEBELUMNYA (RF-29 s.d. RF-31, RF-35) — bisa
 *     dilaporkan realisasinya di bulan mana pun sepanjang triwulan berjalan.
 *  2. Penetapan RTL untuk TRIWULAN BERIKUTNYA (RF-32 s.d. RF-34) — diisi SATU
 *     KALI (biasanya di akhir triwulan) lalu tampil hanya-baca setelahnya.
 */
class RtlEvaluasi extends Component
{
    use WithFileUploads;

    public int $tahun;

    public int $bulan;

    public ?int $iku_id = null;

    /**
     * Form realisasi per poin RTL triwulan sebelumnya yang belum dievaluasi,
     * dikunci pada id baris rtl_evaluasi.
     *
     * @var array<int, array{realisasi: string, status_cocok: string, bukti: array}>
     */
    public array $evaluasi = [];

    /**
     * Blok RTL baru untuk triwulan berikutnya (RF-32) — hanya dipakai bila belum
     * pernah ditetapkan untuk triwulan berikutnya itu.
     *
     * @var array<int, array{rtl_teks: string, pic: string, batas_waktu: string}>
     */
    public array $rtlBaru = [];

    public function mount(): void
    {
        $this->tahun = (int) now()->year;
        $this->bulan = (int) now()->month;
        $this->rtlBaru = [$this->emptyRtlBlock()];
    }

    protected function emptyRtlBlock(): array
    {
        return [
            'rtl_teks' => '',
            'pic' => '',
            // RF-34: batas waktu tindak lanjut secara default akhir triwulan berikutnya.
            'batas_waktu' => $this->akhirTriwulanBerikutnya()->toDateString(),
        ];
    }

    public function addRtlBlock(): void
    {
        $this->rtlBaru[] = $this->emptyRtlBlock();
    }

    public function removeRtlBlock(int $index): void
    {
        unset($this->rtlBaru[$index]);
        $this->rtlBaru = array_values($this->rtlBaru);
    }

    public function updatedIkuId(): void
    {
        $this->muatFormEvaluasi();
    }

    public function updatedBulan(): void
    {
        $this->muatFormEvaluasi();
        $this->rtlBaru = [$this->emptyRtlBlock()];
    }

    public function updatedTahun(): void
    {
        $this->muatFormEvaluasi();
        $this->rtlBaru = [$this->emptyRtlBlock()];
    }

    protected function triwulanDari(int $bulan): int
    {
        return (int) ceil($bulan / 3);
    }

    /**
     * Siapkan form evaluasi kosong untuk tiap poin RTL triwulan sebelumnya yang
     * BELUM dievaluasi (realisasi masih kosong) — RF-29, RF-31.
     */
    protected function muatFormEvaluasi(): void
    {
        $this->evaluasi = [];

        foreach ($this->rtlTriwulanSebelumnya() as $poin) {
            if (! $poin->sudahDievaluasi()) {
                $this->evaluasi[$poin->id] = ['realisasi' => '', 'status_cocok' => '', 'bukti' => []];
            }
        }
    }

    /**
     * Poin RTL yang DITETAPKAN untuk triwulan SEBELUMNYA, ditarik otomatis untuk
     * dievaluasi pada triwulan yang sedang berjalan (RF-29). Triwulan sebelum TW I
     * dianggap TW IV tahun sebelumnya.
     */
    protected function rtlTriwulanSebelumnya()
    {
        if (! $this->iku_id) {
            return collect();
        }

        $triwulanSekarang = $this->triwulanDari($this->bulan);
        [$tahunTarget, $triwulanTarget] = $triwulanSekarang === 1
            ? [$this->tahun - 1, 4]
            : [$this->tahun, $triwulanSekarang - 1];

        return RtlEvaluasiModel::with(['periode', 'berkas'])
            ->where('iku_id', $this->iku_id)
            ->whereHas('periode', fn ($q) => $q->where('tahun', $tahunTarget)->where('triwulan', $triwulanTarget))
            ->get();
    }

    /**
     * Poin RTL yang sudah ditetapkan untuk triwulan yang SEDANG berjalan — ditampilkan
     * hanya-baca (RF-32) sepanjang bulan-bulan dalam triwulan tsb.
     */
    protected function rtlTriwulanBerjalan()
    {
        if (! $this->iku_id) {
            return collect();
        }

        $triwulanSekarang = $this->triwulanDari($this->bulan);

        return RtlEvaluasiModel::with('periode')
            ->where('iku_id', $this->iku_id)
            ->whereHas('periode', fn ($q) => $q->where('tahun', $this->tahun)->where('triwulan', $triwulanSekarang))
            ->get();
    }

    protected function targetTriwulanBerikutnya(): array
    {
        $triwulanSekarang = $this->triwulanDari($this->bulan);

        return $triwulanSekarang === 4
            ? ['tahun' => $this->tahun + 1, 'triwulan' => 1]
            : ['tahun' => $this->tahun, 'triwulan' => $triwulanSekarang + 1];
    }

    protected function bulanBulanTarget(): array
    {
        $target = $this->targetTriwulanBerikutnya();
        $bulanPertama = ($target['triwulan'] - 1) * 3 + 1;

        return [$bulanPertama, $bulanPertama + 1, $bulanPertama + 2];
    }

    protected function akhirTriwulanBerikutnya(): Carbon
    {
        $target = $this->targetTriwulanBerikutnya();
        $bulanTerakhir = ($target['triwulan'] - 1) * 3 + 3;

        return Carbon::create($target['tahun'], $bulanTerakhir, 1)->endOfMonth();
    }

    protected function namaBulanIndo(int $bulan): string
    {
        return Carbon::create(2000, $bulan, 1)->locale('id')->translatedFormat('F');
    }

    protected function rtlTriwulanBerikutnyaSudahAda(): bool
    {
        if (! $this->iku_id) {
            return false;
        }

        $target = $this->targetTriwulanBerikutnya();

        return RtlEvaluasiModel::where('iku_id', $this->iku_id)
            ->whereHas('periode', fn ($q) => $q->where('tahun', $target['tahun'])->where('triwulan', $target['triwulan']))
            ->exists();
    }

    protected function labelTriwulanBerikutnya(): string
    {
        $target = $this->targetTriwulanBerikutnya();

        return 'Triwulan '.['I', 'II', 'III', 'IV'][$target['triwulan'] - 1].' '.$target['tahun'];
    }

    // ================= RF-32/33/34: tetapkan RTL triwulan berikutnya =================

    public function tetapkanRtl(): void
    {
        if (! $this->iku_id) {
            $this->addError('iku_id', 'Pilih IKU terlebih dahulu.');

            return;
        }

        // RF-32: RTL diisi SATU KALI di akhir triwulan — bila sudah ada, tolak
        // penetapan ulang (form di Blade sudah disembunyikan, ini jaga-jaga kedua).
        if ($this->rtlTriwulanBerikutnyaSudahAda()) {
            $this->addError('rtlBaru', 'RTL untuk triwulan berikutnya sudah ditetapkan dan tidak dapat diubah lagi.');

            return;
        }

        $this->validate([
            'rtlBaru' => ['required', 'array', 'min:1'],
            'rtlBaru.*.rtl_teks' => ['required', 'string'],
            'rtlBaru.*.pic' => ['required', 'string', 'max:255'],
            'rtlBaru.*.batas_waktu' => ['required', 'date'],
        ], [], [
            'rtlBaru.*.rtl_teks' => 'RTL',
            'rtlBaru.*.pic' => 'PIC',
            'rtlBaru.*.batas_waktu' => 'batas waktu',
        ]);

        $target = $this->targetTriwulanBerikutnya();
        $bulanPertamaTarget = ($target['triwulan'] - 1) * 3 + 1;

        $periodeTarget = Periode::firstOrCreate(
            ['tahun' => $target['tahun'], 'bulan' => $bulanPertamaTarget],
            ['triwulan' => $target['triwulan'], 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]
        );

        // RF-33: "RTL untuk Juli, Agustus, dan September".
        $namaBulanTarget = collect($this->bulanBulanTarget())->map(fn ($b) => $this->namaBulanIndo($b));
        $berlakuBulan = 'RTL untuk '.$namaBulanTarget->join(', ', ', dan ');

        foreach ($this->rtlBaru as $blok) {
            RtlEvaluasiModel::create([
                'iku_id' => $this->iku_id,
                'periode_id' => $periodeTarget->id,
                'rtl_teks' => $blok['rtl_teks'],
                'berlaku_bulan' => $berlakuBulan,
                'pic' => $blok['pic'],
                'batas_waktu' => $blok['batas_waktu'],
            ]);
        }

        session()->flash('status', 'RTL untuk triwulan berikutnya berhasil ditetapkan.');

        $this->rtlBaru = [$this->emptyRtlBlock()];
    }

    // ================= RF-29/30/35: evaluasi RTL triwulan sebelumnya =================

    /**
     * RF-35: saat ketua tim mengetik realisasi, sarankan status_cocok otomatis dari
     * kemiripan teks terhadap rtl_teks aslinya. Tetap bisa ditimpa manual lewat
     * dropdown di Blade (opsi penyesuaian manual) sebelum disimpan.
     */
    public function updatedEvaluasi($value, $key): void
    {
        if (! str_ends_with($key, '.realisasi')) {
            return;
        }

        $rtlId = (int) explode('.', $key)[0];
        $poin = RtlEvaluasiModel::find($rtlId);

        if ($poin && filled($value)) {
            $this->evaluasi[$rtlId]['status_cocok'] = $poin->sarankanStatusCocok($value);
        }
    }

    public function simpanEvaluasi(int $rtlId): void
    {
        $this->validate([
            "evaluasi.{$rtlId}.realisasi" => ['required', 'string'],
            "evaluasi.{$rtlId}.status_cocok" => ['required', 'in:cocok,perlu_ditinjau,tidak_cocok'],
            "evaluasi.{$rtlId}.bukti.*" => ['file', 'mimes:pdf', 'max:10240'],
        ], [], [
            "evaluasi.{$rtlId}.realisasi" => 'realisasi',
            "evaluasi.{$rtlId}.status_cocok" => 'status kecocokan',
        ]);

        $poin = RtlEvaluasiModel::with(['periode', 'masterIku'])->findOrFail($rtlId);

        $poin->update([
            'realisasi' => $this->evaluasi[$rtlId]['realisasi'],
            'status_cocok' => $this->evaluasi[$rtlId]['status_cocok'],
        ]);

        $folderService = app(FolderStructureService::class);

        // RF-30: bukti realisasi bersifat OPSIONAL — boleh tidak ada sama sekali.
        foreach ($this->evaluasi[$rtlId]['bukti'] as $file) {
            $path = $file->store('bukti-evaluasi-rtl', 'local');

            $berkas = Berkas::create([
                'ref_id' => $poin->id,
                'ref_type' => RtlEvaluasiModel::class,
                'kategori' => 'evaluasi_rtl',
                'nama_file' => $file->getClientOriginalName(),
                'path' => $path,
                'status_verifikasi' => 'menunggu',
            ]);

            try {
                $localFullPath = Storage::disk('local')->path($path);
                $hasilDrive = $folderService->unggahBerkas($poin->periode, $poin->masterIku, 'evaluasi_rtl', $localFullPath);
                $berkas->update($hasilDrive);
            } catch (RuntimeException $e) {
                Log::warning('Gagal mengunggah bukti evaluasi RTL ke Google Drive, disimpan lokal saja: '.$e->getMessage());
            }
        }

        unset($this->evaluasi[$rtlId]);

        session()->flash('status', 'Evaluasi RTL berhasil disimpan.');
    }

    public function render()
    {
        return view('livewire.rtl-evaluasi', [
            'ikuList' => MasterIku::daftarUrutKode(),
            'triwulan' => $this->triwulanDari($this->bulan),
            'rtlSebelumnya' => $this->rtlTriwulanSebelumnya(),
            'rtlBerjalan' => $this->rtlTriwulanBerjalan(),
            'sudahAdaRtlBerikutnya' => $this->rtlTriwulanBerikutnyaSudahAda(),
            'labelBerikutnya' => $this->labelTriwulanBerikutnya(),
        ]);
    }
}
