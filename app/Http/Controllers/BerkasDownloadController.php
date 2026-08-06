<?php

namespace App\Http\Controllers;

use App\Models\Berkas;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BerkasDownloadController extends Controller
{
    public function show(Berkas $berkas): StreamedResponse
    {
        abort_unless($berkas->path && Storage::disk('local')->exists($berkas->path), 404);

        return Storage::disk('local')->response($berkas->path, $berkas->nama_file);
    }
}
