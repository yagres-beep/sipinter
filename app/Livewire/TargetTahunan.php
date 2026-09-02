<?php

namespace App\Livewire;

use App\Models\CapaianTahunan;
use App\Models\MasterIku;
use App\Models\RincianN;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
 * verifikasi bulanan. Alokasi X diisi Tim SAKIP langsung sebagai angka KUMULATIF TW
 * I s.d. TW tsb (PERSIS seperti kolom M-P sheet "LK_Kabkot" resmi, mis. 1, 1, 2, 3
 * -- BUKAN kontribusi tiap TW yang dijumlahkan aplikasi, lihat
 * App\Models\CapaianTahunan::alokasiKumulatif()). Realisasi X TETAP diisi dari
 * VerifikasiCapaian (per bulan, per IKU, itu satu-satunya angka yang benar-benar
 * berubah tiap triwulan, TETAP nilai TRIWULAN ITU SENDIRI yang dijumlahkan otomatis
 * -- lihat App\Models\CapaianTahunan::realisasiKumulatif(), TIDAK berubah oleh RF
 * ini) -- Realisasi Y otomatis DISALIN dari Alokasi Y di sini setiap kali disimpan
 * (lihat simpan()), karena Y ("jumlah keseluruhan") bukan sesuatu yang dicapai
 * bertahap, nilainya sudah pasti sejak awal tahun sama seperti alokasinya. Target
 * Tahunan IKU 'rasio' TIDAK LAGI diketik terpisah (x_target/y_target lama, kolom
 * dibiarkan di DB tapi tidak dipakai lagi) -- SELALU sama dengan Alokasi Kumulatif
 * TW IV, ditampilkan otomatis (lihat blade) supaya tidak ada 2 tempat isian yang
 * bisa tidak sinkron. IKU 'langsung' (Non %) TIDAK terpengaruh -- Alokasi/Realisasi
 * & Target Tahunan-nya tetap diisi dari VerifikasiCapaian/kolom target_tahunan
 * seperti sebelumnya.
 */
class TargetTahunan extends Component
{
    public int $tahun;

    /**
     * Nilai form per IKU, dikunci pada iku_id — pola sama seperti $catatanBerkas
     * dkk. di App\Livewire\VerifikasiCapaian (Livewire tidak mendukung wire:model
     * langsung ke atribut model).
     *
     * x_target/y_target (Target Tahunan IKU 'rasio' lama, diketik terpisah) SENGAJA
     * TIDAK ADA di sini lagi -- lihat App\Models\CapaianTahunan::targetTahunan(),
     * sekarang selalu diturunkan dari x_alokasi_tw4/y_alokasi_tw4 di bawah.
     *
     * @var array<int, array{
     *     target_tahunan: ?float,
     *     x_alokasi_tw1: ?float, x_alokasi_tw2: ?float, x_alokasi_tw3: ?float, x_alokasi_tw4: ?float,
     *     y_alokasi_tw1: ?float, y_alokasi_tw2: ?float, y_alokasi_tw3: ?float, y_alokasi_tw4: ?float,
     * }>
     */
    public array $nilai = [];

    /**
     * Daftar Rincian N (App\Models\RincianN) per IKU bermetode Rasio (MasterIku::
     * pakaiRasio()) -- dikunci [iku_id][kunci_baris] => ['id' => ?int, 'uraian' => ?string], pola
     * sama seperti $rincianOutput di App\Livewire\VerifikasiCapaian. $kunci_baris
     * adalah id RincianN asli (baris sudah tersimpan) ATAU kunci sementara
     * "baru-..." (baris baru dari tambahN(), belum pernah disimpan). Jumlah baris
     * per iku_id menggantikan input manual Alokasi Y -- lihat simpan().
     *
     * @var array<int, array<string, array{id: int|null, uraian: string|null}>>
     */
    public array $rincianN = [];

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
            ];

            foreach (self::KOLOM_ALOKASI_TW as $kolom) {
                $dasar[$kolom] = $ct?->{$kolom};
            }

            return [$iku->id => $dasar];
        })->all();

        $this->rincianN = RincianN::whereIn('iku_id', $this->daftarIku()->pluck('id'))
            ->where('tahun', $this->tahun)
            ->orderBy('id')
            ->get()
            ->groupBy('iku_id')
            ->map(fn ($baris) => $baris->mapWithKeys(fn (RincianN $n) => [(string) $n->id => ['id' => $n->id, 'uraian' => $n->uraian]])->all())
            ->all();
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
        $rules = [
            'rincianN.*.*.uraian' => ['nullable', 'string', 'max:255'],
        ];

        foreach (array_keys($this->nilai) as $ikuId) {
            $rules["nilai.{$ikuId}.target_tahunan"] = ['nullable', 'numeric', 'min:0'];

            foreach (self::KOLOM_ALOKASI_TW as $kolom) {
                $rules["nilai.{$ikuId}.{$kolom}"] = ['nullable', 'numeric', 'min:0'];
            }
        }

        return $rules;
    }

    /**
     * Tambah satu baris Rincian N kosong untuk IKU ini -- hanya tersimpan ke DB
     * saat simpan() ditekan, pola sama seperti VerifikasiCapaian::tambahRo().
     */
    public function tambahN(int $ikuId): void
    {
        $kunciBaru = 'baru-'.(string) Str::uuid();
        $this->rincianN[$ikuId][$kunciBaru] = ['id' => null, 'uraian' => null];
    }

    /**
     * Hapus satu baris Rincian N -- baris yang SUDAH tersimpan (punya id asli)
     * dihapus dari DB seketika, baris baru yang belum disimpan cukup dibuang dari
     * state lokal. Pola sama seperti VerifikasiCapaian::hapusRo().
     */
    public function hapusN(int $ikuId, string $kunci): void
    {
        $id = $this->rincianN[$ikuId][$kunci]['id'] ?? null;
        if ($id) {
            RincianN::whereKey($id)->delete();
        }

        unset($this->rincianN[$ikuId][$kunci]);
    }

    /**
     * Tulis seluruh baris Rincian N satu IKU ke DB: baris kosong (uraian kosong)
     * dihapus bila sudah tersimpan sebelumnya atau dilewati bila memang baru
     * ditambahkan lewat tambahN() dan tidak pernah diisi; baris terisi
     * dibuat/diperbarui sesuai ada/tidaknya id asli. Pola sama seperti
     * VerifikasiCapaian::simpanRincianOutputKegiatan().
     */
    protected function simpanRincianNUntukIku(int $ikuId): void
    {
        foreach ($this->rincianN[$ikuId] ?? [] as $kunci => $baris) {
            $uraian = trim((string) ($baris['uraian'] ?? ''));

            if ($uraian === '') {
                if (! empty($baris['id'])) {
                    RincianN::whereKey($baris['id'])->delete();
                    $this->rincianN[$ikuId][$kunci]['id'] = null;
                }

                continue;
            }

            if (! empty($baris['id'])) {
                RincianN::whereKey($baris['id'])->update(['uraian' => $uraian]);
            } else {
                $n = RincianN::create(['iku_id' => $ikuId, 'tahun' => $this->tahun, 'uraian' => $uraian]);
                $this->rincianN[$ikuId][$kunci]['id'] = $n->id;
            }
        }
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

        $daftarIkuPerId = $this->daftarIku()->keyBy('id');

        DB::transaction(function () use ($daftarIkuPerId) {
            foreach ($this->nilai as $ikuId => $data) {
                $iku = $daftarIkuPerId->get($ikuId);
                $ct = CapaianTahunan::firstOrNew(['iku_id' => $ikuId, 'tahun' => $this->tahun]);

                // X diisi Tim SAKIP langsung sebagai angka KUMULATIF TW I s.d. TW tsb
                // (lihat CapaianTahunan::alokasiKumulatif(), dibaca APA ADANYA, TIDAK
                // dijumlahkan lagi di sini) -- beda dari Y di bawah yang tetap sengaja
                // memakai DUA pola berbeda (Alokasi Y vs Realisasi Y) supaya
                // realisasiKumulatif() (yang TETAP menjumlahkan X/Y realisasi lintas TW,
                // tidak berubah oleh RF ini) tetap benar.
                $xAlokasi = collect(['x_alokasi_tw1', 'x_alokasi_tw2', 'x_alokasi_tw3', 'x_alokasi_tw4'])
                    ->mapWithKeys(fn ($kolom) => [$kolom => $data[$kolom] ?? null]);

                // Alokasi Y HANYA diminta SEKALI di blade (satu input "Total", bukan
                // 4 kotak identik per TW -- Y tidak bertambah tiap triwulan) --
                // diulang SAMA di keempat TW di sini, supaya alokasiKumulatif() (yang
                // membaca y_alokasi_tw{$tw} APA ADANYA per TW, tanpa menjumlah) tetap
                // menghasilkan kumulatif Y yang konstan sepanjang tahun.
                //
                // Untuk IKU bermetode Rasio (MasterIku::pakaiRasio()), Alokasi Y BUKAN
                // input manual -- diganti COUNT baris Rincian N milik IKU+tahun ini
                // (lihat simpanRincianNUntukIku()), disimpan lebih dulu supaya
                // count-nya akurat sebelum dipakai di sini.
                if ($iku?->pakaiRasio()) {
                    $this->simpanRincianNUntukIku($ikuId);
                    $yTotal = (float) RincianN::where('iku_id', $ikuId)->where('tahun', $this->tahun)->count();
                } else {
                    $yTotal = $data['y_alokasi_tw1'] ?? null;
                }

                $yAlokasi = collect([
                    'y_alokasi_tw1' => $yTotal,
                    'y_alokasi_tw2' => $yTotal,
                    'y_alokasi_tw3' => $yTotal,
                    'y_alokasi_tw4' => $yTotal,
                ]);

                $alokasiTw = $xAlokasi->merge($yAlokasi);

                $adaIsi = $data['target_tahunan'] !== null || $alokasiTw->contains(fn ($v) => $v !== null);

                if (! $ct->exists && ! $adaIsi) {
                    continue;
                }

                $ct->fill([
                    'target_tahunan' => $data['target_tahunan'],
                    ...$alokasiTw->all(),
                    // Realisasi Y SELALU disalin dari (total) Alokasi Y (bukan isian
                    // terpisah) -- lihat docblock kelas: Y ("jumlah keseluruhan") sudah
                    // pasti sejak awal tahun, sama seperti alokasinya -- diulang SAMA di
                    // keempat TW, PERSIS pola Alokasi Y di atas (BUKAN nol di TW II-IV
                    // lagi): realisasiKumulatif() membaca y_realisasi_tw{$tw} LANGSUNG per
                    // TW untuk 'rasio' (TIDAK menjumlahkannya, beda dari x_realisasi_tw
                    // yang tetap dijumlah -- lihat docblock CapaianTahunan::
                    // realisasiKumulatif()), jadi nol di TW II-IV akan salah dibaca
                    // sebagai Y=0 pada TW itu alih-alih Y konstan.
                    'y_realisasi_tw1' => $yTotal,
                    'y_realisasi_tw2' => $yTotal,
                    'y_realisasi_tw3' => $yTotal,
                    'y_realisasi_tw4' => $yTotal,
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
