<div>
    @php
        $R = \App\Support\RegistriVariabel::class;
        $lencanaStatus = ['aktif' => 'b-approve', 'usang' => 'b-tunggu', 'nonaktif' => 'b-draft'];
        $labelStatus = ['aktif' => '✓ Aktif', 'usang' => 'Historis', 'nonaktif' => 'Nonaktif'];
        $labelJenisPendek = ['sistem' => 'Sistem', 'turunan' => 'Turunan', 'penyajian' => 'Khusus template', 'kustom' => 'Kolom Bagian Kustom', 'definisi' => 'Definisi bersama'];
    @endphp

    <div class="page-head">
        <div class="page-title">Katalog Variabel</div>
        <div class="page-sub">Kamus pusat variabel SIPINTER: nilai yang diambil sistem untuk template notula, variabel format notula Bagian Kustom, variabel kolom setiap bagian, dan definisi bersama untuk kolom isian.</div>
    </div>

    @if (session('status-variabel'))
        <div class="badge b-approve" role="status" style="display:block;margin-bottom:14px;white-space:normal">{{ session('status-variabel') }}</div>
    @endif

    <details class="info" style="font-size:12.5px">
        <summary style="cursor:pointer;font-weight:700">ℹ️ Cara memilih & memakai variabel</summary>
        <ul style="margin:8px 0 0;padding-left:18px;line-height:1.6">
            <li><b>Sistem / Turunan</b> — nilainya diambil atau dihitung sistem dari data SIPINTER; tidak perlu diisi manual dan tidak bisa diubah di sini. Pakai di template sesuai kolom "Dipakai di".</li>
            <li><b>Template Bagian I Notula (.docx)</b> — tulis <code>@{{kode}}</code> di berkas Word. Variabel berlingkup "per IKU" hanya terisi di dalam <code>@{{iku_blok}}</code> … <code>@{{/iku_blok}}</code>. Periksa berkas di Verifikasi &amp; Notula → Template Notula → "Periksa template".</li>
            <li><b>Format baris notula Bagian Kustom</b> — tulis <code>{kode}</code>, mis. <code>{kode_iku}: {teks}</code>. Selain variabel sistem, setiap kolom bagian itu menjadi variabel dengan kodenya sendiri.</li>
            <li><b>Definisi bersama</b> — dibuat di halaman ini, lalu ditautkan ke kolom di Bagian Kustom → Struktur Isian → "Variabel katalog". Kolom mengikuti jenis, pilihan, format, dan batasnya; label kolom tetap bebas per bagian dan nilai tetap tersimpan per isian.</li>
            <li><b>Kode adalah identitas tetap.</b> Mengubah nama atau keterangan tidak memutus kolom atau template yang memakainya. Variabel tidak dihapus — cukup dinonaktifkan atau diberi pengganti; data lama tetap terbaca.</li>
            <li>Placeholder yang tidak dikenal <b>tidak pernah</b> diganti kosong diam-diam: tercetak apa adanya dan diperingatkan saat template disimpan/diperiksa.</li>
        </ul>
    </details>

    <div class="card">
        <div class="sec">
            <span>Daftar Variabel</span>
            <span style="margin-left:auto;font-size:12px;color:var(--muted);font-weight:500" data-uji="jumlah-variabel">
                @if ($adaFilter)
                    Menampilkan {{ $daftar->count() }} dari {{ $jumlahTotal }} variabel (sesuai pencarian/filter)
                @else
                    {{ $jumlahTotal }} variabel
                @endif
            </span>
        </div>

        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;font-size:11.5px">
            @foreach ($R::JENIS as $kunci => $label)
                <span class="badge b-draft" title="{{ $label }}">{{ $labelJenisPendek[$kunci] }}: {{ $jumlahPerJenis[$kunci] ?? 0 }}</span>
            @endforeach
            @foreach (['usang' => 'Historis', 'nonaktif' => 'Nonaktif'] as $kunci => $label)
                @if (($jumlahPerStatus[$kunci] ?? 0) > 0)
                    <span class="badge {{ $lencanaStatus[$kunci] }}">{{ $label }}: {{ $jumlahPerStatus[$kunci] }}</span>
                @endif
            @endforeach
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-bottom:12px">
            <div class="field" style="margin-bottom:0;grid-column:span 2;min-width:0">
                <label for="cari-variabel">Cari</label>
                <input id="cari-variabel" type="search" class="inp filled" wire:model.live.debounce.300ms="cari" placeholder="Kode, nama, deskripsi, atau nama bagian">
            </div>
            <div class="field" style="margin-bottom:0">
                <label for="f-kategori">Kategori</label>
                <select id="f-kategori" class="inp filled" wire:model.live="filterKategori">
                    <option value="">Semua kategori</option>
                    @foreach ($R::KATEGORI as $nilai => $label)
                        <option value="{{ $nilai }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="margin-bottom:0">
                <label for="f-jenis">Asal nilai</label>
                <select id="f-jenis" class="inp filled" wire:model.live="filterJenis">
                    <option value="">Semua asal</option>
                    @foreach ($R::JENIS as $nilai => $label)
                        <option value="{{ $nilai }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="margin-bottom:0">
                <label for="f-tipe">Jenis data</label>
                <select id="f-tipe" class="inp filled" wire:model.live="filterTipe">
                    <option value="">Semua jenis data</option>
                    @foreach ($R::TIPE as $nilai => $label)
                        <option value="{{ $nilai }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="margin-bottom:0">
                <label for="f-konteks">Dipakai di</label>
                <select id="f-konteks" class="inp filled" wire:model.live="filterKonteks">
                    <option value="">Semua konteks</option>
                    @foreach ($R::KONTEKS as $nilai => $k)
                        <option value="{{ $nilai }}">{{ $k['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="margin-bottom:0">
                <label for="status-variabel">Status</label>
                <select id="status-variabel" class="inp filled" wire:model.live="filterStatus">
                    <option value="">Semua status</option>
                    @foreach ($R::STATUS as $nilai => $label)
                        <option value="{{ $nilai }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="btn-row" style="margin:0 0 12px">
            <button type="button" class="btn btn-primary btn-sm" wire:click="baru" wire:loading.attr="disabled" wire:target="baru">＋ Definisi bersama baru</button>
            @if ($adaFilter)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="resetFilter">Hapus filter</button>
            @endif
        </div>

        @if ($editId !== null)
            <div style="padding:14px;border:1px solid var(--line);border-radius:12px;background:var(--bg);margin-bottom:14px">
                <div style="font-weight:700;margin-bottom:4px">{{ $editId === 0 ? 'Definisi bersama baru' : 'Ubah definisi "'.($form['nama'] ?? '').'"' }}</div>
                <div class="fhint" style="margin:0 0 10px">Definisi bersama dipakai kolom isian Bagian Kustom (nilainya diisi pengguna). Nilai yang sudah tersedia di sistem — kode/indikator/sasaran IKU, periode — tidak perlu dibuat di sini; pakai variabel sistem di format notula.</div>
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
                        <label for="v-kategori">Kategori</label>
                        <select id="v-kategori" class="inp filled" wire:model.live="form.kategori">
                            @foreach ($kategoriDefinisi as $nilai => $label)
                                <option value="{{ $nilai }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('form.kategori')<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="field">
                    <label>Sumber data</label>
                    <div class="inp filled" style="color:var(--muted)">{{ \App\Models\VariabelKatalog::SUMBER['isian'] }}</div>
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
                    <label for="v-ket">Deskripsi <span style="color:var(--muted);font-weight:500">(opsional)</span></label>
                    <textarea id="v-ket" class="inp filled" style="height:auto;display:block" rows="2" wire:model.blur="form.keterangan" placeholder="Apa arti nilai ini?"></textarea>
                </div>
                <div class="row2">
                    <div class="field">
                        <label for="v-contoh">Contoh nilai <span style="color:var(--muted);font-weight:500">(opsional, jangan data sungguhan)</span></label>
                        <input id="v-contoh" type="text" class="inp filled" wire:model.blur="form.contoh" placeholder="kosong = dibuat otomatis dari jenisnya">
                        @error('form.contoh')<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="v-pengganti">Diganti oleh <span style="color:var(--muted);font-weight:500">(opsional)</span></label>
                        <select id="v-pengganti" class="inp filled" wire:model.live="form.pengganti_kode">
                            <option value="">— Tidak ada pengganti —</option>
                            @foreach ($definisiAktif as $kode => $nama)
                                @if ($kode !== ($form['kode'] ?? null))
                                    <option value="{{ $kode }}">{{ $nama }} ({{ $kode }})</option>
                                @endif
                            @endforeach
                        </select>
                        @error('form.pengganti_kode')<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                        <div class="fhint" style="margin-top:4px">Diisi bila variabel ini tidak disarankan lagi. Kolom lama tetap memakai variabel ini; datanya tidak dipindahkan otomatis.</div>
                    </div>
                </div>
                <div class="field">
                    <label for="v-catatan">Aturan penggunaan & keterbatasan <span style="color:var(--muted);font-weight:500">(opsional)</span></label>
                    <textarea id="v-catatan" class="inp filled" style="height:auto;display:block" rows="2" wire:model.blur="form.catatan"></textarea>
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

        @forelse ($kelompok as $kategori => $isi)
            <div style="margin-top:6px" wire:key="kategori-{{ $kategori }}">
                <div style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.5px;padding:10px 0 4px;border-bottom:1px solid var(--line)">
                    {{ $R::KATEGORI[$kategori] }} <span style="font-weight:500">· {{ count($isi) }}</span>
                </div>
                @foreach ($isi as $v)
                    <div style="border-bottom:1px solid var(--line2);padding:9px 0" wire:key="var-{{ $v['id'] }}" data-uji="variabel-{{ $v['id'] }}">
                        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;justify-content:space-between">
                            <div style="min-width:220px;flex:1">
                                <div style="font-weight:700;font-size:13.5px">{{ $v['nama'] }}
                                    @foreach ($v['konteks'] as $konteks)
                                        @if ($tulisan = $R::tulisan($v['kode'], $konteks))
                                            <code style="font-size:11px;font-weight:500;color:var(--muted)">{{ $tulisan }}</code>
                                        @endif
                                    @endforeach
                                    @if ($v['jenis'] === 'definisi')
                                        <code style="font-size:11px;font-weight:500;color:var(--muted)">{{ $v['kode'] }}</code>
                                    @endif
                                </div>
                                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px">
                                    <span class="badge {{ $lencanaStatus[$v['status']] }}">{{ $labelStatus[$v['status']] }}</span>
                                    <span class="badge b-verif">{{ $labelJenisPendek[$v['jenis']] }}</span>
                                    <span class="badge b-draft">{{ $R::TIPE[$v['tipe']] ?? $v['tipe'] }}</span>
                                    @if ($v['bagian'])<span class="badge b-draft">Bagian: {{ $v['bagian'] }}</span>@endif
                                    @if ($v['lingkup'] && $v['lingkup'] !== 'global')<span class="badge b-draft">per {{ $v['lingkup'] === 'iku_blok' ? 'IKU' : ($v['lingkup'] === 'ro_row' ? 'rincian output' : 'proksi') }}</span>@endif
                                    @if ($v['jenis'] === 'definisi')<span class="badge b-draft">dipakai {{ count($v['pemakaian']) }} kolom</span>@endif
                                    @if ($v['pengganti'])<span class="badge b-tunggu">ganti dengan {{ $v['pengganti'] }}</span>@endif
                                </div>
                                <div style="font-size:12px;color:var(--muted);margin-top:4px">{{ $v['deskripsi'] }}</div>
                            </div>
                            <div class="btn-row" style="margin-top:0;flex-wrap:wrap">
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="bukaDetail('{{ $v['id'] }}')" aria-expanded="{{ $detail === $v['id'] ? 'true' : 'false' }}">{{ $detail === $v['id'] ? 'Tutup' : 'Detail' }}</button>
                                @if ($v['jenis'] === 'definisi')
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="ubah({{ $v['model_id'] }})">✎ Ubah</button>
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleAktif({{ $v['model_id'] }})" @if ($v['aktif_model']) wire:confirm="Nonaktifkan variabel &quot;{{ $v['nama'] }}&quot;? Kolom yang sudah memakainya tetap berfungsi dan datanya tetap ada; variabel ini tidak bisa dipilih untuk kolom baru." @endif>{{ $v['aktif_model'] ? 'Nonaktifkan' : 'Aktifkan kembali' }}</button>
                                @endif
                            </div>
                        </div>

                        @if ($detail === $v['id'])
                            <table style="width:100%;border-collapse:collapse;font-size:12.5px;margin-top:8px" data-uji="detail-variabel">
                                @foreach ([
                                    'Kode' => $v['kode'],
                                    'Kategori' => $R::KATEGORI[$v['kategori']] ?? $v['kategori'],
                                    'Asal nilai' => $R::JENIS[$v['jenis']],
                                    'Jenis data' => $R::TIPE[$v['tipe']] ?? $v['tipe'],
                                    'Sumber data' => $v['sumber'],
                                    'Aturan pengambilan & format' => $v['aturan'],
                                    'Bila kosong / nol' => $v['kosong'],
                                    'Contoh nilai' => $v['contoh'],
                                    'Letak di template' => $v['lingkup'] ? $R::LINGKUP[$v['lingkup']] : null,
                                    'Penutup blok' => $v['penutup'] ? $R::tulisan($v['penutup'], $R::KONTEKS_NOTULA_DOCX) : null,
                                    'Status' => $R::STATUS[$v['status']],
                                    'Pengganti' => $v['pengganti'],
                                    'Catatan & keterbatasan' => $v['catatan'],
                                ] as $label => $nilai)
                                    @if (filled($nilai))
                                        <tr>
                                            <th style="text-align:left;vertical-align:top;padding:4px 8px 4px 0;color:var(--muted);font-weight:600;width:190px">{{ $label }}</th>
                                            <td style="padding:4px 0;white-space:pre-line">{{ $nilai }}</td>
                                        </tr>
                                    @endif
                                @endforeach
                                <tr>
                                    <th style="text-align:left;vertical-align:top;padding:4px 8px 4px 0;color:var(--muted);font-weight:600">Dipakai di</th>
                                    <td style="padding:4px 0">
                                        @foreach ($v['konteks'] as $konteks)
                                            <div>{{ $R::KONTEKS[$konteks]['label'] }}@if ($tulisan = $R::tulisan($v['kode'], $konteks)) — tulis <code>{{ $tulisan }}</code>@endif <span style="color:var(--muted)">({{ $R::KONTEKS[$konteks]['tempat'] }})</span></div>
                                        @endforeach
                                    </td>
                                </tr>
                                @if ($v['jenis'] === 'definisi')
                                    <tr>
                                        <th style="text-align:left;vertical-align:top;padding:4px 8px 4px 0;color:var(--muted);font-weight:600">Kolom yang memakai</th>
                                        <td style="padding:4px 0">
                                            @forelse ($v['pemakaian'] as $p)
                                                <div>Bagian "{{ $p['bagian'] }}" → kolom "{{ $p['kolom'] }}"{{ $p['aktif'] ? '' : ' (nonaktif)' }}</div>
                                            @empty
                                                <span style="color:var(--muted)">Belum dipakai kolom mana pun.</span>
                                            @endforelse
                                        </td>
                                    </tr>
                                @endif
                            </table>
                        @endif
                    </div>
                @endforeach
            </div>
        @empty
            <p style="color:var(--muted);font-size:13px">Tidak ada variabel yang cocok dengan pencarian/filter. <button type="button" class="btn btn-ghost btn-sm" wire:click="resetFilter">Hapus filter</button></p>
        @endforelse
    </div>
</div>
