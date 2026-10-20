<?php

namespace Tests\Support;

use App\Models\StorageAccount;
use App\Services\GoogleDriveService;
use RuntimeException;

/**
 * Google Drive palsu di memori untuk pengujian — menyimpan pohon folder & berkas
 * sungguhan (id, nama, induk, appProperties, isi) supaya uji bisa memeriksa letak
 * fisik berkas, salinan, dan penghapusan tanpa menyentuh Drive asli.
 */
class DrivePalsu extends GoogleDriveService
{
    /** @var array<string, array{nama: string, induk: ?string, folder: bool, penanda: array, isi: ?string, sampah: bool}> */
    public array $item = [];

    public int $jumlahSalin = 0;

    public bool $gagalSalin = false;

    public bool $gagalHapus = false;

    protected int $urut = 0;

    public function __construct()
    {
        $this->item['root'] = ['nama' => 'root', 'induk' => null, 'folder' => true, 'penanda' => [], 'isi' => null, 'sampah' => false];
    }

    protected function idBaru(string $awalan): string
    {
        return $awalan.'-'.(++$this->urut);
    }

    public function createFolder(string $name, string $parentFolderId): string
    {
        $id = $this->idBaru('fld');
        $this->item[$id] = ['nama' => $name, 'induk' => $parentFolderId, 'folder' => true, 'penanda' => [], 'isi' => null, 'sampah' => false];

        return $id;
    }

    public function findOrCreateFolder(string $name, string $parentFolderId): string
    {
        foreach ($this->item as $id => $i) {
            if ($i['folder'] && ! $i['sampah'] && $i['induk'] === $parentFolderId && $i['nama'] === $name) {
                return $id;
            }
        }

        return $this->createFolder($name, $parentFolderId);
    }

    public function foldersInFolder(string $parentFolderId): array
    {
        return collect($this->item)->filter(fn ($i) => $i['folder'] && ! $i['sampah'] && $i['induk'] === $parentFolderId)->map->nama->all();
    }

    public function namesInFolder(string $parentFolderId): array
    {
        return collect($this->item)->filter(fn ($i) => ! $i['sampah'] && $i['induk'] === $parentFolderId)->pluck('nama')->values()->all();
    }

    public function uploadFile(string $localPath, string $filename, string $parentFolderId, string $mimeType = 'application/pdf', array $penanda = []): array
    {
        $id = $this->idBaru('file');
        $this->item[$id] = ['nama' => $filename, 'induk' => $parentFolderId, 'folder' => false, 'penanda' => $penanda, 'isi' => md5_file($localPath) ?: $localPath, 'sampah' => false];

        return ['id' => $id, 'name' => $filename, 'web_view_link' => null, 'size_bytes' => 10];
    }

    public function copyFile(string $fileId, string $filename, string $parentFolderId, array $penanda = []): array
    {
        if ($this->gagalSalin) {
            throw new RuntimeException('Drive sedang tidak bisa dihubungi');
        }

        $asal = $this->item[$fileId] ?? throw new RuntimeException("Berkas {$fileId} tidak ada");
        $this->jumlahSalin++;

        $id = $this->idBaru('salinan');
        $this->item[$id] = ['nama' => $filename, 'induk' => $parentFolderId, 'folder' => false, 'penanda' => array_merge($asal['penanda'], $penanda), 'isi' => $asal['isi'], 'sampah' => false];

        return ['id' => $id, 'name' => $filename, 'web_view_link' => null, 'size_bytes' => 10];
    }

    public function cariBerkasBerpenanda(string $kunci, string $nilai): ?array
    {
        foreach ($this->item as $id => $i) {
            if (! $i['sampah'] && ($i['penanda'][$kunci] ?? null) === $nilai) {
                return ['id' => $id, 'name' => $i['nama'], 'web_view_link' => null, 'size_bytes' => 10];
            }
        }

        return null;
    }

    public function namaFile(string $fileId): string
    {
        return $this->item[$fileId]['nama'] ?? throw new RuntimeException("Berkas {$fileId} tidak ada");
    }

    public function moveFile(string $fileId, string $parentFolderId): void
    {
        $this->item[$fileId]['induk'] = $parentFolderId;
    }

    public function renameFile(string $fileId, string $namaBaru): void
    {
        $this->item[$fileId]['nama'] = $namaBaru;
    }

    public function deleteFile(string $fileId, ?StorageAccount $akun = null): void
    {
        if ($this->gagalHapus) {
            throw new RuntimeException('Drive sedang tidak bisa dihubungi');
        }

        unset($this->item[$fileId]);
    }

    public function trashFile(string $fileId, ?StorageAccount $akun = null): void
    {
        if ($this->gagalHapus) {
            throw new RuntimeException('Drive sedang tidak bisa dihubungi');
        }

        if (isset($this->item[$fileId])) {
            $this->item[$fileId]['sampah'] = true;
        }
    }

    public function getFileLink(string $fileId): string
    {
        return "https://drive.test/file/{$fileId}";
    }

    public function folderLink(string $folderId): string
    {
        return "https://drive.test/folder/{$folderId}";
    }

    /** Jalur lengkap satu item, mis. "root/2026/Triwulan III/.../undangan.pdf". */
    public function jalur(string $id): string
    {
        $bagian = [];

        for ($i = $id; $i !== null; $i = $this->item[$i]['induk'] ?? null) {
            array_unshift($bagian, $this->item[$i]['nama']);
        }

        return implode('/', $bagian);
    }

    /** @return list<string> jalur seluruh berkas (bukan folder) yang tidak di Sampah. */
    public function semuaBerkas(): array
    {
        return collect($this->item)->filter(fn ($i) => ! $i['folder'] && ! $i['sampah'])->keys()->map(fn ($id) => $this->jalur($id))->sort()->values()->all();
    }

    /** @return list<string> jalur berkas di Sampah. */
    public function berkasDiSampah(): array
    {
        return collect($this->item)->filter(fn ($i) => ! $i['folder'] && $i['sampah'])->keys()->map(fn ($id) => $this->jalur($id))->sort()->values()->all();
    }
}
