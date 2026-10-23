<div>
    <div class="page-head">
        <div class="page-title">Log Aktivitas</div>
        <div class="page-sub">Jejak siapa mengubah apa dan kapan pada isian Bagian Kustom, bukti dukungnya, dan penandaan verifikasinya. Hanya baca — catatan tidak bisa diubah atau dihapus dari aplikasi.</div>
    </div>

    <div class="info">ℹ️ Keterangan disimpan apa adanya <b>saat kejadian</b> (nama bagian, IKU, dan periode tidak ikut berubah bila datanya diubah kemudian). Perubahan definisi bagian ada di tab 🕘 Riwayat pada Bagian Kustom; status alur Capaian ada di riwayat status Verifikasi.</div>

    <div class="card">
        <div class="sec"><span>Saring</span></div>
        <div class="row2">
            <div class="field">
                <label>Dari tanggal</label>
                <input type="date" class="inp filled" wire:model.live="dari">
            </div>
            <div class="field">
                <label>Sampai tanggal</label>
                <input type="date" class="inp filled" wire:model.live="sampai">
            </div>
        </div>
        <div class="row2">
            <div class="field">
                <label>Pelaku</label>
                <select class="inp filled" wire:model.live="pelaku">
                    <option value="">Semua pelaku</option>
                    @foreach ($daftarPengguna as $u)
                        <option value="{{ $u->id }}">{{ $u->nama }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label>Jenis tindakan</label>
                <select class="inp filled" wire:model.live="aksi">
                    <option value="">Semua tindakan</option>
                    @foreach ($daftarAksi as $kunci => $label)
                        <option value="{{ $kunci }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="row2">
            <div class="field">
                <label>IKU</label>
                <select class="inp filled" wire:model.live="ikuId">
                    <option value="">Semua IKU</option>
                    @foreach ($daftarIku as $iku)
                        <option value="{{ $iku->id }}">{{ $iku->kode }} — {{ \Illuminate\Support\Str::limit($iku->indikator, 70) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label>Bagian</label>
                <select class="inp filled" wire:model.live="bagianId">
                    <option value="">Semua bagian</option>
                    @foreach ($daftarBagian as $b)
                        <option value="{{ $b->id }}">{{ $b->nama }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="row2">
            <div class="field">
                <label>Periode isian</label>
                <div style="display:flex;gap:8px">
                    <select class="inp filled" wire:model.live="tahun">
                        <option value="">Semua tahun</option>
                        @foreach ($daftarTahun as $t)
                            <option value="{{ $t }}">{{ $t }}</option>
                        @endforeach
                    </select>
                    <select class="inp filled" wire:model.live="bulan">
                        <option value="">Semua bulan</option>
                        @foreach ($namaBulan as $no => $nm)
                            <option value="{{ $no }}">{{ $nm }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="field">
                <label>Hasil tindakan</label>
                <select class="inp filled" wire:model.live="status">
                    <option value="">Semua hasil</option>
                    <option value="berhasil">Berhasil</option>
                    <option value="ditolak">Ditolak</option>
                    <option value="gagal">Gagal</option>
                </select>
            </div>
        </div>
        <div class="btn-row">
            @if ($korelasi !== '')
                <span class="badge b-verif">Menampilkan satu proses simpan</span>
            @endif
            <button type="button" class="btn btn-ghost btn-sm" wire:click="resetFilter" wire:loading.attr="disabled" wire:target="resetFilter">✕ Hapus semua saringan</button>
        </div>
    </div>

    <div class="card">
        <div class="card-h">📜 Peristiwa terbaru</div>
        <div wire:loading.delay class="fhint" style="margin:0 0 8px">Memuat…</div>
        <table>
            <thead>
                <tr>
                    <th style="width:150px">Waktu (WITA)</th>
                    <th style="width:170px">Pelaku</th>
                    <th style="width:170px">Tindakan</th>
                    <th>Keterangan</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($log as $baris)
                    @php $rinci = $baris->barisPerubahan(); @endphp
                    <tr wire:key="log-{{ $baris->id }}" x-data="{ buka: false }">
                        <td class="muted" style="vertical-align:top">{{ $baris->created_at?->wita()->translatedFormat('d M Y, H:i:s') }}</td>
                        <td style="vertical-align:top">
                            <div>{{ $baris->nama_pengguna ?? 'Sistem' }}</div>
                            <div class="muted" style="font-size:11px">{{ $baris->peran ?? 'proses otomatis' }}</div>
                        </td>
                        <td style="vertical-align:top">
                            <div>{{ \App\Models\LogAktivitas::labelAksi($baris->aksi) }}</div>
                            <span class="badge {{ $baris->status === 'berhasil' ? 'b-approve' : ($baris->status === 'ditolak' ? 'b-tolak' : 'b-ajukan') }}" style="margin-top:4px">{{ ucfirst($baris->status) }}</span>
                        </td>
                        <td style="vertical-align:top">
                            <div>{{ $baris->keterangan }}</div>
                            <button type="button" class="btn btn-ghost btn-sm" style="margin-top:6px" @click="buka = ! buka" x-text="buka ? '▲ Tutup rincian' : '▼ Rincian'">▼ Rincian</button>
                            <div x-show="buka" x-cloak style="margin-top:8px;padding:10px;border:1px solid var(--line2);border-radius:10px;background:var(--bg);font-size:12px">
                                <div style="display:grid;grid-template-columns:max-content 1fr;gap:4px 12px">
                                    @if ($baris->menu)
                                        <span class="muted">Menu</span><span>{{ \App\Support\MenuBagianKustom::label($baris->menu) }}</span>
                                    @endif
                                    @if ($baris->bagian_kustom_id)
                                        <span class="muted">Bagian (saat ini)</span><span>{{ $namaBagian[$baris->bagian_kustom_id] ?? 'sudah dihapus' }}</span>
                                    @endif
                                    @if ($baris->iku_id)
                                        @php $ikuIni = $ikuLabel[$baris->iku_id] ?? null; @endphp
                                        <span class="muted">IKU (saat ini)</span><span>{{ $ikuIni ? $ikuIni->kode.' — '.\Illuminate\Support\Str::limit($ikuIni->indikator, 90) : 'tidak ditemukan' }}</span>
                                    @endif
                                    @if ($baris->periode_id)
                                        <span class="muted">Periode</span><span>{{ \App\Models\LogAktivitas::labelPeriode($periodeLabel[$baris->periode_id] ?? null) }}</span>
                                    @endif
                                </div>

                                @if ($rinci !== [])
                                    <table style="margin-top:10px">
                                        <thead>
                                            <tr><th>Data</th><th>Sebelum</th><th>Sesudah</th></tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($rinci as $r)
                                                <tr>
                                                    <td class="muted">{{ $r['label'] }}</td>
                                                    <td style="white-space:pre-wrap">{{ $r['sebelum'] }}</td>
                                                    <td style="white-space:pre-wrap">{{ $r['sesudah'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @else
                                    <div class="muted" style="margin-top:8px">Tidak ada rincian nilai sebelum/sesudah untuk peristiwa ini.</div>
                                @endif

                                @if ($baris->korelasi)
                                    <button type="button" class="btn btn-ghost btn-sm" style="margin-top:8px" wire:click="filterKorelasi('{{ $baris->korelasi }}')">Lihat peristiwa lain dalam proses simpan yang sama</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" style="color:var(--muted)">Belum ada peristiwa yang cocok dengan saringan.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="btn-row" style="justify-content:space-between;margin-top:12px">
            <button type="button" class="btn btn-ghost btn-sm" wire:click="previousPage" wire:loading.attr="disabled" @disabled($log->onFirstPage())>← Lebih baru</button>
            <span class="muted" style="font-size:12px">Halaman {{ $log->currentPage() }}</span>
            <button type="button" class="btn btn-ghost btn-sm" wire:click="nextPage" wire:loading.attr="disabled" @disabled(! $log->hasMorePages())>Lebih lama →</button>
        </div>
    </div>
</div>
