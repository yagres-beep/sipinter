<?php

namespace App\Livewire;

use App\Models\FolderConfig;
use App\Services\FolderStructureService;
use App\Services\GoogleDriveService;
use App\Services\NotulaBagian1DocxService;
use App\Support\KatalogVariabel;
use App\Support\RegistriVariabel;
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

    /** Pemilih variabel: pencarian & kategori. */
    public string $cariVariabel = '';

    public string $kategoriVariabel = '';

    /**
     * Hasil pemeriksaan placeholder template aktif (lihat periksaTemplate()).
     *
     * @var array<string, mixed>|null
     */
    public ?array $hasilPeriksa = null;

    /**
     * Periksa placeholder template yang sedang dipakai (unggahan, atau bawaan bila belum
     * ada) terhadap Katalog Variabel: tidak dikenal, usang, dan salah letak. Hanya membaca.
     */
    public function periksaTemplate(): void
    {
        $docx = app(NotulaBagian1DocxService::class);

        try {
            $path = $docx->pathTemplateAktif();
        } catch (\Throwable $e) {
            $this->hasilPeriksa = ['galat' => 'Template aktif tidak bisa dibaca: '.$e->getMessage()];

            return;
        }

        $this->hasilPeriksa = $docx->analisisTemplate($path) + [
            'bawaan' => realpath($path) === realpath(base_path('template_notula/SIPINTER_Template_Bagian_I_Mesin.docx')),
            'galat' => null,
        ];
    }

    /**
     * Kalimat peringatan dari hasil analisisTemplate() — dipakai saat unggah.
     *
     * @param  array<string, mixed>  $analisis
     * @return list<string>
     */
    protected static function peringatanDari(array $analisis): array
    {
        $pesan = [];

        if ($analisis['tidak_dikenal'] !== []) {
            $pesan[] = 'Placeholder berikut tidak dikenali sistem dan TIDAK akan terisi (tercetak apa adanya di notula): '
                .collect($analisis['tidak_dikenal'])->map(fn ($m) => '{{'.$m.'}}')->implode(', ')
                .'. Periksa ejaannya di Katalog Variabel, atau hapus dari template bila memang tidak dipakai.';
        }

        foreach ($analisis['usang'] as $kode => $pengganti) {
            $pesan[] = 'Placeholder {{'.$kode.'}} sudah tidak disarankan'.($pengganti ? ' — gunakan {{'.$pengganti.'}}' : '').'.';
        }

        foreach ($analisis['salah_tempat'] as $kode => $lingkup) {
            $pesan[] = 'Placeholder {{'.$kode.'}} diletakkan di luar lingkupnya sehingga tidak akan terisi. Letak yang benar: '.$lingkup.'.';
        }

        return $pesan;
    }

    protected function rules(): array
    {
        return [
            'templateFile' => ['required', 'file', 'mimes:docx'],
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

        $docx = app(NotulaBagian1DocxService::class);

        try {
            $docx->validasiStrukturTemplate($this->templateFile->getRealPath());
        } catch (\RuntimeException $e) {
            $this->addError('templateFile', $e->getMessage());

            return;
        }

        $peringatan = self::peringatanDari($docx->analisisTemplate($this->templateFile->getRealPath()));

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

        if ($peringatan !== []) {
            session()->flash('peringatan', implode(' ', $peringatan));
        }

        $this->reset('templateFile', 'hasilPeriksa');
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
        $this->hasilPeriksa = null;

        session()->flash('status', 'Template notula dihapus.');
    }

    public function render()
    {
        $variabel = KatalogVariabel::saring(collect(RegistriVariabel::semua())->values(), [
            'cari' => $this->cariVariabel,
            'kategori' => $this->kategoriVariabel,
            'konteks' => RegistriVariabel::KONTEKS_NOTULA_DOCX,
        ]);

        return view('livewire.template-notula', [
            'config' => FolderConfig::current(),
            'kelompokVariabel' => KatalogVariabel::kelompokkan($variabel),
            'jumlahVariabel' => count(RegistriVariabel::kodeKonteks(RegistriVariabel::KONTEKS_NOTULA_DOCX)),
            'jumlahTampil' => $variabel->count(),
        ]);
    }
}
