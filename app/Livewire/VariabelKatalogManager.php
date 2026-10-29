<?php

namespace App\Livewire;

use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\LogAktivitas;
use App\Models\VariabelKatalog;
use App\Support\FormatAngkaKolom;
use App\Support\JejakAktivitasBagianKustom;
use App\Support\JenisKolomBagianKustom;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Tim SAKIP — katalog variabel yang dipakai ulang oleh kolom Bagian Kustom. Kolom yang
 * menautkan variabel mengikuti jenis, opsi, format angka, dan batasnya.
 *
 * Perubahan definisi tidak pernah mengonversi nilai tersimpan: mengganti jenis atau
 * membuang opsi ditolak bila ada nilai tersimpan pada kolom bertaut yang tidak lagi
 * cocok. Variabel tidak bisa dihapus — cukup dinonaktifkan (kolom yang sudah bertaut
 * tetap berfungsi, tautan baru tidak bisa dibuat).
 */
class VariabelKatalogManager extends Component
{
    public string $cari = '';

    /** semua | aktif | nonaktif */
    public string $filterStatus = 'semua';

    /** null = form tutup, 0 = variabel baru, >0 = id yang diubah */
    public ?int $editId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** Sidik jari variabel saat form dibuka — simpan ditolak bila sudah diubah orang lain. */
    #[Locked]
    public ?string $versi = null;

    public ?int $pemakaianId = null;

    public function boot(): void
    {
        if (auth()->user()?->namaRole() !== BagianKustom::NAMA_PERAN[BagianKustom::PERAN_TIM_SAKIP]) {
            LogAktivitas::aksesDitolak('katalog variabel');
            abort(403);
        }
    }

    protected function formKosong(): array
    {
        return [
            'kode' => '', 'nama' => '', 'tipe' => 'angka', 'satuan' => '', 'desimal' => '', 'pemisah' => FormatAngkaKolom::PEMISAH_KOMA,
            'ribuan' => true, 'min' => '', 'maks' => '', 'panjang_min' => '', 'panjang_maks' => '', 'opsi_teks' => '', 'keterangan' => '',
        ];
    }

    public function baru(): void
    {
        $this->resetErrorBag();
        $this->editId = 0;
        $this->versi = null;
        $this->form = $this->formKosong();
    }

    public function ubah(int $id): void
    {
        $v = VariabelKatalog::findOrFail($id);
        $this->resetErrorBag();
        $this->editId = $v->id;
        $this->versi = self::sidik($v);
        $this->form = [
            'kode' => $v->kode, 'nama' => $v->nama, 'tipe' => $v->tipe, 'satuan' => (string) $v->satuan,
            'desimal' => $v->desimal !== null ? (string) $v->desimal : '', 'pemisah' => $v->pemisah ?: FormatAngkaKolom::PEMISAH_KOMA,
            'ribuan' => (bool) $v->ribuan, 'min' => (string) $v->min, 'maks' => (string) $v->maks,
            'panjang_min' => $v->panjang_min !== null ? (string) $v->panjang_min : '', 'panjang_maks' => $v->panjang_maks !== null ? (string) $v->panjang_maks : '',
            'opsi_teks' => implode(', ', (array) ($v->opsi ?? [])), 'keterangan' => (string) $v->keterangan,
        ];
    }

    public function tutup(): void
    {
        $this->editId = null;
        $this->form = [];
        $this->resetErrorBag();
    }

    public function lihatPemakaian(int $id): void
    {
        VariabelKatalog::findOrFail($id);
        $this->pemakaianId = $this->pemakaianId === $id ? null : $id;
    }

    protected static function sidik(VariabelKatalog $v): string
    {
        return sha1((string) json_encode($v->only(['kode', 'nama', 'tipe', 'satuan', 'desimal', 'pemisah', 'ribuan', 'min', 'maks', 'panjang_min', 'panjang_maks', 'opsi', 'keterangan', 'aktif'])));
    }

    /** @return array<int, string> */
    protected function opsi(): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,\n]+/', (string) ($this->form['opsi_teks'] ?? ''))), fn ($o) => $o !== '')));
    }

    public function simpan(): void
    {
        if ($this->editId === null) {
            return;
        }

        $baru = $this->editId === 0;
        $this->resetErrorBag();

        $this->validate([
            'form.kode' => $baru ? ['required', 'string', 'regex:'.VariabelKatalog::POLA_KODE, Rule::unique('variabel_katalog', 'kode')] : [],
            'form.nama' => ['required', 'string', 'max:150'],
            'form.tipe' => ['required', Rule::in(JenisKolomBagianKustom::TIPE_KATALOG)],
            'form.satuan' => ['nullable', 'string', 'max:30'],
            'form.desimal' => ['nullable', 'integer', 'min:0', 'max:'.FormatAngkaKolom::DESIMAL_MAKS],
            'form.pemisah' => ['nullable', Rule::in(array_keys(FormatAngkaKolom::PEMISAH))],
            'form.ribuan' => ['boolean'],
            'form.min' => ['nullable', 'string', 'max:30'],
            'form.maks' => ['nullable', 'string', 'max:30'],
            'form.panjang_min' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'form.panjang_maks' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'form.opsi_teks' => ['nullable', 'string', 'max:1000'],
            'form.keterangan' => ['nullable', 'string', 'max:1000'],
        ], [
            'form.kode.regex' => 'Kode hanya huruf kecil, angka, dan garis bawah, diawali huruf (2–60 karakter), mis. persen_tindak_lanjut.',
            'form.kode.unique' => 'Kode tersebut sudah dipakai variabel lain.',
        ], ['form.kode' => 'kode', 'form.nama' => 'nama', 'form.tipe' => 'jenis']);

        $f = $this->form;
        $tipe = $f['tipe'];
        $angka = $tipe === 'angka';
        $opsi = $this->opsi();

        if (in_array($tipe, ['pilihan', 'pilihan_ganda'], true) && count($opsi) < 2) {
            $this->addError('form.opsi_teks', 'Jenis pilihan butuh minimal dua opsi, dipisah koma.');
        }

        $min = $angka && filled($f['min']) ? FormatAngkaKolom::urai((string) $f['min'], null) : null;
        $maks = $angka && filled($f['maks']) ? FormatAngkaKolom::urai((string) $f['maks'], null) : null;

        foreach (['min' => $min, 'maks' => $maks] as $k => $v) {
            if ($angka && filled($f[$k]) && $v === null) {
                $this->addError("form.{$k}", 'Batas harus berupa angka, mis. 0 atau 12,5.');
            }
        }

        if ($min !== null && $maks !== null && (float) $min > (float) $maks) {
            $this->addError('form.maks', 'Nilai maksimal harus lebih besar atau sama dengan nilai minimal.');
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $data = [
            'nama' => trim($f['nama']),
            'tipe' => $tipe,
            'satuan' => $angka ? (trim((string) $f['satuan']) ?: null) : null,
            'desimal' => $angka && filled($f['desimal']) ? (int) $f['desimal'] : null,
            'pemisah' => $angka ? ($f['pemisah'] ?: FormatAngkaKolom::PEMISAH_KOMA) : null,
            'ribuan' => (bool) ($f['ribuan'] ?? true),
            'min' => $min,
            'maks' => $maks,
            'panjang_min' => JenisKolomBagianKustom::teks($tipe) && filled($f['panjang_min']) ? (int) $f['panjang_min'] : null,
            'panjang_maks' => JenisKolomBagianKustom::teks($tipe) && filled($f['panjang_maks']) ? (int) $f['panjang_maks'] : null,
            'opsi' => in_array($tipe, ['pilihan', 'pilihan_ganda'], true) ? $opsi : null,
            'keterangan' => trim((string) $f['keterangan']) ?: null,
            'diubah_oleh' => auth()->id(),
        ];

        $galat = DB::transaction(function () use ($baru, $data, $f) {
            if ($baru) {
                $v = VariabelKatalog::create($data + ['kode' => $f['kode'], 'sumber' => 'isian', 'aktif' => true, 'dibuat_oleh' => auth()->id()]);
                JejakAktivitasBagianKustom::variabel('dibuat', $v, 'membuat', ['sesudah' => $v->only(['kode', 'nama', 'tipe', 'satuan', 'desimal', 'pemisah', 'opsi'])]);

                return null;
            }

            $v = VariabelKatalog::whereKey($this->editId)->lockForUpdate()->firstOrFail();

            if (self::sidik($v) !== $this->versi) {
                return 'Variabel ini sudah diubah Tim SAKIP lain sejak Anda membukanya. Tutup lalu buka kembali untuk memuat versi terbaru.';
            }

            if (($tolak = $this->galatNilaiTersimpan($v, $data)) !== null) {
                return $tolak;
            }

            $sebelum = $v->only(array_keys($data));
            $v->update($data);
            $selisih = LogAktivitas::selisih(array_diff_key($sebelum, ['diubah_oleh' => 1]), array_diff_key($v->only(array_keys($data)), ['diubah_oleh' => 1]));

            if ($selisih !== []) {
                JejakAktivitasBagianKustom::variabel('diubah', $v, 'mengubah', $selisih);
            }

            return null;
        });

        if ($galat !== null) {
            $this->addError('form.tipe', $galat);

            return;
        }

        VariabelKatalog::lupakanCache();
        BagianKustom::lupakanCache();
        session()->flash('status-variabel', $baru ? 'Variabel "'.$data['nama'].'" ditambahkan ke katalog.' : 'Variabel "'.$data['nama'].'" diperbarui. Kolom yang memakainya langsung mengikuti — nilai tersimpan tidak diubah.');
        $this->tutup();
    }

    /**
     * Penggantian jenis / opsi / batas yang membuat nilai tersimpan pada kolom bertaut
     * tidak lagi bermakna ditolak (nilai tidak pernah dikonversi otomatis).
     *
     * @param  array<string, mixed>  $data
     */
    protected function galatNilaiTersimpan(VariabelKatalog $v, array $data): ?string
    {
        $tipeBerubah = $data['tipe'] !== $v->tipe;
        $opsiDibuang = array_diff((array) ($v->opsi ?? []), (array) ($data['opsi'] ?? []));

        if (! $tipeBerubah && $opsiDibuang === []) {
            return null;
        }

        $kolomBaru = ['tipe' => $data['tipe'], 'opsi' => (array) ($data['opsi'] ?? [])];

        foreach ($v->pemakaian() as ['bagian' => $bagian, 'kolom' => $kolom]) {
            $nilai = BagianKustomPoin::where('bagian_kustom_id', $bagian->id)->whereNotNull('nilai_kolom')->pluck('nilai_kolom')
                ->map(fn ($n) => trim((string) ($n[$kolom['kunci']] ?? '')))
                ->filter(fn ($s) => $s !== '');

            foreach ($nilai as $s) {
                $cocok = $tipeBerubah
                    ? JenisKolomBagianKustom::cocokDenganTipe($s, $v->tipe, $kolomBaru)
                    : collect($data['tipe'] === 'pilihan_ganda' ? JenisKolomBagianKustom::daftarPilihan($s) : [$s])->every(fn ($o) => ! in_array($o, $opsiDibuang, true));

                if (! $cocok) {
                    return "Perubahan ditolak: kolom \"{$kolom['label']}\" di bagian \"{$bagian->nama}\" sudah berisi nilai \"{$s}\" yang tidak cocok dengan definisi baru. Nilai lama tidak dikonversi otomatis — biarkan definisinya, atau buat variabel baru.";
                }
            }
        }

        return null;
    }

    public function toggleAktif(int $id): void
    {
        DB::transaction(function () use ($id) {
            $v = VariabelKatalog::whereKey($id)->lockForUpdate()->firstOrFail();
            $v->update(['aktif' => ! $v->aktif, 'diubah_oleh' => auth()->id()]);
            JejakAktivitasBagianKustom::variabel($v->aktif ? 'diaktifkan' : 'dinonaktifkan', $v, $v->aktif ? 'mengaktifkan' : 'menonaktifkan', ['aktif' => ['sebelum' => ! $v->aktif, 'sesudah' => $v->aktif]]);
        });

        VariabelKatalog::lupakanCache();
        BagianKustom::lupakanCache();
    }

    public function render()
    {
        $cari = mb_strtolower(trim($this->cari));

        $daftar = VariabelKatalog::orderBy('nama')->get()->filter(function (VariabelKatalog $v) use ($cari) {
            if ($cari !== '' && ! str_contains(mb_strtolower($v->kode.' '.$v->nama.' '.$v->keterangan), $cari)) {
                return false;
            }

            return match ($this->filterStatus) {
                'aktif' => $v->aktif,
                'nonaktif' => ! $v->aktif,
                default => true,
            };
        })->values();

        $pemakaian = [];

        foreach (BagianKustom::orderBy('urutan')->orderBy('id')->get() as $bagian) {
            foreach ($bagian->kolom ?? [] as $kolom) {
                if (filled($kolom['variabel'] ?? null)) {
                    $pemakaian[$kolom['variabel']][] = ['bagian' => $bagian->nama, 'kolom' => $kolom['label'] ?? $kolom['kunci'], 'aktif' => (bool) $bagian->aktif && (bool) ($kolom['aktif'] ?? true)];
                }
            }
        }

        return view('livewire.variabel-katalog-manager', [
            'daftar' => $daftar,
            'pemakaian' => $pemakaian,
            'tipeKolom' => JenisKolomBagianKustom::tipeKatalog(),
            'pilihanPemisah' => FormatAngkaKolom::PEMISAH,
        ]);
    }
}
