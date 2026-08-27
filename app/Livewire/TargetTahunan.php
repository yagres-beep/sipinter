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
 * Alokasi Target Pembilang(X)/Penyebut(Y) per triwulan (IKU 'rasio') JUGA diisi
 * SEKALI di sini bareng Target Tahunan -- sesuai Kertas Kerja resmi, alokasi X/Y
 * per triwulan sudah ditetapkan di awal tahun, bukan diketik ulang tiap sesi
 * verifikasi bulanan. Realisasi X TETAP diisi dari VerifikasiCapaian (per bulan,
 * per IKU, itu satu-satunya angka yang benar-benar berubah tiap triwulan) --
 * Realisasi Y otomatis DISALIN dari Alokasi Y di sini setiap kali disimpan (lihat
 * simpan()), karena Y ("jumlah keseluruhan") bukan sesuatu yang dicapai bertahap,
 * nilainya sudah pasti sejak awal tahun sama seperti alokasinya. IKU 'langsung'
 * (Non %) TIDAK terpengaruh -- Alokasi/Realisasi-nya tetap diisi dari
 * VerifikasiCapaian seperti sebelumnya.
 */
class TargetTahunan extends Component
{
    public int $tahun;

    /**
     * Nilai form per IKU, dikunci pada iku_id — pola sama seperti $catatanBerkas
     * dkk. di App\Livewire\VerifikasiCapaian (Livewire tidak mendukung wire:model
     * langsung ke atribut model).
     *
     * @var array<int, array{
     *     target_tahunan: ?float, x_target: ?float, y_target: ?float,
     *     x_alokasi_tw1: ?float, x_alokasi_tw2: ?float, x_alokasi_tw3: ?float, x_alokasi_tw4: ?float,
     *     y_alokasi_tw1: ?float, y_alokasi_tw2: ?float, y_alokasi_tw3: ?float, y_alokasi_tw4: ?float,
     * }>
     */
    public array $nilai = [];

    /**
     * Jenis Nilai (rasio/langsung) per IKU, dikunci pada iku_id -- default dari
     * MasterIku::metode_capaian yang sudah tersimpan, tapi bisa DIREVISI dari sini
     * (dropdown di blade) kalau ternyata perlu diubah, tanpa harus pindah ke tab
     * Master IKU. BUKAN data per-tahun (metode_capaian bukan kolom CapaianTahunan,
     * jadi TIDAK ikut dimuat ulang oleh updatedTahun()/muatNilai() seperti $nilai)
     * -- lihat simpan(), yang menuliskannya balik ke MasterIku bila berubah.
     *
     * @var array<int, string>
     */
    public array $jenisNilai = [];

    /**
     * Kolom Alokasi X/Y per triwulan, dipakai berulang di muatNilai()/rules()/simpan()
     * supaya urutan TW1-4 selalu konsisten.
     */
    private const KOLOM_ALOKASI_TW = [
        'x_alokasi_tw1', 'x_alokasi_tw2', 'x_alokasi_tw3', 'x_alokasi_tw4',
        'y_alokasi_tw1', 'y_alokasi_tw2', 'y_alokasi_tw3', 'y_alokasi_tw4',
    ];

    public function mount(): void
    {
        $this->tahun = (int) now()->year;
        $this->muatNilai();
        $this->jenisNilai = $this->daftarIku()->mapWithKeys(fn (MasterIku $iku) => [$iku->id => $iku->metode_capaian])->all();
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

            $dasar = [
                'target_tahunan' => $ct?->target_tahunan,
                'x_target' => $ct?->x_target,
                'y_target' => $ct?->y_target,
            ];

            foreach (self::KOLOM_ALOKASI_TW as $kolom) {
                $dasar[$kolom] = $ct?->{$kolom};
            }

            return [$iku->id => $dasar];
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

        foreach (array_keys($this->jenisNilai) as $ikuId) {
            $rules["jenisNilai.{$ikuId}"] = ['required', 'string', 'in:'.MasterIku::METODE_RASIO.','.MasterIku::METODE_LANGSUNG];
        }

        foreach (array_keys($this->nilai) as $ikuId) {
            $rules["nilai.{$ikuId}.target_tahunan"] = ['nullable', 'numeric', 'min:0'];
            $rules["nilai.{$ikuId}.x_target"] = ['nullable', 'numeric', 'min:0'];
            $rules["nilai.{$ikuId}.y_target"] = ['nullable', 'numeric', 'min:0'];

            foreach (self::KOLOM_ALOKASI_TW as $kolom) {
                $rules["nilai.{$ikuId}.{$kolom}"] = ['nullable', 'numeric', 'min:0'];
            }
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
            // Revisi Jenis Nilai (rasio/langsung) DITULIS BALIK ke MasterIku bila
            // berbeda dari yang tersimpan -- lihat docblock $jenisNilai. Data lama
            // di kolom yang jadi "tidak dipakai" (mis. target_tahunan lama saat
            // pindah ke rasio) SENGAJA dibiarkan apa adanya, bukan dihapus, supaya
            // tidak hilang kalau ternyata direvisi balik lagi.
            $ikuBerubah = MasterIku::whereIn('id', array_keys($this->jenisNilai))->get()->keyBy('id');
            foreach ($this->jenisNilai as $ikuId => $jenis) {
                $iku = $ikuBerubah->get($ikuId);
                if ($iku && $iku->metode_capaian !== $jenis) {
                    $iku->update(['metode_capaian' => $jenis]);
                }
            }
            MasterIku::lupakanCache();

            foreach ($this->nilai as $ikuId => $data) {
                $ct = CapaianTahunan::firstOrNew(['iku_id' => $ikuId, 'tahun' => $this->tahun]);

                $xAlokasi = collect(['x_alokasi_tw1', 'x_alokasi_tw2', 'x_alokasi_tw3', 'x_alokasi_tw4'])
                    ->mapWithKeys(fn ($kolom) => [$kolom => $data[$kolom] ?? null]);

                // Alokasi Y HANYA diminta SEKALI di blade (satu input "Total", bukan
                // 4 kotak identik per TW -- Y tidak bertambah tiap triwulan) --
                // diratakan jadi TW I di sini & TW II-IV otomatis 0, supaya struktur
                // 4-kolom-per-TW yang dipakai alokasiKumulatif()/realisasiKumulatif()
                // (raw per-TW DIJUMLAHKAN, lihat CapaianTahunan) tetap menghasilkan
                // kumulatif Y yang konstan sepanjang tahun.
                $yTotal = $data['y_alokasi_tw1'] ?? null;
                $yAlokasi = collect([
                    'y_alokasi_tw1' => $yTotal,
                    'y_alokasi_tw2' => $yTotal !== null ? 0.0 : null,
                    'y_alokasi_tw3' => $yTotal !== null ? 0.0 : null,
                    'y_alokasi_tw4' => $yTotal !== null ? 0.0 : null,
                ]);

                $alokasiTw = $xAlokasi->merge($yAlokasi);

                $adaIsi = $data['target_tahunan'] !== null || $data['x_target'] !== null || $data['y_target'] !== null
                    || $alokasiTw->contains(fn ($v) => $v !== null);

                if (! $ct->exists && ! $adaIsi) {
                    continue;
                }

                $ct->fill([
                    'target_tahunan' => $data['target_tahunan'],
                    'x_target' => $data['x_target'],
                    'y_target' => $data['y_target'],
                    ...$alokasiTw->all(),
                    // Realisasi Y SELALU disalin dari Alokasi Y (bukan isian terpisah) --
                    // lihat docblock kelas: Y ("jumlah keseluruhan") sudah pasti sejak
                    // awal tahun, sama seperti alokasinya, jadi CapaianTahunan::
                    // realisasiKumulatif() tetap menghitung Y yang benar tanpa Tim SAKIP
                    // perlu mengisi Realisasi Y berulang tiap triwulan di Verifikasi Capaian.
                    'y_realisasi_tw1' => $yAlokasi['y_alokasi_tw1'],
                    'y_realisasi_tw2' => $yAlokasi['y_alokasi_tw2'],
                    'y_realisasi_tw3' => $yAlokasi['y_alokasi_tw3'],
                    'y_realisasi_tw4' => $yAlokasi['y_alokasi_tw4'],
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
