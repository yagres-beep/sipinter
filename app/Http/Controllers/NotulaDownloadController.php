<?php

namespace App\Http\Controllers;

use App\Models\Notula;
use App\Services\NotulaService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Endpoint unduhan notula gabungan (RF-43) — dibuat terautentikasi & tergerbang
 * peran secara eksplisit (bukan lewat rute publik storage.local Laravel yang tidak
 * berautentikasi sama sekali — sudah pernah dihindari juga untuk BerkasDownloadController).
 */
class NotulaDownloadController extends Controller
{
    /**
     * "Unduh draf" — TANPA blok TTD, hanya tersedia setelah seluruh bukti kegiatan
     * pada triwulan tsb terverifikasi (RF-43).
     */
    public function draf(Notula $notula, NotulaService $notulaService): StreamedResponse
    {
        abort_unless($notula->pdf_gabungan, 404);
        abort_unless(
            $notulaService->semuaBuktiTerverifikasi($notula->periode),
            403,
            'Belum seluruh bukti kegiatan pada triwulan ini terverifikasi.'
        );

        $namaUnduhan = "notula-draf-tw{$notula->periode->triwulan}-{$notula->periode->tahun}.pdf";

        return Storage::disk('local')->response($notula->pdf_gabungan, $namaUnduhan);
    }

    /**
     * "Unduh final" — DENGAN blok TTD, hanya aktif setelah Kepala menyetujui (RF-43, RF-44).
     */
    public function final(Notula $notula): StreamedResponse
    {
        abort_unless($notula->status === Notula::STATUS_DISETUJUI && $notula->pdf_final, 403, 'Notula belum disetujui Kepala.');

        $namaUnduhan = "notula-final-tw{$notula->periode->triwulan}-{$notula->periode->tahun}.pdf";

        return Storage::disk('local')->response($notula->pdf_final, $namaUnduhan);
    }

    /**
     * Pratinjau PDF gabungan untuk Kepala meninjau sebelum menyetujui/mengembalikan
     * (panel "Persetujuan Notula"). SENGAJA tidak memakai gerbang "semua bukti
     * terverifikasi" seperti draf() — Kepala tetap harus bisa meninjau apa pun yang
     * sudah dikirim Tim SAKIP untuk memutuskan menyetujui atau mengembalikannya.
     */
    public function pratinjau(Notula $notula): StreamedResponse
    {
        abort_unless($notula->pdf_gabungan, 404);

        return Storage::disk('local')->response($notula->pdf_gabungan, null, [], 'inline');
    }

    /**
     * Pratinjau inline berkas PDF Bagian II/III yang sudah dikonversi — dipakai di
     * layar Kompilasi Notula supaya Tim SAKIP bisa melihat isinya tanpa mengunduh.
     */
    public function pratinjauBagian2(Notula $notula): StreamedResponse
    {
        abort_unless($notula->bagian2_pdf, 404);

        return Storage::disk('local')->response($notula->bagian2_pdf, null, [], 'inline');
    }

    public function pratinjauBagian3(Notula $notula): StreamedResponse
    {
        abort_unless($notula->bagian3_pdf, 404);

        return Storage::disk('local')->response($notula->bagian3_pdf, null, [], 'inline');
    }

    /**
     * F2.2: unduh template Word resmi Bagian II/III (bukan hasil generate — Tim SAKIP
     * mengisinya di Word lalu mengunggahnya balik lewat Kompilasi Notula). Berkasnya
     * disimpan sebagai arsip di root proyek (template_notula/, bukan storage), sama
     * seperti berkas arsip lain yang dikelola lewat TemplateNotula — file ini murni
     * bawaan aplikasi, bukan unggahan pengguna.
     */
    /**
     * Bagian I TIDAK diunggah balik (disusun otomatis, lihat NotulaService::susunBagianSatu())
     * — berkas ini murni panduan/referensi supaya Tim SAKIP tahu isi apa yang perlu diketik
     * di kolom Analisis Capaian Kinerja/Kendala/Solusi/RTL/Dasar Hitung agar hasil cetaknya
     * mengikuti struktur resmi.
     */
    public function templateBagian1(): BinaryFileResponse
    {
        $path = base_path('template_notula/Panduan_Template_dan_Rekomendasi_BagianI.docx');
        abort_unless(file_exists($path), 404);

        return response()->download($path, 'Panduan_Bagian_I_Capaian_Kinerja.docx');
    }

    public function templateBagian2(): BinaryFileResponse
    {
        $path = base_path('template_notula/SIPINTER_Template_Bagian_II_Prioritas.docx');
        abort_unless(file_exists($path), 404);

        return response()->download($path, 'SIPINTER_Template_Bagian_II_Prioritas.docx');
    }

    public function templateBagian3(): BinaryFileResponse
    {
        $path = base_path('template_notula/SIPINTER_Template_Bagian_III_Anggaran.docx');
        abort_unless(file_exists($path), 404);

        return response()->download($path, 'SIPINTER_Template_Bagian_III_Anggaran.docx');
    }
}
