@php
    $formatAngka = fn ($nilai) => \App\Models\PengaturanCapaian::formatAngka($nilai);
    $formatPersen = fn ($nilai) => \App\Models\PengaturanCapaian::formatPersen($nilai);

    $badgeCapaian = function (?float $persen) use ($formatPersen) {
        if ($formatPersen($persen) === '-') {
            return 'b-draft';
        }

        return $persen >= 90 ? 'b-approve' : ($persen >= 70 ? 'b-tunggu' : 'b-tolak');
    };

    $rataRata = $lakin->baris->pluck('capaian_persen')->filter(fn ($p) => $p !== null);
    $jumlahSasaran = $lakin->baris->pluck('sasaran')->filter()->unique()->count();
    $barisPerSasaran = $lakin->baris->groupBy(fn ($b) => $b->sasaran ?: 'Tanpa Sasaran');
@endphp

<div x-data="{
    filterSasaran: 'semua',
    kataKunci: '',
    init() {
        if (window.innerWidth < 900 && @js($tampilan) === 'tabel') {
            $wire.call('gantiTampilan', 'kartu');
        }
    },
    cocok(barisIndikator, barisSasaran) {
        var cari = this.kataKunci.trim().toLowerCase();
        var cocokTeks = !cari || (barisIndikator && barisIndikator.toLowerCase().includes(cari)) || (barisSasaran && barisSasaran.toLowerCase().includes(cari));
        var cocokSasaran = this.filterSasaran === 'semua' || barisSasaran === this.filterSasaran;
        return cocokTeks && cocokSasaran;
    }
}">
    <div class="page-head">
        <div class="page-title">Rekap Kinerja Tahunan {{ $lakin->tahun }}</div>
        <div class="page-sub">Rekap kinerja tahunan — sasaran, indikator, target, realisasi, dan capaian %.</div>
    </div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <div class="period-banner" style="flex-wrap:wrap;gap:12px">
        <div style="display:flex;align-items:center;gap:12px;min-width:0">
            <span class="pb-ico">📈</span>
            <div>
                <div class="pb-lbl">Tahun Laporan</div>
                <div class="pb-val"><b>{{ $lakin->tahun }}</b></div>
            </div>
        </div>
        <div class="btn-row" style="margin:0 0 0 auto;flex-wrap:wrap">
            <a wire:navigate href="{{ route('lakin.index') }}" class="btn btn-ghost btn-sm">← Daftar Rekap</a>
            <button type="button" class="btn btn-teal btn-sm" wire:click="unduhExcel" wire:loading.attr="disabled" wire:target="unduhExcel">
                <span wire:loading.remove wire:target="unduhExcel">⬇ Unduh Excel</span>
                <span wire:loading wire:target="unduhExcel"><i class="spin"></i> Menyiapkan…</span>
            </button>
            @if ($this->isTimSakip())
                <button type="button" class="btn btn-ghost btn-sm" wire:click="bukaKonfirmasiSegarkan" wire:loading.attr="disabled" wire:target="segarkanAngka">
                    <span wire:loading.remove wire:target="segarkanAngka">↻ Segarkan Angka</span>
                    <span wire:loading wire:target="segarkanAngka"><i class="spin"></i> Menyegarkan…</span>
                </button>
            @endif
        </div>
    </div>

    <div class="stat-grid" style="grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));margin-bottom:16px">
        <div class="stat-tile">
            <div class="si">🧾</div>
            <div class="sv">{{ $lakin->baris->count() }}</div>
            <div class="sl">Jumlah Indikator</div>
        </div>
        <div class="stat-tile">
            <div class="si">🎯</div>
            <div class="sv" style="display:flex;align-items:baseline;gap:8px">
                <span>{{ $rataRata->isNotEmpty() ? round($rataRata->avg(), 1) : '-' }}%</span>
                @if ($rataRata->isNotEmpty())
                    <span class="badge {{ $badgeCapaian($rataRata->avg()) }}" style="font-size:11px;padding:2px 7px">
                        {{ $rataRata->avg() >= 90 ? 'Tinggi' : ($rataRata->avg() >= 70 ? 'Sedang' : 'Rendah') }}
                    </span>
                @endif
            </div>
            <div class="sl">Rata-rata Capaian</div>
        </div>
        <div class="stat-tile">
            <div class="si">🗂️</div>
            <div class="sv">{{ $jumlahSasaran }}</div>
            <div class="sl">Sasaran Strategis</div>
        </div>
    </div>

    <div class="card">
        <div class="card-h" style="justify-content:space-between;flex-wrap:wrap;gap:10px">
            <div style="display:flex;align-items:center;gap:10px">
                <span>📋 Tabel Rekap Kinerja</span>
                <span class="badge" style="font-size:11.5px">{{ $lakin->baris->count() }} Baris</span>
            </div>

            <!-- Switcher Tampilan (Tabel vs Kartu) -->
            <div class="lakin-view-switch">
                <button type="button" class="lakin-view-btn {{ $tampilan === 'tabel' ? 'active' : '' }}" wire:click="gantiTampilan('tabel')" title="Tampilan spreadsheet lebar dengan kolom sticky">
                    <span>📊</span>
                    <span>Tabel Lengkap</span>
                </button>
                <button type="button" class="lakin-view-btn {{ $tampilan === 'kartu' ? 'active' : '' }}" wire:click="gantiTampilan('kartu')" title="Tampilan kartu responsif untuk smartphone dan tablet">
                    <span>📱</span>
                    <span>Kartu Responsif</span>
                </button>
            </div>
        </div>

        @if ($lakin->baris->isNotEmpty())
            <!-- Toolbar Filter & Pencarian Cepat -->
            <div class="lakin-toolbar">
                <div class="lakin-toolbar-group" style="flex:1;min-width:240px">
                    <input type="search" class="lakin-filter-inp" placeholder="🔍 Cari indikator atau sasaran…" x-model="kataKunci" style="width:100%;max-width:320px">
                    
                    <select class="lakin-filter-inp" x-model="filterSasaran" style="max-width:280px">
                        <option value="semua">Semua Sasaran ({{ $jumlahSasaran }})</option>
                        @foreach ($barisPerSasaran->keys() as $optSasaran)
                            <option value="{{ $optSasaran }}">{{ Str::limit($optSasaran, 45) }}</option>
                        @endforeach
                    </select>
                </div>

                <div style="font-size:11.5px;color:var(--muted)">
                    @if ($tampilan === 'tabel')
                        <span>💡 <em>Kolom Indikator terkunci (sticky) di kiri. Geser tabel horizontal untuk melihat kolom lainnya.</em></span>
                    @else
                        <span>💡 <em>Tampilan kartu memudahkan pengisian per indikator di perangkat mobile/tablet.</em></span>
                    @endif
                </div>
            </div>
        @endif

        @if ($lakin->baris->isEmpty())
            <div style="padding:32px 16px;text-align:center;color:var(--muted)">
                <div style="font-size:36px;margin-bottom:8px">📋</div>
                <div style="font-weight:600;font-size:14px;color:var(--ink)">Belum ada baris rekap kinerja</div>
                <p style="font-size:13px;margin-top:4px">
                    {{ $this->isTimSakip() ? 'Pilih dari data capaian tahun '.$lakin->tahun.' di bawah atau tambahkan baris custom secara manual.' : 'Data rekap belum diisi oleh Tim SAKIP.' }}
                </p>
            </div>
        @elseif ($tampilan === 'tabel')
            {{-- ============================================================== --}}
            {{-- TAMPILAN SPREADSHEET TABEL (LEBAR & STICKY COLUMN)            --}}
            {{-- ============================================================== --}}
            <div class="lakin-table-wrap">
                <table class="lakin-table">
                    <thead>
                        <tr>
                            <th class="th-sticky-left" style="width:330px;min-width:330px">Indikator Kinerja</th>
                            <th style="text-align:right;width:115px;min-width:115px">Target</th>
                            <th style="text-align:right;width:115px;min-width:115px">Realisasi</th>
                            <th style="text-align:center;width:100px;min-width:100px">Capaian %</th>
                            <th style="width:220px;min-width:220px">Kegiatan</th>
                            <th style="width:220px;min-width:220px">Solusi &amp; Kendala</th>
                            <th style="width:220px;min-width:220px">RTL (Rencana Tindak Lanjut)</th>
                            <th style="width:140px;min-width:140px">PIC</th>
                            <th style="width:150px;min-width:150px">Batas Waktu</th>
                            <th style="width:190px;min-width:190px">Link Bukti Dukung</th>
                            <th style="width:190px;min-width:190px">Link RTL Sebelumnya</th>
                            @if ($this->isTimSakip())
                                <th class="th-sticky-right" style="width:110px;min-width:110px;text-align:center">Aksi</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($barisPerSasaran as $sasaran => $daftarBaris)
                            @php
                                $sasaranEscaped = addslashes($sasaran);
                            @endphp
                            <tr class="lakin-sasaran-tr" x-show="filterSasaran === 'semua' || filterSasaran === '{{ $sasaranEscaped }}'">
                                <td colspan="{{ $this->isTimSakip() ? 12 : 11 }}">
                                    <div class="sasaran-title-box">
                                        <span style="font-size:15px">🎯</span>
                                        <span style="text-transform:uppercase">{{ $sasaran }}</span>
                                        <span class="badge" style="background:var(--white);color:var(--navy);font-size:11px;font-weight:700">
                                            {{ $daftarBaris->count() }} Indikator
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            @foreach ($daftarBaris as $baris)
                                @php
                                    $indikatorEscaped = addslashes($baris->indikator);
                                @endphp
                                <tr wire:key="baris-tbl-{{ $baris->id }}" x-show="cocok('{{ $indikatorEscaped }}', '{{ $sasaranEscaped }}')">
                                    @if ($this->isTimSakip())
                                        {{-- 1. Indikator (Sticky Kiri) --}}
                                        <td class="td-sticky-left">
                                            <textarea class="tbl-textarea" style="height:56px;font-weight:600" placeholder="Ketik indikator…" wire:model="edit.{{ $baris->id }}.indikator"></textarea>
                                            <input type="text" class="tbl-inp" style="margin-top:5px;font-size:11px;color:var(--muted);padding:4px 8px" placeholder="🏷️ Sasaran (opsional)" wire:model="edit.{{ $baris->id }}.sasaran">
                                            @error("edit.{$baris->id}.indikator")
                                                <div style="color:var(--red);font-size:11px;margin-top:3px;font-weight:600">{{ $message }}</div>
                                            @enderror
                                        </td>

                                        {{-- 2. Target --}}
                                        <td style="text-align:right">
                                            <input type="number" step="0.01" class="tbl-inp num" placeholder="0" wire:model="edit.{{ $baris->id }}.target">
                                        </td>

                                        {{-- 3. Realisasi --}}
                                        <td style="text-align:right">
                                            <input type="number" step="0.01" class="tbl-inp num" placeholder="0" wire:model="edit.{{ $baris->id }}.realisasi">
                                        </td>

                                        {{-- 4. Capaian % --}}
                                        <td style="text-align:center">
                                            <span class="badge {{ $badgeCapaian($baris->capaian_persen) }}" style="font-weight:700;padding:4px 8px;font-size:12px">
                                                {{ $formatPersen($baris->capaian_persen) }}
                                            </span>
                                        </td>

                                        {{-- 5. Kegiatan --}}
                                        <td>
                                            <textarea class="tbl-textarea" placeholder="Rincian kegiatan…" wire:model="edit.{{ $baris->id }}.kegiatan"></textarea>
                                        </td>

                                        {{-- 6. Solusi & Kendala --}}
                                        <td>
                                            <textarea class="tbl-textarea" placeholder="Kendala & solusi…" wire:model="edit.{{ $baris->id }}.solusi_kendala"></textarea>
                                        </td>

                                        {{-- 7. RTL --}}
                                        <td>
                                            <textarea class="tbl-textarea" placeholder="Rencana tindak lanjut…" wire:model="edit.{{ $baris->id }}.rtl"></textarea>
                                        </td>

                                        {{-- 8. PIC --}}
                                        <td>
                                            <input type="text" class="tbl-inp" placeholder="Nama PIC…" wire:model="edit.{{ $baris->id }}.pic">
                                        </td>

                                        {{-- 9. Batas Waktu Tindak Lanjut --}}
                                        <td>
                                            <input type="date" class="tbl-inp" wire:model="edit.{{ $baris->id }}.batas_waktu_tindak_lanjut">
                                        </td>

                                        {{-- 10. Link Bukti Dukung Kinerja --}}
                                        <td>
                                            <div style="display:flex;align-items:center;gap:5px">
                                                <input type="url" class="tbl-inp" placeholder="https://…" wire:model="edit.{{ $baris->id }}.link_bukti_dukung_kinerja">
                                                @if (!empty($baris->link_bukti_dukung_kinerja))
                                                    <a href="{{ $baris->link_bukti_dukung_kinerja }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="padding:6px 8px;flex:none" title="Buka tautan bukti">🔗</a>
                                                @endif
                                            </div>
                                        </td>

                                        {{-- 11. Link RTL Sebelumnya --}}
                                        <td>
                                            <div style="display:flex;align-items:center;gap:5px">
                                                <input type="url" class="tbl-inp" placeholder="https://…" wire:model="edit.{{ $baris->id }}.link_rtl_sebelumnya">
                                                @if (!empty($baris->link_rtl_sebelumnya))
                                                    <a href="{{ $baris->link_rtl_sebelumnya }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="padding:6px 8px;flex:none" title="Buka tautan RTL">🔗</a>
                                                @endif
                                            </div>
                                        </td>

                                        {{-- 12. Aksi (Sticky Kanan) --}}
                                        <td class="td-sticky-right">
                                            <div style="display:flex;align-items:center;justify-content:center;gap:6px">
                                                <button type="button" class="btn btn-primary btn-sm" style="padding:6px 10px" title="Simpan baris" wire:click="simpanBaris({{ $baris->id }})" wire:loading.attr="disabled" wire:target="simpanBaris({{ $baris->id }}),hapusBaris({{ $baris->id }})">
                                                    <span wire:loading.remove wire:target="simpanBaris({{ $baris->id }})">💾 Simpan</span>
                                                    <span wire:loading wire:target="simpanBaris({{ $baris->id }})"><i class="spin"></i></span>
                                                </button>
                                                <button type="button" class="btn btn-red btn-sm" style="padding:6px 8px" title="Hapus baris" wire:click="confirmHapusBaris({{ $baris->id }})" wire:loading.attr="disabled" wire:target="simpanBaris({{ $baris->id }}),confirmHapusBaris({{ $baris->id }})">
                                                    <span wire:loading.remove wire:target="confirmHapusBaris({{ $baris->id }})">🗑</span>
                                                    <span wire:loading wire:target="confirmHapusBaris({{ $baris->id }})"><i class="spin"></i></span>
                                                </button>
                                            </div>
                                        </td>
                                    @else
                                        {{-- Mode Hanya-Baca (Ketua Tim & Kepala) --}}
                                        <td class="td-sticky-left">
                                            <div style="font-weight:600;color:var(--ink);line-height:1.4">{{ $baris->indikator }}</div>
                                            @if ($baris->sasaran)
                                                <div style="font-size:11px;color:var(--muted);margin-top:4px">🏷️ {{ $baris->sasaran }}</div>
                                            @endif
                                        </td>
                                        <td style="text-align:right;font-weight:600;font-variant-numeric:tabular-nums">{{ $formatAngka($baris->target) }}</td>
                                        <td style="text-align:right;font-weight:600;font-variant-numeric:tabular-nums">{{ $formatAngka($baris->realisasi) }}</td>
                                        <td style="text-align:center">
                                            <span class="badge {{ $badgeCapaian($baris->capaian_persen) }}" style="font-weight:700;padding:4px 8px;font-size:12px">
                                                {{ $formatPersen($baris->capaian_persen) }}
                                            </span>
                                        </td>
                                        <td><div style="white-space:pre-wrap;line-height:1.4;color:var(--ink)">{{ $baris->kegiatan ?: '—' }}</div></td>
                                        <td><div style="white-space:pre-wrap;line-height:1.4;color:var(--ink)">{{ $baris->solusi_kendala ?: '—' }}</div></td>
                                        <td><div style="white-space:pre-wrap;line-height:1.4;color:var(--ink)">{{ $baris->rtl ?: '—' }}</div></td>
                                        <td><div style="color:var(--ink);font-weight:500">{{ $baris->pic ?: '—' }}</div></td>
                                        <td><div style="color:var(--ink)">{{ $baris->batas_waktu_tindak_lanjut?->format('d/m/Y') ?: '—' }}</div></td>
                                        <td>
                                            @if ($baris->link_bukti_dukung_kinerja)
                                                <a href="{{ $baris->link_bukti_dukung_kinerja }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;gap:5px;font-size:11.5px">
                                                    <span>🔗</span> Buka Bukti
                                                </a>
                                            @else
                                                <span style="color:var(--faint)">—</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($baris->link_rtl_sebelumnya)
                                                <a href="{{ $baris->link_rtl_sebelumnya }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;gap:5px;font-size:11.5px">
                                                    <span>🔗</span> Buka RTL
                                                </a>
                                            @else
                                                <span style="color:var(--faint)">—</span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            {{-- ============================================================== --}}
            {{-- TAMPILAN KARTU RESPONSIF (UNTUK SEMUA DEVICE: HP, TABLET, PC) --}}
            {{-- ============================================================== --}}
            <div class="lakin-cards-wrap">
                @foreach ($barisPerSasaran as $sasaran => $daftarBaris)
                    @php
                        $sasaranEscaped = addslashes($sasaran);
                    @endphp
                    <div class="lakin-group-card" x-show="filterSasaran === 'semua' || filterSasaran === '{{ $sasaranEscaped }}'">
                        {{-- Header Grup Sasaran --}}
                        <div class="lakin-group-header">
                            <div class="lakin-group-title">
                                <span style="font-size:16px">🎯</span>
                                <span>{{ $sasaran }}</span>
                            </div>
                            <span class="badge" style="background:var(--white);color:var(--navy);font-size:11.5px;font-weight:700">
                                {{ $daftarBaris->count() }} Indikator
                            </span>
                        </div>

                        {{-- Daftar Kartu Indikator --}}
                        <div class="lakin-group-body">
                            @foreach ($daftarBaris as $baris)
                                @php
                                    $indikatorEscaped = addslashes($baris->indikator);
                                    $persenAngka = $baris->capaian_persen !== null ? min(100, max(0, $baris->capaian_persen)) : 0;
                                    $barisBadge = $badgeCapaian($baris->capaian_persen);
                                @endphp
                                <div class="lakin-card" wire:key="baris-crd-{{ $baris->id }}" x-show="cocok('{{ $indikatorEscaped }}', '{{ $sasaranEscaped }}')">
                                    {{-- Baris Atas: Nomor/Kode & Capaian --}}
                                    <div class="lakin-card-head">
                                        <div style="flex:1;min-width:0">
                                            @if ($this->isTimSakip())
                                                <label style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:4px">
                                                    Indikator Kinerja
                                                </label>
                                                <textarea class="tbl-textarea" style="height:56px;font-weight:600;font-size:13px" placeholder="Indikator…" wire:model="edit.{{ $baris->id }}.indikator"></textarea>
                                                <input type="text" class="tbl-inp" style="margin-top:6px;font-size:11.5px;color:var(--muted)" placeholder="🏷️ Sasaran (opsional)" wire:model="edit.{{ $baris->id }}.sasaran">
                                                @error("edit.{$baris->id}.indikator")
                                                    <div style="color:var(--red);font-size:11px;margin-top:3px;font-weight:600">{{ $message }}</div>
                                                @enderror
                                            @else
                                                <div style="font-size:14px;font-weight:700;color:var(--ink);line-height:1.4">
                                                    {{ $baris->indikator }}
                                                </div>
                                                @if ($baris->sasaran)
                                                    <div style="font-size:11.5px;color:var(--muted);margin-top:4px">🏷️ {{ $baris->sasaran }}</div>
                                                @endif
                                            @endif
                                        </div>

                                        <div style="flex:none;text-align:right">
                                            <span class="badge {{ $barisBadge }}" style="font-size:13px;font-weight:800;padding:5px 10px">
                                                {{ $formatPersen($baris->capaian_persen) }}
                                            </span>
                                        </div>
                                    </div>

                                    {{-- Strip Metrik Capaian --}}
                                    <div class="lakin-card-metrics">
                                        <div class="lakin-metric-box">
                                            <div class="lakin-metric-lbl">Target</div>
                                            @if ($this->isTimSakip())
                                                <input type="number" step="0.01" class="tbl-inp num" placeholder="0" wire:model="edit.{{ $baris->id }}.target" style="padding:5px 8px;font-size:13px">
                                            @else
                                                <div class="lakin-metric-val">{{ $formatAngka($baris->target) }}</div>
                                            @endif
                                        </div>

                                        <div class="lakin-metric-box">
                                            <div class="lakin-metric-lbl">Realisasi</div>
                                            @if ($this->isTimSakip())
                                                <input type="number" step="0.01" class="tbl-inp num" placeholder="0" wire:model="edit.{{ $baris->id }}.realisasi" style="padding:5px 8px;font-size:13px">
                                            @else
                                                <div class="lakin-metric-val">{{ $formatAngka($baris->realisasi) }}</div>
                                            @endif
                                        </div>

                                        <div class="lakin-metric-box">
                                            <div class="lakin-metric-lbl">Capaian %</div>
                                            <div class="lakin-metric-val" style="color:var(--blue-600)">{{ $formatPersen($baris->capaian_persen) }}</div>
                                            <div class="quota-bar" style="height:6px;margin-top:4px" title="Capaian {{ $formatPersen($baris->capaian_persen) }}">
                                                <div class="quota-fill {{ $baris->capaian_persen >= 90 ? '' : ($baris->capaian_persen >= 70 ? 'warn' : 'danger') }}" style="width:{{ $persenAngka }}%"></div>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Form Detail / Rincian Kegiatan & RTL --}}
                                    <div class="lakin-card-grid">
                                        <div>
                                            <label style="font-size:11px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">📝 Kegiatan</label>
                                            @if ($this->isTimSakip())
                                                <textarea class="tbl-textarea" placeholder="Rincian kegiatan…" wire:model="edit.{{ $baris->id }}.kegiatan"></textarea>
                                            @else
                                                <div style="background:var(--bg);padding:8px 10px;border-radius:6px;font-size:12.5px;min-height:48px;white-space:pre-wrap">{{ $baris->kegiatan ?: '—' }}</div>
                                            @endif
                                        </div>

                                        <div>
                                            <label style="font-size:11px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">💡 Solusi &amp; Kendala</label>
                                            @if ($this->isTimSakip())
                                                <textarea class="tbl-textarea" placeholder="Solusi &amp; kendala…" wire:model="edit.{{ $baris->id }}.solusi_kendala"></textarea>
                                            @else
                                                <div style="background:var(--bg);padding:8px 10px;border-radius:6px;font-size:12.5px;min-height:48px;white-space:pre-wrap">{{ $baris->solusi_kendala ?: '—' }}</div>
                                            @endif
                                        </div>

                                        <div style="grid-column: 1 / -1">
                                            <label style="font-size:11px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">🔄 Rencana Tindak Lanjut (RTL)</label>
                                            @if ($this->isTimSakip())
                                                <textarea class="tbl-textarea" placeholder="Rencana tindak lanjut…" wire:model="edit.{{ $baris->id }}.rtl"></textarea>
                                            @else
                                                <div style="background:var(--bg);padding:8px 10px;border-radius:6px;font-size:12.5px;min-height:48px;white-space:pre-wrap">{{ $baris->rtl ?: '—' }}</div>
                                            @endif
                                        </div>

                                        <div>
                                            <label style="font-size:11px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">👤 Penanggung Jawab (PIC)</label>
                                            @if ($this->isTimSakip())
                                                <input type="text" class="tbl-inp" placeholder="Nama PIC…" wire:model="edit.{{ $baris->id }}.pic">
                                            @else
                                                <div style="background:var(--bg);padding:7px 10px;border-radius:6px;font-size:12.5px;font-weight:600">{{ $baris->pic ?: '—' }}</div>
                                            @endif
                                        </div>

                                        <div>
                                            <label style="font-size:11px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">📅 Batas Waktu Tindak Lanjut</label>
                                            @if ($this->isTimSakip())
                                                <input type="date" class="tbl-inp" wire:model="edit.{{ $baris->id }}.batas_waktu_tindak_lanjut">
                                            @else
                                                <div style="background:var(--bg);padding:7px 10px;border-radius:6px;font-size:12.5px">{{ $baris->batas_waktu_tindak_lanjut?->format('d F Y') ?: '—' }}</div>
                                            @endif
                                        </div>

                                        <div>
                                            <label style="font-size:11px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">🔗 Link Bukti Dukung Kinerja</label>
                                            @if ($this->isTimSakip())
                                                <div style="display:flex;align-items:center;gap:6px">
                                                    <input type="url" class="tbl-inp" placeholder="https://…" wire:model="edit.{{ $baris->id }}.link_bukti_dukung_kinerja">
                                                    @if (!empty($baris->link_bukti_dukung_kinerja))
                                                        <a href="{{ $baris->link_bukti_dukung_kinerja }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="padding:6px 8px;flex:none" title="Buka tautan bukti">🔗</a>
                                                    @endif
                                                </div>
                                            @else
                                                @if ($baris->link_bukti_dukung_kinerja)
                                                    <a href="{{ $baris->link_bukti_dukung_kinerja }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;gap:6px">
                                                        <span>🔗</span> Buka Tautan Bukti Dukung
                                                    </a>
                                                @else
                                                    <div style="background:var(--bg);padding:7px 10px;border-radius:6px;font-size:12.5px;color:var(--faint)">Belum ada tautan</div>
                                                @endif
                                            @endif
                                        </div>

                                        <div>
                                            <label style="font-size:11px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">🔗 Link RTL Sebelumnya</label>
                                            @if ($this->isTimSakip())
                                                <div style="display:flex;align-items:center;gap:6px">
                                                    <input type="url" class="tbl-inp" placeholder="https://…" wire:model="edit.{{ $baris->id }}.link_rtl_sebelumnya">
                                                    @if (!empty($baris->link_rtl_sebelumnya))
                                                        <a href="{{ $baris->link_rtl_sebelumnya }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="padding:6px 8px;flex:none" title="Buka tautan RTL">🔗</a>
                                                    @endif
                                                </div>
                                            @else
                                                @if ($baris->link_rtl_sebelumnya)
                                                    <a href="{{ $baris->link_rtl_sebelumnya }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;gap:6px">
                                                        <span>🔗</span> Buka Tautan RTL Sebelumnya
                                                    </a>
                                                @else
                                                    <div style="background:var(--bg);padding:7px 10px;border-radius:6px;font-size:12.5px;color:var(--faint)">Belum ada tautan</div>
                                                @endif
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Tombol Aksi di Bawah Kartu --}}
                                    @if ($this->isTimSakip())
                                        <div class="lakin-card-foot">
                                            <span style="font-size:11.5px;color:var(--muted)">
                                                ID Baris: #{{ $baris->id }}
                                            </span>
                                            <div class="btn-row" style="margin:0;gap:8px">
                                                <button type="button" class="btn btn-red btn-sm" wire:click="confirmHapusBaris({{ $baris->id }})" wire:loading.attr="disabled" wire:target="simpanBaris({{ $baris->id }}),confirmHapusBaris({{ $baris->id }})">
                                                    <span wire:loading.remove wire:target="confirmHapusBaris({{ $baris->id }})">🗑 Hapus Baris</span>
                                                    <span wire:loading wire:target="confirmHapusBaris({{ $baris->id }})"><i class="spin"></i></span>
                                                </button>
                                                <button type="button" class="btn btn-primary btn-sm" wire:click="simpanBaris({{ $baris->id }})" wire:loading.attr="disabled" wire:target="simpanBaris({{ $baris->id }}),hapusBaris({{ $baris->id }})">
                                                    <span wire:loading.remove wire:target="simpanBaris({{ $baris->id }})">💾 Simpan Perubahan</span>
                                                    <span wire:loading wire:target="simpanBaris({{ $baris->id }})"><i class="spin"></i> Menyimpan…</span>
                                                </button>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    @if ($this->isTimSakip())
        {{-- ============================================================== --}}
        {{-- KARTU 2: TAMBAH DARI DATA CAPAIAN (IKU TERSEDIA)              --}}
        {{-- ============================================================== --}}
        <div class="card" style="margin-top:20px">
            <div class="card-h" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
                <div style="display:flex;align-items:center;gap:8px">
                    <span>📥 Tambah dari Data Capaian</span>
                    <span class="badge" style="font-size:11px">{{ $ikuBelumDitambahkan->count() }} Tersedia</span>
                </div>
            </div>

            <div class="info" style="margin-bottom:14px">
                ℹ️ IKU yang sudah diverifikasi Tim SAKIP otomatis tercentang. IKU yang belum diverifikasi tetap bisa dicentang manual. Hanya IKU yang dicentang yang akan ditambahkan saat Anda menekan tombol "Tambahkan Terpilih".
            </div>

            @error('ikuTerpilihUntukTambah')
                <div style="color:var(--red);font-size:12px;margin-bottom:12px;font-weight:600">{{ $message }}</div>
            @enderror

            @if ($ikuBelumDitambahkan->isEmpty())
                <div style="padding:18px;text-align:center;background:var(--bg);border-radius:8px;color:var(--muted);font-size:13px">
                    ✅ Semua IKU yang memiliki data capaian pada tahun {{ $lakin->tahun }} sudah ditambahkan ke rekap kinerja.
                </div>
            @else
                <div class="btn-row" style="margin-bottom:14px;gap:8px">
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="pilihSemuaUntukTambah">☑ Pilih Semua</button>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="batalkanSemuaUntukTambah">☐ Batalkan Semua</button>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px;margin-bottom:16px">
                    @foreach ($ikuBelumDitambahkan as $data)
                        <label style="display:flex;align-items:flex-start;gap:12px;padding:12px;border:1.5px solid var(--line);border-radius:10px;background:var(--white);cursor:pointer;transition:border-color .15s, box-shadow .15s" class="hover:border-blue-400">
                            <input type="checkbox" value="{{ $data->iku->id }}" wire:model="ikuTerpilihUntukTambah" style="margin-top:3px;accent-color:var(--blue)">
                            <div style="flex:1;min-width:0">
                                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:3px">
                                    <b style="font-size:13px;color:var(--ink)">{{ $data->iku->kode }}</b>
                                    @if ($data->terverifikasi)
                                        <x-badge-status status="diverifikasi" />
                                    @else
                                        <x-badge-status status="menunggu" label="Belum Diverifikasi" />
                                    @endif
                                </div>
                                <div style="font-size:12px;color:var(--ink);line-height:1.4;margin-bottom:4px">{{ $data->iku->indikator }}</div>
                                <div style="font-size:11px;color:var(--muted);display:flex;gap:8px;flex-wrap:wrap">
                                    <span>Target: <b>{{ $formatAngka($data->target) }}</b></span>
                                    <span>·</span>
                                    <span>Realisasi: <b>{{ $formatAngka($data->realisasi) }}</b></span>
                                    <span>·</span>
                                    <span>Capaian: <b style="color:var(--blue-600)">{{ $formatPersen($data->capaian_persen) }}</b></span>
                                </div>
                            </div>
                        </label>
                    @endforeach
                </div>

                <div class="btn-row">
                    <button type="button" class="btn btn-primary" wire:click="tambahDariCapaian" wire:loading.attr="disabled" wire:target="tambahDariCapaian">
                        <span wire:loading.remove wire:target="tambahDariCapaian">📥 Tambahkan Terpilih</span>
                        <span wire:loading wire:target="tambahDariCapaian"><i class="spin"></i> Menambahkan…</span>
                    </button>
                </div>
            @endif
        </div>

        {{-- ============================================================== --}}
        {{-- KARTU 3: TAMBAH BARIS CUSTOM                                   --}}
        {{-- ============================================================== --}}
        <div class="card" style="margin-top:20px">
            <div class="card-h">✏️ Tambah Baris Custom</div>
            <div class="info" style="margin-bottom:14px">
                ℹ️ Untuk indikator tambahan yang tidak mengacu ke Master IKU, atau format pelaporan khusus yang disesuaikan.
            </div>

            <div class="grid grid-2">
                <div class="field">
                    <label>Sasaran (opsional)</label>
                    <input type="text" class="inp filled" placeholder="Contoh: Terwujudnya Pelayanan Publik..." wire:model="sasaranBaru">
                </div>

                <div class="field">
                    <label>Indikator <span class="req">*</span></label>
                    <input type="text" class="inp filled" placeholder="Nama indikator kinerja…" wire:model="indikatorBaru">
                    @error('indikatorBaru')
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>

                <div class="field">
                    <label>Target (opsional)</label>
                    <input type="number" step="0.01" class="inp filled" placeholder="0" wire:model="targetBaru">
                </div>

                <div class="field">
                    <label>Realisasi (opsional)</label>
                    <input type="number" step="0.01" class="inp filled" placeholder="0" wire:model="realisasiBaru">
                </div>

                <div class="field">
                    <label>Kegiatan (opsional)</label>
                    <textarea class="inp filled" rows="2" placeholder="Uraian kegiatan yang dilaksanakan…" wire:model="kegiatanBaru"></textarea>
                </div>

                <div class="field">
                    <label>Solusi &amp; Kendala (opsional)</label>
                    <textarea class="inp filled" rows="2" placeholder="Kendala yang dihadapi serta solusinya…" wire:model="solusiKendalaBaru"></textarea>
                </div>

                <div class="field">
                    <label>RTL / Rencana Tindak Lanjut (opsional)</label>
                    <textarea class="inp filled" rows="2" placeholder="Rencana tindak lanjut ke depan…" wire:model="rtlBaru"></textarea>
                </div>

                <div class="field">
                    <label>PIC / Penanggung Jawab (opsional)</label>
                    <input type="text" class="inp filled" placeholder="Nama atau tim PIC…" wire:model="picBaru">
                </div>

                <div class="field">
                    <label>Batas Waktu Tindak Lanjut (opsional)</label>
                    <input type="date" class="inp filled" wire:model="batasWaktuTindakLanjutBaru">
                    @error('batasWaktuTindakLanjutBaru')
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>

                <div class="field">
                    <label>Link Bukti Dukung Kinerja (opsional)</label>
                    <input type="url" class="inp filled" placeholder="https://…" wire:model="linkBuktiDukungKinerjaBaru">
                </div>

                <div class="field" style="grid-column: 1 / -1">
                    <label>Link RTL Sebelumnya (opsional)</label>
                    <input type="url" class="inp filled" placeholder="https://…" wire:model="linkRtlSebelumnyaBaru">
                </div>
            </div>

            <div class="btn-row" style="margin-top:14px">
                <button type="button" class="btn btn-ghost" wire:click="tambahBaris" wire:loading.attr="disabled" wire:target="tambahBaris">
                    <span wire:loading.remove wire:target="tambahBaris">＋ Tambah Baris Custom</span>
                    <span wire:loading wire:target="tambahBaris"><i class="spin"></i> Menambahkan…</span>
                </button>
            </div>
        </div>
    @endif

    {{-- Modal Konfirmasi Segarkan --}}
    <x-confirm-modal :show="$showSegarkanConfirm" title="Segarkan Angka?" :danger="false"
        message="Segarkan angka baris yang sudah ada dari data capaian terbaru? Baris custom tidak berubah, dan ini tidak menambah baris baru.">
        <button type="button" class="btn btn-ghost" wire:click="batalkanSegarkan" wire:loading.attr="disabled" wire:target="segarkanAngka">Batal</button>
        <button type="button" class="btn btn-primary" wire:click="segarkanAngka" wire:loading.attr="disabled" wire:target="segarkanAngka">
            <span wire:loading.remove wire:target="segarkanAngka">Segarkan</span>
            <span wire:loading wire:target="segarkanAngka"><i class="spin"></i> Menyegarkan…</span>
        </button>
    </x-confirm-modal>

    {{-- Modal Konfirmasi Hapus Baris --}}
    <x-confirm-modal :show="$pendingHapusBarisId !== null" title="Hapus Baris?" message="Hapus baris ini dari rekap kinerja tahunan?">
        <button type="button" class="btn btn-ghost" wire:click="batalkanHapusBaris" wire:loading.attr="disabled" wire:target="hapusBaris">Batal</button>
        <button type="button" class="btn btn-red" wire:click="hapusBaris" wire:loading.attr="disabled" wire:target="hapusBaris">
            <span wire:loading.remove wire:target="hapusBaris">Hapus</span>
            <span wire:loading wire:target="hapusBaris"><i class="spin"></i> Menghapus…</span>
        </button>
    </x-confirm-modal>
</div>
