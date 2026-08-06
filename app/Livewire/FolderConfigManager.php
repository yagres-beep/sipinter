<?php

namespace App\Livewire;

use App\Models\FolderConfig;
use App\Services\FolderStructureService;
use Livewire\Component;
use RuntimeException;

/**
 * Modul Tim SAKIP untuk mengatur pola struktur folder Drive (RF-15) dan membuat
 * folder tahun lebih awal (RF-16). Perubahan hierarki/kategori di sini TIDAK
 * langsung menyentuh Drive — hanya mengubah pola_json di tabel folder_config,
 * yang baru benar-benar dipakai saat berkas berikutnya diunggah (tetap lazy, RF-14).
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

    public function mount(): void
    {
        $config = FolderConfig::current();

        $this->hierarki = $config->pola_json['hierarki'] ?? FolderConfig::polaDefault()['hierarki'];
        $this->kategori = $config->pola_json['kategori'] ?? FolderConfig::polaDefault()['kategori'];
        $this->tahunBaru = (int) now()->year + 1;
    }

    // ================= RF-15: urutan level hierarki =================

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

    // ================= RF-12/RF-15: daftar kategori folder =================

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

        $this->kategori[] = ['nama' => $nama, 'wajib' => false, 'subfolder_per_kegiatan' => false];
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

    /**
     * @param  array<int, mixed>  $daftar
     */
    protected function tukarPosisi(array &$daftar, int $a, int $b): void
    {
        [$daftar[$a], $daftar[$b]] = [$daftar[$b], $daftar[$a]];
    }

    /**
     * Pratinjau jalur folder (RF-15) — dibangun murni dari state $hierarki/$kategori
     * yang SEDANG diedit (belum tentu sudah disimpan), pakai data contoh statis
     * (mengikuti panel "Struktur Folder Otomatis" di mockup), TANPA menyentuh Drive.
     *
     * @return list<array{teks: string, indent: int, tipe: string}>
     */
    protected function jalurPreview(): array
    {
        $baris = [];
        $indent = 0;

        $baris[] = ['teks' => '📁 2026', 'indent' => $indent, 'tipe' => 'folder'];

        foreach ($this->hierarki as $h) {
            if ($h['level'] === 'tahun' || ! $h['aktif']) {
                continue;
            }

            $label = match ($h['level']) {
                'triwulan' => 'Triwulan III',
                'bulan' => 'Agustus',
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

        foreach ($this->kategori as $k) {
            $baris[] = [
                'teks' => '📁 '.$k['nama'].($k['wajib'] ? ' (wajib)' : ''),
                'indent' => $indentKategori,
                'tipe' => 'kategori',
            ];

            if ($k['subfolder_per_kegiatan']) {
                $baris[] = [
                    'teks' => '📁 [Pelaksanaan] pencacahan Sakernas Agustus 2026',
                    'indent' => $indentKategori + 1,
                    'tipe' => 'folder',
                ];
                $baris[] = [
                    'teks' => '📄 bukti-capaian.pdf',
                    'indent' => $indentKategori + 2,
                    'tipe' => 'file',
                ];
            }
        }

        return $baris;
    }

    public function render()
    {
        return view('livewire.folder-config-manager', [
            'preview' => $this->jalurPreview(),
        ]);
    }
}
