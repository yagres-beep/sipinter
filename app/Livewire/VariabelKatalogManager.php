<?php

namespace App\Livewire;

use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\LogAktivitas;
use App\Models\VariabelKatalog;
use App\Support\FormatAngkaKolom;
use App\Support\JejakAktivitasBagianKustom;
use App\Support\JenisKolomBagianKustom;
use App\Support\KatalogVariabel;
use App\Support\RegistriVariabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Tim SAKIP — Katalog Variabel: kamus pusat semua variabel SIPINTER.
 * - Variabel sistem/turunan/penanda template: hanya baca, dari RegistriVariabel.
 * - Variabel kolom Bagian Kustom: hanya baca, dari konfigurasi bagian.
 * - Definisi bersama (tabel variabel_katalog): bisa dibuat/diubah di sini dan dipakai
 *   ulang oleh kolom Bagian Kustom (mengikuti jenis, opsi, format angka, batasnya).
 *
 * Perubahan definisi tidak pernah mengonversi nilai tersimpan: mengganti jenis atau
 * membuang opsi ditolak bila ada nilai tersimpan pada kolom bertaut yang tidak lagi
 * cocok. Variabel tidak bisa dihapus — cukup dinonaktifkan (kolom yang sudah bertaut
 * tetap berfungsi, tautan baru tidak bisa dibuat).
 */
class VariabelKatalogManager extends Component
{
    public string $cari = '';

    /** '' = semua | aktif | usang | nonaktif */
    public string $filterStatus = '';

    public string $filterKategori = '';

    /** Asal: sistem | turunan | penyajian | kustom | definisi */
    public string $filterJenis = '';

    public string $filterTipe = '';

    public string $filterKonteks = '';

    /** Id entri katalog yang detailnya dibuka (mis. "sistem:kode"). */
    public ?string $detail = null;

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
            'kategori' => VariabelKatalog::KATEGORI_BAWAAN, 'contoh' => '', 'catatan' => '', 'pengganti_kode' => '',
        ];
    }

    public function bukaDetail(string $id): void
    {
        $this->detail = $this->detail === $id ? null : $id;
    }

    public function resetFilter(): void
    {
        $this->reset('cari', 'filterStatus', 'filterKategori', 'filterJenis', 'filterTipe', 'filterKonteks');
    }

    /** Kategori yang boleh dipilih untuk definisi buatan pengguna. */
    protected static function kategoriDefinisi(): array
    {
        return array_diff_key(RegistriVariabel::KATEGORI, ['struktur' => true]);
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
            'kategori' => $v->kategori ?: VariabelKatalog::KATEGORI_BAWAAN, 'contoh' => (string) $v->contoh,
            'catatan' => (string) $v->catatan, 'pengganti_kode' => (string) $v->pengganti_kode,
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
        return sha1((string) json_encode($v->only(['kode', 'nama', 'tipe', 'satuan', 'desimal', 'pemisah', 'ribuan', 'min', 'maks', 'panjang_min', 'panjang_maks', 'opsi', 'keterangan', 'aktif', 'kategori', 'contoh', 'catatan', 'pengganti_kode'])));
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

        $kodeIni = $baru ? (string) ($this->form['kode'] ?? '') : (string) VariabelKatalog::whereKey($this->editId)->value('kode');

        $this->validate([
            'form.kode' => $baru ? ['required', 'string', 'regex:'.VariabelKatalog::POLA_KODE, Rule::unique('variabel_katalog', 'kode'), Rule::notIn(array_keys(RegistriVariabel::semua()))] : [],
            'form.kategori' => ['nullable', Rule::in(array_keys(self::kategoriDefinisi()))],
            'form.contoh' => ['nullable', 'string', 'max:255'],
            'form.catatan' => ['nullable', 'string', 'max:2000'],
            'form.pengganti_kode' => ['nullable', 'string', Rule::exists('variabel_katalog', 'kode'), Rule::notIn([$kodeIni])],
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
            'form.kode.not_in' => 'Kode tersebut sudah dipakai variabel sistem. Pilih kode lain.',
            'form.pengganti_kode.exists' => 'Variabel pengganti harus ada di katalog.',
            'form.pengganti_kode.not_in' => 'Variabel tidak bisa menjadi pengganti dirinya sendiri.',
        ], ['form.kode' => 'kode', 'form.nama' => 'nama', 'form.tipe' => 'jenis', 'form.pengganti_kode' => 'pengganti']);

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
            'kategori' => filled($f['kategori'] ?? null) ? $f['kategori'] : VariabelKatalog::KATEGORI_BAWAAN,
            'contoh' => trim((string) ($f['contoh'] ?? '')) ?: null,
            'catatan' => trim((string) ($f['catatan'] ?? '')) ?: null,
            'pengganti_kode' => trim((string) ($f['pengganti_kode'] ?? '')) ?: null,
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
        $semua = KatalogVariabel::semua();
        $daftar = KatalogVariabel::saring($semua, [
            'cari' => $this->cari, 'kategori' => $this->filterKategori, 'jenis' => $this->filterJenis,
            'tipe' => $this->filterTipe, 'konteks' => $this->filterKonteks, 'status' => $this->filterStatus,
        ]);

        return view('livewire.variabel-katalog-manager', [
            'jumlahTotal' => $semua->count(),
            'jumlahPerJenis' => $semua->countBy('jenis')->all(),
            'jumlahPerStatus' => $semua->countBy('status')->all(),
            'daftar' => $daftar,
            'kelompok' => KatalogVariabel::kelompokkan($daftar),
            'adaFilter' => collect([$this->cari, $this->filterKategori, $this->filterJenis, $this->filterTipe, $this->filterKonteks, $this->filterStatus])->contains(fn ($f) => trim($f) !== ''),
            'definisiAktif' => $semua->where('jenis', 'definisi')->where('aktif_model', true)->pluck('nama', 'kode')->all(),
            'kategoriDefinisi' => self::kategoriDefinisi(),
            'tipeKolom' => JenisKolomBagianKustom::tipeKatalog(),
            'pilihanPemisah' => FormatAngkaKolom::PEMISAH,
        ]);
    }
}
