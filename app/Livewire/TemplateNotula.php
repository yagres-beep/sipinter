<?php

namespace App\Livewire;

use App\Models\FolderConfig;
use App\Services\FolderStructureService;
use App\Services\GoogleDriveService;
use App\Services\NotulaBagian1DocxService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Template Notula (RF-41) — Tim SAKIP mengunggah template .docx MESIN resmi
 * (macro {{iku_blok}}, {{kode}}, {{sasaran}}, dst. — lihat
 * template_notula/PANDUAN_Template_Bagian_I_Mesin.md untuk daftar lengkapnya).
 * Berkas ini DIPAKAI LANGSUNG sebagai template generate Bagian I lewat
 * App\Services\NotulaBagian1DocxService::resolveTemplatePath() — begitu diunggah
 * di sini, format/tata letak Bagian I di web maupun unduhan .docx langsung ikut
 * berubah. Kalau berkas yang diunggah TIDAK punya struktur macro yang benar,
 * penyusunan Bagian I akan gagal/error, bukan cuma tampil beda.
 */
class TemplateNotula extends Component
{
    use WithFileUploads;

    public $templateFile = null;

    public bool $konfirmasiHapus = false;

    protected function rules(): array
    {
        return [
            'templateFile' => ['required', 'file', 'mimes:docx', 'max:5120'],
        ];
    }

    /**
     * Struktur berkas divalidasi (NotulaBagian1DocxService::validasiStrukturTemplate())
     * SEBELUM diaktifkan sebagai template Bagian I -- berkas ini dipakai LANGSUNG oleh
     * generate() (lihat catatan kelas di NotulaBagian1DocxService), jadi berkas yang
     * penanda bloknya belum lengkap (mis. lupa {{/iku_blok}}) harus ketahuan & ditolak
     * DI SINI, bukan baru meruntuhkan halaman Kompilasi Notula bagi semua pemakai
     * begitu template rusak ini terlanjur jadi template aktif.
     */
    public function unggah(): void
    {
        $this->validate();

        try {
            app(NotulaBagian1DocxService::class)->validasiStrukturTemplate($this->templateFile->getRealPath());
        } catch (\RuntimeException $e) {
            $this->addError('templateFile', $e->getMessage());

            return;
        }

        $config = FolderConfig::current();

        if ($config->template_notula_path && Storage::disk('local')->exists($config->template_notula_path)) {
            Storage::disk('local')->delete($config->template_notula_path);
        }

        $path = $this->templateFile->store('template-notula', 'local');

        if (! $path || ! Storage::disk('local')->exists($path)) {
            session()->flash('error', 'Gagal menyimpan berkas template ke server. Periksa izin tulis folder storage/app/private.');

            return;
        }

        $namaAsli = $this->templateFile->getClientOriginalName();

        $isian = [
            'template_notula_path' => $path,
            'template_notula_nama_asli' => $namaAsli,
        ];

        // Salinan lokal (storage/app/private) TIDAK persisten di Render free plan —
        // terhapus tiap kali container di-deploy ulang. Arsipkan juga ke Drive
        // (sama seperti Berkas & PDF final notula, lihat BerkasDownloadController)
        // supaya unduh() masih bisa mengambilnya walau salinan lokalnya sudah hilang.
        // Kegagalan Drive TIDAK membatalkan unggahan — salinan lokal tetap tersimpan
        // untuk sesi berjalan, hanya dicatat sebagai peringatan (pola sama seperti
        // NotulaService::setujui()).
        try {
            $hasil = app(FolderStructureService::class)->unggahTemplateNotula(Storage::disk('local')->path($path), $namaAsli);
            $isian['template_notula_drive_file_id'] = $hasil['drive_file_id'];
            $isian['template_notula_storage_account_id'] = $hasil['storage_account_id'];
        } catch (\Throwable $e) {
            Log::warning("Gagal mengarsipkan template notula ke Drive: {$e->getMessage()}");
        }

        $config->update($isian);

        session()->flash('status', 'Template notula berhasil diunggah.');

        $this->reset('templateFile');
    }

    public function unduh(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $config = FolderConfig::current();

        if ($config->template_notula_path && Storage::disk('local')->exists($config->template_notula_path)) {
            return Storage::disk('local')->download($config->template_notula_path, $config->template_notula_nama_asli);
        }

        abort_unless($config->template_notula_drive_file_id, 404);

        try {
            $konten = app(GoogleDriveService::class)->downloadFileContent($config->template_notula_drive_file_id);
        } catch (\Throwable $e) {
            Log::warning("Gagal mengambil template notula dari Drive: {$e->getMessage()}");
            abort(404);
        }

        // WAJIB StreamedResponse (bukan response() biasa) -- Livewire hanya mengenali
        // StreamedResponse/BinaryFileResponse sebagai "unduhan berkas" dari method
        // component (lihat SupportFileDownloads::valueIsntAFileResponse()); response()
        // biasa akan diam-diam diabaikan, browser tidak pernah menerima berkasnya.
        return new \Symfony\Component\HttpFoundation\StreamedResponse(
            fn () => print($konten),
            200,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Content-Disposition' => 'attachment; filename="'.$config->template_notula_nama_asli.'"',
            ]
        );
    }

    public function confirmHapus(): void
    {
        $this->konfirmasiHapus = true;
    }

    public function cancelHapus(): void
    {
        $this->konfirmasiHapus = false;
    }

    public function hapus(): void
    {
        $config = FolderConfig::current();

        if ($config->template_notula_path && Storage::disk('local')->exists($config->template_notula_path)) {
            Storage::disk('local')->delete($config->template_notula_path);
        }

        $config->update([
            'template_notula_path' => null,
            'template_notula_nama_asli' => null,
            'template_notula_drive_file_id' => null,
            'template_notula_storage_account_id' => null,
        ]);

        $this->konfirmasiHapus = false;

        session()->flash('status', 'Template notula dihapus.');
    }

    public function render()
    {
        return view('livewire.template-notula', [
            'config' => FolderConfig::current(),
        ]);
    }
}
