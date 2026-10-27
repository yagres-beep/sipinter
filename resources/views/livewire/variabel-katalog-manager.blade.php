<div>
    <div class="page-head">
        <div class="page-title">Katalog Variabel</div>
        <div class="page-sub">Definisi bersama (jenis, satuan, format angka, pilihan, batas) yang bisa dipakai ulang oleh kolom Bagian Kustom mana pun. Label kolom tetap boleh berbeda per bagian; nilai tetap tersimpan di isian masing-masing bagian.</div>
    </div>

    @if (session('status-variabel'))
        <div class="badge b-approve" role="status" style="display:block;margin-bottom:14px;white-space:normal">{{ session('status-variabel') }}</div>
    @endif

    <div class="info" style="font-size:12px">ℹ️ Kode variabel adalah identitas tetap dan tidak bisa diubah. Mengubah nama tidak memutus tautan. Mengganti jenis atau membuang pilihan ditolak bila ada nilai tersimpan yang tidak cocok — nilai lama tidak pernah dikonversi otomatis. Variabel tidak dihapus, cukup dinonaktifkan.</div>

    <div class="card">
        <div class="sec"><span>Daftar Variabel</span><span style="margin-left:auto;font-size:12px;color:var(--muted);font-weight:500">{{ $daftar->count() }} variabel</span></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin-bottom:12px">
            <div class="field" style="margin-bottom:0;grid-column:span 2;min-width:0">
                <label for="cari-variabel">Cari</label>
                <input id="cari-variabel" type="search" class="inp filled" wire:model.live.debounce.300ms="cari" placeholder="Kode, nama, atau keterangan">
            </div>
            <div class="field" style="margin-bottom:0">
                <label for="status-variabel">Status</label>
                <select id="status-variabel" class="inp filled" wire:model.live="filterStatus">
                    <option value="semua">Semua</option>
                    <option value="aktif">Aktif</option>
                    <option value="nonaktif">Nonaktif</option>
                </select>
            </div>
            <div style="align-self:end">
                <button type="button" class="btn btn-primary btn-sm" wire:click="baru" wire:loading.attr="disabled" wire:target="baru">＋ Variabel baru</button>
            </div>
        </div>

        @if ($editId !== null)
            <div style="padding:14px;border:1px solid var(--line);border-radius:12px;background:var(--bg);margin-bottom:14px">
                <div style="font-weight:700;margin-bottom:10px">{{ $editId === 0 ? 'Variabel baru' : 'Ubah variabel "'.($form['nama'] ?? '').'"' }}</div>
                <div class="row2">
                    <div class="field">
                        <label for="v-kode">Kode <span class="req">*</span></label>
                        @if ($editId === 0)
                            <input id="v-kode" type="text" class="inp filled" style="font-family:monospace" wire:model.blur="form.kode" placeholder="mis. persen_tindak_lanjut">
                        @else
                            <div class="inp filled" style="font-family:monospace;color:var(--muted)">{{ $form['kode'] }} <span style="font-size:11px">(tetap)</span></div>
                        @endif
                        @error('form.kode')<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="v-nama">Nama tampilan <span class="req">*</span></label>
                        <input id="v-nama" type="text" class="inp filled" wire:model.blur="form.nama" placeholder="mis. Persentase tindak lanjut">
                        @error('form.nama')<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="row2">
                    <div class="field">
                        <label for="v-tipe">Jenis data</label>
                        <select id="v-tipe" class="inp filled" wire:model.live="form.tipe">
                            @foreach ($tipeKolom as $nilai => $label)
                                <option value="{{ $nilai }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('form.tipe')<div role="alert" style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label>Sumber data</label>
                        <div class="inp filled" style="color:var(--muted)">{{ \App\Models\VariabelKatalog::SUMBER['isian'] }}</div>
                    </div>
                </div>

                @if (($form['tipe'] ?? '') === 'angka')
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-bottom:8px">
                        <div class="field" style="margin-bottom:0">
                            <label for="v-satuan">Satuan</label>
                            <input id="v-satuan" type="text" class="inp filled" wire:model.live.debounce.300ms="form.satuan" placeholder="mis. %, orang">
                        </div>
                        <div class="field" style="margin-bottom:0">
                            <label for="v-desimal">Angka di belakang koma</label>
                            <select id="v-desimal" class="inp filled" wire:model.live="form.desimal">
                                <option value="">Tampil apa adanya</option>
                                @for ($d = 0; $d <= \App\Support\FormatAngkaKolom::DESIMAL_MAKS; $d++)
                                    <option value="{{ $d }}">{{ $d }} desimal</option>
                                @endfor
                            </select>
                        </div>
                        <div class="field" style="margin-bottom:0">
                            <label for="v-pemisah">Pemisah desimal</label>
                            <select id="v-pemisah" class="inp filled" wire:model.live="form.pemisah">
                                @foreach ($pilihanPemisah as $nilai => $label)
                                    <option value="{{ $nilai }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;align-self:end;padding-bottom:10px">
                            <input type="checkbox" wire:model.live="form.ribuan"> Pemisah ribuan
                        </label>
                        <div class="field" style="margin-bottom:0">
                            <label for="v-min">Nilai minimal</label>
                            <input id="v-min" type="text" inputmode="decimal" class="inp filled" wire:model.blur="form.min" placeholder="kosong = bebas">
                            @error('form.min')<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                        </div>
                        <div class="field" style="margin-bottom:0">
                            <label for="v-maks">Nilai maksimal</label>
                            <input id="v-maks" type="text" inputmode="decimal" class="inp filled" wire:model.blur="form.maks" placeholder="kosong = bebas">
                            @error('form.maks')<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    @php
                        $contoh = \App\Models\BagianKustom::normalkanKolom(['kunci' => 'c', 'label' => 'c', 'tipe' => 'angka', 'satuan' => $form['satuan'] ?? null, 'desimal' => $form['desimal'] ?? null, 'pemisah' => $form['pemisah'] ?? null, 'ribuan' => $form['ribuan'] ?? true]);
                    @endphp
                    <div class="fhint" style="margin:0 0 8px">Contoh: nilai tersimpan 62.5 tampil sebagai <b>{{ \App\Support\FormatAngkaKolom::tampil('62.5', $contoh) }}</b>; 1234567.891 tampil <b>{{ \App\Support\FormatAngkaKolom::tampil('1234567.891', $contoh) }}</b>. Format hanya mengubah tampilan, nilai tersimpan tidak dibulatkan.</div>
                @endif

                @if (in_array($form['tipe'] ?? '', ['pilihan', 'pilihan_ganda'], true))
                    <div class="field">
                        <label for="v-opsi">Pilihan (pisahkan dengan koma)</label>
                        <input id="v-opsi" type="text" class="inp filled" wire:model.blur="form.opsi_teks" placeholder="mis. Rendah, Sedang, Tinggi">
                        @error('form.opsi_teks')<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                    </div>
                @endif

                @if (in_array($form['tipe'] ?? '', ['teks', 'teks_panjang'], true))
                    <div class="row2">
                        <div class="field">
                            <label for="v-pmin">Panjang minimal (karakter)</label>
                            <input id="v-pmin" type="number" min="0" max="5000" class="inp filled" wire:model.blur="form.panjang_min">
                        </div>
                        <div class="field">
                            <label for="v-pmaks">Panjang maksimal (karakter)</label>
                            <input id="v-pmaks" type="number" min="1" max="5000" class="inp filled" wire:model.blur="form.panjang_maks">
                        </div>
                    </div>
                @endif

                <div class="field">
                    <label for="v-ket">Keterangan <span style="color:var(--muted);font-weight:500">(opsional)</span></label>
                    <textarea id="v-ket" class="inp filled" style="height:auto;display:block" rows="2" wire:model.blur="form.keterangan"></textarea>
                </div>

                <div class="btn-row">
                    <button type="button" class="btn btn-primary btn-sm" wire:click="simpan" wire:loading.attr="disabled" wire:target="simpan">
                        <span wire:loading.remove wire:target="simpan">💾 Simpan</span>
                        <span wire:loading wire:target="simpan"><i class="spin"></i> Menyimpan…</span>
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="tutup">Batal</button>
                </div>
            </div>
        @endif

        @forelse ($daftar as $v)
            <div style="border-top:1px solid var(--line2);padding:10px 0" wire:key="variabel-{{ $v->id }}">
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;justify-content:space-between">
                    <div style="min-width:220px;flex:1">
                        <div style="font-weight:700;font-size:13.5px">🔣 {{ $v->nama }} <code style="font-size:11px;font-weight:500;color:var(--muted)">{{ $v->kode }}</code></div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px">
                            <span class="badge {{ $v->aktif ? 'b-approve' : 'b-draft' }}">{{ $v->aktif ? '✓ Aktif' : 'Nonaktif' }}</span>
                            <span class="badge b-verif">{{ $tipeKolom[$v->tipe] ?? $v->tipe }}</span>
                            @if ($v->ringkasan() !== ($tipeKolom[$v->tipe] ?? $v->tipe))<span class="badge b-draft">{{ $v->ringkasan() }}</span>@endif
                            @if ($v->opsi)<span class="badge b-draft">{{ implode(' / ', $v->opsi) }}</span>@endif
                            <span class="badge b-draft">dipakai {{ count($pemakaian[$v->kode] ?? []) }} kolom</span>
                        </div>
                        @if ($v->keterangan)<div style="font-size:12px;color:var(--muted);margin-top:4px">{{ $v->keterangan }}</div>@endif
                    </div>
                    <div class="btn-row" style="margin-top:0;flex-wrap:wrap">
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="ubah({{ $v->id }})">✎ Ubah</button>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="lihatPemakaian({{ $v->id }})">👁 Pemakaian</button>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleAktif({{ $v->id }})" @if ($v->aktif) wire:confirm="Nonaktifkan variabel &quot;{{ $v->nama }}&quot;? Kolom yang sudah memakainya tetap berfungsi dan datanya tetap ada; variabel ini tidak bisa dipilih untuk kolom baru." @endif>{{ $v->aktif ? 'Nonaktifkan' : 'Aktifkan kembali' }}</button>
                    </div>
                </div>
                @if ($pemakaianId === $v->id)
                    <ul style="margin:8px 0 0;padding-left:18px;font-size:12.5px">
                        @forelse ($pemakaian[$v->kode] ?? [] as $p)
                            <li>Bagian "{{ $p['bagian'] }}" → kolom "{{ $p['kolom'] }}"{{ $p['aktif'] ? '' : ' (nonaktif)' }}</li>
                        @empty
                            <li style="color:var(--muted)">Belum dipakai kolom mana pun.</li>
                        @endforelse
                    </ul>
                @endif
            </div>
        @empty
            <p style="color:var(--muted);font-size:13px">Belum ada variabel. Tambahkan lewat tombol "＋ Variabel baru", lalu tautkan di Bagian Kustom → Struktur Isian → kolom → "Variabel katalog".</p>
        @endforelse
    </div>
</div>
