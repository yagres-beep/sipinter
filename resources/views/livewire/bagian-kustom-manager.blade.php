<div>
    <div class="page-head">
        <div class="page-title">Bagian Kustom</div>
        <div class="page-sub">Atur bagian tambahan (mis. Manajemen Risiko) langsung dari sini — siapa yang mengisi, kolom isian, dan tampilannya di notula. Tidak perlu mengubah kode.</div>
    </div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <div class="info">ℹ️ Bagian yang <b>aktif</b> muncul di menu peran pengisinya (Ketua Tim di Isian Kegiatan; Tim SAKIP/Kepala di 🧩 Isian Bagian Kustom). Data yang sudah tersimpan tetap tampil di notula meski bagiannya kemudian dinonaktifkan.</div>

    <div class="card" id="form-tambah-bagian" style="scroll-margin-top:90px">
        <div class="sec"><span>Tambah Bagian Baru</span></div>
        <div class="row2">
            <div class="field">
                <label>Nama Bagian <span class="req">*</span></label>
                <input type="text" class="inp filled" wire:model="namaBaru" placeholder="mis. Manajemen Risiko">
                @error('namaBaru')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
            </div>
            <div class="field">
                <label>Mulai dari</label>
                <select class="inp filled" wire:model.live="sumberSalinId">
                    <option value="">Pengaturan kosong</option>
                    @foreach ($daftar as $sumber)
                        <option value="{{ $sumber->id }}">Salin pengaturan "{{ $sumber->nama }}"</option>
                    @endforeach
                </select>
                @error('sumberSalinId')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
            </div>
        </div>
        @if ($ringkasanSalin)
            <div class="info" style="font-size:12px">
                <b>Yang akan disalin dari "{{ $ringkasanSalin['nama'] }}"</b> (bisa diubah setelah dibuat):
                <ul style="margin:6px 0 0;padding-left:18px;line-height:1.6">
                    <li>Peran, menu, dan kewajiban isi — sudah diisikan ke form di bawah.</li>
                    <li>Label uraian: {{ $ringkasanSalin['label_uraian'] }}.</li>
                    <li>Kolom isian ({{ count($ringkasanSalin['kolom']) }}):
                        @forelse ($ringkasanSalin['kolom'] as $k)
                            {{ $k['label'] }} <span style="color:var(--muted)">({{ $tipeKolom[$k['tipe']] ?? $k['tipe'] }}{{ \App\Models\BagianKustom::ringkasanKolom($k) !== '' ? ', '.\App\Models\BagianKustom::ringkasanKolom($k) : '' }})</span>@if (! $loop->last), @endif
                        @empty
                            tidak ada
                        @endforelse
                        @if ($ringkasanSalin['kolom_nonaktif'] > 0)
                            <span style="color:var(--muted)">— {{ $ringkasanSalin['kolom_nonaktif'] }} kolom nonaktif tidak disalin</span>
                        @endif
                    </li>
                    <li>Subbagian ({{ count($ringkasanSalin['subbagian']) }}): {{ implode(', ', $ringkasanSalin['subbagian']) ?: 'tidak ada' }}.</li>
                    <li>Notula: {{ $ringkasanSalin['notula'] }} (judul notula memakai nama bagian baru).</li>
                    <li>Bukti dukung: {{ $ringkasanSalin['aturan_bukti'] }}.</li>
                </ul>
                <div style="margin-top:6px"><b>Tidak disalin:</b> {{ $ringkasanSalin['poin_sumber'] }} poin isian, bukti dukung, status verifikasi, dan riwayat milik "{{ $ringkasanSalin['nama'] }}". Bagian baru mulai tanpa data.</div>
            </div>
        @endif
        <div class="row2">
            <div class="field">
                <label>Deskripsi (opsional)</label>
                <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model="deskripsiBaru" placeholder="Petunjuk singkat untuk pengisi bagian ini"></textarea>
            </div>
            <div class="field">
                <label>Kode internal <span style="color:var(--muted);font-weight:500">(opsional)</span></label>
                <input type="text" class="inp filled" style="font-family:monospace" wire:model="kodeBaru" placeholder="kosong = dibuat dari nama, mis. manajemen_risiko">
                @error('kodeBaru')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
                <div class="fhint" style="margin-top:4px">Identitas tetap bagian (dipakai variabel notula). Tidak berubah walau nama bagian diganti, dan tidak bisa diubah setelah dibuat.</div>
            </div>
        </div>
        @include('livewire.partials._peran-penempatan', ['modelPeran' => 'peranBaru', 'modelMenu' => 'penempatanBaru', 'peranDipilih' => $peranBaru, 'namaPeran' => $namaPeran, 'katalogMenu' => $katalogMenu])
        @error('penempatanBaru')
            <div style="color:var(--red);font-size:11.5px;margin-bottom:8px">{{ $message }}</div>
        @enderror
        <div class="field">
            <label>Kapan wajib diisi</label>
            <select class="inp filled" wire:model="frekuensiWajibBaru">
                <option value="opsional">Selalu opsional</option>
                <option value="setiap_bulan">Wajib tiap bulan</option>
                <option value="akhir_triwulan">Wajib hanya bulan terakhir triwulan</option>
            </select>
        </div>
        <div class="btn-row">
            <button type="button" class="btn btn-primary" wire:click="tambah" wire:loading.attr="disabled" wire:target="tambah">
                <span wire:loading.remove wire:target="tambah">＋ Tambah Bagian</span>
                <span wire:loading wire:target="tambah"><i class="spin"></i> Menambahkan…</span>
            </button>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="sec"><span>Daftar Bagian Kustom</span><span style="margin-left:auto;font-size:12px;color:var(--muted);font-weight:500">{{ $daftarTampil->count() }} dari {{ $daftar->count() }} bagian</span></div>

        {{-- Pencarian, filter, urutan --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-bottom:12px">
            <div class="field" style="margin-bottom:0;grid-column:span 2;min-width:0">
                <label for="cari-bagian">Cari</label>
                <input id="cari-bagian" type="search" class="inp filled" wire:model.live.debounce.300ms="cari" placeholder="Nama, kode, atau deskripsi">
            </div>
            <div class="field" style="margin-bottom:0">
                <label for="filter-status">Status</label>
                <select id="filter-status" class="inp filled" wire:model.live="filterStatus">
                    <option value="semua">Semua</option>
                    <option value="aktif">Aktif</option>
                    <option value="nonaktif">Nonaktif</option>
                </select>
            </div>
            <div class="field" style="margin-bottom:0">
                <label for="filter-peran">Peran</label>
                <select id="filter-peran" class="inp filled" wire:model.live="filterPeran">
                    <option value="">Semua peran</option>
                    @foreach ($namaPeran as $kunci => $label)
                        <option value="{{ $kunci }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="margin-bottom:0">
                <label for="filter-menu">Penempatan</label>
                <select id="filter-menu" class="inp filled" wire:model.live="filterMenu">
                    <option value="">Semua menu</option>
                    @foreach ($katalogMenu as $kunci => $menu)
                        <option value="{{ $kunci }}">{{ $menu['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="margin-bottom:0">
                <label for="urut-bagian">Urutkan</label>
                <select id="urut-bagian" class="inp filled" wire:model.live="urut">
                    <option value="urutan">Urutan tampil</option>
                    <option value="nama">Nama (A–Z)</option>
                    <option value="diubah">Terakhir diperbarui</option>
                </select>
            </div>
        </div>
        @if ($filterAktif)
            <div class="fhint" style="margin:-4px 0 10px">Tombol ↑↓ untuk mengatur urutan tampil hanya tersedia tanpa pencarian/filter dan dengan urutan "Urutan tampil". <button type="button" class="btn btn-ghost btn-sm" wire:click="resetFilter">Hapus filter</button></div>
        @endif

        @error('hapus')
            <div style="color:var(--red);font-size:11.5px;margin-bottom:10px">{{ $message }}</div>
        @enderror

        @forelse ($daftarTampil as $i => $bagian)
            @php
                $f = $form[$bagian->id] ?? null;
                $semuaKolom = $bagian->kolomList();
                $kolomAktif = count($bagian->kolomBerlaku(null, null));
            @endphp
            <div style="border-top:1px solid var(--line2);padding:12px 0" wire:key="daftar-bagian-{{ $bagian->id }}">
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start">
                    @unless ($filterAktif)
                        <span class="fl-move" style="margin-top:2px">
                            <button type="button" wire:click="naikkan({{ $bagian->id }})" wire:loading.attr="disabled" wire:target="naikkan({{ $bagian->id }})" @disabled($i === 0) aria-label="Naikkan {{ $bagian->nama }}">↑</button>
                            <button type="button" wire:click="turunkan({{ $bagian->id }})" wire:loading.attr="disabled" wire:target="turunkan({{ $bagian->id }})" @disabled($i === $daftar->count() - 1) aria-label="Turunkan {{ $bagian->nama }}">↓</button>
                        </span>
                    @endunless

                    <div style="flex:1;min-width:240px">
                        <div style="font-weight:700;font-size:14px">🧩 {{ $bagian->nama }} <code style="font-size:11px;font-weight:500;color:var(--muted)">{{ $bagian->kode }}</code></div>
                        @if ($bagian->deskripsi)
                            <div style="font-size:12px;color:var(--muted);margin-top:2px">{{ \Illuminate\Support\Str::limit($bagian->deskripsi, 140) }}</div>
                        @endif
                        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">
                            <span class="badge {{ $bagian->aktif ? 'b-approve' : 'b-draft' }}">{{ $bagian->aktif ? '✓ Aktif' : 'Nonaktif' }}</span>
                            <span class="badge b-verif" title="Peran yang dapat melihat dan mengisi">Peran: {{ $bagian->labelPeran() }}</span>
                            <span class="badge b-verif">Menu: {{ $bagian->labelPenempatan() }}</span>
                            <span class="badge {{ $bagian->frekuensi_wajib === 'opsional' ? 'b-draft' : 'b-ajukan' }}">Periode: {{ $bagian->labelFrekuensi() }}</span>
                            <span class="badge {{ $bagian->masuk_notula ? 'b-approve' : 'b-draft' }}">{{ $bagian->masuk_notula ? '📄 Masuk notula' : 'Tidak masuk notula' }}</span>
                            <span class="badge b-draft">{{ count($bagian->subbagianList()) }} subbagian</span>
                            <span class="badge b-draft">{{ $kolomAktif }} kolom{{ count($semuaKolom) > $kolomAktif ? ' (+'.(count($semuaKolom) - $kolomAktif).' nonaktif)' : '' }}</span>
                            <span class="badge b-draft">{{ $bagian->poin_count }} poin tersimpan</span>
                        </div>
                        <div style="font-size:11px;color:var(--faint);margin-top:4px">Diperbarui {{ $bagian->updated_at?->wita()->format('d/m/Y H:i') }} WITA</div>
                    </div>

                    <div class="btn-row" style="margin-top:0;flex-wrap:wrap">
                        <button type="button" class="btn {{ $bukaId === $bagian->id ? 'btn-primary' : 'btn-ghost' }} btn-sm" wire:click="buka({{ $bagian->id }})" wire:loading.attr="disabled" wire:target="buka({{ $bagian->id }})"
                            @if ($bukaId === $bagian->id && $belumDisimpan) wire:confirm="Ada perubahan pengaturan yang belum disimpan. Tutup editor? Perubahan tetap ada di formulir sampai halaman dimuat ulang, tetapi belum tersimpan." @endif>
                            {{ $bukaId === $bagian->id ? '✕ Tutup' : '✎ Edit' }}
                        </button>
                        <button type="button" class="btn {{ $detailId === $bagian->id ? 'btn-primary' : 'btn-ghost' }} btn-sm" wire:click="lihatDetail({{ $bagian->id }})" wire:loading.attr="disabled" wire:target="lihatDetail({{ $bagian->id }})">👁 Detail</button>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="duplikasi({{ $bagian->id }})" x-on:click="$nextTick(() => document.getElementById('form-tambah-bagian')?.scrollIntoView({ behavior: 'smooth' }))" wire:loading.attr="disabled" wire:target="duplikasi({{ $bagian->id }})" title="Isi form Tambah dengan pengaturan bagian ini — tanpa isian, bukti, maupun riwayatnya">⧉ Duplikasi</button>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleAktif({{ $bagian->id }}, {{ $bagian->aktif ? 'false' : 'true' }})" wire:loading.attr="disabled" wire:loading.class="btn-busy" wire:target="toggleAktif" @if ($bagian->aktif) wire:confirm="Nonaktifkan bagian &quot;{{ $bagian->nama }}&quot;? Bagian tidak lagi tampil untuk diisi. Isian, bukti, dan riwayat yang sudah ada TIDAK dihapus dan tetap masuk notula." @endif>{{ $bagian->aktif ? 'Nonaktifkan' : 'Aktifkan kembali' }}</button>
                        @if ($bagian->poin_count === 0)
                            <button type="button" class="btn btn-red btn-sm" wire:click="confirmHapus({{ $bagian->id }})" wire:loading.attr="disabled" wire:target="confirmHapus({{ $bagian->id }})">🗑 Hapus</button>
                        @endif
                    </div>
                </div>

                @if ($detailId === $bagian->id && $detail)
                    <div style="margin-top:12px;padding:14px;border:1px solid var(--line);border-radius:12px;background:var(--surface-2);font-size:12.5px">
                        <div style="font-weight:700;margin-bottom:8px">Detail "{{ $detail->nama }}" <span style="font-weight:500;color:var(--muted)">(baca-saja)</span></div>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:4px 16px;line-height:1.7">
                            <div><span style="color:var(--muted)">ID:</span> {{ $detail->id }}</div>
                            <div><span style="color:var(--muted)">Kode internal:</span> <code>{{ $detail->kode }}</code></div>
                            <div><span style="color:var(--muted)">Folder Drive bukti:</span> {{ $detail->namaFolderEfektif() }}</div>
                            <div><span style="color:var(--muted)">Status:</span> {{ $detail->aktif ? 'Aktif' : 'Nonaktif' }} · urutan {{ $detail->urutan }}</div>
                            <div><span style="color:var(--muted)">Peran (melihat &amp; mengisi):</span> {{ $detail->labelPeran() }}</div>
                            <div><span style="color:var(--muted)">Menu:</span> {{ $detail->labelPenempatan() }}</div>
                            <div><span style="color:var(--muted)">Periode pengisian:</span> {{ $detail->labelFrekuensi() }}</div>
                            <div><span style="color:var(--muted)">Peran pembaca (baca-saja):</span> {{ $detail->labelPeranPembaca() }}</div>
                            <div><span style="color:var(--muted)">Hak aksi:</span> {{ \App\Models\RiwayatKonfigurasiBagianKustom::ringkasHak($detail->matriksAksi()) }}</div>
                            <div><span style="color:var(--muted)">Bukti dukung:</span> {{ \App\Support\AturanBuktiBagianKustom::ringkasan($detail->aturanBukti()) }}{{ $detail->aturanBukti()['diatur'] ? '' : ' (aturan lama: hanya di Isian Kegiatan)' }}</div>
                            <div><span style="color:var(--muted)">Tampilan:</span> isian {{ mb_strtolower(\App\Models\BagianKustom::TAMPILAN_ISIAN[$detail->tampilanIsian()]) }}, penyajian {{ mb_strtolower(\App\Models\BagianKustom::TAMPILAN_BACA[$detail->tampilanBaca()]) }}</div>
                            <div><span style="color:var(--muted)">Notula:</span> {{ $detail->masuk_notula ? 'Masuk, judul "'.$detail->judulNotulaEfektif().'"' : 'Tidak masuk' }}</div>
                            <div><span style="color:var(--muted)">Format baris notula:</span> <code>{{ $detail->formatNotulaEfektif() }}</code></div>
                            <div><span style="color:var(--muted)">Label uraian:</span> {{ $detail->labelUraianEfektif() }}</div>
                            <div><span style="color:var(--muted)">Poin tersimpan:</span> {{ $detail->poin_count }}</div>
                            <div><span style="color:var(--muted)">Dibuat:</span> {{ $detail->created_at?->wita()->format('d/m/Y H:i') }} WITA</div>
                            <div><span style="color:var(--muted)">Diperbarui:</span> {{ $detail->updated_at?->wita()->format('d/m/Y H:i') }} WITA</div>
                        </div>
                        @if ($detail->deskripsi)
                            <div style="margin-top:8px"><span style="color:var(--muted)">Deskripsi:</span> {{ $detail->deskripsi }}</div>
                        @endif

                        <div style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.5px;margin:12px 0 6px">Struktur isian</div>
                        <div style="padding-left:4px">• {{ $detail->labelUraianEfektif() }} <span style="color:var(--muted)">(uraian utama, wajib)</span></div>
                        @forelse ($kelompokDetail as $grup)
                            @if ($grup['subbagian'])
                                <div style="margin-top:6px;font-weight:700">▸ Subbagian: {{ $grup['subbagian']['nama'] }} @unless ($grup['subbagian']['aktif'])<span class="badge b-draft">nonaktif</span>@endunless</div>
                            @endif
                            @foreach ($grup['kolom'] as $k)
                                <div style="padding-left:{{ $grup['subbagian'] ? '18px' : '4px' }}">• {{ $k['label'] }} <code style="font-size:11px">{{ $k['kunci'] }}</code>
                                    @if ($k['variabel'] ?? null)<span class="badge b-verif" style="font-size:10px">katalog: {{ $k['variabel'] }}</span>@endif
                                    <span style="color:var(--muted)">— {{ $tipeKolom[$k['tipe']] ?? $k['tipe'] }}{{ $k['wajib'] ? ', wajib' : ', opsional' }}{{ \App\Models\BagianKustom::ringkasanKolom($k) !== '' ? ', '.\App\Models\BagianKustom::ringkasanKolom($k) : '' }}{{ $k['opsi'] ? ' · opsi: '.implode(' / ', $k['opsi']) : '' }}</span>
                                </div>
                            @endforeach
                        @empty
                            <div style="color:var(--muted);padding-left:4px">Tidak ada kolom tambahan.</div>
                        @endforelse
                        @foreach (collect($detail->subbagianList())->filter(fn ($s) => ! collect($kelompokDetail)->pluck('subbagian.kunci')->contains($s['kunci'])) as $kosong)
                            <div style="margin-top:6px;font-weight:700">▸ Subbagian: {{ $kosong['nama'] }} <span style="color:var(--muted);font-weight:500">(belum ada kolom)</span></div>
                        @endforeach
                    </div>
                @endif

                @if ($bukaId === $bagian->id && $f)
                    <div style="margin-top:14px;padding:16px;background:var(--bg);border:1px solid var(--line);border-radius:12px">
                        {{-- Tab --}}
                        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px">
                            @foreach (['dasar' => '⚙️ Informasi, Peran & Penempatan', 'isian' => '🧾 Struktur Isian & Tampilan', 'bukti' => '📎 Bukti Dukung', 'notula' => '📄 Notula & Variabel', 'pratinjau' => '👁 Pratinjau', 'riwayat' => '🕘 Riwayat'] as $kunciTab => $labelTab)
                                <button type="button" class="btn {{ $tab === $kunciTab ? 'btn-primary' : 'btn-ghost' }} btn-sm" wire:click="setTab('{{ $kunciTab }}')" @if ($tab === $kunciTab) aria-current="true" @endif>{{ $labelTab }}</button>
                            @endforeach
                        </div>
                        @if ($belumDisimpan)
                            <div class="info warn" role="status" style="font-size:12px" data-belum-disimpan="1">✎ Ada perubahan yang <b>belum disimpan</b>. Klik "💾 Simpan Pengaturan" untuk menyimpan, atau "Batal ubah" untuk membuangnya.</div>
                        @endif

                        @error("form.{$bagian->id}.nama")<div style="color:var(--red);font-size:11.5px;margin-bottom:8px">{{ $message }}</div>@enderror
                        @error("form.{$bagian->id}.kolom")<div style="color:var(--red);font-size:11.5px;margin-bottom:8px">{{ $message }}</div>@enderror

                        {{-- TAB 1: DASAR & PERAN --}}
                        @if ($tab === 'dasar')
                            <div class="row2">
                                <div class="field">
                                    <label>Nama Bagian <span class="req">*</span></label>
                                    <input type="text" class="inp filled" wire:model="form.{{ $bagian->id }}.nama">
                                    <div class="fhint" style="margin-top:4px">Nama boleh diganti — kode, data isian, bukti, dan folder Drive tetap sama.</div>
                                </div>
                                <div class="field">
                                    <label>Kode internal &amp; folder Drive <span style="color:var(--muted);font-weight:500">(tetap)</span></label>
                                    <div class="inp filled" style="font-size:12.5px;color:var(--muted)"><code>{{ $bagian->kode }}</code> · folder "{{ $bagian->namaFolderEfektif() }}"</div>
                                </div>
                            </div>
                            <div class="field">
                                <label>Deskripsi <span style="color:var(--muted);font-weight:500">(opsional — petunjuk untuk pengisi)</span></label>
                                <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model="form.{{ $bagian->id }}.deskripsi"></textarea>
                            </div>
                            @include('livewire.partials._peran-penempatan', ['modelPeran' => 'form.'.$bagian->id.'.peran', 'modelMenu' => 'form.'.$bagian->id.'.penempatan', 'peranDipilih' => $f['peran'] ?? [], 'namaPeran' => $namaPeran, 'katalogMenu' => $katalogMenu])
                            @error("form.{$bagian->id}.penempatan")
                                <div style="color:var(--red);font-size:11.5px;margin-bottom:8px">{{ $message }}</div>
                            @enderror
                            <div class="row2">
                                <div class="field">
                                    <label>Kapan wajib diisi</label>
                                    <select class="inp filled" wire:model="form.{{ $bagian->id }}.frekuensi_wajib">
                                        <option value="opsional">Selalu opsional</option>
                                        <option value="setiap_bulan">Wajib tiap bulan</option>
                                        <option value="akhir_triwulan">Wajib hanya bulan terakhir triwulan</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Bukti dukung</label>
                                    <div class="inp filled" style="font-size:12.5px;height:auto;min-height:38px">{{ \App\Support\AturanBuktiBagianKustom::ringkasan(\App\Support\AturanBuktiBagianKustom::normalkan(($f['aturan_bukti'] ?? null) ?: null, (bool) ($f['bukti_wajib'] ?? false))) }}</div>
                                    <div class="fhint" style="margin-top:4px">Atur di tab 📎 Bukti Dukung.</div>
                                </div>
                            </div>

                            <div class="field">
                                <label>Peran pembaca <span style="color:var(--muted);font-weight:500">(opsional — hanya melihat, tidak bisa mengubah)</span></label>
                                <div style="display:flex;gap:16px;flex-wrap:wrap">
                                    @foreach ($namaPeran as $nilai => $label)
                                        @php $sudahPengisi = in_array($nilai, $f['peran'] ?? [], true); @endphp
                                        <label style="display:flex;gap:6px;align-items:center;font-size:13px;cursor:{{ $sudahPengisi ? 'not-allowed' : 'pointer' }};opacity:{{ $sudahPengisi ? '.5' : '1' }}" title="{{ $sudahPengisi ? 'Sudah menjadi pengisi' : '' }}">
                                            <input type="checkbox" value="{{ $nilai }}" wire:model.live="form.{{ $bagian->id }}.peran_pembaca" @disabled($sudahPengisi)> {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                                @error("form.{$bagian->id}.peran_pembaca")<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                                <div class="fhint" style="margin-top:4px">Pembaca melihat isian semua pengisi bagian ini (beserta bukti dukungnya) di menu penempatan yang bisa dibukanya.</div>
                            </div>

                            {{-- Matriks hak aksi per peran pengisi. Diperiksa ulang di server pada
                                 setiap aksi (IsianBagianKustom, PengisianKegiatan). --}}
                            <div class="field" data-matriks-hak="{{ $bagian->id }}">
                                <label>Hak akses per peran</label>
                                <div style="overflow-x:auto;max-width:100%">
                                    <table style="border-collapse:collapse;font-size:12.5px;min-width:520px">
                                        <thead>
                                            <tr style="text-align:center;color:var(--muted)">
                                                <th scope="col" style="text-align:left;padding:4px 8px;border-bottom:1px solid var(--line)">Peran</th>
                                                <th scope="col" style="padding:4px 8px;border-bottom:1px solid var(--line)">Lihat</th>
                                                @foreach (\App\Models\BagianKustom::AKSI as $labelAksi)
                                                    <th scope="col" style="padding:4px 8px;border-bottom:1px solid var(--line)">{{ $labelAksi }}</th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($namaPeran as $nilai => $label)
                                                @php
                                                    $pengisi = in_array($nilai, $f['peran'] ?? [], true);
                                                    $pembaca = ! $pengisi && in_array($nilai, $f['peran_pembaca'] ?? [], true);
                                                @endphp
                                                <tr style="text-align:center" wire:key="hak-{{ $bagian->id }}-{{ $nilai }}">
                                                    <th scope="row" style="text-align:left;padding:4px 8px;border-bottom:1px solid var(--line2);font-weight:600">{{ $label }}</th>
                                                    <td style="padding:4px 8px;border-bottom:1px solid var(--line2)">{{ $pengisi ? '✓ isi' : ($pembaca ? '✓ baca' : '—') }}</td>
                                                    @foreach (\App\Models\BagianKustom::AKSI as $aksi => $labelAksi)
                                                        <td style="padding:4px 8px;border-bottom:1px solid var(--line2)">
                                                            @if ($pengisi)
                                                                <input type="checkbox" aria-label="{{ $label }}: {{ $labelAksi }}" wire:model.live="form.{{ $bagian->id }}.hak_aksi.{{ $nilai }}.{{ $aksi }}">
                                                            @else
                                                                <span style="color:var(--faint)" title="Hanya peran pengisi yang punya aksi">—</span>
                                                            @endif
                                                        </td>
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                <div class="fhint" style="margin-top:4px">Lihat diatur lewat peran pengisi dan peran pembaca di atas. Aksi lain hanya untuk peran pengisi, dan semuanya diperiksa di server. Menghapus centang tidak menghapus data yang sudah ada. Di Isian Kegiatan, poin tidak pernah dihapus dan pengajuan/verifikasi tetap mengikuti alur Isian Kegiatan.</div>
                            </div>

                        {{-- TAB: BUKTI DUKUNG --}}
                        @elseif ($tab === 'bukti')
                            @php $ab = $f['aturan_bukti'] ?? []; @endphp
                            <div class="info" style="font-size:12px">📎 Aturan bukti dukung berlaku untuk setiap poin yang diisi, di semua menu tempat bagian ini ditempatkan. Semua aturan diperiksa di server. Mengubah aturan <b>tidak</b> mengubah, memindahkan, atau menghapus bukti yang sudah diunggah — berkas lama tetap tersimpan di Google Drive dan tetap bisa dibuka.</div>
                            <div class="row2">
                                <div class="field">
                                    <label for="mode-bukti-{{ $bagian->id }}">Kebutuhan bukti</label>
                                    <select id="mode-bukti-{{ $bagian->id }}" class="inp filled" wire:model.live="form.{{ $bagian->id }}.aturan_bukti.mode">
                                        @foreach ($modeBukti as $nilai => $label)
                                            <option value="{{ $nilai }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error("form.{$bagian->id}.aturan_bukti.mode")<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                                </div>
                                <div class="field">
                                    <label for="ukuran-bukti-{{ $bagian->id }}">Ukuran maksimal per berkas (MB)</label>
                                    <input id="ukuran-bukti-{{ $bagian->id }}" type="number" min="1" max="{{ \App\Support\AturanBuktiBagianKustom::UKURAN_MAKS_MB }}" class="inp filled" wire:model.blur="form.{{ $bagian->id }}.aturan_bukti.ukuran_maks_mb" placeholder="kosong = tanpa batas khusus" @disabled(($ab['mode'] ?? '') === 'tidak')>
                                    @error("form.{$bagian->id}.aturan_bukti.ukuran_maks_mb")<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            @if (($ab['mode'] ?? '') !== 'tidak')
                                <div class="row2">
                                    <div class="field">
                                        <label for="min-bukti-{{ $bagian->id }}">Jumlah minimal berkas per poin</label>
                                        <input id="min-bukti-{{ $bagian->id }}" type="number" min="0" max="{{ \App\Support\AturanBuktiBagianKustom::JUMLAH_MAKS }}" class="inp filled" wire:model.blur="form.{{ $bagian->id }}.aturan_bukti.min">
                                        <div class="fhint" style="margin-top:4px">Untuk "Wajib", minimal 1. Untuk "Opsional", berlaku bila pengisi melampirkan bukti.</div>
                                        @error("form.{$bagian->id}.aturan_bukti.min")<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="field">
                                        <label for="maks-bukti-{{ $bagian->id }}">Jumlah maksimal berkas per poin</label>
                                        <input id="maks-bukti-{{ $bagian->id }}" type="number" min="1" max="{{ \App\Support\AturanBuktiBagianKustom::JUMLAH_MAKS }}" class="inp filled" wire:model.blur="form.{{ $bagian->id }}.aturan_bukti.maks" placeholder="kosong = tanpa batas">
                                        @error("form.{$bagian->id}.aturan_bukti.maks")<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                                <div class="field">
                                    <label>Format yang diizinkan</label>
                                    <div style="display:flex;gap:16px;flex-wrap:wrap">
                                        @foreach ($formatBukti as $nilai => $fmt)
                                            <label style="display:flex;gap:6px;align-items:center;font-size:13px;cursor:pointer">
                                                <input type="checkbox" value="{{ $nilai }}" wire:model.live="form.{{ $bagian->id }}.aturan_bukti.format"> {{ $fmt['label'] }}
                                            </label>
                                        @endforeach
                                    </div>
                                    <div class="fhint" style="margin-top:4px">Tanpa centang = PDF. Isi berkas juga diperiksa, bukan hanya nama berkasnya.</div>
                                    @error("form.{$bagian->id}.aturan_bukti.format")<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                                </div>
                                <div class="field">
                                    <label for="petunjuk-bukti-{{ $bagian->id }}">Petunjuk pengunggahan <span style="color:var(--muted);font-weight:500">(opsional)</span></label>
                                    <input id="petunjuk-bukti-{{ $bagian->id }}" type="text" class="inp filled" wire:model.blur="form.{{ $bagian->id }}.aturan_bukti.petunjuk" placeholder="mis. Unggah notulen rapat dan daftar hadir">
                                </div>
                            @endif
                            <div class="fhint">Ringkasan: <b>{{ \App\Support\AturanBuktiBagianKustom::ringkasan(\App\Support\AturanBuktiBagianKustom::normalkan($ab ?: null, (bool) ($f['bukti_wajib'] ?? false))) }}</b>. Bukti disimpan di folder Google Drive "{{ $bagian->namaFolderEfektif() }}" (mekanisme Drive yang sama dengan bukti lain).</div>

                        {{-- TAB 2: KOLOM ISIAN --}}
                        @elseif ($tab === 'isian')
                            <div class="info" style="font-size:12px">🧾 Susun isian bagian ini: <b>Uraian</b> selalu ada sebagai isian utama, lalu kolom tambahan — langsung di bagian atau dikelompokkan ke <b>subbagian</b>. Setiap kolom otomatis menjadi <b>variabel</b> notula dengan nama seperti tertulis di "Variabel", dan tetap terhubung ke data lama walau labelnya diganti.</div>

                            <div class="row2">
                                <div class="field">
                                    <label for="tampilan-isian-{{ $bagian->id }}">Bentuk formulir isian</label>
                                    <select id="tampilan-isian-{{ $bagian->id }}" class="inp filled" wire:model.live="form.{{ $bagian->id }}.tampilan.isian">
                                        @foreach (\App\Models\BagianKustom::TAMPILAN_ISIAN as $nilai => $label)
                                            <option value="{{ $nilai }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <div class="fhint" style="margin-top:4px">Tabel berlaku di menu selain Isian Kegiatan (Isian Kegiatan selalu kartu). Di layar kecil tabel bisa digeser ke samping.</div>
                                </div>
                                <div class="field">
                                    <label for="tampilan-baca-{{ $bagian->id }}">Bentuk penyajian isian tersimpan</label>
                                    <select id="tampilan-baca-{{ $bagian->id }}" class="inp filled" wire:model.live="form.{{ $bagian->id }}.tampilan.baca">
                                        @foreach (\App\Models\BagianKustom::TAMPILAN_BACA as $nilai => $label)
                                            <option value="{{ $nilai }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <div class="fhint" style="margin-top:4px">Dipakai pada ringkasan isian dan tampilan pembaca. Tidak mengubah notula.</div>
                                </div>
                            </div>

                            <div class="field">
                                <label>Label untuk isian Uraian <span style="color:var(--muted);font-weight:500">(opsional, default "Uraian")</span></label>
                                <input type="text" class="inp filled" wire:model.live.debounce.300ms="form.{{ $bagian->id }}.label_uraian" placeholder="mis. Nama Risiko">
                            </div>

                            @php
                                $barisKolom = $f['kolom'] ?? [];
                                $kunciSubForm = array_column($f['subbagian'] ?? [], 'kunci');
                                $kelompokForm = ['' => []];
                                foreach ($f['subbagian'] ?? [] as $s) { $kelompokForm[$s['kunci']] = []; }
                                foreach ($barisKolom as $j => $kol) {
                                    $g = in_array($kol['subbagian'] ?? '', $kunciSubForm, true) ? $kol['subbagian'] : '';
                                    $kelompokForm[$g][] = $j;
                                }
                                $kartu = ['idBagian' => $bagian->id, 'f' => $f, 'tipeKolom' => $tipeKolom, 'pilihanPemisah' => $pilihanPemisah, 'formatTanggal' => $formatTanggal, 'katalogVariabel' => $katalogVariabel];
                            @endphp

                            <div style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.5px;margin:14px 0 8px">Kolom langsung di bagian</div>
                            @forelse ($kelompokForm[''] as $urutDiGrup => $j)
                                @include('livewire.partials._kartu-kolom-bagian-kustom', $kartu + ['j' => $j, 'kol' => $barisKolom[$j], 'pertama' => $urutDiGrup === 0, 'terakhir' => $urutDiGrup === count($kelompokForm['']) - 1])
                            @empty
                                <p style="color:var(--muted);font-size:12.5px;margin:0 0 8px">Belum ada kolom langsung di bagian.</p>
                            @endforelse
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="tambahKolom({{ $bagian->id }})">＋ Tambah kolom</button>

                            <div style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.5px;margin:20px 0 8px">Subbagian <span style="text-transform:none;font-weight:500">(opsional)</span></div>
                            @error("form.{$bagian->id}.subbagian")<div style="color:var(--red);font-size:11.5px;margin-bottom:8px">{{ $message }}</div>@enderror
                            @forelse ($f['subbagian'] ?? [] as $si => $sub)
                                <div style="border:1px solid var(--line);border-left:4px solid var(--blue);border-radius:10px;padding:12px;margin-bottom:12px" wire:key="sub-{{ $bagian->id }}-{{ $sub['kunci'] }}">
                                    <div class="row2">
                                        <div class="field" style="margin-bottom:8px">
                                            <label>Nama subbagian <span class="req">*</span></label>
                                            <input type="text" class="inp filled" wire:model.live.debounce.300ms="form.{{ $bagian->id }}.subbagian.{{ $si }}.nama" placeholder="mis. Identifikasi Risiko">
                                            @error("form.{$bagian->id}.subbagian.{$si}.nama")<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                                        </div>
                                        <div class="field" style="margin-bottom:8px">
                                            <label>Deskripsi / petunjuk <span style="color:var(--muted);font-weight:500">(opsional)</span></label>
                                            <input type="text" class="inp filled" wire:model.blur="form.{{ $bagian->id }}.subbagian.{{ $si }}.deskripsi">
                                        </div>
                                    </div>
                                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:10px">
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">
                                            <input type="checkbox" wire:model.live="form.{{ $bagian->id }}.subbagian.{{ $si }}.aktif"> Aktif
                                        </label>
                                        <span style="font-size:11px;color:var(--muted)">Kode: <code>{{ $sub['kunci'] }}</code></span>
                                        <div class="btn-row" style="margin-top:0;gap:4px">
                                            <button type="button" class="btn btn-ghost btn-sm" wire:click="naikkanSubbagian({{ $bagian->id }}, {{ $si }})" @disabled($si === 0) aria-label="Naikkan subbagian">↑</button>
                                            <button type="button" class="btn btn-ghost btn-sm" wire:click="turunkanSubbagian({{ $bagian->id }}, {{ $si }})" @disabled($si === count($f['subbagian']) - 1) aria-label="Turunkan subbagian">↓</button>
                                            <button type="button" class="btn btn-red btn-sm" wire:click="hapusSubbagian({{ $bagian->id }}, {{ $si }})" aria-label="Hapus subbagian">🗑</button>
                                        </div>
                                    </div>
                                    @unless ($sub['aktif'] ?? true)
                                        <div class="info warn" style="font-size:11.5px;margin-bottom:8px">Subbagian nonaktif: kolom di dalamnya tidak tampil di formulir isian. Data yang sudah tersimpan tetap ada dan tetap masuk notula.</div>
                                    @endunless
                                    @forelse ($kelompokForm[$sub['kunci']] ?? [] as $urutDiGrup => $j)
                                        @include('livewire.partials._kartu-kolom-bagian-kustom', $kartu + ['j' => $j, 'kol' => $barisKolom[$j], 'pertama' => $urutDiGrup === 0, 'terakhir' => $urutDiGrup === count($kelompokForm[$sub['kunci']]) - 1])
                                    @empty
                                        <p style="color:var(--muted);font-size:12.5px;margin:0 0 8px">Belum ada kolom di subbagian ini.</p>
                                    @endforelse
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="tambahKolom({{ $bagian->id }}, '{{ $sub['kunci'] }}')">＋ Tambah kolom di subbagian ini</button>
                                </div>
                            @empty
                                <p style="color:var(--muted);font-size:12.5px;margin:0 0 8px">Bagian ini tidak memakai subbagian. Tambahkan bila kolom perlu dikelompokkan.</p>
                            @endforelse
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="tambahSubbagian({{ $bagian->id }})">＋ Tambah subbagian</button>

                        {{-- TAB 3: NOTULA & VARIABEL --}}
                        @elseif ($tab === 'notula')
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;margin-bottom:12px">
                                <input type="checkbox" wire:model.live="form.{{ $bagian->id }}.masuk_notula">
                                <b>Tampilkan bagian ini di notula (Bagian I)</b>
                            </label>

                            @if ($f['masuk_notula'] ?? false)
                                <div class="row2">
                                    <div class="field">
                                        <label>Judul di notula <span style="color:var(--muted);font-weight:500">(kosong = nama bagian)</span></label>
                                        <input type="text" class="inp filled" wire:model.live.debounce.300ms="form.{{ $bagian->id }}.judul_notula" placeholder="{{ $f['nama'] ?? '' }}">
                                    </div>
                                    <div class="field">
                                        <label>Contoh format cepat</label>
                                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                                            <button type="button" class="btn btn-ghost btn-sm" wire:click="pakaiFormatContoh({{ $bagian->id }}, 'standar')">Standar</button>
                                            <button type="button" class="btn btn-ghost btn-sm" wire:click="pakaiFormatContoh({{ $bagian->id }}, 'lengkap')">Dengan semua kolom</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="field">
                                    <label>Format satu baris poin di notula</label>
                                    <textarea class="inp filled" x-ref="formatNotula" style="height:auto;display:block;font-family:monospace;font-size:12.5px" rows="2"
                                        wire:model.live.debounce.400ms="form.{{ $bagian->id }}.format_notula"
                                        placeholder="{{ \App\Support\TemplateBagianKustom::FORMAT_STANDAR }}"></textarea>
                                    @error("form.{$bagian->id}.format_notula")<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                                    <div class="fhint" style="margin-top:6px">Tulis teks biasa dan sisipkan variabel dalam kurung kurawal, mis. <code>{kode_iku}: {teks}</code>. Tanda • (bullet) ditambahkan otomatis di notula. Klik variabel di bawah untuk menyisipkannya.</div>
                                </div>

                                <div style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 14px">
                                    @foreach ($variabel as $v)
                                        <button type="button" class="btn btn-ghost btn-sm" style="font-family:monospace;font-size:11.5px"
                                            x-on:click="$refs.formatNotula.value += '{{ '{'.$v['kunci'].'}' }}'; $refs.formatNotula.dispatchEvent(new Event('input'))">{{ '{'.$v['kunci'].'}' }}</button>
                                    @endforeach
                                </div>

                                <div style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Keterangan variabel</div>
                                <div style="overflow-x:auto">
                                    <table style="width:100%;border-collapse:collapse;font-size:12px">
                                        <thead>
                                            <tr style="text-align:left;color:var(--muted)">
                                                <th style="padding:6px;border-bottom:1px solid var(--line)">Variabel</th>
                                                <th style="padding:6px;border-bottom:1px solid var(--line)">Keterangan</th>
                                                <th style="padding:6px;border-bottom:1px solid var(--line)">Contoh hasil</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($variabel as $v)
                                                <tr>
                                                    <td style="padding:6px;border-bottom:1px solid var(--line2);font-family:monospace">{{ '{'.$v['kunci'].'}' }}</td>
                                                    <td style="padding:6px;border-bottom:1px solid var(--line2)">{{ $v['keterangan'] }}</td>
                                                    <td style="padding:6px;border-bottom:1px solid var(--line2)">{{ $v['contoh'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                <div class="info" style="font-size:11.5px;margin-top:12px">
                                    <b>Cara pakai:</b> tiap poin yang diisi menjadi satu baris di notula, di bawah judul bagian. Contoh: format <code>{kode_iku}: {teks} ({level_risiko})</code> akan menghasilkan <i>1131: Keterlambatan data mitra (Tinggi)</i>.
                                    Variabel yang tidak dikenal tidak akan bisa disimpan. Notula yang sudah disusun sebelumnya baru ikut berubah setelah disusun ulang.
                                </div>
                            @else
                                <div class="fhint">Bagian ini tidak ditampilkan di notula. Data isiannya tetap tersimpan dan bisa dilihat di Verifikasi capaian bila berlaku.</div>
                            @endif

                        {{-- TAB 4: PRATINJAU --}}
                        @elseif ($tab === 'pratinjau')
                            <div style="font-size:12px;font-weight:700;margin-bottom:8px">📍 Di mana bagian ini muncul</div>
                            <ul style="font-size:12.5px;line-height:1.7;margin:0 0 16px;padding-left:18px">
                                @forelse ($lokasi as $tempat)
                                    <li><b>Menu {{ $tempat['menu'] }} ({{ $tempat['peran'] }}):</b> {{ $tempat['tempat'] }}</li>
                                @empty
                                    <li>Belum ada menu yang dipilih untuk peran yang dipilih.</li>
                                @endforelse
                                @if (in_array(\App\Support\MenuBagianKustom::PENGISIAN, $f['penempatan'] ?? []) && in_array(\App\Models\BagianKustom::PERAN_KETUA_TIM, $f['peran'] ?? []))
                                    <li><b>Verifikasi capaian (Tim SAKIP):</b> menu ✅ Verifikasi &amp; Notula → buka capaian IKU → kartu "Bagian Kustom", tombol Sesuai / Tidak Sesuai per poin.</li>
                                @endif
                                @if ($f['masuk_notula'] ?? false)
                                    <li><b>Notula (Bagian I):</b> menu 📄 Notula → susun Bagian I. Bagian ini dicetak sebagai judul "{{ $pratinjau['judul'] ?? '' }}" di bawah blok IKU terakhir, sebelum Bagian II.</li>
                                @else
                                    <li><b>Notula:</b> tidak ditampilkan (lihat tab Notula &amp; Variabel).</li>
                                @endif
                            </ul>

                            <div style="font-size:12px;font-weight:700;margin-bottom:8px">🧾 Tampilan isian bagi pengisi (contoh)</div>
                            <div class="card" style="margin:0 0 16px;padding:14px">
                                <div class="sec" style="margin:0 0 8px"><span>🧩 {{ $f['nama'] ?: 'Nama Bagian' }}</span></div>
                                @if (! empty($f['deskripsi']))
                                    <div class="info" style="font-size:11.5px">ℹ️ {{ $f['deskripsi'] }}</div>
                                @endif
                                <div class="poin-single" style="margin-top:8px">
                                    <span class="k-num stat-in">Poin 1</span>
                                    <div class="field" style="margin-bottom:8px">
                                        <label style="font-size:11px">{{ filled($f['label_uraian'] ?? null) ? $f['label_uraian'] : 'Uraian' }} <span class="req">*</span></label>
                                        <div class="inp filled" style="color:var(--muted);font-style:italic">Contoh isian uraian…</div>
                                    </div>
                                    @foreach ($kelompokPratinjau as $grup)
                                        @if ($grup['subbagian'])
                                            <div style="margin:12px 0 6px;padding-top:8px;border-top:1px dashed var(--line);font-weight:700;font-size:12px">{{ $grup['subbagian']['nama'] }}</div>
                                            @if ($grup['subbagian']['deskripsi'])<div class="fhint" style="margin:0 0 6px">{{ $grup['subbagian']['deskripsi'] }}</div>@endif
                                        @endif
                                        @foreach ($grup['kolom'] as $kol)
                                            <div class="field" style="margin-bottom:8px">
                                                <label style="font-size:11px">{{ $kol['label'] }} @if ($kol['wajib'])<span class="req">*</span>@endif</label>
                                                @if ($kol['petunjuk'])<div class="fhint" style="margin:0 0 4px">{{ $kol['petunjuk'] }}</div>@endif
                                                <div class="inp filled" style="color:var(--muted);font-style:italic;{{ $kol['tipe'] === 'teks_panjang' ? 'height:64px' : '' }}">{{ match ($kol['tipe']) {
                                                    'pilihan' => 'Pilih: '.implode(' / ', $kol['opsi']),
                                                    'pilihan_ganda' => 'Centang satu atau lebih: '.implode(' / ', $kol['opsi']),
                                                    'angka' => $kol['placeholder'] ?? 'Isi angka…',
                                                    'tanggal' => 'Pilih tanggal (tampil: '.\App\Support\TemplateBagianKustom::contohKolom($kol).')',
                                                    default => $kol['placeholder'] ?? 'Isi teks…',
                                                } }}</div>
                                            </div>
                                        @endforeach
                                    @endforeach
                                    @php $aturanPratinjau = \App\Support\AturanBuktiBagianKustom::normalkan(($f['aturan_bukti'] ?? null) ?: null, (bool) ($f['bukti_wajib'] ?? false)); @endphp
                                    @if (\App\Support\AturanBuktiBagianKustom::dipakai($aturanPratinjau))
                                        <div class="fhint" style="margin:0 0 4px">Bukti dukung: {{ \App\Support\AturanBuktiBagianKustom::ringkasan($aturanPratinjau) }}</div>
                                        <div class="filechip ok" style="font-size:11px"><span class="nm">📄 contoh-bukti-dukung.{{ $aturanPratinjau['format'][0] ?? 'pdf' }}</span></div>
                                    @endif
                                </div>
                            </div>

                            <div style="font-size:12px;font-weight:700;margin-bottom:8px">📄 Hasil di notula (contoh dua poin)</div>
                            @if ($f['masuk_notula'] ?? false)
                                <div class="notula" style="margin:0;padding:20px;min-height:0;box-shadow:none;font-size:12px;border-radius:8px">
                                    <h3 style="font-size:13px;text-align:center">{{ $pratinjau['judul'] }}</h3>
                                    <ul style="font-size:12px">
                                        @foreach ($pratinjau['baris'] as $baris)
                                            <li>{{ $baris }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                                @if (! empty($pratinjau['peringatan']))
                                    <div class="info warn" style="font-size:11.5px;margin-top:8px">⚠️ Variabel tidak dikenal: {{ implode(', ', $pratinjau['peringatan']) }} — akan tampil apa adanya di notula. Perbaiki di tab Notula &amp; Variabel.</div>
                                @endif
                            @else
                                <div class="fhint">Tidak ditampilkan di notula.</div>
                            @endif

                        {{-- TAB 5: RIWAYAT PERUBAHAN PENGATURAN --}}
                        @elseif ($tab === 'riwayat')
                            <div class="fhint" style="margin:0 0 10px">Rekam jejak perubahan pengaturan bagian ini (30 terakhir). Isian pengguna tidak dicatat di sini.</div>
                            @forelse ($riwayat as $r)
                                <div style="padding:8px 0;border-bottom:1px solid var(--line2);font-size:12.5px">
                                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                                        <span class="badge b-verif">{{ $r->labelAksi() }}</span>
                                        <span style="color:var(--muted)">{{ $r->created_at?->wita()->format('d/m/Y H:i') }} WITA · {{ $r->nama_pengguna ?? $r->user?->nama ?? 'Sistem' }}</span>
                                    </div>
                                    <div style="margin-top:4px">{{ $r->ringkasan }}</div>
                                </div>
                            @empty
                                <p style="color:var(--muted);font-size:12.5px;margin:0">Belum ada perubahan tercatat. Pencatatan dimulai sejak fitur riwayat dipasang.</p>
                            @endforelse
                        @endif

                        {{-- Aksi simpan (tampil di semua tab) --}}
                        @error('form.'.$bagian->id.'.bentrok')
                            <div class="info red" style="margin-top:14px">⚠️ {{ $message }}</div>
                        @enderror
                        <div class="btn-row" style="margin-top:16px">
                            <button type="button" class="btn btn-primary btn-sm" wire:click="simpan({{ $bagian->id }})" wire:loading.attr="disabled" wire:target="simpan({{ $bagian->id }})">
                                <span wire:loading.remove wire:target="simpan({{ $bagian->id }})">💾 Simpan Pengaturan</span>
                                <span wire:loading wire:target="simpan({{ $bagian->id }})"><i class="spin"></i> Menyimpan…</span>
                            </button>
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="batalUbah({{ $bagian->id }})" wire:loading.attr="disabled" wire:target="batalUbah({{ $bagian->id }})">Batal ubah</button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <p style="color:var(--muted);font-size:13px">Belum ada bagian kustom. Tambahkan lewat form di atas.</p>
        @endforelse
    </div>

    {{-- Peringatan meninggalkan halaman saat ada pengaturan yang belum disimpan
         (penanda data-belum-disimpan dirender server dari perbandingan form vs database). --}}
    @script
    <script>
        if (! window.__jagaBagianKustomBelumDisimpan) {
            window.__jagaBagianKustomBelumDisimpan = true;
            const adaPerubahan = () => document.querySelector('[data-belum-disimpan="1"]') !== null;
            window.addEventListener('beforeunload', (e) => {
                if (adaPerubahan()) { e.preventDefault(); e.returnValue = ''; }
            });
            document.addEventListener('livewire:navigate', (e) => {
                if (adaPerubahan() && ! confirm('Ada perubahan pengaturan Bagian Kustom yang belum disimpan. Tinggalkan halaman ini dan buang perubahannya?')) {
                    e.preventDefault();
                }
            });
        }
    </script>
    @endscript

    <x-confirm-modal :show="$pendingHapusId !== null" title="Hapus Bagian?" :message="'Hapus bagian \''.$pendingHapusNama.'\'?'">
        <button type="button" class="btn btn-ghost" wire:click="batalkanHapus" wire:loading.attr="disabled" wire:target="hapus">Batal</button>
        <button type="button" class="btn btn-red" wire:click="hapus" wire:loading.attr="disabled" wire:target="hapus">
            <span wire:loading.remove wire:target="hapus">Hapus</span>
            <span wire:loading wire:target="hapus"><i class="spin"></i> Menghapus…</span>
        </button>
    </x-confirm-modal>
</div>
