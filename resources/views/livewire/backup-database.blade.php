<div>
    <div class="page-head">
        <div class="page-title">Backup Database</div>
        <div class="page-sub">Buat & unduh salinan penuh database aplikasi (data IKU, capaian, notula, dsb).</div>
    </div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="info red" style="margin-bottom:14px">⚠️ {{ session('error') }}</div>
    @endif

    <div class="info">
        ℹ️ Backup berisi DATA SAJA (bukan berkas bukti dukung — berkas sudah tersimpan di Google Drive).
        Setelah dibuat, backup otomatis diarsipkan juga ke Google Drive (folder "Backup Database" di akun
        storage aktif) supaya tetap bisa diunduh walau server di-deploy ulang.
    </div>

    <div class="card">
        <div class="sec"><span>Buat Backup Baru</span></div>
        <p style="color:var(--muted);font-size:13px;margin:0 0 12px">
            Proses ini membuat dump penuh database saat ini. Bisa memakan waktu beberapa saat tergantung
            ukuran data — jangan tutup halaman ini sampai selesai.
        </p>
        <div class="btn-row">
            <button type="button" class="btn btn-primary" wire:click="buat" wire:loading.attr="disabled" wire:target="buat">
                <span wire:loading.remove wire:target="buat">💾 Buat Backup Sekarang</span>
                <span wire:loading wire:target="buat"><i class="spin"></i> Membuat backup…</span>
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-h">🗂️ Riwayat Backup</div>
        <table>
            <thead>
                <tr>
                    <th>Nama Berkas</th>
                    <th>Ukuran</th>
                    <th>Dibuat</th>
                    <th>Status</th>
                    <th style="text-align:right">Tindakan</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($daftarBackup as $backup)
                    <tr wire:key="backup-{{ $backup->id }}">
                        <td>{{ $backup->nama_file }}</td>
                        <td class="muted">{{ $backup->ukuranHuman() }}</td>
                        <td class="muted">
                            {{ $backup->created_at->wita()->translatedFormat('d F Y, H:i') }} WITA
                            @if ($backup->dibuatOleh)
                                <div style="font-size:11px">oleh {{ $backup->dibuatOleh->nama }}</div>
                            @endif
                        </td>
                        <td>
                            @if ($backup->status === \App\Models\Backup::STATUS_BERHASIL)
                                <span class="badge b-approve">✅ Berhasil</span>
                                @if ($backup->drive_file_id)
                                    <div style="font-size:11px;color:var(--muted);margin-top:3px">☁️ Tersimpan di Drive</div>
                                @elseif (! $backup->adaSalinanLokal())
                                    <div style="font-size:11px;color:var(--red);margin-top:3px">⚠️ Salinan sudah tidak ada</div>
                                @else
                                    <div style="font-size:11px;color:var(--red);margin-top:3px" title="{{ $backup->catatan }}">⚠️ Belum tersalin ke Drive</div>
                                @endif
                            @else
                                <span class="badge b-tolak" title="{{ $backup->catatan }}">✕ Gagal</span>
                            @endif
                        </td>
                        <td style="text-align:right">
                            @if ($backup->status === \App\Models\Backup::STATUS_BERHASIL && ($backup->adaSalinanLokal() || $backup->drive_file_id))
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="unduh({{ $backup->id }})" wire:loading.attr="disabled" wire:target="unduh({{ $backup->id }})">⬇ Unduh</button>
                            @endif
                            <button type="button" class="btn btn-red btn-sm" style="margin-left:6px" wire:click="confirmHapus({{ $backup->id }})" wire:loading.attr="disabled" wire:target="confirmHapus({{ $backup->id }})">Hapus</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="color:var(--muted)">Belum ada backup. Buat backup pertama lewat tombol di atas.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-confirm-modal :show="$konfirmasiHapusId !== null" title="Hapus Riwayat Backup?" message="Hapus riwayat & salinan lokal backup ini? Salinan di Google Drive (bila ada) tidak ikut terhapus.">
        <button type="button" class="btn btn-ghost" wire:click="batalHapus" wire:loading.attr="disabled" wire:target="hapus">Batal</button>
        <button type="button" class="btn btn-red" wire:click="hapus({{ $konfirmasiHapusId }})" wire:loading.attr="disabled" wire:target="hapus">
            <span wire:loading.remove wire:target="hapus">Hapus</span>
            <span wire:loading wire:target="hapus"><i class="spin"></i> Menghapus…</span>
        </button>
    </x-confirm-modal>
</div>
