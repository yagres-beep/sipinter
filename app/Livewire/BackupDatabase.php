<?php

namespace App\Livewire;

use App\Models\Backup;
use App\Services\BackupService;
use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

/**
 * Modul Tim SAKIP untuk membuat & mengunduh backup database (lihat
 * App\Services\BackupService untuk alur pembuatannya).
 */
class BackupDatabase extends Component
{
    public ?int $konfirmasiHapusId = null;

    public function buat(): void
    {
        $backup = app(BackupService::class)->buat(auth()->user());

        if ($backup->status === Backup::STATUS_GAGAL) {
            session()->flash('error', "Gagal membuat backup: {$backup->catatan}");

            return;
        }

        if ($backup->catatan) {
            // Lokal berhasil, Drive gagal -- tetap dianggap sukses (lihat BackupService),
            // hanya diberi peringatan supaya Tim SAKIP tahu harus segera mengunduhnya.
            session()->flash('error', $backup->catatan);
        } else {
            session()->flash('status', "Backup \"{$backup->nama_file}\" berhasil dibuat.");
        }
    }

    public function unduh(int $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $backup = Backup::findOrFail($id);

        if ($backup->adaSalinanLokal()) {
            return Storage::disk('local')->download($backup->path, $backup->nama_file);
        }

        abort_unless($backup->drive_file_id, 404);

        try {
            $konten = app(GoogleDriveService::class)->downloadFileContent($backup->drive_file_id);
        } catch (\Throwable $e) {
            Log::warning("Gagal mengambil backup {$backup->id} dari Drive: {$e->getMessage()}");
            abort(404);
        }

        // WAJIB StreamedResponse -- lihat catatan TemplateNotula::unduh() soal
        // SupportFileDownloads hanya mengenali StreamedResponse/BinaryFileResponse
        // sebagai unduhan berkas dari method component Livewire.
        return new \Symfony\Component\HttpFoundation\StreamedResponse(
            fn () => print($konten),
            200,
            [
                'Content-Type' => 'application/sql',
                'Content-Disposition' => 'attachment; filename="'.$backup->nama_file.'"',
            ]
        );
    }

    public function confirmHapus(int $id): void
    {
        $this->konfirmasiHapusId = $id;
    }

    public function batalHapus(): void
    {
        $this->konfirmasiHapusId = null;
    }

    /**
     * Hapus riwayat & salinan lokalnya saja -- salinan di Google Drive (bila ada)
     * TIDAK ikut dihapus, sengaja dibiarkan sebagai jaring pengaman tambahan yang
     * masih bisa dibuka manual lewat Drive walau baris riwayatnya sudah dibuang.
     */
    public function hapus(int $id): void
    {
        $backup = Backup::findOrFail($id);

        if ($backup->adaSalinanLokal()) {
            Storage::disk('local')->delete($backup->path);
        }

        $backup->delete();

        $this->konfirmasiHapusId = null;

        session()->flash('status', 'Riwayat backup dihapus.');
    }

    public function render()
    {
        return view('livewire.backup-database', [
            'daftarBackup' => Backup::with('dibuatOleh')->latest()->get(),
        ]);
    }
}
