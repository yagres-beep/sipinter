<div>
    @php $adaBagian = $bagianList->isNotEmpty() || $bagianBaca->isNotEmpty(); @endphp
    @if ($menu === 'isian_bagian_kustom')
        <div class="page-head">
            <div class="page-title">Isian Bagian Kustom</div>
            <div class="page-sub">Isi bagian tambahan untuk peran {{ $namaPeran }}. Pilih tahun dan bulan, lalu isi poin per IKU. Bagian yang tampil di sini diatur di Data Master &amp; Konfigurasi → Bagian Kustom.</div>
        </div>
    @elseif ($adaBagian)
        <div class="sec" style="margin-top:24px"><span class="n">🧩</span><span>Bagian Kustom</span></div>
        <div class="fhint" style="margin-bottom:12px">Bagian tambahan yang ditempatkan di menu {{ $namaMenu }} untuk peran {{ $namaPeran }}. Pilih tahun dan bulan, lalu isi per IKU.</div>
    @endif

    @if (! $adaBagian && $menu !== 'isian_bagian_kustom')
        {{-- Tidak ada bagian kustom yang ditempatkan di menu ini: tidak tampil apa pun. --}}
    @else
    @php $halamanSendiri = $menu === 'isian_bagian_kustom'; @endphp
    {{-- Di halaman Isian Bagian Kustom sendiri, pemilih periode + tombol lompat ke tiap
         bagian lengket SATU UNIT di bawah .page-head (pola Isian Kegiatan — navigasiBagian
         di app.js & .bilah-lengket di sipinter.css). Saat disisipkan di bawah halaman menu
         lain tetap tampil biasa (tidak lengket), supaya tidak bertumpuk dengan halaman itu. --}}
    @if ($halamanSendiri)
    <div class="bilah-lengket" x-data="navigasiBagian" :class="{ menempel: menempel }">
        <div class="period-banner">
            <span class="pb-ico">📅</span>
            <div>
                <div class="pb-lbl">Periode Pengisian</div>
                <div class="pb-val">
                    <select wire:model.live="bulan" aria-label="Bulan" style="border:none;background:transparent;font-weight:700;color:var(--ink);font-size:14px">
                        @foreach ($namaBulan as $nomor => $nama)
                            <option value="{{ $nomor }}">{{ $nama }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="tahun" aria-label="Tahun" style="border:none;background:transparent;font-weight:700;color:var(--ink);font-size:14px">
                        @foreach ($tahunPilihan as $t)
                            <option value="{{ $t }}">{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @if ($periodeAda)
                <span class="pb-tag">TW {{ ['I', 'II', 'III', 'IV'][intdiv((int) $bulan - 1, 3)] }}</span>
            @else
                <span class="badge b-tunggu" style="margin-left:auto">⚠ Periode belum tersedia</span>
            @endif
        </div>
        @if ($adaBagian)
            <nav class="nav-bagian" x-ref="daftar" aria-label="Lompat ke bagian isian">
                @foreach ($bagianList->concat($bagianBaca) as $bagian)
                    <button type="button" class="nb-item" data-bagian="bagian-kustom-{{ $bagian->id }}" title="{{ $bagian->nama }}" wire:key="nav-bagian-kustom-{{ $bagian->id }}"
                        :class="{ on: aktif === 'bagian-kustom-{{ $bagian->id }}' }" :aria-current="aktif === 'bagian-kustom-{{ $bagian->id }}' ? 'true' : null"
                        @click="lompatKe('bagian-kustom-{{ $bagian->id }}')">
                        <span class="nb-n">{{ $loop->iteration }}</span><span class="nb-teks">{{ $bagian->nama }}</span>
                    </button>
                @endforeach
            </nav>
        @endif
    </div>
    @unless ($periodeAda)
        <div class="info warn">⚠️ Periode {{ $namaBulan[$bulan] }} {{ $tahun }} belum tersedia di sistem, jadi isian belum bisa disimpan.</div>
    @endunless
    @else
    <div class="card" style="margin-bottom:16px">
        <div class="row2">
            <div class="field" style="margin-bottom:0">
                <label>Tahun</label>
                <select class="inp filled" wire:model.live="tahun">
                    @foreach ($tahunPilihan as $t)
                        <option value="{{ $t }}">{{ $t }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="margin-bottom:0">
                <label>Bulan</label>
                <select class="inp filled" wire:model.live="bulan">
                    @foreach ($namaBulan as $nomor => $nama)
                        <option value="{{ $nomor }}">{{ $nama }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        @unless ($periodeAda)
            <div class="info warn" style="margin-top:12px">⚠️ Periode {{ $namaBulan[$bulan] }} {{ $tahun }} belum tersedia di sistem, jadi isian belum bisa disimpan.</div>
        @endunless
    </div>
    @endif

    @foreach ($bagianList as $bagian)
        @php
            $aturan = $bagian->aturanBuktiDi($menu);
            $pakaiBukti = \App\Support\AturanBuktiBagianKustom::dipakai($aturan);
            $kelompokBerlaku = $bagian->kelompokKolomBerlaku($tahun, \App\Models\BagianKustom::triwulanDariBulan($bulan));
            $kolomBerlaku = collect($kelompokBerlaku)->flatMap(fn ($g) => $g['kolom'])->values()->all();
            $terbuka = $objekTerbuka[$bagian->id] ?? collect();
            $tabel = $bagian->tampilanIsian() === 'tabel';
        @endphp
        <div class="card" style="margin-bottom:16px" wire:key="bagian-{{ $bagian->id }}">
            <div class="sec" @if ($halamanSendiri) id="bagian-kustom-{{ $bagian->id }}" style="scroll-margin-top:var(--offset-lengket, 200px)" @endif><span class="n">🧩</span><span>{{ $bagian->nama }}</span></div>
            @if ($bagian->deskripsi)
                <div class="info">ℹ️ {{ $bagian->deskripsi }}</div>
            @endif
            @if ($bagian->frekuensi_wajib === 'setiap_bulan')
                <div class="info warn">⚠️ Minimal satu poin wajib diisi setiap bulan.</div>
            @elseif ($bagian->frekuensi_wajib === 'akhir_triwulan')
                <div class="info warn">⚠️ Minimal satu poin wajib diisi pada bulan terakhir triwulan.</div>
            @endif

            @if (session()->has('status-'.$bagian->id))
                <div class="badge b-approve" role="status" style="display:block;margin-bottom:12px;white-space:normal">{{ session('status-'.$bagian->id) }}</div>
            @endif
            @error("baris.{$bagian->id}")
                <div role="alert" style="color:var(--red);font-size:11.5px;margin-bottom:10px">{{ $message }}</div>
            @enderror

            {{-- Relasi antarperiode: isian periode sebelumnya (tahun yang sama) yang belum ada di periode ini. --}}
            @if ($terbuka->isNotEmpty())
                <details class="lanjutan-periode" style="margin-bottom:12px;border:1px dashed var(--line);border-radius:10px;padding:10px 12px">
                    <summary style="cursor:pointer;font-size:12.5px;font-weight:700">↪ {{ $terbuka->count() }} isian periode sebelumnya bisa dilanjutkan ke {{ $namaBulan[$bulan] }} {{ $tahun }}</summary>
                    <div class="fhint" style="margin:6px 0 8px">Melanjutkan membuat poin baru di periode ini dengan isi awal dari poin sebelumnya (bisa diubah sebelum disimpan). Poin lama, bukti dukungnya, dan riwayatnya tidak diubah; bukti tidak disalin.</div>
                    @foreach ($terbuka as $sumber)
                        <div style="display:flex;gap:10px;align-items:flex-start;justify-content:space-between;padding:6px 0;border-top:1px solid var(--line2);font-size:12.5px" wire:key="terbuka-{{ $bagian->id }}-{{ $sumber->id }}">
                            <div style="min-width:0">
                                <span class="badge b-draft">{{ \App\Support\LanjutanBagianKustom::labelPeriode($sumber->periode) }}</span>
                                {{ \Illuminate\Support\Str::limit($sumber->teks, 140) }}
                                @if ($sumber->berkas->isNotEmpty())<span style="color:var(--muted)">· {{ $sumber->berkas->count() }} bukti</span>@endif
                            </div>
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="lanjutkan({{ $bagian->id }}, {{ $sumber->id }})" wire:loading.attr="disabled" wire:target="lanjutkan({{ $bagian->id }}, {{ $sumber->id }})" @disabled(! $periodeAda)>Lanjutkan</button>
                        </div>
                    @endforeach
                    @if ($terbuka->count() > 1)
                        <button type="button" class="btn btn-ghost btn-sm" style="margin-top:6px" wire:click="lanjutkanSemua({{ $bagian->id }})" wire:loading.attr="disabled" wire:target="lanjutkanSemua" @disabled(! $periodeAda)>Lanjutkan semua</button>
                    @endif
                </details>
            @endif

            @if ($tabel)
                {{-- Bentuk tabel: satu baris per poin, satu kolom per kolom isian. Bergulir mendatar di layar kecil. --}}
                {{-- contain:inline-size: lebar wadah tidak ikut melebar mengikuti tabel, jadi
                     halaman tidak bergeser di layar kecil — tabel menggulir di dalam wadahnya. --}}
                <div class="tabel-gulir" style="overflow-x:auto;-webkit-overflow-scrolling:touch;margin-bottom:10px;max-width:100%;min-width:0;contain:inline-size;position:relative">
                    <table class="tabel-isian-bagian-kustom" style="width:100%;border-collapse:collapse;font-size:12.5px">
                        <thead>
                            <tr style="text-align:left;color:var(--muted);vertical-align:bottom">
                                <th scope="col" style="padding:6px;border-bottom:1px solid var(--line)">No</th>
                                <th scope="col" style="padding:6px;border-bottom:1px solid var(--line);min-width:180px">IKU <span class="req">*</span></th>
                                <th scope="col" style="padding:6px;border-bottom:1px solid var(--line);min-width:220px">{{ $bagian->labelUraianEfektif() }} <span class="req">*</span></th>
                                @foreach ($kolomBerlaku as $k)
                                    <th scope="col" style="padding:6px;border-bottom:1px solid var(--line)" title="{{ $k['petunjuk'] }}">{{ $k['label'] }}@if ($k['wajib']) <span class="req">*</span>@endif @if ($k['satuan'])<span style="font-weight:500">({{ $k['satuan'] }})</span>@endif</th>
                                @endforeach
                                @if ($pakaiBukti)<th scope="col" style="padding:6px;border-bottom:1px solid var(--line);min-width:200px">Bukti dukung</th>@endif
                                <th scope="col" style="padding:6px;border-bottom:1px solid var(--line)"><span class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)">Aksi</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($baris[$bagian->id] ?? [] as $i => $row)
                                <tr wire:key="baris-{{ $bagian->id }}-{{ $i }}" style="vertical-align:top">
                                    <td style="padding:6px;border-bottom:1px solid var(--line2)">{{ $i + 1 }}
                                        @if (! empty($row['lanjutan_dari_id']))<div class="badge b-draft" style="font-size:10px" title="Lanjutan dari periode sebelumnya">↪ {{ \App\Support\LanjutanBagianKustom::labelPeriode($sumberLanjutan[(int) $row['lanjutan_dari_id']]?->periode ?? null) }}</div>@endif
                                    </td>
                                    <td style="padding:6px;border-bottom:1px solid var(--line2)">
                                        <select class="inp filled" aria-label="IKU poin {{ $i + 1 }}" wire:model="baris.{{ $bagian->id }}.{{ $i }}.iku_id">
                                            <option value="">— pilih IKU —</option>
                                            @foreach ($ikuList as $iku)
                                                <option value="{{ $iku->id }}">{{ $iku->kode }} — {{ \Illuminate\Support\Str::limit($iku->indikator, 60) }}</option>
                                            @endforeach
                                        </select>
                                        @error("baris.{$bagian->id}.{$i}.iku_id")<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                                    </td>
                                    <td style="padding:6px;border-bottom:1px solid var(--line2)">
                                        <textarea class="inp filled" style="height:auto;display:block" rows="2" aria-label="{{ $bagian->labelUraianEfektif() }} poin {{ $i + 1 }}" wire:model.blur="baris.{{ $bagian->id }}.{{ $i }}.teks"></textarea>
                                        @error("baris.{$bagian->id}.{$i}.teks")<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                                    </td>
                                    @foreach ($kolomBerlaku as $k)
                                        <td style="padding:6px;border-bottom:1px solid var(--line2)">
                                            @include('livewire.partials._isian-kolom-bagian-kustom', ['kelompok' => [['subbagian' => null, 'kolom' => [$k]]], 'model' => "baris.{$bagian->id}.{$i}.kolom", 'ringkas' => true])
                                        </td>
                                    @endforeach
                                    @if ($pakaiBukti)
                                        <td style="padding:6px;border-bottom:1px solid var(--line2)">
                                            @include('livewire.partials._bukti-isian-bagian-kustom', ['bagianId' => $bagian->id, 'i' => $i, 'aturan' => $aturan, 'poinId' => $row['id'] ?? null, 'tersimpan' => filled($row['id'] ?? null) ? ($buktiTersimpan[(int) $row['id']] ?? collect()) : collect(), 'baru' => $bukti[$bagian->id][$i] ?? [], 'ringkas' => true])
                                        </td>
                                    @endif
                                    <td style="padding:6px;border-bottom:1px solid var(--line2)">
                                        <button type="button" class="btn btn-red btn-sm" aria-label="Hapus poin {{ $i + 1 }}" wire:click="hapusBaris({{ $bagian->id }}, {{ $i }})" wire:loading.attr="disabled" wire:target="hapusBaris({{ $bagian->id }}, {{ $i }})" @if (filled($row['id'] ?? null)) wire:confirm="Hapus poin {{ $i + 1 }} dari daftar? Poin baru benar-benar terhapus setelah Simpan; poin yang punya bukti atau sudah dilanjutkan tidak dihapus." @endif>🗑</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($pakaiBukti)
                    <div class="fhint" style="margin:-4px 0 10px">Bukti dukung: {{ \App\Support\AturanBuktiBagianKustom::ringkasan($aturan) }}@if ($aturan['petunjuk']) — {{ $aturan['petunjuk'] }}@endif</div>
                @endif
            @else
                @foreach ($baris[$bagian->id] ?? [] as $i => $row)
                    <div class="poin-single" wire:key="baris-{{ $bagian->id }}-{{ $i }}">
                        <span class="k-num stat-in">Poin {{ $i + 1 }}</span>
                        @if (! empty($row['lanjutan_dari_id']))
                            <span class="badge b-draft" style="margin-left:6px" title="Poin ini melanjutkan isian periode sebelumnya">↪ Lanjutan dari {{ \App\Support\LanjutanBagianKustom::labelPeriode($sumberLanjutan[(int) $row['lanjutan_dari_id']]?->periode ?? null) }}</span>
                        @endif
                        <button type="button" class="btn btn-red btn-sm" style="position:absolute;top:8px;right:8px" aria-label="Hapus poin {{ $i + 1 }}" wire:click="hapusBaris({{ $bagian->id }}, {{ $i }})" wire:loading.attr="disabled" wire:target="hapusBaris({{ $bagian->id }}, {{ $i }})" @if (filled($row['id'] ?? null)) wire:confirm="Hapus poin {{ $i + 1 }} dari daftar? Poin baru benar-benar terhapus setelah Simpan; poin yang punya bukti atau sudah dilanjutkan tidak dihapus." @endif>🗑</button>

                        <div class="field">
                            <label for="iku-{{ $bagian->id }}-{{ $i }}">IKU <span class="req">*</span></label>
                            <select id="iku-{{ $bagian->id }}-{{ $i }}" class="inp filled" wire:model="baris.{{ $bagian->id }}.{{ $i }}.iku_id">
                                <option value="">— pilih IKU —</option>
                                @foreach ($ikuList as $iku)
                                    <option value="{{ $iku->id }}">{{ $iku->kode }} — {{ \Illuminate\Support\Str::limit($iku->indikator, 80) }}</option>
                                @endforeach
                            </select>
                            @error("baris.{$bagian->id}.{$i}.iku_id")
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="field">
                            <label for="teks-{{ $bagian->id }}-{{ $i }}">{{ $bagian->labelUraianEfektif() }} <span class="req">*</span></label>
                            <textarea id="teks-{{ $bagian->id }}-{{ $i }}" class="inp filled" style="height:auto;display:block" rows="2" wire:model.blur="baris.{{ $bagian->id }}.{{ $i }}.teks" placeholder="Uraikan poin {{ $bagian->nama }} ini..."></textarea>
                            @error("baris.{$bagian->id}.{$i}.teks")
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                        </div>

                        @include('livewire.partials._isian-kolom-bagian-kustom', [
                            'kelompok' => $kelompokBerlaku,
                            'model' => "baris.{$bagian->id}.{$i}.kolom",
                        ])

                        @if ($pakaiBukti)
                            @include('livewire.partials._bukti-isian-bagian-kustom', ['bagianId' => $bagian->id, 'i' => $i, 'aturan' => $aturan, 'poinId' => $row['id'] ?? null, 'tersimpan' => filled($row['id'] ?? null) ? ($buktiTersimpan[(int) $row['id']] ?? collect()) : collect(), 'baru' => $bukti[$bagian->id][$i] ?? []])
                        @endif
                    </div>
                @endforeach
            @endif

            <div class="btn-row" style="flex-wrap:wrap">
                <button type="button" class="btn btn-ghost btn-sm" wire:click="tambahBaris({{ $bagian->id }})" wire:loading.attr="disabled" wire:target="tambahBaris({{ $bagian->id }})" @disabled(! $periodeAda)>＋ Tambah Poin</button>
                <button type="button" class="btn btn-primary btn-sm" wire:click="simpan({{ $bagian->id }})" wire:loading.attr="disabled" wire:target="simpan({{ $bagian->id }})" @disabled(! $periodeAda)>
                    <span wire:loading.remove wire:target="simpan({{ $bagian->id }})">💾 Simpan {{ $bagian->nama }}</span>
                    <span wire:loading wire:target="simpan({{ $bagian->id }})"><i class="spin"></i> Menyimpan…</span>
                </button>
            </div>

            @if (($tersimpanPeriode[$bagian->id] ?? collect())->isNotEmpty())
                <details style="margin-top:12px">
                    <summary style="cursor:pointer;font-size:12.5px;font-weight:700">Isian tersimpan {{ $namaBulan[$bulan] }} {{ $tahun }} ({{ \App\Models\BagianKustom::TAMPILAN_BACA[$bagian->tampilanBaca()] }})</summary>
                    <div style="margin-top:8px">
                        @include('livewire.partials._baca-isian-bagian-kustom', ['bagian' => $bagian, 'daftar' => $tersimpanPeriode[$bagian->id], 'kolomDitampilkan' => $kolomBerlaku])
                    </div>
                </details>
            @endif
        </div>
    @endforeach

    {{-- Peran pembaca: hanya melihat, tanpa formulir maupun tombol ubah. --}}
    @foreach ($bagianBaca as $bagian)
        <div class="card" style="margin-bottom:16px" wire:key="baca-bagian-{{ $bagian->id }}">
            <div class="sec" @if ($halamanSendiri) id="bagian-kustom-{{ $bagian->id }}" style="scroll-margin-top:var(--offset-lengket, 200px)" @endif><span class="n">👁</span><span>{{ $bagian->nama }}</span> <span class="badge b-draft" style="margin-left:6px">baca-saja</span></div>
            @if ($bagian->deskripsi)
                <div class="info">ℹ️ {{ $bagian->deskripsi }}</div>
            @endif
            <div class="fhint" style="margin:0 0 8px">Isian {{ $namaBulan[$bulan] }} {{ $tahun }} dari semua pengisi. Peran {{ $namaPeran }} hanya dapat melihat bagian ini.</div>
            @include('livewire.partials._baca-isian-bagian-kustom', ['bagian' => $bagian, 'daftar' => $bacaan[$bagian->id] ?? collect(), 'tampilkanKonteks' => true])
        </div>
    @endforeach

    @if (! $adaBagian)
        <div class="card">
            <p style="color:var(--muted);font-size:13px;margin:0">Belum ada bagian kustom aktif yang diisi atau dibaca peran {{ $namaPeran }} di menu ini. Bagian tampil di sini bila Tim SAKIP mengaktifkannya, memilih peran {{ $namaPeran }} sebagai pengisi atau pembaca, dan menempatkannya di menu {{ $namaMenu }} (Data Master &amp; Konfigurasi → Bagian Kustom).</p>
        </div>
    @endif
    @endif
</div>
