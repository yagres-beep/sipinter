<?php

namespace App\Livewire;

use App\Models\CapaianTahunan;
use App\Models\MasterIku;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Target Tahunan SELURUH IKU dalam satu tabel (RF terkait Kertas Kerja Pengukuran
 * Kinerja Triwulanan resmi, kolom "Target") — diisi Tim SAKIP SEKALI per tahun di
 * sini, dipakai otomatis di halaman Verifikasi setiap bulan/IKU (App\Livewire\
 * VerifikasiCapaian menampilkannya sebagai referensi readonly, bukan lagi form
 * isian). Sebelumnya field ini "nyempil" di dalam sesi verifikasi bulanan tiap
 * IKU (bisa dibuka 12x setahun x puluhan IKU) padahal nilainya sekali setahun —
 * dipisah ke sini supaya jelas: satu tempat, satu kali per tahun, semua IKU.
 *
 * Alokasi Target/Realisasi per triwulan TETAP diisi dari VerifikasiCapaian (per
 * bulan, per IKU) karena keduanya memang data triwulanan yang terikat ke sesi
 * verifikasi berjalan — hanya Target Tahunan yang dipindah ke sini.
 */
class TargetTahunan extends Component
{
    public int $tahun;

    /**
     * Nilai form per IKU, dikunci pada iku_id — pola sama seperti $catatanBerkas
     * dkk. di App\Livewire\VerifikasiCapaian (Livewire tidak mendukung wire:model
     * langsung ke atribut model).
     *
     * @var array<int, array{target_tahunan: ?float, x_target: ?float, y_target: ?float}>
     */
    public array $nilai = [];

    public function mount(): void
    {
        $this->tahun = (int) now()->year;
        $this->muatNilai();
    }

    public function updatedTahun(): void
    {
        $this->muatNilai();
    }

    protected function muatNilai(): void
    {
        $capaianTahunanPerIku = CapaianTahunan::where('tahun', $this->tahun)->get()->keyBy('iku_id');

        $this->nilai = $this->daftarIku()->mapWithKeys(function (MasterIku $iku) use ($capaianTahunanPerIku) {
            $ct = $capaianTahunanPerIku->get($iku->id);

            return [$iku->id => [
                'target_tahunan' => $ct?->target_tahunan,
                'x_target' => $ct?->x_target,
                'y_target' => $ct?->y_target,
            ]];
        })->all();
    }

    /**
     * Query LANGSUNG (bukan MasterIku::daftarUrutKode() yang di-cache 1 jam untuk
     * dropdown) — halaman ini bagian dari Data Master &amp; Konfigurasi, IKU yang
     * baru ditambahkan/diubah di tab lain pada sesi yang sama harus langsung
     * terlihat di sini, sama seperti alasan App\Livewire\MasterIku sendiri tidak
     * memakai cache tsb.
     */
    protected function daftarIku()
    {
        return MasterIku::orderBy('kode')->get();
    }

    protected function rules(): array
    {
        $rules = [];

        foreach (array_keys($this->nilai) as $ikuId) {
            $rules["nilai.{$ikuId}.target_tahunan"] = ['nullable', 'numeric', 'min:0'];
            $rules["nilai.{$ikuId}.x_target"] = ['nullable', 'numeric', 'min:0'];
            $rules["nilai.{$ikuId}.y_target"] = ['nullable', 'numeric', 'min:0'];
        }

        return $rules;
    }

    /**
     * Simpan seluruh baris sekaligus (satu tombol untuk semua IKU, sesuai
     * permintaan "satu menu yang sama") — HANYA menyentuh baris yang benar-benar
     * sudah punya isi (atau sudah pernah tersimpan sebelumnya), supaya IKU yang
     * belum disentuh sama sekali tidak ikut membuat baris CapaianTahunan kosong.
     */
    public function simpan(): void
    {
        $this->validate();

        DB::transaction(function () {
            foreach ($this->nilai as $ikuId => $data) {
                $ct = CapaianTahunan::firstOrNew(['iku_id' => $ikuId, 'tahun' => $this->tahun]);

                $adaIsi = $data['target_tahunan'] !== null || $data['x_target'] !== null || $data['y_target'] !== null;

                if (! $ct->exists && ! $adaIsi) {
                    continue;
                }

                $ct->fill([
                    'target_tahunan' => $data['target_tahunan'],
                    'x_target' => $data['x_target'],
                    'y_target' => $data['y_target'],
                ])->save();
            }
        });

        session()->flash('status', 'Target Tahunan tersimpan.');
    }

    public function render()
    {
        return view('livewire.target-tahunan', [
            'daftarIku' => $this->daftarIku(),
        ]);
    }
}
