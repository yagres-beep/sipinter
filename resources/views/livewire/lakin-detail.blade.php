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

    $jumlahTinggi = $lakin->baris->where('capaian_persen', '>=', 90)->count();
    $jumlahSedang = $lakin->baris->filter(fn ($b) => $b->capaian_persen !== null && $b->capaian_persen >= 70 && $b->capaian_persen < 90)->count();
    $jumlahRendah = $lakin->baris->filter(fn ($b) => $b->capaian_persen !== null && $b->capaian_persen < 70)->count();
    $sudahAdaRtl = $lakin->baris->filter(fn ($b) => !blank($b->rtl))->count();
@endphp

<div x-data="{
    filterSasaran: 'semua',
    kataKunci: '',
    filterCapaian: 'semua',
    cocok(barisIndikator, barisSasaran, capaianPersen) {
        var cari = this.kataKunci.trim().toLowerCase();
        var cocokTeks = !cari || (barisIndikator && barisIndikator.toLowerCase().includes(cari)) || (barisSasaran && barisSasaran.toLowerCase().includes(cari));
        var cocokSasaran = this.filterSasaran === 'semua' || barisSasaran === this.filterSasaran;
        var cocokCapaian = true;
        if (this.filterCapaian === 'tinggi') {
            cocokCapaian = capaianPersen !== null && capaianPersen >= 90;
        } else if (this.filterCapaian === 'sedang') {
            cocokCapaian = capaianPersen !== null && capaianPersen >= 70 && capaianPersen < 90;
        } else if (this.filterCapaian === 'rendah') {
            cocokCapaian = capaianPersen !== null && capaianPersen < 70;
        }
        return cocokTeks && cocokSasaran && cocokCapaian;
    },
    cocokSasaran(namaSasaran) {
        return this.filterSasaran === 'semua' || this.filterSasaran === namaSasaran;
    }
}">
    <div class="page-head">
        <div class="page-title">Rekap Kinerja Tahunan {{ $lakin->tahun }}</div>
        <div class="page-sub">Laporan kinerja tahunan komprehensif — sasaran, indikator, target, realisasi, capaian %, dan tindak lanjut.</div>
    </div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px;padding:10px 14px;font-size:13px;font-weight:600">
            ✓ {{ session('status') }}
        </div>
    @endif

    {{-- Header Banner Periode & Navigasi Aksi --}}
    <div class="lakin-header-banner">
        <div class="lakin-banner-left">
            <div class="lakin-banner-icon">📈</div>
            <div>
                <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px">Tahun Laporan</div>
                <div style="font-size:20px;font-weight:800;color:var(--ink);line-height:1.2">{{ $lakin->tahun }}</div>
            </div>
        </div>
        <div class="btn-row" style="margin:0;gap:8px;flex-wrap:wrap">
            <a wire:navigate href="{{ route('lakin.index') }}" class="btn btn-ghost btn-sm">
                ← Daftar Rekap
            </a>
            <button type="button" class="btn btn-teal btn-sm" wire:click="unduhExcel" wire:loading.attr="disabled" wire:target="unduhExcel">
                <span wire:loading.remove wire:target="unduhExcel">⬇ Unduh Excel</span>
                <span wire:loading wire:target="unduhExcel"><i class="spin"></i> Menyiapkan…</span>
            </button>
            @if ($this->isTimSakip())
                <button type="button" class="btn btn-ghost btn-sm" wire:click="bukaKonfirmasiSegarkan" wire:loading.attr="disabled" wire:target="segarkanAngka" title="Segarkan data capaian & verifikasi terbaru">
                    <span wire:loading.remove wire:target="segarkanAngka">↻ Segarkan Angka</span>
                    <span wire:loading wire:target="segarkanAngka"><i class="spin"></i> Menyegarkan…</span>
                </button>
            @endif
        </div>
    </div>

    {{-- Kartu Ringkasan Metrik --}}
    <div class="lakin-stat-grid">
        <div class="lakin-stat-card">
            <div class="lakin-stat-top">
                <span class="lakin-stat-label">Total Indikator</span>
                <span class="lakin-stat-icon">🧾</span>
            </div>
            <div class="lakin-stat-val">{{ $lakin->baris->count() }}</div>
            <div class="lakin-stat-sub">
                <span>🎯 {{ $jumlahSasaran }} Sasaran Strategis</span>
            </div>
        </div>

        <div class="lakin-stat-card">
            <div class="lakin-stat-top">
                <span class="lakin-stat-label">Rata-rata Capaian</span>
                <span class="lakin-stat-icon">🎯</span>
            </div>
            <div class="lakin-stat-val">
                <span>{{ $rataRata->isNotEmpty() ? round($rataRata->avg(), 1) : '-' }}%</span>
                @if ($rataRata->isNotEmpty())
                    <span class="badge {{ $badgeCapaian($rataRata->avg()) }}" style="font-size:11px;padding:2px 8px;font-weight:700">
                        {{ $rataRata->avg() >= 90 ? 'Tinggi' : ($rataRata->avg() >= 70 ? 'Sedang' : 'Rendah') }}
                    </span>
                @endif
            </div>
            @if ($rataRata->isNotEmpty())
                @php
                    $avgPct = min(100, max(0, $rataRata->avg()));
                    $avgColor = $rataRata->avg() >= 90 ? 'green' : ($rataRata->avg() >= 70 ? 'amber' : 'red');
                @endphp
                <div class="lakin-progress-wrap" style="max-width:100%">
                    <div class="lakin-progress-fill {{ $avgColor }}" style="width:{{ $avgPct }}%"></div>
                </div>
            @else
                <div class="lakin-stat-sub">Belum ada nilai capaian</div>
            @endif
        </div>

        <div class="lakin-stat-card">
            <div class="lakin-stat-top">
                <span class="lakin-stat-label">Distribusi Capaian</span>
                <span class="lakin-stat-icon">📊</span>
            </div>
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin:4px 0">
                <span class="badge b-approve" style="font-size:11px;padding:3px 7px;font-weight:700" title="Capaian ≥ 90%">
                    {{ $jumlahTinggi }} Tinggi
                </span>
                <span class="badge b-tunggu" style="font-size:11px;padding:3px 7px;font-weight:700" title="Capaian 70% - 89.9%">
                    {{ $jumlahSedang }} Sedang
                </span>
                <span class="badge b-tolak" style="font-size:11px;padding:3px 7px;font-weight:700" title="Capaian < 70%">
                    {{ $jumlahRendah }} Rendah
                </span>
            </div>
            <div class="lakin-stat-sub">Berdasarkan standar capaian organisasi</div>
        </div>

        <div class="lakin-stat-card">
            <div class="lakin-stat-top">
                <span class="lakin-stat-label">Kelengkapan RTL</span>
                <span class="lakin-stat-icon">🔄</span>
            </div>
            <div class="lakin-stat-val">{{ $sudahAdaRtl }} / {{ $lakin->baris->count() }}</div>
            <div class="lakin-stat-sub">
                <span>{{ $lakin->baris->count() > 0 ? round(($sudahAdaRtl / $lakin->baris->count()) * 100) : 0 }}% indikator memiliki RTL</span>
            </div>
        </div>
    </div>

    {{-- CARD UTAMA: TABEL / KARTU REKAP --}}
    <div class="card">
        <div class="card-h" style="justify-content:space-between;flex-wrap:wrap;gap:10px">
            <div style="display:flex;align-items:center;gap:10px">
                <span style="font-size:15px;font-weight:700">📋 Tabel Rekap Kinerja</span>
                <span class="badge" style="font-size:11.5px;font-weight:700">{{ $lakin->baris->count() }} Baris</span>
            </div>

            <!-- Switcher Tampilan -->
            <div class="lakin-view-switch">
                <button type="button" class="lakin-view-btn {{ in_array($tampilan, ['rapi', 'tabel'], true) ? 'active' : '' }}" wire:click="gantiTampilan('rapi')" title="Tampilan tabel bersih, rapi, dan mudah dibaca">
                    <span>👁️</span>
                    <span>Tabel Rapi</span>
                </button>
                @if ($this->isTimSakip())
                    <button type="button" class="lakin-view-btn {{ $tampilan === 'edit' ? 'active' : '' }}" wire:click="gantiTampilan('edit')" title="Mode sunting massal langsung di tabel (spreadsheet)">
                        <span>✏️</span>
                        <span>Sunting Tabel</span>
                    </button>
                @endif
                <button type="button" class="lakin-view-btn {{ $tampilan === 'kartu' ? 'active' : '' }}" wire:click="gantiTampilan('kartu')" title="Tampilan kartu responsif untuk smartphone, tablet, atau tinjauan santai">
                    <span>📱</span>
                    <span>Mode Kartu</span>
                </button>
            </div>
        </div>

        @if ($lakin->baris->isNotEmpty())
            <!-- Toolbar Pencarian & Filter Cepat -->
            <div class="lakin-toolbar">
                <div class="lakin-toolbar-group">
                    <input type="search" class="lakin-filter-inp" placeholder="🔍 Cari indikator, sasaran, PIC…" x-model="kataKunci" style="width:100%;max-width:280px">
                    
                    <select class="lakin-filter-inp" x-model="filterSasaran" style="max-width:260px">
                        <option value="semua">Semua Sasaran ({{ $jumlahSasaran }})</option>
                        @foreach ($barisPerSasaran->keys() as $optSasaran)
                            <option value="{{ $optSasaran }}">{{ Str::limit($optSasaran, 40) }}</option>
                        @endforeach
                    </select>

                    <select class="lakin-filter-inp" x-model="filterCapaian" style="max-width:180px">
                        <option value="semua">Semua Capaian</option>
                        <option value="tinggi">Tinggi (≥90%)</option>
                        <option value="sedang">Sedang (70-89%)</option>
                        <option value="rendah">Rendah (&lt;70%)</option>
                    </select>
                </div>

                <div class="lakin-hint-alert">
                    @if (in_array($tampilan, ['rapi', 'tabel'], true))
                        <span>💡 Kolom Indikator terkunci di kiri. Klik <b>✏️ Sunting</b> untuk mengubah data per baris.</span>
                    @elseif ($tampilan === 'edit')
                        <span>✏️ <em>Mode Sunting Tabel: Anda dapat mengedit isian langsung di sel tabel lalu tekan Simpan.</em></span>
                    @else
                        <span>📱 <em>Mode Kartu: Format kartu memudahkan evaluasi rincian kinerja indikator secara fokus.</em></span>
                    @endif
                </div>
            </div>
        @endif

        @if ($lakin->baris->isEmpty())
            <div style="padding:48px 16px;text-align:center;color:var(--muted)">
                <div style="font-size:42px;margin-bottom:12px">📋</div>
                <div style="font-weight:700;font-size:15px;color:var(--ink)">Belum Ada Baris Rekap Kinerja</div>
                <p style="font-size:13px;margin-top:6px;max-width:500px;margin-left:auto;margin-right:auto;line-height:1.5">
                    {{ $this->isTimSakip() ? 'Pilih dari data capaian tahun '.$lakin->tahun.' di bawah atau tambahkan baris custom secara manual untuk memulai rekap tahunan.' : 'Data rekap belum diisi oleh Tim SAKIP.' }}
                </p>
            </div>

        {{-- ============================================================== --}}
        {{-- MODE 1: TABEL RAPI (EXECUTIVE CLEAN VIEW - DEFAULT)             --}}
        {{-- ============================================================== --}}
        @elseif (in_array($tampilan, ['rapi', 'tabel'], true))
            <div class="lakin-table-wrap">
                <table class="lakin-table">
                    <thead>
                        <tr>
                            <th class="th-sticky-left" style="width:340px;min-width:340px">Indikator Kinerja</th>
                            <th style="text-align:right;width:110px;min-width:110px">Target</th>
                            <th style="text-align:right;width:110px;min-width:110px">Realisasi</th>
                            <th style="text-align:center;width:110px;min-width:110px">Capaian %</th>
                            <th style="width:260px;min-width:260px">Kegiatan</th>
                            <th style="width:260px;min-width:260px">Solusi &amp; Kendala</th>
                            <th style="width:260px;min-width:260px">RTL (Rencana Tindak Lanjut)</th>
                            <th style="width:160px;min-width:160px">PIC &amp; Batas Waktu</th>
                            <th style="width:140px;min-width:140px">Tautan Bukti</th>
                            @if ($this->isTimSakip())
                                <th class="th-sticky-right" style="width:115px;min-width:115px;text-align:center">Aksi</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($barisPerSasaran as $sasaran => $daftarBaris)
                            @php
                                $sasaranEscaped = addslashes($sasaran);
                            @endphp
                            <tr class="lakin-sasaran-tr" x-show="cocokSasaran('{{ $sasaranEscaped }}')">
                                <td colspan="{{ $this->isTimSakip() ? 10 : 9 }}">
                                    <div class="sasaran-title-box">
                                        <span style="font-size:16px">🎯</span>
                                        <span>{{ $sasaran }}</span>
                                        <span class="badge" style="background:var(--white);color:var(--navy);font-size:11px;font-weight:700;box-shadow:var(--sh-sm)">
                                            {{ $daftarBaris->count() }} Indikator
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            @foreach ($daftarBaris as $baris)
                                @php
                                    $indikatorEscaped = addslashes($baris->indikator);
                                    $capaianVal = $baris->capaian_persen !== null ? (float) $baris->capaian_persen : 'null';
                                @endphp
                                <tr wire:key="baris-rapi-{{ $baris->id }}" x-show="cocok('{{ $indikatorEscaped }}', '{{ $sasaranEscaped }}', {{ $capaianVal }})">
                                    {{-- 1. Indikator Kinerja (Sticky Kiri) --}}
                                    <td class="td-sticky-left">
                                        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:4px">
                                            @if ($baris->masterIku)
                                                <span class="badge" style="background:var(--blue-soft);color:var(--blue-600);font-size:10.5px;font-weight:700">
                                                    {{ $baris->masterIku->kode }}
                                                </span>
                                            @else
                                                <span class="badge" style="background:var(--bg);color:var(--muted);font-size:10px">Custom</span>
                                            @endif
                                            @if ($baris->sasaran)
                                                <span style="font-size:11px;color:var(--muted)">· {{ Str::limit($baris->sasaran, 32) }}</span>
                                            @endif
                                        </div>
                                        <div style="font-weight:600;font-size:13px;color:var(--ink);line-height:1.45">{{ $baris->indikator }}</div>
                                    </td>

                                    {{-- 2. Target --}}
                                    <td style="text-align:right;font-weight:600;font-variant-numeric:tabular-nums;white-space:nowrap">
                                        {{ $formatAngka($baris->target) }}
                                    </td>

                                    {{-- 3. Realisasi --}}
                                    <td style="text-align:right;font-weight:600;font-variant-numeric:tabular-nums;white-space:nowrap">
                                        {{ $formatAngka($baris->realisasi) }}
                                    </td>

                                    {{-- 4. Capaian % --}}
                                    <td style="text-align:center">
                                        <span class="badge {{ $badgeCapaian($baris->capaian_persen) }}" style="font-weight:800;padding:4px 9px;font-size:12px;box-shadow:var(--sh-sm)">
                                            {{ $formatPersen($baris->capaian_persen) }}
                                        </span>
                                        @if ($baris->capaian_persen !== null)
                                            @php
                                                $pct = min(100, max(0, (float) $baris->capaian_persen));
                                                $colorCls = $baris->capaian_persen >= 90 ? 'green' : ($baris->capaian_persen >= 70 ? 'amber' : 'red');
                                            @endphp
                                            <div class="lakin-progress-wrap" style="margin:6px auto 0" title="{{ $formatPersen($baris->capaian_persen) }}">
                                                <div class="lakin-progress-fill {{ $colorCls }}" style="width:{{ $pct }}%"></div>
                                            </div>
                                        @endif
                                    </td>

                                    {{-- 5. Kegiatan --}}
                                    <td>
                                        @if ($baris->kegiatan)
                                            <div class="lakin-text-cell">{{ $baris->kegiatan }}</div>
                                        @else
                                            <span class="lakin-text-empty">—</span>
                                        @endif
                                    </td>

                                    {{-- 6. Solusi & Kendala --}}
                                    <td>
                                        @if ($baris->solusi_kendala)
                                            <div class="lakin-text-cell">{{ $baris->solusi_kendala }}</div>
                                        @else
                                            <span class="lakin-text-empty">—</span>
                                        @endif
                                    </td>

                                    {{-- 7. RTL --}}
                                    <td>
                                        @if ($baris->rtl)
                                            <div class="lakin-text-cell">{{ $baris->rtl }}</div>
                                        @else
                                            <span class="lakin-text-empty">—</span>
                                        @endif
                                    </td>

                                    {{-- 8. PIC & Batas Waktu --}}
                                    <td>
                                        <div style="font-weight:600;font-size:12px;color:var(--ink);margin-bottom:3px">
                                            👤 {{ $baris->pic ?: '—' }}
                                        </div>
                                        <div style="font-size:11.5px;color:var(--muted)">
                                            📅 {{ $baris->batas_waktu_tindak_lanjut?->format('d/m/Y') ?: '—' }}
                                        </div>
                                    </td>

                                    {{-- 9. Link Bukti & RTL --}}
                                    <td>
                                        <div style="display:flex;flex-direction:column;gap:5px">
                                            @if ($baris->link_bukti_dukung_kinerja)
                                                <a href="{{ $baris->link_bukti_dukung_kinerja }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="padding:4px 8px;font-size:11px;display:inline-flex;align-items:center;gap:4px">
                                                    <span>🔗</span> Bukti
                                                </a>
                                            @endif
                                            @if ($baris->link_rtl_sebelumnya)
                                                <a href="{{ $baris->link_rtl_sebelumnya }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="padding:4px 8px;font-size:11px;display:inline-flex;align-items:center;gap:4px">
                                                    <span>🔗</span> RTL Lalu
                                                </a>
                                            @endif
                                            @if (!$baris->link_bukti_dukung_kinerja && !$baris->link_rtl_sebelumnya)
                                                <span class="lakin-text-empty">—</span>
                                            @endif
                                        </div>
                                    </td>

                                    {{-- 10. Aksi (Sticky Kanan, khusus Tim SAKIP) --}}
                                    @if ($this->isTimSakip())
                                        <td class="td-sticky-right">
                                            <div style="display:flex;align-items:center;justify-content:center;gap:6px">
                                                <button type="button" class="btn btn-ghost btn-sm" style="padding:5px 9px;font-size:11.5px;font-weight:600;color:var(--blue-600)" title="Sunting baris ini" wire:click="bukaEditBaris({{ $baris->id }})">
                                                    ✏️ Sunting
                                                </button>
                                                <button type="button" class="btn btn-ghost btn-sm" style="padding:5px 7px;color:var(--red)" title="Hapus baris" wire:click="confirmHapusBaris({{ $baris->id }})">
                                                    🗑
                                                </button>
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>

        {{-- ============================================================== --}}
        {{-- MODE 2: SUNTEK TABEL (SPREADSHEET INLINE MASS EDITING)          --}}
        {{-- ============================================================== --}}
        @elseif ($tampilan === 'edit')
            <div class="lakin-table-wrap">
                <table class="lakin-table">
                    <thead>
                        <tr>
                            <th class="th-sticky-left" style="width:340px;min-width:340px">Indikator Kinerja</th>
                            <th style="text-align:right;width:115px;min-width:115px">Target</th>
                            <th style="text-align:right;width:115px;min-width:115px">Realisasi</th>
                            <th style="text-align:center;width:105px;min-width:105px">Capaian %</th>
                            <th style="width:240px;min-width:240px">Kegiatan</th>
                            <th style="width:240px;min-width:240px">Solusi &amp; Kendala</th>
                            <th style="width:240px;min-width:240px">RTL (Rencana Tindak Lanjut)</th>
                            <th style="width:140px;min-width:140px">PIC</th>
                            <th style="width:150px;min-width:150px">Batas Waktu</th>
                            <th style="width:190px;min-width:190px">Link Bukti Dukung</th>
                            <th style="width:190px;min-width:190px">Link RTL Sebelumnya</th>
                            <th class="th-sticky-right" style="width:115px;min-width:115px;text-align:center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($barisPerSasaran as $sasaran => $daftarBaris)
                            @php
                                $sasaranEscaped = addslashes($sasaran);
                            @endphp
                            <tr class="lakin-sasaran-tr" x-show="cocokSasaran('{{ $sasaranEscaped }}')">
                                <td colspan="12">
                                    <div class="sasaran-title-box">
                                        <span style="font-size:16px">🎯</span>
                                        <span>{{ $sasaran }}</span>
                                        <span class="badge" style="background:var(--white);color:var(--navy);font-size:11px;font-weight:700">
                                            {{ $daftarBaris->count() }} Indikator
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            @foreach ($daftarBaris as $baris)
                                @php
                                    $indikatorEscaped = addslashes($baris->indikator);
                                    $capaianVal = $baris->capaian_persen !== null ? (float) $baris->capaian_persen : 'null';
                                @endphp
                                <tr wire:key="baris-edt-{{ $baris->id }}" x-show="cocok('{{ $indikatorEscaped }}', '{{ $sasaranEscaped }}', {{ $capaianVal }})">
                                    {{-- 1. Indikator (Sticky Kiri) --}}
                                    <td class="td-sticky-left">
                                        <textarea class="tbl-textarea" style="height:62px;font-weight:600" placeholder="Ketik indikator…" wire:model="edit.{{ $baris->id }}.indikator"></textarea>
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
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>

        {{-- ============================================================== --}}
        {{-- MODE 3: KARTU RESPONSIF                                         --}}
        {{-- ============================================================== --}}
        @else
            <div class="lakin-cards-wrap">
                @foreach ($barisPerSasaran as $sasaran => $daftarBaris)
                    @php
                        $sasaranEscaped = addslashes($sasaran);
                    @endphp
                    <div class="lakin-group-card" x-show="cocokSasaran('{{ $sasaranEscaped }}')">
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
                                    $persenAngka = $baris->capaian_persen !== null ? min(100, max(0, (float) $baris->capaian_persen)) : 0;
                                    $capaianVal = $baris->capaian_persen !== null ? (float) $baris->capaian_persen : 'null';
                                    $barisBadge = $badgeCapaian($baris->capaian_persen);
                                @endphp
                                <div class="lakin-card" wire:key="baris-crd-{{ $baris->id }}" x-show="cocok('{{ $indikatorEscaped }}', '{{ $sasaranEscaped }}', {{ $capaianVal }})">
                                    {{-- Baris Atas: Kode & Indikator & Capaian --}}
                                    <div class="lakin-card-head">
                                        <div style="flex:1;min-width:0">
                                            <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">
                                                @if ($baris->masterIku)
                                                    <span class="badge" style="background:var(--blue-soft);color:var(--blue-600);font-size:10.5px;font-weight:700">
                                                        {{ $baris->masterIku->kode }}
                                                    </span>
                                                @endif
                                                @if ($baris->sasaran)
                                                    <span style="font-size:11px;color:var(--muted)">🏷️ {{ $baris->sasaran }}</span>
                                                @endif
                                            </div>
                                            <div style="font-size:14px;font-weight:700;color:var(--ink);line-height:1.45">
                                                {{ $baris->indikator }}
                                            </div>
                                        </div>

                                        <div style="flex:none;text-align:right">
                                            <span class="badge {{ $barisBadge }}" style="font-size:13px;font-weight:800;padding:5px 11px">
                                                {{ $formatPersen($baris->capaian_persen) }}
                                            </span>
                                        </div>
                                    </div>

                                    {{-- Strip Metrik Capaian --}}
                                    <div class="lakin-card-metrics">
                                        <div class="lakin-metric-box">
                                            <div class="lakin-metric-lbl">Target</div>
                                            <div class="lakin-metric-val">{{ $formatAngka($baris->target) }}</div>
                                        </div>

                                        <div class="lakin-metric-box">
                                            <div class="lakin-metric-lbl">Realisasi</div>
                                            <div class="lakin-metric-val">{{ $formatAngka($baris->realisasi) }}</div>
                                        </div>

                                        <div class="lakin-metric-box">
                                            <div class="lakin-metric-lbl">Capaian %</div>
                                            <div class="lakin-metric-val" style="color:var(--blue-600)">{{ $formatPersen($baris->capaian_persen) }}</div>
                                            <div class="quota-bar" style="height:5px;margin-top:4px" title="Capaian {{ $formatPersen($baris->capaian_persen) }}">
                                                <div class="quota-fill {{ $baris->capaian_persen >= 90 ? '' : ($baris->capaian_persen >= 70 ? 'warn' : 'danger') }}" style="width:{{ $persenAngka }}%"></div>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Grid Rincian Kegiatan & RTL --}}
                                    <div class="lakin-card-grid">
                                        <div>
                                            <label style="font-size:11px;font-weight:700;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase">📝 Kegiatan</label>
                                            <div style="background:var(--bg);padding:9px 12px;border-radius:8px;font-size:12.5px;min-height:48px;white-space:pre-wrap;line-height:1.5;color:var(--ink)">
                                                {{ $baris->kegiatan ?: '—' }}
                                            </div>
                                        </div>

                                        <div>
                                            <label style="font-size:11px;font-weight:700;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase">💡 Solusi &amp; Kendala</label>
                                            <div style="background:var(--bg);padding:9px 12px;border-radius:8px;font-size:12.5px;min-height:48px;white-space:pre-wrap;line-height:1.5;color:var(--ink)">
                                                {{ $baris->solusi_kendala ?: '—' }}
                                            </div>
                                        </div>

                                        <div style="grid-column: 1 / -1">
                                            <label style="font-size:11px;font-weight:700;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase">🔄 Rencana Tindak Lanjut (RTL)</label>
                                            <div style="background:var(--bg);padding:9px 12px;border-radius:8px;font-size:12.5px;min-height:48px;white-space:pre-wrap;line-height:1.5;color:var(--ink)">
                                                {{ $baris->rtl ?: '—' }}
                                            </div>
                                        </div>

                                        <div>
                                            <label style="font-size:11px;font-weight:700;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase">👤 Penanggung Jawab (PIC)</label>
                                            <div style="background:var(--bg);padding:7px 12px;border-radius:8px;font-size:12.5px;font-weight:600;color:var(--ink)">
                                                {{ $baris->pic ?: '—' }}
                                            </div>
                                        </div>

                                        <div>
                                            <label style="font-size:11px;font-weight:700;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase">📅 Batas Waktu Tindak Lanjut</label>
                                            <div style="background:var(--bg);padding:7px 12px;border-radius:8px;font-size:12.5px;color:var(--ink)">
                                                {{ $baris->batas_waktu_tindak_lanjut?->format('d F Y') ?: '—' }}
                                            </div>
                                        </div>

                                        <div>
                                            <label style="font-size:11px;font-weight:700;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase">🔗 Bukti Dukung Kinerja</label>
                                            @if ($baris->link_bukti_dukung_kinerja)
                                                <a href="{{ $baris->link_bukti_dukung_kinerja }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;gap:6px">
                                                    <span>🔗</span> Buka Tautan Bukti Dukung
                                                </a>
                                            @else
                                                <div style="background:var(--bg);padding:7px 12px;border-radius:8px;font-size:12px;color:var(--faint)">Belum ada tautan</div>
                                            @endif
                                        </div>

                                        <div>
                                            <label style="font-size:11px;font-weight:700;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase">🔗 RTL Sebelumnya</label>
                                            @if ($baris->link_rtl_sebelumnya)
                                                <a href="{{ $baris->link_rtl_sebelumnya }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;gap:6px">
                                                    <span>🔗</span> Buka Tautan RTL Sebelumnya
                                                </a>
                                            @else
                                                <div style="background:var(--bg);padding:7px 12px;border-radius:8px;font-size:12px;color:var(--faint)">Belum ada tautan</div>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Tombol Aksi di Bawah Kartu --}}
                                    @if ($this->isTimSakip())
                                        <div class="lakin-card-foot">
                                            <span style="font-size:11.5px;color:var(--muted)">
                                                Baris #{{ $baris->id }}
                                            </span>
                                            <div class="btn-row" style="margin:0;gap:8px">
                                                <button type="button" class="btn btn-ghost btn-sm" style="color:var(--red)" wire:click="confirmHapusBaris({{ $baris->id }})">
                                                    🗑 Hapus Baris
                                                </button>
                                                <button type="button" class="btn btn-primary btn-sm" wire:click="bukaEditBaris({{ $baris->id }})">
                                                    ✏️ Sunting Baris
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
                    <span style="font-size:15px;font-weight:700">📥 Tambah dari Data Capaian</span>
                    <span class="badge" style="font-size:11px;font-weight:700">{{ $ikuBelumDitambahkan->count() }} Tersedia</span>
                </div>
            </div>

            <div class="info" style="margin-bottom:14px">
                ℹ️ IKU yang sudah diverifikasi Tim SAKIP otomatis tercentang. IKU yang belum diverifikasi tetap bisa dicentang manual. Tekan tombol <b>Tambahkan Terpilih</b> untuk memasukkan ke rekap kinerja tahunan.
            </div>

            @error('ikuTerpilihUntukTambah')
                <div style="color:var(--red);font-size:12px;margin-bottom:12px;font-weight:600">{{ $message }}</div>
            @enderror

            @if ($ikuBelumDitambahkan->isEmpty())
                <div style="padding:22px;text-align:center;background:var(--bg);border-radius:10px;color:var(--muted);font-size:13px">
                    ✅ Seluruh Master IKU yang memiliki data capaian pada tahun {{ $lakin->tahun }} sudah ditambahkan ke rekap kinerja.
                </div>
            @else
                <div class="btn-row" style="margin-bottom:14px;gap:8px">
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="pilihSemuaUntukTambah">☑ Pilih Semua</button>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="batalkanSemuaUntukTambah">☐ Batalkan Semua</button>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;margin-bottom:16px">
                    @foreach ($ikuBelumDitambahkan as $data)
                        <label style="display:flex;align-items:flex-start;gap:12px;padding:14px;border:1.5px solid var(--line);border-radius:10px;background:var(--white);cursor:pointer;transition:border-color .15s, box-shadow .15s" class="hover:border-blue-400">
                            <input type="checkbox" value="{{ $data->iku->id }}" wire:model="ikuTerpilihUntukTambah" style="margin-top:3px;accent-color:var(--blue)">
                            <div style="flex:1;min-width:0">
                                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:4px">
                                    <b style="font-size:13px;color:var(--ink)">{{ $data->iku->kode }}</b>
                                    @if ($data->terverifikasi)
                                        <x-badge-status status="diverifikasi" />
                                    @else
                                        <x-badge-status status="menunggu" label="Belum Diverifikasi" />
                                    @endif
                                </div>
                                <div style="font-size:12px;color:var(--ink);line-height:1.45;margin-bottom:6px">{{ $data->iku->indikator }}</div>
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
            <div class="card-h" style="font-size:15px;font-weight:700">✏️ Tambah Baris Custom Manual</div>
            <div class="info" style="margin-bottom:14px">
                ℹ️ Digunakan untuk indikator tambahan di luar Master IKU atau format pelaporan khusus yang disesuaikan.
            </div>

            <div class="grid grid-2">
                <div class="field">
                    <label>Sasaran Strategis (opsional)</label>
                    <input type="text" class="inp filled" placeholder="Contoh: Terwujudnya Pelayanan Publik..." wire:model="sasaranBaru">
                </div>

                <div class="field">
                    <label>Indikator Kinerja <span class="req">*</span></label>
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

                <div class="field" style="grid-column: 1 / -1">
                    <label>Uraian Kegiatan (opsional)</label>
                    <textarea class="inp filled" rows="2" placeholder="Uraian kegiatan yang dilaksanakan…" wire:model="kegiatanBaru"></textarea>
                </div>

                <div class="field" style="grid-column: 1 / -1">
                    <label>Solusi &amp; Kendala (opsional)</label>
                    <textarea class="inp filled" rows="2" placeholder="Kendala yang dihadapi serta solusinya…" wire:model="solusiKendalaBaru"></textarea>
                </div>

                <div class="field" style="grid-column: 1 / -1">
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

                <div class="field">
                    <label>Link RTL Sebelumnya (opsional)</label>
                    <input type="url" class="inp filled" placeholder="https://…" wire:model="linkRtlSebelumnyaBaru">
                </div>
            </div>

            <div class="btn-row" style="margin-top:16px">
                <button type="button" class="btn btn-ghost" wire:click="tambahBaris" wire:loading.attr="disabled" wire:target="tambahBaris">
                    <span wire:loading.remove wire:target="tambahBaris">＋ Tambahkan Baris Custom</span>
                    <span wire:loading wire:target="tambahBaris"><i class="spin"></i> Menambahkan…</span>
                </button>
            </div>
        </div>
    @endif

    {{-- ============================================================== --}}
    {{-- MODAL SUNTING SATU BARIS (UNTUK MODE RAPI & KARTU)             --}}
    {{-- ============================================================== --}}
    @if ($editingBarisId !== null)
        @php
            $targetVal = $edit[$editingBarisId]['target'] ?? '';
            $realisasiVal = $edit[$editingBarisId]['realisasi'] ?? '';
            $capaianCalc = null;
            if (is_numeric($targetVal) && (float)$targetVal > 0 && is_numeric($realisasiVal)) {
                $capaianCalc = round(((float)$realisasiVal / (float)$targetVal) * 100, 2);
            }
        @endphp
        <div class="modal-overlay" style="z-index:75">
            <div class="modal" style="max-width:820px;height:auto;max-height:90vh;overflow-y:auto">
                <div class="modal-top">
                    <div class="mt-t">✏️ Sunting Indikator Rekap Kinerja</div>
                    <button type="button" class="x" wire:click="tutupEditBaris" title="Tutup">✕</button>
                </div>
                <div style="padding:22px">
                    <div class="grid grid-2" style="gap:14px">
                        <div class="field" style="grid-column: 1 / -1">
                            <label>Indikator Kinerja <span class="req">*</span></label>
                            <textarea class="inp filled" rows="2" style="font-weight:600;font-size:13.5px" placeholder="Ketik indikator kinerja…" wire:model="edit.{{ $editingBarisId }}.indikator"></textarea>
                            @error("edit.{$editingBarisId}.indikator")
                                <div style="color:var(--red);font-size:11.5px;margin-top:4px;font-weight:600">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="field" style="grid-column: 1 / -1">
                            <label>Sasaran Strategis (opsional)</label>
                            <input type="text" class="inp filled" placeholder="Contoh: Terwujudnya data insight..." wire:model="edit.{{ $editingBarisId }}.sasaran">
                        </div>

                        <div class="field">
                            <label>Target</label>
                            <input type="number" step="0.01" class="inp filled" placeholder="0" wire:model.live="edit.{{ $editingBarisId }}.target">
                        </div>

                        <div class="field">
                            <label>Realisasi</label>
                            <input type="number" step="0.01" class="inp filled" placeholder="0" wire:model.live="edit.{{ $editingBarisId }}.realisasi">
                        </div>

                        @if ($capaianCalc !== null)
                            <div class="field" style="grid-column: 1 / -1;background:var(--bg);padding:10px 14px;border-radius:8px">
                                <span style="font-size:12px;color:var(--muted)">Perkiraan Capaian: </span>
                                <b style="font-size:13px;color:var(--ink)">{{ $capaianCalc }}%</b>
                                <span class="badge {{ $capaianCalc >= 90 ? 'b-approve' : ($capaianCalc >= 70 ? 'b-tunggu' : 'b-tolak') }}" style="font-size:10.5px;margin-left:6px">
                                    {{ $capaianCalc >= 90 ? 'Tinggi' : ($capaianCalc >= 70 ? 'Sedang' : 'Rendah') }}
                                </span>
                            </div>
                        @endif

                        <div class="field" style="grid-column: 1 / -1">
                            <label>Uraian Kegiatan</label>
                            <textarea class="inp filled" rows="3" placeholder="Rincian kegiatan yang dilaksanakan…" wire:model="edit.{{ $editingBarisId }}.kegiatan"></textarea>
                        </div>

                        <div class="field" style="grid-column: 1 / -1">
                            <label>Kendala &amp; Solusi</label>
                            <textarea class="inp filled" rows="3" placeholder="Kendala yang dihadapi serta solusi penyelesaiannya…" wire:model="edit.{{ $editingBarisId }}.solusi_kendala"></textarea>
                        </div>

                        <div class="field" style="grid-column: 1 / -1">
                            <label>RTL (Rencana Tindak Lanjut)</label>
                            <textarea class="inp filled" rows="3" placeholder="Rencana tindak lanjut ke depan…" wire:model="edit.{{ $editingBarisId }}.rtl"></textarea>
                        </div>

                        <div class="field">
                            <label>PIC / Penanggung Jawab</label>
                            <input type="text" class="inp filled" placeholder="Nama PIC…" wire:model="edit.{{ $editingBarisId }}.pic">
                        </div>

                        <div class="field">
                            <label>Batas Waktu Tindak Lanjut</label>
                            <input type="date" class="inp filled" wire:model="edit.{{ $editingBarisId }}.batas_waktu_tindak_lanjut">
                        </div>

                        <div class="field">
                            <label>Link Bukti Dukung Kinerja</label>
                            <input type="url" class="inp filled" placeholder="https://…" wire:model="edit.{{ $editingBarisId }}.link_bukti_dukung_kinerja">
                        </div>

                        <div class="field">
                            <label>Link RTL Sebelumnya</label>
                            <input type="url" class="inp filled" placeholder="https://…" wire:model="edit.{{ $editingBarisId }}.link_rtl_sebelumnya">
                        </div>
                    </div>

                    <div class="btn-row" style="margin-top:22px;justify-content:flex-end;gap:10px">
                        <button type="button" class="btn btn-ghost" wire:click="tutupEditBaris">Batal</button>
                        <button type="button" class="btn btn-primary" wire:click="simpanBarisModal" wire:loading.attr="disabled" wire:target="simpanBarisModal">
                            <span wire:loading.remove wire:target="simpanBarisModal">💾 Simpan Perubahan</span>
                            <span wire:loading wire:target="simpanBarisModal"><i class="spin"></i> Menyimpan…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal Konfirmasi Segarkan --}}
    <x-confirm-modal :show="$showSegarkanConfirm" title="Segarkan Data Rekap?" :danger="false"
        message="Segarkan angka dan rincian tindak lanjut (kegiatan, kendala &amp; solusi, RTL, PIC, tautan bukti) dari data capaian &amp; verifikasi terbaru? Baris custom dan kolom yang sudah Anda sunting tidak akan berubah.">
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