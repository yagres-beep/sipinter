<div>
    <div class="page-title">Struktur Folder Drive</div>
    <div class="page-sub">Atur pola folder otomatis di Google Drive tanpa mengubah kode (RF-14, RF-15).</div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <div class="info">ℹ️ Dua bagian: <b>Tingkat folder</b> (hierarki induk → subfolder, "Tahun" selalu di posisi pertama &amp; tidak dapat dinonaktifkan) dan <b>Folder kategori</b> (folder sejajar di dalam folder terdalam — Capaian &amp; Bukti-Dukung-SAKIP wajib ada).</div>

    <div class="grid grid-2" style="align-items:start">
        <div class="card">
            <div class="sec"><span>Tingkat Folder (hierarki)</span></div>

            @foreach ($hierarki as $i => $h)
                <div class="fl-row">
                    <span class="fl-num">{{ $i + 1 }}</span>
                    <label class="fl-name" style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" wire:click="toggleHierarki({{ $i }})" @checked($h['aktif']) @disabled($i === 0)>
                        {{ ucfirst($h['level']) }}
                        @if ($i === 0)
                            <span class="badge b-draft">terkunci</span>
                        @endif
                        @if ($h['custom'] ?? false)
                            <span class="fl-tag">kustom</span>
                        @endif
                    </label>
                    <span class="fl-move">
                        <button type="button" wire:click="naikkanHierarki({{ $i }})" @disabled($i <= 1)>↑</button>
                        <button type="button" wire:click="turunkanHierarki({{ $i }})" @disabled($i === 0 || $i === count($hierarki) - 1)>↓</button>
                        @if ($h['custom'] ?? false)
                            <button type="button" wire:click="hapusLevel({{ $i }})">✕</button>
                        @endif
                    </span>
                </div>
            @endforeach

            <div class="field" style="margin-top:10px;margin-bottom:0">
                <label style="font-size:11.5px">Tambah Tingkat Baru</label>
                <div style="display:flex;gap:8px">
                    <input type="text" class="inp filled" wire:model="levelBaru" placeholder="mis. Kategori Survei">
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="tambahLevel">＋ Tambah</button>
                </div>
                <div class="fhint">Tingkat kustom memakai nama tetap yang sama untuk semua periode/IKU (bukan nilai yang dihitung otomatis seperti Triwulan/Bulan/IKU).</div>
                @error('levelBaru')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
            </div>

            <div class="sec" style="margin-top:20px"><span>Folder Kategori (di folder terdalam)</span></div>
            <p style="color:var(--muted);font-size:12px;margin-bottom:10px">
                Kategori <b>Capaian</b> dan <b>Bukti-Dukung-SAKIP</b> wajib ada (RF-12) dan tidak bisa dihapus.
                Centang "subfolder per kegiatan" agar tiap kegiatan dapat folder sendiri di dalam kategori tsb (RF-13).
            </p>

            @error('kategori')
                <div style="color:var(--red);font-size:11.5px;margin-bottom:10px">{{ $message }}</div>
            @enderror

            @foreach ($kategori as $i => $k)
                <div class="fl-row" style="flex-wrap:wrap">
                    <span class="catchip" style="flex:1">
                        {{ $k['nama'] }}
                        @if ($k['wajib'])
                            <span class="lock">wajib</span>
                        @endif
                    </span>
                    <span class="fl-move">
                        <button type="button" wire:click="naikkanKategori({{ $i }})" @disabled($i === 0)>↑</button>
                        <button type="button" wire:click="turunkanKategori({{ $i }})" @disabled($i === count($kategori) - 1)>↓</button>
                        @unless ($k['wajib'])
                            <button type="button" wire:click="hapusKategori({{ $i }})">✕</button>
                        @endunless
                    </span>
                    <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--muted);width:100%;margin-top:6px">
                        <input type="checkbox" wire:click="toggleSubfolderKegiatan({{ $i }})" @checked($k['subfolder_per_kegiatan'])>
                        Subfolder per kegiatan
                    </label>
                </div>
            @endforeach

            <div class="field" style="margin-top:14px">
                <label>Tambah Kategori Baru</label>
                <div style="display:flex;gap:8px">
                    <input type="text" class="inp filled" wire:model="kategoriBaru" placeholder="mis. Dokumentasi Tambahan">
                    <button type="button" class="btn btn-teal btn-sm" wire:click="tambahKategori">＋ Tambah</button>
                </div>
                @error('kategoriBaru')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
            </div>

            <div class="btn-row">
                <button type="button" class="btn btn-primary" wire:click="simpan">💾 Simpan Pola Folder</button>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="sec"><span>Pratinjau Jalur Folder</span></div>
                <div class="foldertree">
                    @foreach ($preview as $baris)
                        <div class="ft-row {{ $baris['tipe'] === 'kategori' ? 'ft-cat' : '' }}" style="padding-left: {{ $baris['indent'] * 18 }}px">
                            📁 {{ $baris['teks'] }}
                        </div>
                    @endforeach
                </div>
                <div class="fhint" style="margin-top:12px">
                    Folder baru hanya benar-benar dibuat di Drive saat berkas pertama diunggah <em>(lazy creation)</em>.
                    Pratinjau ini hanya simulasi tampilan, belum menyentuh Drive.
                </div>
            </div>

            <div class="card">
                <div class="sec"><span>Buat Folder Tahun Lebih Awal</span></div>
                <div class="info">ℹ️ Siapkan folder tahun berikutnya di Drive storage aktif sebelum tahun berjalan berakhir (RF-16).</div>
                <div class="field">
                    <label>Tahun</label>
                    <input type="number" class="inp filled" wire:model="tahunBaru">
                    @error('tahunBaru')
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>
                <div class="btn-row">
                    <button type="button" class="btn btn-navy" wire:click="buatFolderTahun">＋ Buat Folder Tahun</button>
                </div>
            </div>
        </div>
    </div>
</div>
