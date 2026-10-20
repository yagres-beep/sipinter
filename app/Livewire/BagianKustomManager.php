<?php

namespace App\Livewire;

use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\FolderConfig;
use App\Support\MenuBagianKustom;
use App\Support\TemplateBagianKustom;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Tim SAKIP — atur SEMUA bagian tambahan (mis. "Manajemen Risiko") langsung dari layar:
 * siapa yang mengisi, kolom isian (seperti tabel), frekuensi & bukti, dan apakah serta
 * bagaimana tampil di notula. Tidak perlu mengubah kode.
 */
class BagianKustomManager extends Component
{
    public string $namaBaru = '';

    public string $deskripsiBaru = '';

    /** @var array<int, string> peran yang boleh melihat/mengisi bagian baru */
    public array $peranBaru = [BagianKustom::PERAN_KETUA_TIM];

    /** @var array<int, string> menu tempat bagian baru ditempatkan */
    public array $penempatanBaru = [MenuBagianKustom::PENGISIAN];

    public string $frekuensiWajibBaru = BagianKustom::FREKUENSI_OPSIONAL;

    /**
     * Keadaan form per bagian, dikunci pada id. Kolom isian disimpan sebagai daftar baris
     * (label, tipe, opsi_teks, wajib, kunci) agar bisa diedit langsung di layar.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $form = [];

    /** Bagian yang sedang dibuka untuk diatur (null = semua tertutup). */
    public ?int $bukaId = null;

    /** Tab yang tampil pada kartu yang sedang dibuka: dasar | isian | notula | pratinjau. */
    public string $tab = 'dasar';

    public ?int $pendingHapusId = null;

    public string $pendingHapusNama = '';

    public function mount(): void
    {
        foreach (BagianKustom::orderBy('urutan')->orderBy('id')->get() as $bagian) {
            $this->muatForm($bagian->id);
        }
    }

    public function daftar()
    {
        return BagianKustom::orderBy('urutan')->orderBy('id')->withCount('poin')->get();
    }

    /**
     * Isi form dari data tersimpan (juga dipakai untuk "Batal ubah").
     */
    protected function muatForm(int $id): void
    {
        $bagian = BagianKustom::findOrFail($id);

        $this->form[$id] = [
            'nama' => $bagian->nama,
            'deskripsi' => $bagian->deskripsi,
            'peran' => $bagian->peran ?? [],
            'penempatan' => $bagian->penempatan ?? [],
            'label_uraian' => $bagian->label_uraian,
            'frekuensi_wajib' => $bagian->frekuensi_wajib,
            'bukti_wajib' => (bool) $bagian->bukti_wajib,
            'masuk_notula' => (bool) $bagian->masuk_notula,
            'judul_notula' => $bagian->judul_notula,
            'format_notula' => $bagian->format_notula,
            'kolom' => collect($bagian->kolomList())->map(fn ($k) => [
                'kunci' => $k['kunci'],
                'label' => $k['label'],
                'tipe' => $k['tipe'],
                'opsi_teks' => implode(', ', $k['opsi']),
                'wajib' => $k['wajib'],
            ])->values()->all(),
        ];
    }

    public function buka(int $id): void
    {
        if ($this->bukaId === $id) {
            $this->bukaId = null;

            return;
        }

        $this->bukaId = $id;
        $this->tab = 'dasar';
        $this->resetErrorBag();
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['dasar', 'isian', 'notula', 'pratinjau'], true)) {
            $this->tab = $tab;
        }
    }

    /**
     * Tukar urutan dengan bagian TEPAT DI ATASNYA pada daftar yang sedang tampil.
     */
    public function naikkan(int $id): void
    {
        $daftar = $this->daftar()->values();
        $posisi = $daftar->search(fn ($b) => $b->id === $id);

        if ($posisi === false || $posisi === 0) {
            return;
        }

        $ini = $daftar[$posisi];
        $atas = $daftar[$posisi - 1];

        BagianKustom::whereKey($ini->id)->update(['urutan' => $atas->urutan]);
        BagianKustom::whereKey($atas->id)->update(['urutan' => $ini->urutan]);

        BagianKustom::lupakanCache();
    }

    /**
     * Tukar urutan dengan bagian TEPAT DI BAWAHNYA pada daftar yang sedang tampil.
     */
    public function turunkan(int $id): void
    {
        $daftar = $this->daftar()->values();
        $posisi = $daftar->search(fn ($b) => $b->id === $id);

        if ($posisi === false || $posisi === $daftar->count() - 1) {
            return;
        }

        $ini = $daftar[$posisi];
        $bawah = $daftar[$posisi + 1];

        BagianKustom::whereKey($ini->id)->update(['urutan' => $bawah->urutan]);
        BagianKustom::whereKey($bawah->id)->update(['urutan' => $ini->urutan]);

        BagianKustom::lupakanCache();
    }

    public function tambah(): void
    {
        $this->validate([
            'namaBaru' => ['required', 'string', 'max:255'],
            'deskripsiBaru' => ['nullable', 'string', 'max:1000'],
            'peranBaru' => ['required', 'array', 'min:1'],
            'peranBaru.*' => [Rule::in(array_keys(BagianKustom::NAMA_PERAN))],
            'penempatanBaru' => ['required', 'array', 'min:1'],
            'penempatanBaru.*' => [Rule::in(array_keys(MenuBagianKustom::katalog()))],
            'frekuensiWajibBaru' => ['required', Rule::in([BagianKustom::FREKUENSI_OPSIONAL, BagianKustom::FREKUENSI_SETIAP_BULAN, BagianKustom::FREKUENSI_AKHIR_TRIWULAN])],
        ], [], ['namaBaru' => 'nama bagian']);

        $sudahAda = BagianKustom::whereRaw('LOWER(nama) = ?', [mb_strtolower(trim($this->namaBaru))])->exists();

        if ($sudahAda) {
            $this->addError('namaBaru', 'Bagian dengan nama tersebut sudah ada.');

            return;
        }

        $peran = array_values(array_unique($this->peranBaru));
        $penempatan = array_values(array_unique($this->penempatanBaru));

        if (! $this->penempatanValid($peran, $penempatan)) {
            $this->addError('penempatanBaru', 'Ada menu yang tidak bisa dibuka oleh peran yang dipilih.');

            return;
        }

        $bagian = BagianKustom::create([
            'nama' => trim($this->namaBaru),
            'deskripsi' => trim($this->deskripsiBaru) ?: null,
            'peran' => $peran,
            'penempatan' => $penempatan,
            'frekuensi_wajib' => $this->frekuensiWajibBaru,
            'bukti_wajib' => $this->bukti_wajib_boleh($peran, $penempatan),
            'masuk_notula' => true,
            'kolom' => [],
            'aktif' => true,
            'urutan' => ((int) BagianKustom::max('urutan')) + 1,
        ]);

        $this->reset(['namaBaru', 'deskripsiBaru']);
        $this->peranBaru = [BagianKustom::PERAN_KETUA_TIM];
        $this->penempatanBaru = [MenuBagianKustom::PENGISIAN];
        $this->frekuensiWajibBaru = BagianKustom::FREKUENSI_OPSIONAL;

        FolderConfig::tambahKategoriDariBagianKustom($bagian->nama);

        BagianKustom::lupakanCache();
        $this->muatForm($bagian->id);
        $this->bukaId = $bagian->id;
        $this->tab = 'isian';

        session()->flash('status', 'Bagian "'.$bagian->nama.'" dibuat. Atur kolom isian, peran pengisi, dan notula di bawah ini — folder kategorinya sudah ditambahkan ke Struktur Folder.');
    }

    /**
     * Setiap menu yang dipilih harus bisa dibuka setidaknya oleh salah satu peran yang
     * dipilih (mis. menu "Verifikasi & Notula" hanya untuk Tim SAKIP).
     *
     * @param  array<int, string>  $peran
     * @param  array<int, string>  $penempatan
     */
    protected function penempatanValid(array $peran, array $penempatan): bool
    {
        foreach ($penempatan as $menu) {
            if (array_intersect($peran, MenuBagianKustom::peranDiizinkan($menu)) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bukti PDF hanya berlaku untuk isian di menu Isian Kegiatan oleh Ketua Tim.
     *
     * @param  array<int, string>  $peran
     * @param  array<int, string>  $penempatan
     */
    protected function bukti_wajib_boleh(array $peran, array $penempatan): bool
    {
        return in_array(MenuBagianKustom::PENGISIAN, $penempatan, true) && in_array(BagianKustom::PERAN_KETUA_TIM, $peran, true);
    }

    /* ---------- Kolom isian (baris di form) ---------- */

    public function tambahKolom(int $id): void
    {
        $this->form[$id]['kolom'][] = ['kunci' => '', 'label' => '', 'tipe' => 'teks', 'opsi_teks' => '', 'wajib' => false];
    }

    public function hapusKolom(int $id, int $index): void
    {
        unset($this->form[$id]['kolom'][$index]);
        $this->form[$id]['kolom'] = array_values($this->form[$id]['kolom']);
    }

    public function naikkanKolom(int $id, int $index): void
    {
        if ($index <= 0) {
            return;
        }

        $kolom = $this->form[$id]['kolom'];
        [$kolom[$index - 1], $kolom[$index]] = [$kolom[$index], $kolom[$index - 1]];
        $this->form[$id]['kolom'] = $kolom;
    }

    public function turunkanKolom(int $id, int $index): void
    {
        $kolom = $this->form[$id]['kolom'];

        if ($index >= count($kolom) - 1) {
            return;
        }

        [$kolom[$index + 1], $kolom[$index]] = [$kolom[$index], $kolom[$index + 1]];
        $this->form[$id]['kolom'] = $kolom;
    }

    /**
     * Isi kotak format notula dengan salah satu contoh siap pakai.
     */
    public function pakaiFormatContoh(int $id, string $jenis): void
    {
        $kolom = collect($this->form[$id]['kolom'] ?? [])->pluck('kunci')->filter()->all();

        $this->form[$id]['format_notula'] = match ($jenis) {
            'lengkap' => $kolom === []
                ? TemplateBagianKustom::FORMAT_STANDAR
                : '{kode_iku}: {teks}'.' ('.implode(', ', array_map(fn ($k) => '{'.$k.'}', $kolom)).')',
            default => TemplateBagianKustom::FORMAT_STANDAR,
        };
    }

    /**
     * Batalkan perubahan yang belum disimpan pada satu bagian.
     */
    public function batalUbah(int $id): void
    {
        $this->resetErrorBag();
        $this->muatForm($id);
    }

    /* ---------- Simpan ---------- */

    protected function aturanForm(int $id): array
    {
        return [
            "form.{$id}.nama" => ['required', 'string', 'max:255'],
            "form.{$id}.deskripsi" => ['nullable', 'string', 'max:1000'],
            "form.{$id}.peran" => ['required', 'array', 'min:1'],
            "form.{$id}.peran.*" => [Rule::in(array_keys(BagianKustom::NAMA_PERAN))],
            "form.{$id}.penempatan" => ['required', 'array', 'min:1'],
            "form.{$id}.penempatan.*" => [Rule::in(array_keys(MenuBagianKustom::katalog()))],
            "form.{$id}.label_uraian" => ['nullable', 'string', 'max:100'],
            "form.{$id}.frekuensi_wajib" => ['required', Rule::in([BagianKustom::FREKUENSI_OPSIONAL, BagianKustom::FREKUENSI_SETIAP_BULAN, BagianKustom::FREKUENSI_AKHIR_TRIWULAN])],
            "form.{$id}.bukti_wajib" => ['boolean'],
            "form.{$id}.masuk_notula" => ['boolean'],
            "form.{$id}.judul_notula" => ['nullable', 'string', 'max:255'],
            "form.{$id}.format_notula" => ['nullable', 'string', 'max:500'],
            "form.{$id}.kolom" => ['array', 'max:12'],
            "form.{$id}.kolom.*.label" => ['required', 'string', 'max:100'],
            "form.{$id}.kolom.*.tipe" => ['required', Rule::in(array_keys(BagianKustom::TIPE_KOLOM))],
            "form.{$id}.kolom.*.opsi_teks" => ['nullable', 'string', 'max:500'],
            "form.{$id}.kolom.*.wajib" => ['boolean'],
        ];
    }

    protected function pesanForm(int $id): array
    {
        return [
            "form.{$id}.nama.required" => 'Nama bagian wajib diisi.',
            "form.{$id}.kolom.*.label.required" => 'Label kolom wajib diisi (atau hapus baris kolomnya).',
        ];
    }

    /**
     * Bentuk kolom yang tersimpan dari baris form. Kunci kolom lama DIPERTAHANKAN (dipakai
     * sebagai nama data), kolom baru dibuat dari labelnya. Mengembalikan error bila
     * pilihan kurang dari dua opsi.
     *
     * @return array<int, array{kunci: string, label: string, tipe: string, opsi: array<int, string>, wajib: bool}>
     */
    protected function kolomDariForm(int $id): array
    {
        $baris = $this->form[$id]['kolom'] ?? [];

        $kunciTerpakai = collect($baris)->pluck('kunci')->filter()->values()->all();
        $hasil = [];

        foreach ($baris as $i => $row) {
            $kunci = (string) ($row['kunci'] ?? '');

            if ($kunci === '') {
                $kunci = TemplateBagianKustom::kunciBaru((string) $row['label'], $kunciTerpakai);
                $kunciTerpakai[] = $kunci;
            }

            $opsi = $this->opsiDariTeks((string) ($row['opsi_teks'] ?? ''));

            if ($row['tipe'] === 'pilihan' && count($opsi) < 2) {
                $this->addError("form.{$id}.kolom.{$i}.opsi_teks", 'Kolom pilihan butuh minimal dua opsi, dipisah koma.');
            }

            $hasil[] = [
                'kunci' => $kunci,
                'label' => trim((string) $row['label']),
                'tipe' => $row['tipe'],
                'opsi' => $row['tipe'] === 'pilihan' ? $opsi : [],
                'wajib' => (bool) ($row['wajib'] ?? false),
            ];
        }

        return $hasil;
    }

    /**
     * @return array<int, string>
     */
    protected function opsiDariTeks(string $teks): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,\n]+/', $teks)), fn ($o) => $o !== '')));
    }

    public function simpan(int $id): void
    {
        $bagian = BagianKustom::findOrFail($id);

        $this->resetErrorBag();
        $this->validate($this->aturanForm($id), $this->pesanForm($id));

        $f = $this->form[$id];
        $namaSama = BagianKustom::where('id', '!=', $id)->whereRaw('LOWER(nama) = ?', [mb_strtolower(trim($f['nama']))])->exists();

        if ($namaSama) {
            $this->addError("form.{$id}.nama", 'Sudah ada bagian lain dengan nama tersebut.');
        }

        $kolom = $this->kolomDariForm($id);

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        // Kolom yang sudah berisi data di poin tersimpan tidak boleh dihapus — datanya
        // akan hilang dari notula. Pulihkan dengan "Batal ubah" bila tidak jadi.
        $kunciBaru = collect($kolom)->pluck('kunci')->all();
        $kunciDihapus = collect($bagian->kolomList())->pluck('kunci')->diff($kunciBaru);

        if ($kunciDihapus->isNotEmpty()) {
            $nilaiTersimpan = BagianKustomPoin::where('bagian_kustom_id', $id)->whereNotNull('nilai_kolom')->pluck('nilai_kolom');

            foreach ($kunciDihapus as $kunci) {
                $jumlah = $nilaiTersimpan->filter(fn ($n) => filled($n[$kunci] ?? null))->count();

                if ($jumlah > 0) {
                    $label = collect($bagian->kolomList())->firstWhere('kunci', $kunci)['label'] ?? $kunci;
                    $this->addError("form.{$id}.kolom", "Kolom \"{$label}\" sudah berisi data di {$jumlah} poin, jadi tidak bisa dihapus. Batalkan penghapusannya.");

                    return;
                }
            }
        }

        $tidakDikenal = TemplateBagianKustom::variabelTidakDikenal((string) ($f['format_notula'] ?? ''), $kunciBaru);

        if ($tidakDikenal !== []) {
            $this->addError("form.{$id}.format_notula", 'Variabel tidak dikenal: '.implode(', ', $tidakDikenal).'. Pakai daftar variabel di tab Notula.');

            return;
        }

        $peran = array_values(array_unique($f['peran']));
        $penempatan = array_values(array_unique($f['penempatan']));

        if (! $this->penempatanValid($peran, $penempatan)) {
            $this->addError("form.{$id}.penempatan", 'Ada menu yang tidak bisa dibuka oleh peran yang dipilih. Hapus centang menu itu, atau tambahkan peran yang sesuai.');

            return;
        }

        $bagian->update([
            'nama' => trim($f['nama']),
            'deskripsi' => trim((string) ($f['deskripsi'] ?? '')) ?: null,
            'peran' => $peran,
            'penempatan' => $penempatan,
            'label_uraian' => trim((string) ($f['label_uraian'] ?? '')) ?: null,
            'frekuensi_wajib' => $f['frekuensi_wajib'],
            // Bukti dukung (PDF) hanya didukung untuk isian di menu Isian Kegiatan (Ketua Tim).
            'bukti_wajib' => $this->bukti_wajib_boleh($peran, $penempatan) && (bool) $f['bukti_wajib'],
            'masuk_notula' => (bool) $f['masuk_notula'],
            'judul_notula' => trim((string) ($f['judul_notula'] ?? '')) ?: null,
            'format_notula' => trim((string) ($f['format_notula'] ?? '')) ?: null,
            'kolom' => $kolom,
        ]);

        BagianKustom::lupakanCache();
        $this->muatForm($id);

        session()->flash('status', 'Perubahan "'.$bagian->nama.'" disimpan. Isian yang sudah tersimpan tetap ada di notula.');
    }

    public function toggleAktif(int $id): void
    {
        $bagian = BagianKustom::findOrFail($id);
        $bagian->update(['aktif' => ! $bagian->aktif]);

        BagianKustom::lupakanCache();
    }

    public function confirmHapus(int $id): void
    {
        $bagian = BagianKustom::findOrFail($id);

        $this->pendingHapusId = $bagian->id;
        $this->pendingHapusNama = $bagian->nama;
    }

    public function batalkanHapus(): void
    {
        $this->pendingHapusId = null;
        $this->pendingHapusNama = '';
    }

    /**
     * Hapus permanen HANYA bila belum ada satu pun poin tersimpan untuk bagian ini —
     * bila sudah ada isian, nonaktifkan saja supaya data historis tidak hilang.
     */
    public function hapus(): void
    {
        if (! $this->pendingHapusId) {
            return;
        }

        $bagian = BagianKustom::withCount('poin')->findOrFail($this->pendingHapusId);

        if ($bagian->poin_count > 0) {
            $this->batalkanHapus();
            $this->addError('hapus', 'Bagian "'.$bagian->nama.'" sudah punya isian tersimpan — nonaktifkan saja, jangan dihapus, supaya data lama tidak hilang.');

            return;
        }

        $bagian->delete();
        unset($this->form[$bagian->id]);

        if ($this->bukaId === $bagian->id) {
            $this->bukaId = null;
        }

        $this->batalkanHapus();

        BagianKustom::lupakanCache();
        session()->flash('status', 'Bagian kustom dihapus.');
    }

    /* ---------- Pratinjau (dihitung dari form, termasuk yang belum disimpan) ---------- */

    /**
     * Contoh hasil notula dari keadaan form saat ini — dua poin contoh.
     *
     * @return array{judul: string, baris: array<int, string>, peringatan: array<int, string>}
     */
    protected function pratinjauNotula(int $id): array
    {
        $f = $this->form[$id];
        $kolom = $this->kolomDariFormTanpaError($id);
        $kunci = collect($kolom)->pluck('kunci')->all();
        $format = trim((string) ($f['format_notula'] ?? '')) ?: TemplateBagianKustom::FORMAT_STANDAR;

        $contohNilai = collect($kolom)->mapWithKeys(fn ($k) => [$k['kunci'] => TemplateBagianKustom::contohKolom($k)])->all();

        $baris = [];

        foreach ([['1131', 'Persentase rekomendasi yang ditindaklanjuti', 'Contoh uraian poin pertama'], ['1131', 'Persentase rekomendasi yang ditindaklanjuti', 'Contoh uraian poin kedua']] as $i => [$kode, $indikator, $teks]) {
            $konteks = TemplateBagianKustom::konteks($kode, $indikator, $teks, trim((string) $f['nama']), 2026, 1, 3 - $i, $contohNilai);
            $baris[] = TemplateBagianKustom::render($format, $konteks);
        }

        return [
            'judul' => trim((string) ($f['judul_notula'] ?? '')) ?: trim((string) $f['nama']),
            'baris' => $baris,
            'peringatan' => TemplateBagianKustom::variabelTidakDikenal($format, $kunci),
        ];
    }

    /**
     * Tempat bagian ini muncul, per menu yang dipilih — dihitung dari form (belum harus
     * disimpan). Menu yang tidak bisa dibuka peran yang dipilih dilewati.
     *
     * @return array<int, array{menu: string, peran: string, tempat: string}>
     */
    protected function lokasiTampil(): array
    {
        if ($this->bukaId === null || ! isset($this->form[$this->bukaId])) {
            return [];
        }

        $f = $this->form[$this->bukaId];
        $hasil = [];

        foreach ($f['penempatan'] ?? [] as $menu) {
            $peranMenu = array_values(array_intersect($f['peran'] ?? [], MenuBagianKustom::peranDiizinkan($menu)));

            if ($peranMenu === []) {
                continue;
            }

            $hasil[] = [
                'menu' => MenuBagianKustom::label($menu),
                'peran' => collect($peranMenu)->map(fn ($p) => BagianKustom::NAMA_PERAN[$p] ?? $p)->implode(', '),
                'tempat' => $menu === MenuBagianKustom::PENGISIAN
                    ? 'Isian Kegiatan → pilih IKU → bagian ini tampil di paling bawah, setelah bagian 5 (RTL).'
                    : 'Di paling bawah halaman '.MenuBagianKustom::label($menu).' (setelah isi halaman yang biasa). Diisi per IKU & bulan.',
            ];
        }

        return $hasil;
    }

    public function render()
    {
        $pratinjau = null;
        $variabel = [];
        $kolomTampil = [];

        if ($this->bukaId !== null && isset($this->form[$this->bukaId])) {
            $kolomTampil = $this->kolomDariFormTanpaError($this->bukaId);
            $variabel = TemplateBagianKustom::semuaVariabel($kolomTampil);
            $pratinjau = $this->pratinjauNotula($this->bukaId);
        }

        return view('livewire.bagian-kustom-manager', [
            'daftar' => $this->daftar(),
            'pratinjau' => $pratinjau,
            'variabel' => $variabel,
            'kolomTampil' => $kolomTampil,
            'namaPeran' => BagianKustom::NAMA_PERAN,
            'tipeKolom' => BagianKustom::TIPE_KOLOM,
            'katalogMenu' => MenuBagianKustom::katalog(),
            'lokasi' => $this->lokasiTampil(),
        ]);
    }

    /**
     * Versi kolom untuk tampilan (render) — tanpa menambah error, dan kunci baru
     * ditampilkan sebagai "akan dibuat" sampai disimpan.
     *
     * @return array<int, array{kunci: string, label: string, tipe: string, opsi: array<int, string>, wajib: bool}>
     */
    protected function kolomDariFormTanpaError(int $id): array
    {
        $baris = $this->form[$id]['kolom'] ?? [];
        $kunciTerpakai = collect($baris)->pluck('kunci')->filter()->values()->all();
        $hasil = [];

        foreach ($baris as $row) {
            $kunci = (string) ($row['kunci'] ?? '');

            if ($kunci === '') {
                $kunci = TemplateBagianKustom::kunciBaru((string) ($row['label'] ?? ''), $kunciTerpakai);
                $kunciTerpakai[] = $kunci;
            }

            if (trim((string) ($row['label'] ?? '')) === '') {
                continue;
            }

            $hasil[] = [
                'kunci' => $kunci,
                'label' => trim((string) $row['label']),
                'tipe' => $row['tipe'] ?? 'teks',
                'opsi' => ($row['tipe'] ?? '') === 'pilihan' ? $this->opsiDariTeks((string) ($row['opsi_teks'] ?? '')) : [],
                'wajib' => (bool) ($row['wajib'] ?? false),
            ];
        }

        return $hasil;
    }
}
