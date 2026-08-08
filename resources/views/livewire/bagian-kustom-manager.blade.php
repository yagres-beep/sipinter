<div>
    <div class="page-title">Bagian Kustom</div>
    <div class="page-sub">Tambahkan bagian baru di Isian Kegiatan Ketua Tim (mis. Manajemen Risiko) tanpa perlu mengubah kode.</div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <div class="info">ℹ️ Bagian yang <b>aktif</b> otomatis muncul di Isian Kegiatan — diisi Ketua Tim per POIN, dan tiap poin WAJIB dilampiri bukti dukung (PDF). Aktifkan "Wajib di akhir triwulan" agar minimal satu poin wajib diisi sebelum isian diajukan pada bulan terakhir tiap triwulan; biarkan nonaktif supaya bagian ini selalu opsional. Data yang sudah tersimpan tetap tampil di notula meski bagiannya kemudian dinonaktifkan.</div>

    <div class="card">
        <div class="sec"><span>Tambah Bagian Baru</span></div>
        <div class="field">
            <label>Nama Bagian <span class="req">*</span></label>
            <input type="text" class="inp filled" wire:model="namaBaru" placeholder="mis. Manajemen Risiko">
            @error('namaBaru')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>
        <div class="field">
            <label>Deskripsi (opsional)</label>
            <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model="deskripsiBaru" placeholder="Petunjuk singkat untuk Ketua Tim saat mengisi bagian ini"></textarea>
        </div>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;margin-bottom:14px">
            <input type="checkbox" wire:model="wajibAkhirTriwulanBaru">
            Wajib diisi minimal satu poin di bulan terakhir tiap triwulan
        </label>
        <div class="btn-row">
            <button type="button" class="btn btn-primary" wire:click="tambah">＋ Tambah Bagian</button>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="sec"><span>Daftar Bagian Kustom</span></div>

        @error('hapus')
            <div style="color:var(--red);font-size:11.5px;margin-bottom:10px">{{ $message }}</div>
        @enderror

        @forelse ($daftar as $bagian)
            <div class="fl-row" style="flex-wrap:wrap;align-items:flex-start;padding:12px 0">
                <div style="flex:1;min-width:240px">
                    <input type="text" class="inp filled" wire:model="edit.{{ $bagian->id }}.nama" style="font-weight:700;margin-bottom:6px">
                    @error("edit.{$bagian->id}.nama")
                        <div style="color:var(--red);font-size:11.5px;margin-bottom:6px">{{ $message }}</div>
                    @enderror
                    <textarea class="inp filled" style="height:auto;display:block;font-size:12.5px" rows="1" wire:model="edit.{{ $bagian->id }}.deskripsi" placeholder="Deskripsi (opsional)"></textarea>

                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
                        <span class="badge {{ $bagian->aktif ? 'b-approve' : 'b-draft' }}">{{ $bagian->aktif ? '✓ Aktif' : 'Nonaktif' }}</span>
                        <span class="badge {{ $bagian->wajib_akhir_triwulan ? 'b-ajukan' : 'b-draft' }}">{{ $bagian->wajib_akhir_triwulan ? 'Wajib akhir triwulan' : 'Selalu opsional' }}</span>
                        <span class="badge b-draft">{{ $bagian->poin_count }} poin tersimpan</span>
                    </div>
                </div>

                <div class="btn-row" style="margin-top:0;flex-wrap:wrap">
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="simpanEdit({{ $bagian->id }})">💾 Simpan</button>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleAktif({{ $bagian->id }})">{{ $bagian->aktif ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleWajib({{ $bagian->id }})">{{ $bagian->wajib_akhir_triwulan ? 'Jadikan Opsional' : 'Wajibkan Akhir Triwulan' }}</button>
                    @if ($bagian->poin_count === 0)
                        <button type="button" class="btn btn-red btn-sm" wire:click="hapus({{ $bagian->id }})" wire:confirm="Hapus bagian ini?">🗑 Hapus</button>
                    @endif
                </div>
            </div>
        @empty
            <p style="color:var(--muted);font-size:13px">Belum ada bagian kustom. Tambahkan lewat form di atas.</p>
        @endforelse
    </div>
</div>
