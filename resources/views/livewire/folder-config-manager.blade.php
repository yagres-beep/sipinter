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

<div class="card" style="margin-top:16px">
    <div class="sec"><span>Pola Khusus per IKU</span></div>
    <div class="info">ℹ️ Sebagian besar IKU cukup memakai pola folder global di atas. Bila ada IKU tertentu yang butuh susunan folder berbeda (mis. tambahan tingkat "Bulan" di dalam kategori tertentu), pilih IKU-nya di sini lalu atur pola sendiri untuk IKU itu saja — IKU lain tidak terpengaruh.</div>

    <div class="field">
        <label>Pilih IKU</label>
        <select class="inp filled" wire:change="pilihIku($event.target.value)">
            <option value="">— Pilih IKU —</option>
            @foreach ($ikuList as $iku)
                <option value="{{ $iku->id }}" @selected($ikuTerpilih === $iku->id)>
                    {{ $iku->kode }} — {{ $iku->indikator }}
                    @if (in_array($iku->id, $ikuDenganOverride, true)) (pola khusus) @endif
                </option>
            @endforeach
        </select>
    </div>

    @if ($ikuTerpilih)
        <div class="badge {{ $ikuPunyaOverride ? 'b-verif' : 'b-draft' }}" style="display:inline-block;margin-bottom:14px">
            {{ $ikuPunyaOverride ? '✓ IKU ini memakai pola khusus' : 'Belum disimpan — masih salinan pola global' }}
        </div>

        <div class="grid grid-2" style="align-items:start">
            <div>
                <div class="sec" style="margin-top:0"><span>Tingkat Folder (khusus IKU ini)</span></div>

                @foreach ($hierarkiIku as $i => $h)
                    <div class="fl-row">
                        <span class="fl-num">{{ $i + 1 }}</span>
                        <label class="fl-name" style="display:flex;align-items:center;gap:8px;cursor:pointer">
                            <input type="checkbox" wire:click="toggleHierarkiIku({{ $i }})" @checked($h['aktif']) @disabled($i === 0)>
                            {{ ucfirst($h['level']) }}
                            @if ($i === 0)
                                <span class="badge b-draft">terkunci</span>
                            @endif
                            @if ($h['custom'] ?? false)
                                <span class="fl-tag">kustom</span>
                            @endif
                        </label>
                        <span class="fl-move">
                            <button type="button" wire:click="naikkanHierarkiIku({{ $i }})" @disabled($i <= 1)>↑</button>
                            <button type="button" wire:click="turunkanHierarkiIku({{ $i }})" @disabled($i === 0 || $i === count($hierarkiIku) - 1)>↓</button>
                            @if ($h['custom'] ?? false)
                                <button type="button" wire:click="hapusLevelIku({{ $i }})">✕</button>
                            @endif
                        </span>
                    </div>
                @endforeach

                <div class="field" style="margin-top:10px;margin-bottom:0">
                    <label style="font-size:11.5px">Tambah Tingkat Baru</label>
                    <div style="display:flex;gap:8px">
                        <input type="text" class="inp filled" wire:model="levelBaruIku" placeholder="mis. Bulan">
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="tambahLevelIku">＋ Tambah</button>
                    </div>
                    @error('levelBaruIku')
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>

                <div class="sec" style="margin-top:20px"><span>Folder Kategori (khusus IKU ini)</span></div>

                @error('kategoriIku')
                    <div style="color:var(--red);font-size:11.5px;margin-bottom:10px">{{ $message }}</div>
                @enderror

                @foreach ($kategoriIku as $i => $k)
                    <div class="fl-row" style="flex-wrap:wrap">
                        <span class="catchip" style="flex:1">
                            {{ $k['nama'] }}
                            @if ($k['wajib'])
                                <span class="lock">wajib</span>
                            @endif
                        </span>
                        <span class="fl-move">
                            <button type="button" wire:click="naikkanKategoriIku({{ $i }})" @disabled($i === 0)>↑</button>
                            <button type="button" wire:click="turunkanKategoriIku({{ $i }})" @disabled($i === count($kategoriIku) - 1)>↓</button>
                            @unless ($k['wajib'])
                                <button type="button" wire:click="hapusKategoriIku({{ $i }})">✕</button>
                            @endunless
                        </span>
                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--muted);width:100%;margin-top:6px">
                            <input type="checkbox" wire:click="toggleSubfolderKegiatanIku({{ $i }})" @checked($k['subfolder_per_kegiatan'])>
                            Subfolder per kegiatan
                        </label>
                    </div>
                @endforeach

                <div class="field" style="margin-top:14px">
                    <label>Tambah Kategori Baru</label>
                    <div style="display:flex;gap:8px">
                        <input type="text" class="inp filled" wire:model="kategoriBaruIku" placeholder="mis. Dokumentasi Tambahan">
                        <button type="button" class="btn btn-teal btn-sm" wire:click="tambahKategoriIku">＋ Tambah</button>
                    </div>
                    @error('kategoriBaruIku')
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>

                <div class="btn-row">
                    <button type="button" class="btn btn-primary" wire:click="simpanPolaIku">💾 Simpan Pola IKU Ini</button>
                    @if ($ikuPunyaOverride)
                        <button type="button" class="btn btn-red" wire:click="hapusOverrideIku">🗑 Hapus Pola Khusus (kembali ke global)</button>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="sec"><span>Pratinjau Jalur Folder — IKU Ini</span></div>
                <div class="foldertree">
                    @foreach ($previewIku as $baris)
                        <div class="ft-row {{ $baris['tipe'] === 'kategori' ? 'ft-cat' : '' }}" style="padding-left: {{ $baris['indent'] * 18 }}px">
                            📁 {{ $baris['teks'] }}
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>

<div class="card" style="margin-top:16px">
    <div class="sec"><span>Tambah Folder Manual</span></div>
    <div class="info">ℹ️ Untuk kebutuhan khusus di tengah tahun berjalan — buat satu folder tambahan langsung di lokasi Drive yang dipilih, tanpa mengubah pola folder otomatis untuk unggahan berikutnya. Kosongkan tingkat yang tidak relevan.</div>

    <div class="grid grid-2">
        <div class="field">
            <label>Tahun <span class="req">*</span></label>
            <input type="number" class="inp filled" wire:model="manualTahun">
            @error('manualTahun')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>
        <div class="field">
            <label>Triwulan (opsional)</label>
            <select class="inp filled" wire:model="manualTriwulan">
                <option value="">— Tidak dipakai —</option>
                <option value="1">Triwulan I</option>
                <option value="2">Triwulan II</option>
                <option value="3">Triwulan III</option>
                <option value="4">Triwulan IV</option>
            </select>
        </div>
        <div class="field">
            <label>Bulan (opsional)</label>
            <select class="inp filled" wire:model="manualBulan">
                <option value="">— Tidak dipakai —</option>
                @foreach (['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'] as $idx => $namaBulan)
                    <option value="{{ $idx + 1 }}">{{ $namaBulan }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label>IKU (opsional)</label>
            <select class="inp filled" wire:model="manualIkuId">
                <option value="">— Tidak dipakai —</option>
                @foreach ($ikuList as $iku)
                    <option value="{{ $iku->id }}">{{ $iku->kode }} — {{ $iku->indikator }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label>Folder Kategori Tujuan (opsional)</label>
            <input type="text" class="inp filled" wire:model="manualKategoriNama" placeholder="mis. Capaian">
            <div class="fhint">Kosongkan bila folder baru langsung di tingkat di atasnya, tanpa masuk ke folder kategori tertentu.</div>
        </div>
        <div class="field">
            <label>Nama Folder Baru <span class="req">*</span></label>
            <input type="text" class="inp filled" wire:model="manualNamaFolder" placeholder="mis. Dokumen Tambahan 2026">
            @error('manualNamaFolder')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="btn-row">
        <button type="button" class="btn btn-navy" wire:click="buatFolderManual">📁 Buat Folder</button>
    </div>
</div>
</div>
