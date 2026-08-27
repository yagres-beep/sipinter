<?php

use App\Models\FolderConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

/**
 * Isi "Template Notula" (halaman Pengaturan, App\Livewire\TemplateNotula) dengan
 * SIPINTER_Template_Bagian_I_Mesin.docx sebagai contoh awal -- supaya Tim SAKIP
 * langsung punya rujukan macro {{...}} yang dipakai NotulaBagian1DocxService tanpa
 * perlu mengunggahnya manual dulu. TIDAK menimpa kalau sudah ada template lain yang
 * sungguhan diunggah Tim SAKIP (template_notula_path sudah terisi).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Migrasi ini menulis berkas sungguhan ke disk 'local' -- dilewati saat
        // testing (RefreshDatabase menjalankan migrasi ini ulang tiap suite) supaya
        // tidak menumpuk berkas sisa di storage/app/private tiap kali tes dijalankan.
        if (app()->environment('testing')) {
            return;
        }

        $config = FolderConfig::current();

        if ($config->template_notula_path) {
            return;
        }

        $sumber = base_path('template_notula/SIPINTER_Template_Bagian_I_Mesin.docx');
        if (! file_exists($sumber)) {
            return;
        }

        $tujuan = 'template-notula/'.uniqid('mesin_').'.docx';
        Storage::disk('local')->put($tujuan, file_get_contents($sumber));

        $config->update([
            'template_notula_path' => $tujuan,
            'template_notula_nama_asli' => 'SIPINTER_Template_Bagian_I_Mesin.docx',
        ]);
    }

    public function down(): void
    {
        // Sengaja tidak menghapus apa pun -- bisa jadi Tim SAKIP sudah mengganti
        // template ini dengan unggahan sungguhan setelah migrasi ini berjalan.
    }
};
