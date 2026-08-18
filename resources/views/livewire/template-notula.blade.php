<div>
    <div class="page-title">Template Notula</div>
    <div class="page-sub">Unggah template resmi (.docx) berisi penanda otomatis untuk Bagian I.</div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <div class="info">
        ℹ️ Penanda yang didukung: <code>@{{iku}}</code>, <code>@{{analisis_capaian}}</code>,
        <code>@{{kendala_solusi_kumulatif}}</code>, <code>@{{rtl}}</code>, <code>@{{ttd_kepala}}</code>.
        Template ini disimpan sebagai arsip format resmi — penyusunan Bagian I otomatis pada Kompilasi Notula tetap memakai tampilan bawaan sistem.
    </div>

    <div class="card">
        <div class="sec"><span>Unggah Template (.docx)</span></div>

        <div class="field">
            @if ($templateFile)
                <div class="filechip">
                    <span class="nm">📄 {{ $templateFile->getClientOriginalName() }}</span>
                    <span class="x" style="cursor:pointer" wire:click="$set('templateFile', null)">✕</span>
                </div>
            @else
                <label class="upload" style="cursor:pointer;display:block">
                    <div class="big">📤</div>
                    Klik untuk memilih berkas template (.docx)
                    <input type="file" wire:model="templateFile" accept=".docx" style="display:none">
                </label>
            @endif

            <div wire:loading wire:target="templateFile" style="font-size:11.5px;color:var(--muted);margin-top:6px">Mengunggah berkas…</div>

            @error('templateFile')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>

        <div class="btn-row">
            <button type="button" class="btn btn-primary" wire:click="unggah" wire:loading.attr="disabled" wire:target="unggah">
                <span wire:loading.remove wire:target="unggah">Unggah &amp; Simpan</span>
                <span wire:loading wire:target="unggah">Menyimpan…</span>
            </button>
        </div>
    </div>

    <div class="card">
        <div class="sec"><span>Template Tersimpan</span></div>

        @if ($config->template_notula_path)
            <div class="filechip ok">
                <span class="nm">📄 {{ $config->template_notula_nama_asli }} <span class="sub">Diperbarui {{ $config->updated_at->translatedFormat('d F Y, H:i') }}</span></span>
                <button type="button" class="btn btn-red btn-sm" wire:click="confirmHapus">Hapus</button>
            </div>
        @else
            <p style="color:var(--muted);font-size:13px">Belum ada template yang diunggah.</p>
        @endif
    </div>

    <x-confirm-modal :show="$konfirmasiHapus" title="Hapus Template Notula?" message="Hapus template notula ini? Tindakan ini tidak dapat dibatalkan.">
        <button type="button" class="btn btn-ghost" wire:click="cancelHapus">Batal</button>
        <button type="button" class="btn btn-red" wire:click="hapus">Hapus</button>
    </x-confirm-modal>
</div>
