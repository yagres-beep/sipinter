<?php

namespace App\Livewire;

use App\Models\Berkas;
use App\Models\KendalaSolusi as KendalaSolusiModel;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Services\FolderStructureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Ketua Tim — Kendala & Solusi (RF-26 s.d. RF-28).
 *
 * Kendala-solusi ditulis KUMULATIF per triwulan (RF-28): pasangan yang sudah
 * disimpan pada triwulan-triwulan SEBELUMNYA (dalam tahun yang sama) ditampilkan
 * sebagai riwayat hanya-baca, sementara pasangan BARU untuk periode yang sedang
 * dipilih ditambahkan lewat form di bawahnya — persis seperti notula nantinya
 * menampilkan "TW I: ... TW II: ..." sekaligus.
 */
class KendalaSolusi extends Component
{
    use WithFileUploads;

    public int $tahun;

    public int $bulan;

    public ?int $iku_id = null;

    /**
     * @var array<int, array{kendala: string, solusi: string, bukti_solusi: array}>
     */
    public array $blocks = [];

    public function mount(): void
    {
        $this->tahun = (int) now()->year;
        $this->bulan = (int) now()->month;
        $this->blocks = [$this->emptyBlock()];
    }

    protected function emptyBlock(): array
    {
        return [
            'kendala' => '',
            'solusi' => '',
            'bukti_solusi' => [],
        ];
    }

    public function addBlock(): void
    {
        $this->blocks[] = $this->emptyBlock();
    }

    public function removeBlock(int $index): void
    {
        unset($this->blocks[$index]);
        $this->blocks = array_values($this->blocks);
    }

    public function removeBukti(int $blockIndex, int $fileIndex): void
    {
        unset($this->blocks[$blockIndex]['bukti_solusi'][$fileIndex]);
        $this->blocks[$blockIndex]['bukti_solusi'] = array_values($this->blocks[$blockIndex]['bukti_solusi']);
    }

    protected function triwulanDari(int $bulan): int
    {
        return (int) ceil($bulan / 3);
    }

    protected function bulanKeDari(int $bulan): int
    {
        return $bulan - ($this->triwulanDari($bulan) - 1) * 3;
    }

    protected function rules(): array
    {
        return [
            'tahun' => ['required', 'integer', 'min:2020', 'max:2100'],
            'bulan' => ['required', 'integer', 'min:1', 'max:12'],
            'iku_id' => ['required', 'exists:master_iku,id'],
            'blocks' => ['required', 'array', 'min:1'],
            'blocks.*.kendala' => ['required', 'string'],
            'blocks.*.solusi' => ['nullable', 'string'],
            // RF-27: solusi diisi -> bukti dukung solusi WAJIB diunggah.
            'blocks.*.bukti_solusi' => ['required_with:blocks.*.solusi', 'array'],
            'blocks.*.bukti_solusi.*' => ['file', 'mimes:pdf', 'max:10240'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'iku_id' => 'IKU',
            'blocks.*.kendala' => 'kendala',
            'blocks.*.solusi' => 'solusi',
            'blocks.*.bukti_solusi' => 'bukti dukung solusi',
        ];
    }

    protected function messages(): array
    {
        return [
            'blocks.*.bukti_solusi.required_with' => 'Bukti dukung solusi (PDF) wajib diunggah bila kolom solusi diisi.',
        ];
    }

    /**
     * Riwayat kumulatif (RF-28): seluruh pasangan kendala-solusi milik IKU terpilih,
     * dari triwulan 1 sampai triwulan yang SEDANG berjalan, pada tahun yang sama —
     * dikelompokkan per triwulan supaya tampilannya persis seperti nanti muncul di notula.
     *
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, KendalaSolusiModel>>
     */
    protected function riwayatKumulatif()
    {
        if (! $this->iku_id) {
            return collect();
        }

        $triwulanSekarang = $this->triwulanDari($this->bulan);

        return KendalaSolusiModel::with(['periode', 'berkas'])
            ->where('iku_id', $this->iku_id)
            ->whereHas('periode', function ($q) use ($triwulanSekarang) {
                $q->where('tahun', $this->tahun)->where('triwulan', '<=', $triwulanSekarang);
            })
            ->get()
            ->sortBy(fn ($item) => $item->periode->triwulan)
            ->groupBy(fn ($item) => $item->periode->triwulan);
    }

    public function simpan(): void
    {
        $this->validate();

        $periode = Periode::firstOrCreate(
            ['tahun' => $this->tahun, 'bulan' => $this->bulan],
            [
                'triwulan' => $this->triwulanDari($this->bulan),
                'bulan_ke' => $this->bulanKeDari($this->bulan),
                'flag_bulan_terlewat' => ! ($this->tahun === (int) now()->year && $this->bulan === (int) now()->month),
            ]
        );

        $iku = MasterIku::findOrFail($this->iku_id);
        $folderService = app(FolderStructureService::class);

        DB::transaction(function () use ($periode, $iku, $folderService) {
            foreach ($this->blocks as $block) {
                if (trim($block['kendala']) === '' && trim($block['solusi']) === '') {
                    continue;
                }

                $entry = KendalaSolusiModel::create([
                    'iku_id' => $this->iku_id,
                    'periode_id' => $periode->id,
                    'kendala' => $block['kendala'],
                    'solusi' => $block['solusi'] ?: null,
                ]);

                foreach ($block['bukti_solusi'] as $file) {
                    $path = $file->store('bukti-solusi', 'local');

                    $berkas = Berkas::create([
                        'ref_id' => $entry->id,
                        'ref_type' => KendalaSolusiModel::class,
                        'kategori' => 'solusi',
                        'nama_file' => $file->getClientOriginalName(),
                        'path' => $path,
                        'status_verifikasi' => 'menunggu',
                    ]);

                    // Sama seperti PengisianKegiatan: dibungkus try/catch supaya isian
                    // tetap tersimpan walau Drive belum terkonfigurasi (berkas tetap
                    // aman di disk lokal sebagai cadangan).
                    try {
                        $localFullPath = Storage::disk('local')->path($path);
                        $hasilDrive = $folderService->unggahBerkas($periode, $iku, 'solusi', $localFullPath);
                        $berkas->update($hasilDrive);
                    } catch (RuntimeException $e) {
                        Log::warning('Gagal mengunggah bukti solusi ke Google Drive, disimpan lokal saja: '.$e->getMessage());
                    }
                }
            }
        });

        session()->flash('status', 'Kendala & solusi berhasil disimpan.');

        $this->blocks = [$this->emptyBlock()];
    }

    public function render()
    {
        $triwulan = $this->triwulanDari($this->bulan);

        return view('livewire.kendala-solusi', [
            'ikuList' => MasterIku::daftarUrutKode(),
            'riwayat' => $this->riwayatKumulatif(),
            'triwulan' => $triwulan,
            'periodeLabel' => \Illuminate\Support\Carbon::create($this->tahun, $this->bulan, 1)->locale('id')->translatedFormat('F Y'),
        ]);
    }
}
