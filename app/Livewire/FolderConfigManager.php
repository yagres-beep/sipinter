<?php

namespace App\Livewire;

use App\Models\FolderConfig;
use App\Models\IkuFolderConfig;
use App\Models\MasterIku;
use App\Services\FolderStructureService;
use Illuminate\Support\Str;
use Livewire\Component;
use RuntimeException;

/**
 * Modul Tim SAKIP untuk mengatur pola struktur folder Drive (RF-15), pola KHUSUS
 * per-IKU (override opsional di atas pola global), membuat folder tahun lebih awal
 * (RF-16), dan membuat satu folder tambahan secara manual di lokasi bebas. Perubahan
 * hierarki/kategori di sini TIDAK langsung menyentuh Drive — hanya mengubah pola_json
 * (di folder_config atau iku_folder_config), yang baru benar-benar dipakai saat berkas
 * berikutnya diunggah (tetap lazy, RF-14).
 */
class FolderConfigManager extends Component
{
    /** @var list<array{level: string, aktif: bool}> */
    public array $hierarki = [];

    /** @var list<array{nama: string, wajib: bool, subfolder_per_kegiatan: bool}> */
    public array $kategori = [];

    public string $kategoriBaru = '';

    public string $levelBaru = '';

    public int $tahunBaru;

    // ================= Pola khusus per IKU (override opsional) =================

    public ?int $ikuTerpilih = null;

    /** @var list<array{level: string, aktif: bool}> */
    public array $hierarkiIku = [];

    /** @var list<array{nama: string, wajib: bool, subfolder_per_kegiatan: bool}> */
    public array $kategoriIku = [];

    public string $kategoriBaruIku = '';

    public string $levelBaruIku = '';

    /** Apakah IKU yang sedang dipilih sudah punya override tersimpan (bukan sekadar salinan pola global yang belum disimpan). */
    public bool $ikuPunyaOverride = false;

    // ================= Alat manual: buat 1 folder di lokasi bebas =================

    public ?int $manualTahun = null;

    public ?int $manualTriwulan = null;

    public ?int $manualBulan = null;

    public ?int $manualIkuId = null;

    public string $manualKategoriNama = '';

    public string $manualNamaFolder = '';

    public function mount(): void
    {
        $config = FolderConfig::current();

        $this->hierarki = $config->pola_json['hierarki'] ?? FolderConfig::polaDefault()['hierarki'];
        $this->kategori = $config->pola_json['kategori'] ?? FolderConfig::polaDefault()['kategori'];
        $this->tahunBaru = (int) now()->year + 1;
        $this->manualTahun = (int) now()->year;
    }

    // ================= RF-15: urutan level hierarki (global) =================

    public function naikkanHierarki(int $index): void
    {
        // Index 0 SELALU "tahun" dan dikunci di posisi pertama (lihat FolderConfig::hierarkiAktif()),
        // jadi baris ke-1 tidak boleh naik lagi dan baris ke-2 tidak boleh menukar posisi dengan tahun.
        if ($index <= 1) {
            return;
        }

        $this->tukarPosisi($this->hierarki, $index, $index - 1);
    }

    public function turunkanHierarki(int $index): void
    {
        if ($index === 0 || $index >= count($this->hierarki) - 1) {
            return;
        }

        $this->tukarPosisi($this->hierarki, $index, $index + 1);
    }

    public function toggleHierarki(int $index): void
    {
        if ($index === 0) {
            return; // tahun tidak boleh dinonaktifkan — RF-16 bergantung padanya.
        }

        $this->hierarki[$index]['aktif'] = ! $this->hierarki[$index]['aktif'];
    }

    /**
     * RF-15: tambah tingkat folder baru dengan nama bebas — di luar 4 tingkat baku
     * (tahun/triwulan/bulan/iku), tingkat kustom memakai namanya sendiri apa adanya
     * sebagai nama folder tetap (lihat FolderStructureService::resolveKategoriFolder()).
     */
    public function tambahLevel(): void
    {
        $nama = trim($this->levelBaru);

        if ($nama === '') {
            $this->addError('levelBaru', 'Nama tingkat tidak boleh kosong.');

            return;
        }

        $sudahAda = collect($this->hierarki)->contains(fn ($h) => strcasecmp($h['level'], $nama) === 0);

        if ($sudahAda) {
            $this->addError('levelBaru', 'Tingkat dengan nama tersebut sudah ada.');

            return;
        }

        $this->hierarki[] = ['level' => $nama, 'aktif' => true, 'custom' => true];
        $this->levelBaru = '';
    }

    public function hapusLevel(int $index): void
    {
        if (($this->hierarki[$index]['custom'] ?? false) !== true) {
            return; // hanya tingkat kustom yang boleh dihapus struktural — 4 tingkat baku hanya bisa dinonaktifkan.
        }

        unset($this->hierarki[$index]);
        $this->hierarki = array_values($this->hierarki);
    }

    // ================= RF-12/RF-15: daftar kategori folder (global) =================

    public function naikkanKategori(int $index): void
    {
        if ($index === 0) {
            return;
        }

        $this->tukarPosisi($this->kategori, $index, $index - 1);
    }

    public function turunkanKategori(int $index): void
    {
        if ($index >= count($this->kategori) - 1) {
            return;
        }

        $this->tukarPosisi($this->kategori, $index, $index + 1);
    }

    public function toggleSubfolderKegiatan(int $index): void
    {
        $this->kategori[$index]['subfolder_per_kegiatan'] = ! $this->kategori[$index]['subfolder_per_kegiatan'];
    }

    /**
     * Kategori mana yang punya subfolder Bulan di dalamnya (mis. Capaian, Bukti-
     * Dukung-SAKIP) — kategori lain langsung menampung berkas tanpa dipecah bulan.
     */
    public function toggleSubfolderBulan(int $index): void
    {
        $this->kategori[$index]['subfolder_per_bulan'] = ! ($this->kategori[$index]['subfolder_per_bulan'] ?? false);
    }

    public function tambahKategori(): void
    {
        $nama = trim($this->kategoriBaru);

        if ($nama === '') {
            $this->addError('kategoriBaru', 'Nama kategori tidak boleh kosong.');

            return;
        }

        $sudahAda = collect($this->kategori)->contains(fn ($k) => strcasecmp($k['nama'], $nama) === 0);

        if ($sudahAda) {
            $this->addError('kategoriBaru', 'Kategori dengan nama tersebut sudah ada.');

            return;
        }

        $this->kategori[] = ['nama' => $nama, 'wajib' => false, 'subfolder_per_kegiatan' => false, 'subfolder_per_bulan' => false];
        $this->kategoriBaru = '';
    }

    public function hapusKategori(int $index): void
    {
        // RF-12: Capaian & Bukti-Dukung-SAKIP wajib ada, tidak boleh dihapus Tim SAKIP.
        if ($this->kategori[$index]['wajib'] ?? false) {
            $this->addError('kategori', 'Kategori "'.$this->kategori[$index]['nama'].'" wajib ada dan tidak dapat dihapus.');

            return;
        }

        unset($this->kategori[$index]);
        $this->kategori = array_values($this->kategori);
    }

    public function simpan(): void
    {
        FolderConfig::current()->update([
            'pola_json' => [
                'hierarki' => $this->hierarki,
                'kategori' => $this->kategori,
            ],
        ]);

        session()->flash('status', 'Pola struktur folder berhasil disimpan.');
    }

    // ================= Pola khusus per IKU (override opsional) =================

    /**
     * Muat pola IKU terpilih untuk disunting — pola override yang sudah tersimpan bila
     * ada, atau SALINAN pola global sebagai titik awal bila IKU ini belum punya pola
     * sendiri (belum tersimpan sampai tombol "Simpan Pola IKU Ini" ditekan).
     */
    public function pilihIku(int $ikuId): void
    {
        $this->ikuTerpilih = $ikuId;

        $override = IkuFolderConfig::where('iku_id', $ikuId)->first();

        if ($override) {
            $this->hierarkiIku = $override->pola_json['hierarki'];
            $this->kategoriIku = $override->pola_json['kategori'];
            $this->ikuPunyaOverride = true;
        } else {
            $this->hierarkiIku = $this->hierarki;
            $this->kategoriIku = $this->kategori;
            $this->ikuPunyaOverride = false;
        }
    }

    public function naikkanHierarkiIku(int $index): void
    {
        if ($index <= 1) {
            return;
        }

        $this->tukarPosisi($this->hierarkiIku, $index, $index - 1);
    }

    public function turunkanHierarkiIku(int $index): void
    {
        if ($index === 0 || $index >= count($this->hierarkiIku) - 1) {
            return;
        }

        $this->tukarPosisi($this->hierarkiIku, $index, $index + 1);
    }

    public function toggleHierarkiIku(int $index): void
    {
        if ($index === 0) {
            return;
        }

        $this->hierarkiIku[$index]['aktif'] = ! $this->hierarkiIku[$index]['aktif'];
    }

    public function tambahLevelIku(): void
    {
        $nama = trim($this->levelBaruIku);

        if ($nama === '') {
            $this->addError('levelBaruIku', 'Nama tingkat tidak boleh kosong.');

            return;
        }

        $sudahAda = collect($this->hierarkiIku)->contains(fn ($h) => strcasecmp($h['level'], $nama) === 0);

        if ($sudahAda) {
            $this->addError('levelBaruIku', 'Tingkat dengan nama tersebut sudah ada.');

            return;
        }

        $this->hierarkiIku[] = ['level' => $nama, 'aktif' => true, 'custom' => true];
        $this->levelBaruIku = '';
    }

    public function hapusLevelIku(int $index): void
    {
        if (($this->hierarkiIku[$index]['custom'] ?? false) !== true) {
            return;
        }

        unset($this->hierarkiIku[$index]);
        $this->hierarkiIku = array_values($this->hierarkiIku);
    }

    public function naikkanKategoriIku(int $index): void
    {
        if ($index === 0) {
            return;
        }

        $this->tukarPosisi($this->kategoriIku, $index, $index - 1);
    }

    public function turunkanKategoriIku(int $index): void
    {
        if ($index >= count($this->kategoriIku) - 1) {
            return;
        }

        $this->tukarPosisi($this->kategoriIku, $index, $index + 1);
    }

    public function toggleSubfolderKegiatanIku(int $index): void
    {
        $this->kategoriIku[$index]['subfolder_per_kegiatan'] = ! $this->kategoriIku[$index]['subfolder_per_kegiatan'];
    }

    public function toggleSubfolderBulanIku(int $index): void
    {
        $this->kategoriIku[$index]['subfolder_per_bulan'] = ! ($this->kategoriIku[$index]['subfolder_per_bulan'] ?? false);
    }

    public function tambahKategoriIku(): void
    {
        $nama = trim($this->kategoriBaruIku);

        if ($nama === '') {
            $this->addError('kategoriBaruIku', 'Nama kategori tidak boleh kosong.');

            return;
        }

        $sudahAda = collect($this->kategoriIku)->contains(fn ($k) => strcasecmp($k['nama'], $nama) === 0);

        if ($sudahAda) {
            $this->addError('kategoriBaruIku', 'Kategori dengan nama tersebut sudah ada.');

            return;
        }

        $this->kategoriIku[] = ['nama' => $nama, 'wajib' => false, 'subfolder_per_kegiatan' => false, 'subfolder_per_bulan' => false];
        $this->kategoriBaruIku = '';
    }

    public function hapusKategoriIku(int $index): void
    {
        if ($this->kategoriIku[$index]['wajib'] ?? false) {
            $this->addError('kategoriIku', 'Kategori "'.$this->kategoriIku[$index]['nama'].'" wajib ada dan tidak dapat dihapus.');

            return;
        }

        unset($this->kategoriIku[$index]);
        $this->kategoriIku = array_values($this->kategoriIku);
    }

    public function simpanPolaIku(): void
    {
        if (! $this->ikuTerpilih) {
            return;
        }

        IkuFolderConfig::updateOrCreate(
            ['iku_id' => $this->ikuTerpilih],
            ['pola_json' => [
                'hierarki' => $this->hierarkiIku,
                'kategori' => $this->kategoriIku,
            ]]
        );

        $this->ikuPunyaOverride = true;

        session()->flash('status', 'Pola folder khusus untuk IKU ini berhasil disimpan.');
    }

    /**
     * Hapus pola khusus IKU ini — IKU kembali memakai pola GLOBAL seperti IKU lain.
     */
    public function hapusOverrideIku(): void
    {
        if (! $this->ikuTerpilih) {
            return;
        }

        IkuFolderConfig::where('iku_id', $this->ikuTerpilih)->delete();

        $this->hierarkiIku = $this->hierarki;
        $this->kategoriIku = $this->kategori;
        $this->ikuPunyaOverride = false;

        session()->flash('status', 'Pola khusus dihapus — IKU ini kembali memakai pola folder global.');
    }

    // ================= RF-16: buat folder tahun lebih awal =================

    public function buatFolderTahun(): void
    {
        $this->validate([
            'tahunBaru' => ['required', 'integer', 'min:2020', 'max:2100'],
        ], [], ['tahunBaru' => 'tahun']);

        try {
            app(FolderStructureService::class)->buatFolderTahunLebihAwal($this->tahunBaru);

            session()->flash('status', "Folder tahun {$this->tahunBaru} berhasil disiapkan di Drive storage aktif.");
        } catch (RuntimeException $e) {
            $this->addError('tahunBaru', $e->getMessage());
        }
    }

    // ================= Alat manual: buat 1 folder di lokasi bebas =================

    /**
     * RF-16 turunan: menambah SATU folder tambahan di tengah tahun berjalan pada
     * lokasi bebas (mis. di dalam Triwulan III, IKU 1131, kategori Capaian) — tanpa
     * mengubah pola hierarki/kategori yang berlaku untuk unggahan berikutnya.
     */
    public function buatFolderManual(): void
    {
        $this->validate([
            'manualTahun' => ['required', 'integer', 'min:2020', 'max:2100'],
            'manualTriwulan' => ['nullable', 'integer', 'min:1', 'max:4'],
            'manualBulan' => ['nullable', 'integer', 'min:1', 'max:12'],
            'manualIkuId' => ['nullable', 'integer', 'exists:master_iku,id'],
            'manualNamaFolder' => ['required', 'string', 'max:100'],
        ], [], [
            'manualTahun' => 'tahun',
            'manualTriwulan' => 'triwulan',
            'manualBulan' => 'bulan',
            'manualIkuId' => 'IKU',
            'manualNamaFolder' => 'nama folder',
        ]);

        try {
            app(FolderStructureService::class)->buatFolderKustom(
                tahun: $this->manualTahun,
                triwulan: $this->manualTriwulan ?: null,
                bulan: $this->manualBulan ?: null,
                iku: $this->manualIkuId ? MasterIku::find($this->manualIkuId) : null,
                kategoriNama: trim($this->manualKategoriNama) ?: null,
                namaFolderBaru: FolderStructureService::namaOtomatis($this->manualNamaFolder),
            );

            session()->flash('status', "Folder \"{$this->manualNamaFolder}\" berhasil dibuat/ditemukan di Drive.");

            $this->reset(['manualTriwulan', 'manualBulan', 'manualIkuId', 'manualKategoriNama', 'manualNamaFolder']);
        } catch (RuntimeException $e) {
            $this->addError('manualNamaFolder', $e->getMessage());
        }
    }

    /**
     * @param  array<int, mixed>  $daftar
     */
    protected function tukarPosisi(array &$daftar, int $a, int $b): void
    {
        [$daftar[$a], $daftar[$b]] = [$daftar[$b], $daftar[$a]];
    }

    /**
     * Pratinjau jalur folder (RF-15) — dibangun murni dari pasangan hierarki/kategori
     * yang diberikan (bisa yang global sedang disunting, atau pola IKU sedang disunting),
     * pakai data contoh statis, TANPA menyentuh Drive.
     *
     * @param  list<array{level: string, aktif: bool}>  $hierarki
     * @param  list<array{nama: string, wajib: bool, subfolder_per_kegiatan: bool}>  $kategori
     * @return list<array{teks: string, indent: int, tipe: string}>
     */
    protected function jalurPreview(array $hierarki, array $kategori): array
    {
        $baris = [];
        $indent = 0;

        $baris[] = ['teks' => '📁 2026', 'indent' => $indent, 'tipe' => 'folder'];

        foreach ($hierarki as $h) {
            if ($h['level'] === 'tahun' || ! $h['aktif']) {
                continue;
            }

            $label = match ($h['level']) {
                'triwulan' => 'Triwulan III',
                'iku' => 'IKU 1131',
                // Tingkat kustom (RF-15) memakai namanya sendiri apa adanya sebagai folder tetap.
                default => $h['level'],
            };

            if ($label !== null) {
                $indent++;
                $baris[] = ['teks' => "📁 {$label}", 'indent' => $indent, 'tipe' => 'folder'];
            }
        }

        $indentKategori = $indent + 1;

        foreach ($kategori as $k) {
            $baris[] = [
                'teks' => '📁 '.$k['nama'].($k['wajib'] ? ' (wajib)' : ''),
                'indent' => $indentKategori,
                'tipe' => 'kategori',
            ];

            $indentDalamKategori = $indentKategori + 1;

            // Folder Bulan (bila kategori ini dikonfigurasi subfolder_per_bulan) selalu
            // membungkus subfolder-per-kegiatan, sesuai urutan nyata di Drive:
            // Kategori/Agustus/[Kegiatan]/berkas.
            if ($k['subfolder_per_bulan'] ?? false) {
                $baris[] = ['teks' => '📁 Agustus', 'indent' => $indentDalamKategori, 'tipe' => 'folder'];
                $indentDalamKategori++;
            }

            if ($k['subfolder_per_kegiatan']) {
                $baris[] = [
                    'teks' => '📁 [Pelaksanaan] pencacahan Sakernas Agustus 2026',
                    'indent' => $indentDalamKategori,
                    'tipe' => 'folder',
                ];
                $baris[] = [
                    'teks' => '📄 bukti-capaian.pdf',
                    'indent' => $indentDalamKategori + 1,
                    'tipe' => 'file',
                ];
            } else {
                $baris[] = [
                    'teks' => '📄 bukti-'.Str::slug($k['nama']).'.pdf',
                    'indent' => $indentDalamKategori,
                    'tipe' => 'file',
                ];
            }
        }

        return $baris;
    }

    public function render()
    {
        return view('livewire.folder-config-manager', [
            'preview' => $this->jalurPreview($this->hierarki, $this->kategori),
            'previewIku' => $this->ikuTerpilih ? $this->jalurPreview($this->hierarkiIku, $this->kategoriIku) : [],
            'ikuList' => MasterIku::daftarUrutKode(),
            'ikuDenganOverride' => IkuFolderConfig::pluck('iku_id')->all(),
        ]);
    }
}
