<?php

namespace App\Livewire;

use App\Models\FolderConfig;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Template Notula (RF-41) — Tim SAKIP mengunggah template .docx resmi berisi
 * penanda {{iku}}, {{analisis_capaian}}, {{kendala_solusi_kumulatif}}, {{rtl}},
 * {{ttd_kepala}}. Disimpan sebagai arsip/referensi format resmi; penyusunan
 * Bagian I otomatis (NotulaService::susunBagianSatu()) tetap memakai tampilan
 * web bawaan sistem, bukan mem-parse berkas ini.
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

    public function unggah(): void
    {
        $this->validate();

        $config = FolderConfig::current();

        if ($config->template_notula_path && Storage::disk('local')->exists($config->template_notula_path)) {
            Storage::disk('local')->delete($config->template_notula_path);
        }

        $path = $this->templateFile->store('template-notula', 'local');

        $config->update([
            'template_notula_path' => $path,
            'template_notula_nama_asli' => $this->templateFile->getClientOriginalName(),
        ]);

        session()->flash('status', 'Template notula berhasil diunggah.');

        $this->reset('templateFile');
    }

    public function unduh(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $config = FolderConfig::current();
        abort_unless($config->template_notula_path && Storage::disk('local')->exists($config->template_notula_path), 404);

        return Storage::disk('local')->download($config->template_notula_path, $config->template_notula_nama_asli);
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

        $config->update(['template_notula_path' => null, 'template_notula_nama_asli' => null]);

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
