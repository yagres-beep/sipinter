<?php

namespace App\Livewire;

use App\Exports\LakinExport;
use App\Models\Lakin;
use App\Models\LakinBaris;
use App\Models\MasterIku;
use App\Services\LakinBuilderService;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Livewire\Component;

/**
 * Detail satu dokumen LAKIN — tabel sasaran/indikator/target/realisasi/capaian %,
 * dapat disunting bebas (tambah/ubah/hapus baris) HANYA oleh Tim SAKIP; Ketua Tim
 * & Kepala melihat versi hanya-baca. Semua peran bisa mengunduh Excel-nya.
 */
class LakinDetail extends Component
{
    public Lakin $lakin;

    /**
     * @var array<int, array{sasaran: ?string, indikator: string, target: ?string, realisasi: ?string, capaian_persen: ?string}>
     */
    public array $edit = [];

    public string $sasaranBaru = '';

    public string $indikatorBaru = '';

    public string $targetBaru = '';

    public string $realisasiBaru = '';

    /**
     * IKU yang dicentang Tim SAKIP di checklist "Tambah dari Data Capaian" — id
     * MasterIku, bukan id LakinBaris (belum tentu ada barisnya).
     *
     * @var list<int>
     */
    public array $ikuTerpilihUntukTambah = [];

    public function mount(Lakin $lakin): void
    {
        $this->lakin = $lakin->load('baris');
        $this->muatEdit();
    }

    /**
     * IKU yang punya data capaian pada tahun LAKIN ini tapi BELUM jadi baris —
     * inilah yang ditawarkan di checklist, supaya Tim SAKIP tidak mencentang IKU
     * yang sudah ada di tabel.
     */
    public function ikuBelumDitambahkan()
    {
        $sudahAda = $this->lakin->baris->pluck('iku_id')->filter()->all();

        return app(LakinBuilderService::class)
            ->ikuTersediaUntukTahun($this->lakin->tahun)
            ->reject(fn ($data) => in_array($data->iku->id, $sudahAda, true));
    }

    protected function muatEdit(): void
    {
        $this->edit = [];

        foreach ($this->lakin->baris as $baris) {
            $this->edit[$baris->id] = [
                'sasaran' => $baris->sasaran,
                'indikator' => $baris->indikator,
                'target' => $baris->target !== null ? (string) $baris->target : '',
                'realisasi' => $baris->realisasi !== null ? (string) $baris->realisasi : '',
            ];
        }
    }

    public function isTimSakip(): bool
    {
        return auth()->user()->namaRole() === 'Tim SAKIP';
    }

    protected function pastikanSakip(): void
    {
        abort_unless($this->isTimSakip(), 403, 'Hanya Tim SAKIP yang dapat mengubah LAKIN.');
    }

    protected function hitungCapaianPersen(?string $target, ?string $realisasi): ?float
    {
        if (! is_numeric($target) || (float) $target <= 0 || ! is_numeric($realisasi)) {
            return null;
        }

        return round(((float) $realisasi / (float) $target) * 100, 2);
    }

    public function simpanBaris(int $id): void
    {
        $this->pastikanSakip();

        $data = $this->edit[$id] ?? null;

        if (! $data || trim($data['indikator']) === '') {
            $this->addError("edit.{$id}.indikator", 'Indikator tidak boleh kosong.');

            return;
        }

        LakinBaris::whereKey($id)->update([
            'sasaran' => trim($data['sasaran'] ?? '') ?: null,
            'indikator' => trim($data['indikator']),
            'target' => is_numeric($data['target']) ? $data['target'] : null,
            'realisasi' => is_numeric($data['realisasi']) ? $data['realisasi'] : null,
            'capaian_persen' => $this->hitungCapaianPersen($data['target'] ?? null, $data['realisasi'] ?? null),
        ]);

        $this->lakin->load('baris');
        $this->muatEdit();

        session()->flash('status', 'Baris tersimpan.');
    }

    public function tambahBaris(): void
    {
        $this->pastikanSakip();

        $this->validate([
            'indikatorBaru' => ['required', 'string'],
            'targetBaru' => ['nullable', 'numeric'],
            'realisasiBaru' => ['nullable', 'numeric'],
        ], [], ['indikatorBaru' => 'indikator']);

        $urutanBaru = ((int) $this->lakin->baris->max('urutan')) + 1;

        LakinBaris::create([
            'lakin_id' => $this->lakin->id,
            'sasaran' => trim($this->sasaranBaru) ?: null,
            'indikator' => trim($this->indikatorBaru),
            'target' => $this->targetBaru !== '' ? $this->targetBaru : null,
            'realisasi' => $this->realisasiBaru !== '' ? $this->realisasiBaru : null,
            'capaian_persen' => $this->hitungCapaianPersen($this->targetBaru, $this->realisasiBaru),
            'urutan' => $urutanBaru,
        ]);

        $this->reset(['sasaranBaru', 'indikatorBaru', 'targetBaru', 'realisasiBaru']);

        $this->lakin->load('baris');
        $this->muatEdit();

        session()->flash('status', 'Baris baru ditambahkan.');
    }

    public function hapusBaris(int $id): void
    {
        $this->pastikanSakip();

        LakinBaris::whereKey($id)->delete();
        unset($this->edit[$id]);

        $this->lakin->load('baris');

        session()->flash('status', 'Baris dihapus.');
    }

    /**
     * Tambahkan HANYA IKU yang dicentang Tim SAKIP di checklist — inilah satu-satunya
     * jalan baris berbasis IKU masuk ke LAKIN, tidak pernah otomatis-semua.
     */
    public function tambahDariCapaian(): void
    {
        $this->pastikanSakip();

        if (empty($this->ikuTerpilihUntukTambah)) {
            $this->addError('ikuTerpilihUntukTambah', 'Pilih minimal satu IKU untuk ditambahkan.');

            return;
        }

        app(LakinBuilderService::class)->tambahDariIku($this->lakin, $this->ikuTerpilihUntukTambah);

        $this->ikuTerpilihUntukTambah = [];
        $this->lakin->load('baris');
        $this->muatEdit();

        session()->flash('status', 'IKU terpilih berhasil ditambahkan ke LAKIN.');
    }

    /**
     * Segarkan ANGKA baris yang sudah ada (target/realisasi/capaian %) dari data
     * capaian terbaru — TIDAK menambah baris baru (itu lewat tambahDariCapaian())
     * dan tidak menyentuh baris custom.
     */
    public function segarkanAngka(): void
    {
        $this->pastikanSakip();

        app(LakinBuilderService::class)->segarkanBaris($this->lakin);

        $this->lakin->load('baris');
        $this->muatEdit();

        session()->flash('status', 'Angka baris yang sudah ada disegarkan dari data capaian terbaru. Baris custom tidak berubah.');
    }

    public function unduhExcel()
    {
        return ExcelFacade::download(new LakinExport($this->lakin), "lakin-{$this->lakin->tahun}.xlsx");
    }

    public function render()
    {
        return view('livewire.lakin-detail', [
            'ikuList' => MasterIku::daftarUrutKode(),
            'ikuBelumDitambahkan' => $this->ikuBelumDitambahkan(),
        ]);
    }
}
