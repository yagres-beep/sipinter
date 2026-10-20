@php
    $namaBulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
@endphp

<div x-data="{ modalBerkas: null }">
    <div class="page-head">
        <div class="page-title">Detail Verifikasi — {{ $capaian->masterIku->kode }}</div>
        <div class="page-sub">
            {{ $capaian->masterIku->indikator }} · {{ $namaBulan[$capaian->periode->bulan - 1] }} {{ $capaian->periode->tahun }} ·
            {{ $kegiatanList->count() }} kegiatan pendukung
        </div>
    </div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    @error('berkas')
        <div class="badge b-kembali" style="display:block;margin-bottom:14px">{{ $message }}</div>
    @enderror

    <div class="info teal">✏️ Analisis Capaian, Target &amp; Realisasi Triwulanan, dan teks bergaris putus-putus (uraian kegiatan, kendala, solusi, realisasi RTL, rencana RTL berikutnya) selalu bisa disunting Tim SAKIP — klik "Simpan Perubahan" di bawah kapan pun, tidak perlu menunggu status "diajukan".</div>
    @unless ($bisaDiverifikasi)
        <div class="info">🔒 Isian ini berstatus <x-badge-status :status="$capaian->status" /> — verifikasi/pengembalian bukti hanya bisa dilakukan selagi berstatus "diajukan" atau "sedang ditangani", tapi field di atas tetap bisa disunting &amp; disimpan.</div>
    @endunless
    @if (in_array($capaian->status, [\App\Models\Capaian::STATUS_DIVERIFIKASI, \App\Models\Capaian::STATUS_DISETUJUI], true))
        <div class="info teal">📝 IKU ini sudah termuat di notula. Bila ada revisi/kesalahan, sunting langsung di halaman ini lalu klik "Simpan Perubahan" — Bagian I notula ikut diperbarui otomatis{{ $capaian->status === \App\Models\Capaian::STATUS_DISETUJUI ? ', dan notula yang sudah disetujui dibuka kembali sebagai versi baru untuk disetujui ulang Kepala' : '' }}. Tidak perlu mengembalikan isian ke Ketua Tim.</div>
    @endif

    {{-- Banner periode pengajuan + tombol lompat ke tiap bagian pemeriksaan, lengket SATU
         UNIT di bawah .page-head (pola yang sama dengan Isian Kegiatan — navigasiBagian di
         app.js & .bilah-lengket di sipinter.css). Tombol disusun dari bagian yang BENAR-BENAR
         dirender di bawah (kondisi @if-nya sama persis) dan sesuai urutan tampil, supaya
         penanda bagian aktif bergerak searah gulir. Tombol hanya menggulir halaman — tidak
         mengubah isian/tanda verifikasi apa pun. --}}
    @php
        $navBagian = [['id' => 'bagian-iku', 'n' => '1', 'label' => 'IKU', 'judul' => 'Angka Capaian IKU']];
        $navBagian[] = ['id' => 'bagian-kegiatan', 'n' => '2', 'label' => 'Kegiatan', 'judul' => 'Verifikasi Bukti Capaian per Kegiatan'];
        if ($kendalaSolusiList->isNotEmpty() || $bisaDiverifikasi) {
            $navBagian[] = ['id' => 'bagian-kendala', 'n' => '3', 'label' => 'Kendala & Solusi', 'judul' => 'Kendala & Solusi'];
        }
        foreach ($bagianKustomList->groupBy('bagian_kustom_id') as $idBagianKustom => $poinPerBagian) {
            $navBagian[] = ['id' => 'bagian-kustom-'.$idBagianKustom, 'n' => '🧩', 'label' => $poinPerBagian->first()->bagianKustom->nama, 'judul' => $poinPerBagian->first()->bagianKustom->nama, 'kustom' => true];
        }
        if ($rtlBerikutnya->isNotEmpty() || $rtlBerikutnyaTambahan !== [] || $rtlBerikutnyaBisaDitambah) {
            $navBagian[] = ['id' => 'bagian-rtl', 'n' => '5', 'label' => 'Rencana Tindak Lanjut', 'judul' => 'Rencana Tindak Lanjut Triwulan Berikutnya'];
        }
        if ($rtlSebelumnya->isNotEmpty()) {
            $navBagian[] = ['id' => 'bagian-evaluasi-rtl', 'n' => '4', 'label' => 'Evaluasi RTL', 'judul' => 'Pengecekan Realisasi RTL Triwulan Sebelumnya'];
        }
        if ($bisaDiverifikasi || $bisaDibukaKembali) {
            $navBagian[] = ['id' => 'bagian-tindakan', 'n' => '✓', 'label' => 'Tindakan', 'judul' => $bisaDiverifikasi ? 'Verifikasi Selesai / Kembalikan ke Ketua Tim' : 'Buka Kembali Isian'];
        }
        $triwulanPengajuan = intdiv($capaian->periode->bulan - 1, 3);
    @endphp
    <div class="bilah-lengket" x-data="navigasiBagian" :class="{ menempel: menempel }">
        <div class="period-banner">
            <span class="pb-ico">📅</span>
            <div style="min-width:0">
                <div class="pb-lbl">Periode Pengajuan</div>
                <div class="pb-val">
                    <b>{{ $namaBulan[$capaian->periode->bulan - 1] }} {{ $capaian->periode->tahun }}</b>
                    · Triwulan {{ ['I', 'II', 'III', 'IV'][$triwulanPengajuan] }} · {{ $capaian->masterIku->kode }}
                </div>
            </div>
            <span style="margin-left:auto"><x-badge-status :status="$capaian->status" /></span>
        </div>

        <nav class="nav-bagian" x-ref="daftar" aria-label="Lompat ke bagian pemeriksaan">
            @foreach ($navBagian as $nav)
                <button type="button" class="nb-item" data-bagian="{{ $nav['id'] }}" title="{{ $nav['judul'] }}" wire:key="nav-{{ $nav['id'] }}"
                    :class="{ on: aktif === '{{ $nav['id'] }}' }" :aria-current="aktif === '{{ $nav['id'] }}' ? 'true' : null"
                    @click="lompatKe('{{ $nav['id'] }}')">
                    <span class="nb-n">{{ $nav['n'] }}</span>@if ($nav['kustom'] ?? false)<span class="nb-teks">{{ $nav['label'] }}</span>@else{{ $nav['label'] }}@endif
                </button>
            @endforeach
        </nav>
    </div>

    @if ($riwayatStatus->isNotEmpty())
        <div class="card">
            <div class="sec"><span>Riwayat Status</span></div>
            <div style="display:flex;flex-direction:column;gap:10px">
                @foreach ($riwayatStatus as $riwayat)
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                        <x-badge-status :status="$riwayat->status" />
                        <span style="font-size:12.5px">{{ $riwayat->user?->nama ?? 'Sistem' }}@if ($riwayat->user) <span style="color:var(--muted)">({{ $riwayat->user->namaRole() }})</span>@endif</span>
                        <span style="font-size:11.5px;color:var(--muted)">{{ $riwayat->created_at->wita()->translatedFormat('d F Y, H:i') }} WITA</span>
                        @if ($riwayat->catatan)
                            <span style="font-size:11.5px;color:var(--muted);width:100%">📝 {{ $riwayat->catatan }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card">
        <div class="sec" id="bagian-iku" style="scroll-margin-top:var(--offset-lengket, 200px)"><span>Angka Capaian</span> <span class="badge b-tunggu">diisi Tim SAKIP</span></div>

        <div class="field">
            <label>Analisis Capaian</label>
            <textarea class="inp filled" style="height:auto;display:block" rows="3" wire:model.live.blur="analisis_capaian"
                placeholder="Narasi analisis capaian..."></textarea>
            @error('analisis_capaian')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>

        <div class="field">
            <label>Penjelasan/Pembahasan Lainnya</label>
            <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model.live.blur="catatan"
                placeholder="Opsional — tampil di Notula Bagian I pada baris Penjelasan/pembahasan lainnya"></textarea>
            @error('catatan')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>

        {{-- Target Tahunan tidak lagi diedit dari sini — sekali per tahun per IKU,
            diisi terpusat di tab "Target Tahunan" (Data Master & Konfigurasi) supaya
            tidak perlu diketik ulang tiap sesi verifikasi bulanan. Di sini cukup
            ditampilkan sebagai referensi. --}}
        <div class="field">
            <label>Target Tahunan <span class="muted" style="font-weight:400;font-size:10px">— sekali per tahun, berlaku untuk seluruh bulan IKU ini</span></label>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <span class="inp filled" style="background:var(--ro-bg);display:inline-block;width:auto;padding:8px 14px">
                    @if ($capaian->masterIku->pakaiRasio())
                        {{ $capaian->masterIku->deskripsi_x ?: 'X' }} {{ \App\Models\PengaturanCapaian::formatAngka($capaianTahunan->x_alokasi_tw4) }} ÷ {{ $capaian->masterIku->deskripsi_y ?: 'Y' }} {{ \App\Models\PengaturanCapaian::formatAngka($capaianTahunan->y_alokasi_tw4) }} = {{ $capaianTahunan->targetTahunan() }}%
                    @else
                        {{ \App\Models\PengaturanCapaian::formatAngka($capaianTahunan->targetTahunan()) }} {{ $capaian->masterIku->satuan }}
                    @endif
                </span>
                <a wire:navigate href="{{ route('master-iku.index') }}#target" class="btn btn-ghost btn-sm">✏️ Ubah di Target Tahunan</a>
            </div>
        </div>
        @if ($capaian->masterIku->pakaiRasio())
            <div class="fhint" style="margin:10px 0 6px">Alokasi Pembilang (X) &amp; Penyebut (Y) TW I-IV sudah ditetapkan sekali di awal tahun lewat <a wire:navigate href="{{ route('master-iku.index') }}#target">🎯 Target Tahunan</a> — di sini centang item Rincian N yang sudah direalisasikan, LANGSUNG di kolom TW yang sebenarnya (checklist terbuka di kolom TW I s.d. TW aktif sekaligus, tidak cuma TW aktif — kalau ada item yang terlewat dicentang saat TW itu berjalan, klik saja checkbox-nya di kolom TW itu dari sini). Begitu tersimpan, item itu terkunci ke kolom tempat dicentang dan tetap ditampilkan — bila ada salah centang, klik <strong>✏️ Edit Pilihan</strong> di baris Realisasi untuk membuka kuncinya (TW I s.d. TW aktif), lalu simpan. Realisasi Penyebut (Y) otomatis mengikuti Alokasi Y. Kumulatif TW I s.d. TW berjalan &amp; persentasenya (X÷Y×100) dihitung otomatis di bawah.</div>
        @else
            <div class="fhint" style="margin:10px 0 6px">Alokasi Target TW I-IV sudah ditetapkan sekali di awal tahun lewat <a wire:navigate href="{{ route('master-iku.index') }}#target">🎯 Target Tahunan</a> — di sini cukup isi <strong>Realisasi sebagai angka KUMULATIF s.d. TW ini</strong> (mis. TW I=25, TW II=85, TW III=95 — BUKAN kontribusi 25, 60, 10 yang perlu dijumlah). Capaian %-nya dihitung otomatis di bawah.</div>
        @endif

        @php $twAktif = (int) $capaian->periode->triwulan; @endphp
        @if (! $capaian->masterIku->pakaiRasio())
            <div class="fhint" style="margin-bottom:8px">✏️ Realisasi kumulatif TW I s.d. TW IV bisa diubah dari sesi verifikasi ini (tidak hanya TW {{ ['I', 'II', 'III', 'IV'][$twAktif - 1] }}) — mengisi satu TW otomatis menyamakan TW sesudahnya (kumulatif, minimal sama). Alokasi Target dikunci; klik ✏️ Edit untuk mengubahnya (ikut mengubah 🎯 Target Tahunan, TW IV = Target Tahunan). Klik "Simpan Perubahan" untuk menyimpan.</div>
        @else
        <div class="fhint" style="margin-bottom:8px">🔒 Hanya kolom TW {{ ['I', 'II', 'III', 'IV'][$twAktif - 1] }} yang bisa diubah dari sesi verifikasi ini (sesuai periode isian ini) — kolom triwulan lain ditampilkan sebagai referensi, disunting lewat sesi verifikasi bulan pada triwulan itu sendiri{{ $capaian->masterIku->pakaiRasio() ? ' (KECUALI checklist Rincian N — kolom TW I s.d. TW aktif SEMUANYA terbuka &amp; bisa dicentang dari sesi ini, lihat baris Realisasi di bawah)' : '' }}. Baris Alokasi Target selalu terkunci di sini (kedua Jenis Nilai) — diisi lewat Target Tahunan.</div>
        @endif
        @if ($capaian->masterIku->pakaiRasio())
            <div class="fhint" style="margin-bottom:8px">⚠️ Dua arti "kumulatif" berbeda di tabel ini — jangan tertukar: <strong>Alokasi X</strong> (baris paling atas) SUDAH kumulatif sejak diisi di Target Tahunan (diketik langsung, tidak dijumlah lagi). <strong>Realisasi X</strong> — checklist-nya terbuka di SETIAP kolom TW s.d. TW aktif; centang item di kolom TW yang sebenarnya (boleh TW berjalan atau TW sebelumnya yang terlewat) — begitu disimpan, TERKUNCI di kolom itu (buka lagi lewat tombol ✏️ Edit Pilihan bila perlu koreksi). Baris "↳ ... Kumulatif (%)" paling bawah MURNI hasil hitung otomatis (X÷Y×100), tidak pernah diketik manual.</div>
        @endif
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th></th>
                        @foreach (['I', 'II', 'III', 'IV'] as $tw)
                            <th style="text-align:center">TW {{ $tw }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                @if ($capaian->masterIku->pakaiRasio())
                    @foreach ([
                        ['x_alokasi', $capaian->masterIku->deskripsi_x ?: 'Pembilang (X)', 'Alokasi', false, 'ANGKA KUMULATIF s.d. TW ini — dari Target Tahunan'],
                        ['y_alokasi', $capaian->masterIku->deskripsi_y ?: 'Penyebut (Y)', 'Alokasi', false, 'konstan sepanjang tahun — dari Target Tahunan'],
                        ['x_realisasi', $capaian->masterIku->deskripsi_x ?: 'Pembilang (X)', 'Realisasi', true, 'checklist terbuka di tiap kolom TW s.d. TW aktif, terkunci begitu disimpan'],
                        ['y_realisasi', $capaian->masterIku->deskripsi_y ?: 'Penyebut (Y)', 'Realisasi', false, 'konstan, otomatis = Alokasi Y'],
                    ] as [$prefix, $label, $bagian, $bisaDiedit, $keterangan])
                        <tr>
                            <td>
                                {{ $label }} — {{ $bagian }}
                                <span class="muted" style="font-weight:400">({{ $keterangan }})</span>
                                @if ($prefix === 'x_realisasi' && $this->rincianNList()->isNotEmpty())
                                    <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                                        @if ($editRincianN)
                                            <span style="font-size:11px;color:var(--amber, #b45309)">✏️ Mode edit — centang tersimpan TW I s.d. TW aktif bisa dilepas/dipindah. Klik "Simpan Perubahan" untuk menyimpan.</span>
                                            <button type="button" class="btn btn-ghost btn-sm" wire:click="batalEditRincianN">✖ Batal Edit</button>
                                        @else
                                            <button type="button" class="btn btn-ghost btn-sm" wire:click="mulaiEditRincianN" title="Buka kunci centang yang sudah tersimpan untuk dikoreksi bila ada salah pilih">✏️ Edit Pilihan</button>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            @for ($tw = 1; $tw <= 4; $tw++)
                                <td style="text-align:center{{ $prefix === 'x_realisasi' && $tw <= $twAktif ? ';min-width:190px;text-align:left' : '' }}">
                                    @if ($prefix === 'x_realisasi' && $tw <= $twAktif)
                                        {{-- Checklist TERBUKA di SETIAP kolom TW s.d. TW aktif (bukan cuma TW
                                            aktif). Satu item TETAP dihitung SEKALI SAJA (di kolom TW asal
                                            klaim-nya, default TW aktif) -- tapi begitu diklaim, item itu juga ikut
                                            TAMPIL tercentang (read-only, abu-abu) di kolom-kolom TW SESUDAHNYA,
                                            supaya sifat kumulatifnya kelihatan (bukan "hilang" begitu lewat dari
                                            kolom asalnya) -- lihat App\Livewire\VerifikasiCapaian::
                                            toggleRincianN()/twTujuanRincianN(). Mengklik checkbox di kolom lain
                                            (bukan carry-forward) memindahkan klaim ke situ. --}}
                                        <div style="max-height:170px;overflow-y:auto;display:flex;flex-direction:column;gap:3px">
                                            @foreach ($this->rincianNBisaDipilih() as $n)
                                                @php
                                                    $dicentang = (bool) ($rincianNPilih[$n->id] ?? false);
                                                    $twItem = ! $editRincianN && $n->triwulan_realisasi === $twAktif
                                                        ? $twAktif
                                                        : min(max((int) ($rincianNTw[$n->id] ?? $twAktif), 1), $twAktif);
                                                @endphp
                                                @if (! $dicentang || $tw === $twItem)
                                                    {{-- belum diklaim (tampil di semua kolom, checkbox aktif) ATAU
                                                        ini kolom asal klaimnya sendiri (interaktif, bisa dilepas) --}}
                                                    <label class="fl-row" style="cursor:pointer;gap:6px;font-size:11.5px" title="{{ $n->triwulan_realisasi === null ? 'Belum tercatat -- klik untuk klaim ke TW '.['I', 'II', 'III', 'IV'][$tw - 1].', akan terkunci ke TW ini begitu disimpan' : '' }}">
                                                        <input type="checkbox" {{ $dicentang ? 'checked' : '' }} wire:click="toggleRincianN({{ $n->id }}, {{ $tw }})">
                                                        <span>{{ $n->uraian }}</span>
                                                    </label>
                                                @elseif ($tw > $twItem)
                                                    {{-- kolom SESUDAH TW asal klaim -- ikut tercentang (kumulatif),
                                                        read-only, TIDAK dihitung ulang di sini (tetap dihitung SEKALI
                                                        di kolom $twItem, lihat recomputeXRealisasiLive()) --}}
                                                    <div class="fl-row muted" style="gap:6px;font-size:11.5px" title="Sudah direalisasikan sejak TW {{ ['I', 'II', 'III', 'IV'][$twItem - 1] }} -- tetap terhitung kumulatif, ikut tampil di sini">
                                                        <input type="checkbox" checked disabled>
                                                        <span>{{ $n->uraian }} <span style="font-weight:400">(sejak TW {{ ['I', 'II', 'III', 'IV'][$twItem - 1] }})</span></span>
                                                    </div>
                                                @endif
                                            @endforeach
                                            @foreach ($this->rincianNTerkunci() as $n)
                                                @continue($n->triwulan_realisasi > $tw)
                                                <div class="muted" style="font-size:11.5px" title="Direalisasikan TW {{ ['I', 'II', 'III', 'IV'][$n->triwulan_realisasi - 1] }}, sudah tersimpan — klik ✏️ Edit Pilihan untuk mengoreksi{{ $n->triwulan_realisasi !== $tw ? ' -- tetap terhitung kumulatif, ikut tampil di sini' : '' }}">✓ {{ $n->uraian }}{{ $n->triwulan_realisasi !== $tw ? ' (sejak TW '.['I', 'II', 'III', 'IV'][$n->triwulan_realisasi - 1].')' : '' }}</div>
                                            @endforeach
                                            @if ($this->rincianNList()->isEmpty() && $tw === $twAktif)
                                                <span class="muted" style="font-size:11.5px">Belum ada Rincian N — tambahkan di <a wire:navigate href="{{ route('master-iku.index') }}#target">🎯 Target Tahunan</a>.</span>
                                            @endif
                                        </div>
                                        @php $kumulatifTw = collect(range(1, $tw))->sum(fn ($i) => $this->{"x_realisasi_tw{$i}"} ?? 0); @endphp
                                        <div class="muted" style="font-size:10.5px;margin-top:2px">{{ $kumulatifTw }} item kumulatif s.d. TW ini{{ $tw === $twAktif ? ' (live, tersimpan setelah "Simpan")' : '' }}</div>
                                    @elseif ($bisaDiedit)
                                        <span class="muted" title="TW ini belum berjalan, belum bisa diisi">🔒 {{ $capaianTahunan->{"{$prefix}_tw{$tw}"} ?? '-' }}</span>
                                    @else
                                        <span class="muted" title="{{ $bagian === 'Alokasi' ? 'Diisi lewat halaman Target Tahunan, bukan di sini' : 'Otomatis disalin dari Alokasi Y di Target Tahunan' }}">🔒 {{ $capaianTahunan->{"{$prefix}_tw{$tw}"} ?? '-' }}</span>
                                    @endif
                                </td>
                            @endfor
                        </tr>
                    @endforeach
                    <tr class="muted" style="border-top:2px solid var(--border)">
                        <td title="Otomatis dihitung, BUKAN input — Alokasi X (kumulatif) ÷ Alokasi Y × 100 pada TW itu sendiri, tidak dijumlahkan lagi">↳ Alokasi Target Kumulatif (%) <span class="muted" style="font-weight:400">— otomatis, = X ÷ Y × 100</span></td>
                        @for ($tw = 1; $tw <= 4; $tw++)
                            <td style="text-align:center">{{ \App\Models\PengaturanCapaian::formatAngka($capaianTahunan->alokasiKumulatif($tw)) }}</td>
                        @endfor
                    </tr>
                    <tr class="muted">
                        <td title="Otomatis dihitung, BUKAN input — jumlah Realisasi X TW I s.d. TW ini (Realisasi X per TW dijumlahkan aplikasi), dibagi Realisasi Y × 100">↳ Realisasi Kumulatif (%) <span class="muted" style="font-weight:400">— otomatis, = (Σ Realisasi X s.d. TW ini) ÷ Y × 100</span></td>
                        @for ($tw = 1; $tw <= 4; $tw++)
                            <td style="text-align:center">{{ \App\Models\PengaturanCapaian::formatRealisasiKumulatif($capaianTahunan->realisasiKumulatifPresisi($tw)) }}</td>
                        @endfor
                    </tr>
                @else
                    <tr>
                        <td>
                            Alokasi Target <span class="muted" style="font-weight:400">(kumulatif)</span>
                            <div style="margin-top:4px;display:flex;gap:6px;flex-wrap:wrap">
                                @if ($editAlokasi)
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="batalEditAlokasi" title="Kembalikan Alokasi ke nilai tersimpan & kunci lagi">✖ Batal</button>
                                @else
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('editAlokasi', true)" title="Buka kunci Alokasi Target — perubahan ikut tersimpan ke 🎯 Target Tahunan (TW IV = Target Tahunan)">✏️ Edit</button>
                                @endif
                            </div>
                        </td>
                        @for ($tw = 1; $tw <= 4; $tw++)
                            <td style="text-align:center">
                                <input type="number" step="0.01" class="inp filled" style="width:100px;text-align:center{{ $editAlokasi ? '' : ';background:var(--ro-bg);cursor:not-allowed' }}" wire:model.live.debounce.400ms="alokasi_tw{{ $tw }}" @readonly(! $editAlokasi) title="{{ $editAlokasi ? 'Ketik angka KUMULATIF s.d. TW ini' : '🔒 Terkunci — klik ✏️ Edit untuk mengubah' }}">
                                @error("alokasi_tw{$tw}")
                                    <div style="color:var(--red);font-size:10.5px">{{ $message }}</div>
                                @enderror
                            </td>
                        @endfor
                    </tr>
                    <tr>
                        <td>Realisasi <span class="muted" style="font-weight:400">(kumulatif s.d. TW ini)</span></td>
                        @for ($tw = 1; $tw <= 4; $tw++)
                            <td style="text-align:center">
                                <input type="number" step="0.00001" class="inp filled" style="width:110px;text-align:center" wire:model.live.debounce.400ms="realisasi_tw{{ $tw }}" title="Ketik angka KUMULATIF s.d. TW ini, bukan kontribusi TW ini saja — TW sesudahnya otomatis ikut disamakan (minimal sama dengan TW ini)">
                                @error("realisasi_tw{$tw}")
                                    <div style="color:var(--red);font-size:10.5px">{{ $message }}</div>
                                @enderror
                            </td>
                        @endfor
                    </tr>
                @endif
                    <tr>
                        <td>Capaian % <span class="muted" style="font-weight:400">(Triwulanan)</span></td>
                        @for ($tw = 1; $tw <= 4; $tw++)
                            <td style="text-align:center">{{ \App\Models\PengaturanCapaian::formatPersen($capaianTahunan->capaianTriwulanan($tw)) }}</td>
                        @endfor
                    </tr>
                    <tr>
                        <td>Capaian % <span class="muted" style="font-weight:400">(Setahun)</span></td>
                        @for ($tw = 1; $tw <= 4; $tw++)
                            <td style="text-align:center">{{ \App\Models\PengaturanCapaian::formatPersen($capaianTahunan->capaianSetahun($tw)) }}</td>
                        @endfor
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="fhint" style="margin-top:8px">ℹ️ Kumulatif = jumlah TW I s.d. triwulan tsb, dihitung otomatis dari isian triwulanan di atas — tidak perlu dijumlahkan manual. Capaian % (Triwulanan) = Realisasi Kumulatif ÷ Alokasi Target Kumulatif; Capaian % (Setahun) = Realisasi Kumulatif ÷ Target Tahunan — sesuai Kertas Kerja Pengukuran Kinerja Triwulanan, dibatasi maksimal sesuai <a wire:navigate href="{{ route('master-iku.index') }}">Pengaturan Rumus Capaian</a>.</div>

        <div class="btn-row" style="margin-top:14px">
            <button type="button" class="btn btn-teal" wire:click="simpanPerubahan" wire:loading.attr="disabled" wire:target="simpanPerubahan">
                <span wire:loading.remove wire:target="simpanPerubahan">💾 Simpan Perubahan</span>
                <span wire:loading wire:target="simpanPerubahan"><i class="spin"></i> Menyimpan…</span>
            </button>
        </div>
    </div>

    <div class="card">
        <div class="sec" id="bagian-kegiatan" style="scroll-margin-top:var(--offset-lengket, 200px)"><span>Verifikasi Bukti Capaian per Kegiatan</span></div>
        @if ($bisaDiverifikasi)
            <div class="info">ℹ️ Uraian kegiatan bisa disunting bila ada salah ketik, lalu tandai sesuai/tidak sesuai. Klik tiap berkas untuk memeriksa &amp; menandai sesuai/tidak sesuai.</div>
        @endif

        @forelse ($kegiatanList as $kegiatan)
            @php
                $berkasKegiatan = $berkasPerKegiatan[$kegiatan->id] ?? collect();
                $bisaDikoreksi = $this->kegiatanBisaDikoreksi($kegiatan);
            @endphp
            <div class="keg" wire:key="keg-{{ $kegiatan->id }}" @if (!$bisaDikoreksi) style="opacity:.8" @endif>
                <div class="keg-head">
                    <span class="t">Kegiatan {{ $loop->iteration }}</span>
                    <x-badge-status :status="$kegiatan->status_dokumen" />
                    <span style="color:var(--muted);font-size:11.5px">
                        {{ $kegiatan->jenis === 'survei_sensus' ? 'Pisah ke beberapa tahapan' : '1 folder (kegiatan)' }}{{ $kegiatan->tahapan_survei ? ' · Tahapan '.\App\Livewire\PengisianKegiatan::labelTahapan($kegiatan->tahapan_survei) : '' }}
                    </span>
                </div>
                <textarea class="inp filled" style="height:auto;display:block;font-style:italic" rows="2" wire:model.live.blur="koreksiKegiatan.{{ $kegiatan->id }}" @readonly(!$bisaDikoreksi)></textarea>
                @error("koreksiKegiatan.{$kegiatan->id}")
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
                <div style="margin-top:6px">
                    <x-nama-folder :id="$kegiatan->id" :nama="$kegiatan->namaFolder()" :kustom="filled($kegiatan->nama_folder_kustom)" />
                </div>

                @if ($this->uraianBisaDiverifikasi($kegiatan->id))
                    <div x-data="{ pendingMark: null }" style="margin-top:8px">
                        <div style="display:flex;gap:8px">
                            <button type="button" class="mark"
                                :class="{ ok: pendingMark === 'ok' || (pendingMark === null && {{ $kegiatan->status_verifikasi_uraian === 'terverifikasi' ? 'true' : 'false' }}) }"
                                @click="pendingMark = 'ok'"
                                wire:click="tandaiUraianSesuai({{ $kegiatan->id }})" wire:loading.attr="disabled" wire:target="tandaiUraianSesuai({{ $kegiatan->id }}),tandaiUraianTolak({{ $kegiatan->id }})">
                                <span wire:loading.remove wire:target="tandaiUraianSesuai({{ $kegiatan->id }})">✓ Sesuai</span>
                                <span wire:loading wire:target="tandaiUraianSesuai({{ $kegiatan->id }})"><i class="spin"></i></span>
                            </button>
                            <button type="button" class="mark"
                                :class="{ no: pendingMark === 'no' || (pendingMark === null && {{ $kegiatan->status_verifikasi_uraian === 'ditolak' ? 'true' : 'false' }}) }"
                                @click="pendingMark = 'no'"
                                wire:click="tandaiUraianTolak({{ $kegiatan->id }})" wire:loading.attr="disabled" wire:target="tandaiUraianSesuai({{ $kegiatan->id }}),tandaiUraianTolak({{ $kegiatan->id }})">
                                <span wire:loading.remove wire:target="tandaiUraianTolak({{ $kegiatan->id }})">✕ Tidak Sesuai</span>
                                <span wire:loading wire:target="tandaiUraianTolak({{ $kegiatan->id }})"><i class="spin"></i></span>
                            </button>
                        </div>
                        <div class="field" style="margin-top:10px;margin-bottom:0" x-cloak
                            x-show="pendingMark === 'no' || (pendingMark === null && {{ $kegiatan->status_verifikasi_uraian === 'ditolak' ? 'true' : 'false' }})">
                            <label style="font-size:11.5px">Catatan (wajib bila tidak sesuai)</label>
                            <textarea class="inp filled" style="height:auto;display:block;font-size:11.5px" rows="2"
                                wire:model="catatanUraian.{{ $kegiatan->id }}" placeholder="mis. Uraian belum menjelaskan output yang dihasilkan"
                                x-on:blur="if (pendingMark === 'no' || (pendingMark === null && {{ $kegiatan->status_verifikasi_uraian === 'ditolak' ? 'true' : 'false' }})) $wire.tandaiUraianTolak({{ $kegiatan->id }})"></textarea>
                            @error('catatanUraian.'.$kegiatan->id)
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                        </div>

                        <div style="margin-top:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap"
                            x-show="pendingMark !== null || {{ $kegiatan->status_verifikasi_uraian !== 'menunggu' ? 'true' : 'false' }}">
                            <button type="button" class="btn btn-ghost btn-sm"
                                x-on:click="(pendingMark === 'no' || (pendingMark === null && {{ $kegiatan->status_verifikasi_uraian === 'ditolak' ? 'true' : 'false' }})) ? $wire.tandaiUraianTolak({{ $kegiatan->id }}) : $wire.tandaiUraianSesuai({{ $kegiatan->id }})"
                                wire:loading.attr="disabled" wire:target="tandaiUraianSesuai({{ $kegiatan->id }}),tandaiUraianTolak({{ $kegiatan->id }})">
                                <span wire:loading.remove wire:target="tandaiUraianSesuai({{ $kegiatan->id }}),tandaiUraianTolak({{ $kegiatan->id }})">💾 Simpan Verifikasi</span>
                                <span wire:loading wire:target="tandaiUraianSesuai({{ $kegiatan->id }}),tandaiUraianTolak({{ $kegiatan->id }})"><i class="spin"></i> Menyimpan…</span>
                            </button>
                            @if ($kegiatan->status_verifikasi_uraian !== 'menunggu' && !$errors->has('catatanUraian.'.$kegiatan->id))
                                <span style="color:#16a34a;font-size:11.5px">✓ Tersimpan</span>
                            @endif
                        </div>
                    </div>
                @elseif ($kegiatan->catatan_uraian)
                    <div class="field" style="margin-top:6px;margin-bottom:0">
                        <label style="font-size:11.5px">Catatan</label>
                        <p style="font-size:12.5px;color:var(--muted);margin:0">{{ $kegiatan->catatan_uraian }}</p>
                    </div>
                @endif

                @if ($kegiatan->catatan_bukti_dihapus)
                    <div class="info red" style="margin-top:8px;font-size:11.5px">
                        🗑️ <b>Ketua Tim menghapus bukti sebelumnya</b> — alasan penolakan sebelumnya: "{{ $kegiatan->catatan_bukti_dihapus }}". Periksa apakah bukti pengganti di bawah sudah memperbaikinya.
                    </div>
                @endif

                @forelse ($berkasKegiatan as $file)
                    <div class="filechip {{ $file->status_verifikasi === 'terverifikasi' ? 'ok' : ($file->status_verifikasi === 'ditolak' ? 'no' : '') }}" style="margin-top:8px" wire:key="berkas-{{ $file->id }}">
                        <span class="nm">
                            <x-nama-berkas :id="$file->id" :nama="$file->nama_file" :diunggah-oleh="$file->diunggah_oleh" :status="$file->status_verifikasi" />
                            @if ($file->status_verifikasi === 'ditolak')
                                <span class="sub" style="color:var(--red)">Tidak Sesuai</span>
                            @endif
                        </span>
                        <button type="button" class="btn btn-ghost btn-sm" @click="modalBerkas = {{ $file->id }}">🔍 Periksa</button>
                    </div>
                @empty
                    <p style="color:var(--muted);font-size:12.5px;margin-top:8px">Belum ada bukti diunggah.</p>
                @endforelse
            </div>
        @empty
            <p style="color:var(--muted);font-size:13px">Belum ada kegiatan.</p>
        @endforelse
    </div>

    @if ($kegiatanList->isNotEmpty())
        <div class="card">
            <div class="sec"><span>Rincian Output (RO)</span></div>
            <div class="info">ℹ️ Opsional — tidak semua IKU punya RO. Klik "+ Tambah RO" hanya bila IKU ini memang ber-RO dan belum punya realisasi triwulan berjalan, agar tabel Realisasi Volume RO &amp; Progres Pelaksanaan Kegiatan di notula terisi benar. RO yang tidak diisi realisasinya tidak dicetak di notula. RO dihitung mandiri per IKU (tidak mengikuti jumlah Kegiatan).</div>

            @php $nomorRo = 0; @endphp
            @forelse ($rincianOutput as $kunciRo => $baris)
                @php $bisaDikoreksi = $this->rincianOutputBisaDikoreksi($baris); $nomorRo++; @endphp
                <div class="keg" style="margin-top:8px{{ $bisaDikoreksi ? '' : ';opacity:.8' }}" wire:key="ro-{{ $kunciRo }}">
                    <div class="keg-head">
                        <span class="t">RO {{ $nomorRo }}</span>
                        @if ($bisaDikoreksi)
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="hapusRo('{{ $kunciRo }}')" wire:loading.attr="disabled" wire:target="hapusRo('{{ $kunciRo }}')">✕ Hapus</button>
                        @endif
                    </div>

                    <div class="field">
                        <label>Rincian Output (RO)</label>
                        <input type="text" class="inp filled" wire:model.live.blur="rincianOutput.{{ $kunciRo }}.uraian" @readonly(!$bisaDikoreksi) placeholder="mis. Publikasi/Laporan Statistik Sumber Daya Mineral dan Konstruksi">
                        @error("rincianOutput.{$kunciRo}.uraian")
                            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="row2" style="margin-top:6px">
                        <div class="field">
                            <label>Realisasi Volume RO</label>
                            <input type="text" class="inp filled" wire:model.live.blur="rincianOutput.{{ $kunciRo }}.volume_ro" @readonly(!$bisaDikoreksi) placeholder="mis. 1 publikasi">
                            @error("rincianOutput.{$kunciRo}.volume_ro")
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="field">
                            <label>Progres Pelaksanaan Kegiatan (%)</label>
                            <input type="number" step="0.01" min="0" max="100" class="inp filled" wire:model.live.blur="rincianOutput.{{ $kunciRo }}.progres_persen" @readonly(!$bisaDikoreksi) placeholder="mis. 100">
                            @error("rincianOutput.{$kunciRo}.progres_persen")
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>
            @empty
                <p style="color:var(--muted);font-size:12.5px;margin-top:8px">IKU ini belum punya RO — bagian RO tidak akan muncul di notula.</p>
            @endforelse

            @if ($this->kegiatanList()->contains(fn ($kegiatan) => $this->kegiatanBisaDikoreksi($kegiatan)))
                <button type="button" class="btn btn-ghost btn-sm" style="margin-top:8px" wire:click="tambahRo" wire:loading.attr="disabled" wire:target="tambahRo">+ Tambah RO</button>
            @endif
        </div>
    @endif

    <div class="card">
        <div class="sec"><span>Indikator Proksi</span></div>
        <div class="info">
            ℹ️ Opsional — berlaku untuk IKU apa pun (tidak lagi khusus IKU SAKIP). Klik "+ Tambah Proksi" hanya bila capaian IKU ini perlu dijelaskan lewat indikator proksi. Setiap proksi dicetak sebagai satu baris tabel <b>Indikator Proksi | Target s.d Triwulan | Realisasi s.d Triwulan</b> di notula. Bila tidak ada proksi yang diisi, seluruh bagian proksi tidak dicetak. Proksi disimpan per triwulan.
            <details style="margin-top:6px">
                <summary style="cursor:pointer">Cara memasangnya di template notula</summary>
                <div style="margin-top:6px;line-height:1.6">
                    Di dalam <code>@{{iku_blok}}</code>, setelah bagian RO, tulis berurutan:
                    <ol style="margin:4px 0 0 18px;padding:0">
                        <li><code>@{{blok_proksi}}</code> — paragraf/baris sendiri, tanpa teks lain.</li>
                        <li>Judul/pertanyaan bagian proksi (teks biasa, mis. "Indikator Proksi :").</li>
                        <li>Tabel 3 kolom: baris judul <i>Indikator Proksi | Target s.d Triwulan <code>@{{triwulan_angka}}</code> | Realisasi s.d Triwulan <code>@{{triwulan_angka}}</code></i>, lalu SATU baris isi: <code>@{{proksi_uraian}}</code> | <code>@{{proksi_target}}</code> | <code>@{{proksi_realisasi}}</code>. Baris isi ini digandakan otomatis sebanyak proksi.</li>
                        <li><code>@{{/blok_proksi}}</code> — paragraf/baris sendiri, tanpa teks lain.</li>
                    </ol>
                </div>
            </details>
        </div>

        @php $nomorProksi = 0; @endphp
        @forelse ($indikatorProksi as $kunciProksi => $baris)
            @php $nomorProksi++; @endphp
            <div class="keg" style="margin-top:8px" wire:key="proksi-{{ $kunciProksi }}">
                <div class="keg-head">
                    <span class="t">Proksi {{ $nomorProksi }}</span>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="hapusProksi('{{ $kunciProksi }}')" wire:loading.attr="disabled" wire:target="hapusProksi('{{ $kunciProksi }}')">✕ Hapus</button>
                </div>

                <div class="field">
                    <label>Indikator Proksi</label>
                    <input type="text" class="inp filled" wire:model.live.blur="indikatorProksi.{{ $kunciProksi }}.uraian" placeholder="mis. Persentase monitoring capaian kinerja triwulanan yang terlaksana tepat waktu">
                    @error("indikatorProksi.{$kunciProksi}.uraian")
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>

                <div class="row2" style="margin-top:6px">
                    <div class="field">
                        <label>Target s.d Triwulan {{ $capaian->periode->triwulan }}</label>
                        <input type="text" class="inp filled" wire:model.live.blur="indikatorProksi.{{ $kunciProksi }}.target" placeholder="mis. 100%">
                        @error("indikatorProksi.{$kunciProksi}.target")
                            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="field">
                        <label>Realisasi s.d Triwulan {{ $capaian->periode->triwulan }}</label>
                        <input type="text" class="inp filled" wire:model.live.blur="indikatorProksi.{{ $kunciProksi }}.realisasi" placeholder="mis. 100%">
                        @error("indikatorProksi.{$kunciProksi}.realisasi")
                            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        @empty
            <p style="color:var(--muted);font-size:12.5px;margin-top:8px">IKU ini belum punya indikator proksi — bagian proksi tidak akan muncul di notula.</p>
        @endforelse

        <button type="button" class="btn btn-ghost btn-sm" style="margin-top:8px" wire:click="tambahProksi" wire:loading.attr="disabled" wire:target="tambahProksi">+ Tambah Proksi</button>
    </div>

    @if ($kendalaSolusiList->isNotEmpty() || $bisaDiverifikasi)
        <div class="card">
            <div class="sec" id="bagian-kendala" style="scroll-margin-top:var(--offset-lengket, 200px)"><span>Kendala &amp; Solusi</span></div>
            @foreach ($kendalaSolusiList as $ks)
                <div class="poin-row" wire:key="ks-{{ $ks->id }}">
                    <span class="k-num">Pasangan {{ $loop->iteration }}</span>
                    @if ($this->kendalaBisaDiverifikasi($ks->id))
                        <button type="button" class="btn btn-red btn-sm" style="position:absolute;top:8px;right:8px;z-index:1" title="Hapus pasangan ini"
                            wire:click="hapusKendala({{ $ks->id }})" wire:confirm="Hapus pasangan kendala & solusi ini?"
                            wire:loading.attr="disabled" wire:loading.class="btn-busy" wire:target="hapusKendala({{ $ks->id }})">🗑</button>
                    @endif
                    <x-badge-status style="position:absolute;top:-9px;right:12px" :status="$ks->status_verifikasi === 'terverifikasi' ? 'diverifikasi' : ($ks->status_verifikasi === 'ditolak' ? 'dikembalikan' : 'diajukan')" :label="$ks->status_verifikasi === 'terverifikasi' ? 'Sesuai' : ($ks->status_verifikasi === 'ditolak' ? 'Tidak Sesuai' : 'Menunggu')" />

                    <div class="field" style="margin-bottom:0">
                        <label style="font-size:11.5px">Kendala</label>
                        <textarea class="inp filled" style="height:auto;display:block;font-style:italic" rows="2" wire:model.live.blur="koreksiKendala.{{ $ks->id }}.kendala"></textarea>
                    </div>
                    @if ($ks->solusi || $bisaDiverifikasi)
                        <div class="field" style="margin-bottom:0">
                            <label style="font-size:11.5px">Solusi</label>
                            <textarea class="inp filled" style="height:auto;display:block;font-style:italic" rows="2" wire:model.live.blur="koreksiKendala.{{ $ks->id }}.solusi"></textarea>
                        </div>
                    @endif

                    @if ($this->kendalaBisaDiverifikasi($ks->id))
                        {{-- pendingMark = pilihan terakhir yg DIKLIK, ditampilkan LANGSUNG (optimistic)
                            tanpa menunggu balasan server (Supabase remote ~400ms/query) — supaya tombol
                            tidak terasa perlu diklik berkali-kali sebelum berubah warna. Begitu respons
                            server datang, render ulang dari status_verifikasi tetap jadi sumber
                            kebenaran akhir (fallback saat pendingMark masih null, mis. buka modal baru). --}}
                        <div style="grid-column:1/-1" x-data="{ pendingMark: null }">
                            <div style="display:flex;gap:8px;margin-top:6px">
                                <button type="button" class="mark"
                                    :class="{ ok: pendingMark === 'ok' || (pendingMark === null && {{ $ks->status_verifikasi === 'terverifikasi' ? 'true' : 'false' }}) }"
                                    @click="pendingMark = 'ok'"
                                    wire:click="tandaiKendalaSesuai({{ $ks->id }})" wire:loading.attr="disabled" wire:target="tandaiKendalaSesuai({{ $ks->id }}),tandaiKendalaTolak({{ $ks->id }})">
                                    <span wire:loading.remove wire:target="tandaiKendalaSesuai({{ $ks->id }})">✓ Sesuai</span>
                                    <span wire:loading wire:target="tandaiKendalaSesuai({{ $ks->id }})"><i class="spin"></i></span>
                                </button>
                                <button type="button" class="mark"
                                    :class="{ no: pendingMark === 'no' || (pendingMark === null && {{ $ks->status_verifikasi === 'ditolak' ? 'true' : 'false' }}) }"
                                    @click="pendingMark = 'no'"
                                    wire:click="tandaiKendalaTolak({{ $ks->id }})" wire:loading.attr="disabled" wire:target="tandaiKendalaSesuai({{ $ks->id }}),tandaiKendalaTolak({{ $ks->id }})">
                                    <span wire:loading.remove wire:target="tandaiKendalaTolak({{ $ks->id }})">✕ Tidak Sesuai</span>
                                    <span wire:loading wire:target="tandaiKendalaTolak({{ $ks->id }})"><i class="spin"></i></span>
                                </button>
                            </div>
                            <div class="field" style="margin-top:10px;margin-bottom:0" x-cloak
                                x-show="pendingMark === 'no' || (pendingMark === null && {{ $ks->status_verifikasi === 'ditolak' ? 'true' : 'false' }})">
                                <label style="font-size:11.5px">Catatan (wajib bila tidak sesuai)</label>
                                {{-- @blur menyusulkan ulang tandaiKendalaTolak() begitu catatan selesai
                                    diisi (lihat catatan yang sama pada catatanBerkas di atas) — tanpa ini
                                    klik "Tidak Sesuai" saat catatan kosong tampak "berhasil" (tombolnya
                                    sudah merah lewat pendingMark optimistic) padahal belum tersimpan. --}}
                                <textarea class="inp filled" style="height:auto;display:block;font-size:11.5px" rows="2"
                                    wire:model="catatanKendala.{{ $ks->id }}" placeholder="mis. Solusi belum konkret / tidak relevan dengan kendala"
                                    x-on:blur="if (pendingMark === 'no' || (pendingMark === null && {{ $ks->status_verifikasi === 'ditolak' ? 'true' : 'false' }})) $wire.tandaiKendalaTolak({{ $ks->id }})"></textarea>
                                @error('catatanKendala.'.$ks->id)
                                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                                @enderror
                            </div>

                            <div style="margin-top:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap"
                                x-show="pendingMark !== null || {{ $ks->status_verifikasi !== 'menunggu' ? 'true' : 'false' }}">
                                <button type="button" class="btn btn-ghost btn-sm"
                                    x-on:click="(pendingMark === 'no' || (pendingMark === null && {{ $ks->status_verifikasi === 'ditolak' ? 'true' : 'false' }})) ? $wire.tandaiKendalaTolak({{ $ks->id }}) : $wire.tandaiKendalaSesuai({{ $ks->id }})"
                                    wire:loading.attr="disabled" wire:target="tandaiKendalaSesuai({{ $ks->id }}),tandaiKendalaTolak({{ $ks->id }})">
                                    <span wire:loading.remove wire:target="tandaiKendalaSesuai({{ $ks->id }}),tandaiKendalaTolak({{ $ks->id }})">💾 Simpan Verifikasi</span>
                                    <span wire:loading wire:target="tandaiKendalaSesuai({{ $ks->id }}),tandaiKendalaTolak({{ $ks->id }})"><i class="spin"></i> Menyimpan…</span>
                                </button>
                                @if ($ks->status_verifikasi !== 'menunggu' && !$errors->has('catatanKendala.'.$ks->id))
                                    <span style="color:#16a34a;font-size:11.5px">✓ Tersimpan</span>
                                @endif
                            </div>
                        </div>
                    @elseif ($ks->catatan)
                        <div class="field" style="grid-column:1/-1;margin-top:6px;margin-bottom:0">
                            <label style="font-size:11.5px">Catatan</label>
                            <p style="font-size:12.5px;color:var(--muted);margin:0">{{ $ks->catatan }}</p>
                        </div>
                    @endif
                </div>
            @endforeach

            {{-- Pasangan baru dari Tim SAKIP sendiri — baru tersimpan saat Simpan/Verifikasi
                 Selesai ditekan, lalu langsung berstatus "Sesuai". --}}
            @foreach ($kendalaBaru as $kunci => $baris)
                <div class="poin-row" wire:key="ks-baru-{{ $kunci }}">
                    <span class="k-num">Pasangan baru</span>
                    <button type="button" class="btn btn-red btn-sm" style="position:absolute;top:8px;right:8px;z-index:1" title="Batal tambah"
                        wire:click="hapusKendalaBaru('{{ $kunci }}')">🗑</button>
                    <div class="field" style="margin-bottom:0">
                        <label style="font-size:11.5px">Kendala</label>
                        <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model.live.blur="kendalaBaru.{{ $kunci }}.kendala" placeholder="Tuliskan kendala…"></textarea>
                        @error("kendalaBaru.{$kunci}.kendala")
                            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label style="font-size:11.5px">Solusi</label>
                        <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model.live.blur="kendalaBaru.{{ $kunci }}.solusi" placeholder="Tuliskan solusi…"></textarea>
                    </div>
                </div>
            @endforeach

            @if ($kendalaSolusiList->isEmpty() && $kendalaBaru === [])
                <p style="color:var(--muted);font-size:12.5px;margin-top:8px">Belum ada kendala &amp; solusi untuk IKU ini.</p>
            @endif

            @if ($bisaDiverifikasi)
                <button type="button" class="btn btn-ghost btn-sm" style="margin-top:8px" wire:click="tambahKendala" wire:loading.attr="disabled" wire:target="tambahKendala">+ Tambah Kendala &amp; Solusi</button>
            @endif
        </div>
    @endif

    @if ($bagianKustomList->isNotEmpty())
        <div class="card">
            <div class="sec"><span>Bagian Kustom</span></div>
            @foreach ($bagianKustomList->groupBy('bagian_kustom_id') as $idBagianKustom => $poinPerBagian)
                @php $namaBagian = $poinPerBagian->first()->bagianKustom->nama; @endphp
                <div id="bagian-kustom-{{ $idBagianKustom }}" style="scroll-margin-top:var(--offset-lengket, 200px);font-size:12px;font-weight:700;color:var(--blue-600);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">{{ $namaBagian }}</div>
                @foreach ($poinPerBagian as $poin)
                    @php $berkasPoin = $berkasPerBagianKustom[$poin->id] ?? collect(); @endphp
                    <div class="keg" wire:key="bagian-kustom-{{ $poin->id }}">
                        <p style="font-size:13px;margin-bottom:8px">{{ $poin->teks }}</p>

                        @if ($this->bagianKustomBisaDiverifikasi($poin->id))
                            <div x-data="{ pendingMark: null }" style="margin-bottom:10px">
                                <div style="display:flex;gap:8px">
                                    <button type="button" class="mark"
                                        :class="{ ok: pendingMark === 'ok' || (pendingMark === null && {{ $poin->status_verifikasi === 'terverifikasi' ? 'true' : 'false' }}) }"
                                        @click="pendingMark = 'ok'"
                                        wire:click="tandaiBagianKustomSesuai({{ $poin->id }})" wire:loading.attr="disabled" wire:target="tandaiBagianKustomSesuai({{ $poin->id }}),tandaiBagianKustomTolak({{ $poin->id }})">
                                        <span wire:loading.remove wire:target="tandaiBagianKustomSesuai({{ $poin->id }})">✓ Sesuai</span>
                                        <span wire:loading wire:target="tandaiBagianKustomSesuai({{ $poin->id }})"><i class="spin"></i></span>
                                    </button>
                                    <button type="button" class="mark"
                                        :class="{ no: pendingMark === 'no' || (pendingMark === null && {{ $poin->status_verifikasi === 'ditolak' ? 'true' : 'false' }}) }"
                                        @click="pendingMark = 'no'"
                                        wire:click="tandaiBagianKustomTolak({{ $poin->id }})" wire:loading.attr="disabled" wire:target="tandaiBagianKustomSesuai({{ $poin->id }}),tandaiBagianKustomTolak({{ $poin->id }})">
                                        <span wire:loading.remove wire:target="tandaiBagianKustomTolak({{ $poin->id }})">✕ Tidak Sesuai</span>
                                        <span wire:loading wire:target="tandaiBagianKustomTolak({{ $poin->id }})"><i class="spin"></i></span>
                                    </button>
                                </div>
                                <div class="field" style="margin-top:10px;margin-bottom:0" x-cloak
                                    x-show="pendingMark === 'no' || (pendingMark === null && {{ $poin->status_verifikasi === 'ditolak' ? 'true' : 'false' }})">
                                    <label style="font-size:11.5px">Catatan (wajib bila tidak sesuai)</label>
                                    <textarea class="inp filled" style="height:auto;display:block;font-size:11.5px" rows="2"
                                        wire:model="catatanBagianKustom.{{ $poin->id }}" placeholder="mis. Narasi belum menjelaskan tindak lanjut yang dilakukan"
                                        x-on:blur="if (pendingMark === 'no' || (pendingMark === null && {{ $poin->status_verifikasi === 'ditolak' ? 'true' : 'false' }})) $wire.tandaiBagianKustomTolak({{ $poin->id }})"></textarea>
                                    @error('catatanBagianKustom.'.$poin->id)
                                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div style="margin-top:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap"
                                    x-show="pendingMark !== null || {{ $poin->status_verifikasi !== 'menunggu' ? 'true' : 'false' }}">
                                    <button type="button" class="btn btn-ghost btn-sm"
                                        x-on:click="(pendingMark === 'no' || (pendingMark === null && {{ $poin->status_verifikasi === 'ditolak' ? 'true' : 'false' }})) ? $wire.tandaiBagianKustomTolak({{ $poin->id }}) : $wire.tandaiBagianKustomSesuai({{ $poin->id }})"
                                        wire:loading.attr="disabled" wire:target="tandaiBagianKustomSesuai({{ $poin->id }}),tandaiBagianKustomTolak({{ $poin->id }})">
                                        <span wire:loading.remove wire:target="tandaiBagianKustomSesuai({{ $poin->id }}),tandaiBagianKustomTolak({{ $poin->id }})">💾 Simpan Verifikasi</span>
                                        <span wire:loading wire:target="tandaiBagianKustomSesuai({{ $poin->id }}),tandaiBagianKustomTolak({{ $poin->id }})"><i class="spin"></i> Menyimpan…</span>
                                    </button>
                                    @if ($poin->status_verifikasi !== 'menunggu' && !$errors->has('catatanBagianKustom.'.$poin->id))
                                        <span style="color:#16a34a;font-size:11.5px">✓ Tersimpan</span>
                                    @endif
                                </div>
                            </div>
                        @elseif ($poin->catatan)
                            <div class="field" style="margin-bottom:10px">
                                <label style="font-size:11.5px">Catatan</label>
                                <p style="font-size:12.5px;color:var(--muted);margin:0">{{ $poin->catatan }}</p>
                            </div>
                        @endif

                        @if ($poin->catatan_bukti_dihapus)
                            <div class="info red" style="margin-bottom:8px;font-size:11.5px">
                                🗑️ <b>Ketua Tim menghapus bukti sebelumnya</b> — alasan penolakan sebelumnya: "{{ $poin->catatan_bukti_dihapus }}". Periksa apakah bukti pengganti di bawah sudah memperbaikinya.
                            </div>
                        @endif

                        @forelse ($berkasPoin as $file)
                            <div class="filechip {{ $file->status_verifikasi === 'terverifikasi' ? 'ok' : ($file->status_verifikasi === 'ditolak' ? 'no' : '') }}" wire:key="berkas-{{ $file->id }}">
                                <span class="nm"><x-nama-berkas :id="$file->id" :nama="$file->nama_file" :diunggah-oleh="$file->diunggah_oleh" :status="$file->status_verifikasi" /> <span class="sub">Bukti dukung</span></span>
                                <button type="button" class="btn btn-ghost btn-sm" @click="modalBerkas = {{ $file->id }}">🔍 Periksa</button>
                            </div>
                        @empty
                            <p style="color:var(--muted);font-size:12.5px">Belum ada bukti diunggah.</p>
                        @endforelse
                    </div>
                @endforeach
            @endforeach
        </div>
    @endif

    @foreach ($semuaBerkas as $file)
        <div class="modal-overlay" x-show="modalBerkas === {{ $file->id }}" x-cloak style="display:none" @click.self="modalBerkas = null" wire:key="modal-berkas-{{ $file->id }}">
            <div class="modal">
                <div class="modal-top">
                    <div class="mt-t">📄 {{ $file->nama_file }}</div>
                    <button type="button" class="x" @click="modalBerkas = null">✕</button>
                </div>
                <div class="modal-body">
                    <div class="pdf-view">
                        <h4>{{ strtoupper(str_replace('_', ' ', $file->kategori)) }}</h4>
                        <div class="pmeta">{{ $file->nama_file }}</div>
                        {{-- src diisi lewat Alpine (bukan langsung di server) SUPAYA berkas baru
                            diminta/dimuat saat modal-nya benar-benar dibuka — kalau semua
                            &lt;iframe&gt; ini diberi src langsung, browser akan diam-diam mengunduh
                            SEMUA berkas bukti di halaman ini sekaligus saat halaman dimuat,
                            meski modalnya masih tersembunyi (x-show cuma mengatur display:none,
                            tidak mencegah iframe mulai memuat).

                            wire:ignore WAJIB ada — tanpanya, tiap wire:click APA PUN di komponen
                            ini (termasuk tandaiSesuai/tandaiTolak) membuat Livewire ikut me-morph
                            elemen ini, dan menimpa iframe.src lewat JS SELALU memicu browser
                            memuat ulang PDF dari awal walau ke URL yang sama persis — inilah
                            sebabnya tombol Sesuai/Tidak Sesuai terasa jauh lebih lambat dibanding
                            tombol lain yang tidak bersebelahan dengan iframe. wire:ignore membuat
                            Livewire sama sekali tidak menyentuh elemen ini lagi setelah render
                            pertama; reaktivitas x-bind:src terhadap modalBerkas tetap jalan
                            normal karena itu urusan Alpine, bukan Livewire. --}}
                        <iframe
                            wire:ignore
                            x-bind:src="modalBerkas === {{ $file->id }} ? @js(route('berkas.show', $file)) : null"
                            class="pdf-frame" title="Pratinjau {{ $file->nama_file }}"
                        ></iframe>
                        <a href="{{ route('berkas.show', $file) }}" target="_blank" class="btn btn-ghost btn-sm" style="margin-top:10px;align-self:flex-start">🔗 Buka di tab baru / layar penuh</a>
                    </div>
                    <div class="verify-panel" x-data="{ pendingMark: null }">
                        <div class="vp-t">Verifikasi Berkas</div>
                        @php $tautanRtl = $rtlPerBerkas[$file->id] ?? null; @endphp
                        @if ($tautanRtl)
                            <div style="font-size:11px;line-height:1.5;color:var(--teal);margin-bottom:8px">🔗 Bukti kegiatan ini juga menjadi bukti realisasi RTL "{{ \Illuminate\Support\Str::limit($tautanRtl['poin']->rtl_teks, 70) }}" — satu berkas, satu status: hasil pemeriksaan di sini otomatis berlaku di Evaluasi RTL.</div>
                        @endif
                        @if ($this->berkasBisaDiverifikasi($file->id))
                            {{-- pendingMark = pilihan terakhir yg DIKLIK, ditampilkan LANGSUNG (optimistic)
                                tanpa menunggu balasan server (Supabase remote ~400ms/query) — supaya
                                "Sesuai" langsung ijo di klik pertama, dan "Tidak Sesuai" langsung
                                kelihatan aktif walau ditolak validasi (catatan kosong) supaya jelas
                                kalau perlu isi catatan dulu. status_verifikasi tetap sumber kebenaran
                                akhir lewat pendingMark===null (mis. saat modal baru dibuka). --}}
                            <button type="button" class="mark"
                                :class="{ ok: pendingMark === 'ok' || (pendingMark === null && {{ $file->status_verifikasi === 'terverifikasi' ? 'true' : 'false' }}) }"
                                @click="pendingMark = 'ok'"
                                wire:click="tandaiSesuai({{ $file->id }})" wire:loading.attr="disabled" wire:target="tandaiSesuai({{ $file->id }}),tandaiTolak({{ $file->id }})">
                                <span wire:loading.remove wire:target="tandaiSesuai({{ $file->id }})">✓ Sesuai</span>
                                <span wire:loading wire:target="tandaiSesuai({{ $file->id }})"><i class="spin"></i></span>
                            </button>
                            <button type="button" class="mark"
                                :class="{ no: pendingMark === 'no' || (pendingMark === null && {{ $file->status_verifikasi === 'ditolak' ? 'true' : 'false' }}) }"
                                @click="pendingMark = 'no'"
                                wire:click="tandaiTolak({{ $file->id }})" wire:loading.attr="disabled" wire:target="tandaiSesuai({{ $file->id }}),tandaiTolak({{ $file->id }})">
                                <span wire:loading.remove wire:target="tandaiTolak({{ $file->id }})">✕ Tidak Sesuai</span>
                                <span wire:loading wire:target="tandaiTolak({{ $file->id }})"><i class="spin"></i></span>
                            </button>
                            <div class="field" style="margin-top:12px" x-cloak
                                x-show="pendingMark === 'no' || (pendingMark === null && {{ $file->status_verifikasi === 'ditolak' ? 'true' : 'false' }})">
                                <label style="font-size:11.5px">Catatan (wajib bila tidak sesuai)</label>
                                {{-- Klik "Tidak Sesuai" saat catatan masih kosong ditolak validasi (lihat
                                    tandaiTolak()) — tombolnya SUDAH terlanjur merah lewat pendingMark
                                    optimistic di atas, jadi kalau berhenti di situ saja pengguna mengira
                                    penandaannya sudah tersimpan padahal belum. @blur di sini menyusulkan
                                    ulang tandaiTolak() begitu catatan selesai diisi (fokus pindah, termasuk
                                    saat modal ditutup) supaya tidak perlu klik "Tidak Sesuai" dua kali. --}}
                                <textarea class="inp filled" style="height:auto;display:block;min-height:56px;font-size:11.5px" rows="3"
                                    wire:model="catatanBerkas.{{ $file->id }}" placeholder="mis. Bukti belum menunjukkan tanggal pelaksanaan…"
                                    :style="pendingMark === 'no' && {{ $file->status_verifikasi !== 'ditolak' ? 'true' : 'false' }} ? 'border-color:var(--red)' : ''"
                                    x-on:blur="if (pendingMark === 'no' || (pendingMark === null && {{ $file->status_verifikasi === 'ditolak' ? 'true' : 'false' }})) $wire.tandaiTolak({{ $file->id }})"></textarea>
                                @error('catatanBerkas.'.$file->id)
                                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Tombol simpan eksplisit untuk penandaan Sesuai/Tidak Sesuai
                                beserta catatannya — @blur di atas sudah menyusulkan simpan
                                otomatis, tapi pengguna tidak selalu sadar itu terjadi (mis.
                                langsung menutup modal setelah mengetik). Tombol ini memicu
                                penyimpanan yang sama secara eksplisit, dan label "✓ Tersimpan"
                                di sampingnya (dibaca langsung dari status_verifikasi, bukan
                                state Alpine) memberi kepastian nyata bahwa penandaan + catatan
                                sudah ada di database, bukan cuma tampak begitu. Catatan hanya
                                relevan untuk "Tidak Sesuai" — tandaiSesuai() di komponen
                                SELALU mengosongkannya (lihat catatan di sana). --}}
                            <div style="margin-top:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap"
                                x-show="pendingMark !== null || {{ $file->status_verifikasi !== 'menunggu' ? 'true' : 'false' }}">
                                <button type="button" class="btn btn-ghost btn-sm"
                                    x-on:click="(pendingMark === 'no' || (pendingMark === null && {{ $file->status_verifikasi === 'ditolak' ? 'true' : 'false' }})) ? $wire.tandaiTolak({{ $file->id }}) : $wire.tandaiSesuai({{ $file->id }})"
                                    wire:loading.attr="disabled" wire:target="tandaiSesuai({{ $file->id }}),tandaiTolak({{ $file->id }})">
                                    <span wire:loading.remove wire:target="tandaiSesuai({{ $file->id }}),tandaiTolak({{ $file->id }})">💾 Simpan Verifikasi</span>
                                    <span wire:loading wire:target="tandaiSesuai({{ $file->id }}),tandaiTolak({{ $file->id }})"><i class="spin"></i> Menyimpan…</span>
                                </button>
                                @if ($file->status_verifikasi !== 'menunggu' && !$errors->has('catatanBerkas.'.$file->id))
                                    <span style="color:#16a34a;font-size:11.5px">✓ Tersimpan</span>
                                @endif
                            </div>
                        @else
                            <span class="mark {{ $file->status_verifikasi === 'terverifikasi' ? 'ok' : ($file->status_verifikasi === 'ditolak' ? 'no' : '') }}" style="cursor:default">{{ $file->status_verifikasi === 'terverifikasi' ? '✓ Sesuai' : ($file->status_verifikasi === 'ditolak' ? '✕ Tidak Sesuai' : '… Menunggu') }}</span>
                            @if ($tautanRtl && (int) $tautanRtl['kegiatan']->periode_id !== (int) $capaian->periode_id)
                                <div style="font-size:11px;color:var(--muted);margin-top:6px">🔒 Bukti kegiatan bulan {{ $tautanRtl['kegiatan']->periode ? \Illuminate\Support\Carbon::create(null, $tautanRtl['kegiatan']->periode->bulan, 1)->locale('id')->translatedFormat('F') : 'lain' }} — diperiksa pada verifikasi isian bulan itu; statusnya dipakai apa adanya di sini.</div>
                            @elseif ($bisaDiverifikasi)
                                <div style="font-size:11px;color:var(--muted);margin-top:6px">🔒 Milik kegiatan yang sudah diproses sebelumnya.</div>
                            @endif
                            @if ($file->catatan)
                                <div class="field" style="margin-top:12px">
                                    <label style="font-size:11.5px">Catatan</label>
                                    <p style="font-size:12.5px;color:var(--muted);margin:0">{{ $file->catatan }}</p>
                                </div>
                            @endif
                        @endif

                        @if (($riwayatVerifikasiBerkas[$file->id] ?? collect())->isNotEmpty())
                            <div style="margin-top:14px;font-size:11px;color:var(--muted);line-height:1.6">
                                <div style="font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px">Riwayat Verifikasi</div>
                                @foreach ($riwayatVerifikasiBerkas[$file->id]->take(5) as $r)
                                    <div wire:key="riwayat-berkas-{{ $r->id }}">{{ $r->created_at?->translatedFormat('d M Y H:i') }} · {{ $r->status_baru === 'terverifikasi' ? 'Sesuai' : 'Tidak Sesuai' }}{{ $r->user ? ' oleh '.$r->user->nama : '' }}{{ $r->catatan ? ' — '.\Illuminate\Support\Str::limit($r->catatan, 60) : '' }}</div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @php $adaRtlBerikutnya = $rtlBerikutnya->isNotEmpty() || $rtlBerikutnyaTambahan !== []; @endphp
    @if ($adaRtlBerikutnya || $rtlBerikutnyaBisaDitambah)
        <div class="card">
            <div class="sec" id="bagian-rtl" style="scroll-margin-top:var(--offset-lengket, 200px)"><span>Rencana Tindak Lanjut Triwulan Berikutnya (Baru Ditetapkan)</span></div>
            <div class="info">ℹ️ Ketua Tim baru menetapkan rencana ini untuk triwulan berikutnya (belum berjalan, belum ada realisasi) — periksa kejelasan/kelayakan rencananya. Kalau ditandai "Tidak Sesuai", seluruh rencana IKU ini akan dibuka kembali untuk diperbaiki Ketua Tim.</div>

            {{-- PIC & Batas Waktu berlaku untuk SELURUH poin di bawah (satu nilai per
                 batch, bukan per poin — sama seperti cara Ketua Tim mengisinya) — cukup
                 ditampilkan sekali di sini, tidak diulang di tiap poin. PIC wajib
                 diisi/dikonfirmasi Tim SAKIP di sini (boleh kosong dari Ketua Tim, lihat
                 App\Livewire\PengisianKegiatan) sebelum "Verifikasi Selesai" bisa ditekan. --}}
            @if ($adaRtlBerikutnya)
                <div class="row2" style="margin-bottom:10px;align-items:flex-end">
                    <div class="field" style="margin-bottom:0">
                        <label>PIC Tindak Lanjut <span class="req">*</span></label>
                        <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:6px">
                            @forelse ($picRtlBerikutnyaTerpilih as $tim)
                                <span class="chip chip-tim" wire:key="pic-rtl-{{ $loop->index }}">
                                    {{ $tim }}
                                    @if ($bisaDiverifikasi)
                                        <span class="chip-x" wire:click="hapusPicRtlBerikutnya('{{ $tim }}')">✕</span>
                                    @endif
                                </span>
                            @empty
                                <span class="muted" style="font-size:11.5px">— Pilih tim PIC —</span>
                            @endforelse
                        </div>
                        @if ($bisaDiverifikasi)
                            <div style="display:flex;gap:6px">
                                <input type="text" class="inp filled" list="daftar-tim-pic" wire:model="picRtlBerikutnyaBaru"
                                    wire:keydown.enter.prevent="tambahPicRtlBerikutnya" placeholder="Pilih dari saran atau ketik tim baru…">
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="tambahPicRtlBerikutnya">＋ Tambah</button>
                            </div>
                        @endif
                        <datalist id="daftar-tim-pic">
                            @foreach ($daftarTimPic as $tim)
                                <option value="{{ $tim }}"></option>
                            @endforeach
                        </datalist>
                        @error('picRtlBerikutnyaTerpilih')
                            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label>Batas Waktu</label>
                        <p style="margin:0;font-size:13px">{{ $rtlBerikutnya->first()?->batas_waktu?->translatedFormat('d F Y') ?? 'Akhir triwulan berikutnya' }}</p>
                    </div>
                </div>
            @endif

            @foreach ($rtlBerikutnya as $poin)
                <div class="poin-single" wire:key="rtl-berikutnya-{{ $poin->id }}">
                    <span class="k-num stat-in">Poin RTL {{ $loop->iteration }}</span>
                    @if ($this->rtlBerikutnyaBisaDiverifikasi($poin->id))
                        <button type="button" class="btn btn-red btn-sm" style="position:absolute;top:8px;right:8px" wire:click="hapusRtlBerikutnya({{ $poin->id }})" wire:loading.attr="disabled" wire:loading.class="btn-busy" wire:target="hapusRtlBerikutnya({{ $poin->id }})">🗑</button>
                    @endif

                    <div class="field" style="margin-bottom:10px">
                        <label>Rencana Kegiatan</label>
                        <textarea class="inp filled" style="height:auto;display:block;font-style:italic" rows="2" wire:model.live.blur="koreksiRtlBerikutnya.{{ $poin->id }}">{{ $this->koreksiRtlBerikutnya[$poin->id] ?? $poin->rtl_teks }}</textarea>
                        @error("koreksiRtlBerikutnya.{$poin->id}")
                            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                        @enderror
                    </div>

                    <x-badge-status :status="$poin->status_verifikasi === 'terverifikasi' ? 'disetujui' : ($poin->status_verifikasi === 'ditolak' ? 'dikembalikan' : 'diajukan')" :label="$poin->status_verifikasi === 'terverifikasi' ? 'Sesuai' : ($poin->status_verifikasi === 'ditolak' ? 'Tidak Sesuai' : 'Menunggu')" />

                    @if ($this->rtlBerikutnyaBisaDiverifikasi($poin->id))
                        <div x-data="{ pendingMark: null }" style="margin-top:10px">
                            <div style="display:flex;gap:8px">
                                <button type="button" class="mark"
                                    :class="{ ok: pendingMark === 'ok' || (pendingMark === null && {{ $poin->status_verifikasi === 'terverifikasi' ? 'true' : 'false' }}) }"
                                    @click="pendingMark = 'ok'"
                                    wire:click="tandaiRtlBerikutnyaSesuai({{ $poin->id }})" wire:loading.attr="disabled" wire:target="tandaiRtlBerikutnyaSesuai({{ $poin->id }}),tandaiRtlBerikutnyaTolak({{ $poin->id }})">
                                    <span wire:loading.remove wire:target="tandaiRtlBerikutnyaSesuai({{ $poin->id }})">✓ Sesuai</span>
                                    <span wire:loading wire:target="tandaiRtlBerikutnyaSesuai({{ $poin->id }})"><i class="spin"></i></span>
                                </button>
                                <button type="button" class="mark"
                                    :class="{ no: pendingMark === 'no' || (pendingMark === null && {{ $poin->status_verifikasi === 'ditolak' ? 'true' : 'false' }}) }"
                                    @click="pendingMark = 'no'"
                                    wire:click="tandaiRtlBerikutnyaTolak({{ $poin->id }})" wire:loading.attr="disabled" wire:target="tandaiRtlBerikutnyaSesuai({{ $poin->id }}),tandaiRtlBerikutnyaTolak({{ $poin->id }})">
                                    <span wire:loading.remove wire:target="tandaiRtlBerikutnyaTolak({{ $poin->id }})">✕ Tidak Sesuai</span>
                                    <span wire:loading wire:target="tandaiRtlBerikutnyaTolak({{ $poin->id }})"><i class="spin"></i></span>
                                </button>
                            </div>

                            <div class="field" style="margin-top:10px;margin-bottom:0" x-cloak
                                x-show="pendingMark === 'no' || (pendingMark === null && {{ $poin->status_verifikasi === 'ditolak' ? 'true' : 'false' }})">
                                <label style="font-size:11.5px">Catatan (wajib bila tidak sesuai)</label>
                                <textarea class="inp filled" style="height:auto;display:block;font-size:11.5px" rows="2"
                                    wire:model="catatanRtlBerikutnya.{{ $poin->id }}" placeholder="mis. Rencana belum spesifik/tidak jelas PIC-nya"
                                    x-on:blur="if (pendingMark === 'no' || (pendingMark === null && {{ $poin->status_verifikasi === 'ditolak' ? 'true' : 'false' }})) $wire.tandaiRtlBerikutnyaTolak({{ $poin->id }})"></textarea>
                                @error('catatanRtlBerikutnya.'.$poin->id)
                                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                                @enderror
                            </div>

                            <div style="margin-top:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap"
                                x-show="pendingMark !== null || {{ $poin->status_verifikasi !== 'menunggu' ? 'true' : 'false' }}">
                                <button type="button" class="btn btn-ghost btn-sm"
                                    x-on:click="(pendingMark === 'no' || (pendingMark === null && {{ $poin->status_verifikasi === 'ditolak' ? 'true' : 'false' }})) ? $wire.tandaiRtlBerikutnyaTolak({{ $poin->id }}) : $wire.tandaiRtlBerikutnyaSesuai({{ $poin->id }})"
                                    wire:loading.attr="disabled" wire:target="tandaiRtlBerikutnyaSesuai({{ $poin->id }}),tandaiRtlBerikutnyaTolak({{ $poin->id }})">
                                    <span wire:loading.remove wire:target="tandaiRtlBerikutnyaSesuai({{ $poin->id }}),tandaiRtlBerikutnyaTolak({{ $poin->id }})">💾 Simpan Verifikasi</span>
                                    <span wire:loading wire:target="tandaiRtlBerikutnyaSesuai({{ $poin->id }}),tandaiRtlBerikutnyaTolak({{ $poin->id }})"><i class="spin"></i> Menyimpan…</span>
                                </button>
                                @if ($poin->status_verifikasi !== 'menunggu' && !$errors->has('catatanRtlBerikutnya.'.$poin->id))
                                    <span style="color:#16a34a;font-size:11.5px">✓ Tersimpan</span>
                                @endif
                            </div>
                        </div>
                    @elseif ($poin->catatan)
                        <div class="field" style="margin-top:8px;margin-bottom:0">
                            <label style="font-size:11.5px">Catatan</label>
                            <p style="font-size:12.5px;color:var(--muted);margin:0">{{ $poin->catatan }}</p>
                        </div>
                    @endif
                </div>
            @endforeach

            {{-- Poin baru dari Tim SAKIP sendiri — baru tersimpan saat Simpan/Verifikasi
                 Selesai ditekan, lalu langsung berstatus "Sesuai". --}}
            @foreach ($rtlBerikutnyaTambahan as $kunci => $teks)
                <div class="poin-single" wire:key="rtl-berikutnya-baru-{{ $kunci }}">
                    <span class="k-num stat-in">Poin RTL baru</span>
                    <button type="button" class="btn btn-red btn-sm" style="position:absolute;top:8px;right:8px" title="Batal tambah"
                        wire:click="hapusRtlBerikutnyaTambahan('{{ $kunci }}')">🗑</button>
                    <div class="field" style="margin-bottom:0">
                        <label>Rencana Kegiatan</label>
                        <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model.live.blur="rtlBerikutnyaTambahan.{{ $kunci }}" placeholder="Tuliskan rencana tindak lanjut…"></textarea>
                    </div>
                </div>
            @endforeach

            @if ($rtlBerikutnyaBisaDitambah)
                <button type="button" class="btn btn-ghost btn-sm" style="margin-top:8px" wire:click="tambahRtlBerikutnya" wire:loading.attr="disabled" wire:target="tambahRtlBerikutnya">+ Tambah Poin RTL</button>
            @endif
        </div>
    @endif

    @if ($rtlSebelumnya->isNotEmpty())
        <div class="card">
            <div class="sec" id="bagian-evaluasi-rtl" style="scroll-margin-top:var(--offset-lengket, 200px)"><span>Pengecekan Realisasi RTL Triwulan Sebelumnya</span></div>
            <div class="info">ℹ️ RTL yang direncanakan pada triwulan sebelumnya ditampilkan berdampingan dengan realisasi yang dilaporkan ketua tim, beserta bukti bila ada. Cukup periksa kesesuaiannya — tanpa skor persentase.</div>

            @foreach ($rtlSebelumnya as $poin)
                <div class="match-row" wire:key="rtl-check-{{ $poin->id }}">
                    <div class="match-col">
                        <div class="mc-lbl">RTL Direncanakan</div><div class="mc-txt">{{ $poin->rtl_teks }}</div>
                        {{-- Rencana ditetapkan & diajukan pada triwulan sebelumnya — hanya referensi,
                             tidak bisa disunting dari verifikasi triwulan berjalan. --}}
                        <div style="font-size:11px;color:var(--muted);margin-top:6px;line-height:1.5">
                            {{ $poin->berlaku_bulan }}@if ($poin->pic) · PIC: {{ $poin->pic }}@endif @if ($poin->batas_waktu) · Batas waktu: {{ $poin->batas_waktu->translatedFormat('d F Y') }}@endif
                            <br>🔒 Ditetapkan pada triwulan sebelumnya — referensi, tidak diubah oleh isian triwulan ini.
                        </div>
                    </div>
                    <div class="match-badge {{ $poin->sudahDievaluasi() ? 'mb-ok' : 'mb-warn' }}">{{ $poin->sudahDievaluasi() ? '✓' : '…' }}</div>
                    <div class="match-col">
                        <div class="mc-lbl">Realisasi Dilaporkan</div>
                        <textarea class="inp filled" style="height:auto;display:block;font-style:italic;font-size:12px" rows="2"
                            wire:model.live.blur="koreksiRtlRealisasi.{{ $poin->id }}" placeholder="{{ $poin->sudahDievaluasi() ? '— tanpa keterangan teks, periksa bukti realisasi di bawah —' : '— belum dilaporkan —' }}"></textarea>

                        @php
                            $buktiKegiatanRtl = $poin->buktiDariKegiatan();
                            $kesesuaianBukti = \App\Models\RtlEvaluasi::kesesuaianBukti($buktiKegiatanRtl->pluck('berkas')->concat($poin->berkas));
                            $salinanRtl = $poin->salinanBukti->keyBy('berkas_id');
                            $labelKesesuaian = [
                                'sesuai' => ['disetujui', 'Semua bukti Sesuai'],
                                'tidak_sesuai' => ['dikembalikan', 'Ada bukti Tidak Sesuai'],
                                'menunggu' => ['diajukan', 'Ada bukti belum diperiksa'],
                                'kosong' => ['draft', 'Belum ada bukti'],
                            ][$kesesuaianBukti];
                        @endphp

                        {{-- Kesesuaian BUKTI (dari status tiap berkas, satu sumber dengan
                             Kegiatan) — terpisah dari penilaian realisasi RTL di bawah. --}}
                        <div style="display:flex;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap">
                            <span style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.4px">Kesesuaian Bukti</span>
                            <x-badge-status :status="$labelKesesuaian[0]" :label="$labelKesesuaian[1]" />
                        </div>

                        @if ($buktiKegiatanRtl->isNotEmpty())
                            <div style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.4px;margin:10px 0 2px">🔗 Dari Bukti Kegiatan</div>
                            <div style="font-size:11px;color:var(--muted);margin-bottom:4px">Berkas yang sama dengan bukti capaian kegiatan — statusnya mengikuti hasil pemeriksaan bukti kegiatan itu, tidak perlu diperiksa dua kali.</div>
                            @foreach ($buktiKegiatanRtl as $tertaut)
                                @php
                                    $file = $tertaut['berkas'];
                                    $kegiatanAsal = $tertaut['kegiatan'];
                                    $salinan = $salinanRtl->get($file->id);
                                @endphp
                                <div class="filechip {{ $file->status_verifikasi === 'terverifikasi' ? 'ok' : ($file->status_verifikasi === 'ditolak' ? 'no' : '') }}" style="margin-top:6px" wire:key="rtl-{{ $poin->id }}-keg-berkas-{{ $file->id }}">
                                    <span class="nm">
                                        <x-nama-berkas :id="'rtl'.$poin->id.'-'.$file->id" :nama="$file->nama_file" :diunggah-oleh="$file->diunggah_oleh" :status="$file->status_verifikasi" :bisa-ubah="false" />
                                        <span class="sub">
                                            Sumber: Kegiatan "{{ \Illuminate\Support\Str::limit($kegiatanAsal->uraianTampil(), 70) }}"@if ($kegiatanAsal->labelTahapan()) · Tahapan {{ $kegiatanAsal->labelTahapan() }}@endif
                                            · 📁 {{ $kegiatanAsal->namaFolder() }}@if (filled($file->subfolder)) / {{ $file->subfolder }}@endif
                                            @if ($kegiatanAsal->periode && (int) $kegiatanAsal->periode_id !== (int) $capaian->periode_id) · isian bulan {{ \Illuminate\Support\Carbon::create(null, $kegiatanAsal->periode->bulan, 1)->locale('id')->translatedFormat('F') }}@endif
                                        </span>
                                        <span class="sub" style="color:{{ $file->status_verifikasi === 'terverifikasi' ? 'var(--teal)' : ($file->status_verifikasi === 'ditolak' ? 'var(--red)' : 'var(--muted)') }}">
                                            Status bukti: {{ $file->status_verifikasi === 'terverifikasi' ? '✓ Sesuai' : ($file->status_verifikasi === 'ditolak' ? '✕ Tidak Sesuai' : '… Belum diperiksa') }}
                                            @if ($salinan?->mutakhir($file)) · 📂 tersalin di folder Evaluasi-RTL @elseif ($salinan?->status === \App\Models\SalinanBuktiRtl::STATUS_GAGAL) · ⚠️ salinan Evaluasi-RTL belum tersedia @endif
                                        </span>
                                    </span>
                                    <button type="button" class="btn btn-ghost btn-sm" @click="modalBerkas = {{ $file->id }}">🔍 Periksa</button>
                                </div>
                            @endforeach
                        @endif

                        @if ($poin->berkas->isNotEmpty())
                            <div style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.4px;margin:10px 0 2px">📤 Unggahan Mandiri Evaluasi RTL</div>
                            <div style="font-size:11px;color:var(--muted);margin-bottom:4px">Diperiksa tersendiri.{{ $poin->berkas->where('status_verifikasi', 'menunggu')->isNotEmpty() ? ' '.$poin->berkas->where('status_verifikasi', 'menunggu')->count().' belum diperiksa (klik 🔍 Periksa).' : '' }}</div>
                            @foreach ($poin->berkas as $file)
                                <div class="filechip {{ $file->status_verifikasi === 'terverifikasi' ? 'ok' : ($file->status_verifikasi === 'ditolak' ? 'no' : '') }}" style="margin-top:6px" wire:key="berkas-{{ $file->id }}">
                                    <span class="nm">
                                        <x-nama-berkas :id="$file->id" :nama="$file->nama_file" :diunggah-oleh="$file->diunggah_oleh" :status="$file->status_verifikasi" />
                                        @if (filled($file->tahapan) || filled($file->subfolder))
                                            <span class="sub">📁 {{ collect([$file->tahapan, $file->subfolder])->filter()->join(' / ') }}</span>
                                        @endif
                                        @if ($file->status_verifikasi === 'ditolak')
                                            <span class="sub" style="color:var(--red)">Tidak Sesuai</span>
                                        @endif
                                    </span>
                                    <button type="button" class="btn btn-ghost btn-sm" @click="modalBerkas = {{ $file->id }}">🔍 Periksa</button>
                                </div>
                            @endforeach
                        @endif

                        @if (! $poin->sudahDievaluasi())
                            <div style="font-size:11.5px;margin-top:6px;color:var(--muted)">Belum ada bukti realisasi diunggah Ketua Tim — belum bisa diverifikasi.</div>
                        @endif

                        @if ($poin->status_verifikasi === 'terverifikasi' && $kesesuaianBukti === \App\Models\RtlEvaluasi::KESESUAIAN_TIDAK_SESUAI)
                            <div class="info red" style="margin-top:8px;font-size:11.5px">⚠️ Realisasi RTL ini sebelumnya dinilai Sesuai, tetapi kini ada bukti yang berstatus Tidak Sesuai — tinjau kembali penilaian realisasinya.</div>
                        @endif

                        <div class="mc-lbl" style="margin-top:12px">Penilaian Realisasi RTL</div>

                        @if ($this->rtlBisaDiverifikasi($poin->id))
                            <div x-data="{ pendingMark: null }" style="margin-top:8px">
                                <div style="display:flex;gap:8px">
                                    <button type="button" class="mark"
                                        :class="{ ok: pendingMark === 'ok' || (pendingMark === null && {{ $poin->status_verifikasi === 'terverifikasi' ? 'true' : 'false' }}) }"
                                        @click="pendingMark = 'ok'"
                                        wire:click="tandaiRtlSesuai({{ $poin->id }})" wire:loading.attr="disabled" wire:target="tandaiRtlSesuai({{ $poin->id }}),tandaiRtlTolak({{ $poin->id }})">
                                        <span wire:loading.remove wire:target="tandaiRtlSesuai({{ $poin->id }})">✓ Sesuai</span>
                                        <span wire:loading wire:target="tandaiRtlSesuai({{ $poin->id }})"><i class="spin"></i></span>
                                    </button>
                                    <button type="button" class="mark"
                                        :class="{ no: pendingMark === 'no' || (pendingMark === null && {{ $poin->status_verifikasi === 'ditolak' ? 'true' : 'false' }}) }"
                                        @click="pendingMark = 'no'"
                                        wire:click="tandaiRtlTolak({{ $poin->id }})" wire:loading.attr="disabled" wire:target="tandaiRtlSesuai({{ $poin->id }}),tandaiRtlTolak({{ $poin->id }})">
                                        <span wire:loading.remove wire:target="tandaiRtlTolak({{ $poin->id }})">✕ Tidak Sesuai</span>
                                        <span wire:loading wire:target="tandaiRtlTolak({{ $poin->id }})"><i class="spin"></i></span>
                                    </button>
                                </div>

                                <div class="field" style="margin-top:10px;margin-bottom:0" x-cloak
                                    x-show="pendingMark === 'no' || (pendingMark === null && {{ $poin->status_verifikasi === 'ditolak' ? 'true' : 'false' }})">
                                    <label style="font-size:11.5px">Catatan (wajib bila tidak sesuai)</label>
                                    <textarea class="inp filled" style="height:auto;display:block;font-size:11.5px" rows="2"
                                        wire:model="catatanRtl.{{ $poin->id }}" placeholder="mis. Realisasi belum sesuai dengan RTL yang direncanakan"
                                        x-on:blur="if (pendingMark === 'no' || (pendingMark === null && {{ $poin->status_verifikasi === 'ditolak' ? 'true' : 'false' }})) $wire.tandaiRtlTolak({{ $poin->id }})"></textarea>
                                    @error('catatanRtl.'.$poin->id)
                                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div style="margin-top:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap"
                                    x-show="pendingMark !== null || {{ $poin->status_verifikasi !== 'menunggu' ? 'true' : 'false' }}">
                                    <button type="button" class="btn btn-ghost btn-sm"
                                        x-on:click="(pendingMark === 'no' || (pendingMark === null && {{ $poin->status_verifikasi === 'ditolak' ? 'true' : 'false' }})) ? $wire.tandaiRtlTolak({{ $poin->id }}) : $wire.tandaiRtlSesuai({{ $poin->id }})"
                                        wire:loading.attr="disabled" wire:target="tandaiRtlSesuai({{ $poin->id }}),tandaiRtlTolak({{ $poin->id }})">
                                        <span wire:loading.remove wire:target="tandaiRtlSesuai({{ $poin->id }}),tandaiRtlTolak({{ $poin->id }})">💾 Simpan Verifikasi</span>
                                        <span wire:loading wire:target="tandaiRtlSesuai({{ $poin->id }}),tandaiRtlTolak({{ $poin->id }})"><i class="spin"></i> Menyimpan…</span>
                                    </button>
                                    @if ($poin->status_verifikasi !== 'menunggu' && !$errors->has('catatanRtl.'.$poin->id))
                                        <span style="color:#16a34a;font-size:11.5px">✓ Tersimpan</span>
                                    @endif
                                </div>
                            </div>
                        @elseif ($poin->catatan)
                            <div class="field" style="margin-top:8px;margin-bottom:0">
                                <label style="font-size:11.5px">Catatan</label>
                                <p style="font-size:12.5px;color:var(--muted);margin:0">{{ $poin->catatan }}</p>
                            </div>
                        @endif

                        @if ($poin->catatan_bukti_dihapus)
                            <div class="info red" style="margin-top:8px;font-size:11.5px">
                                🗑️ <b>Ketua Tim menghapus bukti sebelumnya</b> — alasan penolakan sebelumnya: "{{ $poin->catatan_bukti_dihapus }}". Periksa apakah bukti pengganti di atas sudah memperbaikinya.
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($bisaDiverifikasi)
        {{-- Sasaran tombol "Tindakan" di bilah navigasi bagian. --}}
        <div id="bagian-tindakan" style="scroll-margin-top:var(--offset-lengket, 200px)"></div>
        {{-- Panel status: merinci PERSIS apa yang masih menghalangi "Verifikasi Selesai" /
             "Kembalikan ke Ketua Tim", supaya Tim SAKIP tidak perlu menyisir ulang halaman. --}}
        @if ($daftarBelumDitandai !== [] || $daftarDitolak !== [] || $syaratLain !== [])
            <div class="info warn" style="margin-top:16px;display:block" wire:key="panel-syarat-verifikasi">
                @if ($daftarBelumDitandai !== [])
                    <strong>⏳ {{ count($daftarBelumDitandai) }} isian belum ditandai "Sesuai"/"Tidak Sesuai":</strong>
                    <ul style="margin:6px 0 8px;padding-left:18px">
                        @foreach ($daftarBelumDitandai as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                @endif
                @if ($daftarDitolak !== [])
                    <strong>✗ {{ count($daftarDitolak) }} isian ditandai "Tidak Sesuai" — akan dikembalikan ke Ketua Tim untuk diperbaiki:</strong>
                    <ul style="margin:6px 0 8px;padding-left:18px">
                        @foreach ($daftarDitolak as $item)
                            <li>{{ $item['label'] }}@if (filled($item['catatan'])) — <i>{{ $item['catatan'] }}</i>@endif</li>
                        @endforeach
                    </ul>
                @endif
                @if ($syaratLain !== [])
                    <strong>⚠️ Perlu dilengkapi sebelum "Verifikasi Selesai":</strong>
                    <ul style="margin:6px 0 8px;padding-left:18px">
                        @foreach ($syaratLain as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                @endif
                <div style="opacity:.85">
                    @if ($daftarBelumDitandai !== [])
                        Tandai seluruh isian di atas terlebih dahulu.
                    @endif
                    @if ($daftarDitolak !== [])
                        Karena ada isian "Tidak Sesuai", gunakan <b>"Kembalikan ke Ketua Tim"</b>{{ $daftarBelumDitandai !== [] ? ' setelah semua isian ditandai' : '' }} — "Verifikasi Selesai" hanya bisa dipakai bila semua isian "Sesuai".
                    @elseif ($daftarBelumDitandai === [] && $syaratLain !== [])
                        Semua isian sudah "Sesuai" — lengkapi poin di atas lalu klik "Verifikasi Selesai".
                    @endif
                </div>
            </div>
        @else
            <div class="info teal" style="margin-top:16px">✅ Seluruh isian sudah ditandai "Sesuai" — siap diselesaikan lewat "Verifikasi Selesai".</div>
        @endif
        <div class="btn-row" style="margin-top:16px">
            <button type="button" class="btn btn-teal" wire:click="verifikasiSelesai" wire:loading.attr="disabled" wire:target="verifikasiSelesai,kembalikanKeKetuaTim,simpanSementara" @disabled($adaYangDitolak || $adaBelumDitandai) title="{{ $adaYangDitolak ? 'Ada isian yang ditandai Tidak Sesuai — gunakan "Kembalikan ke Ketua Tim"' : ($adaBelumDitandai ? 'Masih ada isian yang belum ditandai Sesuai/Tidak Sesuai — tombol aktif setelah semuanya diverifikasi' : '') }}">
                <span wire:loading.remove wire:target="verifikasiSelesai">✓ Verifikasi Selesai — Masukkan ke Notula</span>
                <span wire:loading wire:target="verifikasiSelesai"><i class="spin"></i> Memproses…</span>
            </button>
            <button type="button" class="btn btn-red" wire:click="kembalikanKeKetuaTim" wire:loading.attr="disabled" wire:target="verifikasiSelesai,kembalikanKeKetuaTim,simpanSementara" @disabled(! $adaYangDitolak) title="{{ ! $adaYangDitolak ? 'Tandai minimal satu isian Tidak Sesuai sebelum mengembalikan isian' : '' }}">
                <span wire:loading.remove wire:target="kembalikanKeKetuaTim">↩ Kembalikan ke Ketua Tim</span>
                <span wire:loading wire:target="kembalikanKeKetuaTim"><i class="spin"></i> Mengembalikan…</span>
            </button>
            <button type="button" class="btn btn-ghost" wire:click="simpanSementara" wire:loading.attr="disabled" wire:target="verifikasiSelesai,kembalikanKeKetuaTim,simpanSementara">
                <span wire:loading.remove wire:target="simpanSementara">💾 Simpan Sementara</span>
                <span wire:loading wire:target="simpanSementara"><i class="spin"></i> Menyimpan…</span>
            </button>
        </div>
        @if ($capaian->status === \App\Models\Capaian::STATUS_SEDANG_DITANGANI)
            <div class="info" style="margin-top:10px">💾 Pemeriksaan ini masih <b>Sedang Ditangani</b> — progres tersimpan, lanjutkan menandai berkas lalu pilih "Verifikasi Selesai" atau "Kembalikan ke Ketua Tim" saat sudah selesai memeriksa semuanya.</div>
        @endif
    @elseif ($bisaDibukaKembali)
        <div class="card">
            <div class="sec" id="bagian-tindakan" style="scroll-margin-top:var(--offset-lengket, 200px)"><span>Buka Kembali Isian</span></div>
            <div class="info warn">⚠️ Isian ini sudah <b>disetujui</b> dan masuk notula final. Membuka kembali akan mengembalikannya ke Ketua Tim untuk ditambahkan kegiatan baru — kegiatan yang sudah disetujui sebelumnya TIDAK ikut berubah/terhapus. Untuk sekadar memperbaiki kesalahan, cukup sunting langsung di atas lalu "Simpan Perubahan" — tidak perlu dibuka kembali.</div>
            <div class="field" style="margin-top:10px">
                <label style="font-size:11.5px">Alasan membuka kembali (opsional)</label>
                <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model="catatanBukaKembali" placeholder="mis. Ada kegiatan tambahan yang perlu dilaporkan…"></textarea>
            </div>
            <div class="btn-row" style="margin-top:12px">
                <button type="button" class="btn btn-red" wire:click="bukaKembali" wire:loading.attr="disabled" wire:target="bukaKembali">
                    <span wire:loading.remove wire:target="bukaKembali">↩ Buka Kembali ke Ketua Tim</span>
                    <span wire:loading wire:target="bukaKembali"><i class="spin"></i> Memproses…</span>
                </button>
                <a wire:navigate href="{{ route('verifikasi.index') }}" class="btn btn-ghost">← Kembali ke Daftar</a>
            </div>
        </div>
    @else
        <div class="btn-row" style="margin-top:16px">
            <a wire:navigate href="{{ route('verifikasi.index') }}" class="btn btn-ghost">← Kembali ke Daftar</a>
        </div>
    @endif

    <x-status-simpan aksi="simpanPerubahan" label-aksi="💾 Simpan Perubahan"
        pesan-belum="Ada perubahan analisis/angka/koreksi teks yang belum disimpan ke database. (Tanda Sesuai/Tidak Sesuai sudah tersimpan otomatis.)" />
</div>
