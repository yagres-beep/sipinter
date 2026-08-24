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
     */
    public function show(Berkas $berkas): Response
    {
        if ($berkas->path && Storage::disk('local')->exists($berkas->path)) {
            return Storage::disk('local')->response($berkas->path, $berkas->nama_file);
        }

        abort_unless($berkas->drive_file_id, 404);

        try {
            $konten = app(GoogleDriveService::class)->downloadFileContent($berkas->drive_file_id);
        } catch (\Throwable $e) {
            Log::warning("Gagal mengambil berkas {$berkas->id} dari Drive: {$e->getMessage()}");
            abort(404);
        }

        $mime = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $konten) ?: 'application/octet-stream';

        return response($konten, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$berkas->nama_file.'"',
        ]);
    }
}
