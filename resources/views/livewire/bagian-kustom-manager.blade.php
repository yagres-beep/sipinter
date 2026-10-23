<div>
    <div class="page-head">
        <div class="page-title">Bagian Kustom</div>
        <div class="page-sub">Atur bagian tambahan (mis. Manajemen Risiko) langsung dari sini — siapa yang mengisi, kolom isian, dan tampilannya di notula. Tidak perlu mengubah kode.</div>
    </div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <div class="info">ℹ️ Bagian yang <b>aktif</b> muncul di menu peran pengisinya (Ketua Tim di Isian Kegiatan; Tim SAKIP/Kepala di 🧩 Isian Bagian Kustom). Data yang sudah tersimpan tetap tampil di notula meski bagiannya kemudian dinonaktifkan.</div>

    <div class="card">
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
                    <li>Notula: {{ $ringkasanSalin['notula'] }} (judul notula memakai nama bagian baru).</li>
                    <li>Bukti dukung: {{ $ringkasanSalin['bukti'] ? 'wajib' : 'tidak wajib' }}.</li>
                </ul>
                <div style="margin-top:6px"><b>Tidak disalin:</b> {{ $ringkasanSalin['poin_sumber'] }} poin isian, bukti dukung, status verifikasi, dan riwayat milik "{{ $ringkasanSalin['nama'] }}". Bagian baru mulai tanpa data.</div>
            </div>
        @endif
        <div class="field">
            <label>Deskripsi (opsional)</label>
            <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model="deskripsiBaru" placeholder="Petunjuk singkat untuk pengisi bagian ini"></textarea>
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
        <div class="sec"><span>Daftar Bagian Kustom</span></div>

        @error('hapus')
            <div style="color:var(--red);font-size:11.5px;margin-bottom:10px">{{ $message }}</div>
        @enderror

        @forelse ($daftar as $i => $bagian)
            @php $f = $form[$bagian->id] ?? null; @endphp
            <div style="border-top:1px solid var(--line2);padding:12px 0">
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start">
                    <span class="fl-move" style="margin-top:2px">
                        <button type="button" wire:click="naikkan({{ $bagian->id }})" wire:loading.attr="disabled" wire:target="naikkan({{ $bagian->id }})" @disabled($i === 0)>↑</button>
                        <button type="button" wire:click="turunkan({{ $bagian->id }})" wire:loading.attr="disabled" wire:target="turunkan({{ $bagian->id }})" @disabled($i === $daftar->count() - 1)>↓</button>
                    </span>

                    <div style="flex:1;min-width:240px">
                        <div style="font-weight:700;font-size:14px">🧩 {{ $bagian->nama }}</div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">
                            <span class="badge {{ $bagian->aktif ? 'b-approve' : 'b-draft' }}">{{ $bagian->aktif ? '✓ Aktif' : 'Nonaktif' }}</span>
                            <span class="badge b-verif">Peran: {{ $bagian->labelPeran() }}</span>
                            <span class="badge b-verif">Menu: {{ $bagian->labelPenempatan() }}</span>
                            <span class="badge {{ $bagian->frekuensi_wajib === 'opsional' ? 'b-draft' : 'b-ajukan' }}">{{ $bagian->labelFrekuensi() }}</span>
                            <span class="badge {{ $bagian->masuk_notula ? 'b-approve' : 'b-draft' }}">{{ $bagian->masuk_notula ? '📄 Masuk notula' : 'Tidak masuk notula' }}</span>
                            @php $jumlahKolomNonaktif = collect($bagian->kolomList())->where('aktif', false)->count(); @endphp
                            <span class="badge b-draft">{{ count($bagian->kolomList()) - $jumlahKolomNonaktif }} kolom tambahan{{ $jumlahKolomNonaktif > 0 ? ' (+'.$jumlahKolomNonaktif.' nonaktif)' : '' }}</span>
                            <span class="badge b-draft">{{ $bagian->poin_count }} poin tersimpan</span>
                        </div>
                    </div>

                    <div class="btn-row" style="margin-top:0;flex-wrap:wrap">
                        <button type="button" class="btn {{ $bukaId === $bagian->id ? 'btn-primary' : 'btn-ghost' }} btn-sm" wire:click="buka({{ $bagian->id }})" wire:loading.attr="disabled" wire:target="buka({{ $bagian->id }})">
                            {{ $bukaId === $bagian->id ? '✕ Tutup' : '✎ Atur' }}
                        </button>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleAktif({{ $bagian->id }}, {{ $bagian->aktif ? 'false' : 'true' }})" wire:loading.attr="disabled" wire:loading.class="btn-busy" wire:target="toggleAktif">{{ $bagian->aktif ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                        @if ($bagian->poin_count === 0)
                            <button type="button" class="btn btn-red btn-sm" wire:click="confirmHapus({{ $bagian->id }})" wire:loading.attr="disabled" wire:target="confirmHapus({{ $bagian->id }})">🗑 Hapus</button>
                        @endif
                    </div>
                </div>

                @if ($bukaId === $bagian->id && $f)
                    <div style="margin-top:14px;padding:16px;background:var(--bg);border:1px solid var(--line);border-radius:12px">
                        {{-- Tab --}}
                        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px">
                            @foreach (['dasar' => '⚙️ Dasar & Peran', 'isian' => '🧾 Kolom Isian', 'notula' => '📄 Notula & Variabel', 'pratinjau' => '👁 Pratinjau', 'riwayat' => '🕘 Riwayat'] as $kunciTab => $labelTab)
                                <button type="button" class="btn {{ $tab === $kunciTab ? 'btn-primary' : 'btn-ghost' }} btn-sm" wire:click="setTab('{{ $kunciTab }}')">{{ $labelTab }}</button>
                            @endforeach
                        </div>

                        @error("form.{$bagian->id}.nama")<div style="color:var(--red);font-size:11.5px;margin-bottom:8px">{{ $message }}</div>@enderror
                        @error("form.{$bagian->id}.kolom")<div style="color:var(--red);font-size:11.5px;margin-bottom:8px">{{ $message }}</div>@enderror

                        {{-- TAB 1: DASAR & PERAN --}}
                        @if ($tab === 'dasar')
                            <div class="row2">
                                <div class="field">
                                    <label>Nama Bagian <span class="req">*</span></label>
                                    <input type="text" class="inp filled" wire:model="form.{{ $bagian->id }}.nama">
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
                                    <label>Bukti dukung (PDF)</label>
                                    @if (in_array(\App\Support\MenuBagianKustom::PENGISIAN, $f['penempatan'] ?? []) && in_array(\App\Models\BagianKustom::PERAN_KETUA_TIM, $f['peran'] ?? []))
                                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;padding:8px 0">
                                            <input type="checkbox" wire:model="form.{{ $bagian->id }}.bukti_wajib">
                                            Wajib dilampirkan per poin yang diisi
                                        </label>
                                    @else
                                        <div class="fhint" style="margin:6px 0 0">Bukti PDF hanya tersedia untuk isian di menu Isian Kegiatan oleh Ketua Tim. Di menu lain, isian berupa teks dan kolom saja.</div>
                                    @endif
                                </div>
                            </div>

                        {{-- TAB 2: KOLOM ISIAN --}}
                        @elseif ($tab === 'isian')
                            <div class="info" style="font-size:12px">🧾 Kolom isian membuat tiap poin punya beberapa isian, seperti tabel (mis. <i>Nama Risiko | Level | Mitigasi</i>). <b>Uraian</b> selalu ada sebagai isian utama. Setiap kolom otomatis jadi <b>variabel</b> yang bisa dipakai di format notula, dengan nama seperti tertulis di kotak "Variabel".</div>

                            <div class="field">
                                <label>Label untuk isian Uraian <span style="color:var(--muted);font-weight:500">(opsional, default "Uraian")</span></label>
                                <input type="text" class="inp filled" wire:model.live.debounce.300ms="form.{{ $bagian->id }}.label_uraian" placeholder="mis. Nama Risiko">
                            </div>

                            <div style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.5px;margin:14px 0 8px">Kolom tambahan</div>

                            @forelse (($f['kolom'] ?? []) as $j => $kol)
                                <div style="border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:10px;background:var(--surface-2)">
                                    <div class="row2">
                                        <div class="field" style="margin-bottom:8px">
                                            <label>Label kolom <span class="req">*</span></label>
                                            <input type="text" class="inp filled" wire:model.live.debounce.300ms="form.{{ $bagian->id }}.kolom.{{ $j }}.label" placeholder="mis. Level Risiko">
                                            @error("form.{$bagian->id}.kolom.{$j}.label")<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                                        </div>
                                        <div class="field" style="margin-bottom:8px">
                                            <label>Jenis isian</label>
                                            <select class="inp filled" wire:model.live="form.{{ $bagian->id }}.kolom.{{ $j }}.tipe">
                                                @foreach ($tipeKolom as $nilai => $label)
                                                    <option value="{{ $nilai }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    @if (($kol['tipe'] ?? '') === 'pilihan')
                                        <div class="field" style="margin-bottom:8px">
                                            <label>Pilihan jawaban <span style="color:var(--muted);font-weight:500">(pisahkan dengan koma)</span></label>
                                            <input type="text" class="inp filled" wire:model.live.debounce.300ms="form.{{ $bagian->id }}.kolom.{{ $j }}.opsi_teks" placeholder="mis. Rendah, Sedang, Tinggi">
                                            @error("form.{$bagian->id}.kolom.{$j}.opsi_teks")<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                                        </div>
                                    @endif
                                    @if (($kol['tipe'] ?? '') === 'angka')
                                        @php $kolomContoh = \App\Models\BagianKustom::normalkanKolom(['kunci' => 'contoh', 'label' => 'contoh'] + $kol); @endphp
                                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-bottom:8px">
                                            <div class="field" style="margin-bottom:0">
                                                <label>Satuan</label>
                                                <input type="text" class="inp filled" wire:model.live.debounce.300ms="form.{{ $bagian->id }}.kolom.{{ $j }}.satuan" placeholder="mis. persen, orang">
                                                @error("form.{$bagian->id}.kolom.{$j}.satuan")<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                                            </div>
                                            <div class="field" style="margin-bottom:0">
                                                <label>Angka di belakang koma</label>
                                                <select class="inp filled" wire:model.live="form.{{ $bagian->id }}.kolom.{{ $j }}.desimal">
                                                    <option value="">Tampil apa adanya</option>
                                                    @for ($d = 0; $d <= \App\Support\FormatAngkaKolom::DESIMAL_MAKS; $d++)
                                                        <option value="{{ $d }}">{{ $d }} desimal</option>
                                                    @endfor
                                                </select>
                                            </div>
                                            @if (($kol['desimal'] ?? '') !== '')
                                                <div class="field" style="margin-bottom:0">
                                                    <label>Pemisah desimal</label>
                                                    <select class="inp filled" wire:model.live="form.{{ $bagian->id }}.kolom.{{ $j }}.pemisah">
                                                        @foreach ($pilihanPemisah as $nilai => $label)
                                                            <option value="{{ $nilai }}">{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;align-self:end;padding-bottom:10px">
                                                    <input type="checkbox" wire:model.live="form.{{ $bagian->id }}.kolom.{{ $j }}.ribuan"> Pakai pemisah ribuan
                                                </label>
                                            @endif
                                        </div>
                                        @if ($kolomContoh)
                                            <div class="fhint" style="margin:0 0 8px">Contoh tampilan: <b>{{ \App\Support\TemplateBagianKustom::contohKolom($kolomContoh) }}</b>. Format hanya mengubah tampilan — angka yang sudah tersimpan tidak diubah atau dibulatkan.</div>
                                        @endif
                                    @endif
                                    <div class="row2">
                                        <div class="field" style="margin-bottom:8px">
                                            <label>Mulai berlaku <span style="color:var(--muted);font-weight:500">(kosong = sejak awal)</span></label>
                                            <div style="display:flex;gap:6px">
                                                <select class="inp filled" wire:model="form.{{ $bagian->id }}.kolom.{{ $j }}.mulai_triwulan" style="flex:1">
                                                    <option value="">—</option>
                                                    @foreach (['I', 'II', 'III', 'IV'] as $n => $romawi)
                                                        <option value="{{ $n + 1 }}">TW {{ $romawi }}</option>
                                                    @endforeach
                                                </select>
                                                <input type="number" class="inp filled" style="flex:1" min="2020" max="2100" wire:model="form.{{ $bagian->id }}.kolom.{{ $j }}.mulai_tahun" placeholder="Tahun">
                                            </div>
                                            @error("form.{$bagian->id}.kolom.{$j}.mulai_triwulan")<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                                            @error("form.{$bagian->id}.kolom.{$j}.mulai_tahun")<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                                            <div class="fhint" style="margin-top:4px">Sebelum periode ini kolom tidak tampil dan tidak diwajibkan, jadi isian lama tidak dianggap kurang.</div>
                                        </div>
                                    </div>
                                    @if (! ($kol['aktif'] ?? true))
                                        <div class="info warn" style="font-size:11.5px;margin-bottom:8px">Kolom nonaktif: tidak lagi tampil di formulir isian. Data yang sudah tersimpan tetap ada, tetap tampil di Verifikasi, dan tetap tercetak di notula bila variabelnya dipakai.</div>
                                    @endif
                                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between">
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">
                                            <input type="checkbox" wire:model="form.{{ $bagian->id }}.kolom.{{ $j }}.wajib"> Wajib diisi
                                        </label>
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">
                                            <input type="checkbox" wire:model.live="form.{{ $bagian->id }}.kolom.{{ $j }}.aktif"> Aktif
                                        </label>
                                        <span style="font-size:11px;color:var(--muted)">Variabel: <code>{{ $kol['kunci'] ?? '' }}</code>@if (! ($kol['kunci'] ?? null)) <i>(dibuat otomatis saat disimpan)</i>@endif</span>
                                        <div class="btn-row" style="margin-top:0;gap:4px">
                                            <button type="button" class="btn btn-ghost btn-sm" wire:click="naikkanKolom({{ $bagian->id }}, {{ $j }})" @disabled($j === 0)>↑</button>
                                            <button type="button" class="btn btn-ghost btn-sm" wire:click="turunkanKolom({{ $bagian->id }}, {{ $j }})" @disabled($j === count($f['kolom']) - 1)>↓</button>
                                            <button type="button" class="btn btn-red btn-sm" wire:click="hapusKolom({{ $bagian->id }}, {{ $j }})">🗑</button>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <p style="color:var(--muted);font-size:12.5px;margin:0 0 8px">Belum ada kolom tambahan — isian hanya berupa Uraian.</p>
                            @endforelse

                            <button type="button" class="btn btn-ghost btn-sm" wire:click="tambahKolom({{ $bagian->id }})">＋ Tambah kolom</button>

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
                                    @foreach ($kolomTampil as $kol)
                                        <div class="field" style="margin-bottom:8px">
                                            <label style="font-size:11px">{{ $kol['label'] }} @if ($kol['wajib'])<span class="req">*</span>@endif</label>
                                            <div class="inp filled" style="color:var(--muted);font-style:italic">{{ $kol['tipe'] === 'pilihan' ? 'Pilih: '.implode(' / ', $kol['opsi']) : ($kol['tipe'] === 'angka' ? 'Isi angka…' : 'Isi teks…') }}</div>
                                        </div>
                                    @endforeach
                                    @if (in_array(\App\Support\MenuBagianKustom::PENGISIAN, $f['penempatan'] ?? []) && in_array(\App\Models\BagianKustom::PERAN_KETUA_TIM, $f['peran'] ?? []) && ($f['bukti_wajib'] ?? false))
                                        <div class="filechip ok" style="font-size:11px"><span class="nm">📄 contoh-bukti-dukung.pdf</span></div>
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

    <x-confirm-modal :show="$pendingHapusId !== null" title="Hapus Bagian?" :message="'Hapus bagian \''.$pendingHapusNama.'\'?'">
        <button type="button" class="btn btn-ghost" wire:click="batalkanHapus" wire:loading.attr="disabled" wire:target="hapus">Batal</button>
        <button type="button" class="btn btn-red" wire:click="hapus" wire:loading.attr="disabled" wire:target="hapus">
            <span wire:loading.remove wire:target="hapus">Hapus</span>
            <span wire:loading wire:target="hapus"><i class="spin"></i> Menghapus…</span>
        </button>
    </x-confirm-modal>
</div>
