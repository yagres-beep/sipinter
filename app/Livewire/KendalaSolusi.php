<?php

namespace App\Livewire;

use App\Models\KendalaSolusi as KendalaSolusiModel;
use App\Models\MasterIku;
use App\Models\Periode;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

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
    public int $tahun;

    public int $bulan;

    public ?int $iku_id = null;

    /**
     * @var array<int, array{kendala: string, solusi: string}>
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
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'iku_id' => 'IKU',
            'blocks.*.kendala' => 'kendala',
            'blocks.*.solusi' => 'solusi',
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

        DB::transaction(function () use ($periode) {
            foreach ($this->blocks as $block) {
                if (trim($block['kendala']) === '' && trim($block['solusi']) === '') {
                    continue;
                }

                KendalaSolusiModel::create([
                    'iku_id' => $this->iku_id,
                    'periode_id' => $periode->id,
                    'kendala' => $block['kendala'],
                    'solusi' => $block['solusi'] ?: null,
                ]);
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
