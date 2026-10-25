<?php

namespace App\Livewire;

use App\Models\CapaianTahunan;
use App\Models\MasterIku;
use App\Models\RincianN;
use App\Support\JejakRincianN;
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
 * Alokasi Target per triwulan JUGA diisi SEKALI di sini bareng Target Tahunan --
 * sesuai Kertas Kerja resmi, alokasi per triwulan sudah ditetapkan di awal tahun,
 * bukan diketik ulang tiap sesi verifikasi bulanan -- berlaku untuk KEDUA Jenis
 * Nilai IKU (bukan cuma "%"):
 * - 'rasio' (%): Pembilang (X)/Penyebut (Y) diisi Tim SAKIP langsung sebagai angka
 *   KUMULATIF TW I s.d. TW tsb (PERSIS seperti kolom M-P sheet "LK_Kabkot" resmi,
 *   mis. 1, 1, 2, 3 -- BUKAN kontribusi tiap TW yang dijumlahkan aplikasi, lihat
 *   App\Models\CapaianTahunan::alokasiKumulatif()).
 * - 'langsung' (Non %): SATU angka per TW (bukan pasangan X/Y) diisi Tim SAKIP
 *   langsung sebagai angka KUMULATIF juga -- verifikasi terhadap sheet "LK_Kabkot"
 *   resmi menunjukkan baris Non % pun M-P berisi angka kumulatif naik terus (mis.
 *   0, 1.04, 4.05, 4.35), SAMA seperti pola "%" di atas, BUKAN kontribusi
 *   per-triwulan yang perlu dijumlah manual (beda dari asumsi versi lama).
 *
 * Realisasi TETAP diisi dari VerifikasiCapaian (per bulan, per IKU) -- untuk
 * 'rasio' lewat checklist Rincian N (Realisasi X, dijumlahkan otomatis lintas TW,
 * lihat App\Models\CapaianTahunan::realisasiKumulatif()); untuk 'langsung' juga
 * diketik langsung sebagai angka KUMULATIF (SAMA pola dengan Alokasi di atas,
 * TIDAK dijumlahkan lagi) -- TIDAK berubah oleh RF ini: kedua Realisasi tetap di
 * VerifikasiCapaian, bukan di sini. Realisasi Y (khusus 'rasio') otomatis DISALIN
 * dari Alokasi Y di sini setiap kali disimpan (lihat simpan()), karena Y ("jumlah
 * keseluruhan") bukan sesuatu yang dicapai bertahap, nilainya sudah pasti sejak
 * awal tahun sama seperti alokasinya.
 *
 * Target Tahunan (KEDUA metode) TIDAK LAGI diketik terpisah (target_tahunan/
 * x_target/y_target lama, kolom dibiarkan di DB tapi tidak dipakai lagi) -- SELALU
 * sama dengan Alokasi Kumulatif TW IV, ditampilkan otomatis (lihat blade) supaya
 * tidak ada 2 tempat isian yang bisa tidak sinkron.
 *
 * Pemisahan N dan n (IKU 'rasio'):
 * - Angka total N diketik di sini ($angkaN) dan menjadi satu-satunya sumber
 *   y_alokasi_tw1..4. Jumlah rincian target tidak pernah menimpanya.
 * - Nilai dasar n (y_realisasi_tw1..4) disamakan dengan N setiap simpan; pembilang
 *   realisasi (x_realisasi_tw1..4) tidak pernah disentuh dari sini.
 * - Rincian target dikelola di sini; rincian tambahan n (dari Verifikasi) tidak
 *   dimuat dan tidak bisa diubah dari sini.
 */
class TargetTahunan extends Component
{
    /** Nama menu di log aktivitas. */
    private const MENU = 'Target Tahunan';

    public int $tahun;

    /**
     * Nilai form per IKU, dikunci pada iku_id — pola sama seperti $catatanBerkas
     * dkk. di App\Livewire\VerifikasiCapaian (Livewire tidak mendukung wire:model
     * langsung ke atribut model).
     *
     * target_tahunan/x_target/y_target (Target Tahunan lama, diketik terpisah)
     * SENGAJA TIDAK ADA di sini lagi -- lihat App\Models\CapaianTahunan::
     * targetTahunan(), sekarang SELALU diturunkan dari Alokasi TW IV (x_alokasi_tw4/
     * y_alokasi_tw4 untuk 'rasio', alokasi_tw4 untuk 'langsung') di bawah.
     *
     * @var array<int, array{
     *     alokasi_tw1: ?float, alokasi_tw2: ?float, alokasi_tw3: ?float, alokasi_tw4: ?float,
     *     x_alokasi_tw1: ?float, x_alokasi_tw2: ?float, x_alokasi_tw3: ?float, x_alokasi_tw4: ?float,
     *     y_alokasi_tw1: ?float, y_alokasi_tw2: ?float, y_alokasi_tw3: ?float, y_alokasi_tw4: ?float,
     * }>
     */
    public array $nilai = [];

    /**
     * Angka total N per IKU bermetode Rasio, dikunci iku_id -- DIKETIK Tim SAKIP,
     * bukan dihitung dari jumlah rincian (jumlah rincian boleh berbeda). Disimpan
     * ke y_alokasi_tw1..4 dan nilai dasar n (y_realisasi_tw1..4) di simpan();
     * berlaku sama untuk keempat TW.
     *
     * @var array<int, float|string|null>
     */
    public array $angkaN = [];

    /**
     * Daftar Rincian N (App\Models\RincianN) per IKU bermetode Rasio (MasterIku::
     * pakaiRasio()) -- dikunci [iku_id][kunci_baris] => ['id' => ?int, 'uraian' => ?string], pola
     * sama seperti $rincianOutput di App\Livewire\VerifikasiCapaian. $kunci_baris
     * adalah id RincianN asli (baris sudah tersimpan) ATAU kunci sementara
     * "baru-..." (baris baru dari tambahN(), belum pernah disimpan). Jumlah baris
     * TIDAK menentukan angka N -- lihat $angkaN.
     *
     * Hanya baris sumber 'target' yang belum dilepas -- rincian tambahan dari
     * Verifikasi tidak pernah dimuat, jadi tidak bisa terhapus/tertimpa dari sini.
     *
     * @var array<int, array<string, array{id: int|null, uraian: string|null, terealisasi?: bool}>>
     */
    public array $rincianN = [];

    /**
     * Id rincian N tersimpan yang ditandai hapus lewat hapusN() -- baru diproses
     * saat simpan() (lihat lepasAtauHapusRincianN()), tidak seketika.
     *
     * @var array<int, list<int>>
     */
    public array $rincianNDihapus = [];

    /**
     * Kolom Alokasi per triwulan, dipakai berulang di muatNilai()/rules()/simpan()
     * supaya urutan TW1-4 selalu konsisten. alokasi_twN dipakai IKU 'langsung'
     * (Non %, satu angka kumulatif), x_alokasi_twN/y_alokasi_twN dipakai IKU
     * 'rasio' (%, pasangan pembilang/penyebut) -- keduanya sekaligus dimuat untuk
     * setiap IKU (kolom yang tidak relevan untuk metode IKU tsb cukup diabaikan
     * blade, tidak perlu dipisah per cabang di sini).
     */
    private const KOLOM_ALOKASI_TW = [
        'alokasi_tw1', 'alokasi_tw2', 'alokasi_tw3', 'alokasi_tw4',
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

            $dasar = [];

            foreach (self::KOLOM_ALOKASI_TW as $kolom) {
                $dasar[$kolom] = $ct?->{$kolom};
            }

            return [$iku->id => $dasar];
        })->all();

        $this->angkaN = $this->daftarIku()
            ->filter(fn (MasterIku $iku) => $iku->pakaiRasio())
            ->mapWithKeys(fn (MasterIku $iku) => [$iku->id => $capaianTahunanPerIku->get($iku->id)?->angkaN()])
            ->all();

        $this->rincianN = RincianN::whereIn('iku_id', $this->daftarIku()->pluck('id'))
            ->where('tahun', $this->tahun)
            ->targetAktif()
            ->orderBy('id')
            ->get()
            ->groupBy('iku_id')
            ->map(fn ($baris) => $baris->mapWithKeys(fn (RincianN $n) => [(string) $n->id => [
                'id' => $n->id,
                'uraian' => $n->uraian,
                'terealisasi' => $n->isTerealisasi(),
            ]])->all())
            ->all();

        $this->rincianNDihapus = [];
    }

    /**
     * Jumlah rincian tambahan (dari Verifikasi) per IKU tahun ini -- hanya info di
     * blade, tidak pernah masuk N.
     *
     * @return array<int, int>
     */
    public function jumlahRincianTambahan(): array
    {
        return RincianN::where('tahun', $this->tahun)
            ->where('sumber', RincianN::SUMBER_VERIFIKASI)
            ->selectRaw('iku_id, COUNT(*) as jumlah')
            ->groupBy('iku_id')
            ->pluck('jumlah', 'iku_id')
            ->map(fn ($j) => (int) $j)
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
            'angkaN.*' => ['nullable', 'numeric', 'min:0'],
        ];

        foreach (array_keys($this->nilai) as $ikuId) {
            foreach (self::KOLOM_ALOKASI_TW as $kolom) {
                $rules["nilai.{$ikuId}.{$kolom}"] = ['nullable', 'numeric', 'min:0'];
            }
        }

        return $rules;
    }

    protected function validationAttributes(): array
    {
        return collect(array_keys($this->angkaN))
            ->mapWithKeys(fn ($ikuId) => ["angkaN.{$ikuId}" => 'angka N'])
            ->all();
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
     * Tandai satu baris Rincian N untuk dihapus -- baris tersimpan baru diproses
     * saat simpan() (lihat lepasAtauHapusRincianN()), baris baru yang belum
     * disimpan cukup dibuang dari state lokal.
     */
    public function hapusN(int $ikuId, string $kunci): void
    {
        $id = $this->rincianN[$ikuId][$kunci]['id'] ?? null;
        if ($id) {
            $this->rincianNDihapus[$ikuId][] = (int) $id;
        }

        unset($this->rincianN[$ikuId][$kunci]);
    }

    /**
     * Isi angka N di form dengan jumlah rincian target yang sedang terisi -- hanya
     * bantuan, baru tersimpan saat simpan(). N tidak pernah disamakan otomatis.
     */
    public function samakanNDenganRincian(int $ikuId): void
    {
        $jumlah = collect($this->rincianN[$ikuId] ?? [])
            ->filter(fn ($baris) => trim((string) ($baris['uraian'] ?? '')) !== '')
            ->count();

        $this->angkaN[$ikuId] = $jumlah;
    }

    /**
     * Rincian target yang dikeluarkan dari N: yang belum terealisasi dihapus,
     * yang sudah terealisasi hanya dilepas dari target (realisasinya tetap
     * tercatat & tetap dihitung di n, tapi tidak lagi ada di daftar target). Hanya
     * menyentuh baris sumber 'target' milik IKU+tahun ini. Angka N tidak berubah.
     */
    protected function lepasAtauHapusRincianN(int $ikuId, int $id): void
    {
        $n = RincianN::milik($ikuId, $this->tahun)->targetAktif()->whereKey($id)->first();

        if (! $n) {
            return;
        }

        if ($n->isTerealisasi()) {
            $n->update(['dilepas_dari_target_at' => now(), 'diubah_oleh' => auth()->id()]);
            JejakRincianN::rincian('rincian_n.dilepas', $n, self::MENU, [
                'status' => ['sebelum' => 'rincian target aktif', 'sesudah' => 'dilepas dari target, realisasi '.JejakRincianN::statusRealisasi($n->triwulan_realisasi).' tetap tercatat'],
            ]);
        } else {
            JejakRincianN::rincian('rincian_n.dihapus', $n, self::MENU, ['sebelum' => ['uraian' => $n->uraian], 'sesudah' => []]);
            $n->delete();
        }
    }

    /**
     * Tulis perubahan rincian N satu IKU ke DB berdasarkan perubahan nyata saja:
     * baris yang ditandai hapus/dikosongkan dilepas atau dihapus, baris terisi
     * dibuat/diperbarui sesuai ada/tidaknya id asli (uraian yang tidak berubah
     * tidak ditulis ulang). Karena rincian n yang mengikuti target adalah baris
     * yang sama, perubahan di sini langsung berlaku di daftar n tanpa salinan.
     * Rincian tambahan (sumber 'verifikasi') tidak pernah disentuh.
     */
    protected function simpanRincianNUntukIku(int $ikuId): void
    {
        foreach (array_unique($this->rincianNDihapus[$ikuId] ?? []) as $id) {
            $this->lepasAtauHapusRincianN($ikuId, (int) $id);
        }
        unset($this->rincianNDihapus[$ikuId]);

        foreach ($this->rincianN[$ikuId] ?? [] as $kunci => $baris) {
            $uraian = trim((string) ($baris['uraian'] ?? ''));

            if ($uraian === '') {
                if (! empty($baris['id'])) {
                    $this->lepasAtauHapusRincianN($ikuId, (int) $baris['id']);
                    $this->rincianN[$ikuId][$kunci]['id'] = null;
                }

                continue;
            }

            if (! empty($baris['id'])) {
                $n = RincianN::milik($ikuId, $this->tahun)->targetAktif()->whereKey($baris['id'])->first();

                if ($n && $n->uraian !== $uraian) {
                    $lama = $n->uraian;
                    $n->update(['uraian' => $uraian, 'diubah_oleh' => auth()->id()]);
                    JejakRincianN::rincian('rincian_n.diubah', $n, self::MENU, ['uraian' => ['sebelum' => $lama, 'sesudah' => $uraian]]);
                }
            } else {
                $n = RincianN::create([
                    'iku_id' => $ikuId,
                    'tahun' => $this->tahun,
                    'uraian' => $uraian,
                    'sumber' => RincianN::SUMBER_TARGET,
                    'triwulan_mulai_berlaku' => 1,
                    'dibuat_oleh' => auth()->id(),
                ]);
                $this->rincianN[$ikuId][$kunci]['id'] = $n->id;
                JejakRincianN::rincian('rincian_n.dibuat', $n, self::MENU, ['sebelum' => [], 'sesudah' => ['uraian' => $uraian]]);
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

                // X (rasio) & alokasi_tw (langsung) diisi Tim SAKIP langsung sebagai
                // angka KUMULATIF TW I s.d. TW tsb (lihat CapaianTahunan::
                // alokasiKumulatif(), dibaca APA ADANYA, TIDAK dijumlahkan lagi di sini)
                // -- beda dari Y di bawah yang tetap sengaja memakai DUA pola berbeda
                // (Alokasi Y vs Realisasi Y) supaya realisasiKumulatif() (yang TETAP
                // menjumlahkan X realisasi lintas TW untuk 'rasio') tetap benar.
                $alokasi = collect([
                    'alokasi_tw1', 'alokasi_tw2', 'alokasi_tw3', 'alokasi_tw4',
                    'x_alokasi_tw1', 'x_alokasi_tw2', 'x_alokasi_tw3', 'x_alokasi_tw4',
                ])->mapWithKeys(fn ($kolom) => [$kolom => $this->angkaAtauNull($data[$kolom] ?? null)]);

                // Angka N (Alokasi Y) HANYA berlaku IKU bermetode Rasio -- SATU angka
                // yang diketik Tim SAKIP ($angkaN), diulang SAMA di keempat TW supaya
                // alokasiKumulatif() (yang membaca y_alokasi_tw{$tw} APA ADANYA per TW)
                // tetap konstan sepanjang tahun. TIDAK lagi diganti jumlah baris rincian:
                // menambah/menghapus rincian tidak mengubah N, dan rincian tambahan dari
                // Verifikasi tidak pernah menyentuhnya. IKU 'langsung' tidak punya Y --
                // kolom y_* dibiarkan apa adanya (tidak pernah dibaca untuk metode ini).
                $yTotal = null;

                if ($iku?->pakaiRasio()) {
                    $this->simpanRincianNUntukIku($ikuId);
                    $yTotal = $this->angkaAtauNull($this->angkaN[$ikuId] ?? null);
                    $nLama = $ct->exists ? $ct->angkaN() : null;

                    if ($nLama !== $yTotal) {
                        JejakRincianN::nDiubah($iku, $this->tahun, $nLama, $yTotal, self::MENU);
                    }

                    $alokasi = $alokasi->merge([
                        'y_alokasi_tw1' => $yTotal,
                        'y_alokasi_tw2' => $yTotal,
                        'y_alokasi_tw3' => $yTotal,
                        'y_alokasi_tw4' => $yTotal,
                        // Nilai dasar n (Realisasi Y) SELALU mengikuti angka N -- diulang
                        // SAMA di keempat TW (BUKAN nol di TW II-IV): realisasiKumulatif()
                        // membaca y_realisasi_tw{$tw} LANGSUNG per TW sebagai penyebut.
                        // Pembilang (x_realisasi_tw, rincian yang benar-benar terealisasi)
                        // TIDAK disentuh: N naik tidak berarti target baru sudah terealisasi.
                        // Menulis nilai yang sama berulang tidak mengubah apa pun (save()
                        // hanya menulis kolom yang benar-benar berubah).
                        'y_realisasi_tw1' => $yTotal,
                        'y_realisasi_tw2' => $yTotal,
                        'y_realisasi_tw3' => $yTotal,
                        'y_realisasi_tw4' => $yTotal,
                    ]);
                }

                $adaIsi = $alokasi->contains(fn ($v) => $v !== null);

                if (! $ct->exists && ! $adaIsi) {
                    continue;
                }

                $ct->fill($alokasi->all())->save();
            }
        });

        // Muat ulang supaya rincian yang dilepas dari target hilang dari daftar N.
        $this->muatNilai();

        session()->flash('status', 'Target Tahunan tersimpan.');
    }

    /**
     * Livewire menyisakan string kosong "" (bukan null) untuk input angka yang
     * dikosongkan pengguna -- kolom-kolom ini di-cast 'decimal:2' di
     * App\Models\CapaianTahunan, dan Brick\Math (dipakai cast decimal Laravel)
     * melempar exception untuk "" (bukan angka valid), beda dari null yang
     * aman. Konversi di sini SEBELUM fill() supaya nilai kosong konsisten
     * tersimpan/terbaca sebagai null, sama seperti App\Livewire\
     * VerifikasiCapaian::angkaAtauNull().
     */
    private function angkaAtauNull(mixed $nilai): ?float
    {
        return $nilai === null || $nilai === '' ? null : (float) $nilai;
    }

    public function render()
    {
        return view('livewire.target-tahunan', [
            'daftarIku' => $this->daftarIku(),
            'jumlahTambahan' => $this->jumlahRincianTambahan(),
        ]);
    }
}
