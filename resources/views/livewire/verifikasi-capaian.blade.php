@php
    $namaBulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
@endphp

<div x-data="{ modalBerkas: null }">
    <div class="page-title">Detail Verifikasi — {{ $capaian->masterIku->kode }}</div>
    <div class="page-sub">
        {{ $capaian->masterIku->indikator }} · {{ $namaBulan[$capaian->periode->bulan - 1] }} {{ $capaian->periode->tahun }} ·
        {{ $kegiatanList->count() }} kegiatan pendukung
    </div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    @error('berkas')
        <div class="badge b-kembali" style="display:block;margin-bottom:14px">{{ $message }}</div>
    @enderror

    <div class="info teal">✏️ Teks yang bergaris putus-putus (uraian kegiatan, kendala, solusi, realisasi RTL) bisa langsung disunting bila ada salah ketik dari isian ketua tim.</div>

    <div class="card">
        <div class="sec"><span>Angka Capaian</span> <span class="badge b-tunggu">diisi Tim SAKIP</span></div>

        <div class="field">
            <label>Analisis Capaian</label>
            <textarea class="inp filled" style="height:auto;display:block" rows="3" wire:model="analisis_capaian"
                placeholder="Narasi analisis capaian..."></textarea>
            @error('analisis_capaian')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>

        <div class="num-grid">
            <div class="num-cell">
                <label>Target PK</label>
                <input type="number" step="0.01" class="box" style="width:100%" wire:model="target_pk">
            </div>
            <div class="num-cell">
                <label>Target TW</label>
                <input type="number" step="0.01" class="box" style="width:100%" wire:model.live="target_tw">
            </div>
            <div class="num-cell">
                <label>Realisasi</label>
                <input type="number" step="0.01" class="box" style="width:100%" wire:model.live="realisasi">
            </div>
            <div class="num-cell">
                <label>Capaian %</label>
                <input type="number" step="0.01" class="box" style="width:100%;background:#f4f6fa" wire:model="persentase_capaian" readonly>
            </div>
        </div>
        @error('target_pk')
            <div style="color:var(--red);font-size:11.5px;margin-top:8px">{{ $message }}</div>
        @enderror
        @error('target_tw')
            <div style="color:var(--red);font-size:11.5px;margin-top:8px">{{ $message }}</div>
        @enderror
        @error('realisasi')
            <div style="color:var(--red);font-size:11.5px;margin-top:8px">{{ $message }}</div>
        @enderror
        @error('persentase_capaian')
            <div style="color:var(--red);font-size:11.5px;margin-top:8px">{{ $message }}</div>
        @enderror
    </div>

    <div class="card">
        <div class="sec"><span>Verifikasi Bukti Capaian per Kegiatan</span></div>
        <div class="info">ℹ️ Klik tiap berkas untuk memeriksa &amp; menandai sesuai/tidak. Uraian kegiatan bisa disunting bila ada salah ketik.</div>

        @forelse ($kegiatanList as $kegiatan)
            @php $berkasKegiatan = $berkasPerKegiatan[$kegiatan->id] ?? collect(); @endphp
            <div class="keg" wire:key="keg-{{ $kegiatan->id }}">
                <div class="keg-head">
                    <span class="t">Kegiatan {{ $loop->iteration }}</span>
                    <span style="color:var(--muted);font-size:11.5px">
                        {{ $kegiatan->jenis === 'survei_sensus' ? 'Survei/Sensus' : 'Bukan Survei/Sensus' }}{{ $kegiatan->tahapan_survei ? ' · '.ucfirst($kegiatan->tahapan_survei) : '' }}
                    </span>
                </div>
                <textarea class="inp filled" style="height:auto;display:block;font-style:italic" rows="2" wire:model="koreksiKegiatan.{{ $kegiatan->id }}"></textarea>
                @error("koreksiKegiatan.{$kegiatan->id}")
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror

                @forelse ($berkasKegiatan as $file)
                    <div class="filechip {{ $file->status_verifikasi === 'terverifikasi' ? 'ok' : ($file->status_verifikasi === 'ditolak' ? 'no' : '') }}" style="margin-top:8px" wire:key="berkas-{{ $file->id }}">
                        <span class="nm">
                            📄 {{ $file->nama_file }}
                            @if ($file->status_verifikasi === 'ditolak')
                                <span class="sub" style="color:var(--red)">Tidak sesuai</span>
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

    @if ($kendalaSolusiList->isNotEmpty())
        <div class="card">
            <div class="sec"><span>Kendala &amp; Solusi</span></div>
            @foreach ($kendalaSolusiList as $ks)
                @php $berkasSolusi = $berkasPerKendala[$ks->id] ?? collect(); @endphp
                <div class="keg" wire:key="ks-{{ $ks->id }}">
                    <div class="field" style="margin-bottom:10px">
                        <label style="font-size:11.5px">Kendala</label>
                        <textarea class="inp filled" style="height:auto;display:block;font-style:italic" rows="2" wire:model="koreksiKendala.{{ $ks->id }}.kendala"></textarea>
                    </div>
                    @if ($ks->solusi)
                        <div class="field" style="margin-bottom:0">
                            <label style="font-size:11.5px">Solusi</label>
                            <textarea class="inp filled" style="height:auto;display:block;font-style:italic" rows="2" wire:model="koreksiKendala.{{ $ks->id }}.solusi"></textarea>
                        </div>
                        @forelse ($berkasSolusi as $file)
                            <div class="filechip {{ $file->status_verifikasi === 'terverifikasi' ? 'ok' : ($file->status_verifikasi === 'ditolak' ? 'no' : '') }}" style="margin-top:8px" wire:key="berkas-{{ $file->id }}">
                                <span class="nm">📄 {{ $file->nama_file }} <span class="sub">Bukti solusi</span></span>
                                <button type="button" class="btn btn-ghost btn-sm" @click="modalBerkas = {{ $file->id }}">🔍 Periksa</button>
                            </div>
                        @empty
                            <p style="color:var(--muted);font-size:12.5px;margin-top:8px">Belum ada bukti solusi diunggah.</p>
                        @endforelse
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($bagianKustomList->isNotEmpty())
        <div class="card">
            <div class="sec"><span>Bagian Kustom</span></div>
            @foreach ($bagianKustomList->groupBy('bagian_kustom_id') as $poinPerBagian)
                @php $namaBagian = $poinPerBagian->first()->bagianKustom->nama; @endphp
                <div style="font-size:12px;font-weight:700;color:var(--blue-600);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">{{ $namaBagian }}</div>
                @foreach ($poinPerBagian as $poin)
                    @php $berkasPoin = $berkasPerBagianKustom[$poin->id] ?? collect(); @endphp
                    <div class="keg" wire:key="bagian-kustom-{{ $poin->id }}">
                        <p style="font-size:13px;margin-bottom:8px">{{ $poin->teks }}</p>
                        @forelse ($berkasPoin as $file)
                            <div class="filechip {{ $file->status_verifikasi === 'terverifikasi' ? 'ok' : ($file->status_verifikasi === 'ditolak' ? 'no' : '') }}" wire:key="berkas-{{ $file->id }}">
                                <span class="nm">📄 {{ $file->nama_file }} <span class="sub">Bukti dukung</span></span>
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

    @php
        $semuaBerkas = collect($berkasPerKegiatan)->flatten(1)
            ->concat(collect($berkasPerKendala)->flatten(1))
            ->concat($rtlSebelumnya->flatMap->berkas)
            ->concat(collect($berkasPerBagianKustom)->flatten(1));
    @endphp

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
                            tidak mencegah iframe mulai memuat). --}}
                        <iframe
                            x-bind:src="modalBerkas === {{ $file->id }} ? @js(route('berkas.show', $file)) : null"
                            class="pdf-frame" title="Pratinjau {{ $file->nama_file }}"
                        ></iframe>
                        <a href="{{ route('berkas.show', $file) }}" target="_blank" class="btn btn-ghost btn-sm" style="margin-top:10px;align-self:flex-start">🔗 Buka di tab baru / layar penuh</a>
                    </div>
                    <div class="verify-panel">
                        <div class="vp-t">Verifikasi Berkas</div>
                        <button type="button" class="mark {{ $file->status_verifikasi === 'terverifikasi' ? 'ok' : '' }}" wire:click="tandaiSesuai({{ $file->id }})">✓ Sesuai</button>
                        <button type="button" class="mark {{ $file->status_verifikasi === 'ditolak' ? 'no' : '' }}" wire:click="tandaiTolak({{ $file->id }})">✕ Tidak Sesuai</button>
                        <div class="field" style="margin-top:12px">
                            <label style="font-size:11.5px">Catatan (wajib bila tidak sesuai)</label>
                            <textarea class="inp filled" style="height:auto;display:block;min-height:56px;font-size:11.5px" rows="3"
                                wire:model="catatanBerkas.{{ $file->id }}" placeholder="mis. Bukti belum menunjukkan tanggal pelaksanaan…"></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @if ($rtlSebelumnya->isNotEmpty())
        <div class="card">
            <div class="sec"><span>Pengecekan Realisasi RTL Triwulan Sebelumnya</span></div>
            <div class="info">ℹ️ RTL yang direncanakan pada triwulan sebelumnya ditampilkan berdampingan dengan realisasi yang dilaporkan ketua tim, beserta bukti bila ada. Cukup periksa kesesuaiannya — tanpa skor persentase.</div>

            @foreach ($rtlSebelumnya as $poin)
                <div class="match-row" wire:key="rtl-check-{{ $poin->id }}">
                    <div class="match-col"><div class="mc-lbl">RTL Direncanakan</div><div class="mc-txt">{{ $poin->rtl_teks }}</div></div>
                    <div class="match-badge {{ $poin->sudahDievaluasi() ? 'mb-ok' : 'mb-warn' }}">{{ $poin->sudahDievaluasi() ? '✓' : '…' }}</div>
                    <div class="match-col">
                        <div class="mc-lbl">Realisasi Dilaporkan</div>
                        <textarea class="inp filled" style="height:auto;display:block;font-style:italic;font-size:12px" rows="2"
                            wire:model="koreksiRtlRealisasi.{{ $poin->id }}" placeholder="— belum dilaporkan —"></textarea>
                        @foreach ($poin->berkas as $file)
                            <div class="filechip" style="margin-top:6px"><span class="nm">📄 {{ $file->nama_file }}</span></div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="btn-row" style="margin-top:16px">
        <button type="button" class="btn btn-teal" wire:click="verifikasiSelesai">✓ Verifikasi Selesai — Masukkan ke Notula</button>
        <button type="button" class="btn btn-red" wire:click="kembalikanKeKetuaTim">↩ Kembalikan ke Ketua Tim</button>
    </div>
</div>
