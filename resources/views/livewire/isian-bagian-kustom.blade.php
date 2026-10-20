<div>
    @if ($menu === 'isian_bagian_kustom')
        <div class="page-head">
            <div class="page-title">Isian Bagian Kustom</div>
            <div class="page-sub">Isi bagian tambahan untuk peran {{ $namaPeran }}. Pilih tahun dan bulan, lalu isi poin per IKU. Bagian yang tampil di sini diatur di Data Master &amp; Konfigurasi → Bagian Kustom.</div>
        </div>
    @elseif ($bagianList->isNotEmpty())
        <div class="sec" style="margin-top:24px"><span class="n">🧩</span><span>Bagian Kustom</span></div>
        <div class="fhint" style="margin-bottom:12px">Bagian tambahan yang ditempatkan di menu {{ $namaMenu }} untuk peran {{ $namaPeran }}. Pilih tahun dan bulan, lalu isi per IKU.</div>
    @endif

    @if ($bagianList->isEmpty() && $menu !== 'isian_bagian_kustom')
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
        @if ($bagianList->isNotEmpty())
            <nav class="nav-bagian" x-ref="daftar" aria-label="Lompat ke bagian isian">
                @foreach ($bagianList as $bagian)
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

    @forelse ($bagianList as $bagian)
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
                <div class="badge b-approve" style="display:block;margin-bottom:12px">{{ session('status-'.$bagian->id) }}</div>
            @endif
            @error("baris.{$bagian->id}")
                <div style="color:var(--red);font-size:11.5px;margin-bottom:10px">{{ $message }}</div>
            @enderror

            @foreach ($baris[$bagian->id] ?? [] as $i => $row)
                <div class="poin-single" wire:key="baris-{{ $bagian->id }}-{{ $i }}">
                    <span class="k-num stat-in">Poin {{ $i + 1 }}</span>
                    <button type="button" class="btn btn-red btn-sm" style="position:absolute;top:8px;right:8px" wire:click="hapusBaris({{ $bagian->id }}, {{ $i }})" wire:loading.attr="disabled" wire:target="hapusBaris({{ $bagian->id }}, {{ $i }})">🗑</button>

                    <div class="field">
                        <label>IKU <span class="req">*</span></label>
                        <select class="inp filled" wire:model="baris.{{ $bagian->id }}.{{ $i }}.iku_id">
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
                        <label>{{ $bagian->labelUraianEfektif() }} <span class="req">*</span></label>
                        <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model.blur="baris.{{ $bagian->id }}.{{ $i }}.teks" placeholder="Uraikan poin {{ $bagian->nama }} ini..."></textarea>
                        @error("baris.{$bagian->id}.{$i}.teks")
                            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                        @enderror
                    </div>

                    @foreach ($bagian->kolomList() as $kolom)
                        <div class="field">
                            <label>{{ $kolom['label'] }}
                                @if ($kolom['wajib'])<span class="req">*</span>@else<span style="color:var(--muted);font-weight:500">opsional</span>@endif
                            </label>
                            @if ($kolom['tipe'] === 'pilihan')
                                <select class="inp filled" wire:model.blur="baris.{{ $bagian->id }}.{{ $i }}.kolom.{{ $kolom['kunci'] }}">
                                    <option value="">— pilih —</option>
                                    @foreach ($kolom['opsi'] as $opsi)
                                        <option value="{{ $opsi }}">{{ $opsi }}</option>
                                    @endforeach
                                </select>
                            @elseif ($kolom['tipe'] === 'angka')
                                <input type="text" inputmode="decimal" class="inp filled" wire:model.blur="baris.{{ $bagian->id }}.{{ $i }}.kolom.{{ $kolom['kunci'] }}" placeholder="Angka">
                            @else
                                <input type="text" class="inp filled" wire:model.blur="baris.{{ $bagian->id }}.{{ $i }}.kolom.{{ $kolom['kunci'] }}">
                            @endif
                            @error("baris.{$bagian->id}.{$i}.kolom.{$kolom['kunci']}")
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                        </div>
                    @endforeach
                </div>
            @endforeach

            <div class="btn-row" style="flex-wrap:wrap">
                <button type="button" class="btn btn-ghost btn-sm" wire:click="tambahBaris({{ $bagian->id }})" wire:loading.attr="disabled" wire:target="tambahBaris({{ $bagian->id }})" @disabled(! $periodeAda)>＋ Tambah Poin</button>
                <button type="button" class="btn btn-primary btn-sm" wire:click="simpan({{ $bagian->id }})" wire:loading.attr="disabled" wire:target="simpan({{ $bagian->id }})" @disabled(! $periodeAda)>
                    <span wire:loading.remove wire:target="simpan({{ $bagian->id }})">💾 Simpan {{ $bagian->nama }}</span>
                    <span wire:loading wire:target="simpan({{ $bagian->id }})"><i class="spin"></i> Menyimpan…</span>
                </button>
            </div>
        </div>
    @empty
        <div class="card">
            <p style="color:var(--muted);font-size:13px;margin:0">Belum ada bagian kustom aktif untuk peran {{ $namaPeran }}. Tim SAKIP dapat menambahkannya di Data Master &amp; Konfigurasi → Bagian Kustom.</p>
        </div>
    @endforelse
    @endif
</div>
