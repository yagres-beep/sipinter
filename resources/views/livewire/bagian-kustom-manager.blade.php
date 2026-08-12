<div>
    <div class="page-title">Bagian Kustom</div>
    <div class="page-sub">Tambahkan bagian baru di Isian Kegiatan Ketua Tim (mis. Manajemen Risiko) tanpa perlu mengubah kode.</div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <div class="info">ℹ️ Bagian yang <b>aktif</b> otomatis muncul di Isian Kegiatan — diisi Ketua Tim per POIN. Atur <b>kapan wajib diisi</b> (selalu opsional / tiap bulan / hanya bulan terakhir triwulan) dan <b>apakah bukti dukung wajib</b> dilampirkan per poin. Data yang sudah tersimpan tetap tampil di notula meski bagiannya kemudian dinonaktifkan.</div>

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
        <div class="row2">
            <div class="field" style="margin-bottom:0">
                <label>Kapan wajib diisi</label>
                <select class="inp filled" wire:model="frekuensiWajibBaru">
                    <option value="opsional">Selalu opsional</option>
                    <option value="setiap_bulan">Wajib tiap bulan</option>
                    <option value="akhir_triwulan">Wajib hanya bulan terakhir triwulan</option>
                </select>
            </div>
            <div class="field" style="margin-bottom:0">
                <label>&nbsp;</label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;padding:10px 0">
                    <input type="checkbox" wire:model="buktiWajibBaru">
                    Bukti dukung (PDF) wajib per poin yang diisi
                </label>
            </div>
        </div>
        <div class="btn-row">
            <button type="button" class="btn btn-primary" wire:click="tambah">＋ Tambah Bagian</button>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="sec"><span>Daftar Bagian Kustom</span></div>

        @error('hapus')
            <div style="color:var(--red);font-size:11.5px;margin-bottom:10px">{{ $message }}</div>
        @enderror

        @forelse ($daftar as $i => $bagian)
            <div class="fl-row" style="flex-wrap:wrap;align-items:flex-start;padding:12px 0">
                <span class="fl-move" style="margin-top:2px">
                    <button type="button" wire:click="naikkan({{ $bagian->id }})" @disabled($i === 0)>↑</button>
                    <button type="button" wire:click="turunkan({{ $bagian->id }})" @disabled($i === $daftar->count() - 1)>↓</button>
                </span>

                <div style="flex:1;min-width:240px">
                    <input type="text" class="inp filled" wire:model="edit.{{ $bagian->id }}.nama" style="font-weight:700;margin-bottom:6px">
                    @error("edit.{$bagian->id}.nama")
                        <div style="color:var(--red);font-size:11.5px;margin-bottom:6px">{{ $message }}</div>
                    @enderror
                    <textarea class="inp filled" style="height:auto;display:block;font-size:12.5px" rows="1" wire:model="edit.{{ $bagian->id }}.deskripsi" placeholder="Deskripsi (opsional)"></textarea>

                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;align-items:center">
                        <span class="badge {{ $bagian->aktif ? 'b-approve' : 'b-draft' }}">{{ $bagian->aktif ? '✓ Aktif' : 'Nonaktif' }}</span>
                        <span class="badge {{ $bagian->frekuensi_wajib === 'opsional' ? 'b-draft' : 'b-ajukan' }}">{{ $bagian->labelFrekuensi() }}</span>
                        <span class="badge {{ $bagian->bukti_wajib ? 'b-verif' : 'b-draft' }}">{{ $bagian->bukti_wajib ? 'Bukti wajib' : 'Bukti opsional' }}</span>
                        <span class="badge b-draft">{{ $bagian->poin_count }} poin tersimpan</span>
                    </div>

                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px">
                        <select class="inp filled" style="width:auto;padding:6px 10px;font-size:11.5px" wire:change="setFrekuensi({{ $bagian->id }}, $event.target.value)">
                            <option value="opsional" @selected($bagian->frekuensi_wajib === 'opsional')>Selalu opsional</option>
                            <option value="setiap_bulan" @selected($bagian->frekuensi_wajib === 'setiap_bulan')>Wajib tiap bulan</option>
                            <option value="akhir_triwulan" @selected($bagian->frekuensi_wajib === 'akhir_triwulan')>Wajib akhir triwulan</option>
                        </select>
                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--muted);cursor:pointer">
                            <input type="checkbox" wire:click="toggleBuktiWajib({{ $bagian->id }})" @checked($bagian->bukti_wajib)>
                            Bukti wajib
                        </label>
                    </div>
                </div>

                <div class="btn-row" style="margin-top:0;flex-wrap:wrap">
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="simpanEdit({{ $bagian->id }})">💾 Simpan</button>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleAktif({{ $bagian->id }})">{{ $bagian->aktif ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="togglePratinjau({{ $bagian->id }})">{{ $pratinjauId === $bagian->id ? '✕ Tutup Pratinjau' : '👁 Pratinjau' }}</button>
                    @if ($bagian->poin_count === 0)
                        <button type="button" class="btn btn-red btn-sm" wire:click="hapus({{ $bagian->id }})" wire:confirm="Hapus bagian ini?">🗑 Hapus</button>
                    @endif
                </div>

                @if ($pratinjauId === $bagian->id)
                    <div style="width:100%;margin-top:14px;padding:16px;background:var(--bg);border:1px dashed var(--line);border-radius:12px">
                        <div style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px">👁 Pratinjau tampilan lintas peran (contoh, bukan data sungguhan)</div>

                        <div class="grid grid-3" style="align-items:start">
                            <div>
                                <div style="font-size:11px;font-weight:700;color:var(--blue-600);margin-bottom:6px">📝 KETUA TIM — Isian Kegiatan</div>
                                <div class="card" style="margin:0;padding:14px">
                                    <div class="sec" style="margin:0 0 8px"><span class="n">🧩</span><span>{{ $bagian->nama ?: 'Nama Bagian' }}</span></div>
                                    @if ($bagian->frekuensi_wajib === 'setiap_bulan')
                                        <div class="info warn" style="font-size:11px">⚠️ Minimal satu poin wajib diisi tiap bulan.</div>
                                    @elseif ($bagian->frekuensi_wajib === 'akhir_triwulan')
                                        <div class="info warn" style="font-size:11px">⚠️ Minimal satu poin wajib diisi di bulan terakhir triwulan.</div>
                                    @else
                                        <div class="info" style="font-size:11px">ℹ️ Bagian ini opsional.</div>
                                    @endif
                                    <div class="poin-single" style="margin-top:10px;position:relative">
                                        <span class="k-num stat-in">Poin 1</span>
                                        <div class="field" style="margin-bottom:8px">
                                            <label style="font-size:11px">Uraian</label>
                                            <div class="inp filled" style="color:var(--muted);font-style:italic">Contoh isian poin {{ $bagian->nama ?: 'bagian ini' }}…</div>
                                        </div>
                                        @if ($bagian->bukti_wajib)
                                            <div class="filechip ok" style="font-size:11px"><span class="nm">📄 contoh-bukti-dukung.pdf</span></div>
                                        @else
                                            <div class="fhint" style="margin:0">Bukti dukung opsional untuk bagian ini.</div>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div>
                                <div style="font-size:11px;font-weight:700;color:var(--teal);margin-bottom:6px">✅ TIM SAKIP — Verifikasi</div>
                                <div class="card" style="margin:0;padding:14px">
                                    <div class="sec" style="margin:0 0 8px"><span>{{ $bagian->nama ?: 'Nama Bagian' }}</span></div>
                                    <div class="keg" style="margin:0">
                                        <p style="font-size:12px;margin-bottom:8px">Contoh isian poin {{ $bagian->nama ?: 'bagian ini' }}…</p>
                                        @if ($bagian->bukti_wajib)
                                            <div class="filechip ok" style="font-size:11px"><span class="nm">📄 contoh-bukti-dukung.pdf</span></div>
                                        @else
                                            <div class="fhint" style="margin:0">Tanpa berkas — bukti dukung opsional.</div>
                                        @endif
                                    </div>
                                </div>
                                <div style="font-size:10.5px;color:var(--muted);margin-top:6px">Tampil di kartu verifikasi capaian, bersama tombol Sesuai/Tidak per berkas.</div>
                            </div>

                            <div>
                                <div style="font-size:11px;font-weight:700;color:var(--ink);margin-bottom:6px">📄 NOTULA — dibaca Tim SAKIP &amp; Kepala</div>
                                <div class="notula" style="margin:0;padding:20px;min-height:0;box-shadow:none;font-size:12px;border-radius:8px">
                                    <h3 style="font-size:13px">{{ $bagian->nama ?: 'Nama Bagian' }}</h3>
                                    <ul style="font-size:12px">
                                        <li>1131: Contoh isian poin {{ $bagian->nama ?: 'bagian ini' }}…</li>
                                    </ul>
                                </div>
                                <div style="font-size:10.5px;color:var(--muted);margin-top:6px">Muncul otomatis di sini begitu Ketua Tim mengisi minimal satu poin.</div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <p style="color:var(--muted);font-size:13px">Belum ada bagian kustom. Tambahkan lewat form di atas.</p>
        @endforelse
    </div>
</div>
