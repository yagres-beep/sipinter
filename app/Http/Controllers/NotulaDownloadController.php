<?php

namespace App\Http\Controllers;

use App\Models\Notula;
use App\Services\NotulaBagian1DocxService;
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
     * Unduh Bagian I sebagai .docx ASLI (bukan panduan statis) — template resmi
     * bervariabel {{...}} (template_notula/SIPINTER_Template_Bagian_I_Mesin.docx)
     * diisi otomatis dari data yang sama dipakai jalur PDF (lihat
     * NotulaService::kumpulkanDataBagianSatu()), lewat NotulaBagian1DocxService.
     * Digenerate on-the-fly ke berkas sementara lalu langsung dihapus setelah terkirim.
     */
    public function unduhBagian1Docx(Notula $notula, NotulaBagian1DocxService $docxService): BinaryFileResponse
    {
        $path = storage_path("app/private/notula/{$notula->id}/bagian1-mesin.docx");
        $docxService->generate($notula, $path);

        $namaUnduhan = "notula-bagian1-tw{$notula->periode->triwulan}-{$notula->periode->tahun}.docx";

        return response()->download($path, $namaUnduhan)->deleteFileAfterSend();
    }

    /**
     * Unduhan cepat pratinjau Bagian I+II+III yang tersimpan SAAT INI — TIDAK terikat
     * status "semua bukti terverifikasi" maupun alur kirim-ke-Kepala seperti draf()
     * di atas (lihat NotulaService::renderPratinjauPdf()); murni kenyamanan Tim SAKIP
     * untuk memeriksa hasil sewaktu-waktu selagi masih menyusun. Digenerate on-the-fly
     * lalu langsung dihapus setelah terkirim, sama seperti unduhBagian1Docx().
     */
    public function pratinjauCepatPdf(Notula $notula, NotulaService $notulaService): BinaryFileResponse
    {
        $path = storage_path("app/private/notula/{$notula->id}/pratinjau-cepat.pdf");
        $notulaService->renderPratinjauPdf($notula, $path);

        $namaUnduhan = "notula-pratinjau-tw{$notula->periode->triwulan}-{$notula->periode->tahun}.pdf";

        return response()->download($path, $namaUnduhan)->deleteFileAfterSend();
    }

    /**
     * Bagian I TIDAK diunggah balik (disusun otomatis, lihat NotulaService::susunBagianSatu())
     * — berkas ini murni panduan/referensi supaya Tim SAKIP tahu isi apa yang perlu diketik
     * di kolom Analisis Capaian Kinerja/Kendala/Solusi/RTL/Dasar Hitung agar hasil cetaknya
     * mengikuti struktur resmi. Contoh TEMPLATE MESIN mentah (macro {{...}} apa adanya, yang
     * dipakai NotulaBagian1DocxService::generate()) ada di menu Pengaturan > Template Notula
     * (App\Livewire\TemplateNotula), bukan di sini -- lihat
     * template_notula/PANDUAN_Template_Bagian_I_Mesin.md untuk konvensi macro-nya.
     */
    public function templateBagian1(): BinaryFileResponse
    {
        $path = base_path('template_notula/Panduan_Template_dan_Rekomendasi_BagianI.docx');
        abort_unless(file_exists($path), 404);

        return response()->download($path, 'Panduan_Bagian_I_Capaian_Kinerja.docx');
    }

    /**
     * F2.2: unduh template Word resmi Bagian II/III (bukan hasil generate — Tim SAKIP
     * mengisinya di Word lalu mengunggahnya balik lewat Kompilasi Notula). Berkasnya
     * disimpan sebagai arsip di root proyek (template_notula/, bukan storage), sama
     * seperti berkas arsip lain yang dikelola lewat TemplateNotula — file ini murni
     * bawaan aplikasi, bukan unggahan pengguna.
     */
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
