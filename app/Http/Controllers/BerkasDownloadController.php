<?php

namespace App\Http\Controllers;

use App\Models\Berkas;
use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class BerkasDownloadController extends Controller
{
    /**
     * Salinan lokal (storage/app/private) TIDAK persisten di Render free plan —
     * terhapus tiap kali container di-deploy ulang, walau unggahan ke Drive sudah
     * sukses sebelumnya. Karena itu jatuh ke Drive (sumber yang persisten) lewat
     * drive_file_id bila salinan lokalnya sudah tidak ada, alih-alih langsung 404.
     *
     * Hasil unduhan dari Drive disimpan ulang ke disk lokal, supaya pratinjau
     * berikutnya (mis. tiap modal dibuka ulang, atau tiap klik Sesuai/Tidak Sesuai)
     * tidak perlu memanggil Drive API lagi sampai container di-deploy ulang.
     */
    public function show(Berkas $berkas): Response
    {
        if ($berkas->path && Storage::disk('local')->exists($berkas->path)) {
            return Storage::disk('local')->response($berkas->path, $berkas->nama_file);
        }

        if (! $berkas->drive_file_id) {
            return $this->tidakDitemukan($berkas, 'Salinan berkas di server sudah terhapus (server di-deploy ulang) dan berkas ini dulu gagal terunggah ke Google Drive, jadi tidak ada cadangannya. Minta Ketua Tim mengunggah ulang bukti ini.');
        }

        try {
            $konten = app(GoogleDriveService::class)->downloadFileContent($berkas->drive_file_id, $berkas->storageAccount);
        } catch (\Throwable $e) {
            Log::warning("Gagal mengambil berkas {$berkas->id} ({$berkas->kode}) dari Drive: {$e->getMessage()}");

            return $this->tidakDitemukan($berkas, $e->getMessage());
        }

        if ($berkas->path) {
            try {
                Storage::disk('local')->put($berkas->path, $konten);
            } catch (\Throwable $e) {
                Log::info("Tidak bisa menyimpan ulang salinan lokal berkas {$berkas->kode}: {$e->getMessage()}");
            }
        }

        $mime = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $konten) ?: 'application/octet-stream';

        return response($konten, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.addcslashes($berkas->nama_file, '"\\').'"',
        ]);
    }

    /**
     * Halaman 404 yang menjelaskan SEBABNYA (tampil di dalam iframe pratinjau),
     * bukan cuma "404 NOT FOUND" polos yang membuat pengguna menebak-nebak.
     */
    protected function tidakDitemukan(Berkas $berkas, string $sebab): Response
    {
        return response()->view('berkas.tidak-ditemukan', [
            'berkas' => $berkas,
            'sebab' => $sebab,
        ], 404);
    }
}
