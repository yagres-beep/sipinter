@php
    $formatAngka = fn ($nilai) => \App\Models\PengaturanCapaian::formatAngka($nilai);
    $formatPersen = fn ($nilai) => \App\Models\PengaturanCapaian::formatPersen($nilai);

    $badgeCapaian = function (?float $persen) use ($formatPersen) {
        if ($formatPersen($persen) === '-') {
            return 'b-draft';
        }

        return $persen >= 90 ? 'b-approve' : ($persen >= 70 ? 'b-tunggu' : 'b-tolak');
    };

    /**
     * Memecah teks tindak lanjut (kegiatan/kendala/RTL) yang dikelompokkan
     * dengan header [Triwulan ...] atau [Tahunan] menjadi array blok.
     */
    $parseBlocks = function (?string $teks, ?int $filterTw = null) {
        if (blank($teks)) {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', (string) $teks);
        $blocks = [];
        $currentLabel = 'Umum';
        $currentLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^\[(Triwulan\s+[IVX]+|Tahunan)\]$/i', $trimmed, $m)) {
                if (!empty($currentLines)) {
                    $blocks[$currentLabel] = trim(implode("\n", $currentLines));
                    $currentLines = [];
                }
                $currentLabel = $m[1];
            } else {
                $currentLines[] = $line;
            }
        }
        if (!empty($currentLines)) {
            $blocks[$currentLabel] = trim(implode("\n", $currentLines));
        }

        // Jika ada filter triwulan aktif (1..4), tampilkan HANYA triwulan tersebut
        if ($filterTw !== null && $filterTw >= 1 && $filterTw <= 4) {
            $rom = ['I', 'II', 'III', 'IV'][$filterTw - 1];
            $targetLabel = "Triwulan {$rom}";
            foreach ($blocks as $lbl => $content) {
                if (strcasecmp($lbl, $targetLabel) === 0) {
                    return [$lbl => $content];
                }
            }
            return [];
        }

        return $blocks;
    };

    $twColorClass = function (string $lbl) {
        if (stripos($lbl, 'Triwulan I') !== false && stripos($lbl, 'Triwulan II') === false && stripos($lbl, 'Triwulan III') === false && stripos($lbl, 'Triwulan IV') === false) return 'tw-1';
        if (stripos($lbl, 'Triwulan II') !== false) return 'tw-2';
        if (stripos($lbl, 'Triwulan III') !== false) return 'tw-3';
        if (stripos($lbl, 'Triwulan IV') !== false) return 'tw-4';
        return 'tw-def';
    };

    $renderKegiatanHtml = function (array $blocks) use ($twColorClass) {
        if (empty($blocks)) {
            return '<div class="lakin-text-empty">— Tidak ada data kegiatan</div>';
        }

        $html = '';
        foreach ($blocks as $lbl => $content) {
            $cls = $twColorClass($lbl);
            $lines = array_filter(array_map('trim', explode("\n", $content)));
            $html .= '<div class="lakin-content-section">';
            if ($lbl !== 'Umum') {
                $html .= '<div class="lakin-section-badge ' . $cls . '">📅 ' . e($lbl) . '</div>';
            }
            $html .= '<ul class="lakin-item-list">';
            foreach ($lines as $line) {
                $cleanLine = preg_replace('/^(\d+\.\s*|•\s*|-+\s*)/', '', $line);
                $html .= '<li><span class="lakin-bullet">▸</span><span>' . e($cleanLine) . '</span></li>';
            }
            $html .= '</ul></div>';
        }
        return $html;
    };

    $renderSolusiKendalaHtml = function (array $blocks) use ($twColorClass) {
        if (empty($blocks)) {
            return '<div class="lakin-text-empty">— Tidak ada kendala & solusi</div>';
        }

        $html = '';
        foreach ($blocks as $lbl => $content) {
            $cls = $twColorClass($lbl);
            $html .= '<div class="lakin-content-section">';
            if ($lbl !== 'Umum') {
                $html .= '<div class="lakin-section-badge ' . $cls . '">📅 ' . e($lbl) . '</div>';
            }

            $items = preg_split('/(?=\b\d+\.\s*Kendala:)/i', $content, -1, PREG_SPLIT_NO_EMPTY);
            if (empty($items)) {
                $items = [$content];
            }

            foreach ($items as $item) {
                $item = trim($item);
                $kendalaPart = null;
                $solusiPart = null;

                if (preg_match('/(?:^\d+\.\s*)?Kendala:\s*(.*?)(?:\s*Solusi:\s*(.*))?$/is', $item, $matches)) {
                    $kendalaPart = trim($matches[1] ?? '');
                    $solusiPart = trim($matches[2] ?? '');
                }

                if ($kendalaPart) {
                    $html .= '<div class="lakin-ks-box">';
                    $html .= '<div class="lakin-ks-item">';
                    $html .= '<span class="lakin-ks-tag tag-k">Kendala</span>';
                    $html .= '<span class="lakin-ks-desc">' . nl2br(e($kendalaPart)) . '</span>';
                    $html .= '</div>';
                    if ($solusiPart) {
                        $html .= '<div class="lakin-ks-item">';
                        $html .= '<span class="lakin-ks-tag tag-s">Solusi</span>';
                        $html .= '<span class="lakin-ks-desc">' . nl2br(e($solusiPart)) . '</span>';
                        $html .= '</div>';
                    }
                    $html .= '</div>';
                } else {
                    $html .= '<div class="lakin-ks-box"><div class="lakin-ks-item"><span class="lakin-ks-desc">' . nl2br(e($item)) . '</span></div></div>';
                }
            }
            $html .= '</div>';
        }
        return $html;
    };

    $renderRtlHtml = function (array $blocks) use ($twColorClass) {
        if (empty($blocks)) {
            return '<div class="lakin-text-empty">— Tidak ada RTL</div>';
        }

        $html = '';
        foreach ($blocks as $lbl => $content) {
            $cls = $twColorClass($lbl);
            $lines = array_filter(array_map('trim', explode("\n", $content)));
            $html .= '<div class="lakin-content-section">';
            if ($lbl !== 'Umum') {
                $html .= '<div class="lakin-section-badge ' . $cls . '">📅 ' . e($lbl) . '</div>';
            }
            $html .= '<ul class="lakin-rtl-list">';
            $idx = 1;
            foreach ($lines as $line) {
                $cleanLine = preg_replace('/^(\d+\.\s*|•\s*|-+\s*)/', '', $line);
                $html .= '<li><span class="lakin-rtl-num">' . ($idx++) . '</span><span>' . e($cleanLine) . '</span></li>';
            }
            $html .= '</ul></div>';
        }
        return $html;
    };

    $twAktif = $filterTriwulan !== 'semua' ? (int) $filterTriwulan : null;

    $rataRata = $lakin->baris->map(function ($b) use ($twAktif, $capaianTahunanMap) {
        if ($twAktif === null || ! $b->iku_id) {
            return $b->capaian_persen;
        }
        $ct = $capaianTahunanMap[$b->iku_id] ?? null;
        return $ct ? $ct->capaianTriwulanan($twAktif) : $b->capaian_persen;
    })->filter(fn ($p) => $p !== null);

    $jumlahSasaran = $lakin->baris->pluck('sasaran')->filter()->unique()->count();
    $barisPerSasaran = $lakin->baris->groupBy(fn ($b) => $b->sasaran ?: 'Tanpa Sasaran');

    $jumlahTinggi = $lakin->baris->filter(function ($b) use ($twAktif, $capaianTahunanMap) {
        $cap = $twAktif && $b->iku_id && isset($capaianTahunanMap[$b->iku_id])
            ? $capaianTahunanMap[$b->iku_id]->capaianTriwulanan($twAktif)
            : $b->capaian_persen;
        return $cap !== null && $cap >= 90;
    })->count();

    $jumlahSedang = $lakin->baris->filter(function ($b) use ($twAktif, $capaianTahunanMap) {
        $cap = $twAktif && $b->iku_id && isset($capaianTahunanMap[$b->iku_id])
            ? $capaianTahunanMap[$b->iku_id]->capaianTriwulanan($twAktif)
            : $b->capaian_persen;
        return $cap !== null && $cap >= 70 && $cap < 90;
    })->count();

    $jumlahRendah = $lakin->baris->filter(function ($b) use ($twAktif, $capaianTahunanMap) {
        $cap = $twAktif && $b->iku_id && isset($capaianTahunanMap[$b->iku_id])
            ? $capaianTahunanMap[$b->iku_id]->capaianTriwulanan($twAktif)
            : $b->capaian_persen;
        return $cap !== null && $cap < 70;
    })->count();

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
        <div class="page-sub">Laporan kinerja tahunan komprehensif — sasaran, indikator, alokasi per triwulan, capaian %, dan tindak lanjut.</div>
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
                <div style="font-size:20px;font-weight:800;color:var(--ink);line-height:1.2">
                    {{ $lakin->tahun }}
                    @if ($filterTriwulan !== 'semua')
                        <span style="font-size:15px;color:var(--blue-600);font-weight:700">· Triwulan {{ ['I', 'II', 'III', 'IV'][$filterTriwulan - 1] }}</span>
                    @endif
                </div>
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
                <button type="button" class="btn btn-ghost btn-sm" wire:click="bukaKonfirmasiSegarkan" wire:loading.attr="disabled" wire:target="segarkanAngka" title="Segarkan rincian per triwulan & tautan folder dari sistem terkini">
                    <span wire:loading.remove wire:target="segarkanAngka">↻ Segarkan Angka &amp; Rincian</span>
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
                <span class="lakin-stat-label">
                    {{ $filterTriwulan === 'semua' ? 'Rata-rata Capaian Setahun' : 'Rata-rata Capaian TW ' . ['I', 'II', 'III', 'IV'][$filterTriwulan - 1] }}
                </span>
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
            <div class="lakin-stat-sub">{{ $filterTriwulan === 'semua' ? 'Berdasarkan capaian kumulatif setahun' : 'Berdasarkan capaian triwulan terpilih' }}</div>
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
        <div class="card-h" style="justify-content:space-between;flex-wrap:wrap;gap:12px">
            <div style="display:flex;align-items:center;gap:10px">
                <span style="font-size:15px;font-weight:700">📋 Tabel Rekap Kinerja</span>
                <span class="badge" style="font-size:11.5px;font-weight:700">{{ $lakin->baris->count() }} Baris</span>
            </div>

            <!-- Pilihan Triwulan (Per Triwulan vs Setahun Penuh) -->
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <div class="lakin-tw-switch" title="Pilih Triwulan untuk memfilter angka target, realisasi, dan rincian triwulan">
                    <button type="button" class="lakin-tw-btn {{ $filterTriwulan === 'semua' ? 'active' : '' }}" wire:click="$set('filterTriwulan', 'semua')">
                        <span>🗓️</span> Setahun Penuh
                    </button>
                    @foreach ([1 => 'TW I', 2 => 'TW II', 3 => 'TW III', 4 => 'TW IV'] as $tNum => $tLbl)
                        <button type="button" class="lakin-tw-btn {{ $filterTriwulan === (string)$tNum ? 'active' : '' }}" wire:click="$set('filterTriwulan', '{{ $tNum }}')">
                            {{ $tLbl }}
                        </button>
                    @endforeach
                </div>

                <!-- Switcher Tampilan -->
                <div class="lakin-view-switch">
                    <button type="button" class="lakin-view-btn {{ in_array($tampilan, ['rapi', 'tabel'], true) ? 'active' : '' }}" wire:click="gantiTampilan('rapi')" title="Tampilan tabel spreadsheet rapi dengan indikator sticky yang tidak tumpang tindih">
                        <span>📋</span>
                        <span>Tabel Rekap</span>
                    </button>
                    <button type="button" class="lakin-view-btn {{ $tampilan === 'kartu' ? 'active' : '' }}" wire:click="gantiTampilan('kartu')" title="Tampilan kartu eksekutif komprehensif tanpa perlu geser horizontal">
                        <span>📊</span>
                        <span>Kartu Eksekutif</span>
                    </button>
                    @if ($this->isTimSakip())
                        <button type="button" class="lakin-view-btn {{ $tampilan === 'edit' ? 'active' : '' }}" wire:click="gantiTampilan('edit')" title="Mode sunting massal langsung di tabel (spreadsheet)">
                            <span>✏️</span>
                            <span>Sunting Massal</span>
                        </button>
                    @endif
                </div>
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
                    @if ($filterTriwulan === 'semua')
                        <span>💡 Rekapitulasi menampilkan capaian setahun penuh beserta rincian histori TW I s.d. TW IV.</span>
                    @else
                        <span>💡 Menampilkan capaian dan rincian khusus <b>Triwulan {{ ['I', 'II', 'III', 'IV'][$filterTriwulan - 1] }}</b>.</span>
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
                            @if ($filterTriwulan === 'semua')
                                <th style="text-align:right;width:115px;min-width:115px">Target (Setahun)</th>
                                <th style="text-align:right;width:115px;min-width:115px">Realisasi (Setahun)</th>
                                <th style="text-align:center;width:130px;min-width:130px">Capaian %</th>
                            @else
                                @php $romTw = ['I', 'II', 'III', 'IV'][$filterTriwulan - 1]; @endphp
                                <th style="text-align:right;width:115px;min-width:115px">Target (TW {{ $romTw }})</th>
                                <th style="text-align:right;width:115px;min-width:115px">Realisasi (TW {{ $romTw }})</th>
                                <th style="text-align:center;width:125px;min-width:125px">Capaian % (TW {{ $romTw }})</th>
                            @endif
                            <th style="width:270px;min-width:270px">Kegiatan</th>
                            <th style="width:270px;min-width:270px">Solusi &amp; Kendala</th>
                            <th style="width:270px;min-width:270px">RTL (Rencana Tindak Lanjut)</th>
                            <th style="width:160px;min-width:160px">PIC &amp; Batas Waktu</th>
                            <th style="width:140px;min-width:140px">Folder Bukti</th>
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
                                    $ct = $baris->iku_id ? ($capaianTahunanMap[$baris->iku_id] ?? null) : null;

                                    if ($filterTriwulan === 'semua' || ! $ct) {
                                        $targetBaris = $baris->target;
                                        $realisasiBaris = $baris->realisasi;
                                        $capaianBaris = $baris->capaian_persen;
                                    } else {
                                        $twN = (int) $filterTriwulan;
                                        $targetBaris = $ct->alokasiKumulatif($twN);
                                        $realisasiBaris = $ct->realisasiKumulatif($twN);
                                        $capaianBaris = $ct->capaianTriwulanan($twN);
                                    }

                                    $capaianVal = $capaianBaris !== null ? (float) $capaianBaris : 'null';
                                    $isFolderBukti = ! empty($baris->link_bukti_dukung_kinerja) && str_contains($baris->link_bukti_dukung_kinerja, '/drive/folders/');
                                    $isFolderRtl = ! empty($baris->link_rtl_sebelumnya) && str_contains($baris->link_rtl_sebelumnya, '/drive/folders/');
                                @endphp
                                <tr wire:key="baris-rapi-{{ $baris->id }}-{{ $filterTriwulan }}" x-show="cocok('{{ $indikatorEscaped }}', '{{ $sasaranEscaped }}', {{ $capaianVal }})">
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
                                        {{ $formatAngka($targetBaris) }}
                                    </td>

                                    {{-- 3. Realisasi --}}
                                    <td style="text-align:right;font-weight:600;font-variant-numeric:tabular-nums;white-space:nowrap">
                                        {{ $formatAngka($realisasiBaris) }}
                                    </td>

                                    {{-- 4. Capaian % --}}
                                    <td style="text-align:center">
                                        <span class="badge {{ $badgeCapaian($capaianBaris) }}" style="font-weight:800;padding:4px 9px;font-size:12px;box-shadow:var(--sh-sm)">
                                            {{ $formatPersen($capaianBaris) }}
                                        </span>
                                        @if ($capaianBaris !== null)
                                            @php
                                                $pct = min(100, max(0, (float) $capaianBaris));
                                                $colorCls = $capaianBaris >= 90 ? 'green' : ($capaianBaris >= 70 ? 'amber' : 'red');
                                            @endphp
                                            <div class="lakin-progress-wrap" style="margin:5px auto 0" title="{{ $formatPersen($capaianBaris) }}">
                                                <div class="lakin-progress-fill {{ $colorCls }}" style="width:{{ $pct }}%"></div>
                                            </div>
                                        @endif

                                        {{-- Matriks Capaian 4-Triwulan (jika melihat setahun penuh) --}}
                                        @if ($filterTriwulan === 'semua' && $ct)
                                            <div class="lakin-tw-matrix">
                                                @foreach ([1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV'] as $tN => $tRom)
                                                    @php
                                                        $cVal = $ct->capaianTriwulanan($tN);
                                                        $tVal = $ct->alokasiKumulatif($tN);
                                                        $rVal = $ct->realisasiKumulatif($tN);
                                                        $mCls = $cVal !== null ? ($cVal >= 90 ? 'up' : ($cVal >= 70 ? 'mid' : 'low')) : 'empty';
                                                    @endphp
                                                    <div class="lakin-tw-cell {{ $mCls }}" title="Triwulan {{ $tRom }}: Target {{ $formatAngka($tVal) }} | Real {{ $formatAngka($rVal) }} | Capaian {{ $formatPersen($cVal) }}">
                                                        <span class="m-lbl">TW {{ $tRom }}</span>
                                                        <span class="m-val">{{ $formatPersen($cVal) }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>

                                    {{-- 5. Kegiatan (dikelompokkan per Triwulan) --}}
                                    <td>
                                        <div class="lakin-text-cell">
                                            {!! $renderKegiatanHtml($parseBlocks($baris->kegiatan, $twAktif)) !!}
                                        </div>
                                    </td>

                                    {{-- 6. Solusi & Kendala (dikelompokkan per Triwulan) --}}
                                    <td>
                                        <div class="lakin-text-cell">
                                            {!! $renderSolusiKendalaHtml($parseBlocks($baris->solusi_kendala, $twAktif)) !!}
                                        </div>
                                    </td>

                                    {{-- 7. RTL (dikelompokkan per Triwulan) --}}
                                    <td>
                                        <div class="lakin-text-cell">
                                            {!! $renderRtlHtml($parseBlocks($baris->rtl, $twAktif)) !!}
                                        </div>
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

                                    {{-- 9. Folder Bukti & RTL (Hanya Folder Google Drive Resmi) --}}
                                    <td>
                                        @if ($isFolderBukti || $isFolderRtl)
                                            <div style="display:flex;flex-direction:column;gap:5px">
                                                @if ($isFolderBukti)
                                                    <a href="{{ $baris->link_bukti_dukung_kinerja }}" target="_blank" rel="noopener" class="lakin-drive-pill bukti" title="Buka Folder Bukti Dukung di Google Drive">
                                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                                                        <span>Folder Bukti</span>
                                                        <span class="ext">↗</span>
                                                    </a>
                                                @endif
                                                @if ($isFolderRtl)
                                                    <a href="{{ $baris->link_rtl_sebelumnya }}" target="_blank" rel="noopener" class="lakin-drive-pill rtl" title="Buka Folder RTL Sebelumnya di Google Drive">
                                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                                                        <span>Folder RTL</span>
                                                        <span class="ext">↗</span>
                                                    </a>
                                                @endif
                                            </div>
                                        @else
                                            <span class="lakin-text-empty">—</span>
                                        @endif
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
        {{-- MODE 2: SUNTING TABEL (SPREADSHEET INLINE MASS EDITING)         --}}
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
                            <th style="width:250px;min-width:250px">Kegiatan</th>
                            <th style="width:250px;min-width:250px">Solusi &amp; Kendala</th>
                            <th style="width:250px;min-width:250px">RTL</th>
                            <th style="width:140px;min-width:140px">PIC</th>
                            <th style="width:150px;min-width:150px">Batas Waktu</th>
                            <th style="width:190px;min-width:190px">Folder Bukti Dukung</th>
                            <th style="width:190px;min-width:190px">Folder RTL Sebelumnya</th>
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
                                    <td class="td-sticky-left">
                                        <textarea class="tbl-textarea" style="height:62px;font-weight:600" placeholder="Ketik indikator…" wire:model="edit.{{ $baris->id }}.indikator"></textarea>
                                        <input type="text" class="tbl-inp" style="margin-top:5px;font-size:11px;color:var(--muted);padding:4px 8px" placeholder="🏷️ Sasaran (opsional)" wire:model="edit.{{ $baris->id }}.sasaran">
                                        @error("edit.{$baris->id}.indikator")
                                            <div style="color:var(--red);font-size:11px;margin-top:3px;font-weight:600">{{ $message }}</div>
                                        @enderror
                                    </td>

                                    <td style="text-align:right">
                                        <input type="number" step="0.01" class="tbl-inp num" placeholder="0" wire:model="edit.{{ $baris->id }}.target">
                                    </td>

                                    <td style="text-align:right">
                                        <input type="number" step="0.01" class="tbl-inp num" placeholder="0" wire:model="edit.{{ $baris->id }}.realisasi">
                                    </td>

                                    <td style="text-align:center">
                                        <span class="badge {{ $badgeCapaian($baris->capaian_persen) }}" style="font-weight:700;padding:4px 8px;font-size:12px">
                                            {{ $formatPersen($baris->capaian_persen) }}
                                        </span>
                                    </td>

                                    <td>
                                        <textarea class="tbl-textarea" placeholder="Rincian kegiatan per triwulan…" wire:model="edit.{{ $baris->id }}.kegiatan"></textarea>
                                    </td>

                                    <td>
                                        <textarea class="tbl-textarea" placeholder="Kendala & solusi per triwulan…" wire:model="edit.{{ $baris->id }}.solusi_kendala"></textarea>
                                    </td>

                                    <td>
                                        <textarea class="tbl-textarea" placeholder="Rencana tindak lanjut per triwulan…" wire:model="edit.{{ $baris->id }}.rtl"></textarea>
                                    </td>

                                    <td>
                                        <input type="text" class="tbl-inp" placeholder="Nama PIC…" wire:model="edit.{{ $baris->id }}.pic">
                                    </td>

                                    <td>
                                        <input type="date" class="tbl-inp" wire:model="edit.{{ $baris->id }}.batas_waktu_tindak_lanjut">
                                    </td>

                                    <td>
                                        <div style="display:flex;align-items:center;gap:5px">
                                            <input type="url" class="tbl-inp" placeholder="https://drive.google.com/drive/folders/…" wire:model="edit.{{ $baris->id }}.link_bukti_dukung_kinerja">
                                            @if (!empty($baris->link_bukti_dukung_kinerja) && str_contains($baris->link_bukti_dukung_kinerja, '/drive/folders/'))
                                                <a href="{{ $baris->link_bukti_dukung_kinerja }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="padding:6px 8px;flex:none" title="Buka Folder Drive">📁</a>
                                            @endif
                                        </div>
                                    </td>

                                    <td>
                                        <div style="display:flex;align-items:center;gap:5px">
                                            <input type="url" class="tbl-inp" placeholder="https://drive.google.com/drive/folders/…" wire:model="edit.{{ $baris->id }}.link_rtl_sebelumnya">
                                            @if (!empty($baris->link_rtl_sebelumnya) && str_contains($baris->link_rtl_sebelumnya, '/drive/folders/'))
                                                <a href="{{ $baris->link_rtl_sebelumnya }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="padding:6px 8px;flex:none" title="Buka Folder RTL">📁</a>
                                            @endif
                                        </div>
                                    </td>

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
        {{-- MODE 3: KARTU EKSEKUTIF (SCORECARD DETAIL TANPA SCROLL HORIZONTAL)--}}
        {{-- ============================================================== --}}
        @else
            <div class="lakin-cards-wrap">
                @foreach ($barisPerSasaran as $sasaran => $daftarBaris)
                    @php
                        $sasaranEscaped = addslashes($sasaran);
                    @endphp
                    <div class="lakin-group-card" x-show="cocokSasaran('{{ $sasaranEscaped }}')">
                        <div class="lakin-group-header">
                            <div class="lakin-group-title">
                                <span style="font-size:16px">🎯</span>
                                <span>{{ $sasaran }}</span>
                            </div>
                            <span class="badge" style="background:var(--white);color:var(--navy);font-size:11.5px;font-weight:700">
                                {{ $daftarBaris->count() }} Indikator
                            </span>
                        </div>

                        <div class="lakin-group-body">
                            @foreach ($daftarBaris as $baris)
                                @php
                                    $indikatorEscaped = addslashes($baris->indikator);
                                    $ct = $baris->iku_id ? ($capaianTahunanMap[$baris->iku_id] ?? null) : null;

                                    if ($filterTriwulan === 'semua' || ! $ct) {
                                        $targetBaris = $baris->target;
                                        $realisasiBaris = $baris->realisasi;
                                        $capaianBaris = $baris->capaian_persen;
                                    } else {
                                        $twN = (int) $filterTriwulan;
                                        $targetBaris = $ct->alokasiKumulatif($twN);
                                        $realisasiBaris = $ct->realisasiKumulatif($twN);
                                        $capaianBaris = $ct->capaianTriwulanan($twN);
                                    }

                                    $capaianVal = $capaianBaris !== null ? (float) $capaianBaris : 'null';
                                    $barisBadge = $badgeCapaian($capaianBaris);
                                    $isFolderBukti = ! empty($baris->link_bukti_dukung_kinerja) && str_contains($baris->link_bukti_dukung_kinerja, '/drive/folders/');
                                    $isFolderRtl = ! empty($baris->link_rtl_sebelumnya) && str_contains($baris->link_rtl_sebelumnya, '/drive/folders/');
                                @endphp
                                <div class="lakin-card" wire:key="baris-crd-{{ $baris->id }}-{{ $filterTriwulan }}" x-show="cocok('{{ $indikatorEscaped }}', '{{ $sasaranEscaped }}', {{ $capaianVal }})">
                                    {{-- Header Kartu --}}
                                    <div class="lakin-card-head">
                                        <div style="flex:1;min-width:0">
                                            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:6px">
                                                @if ($baris->masterIku)
                                                    <span class="badge" style="background:var(--blue-soft);color:var(--blue-600);font-size:11px;font-weight:800">
                                                        IKU {{ $baris->masterIku->kode }}
                                                    </span>
                                                @else
                                                    <span class="badge" style="background:var(--bg);color:var(--muted);font-size:10.5px">Custom</span>
                                                @endif
                                                @if ($baris->sasaran)
                                                    <span style="font-size:11.5px;color:var(--muted);font-weight:600">🎯 {{ $baris->sasaran }}</span>
                                                @endif
                                            </div>
                                            <div style="font-size:15px;font-weight:800;color:var(--ink);line-height:1.4">
                                                {{ $baris->indikator }}
                                            </div>
                                        </div>

                                        <div style="flex:none;text-align:right">
                                            <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:3px">
                                                {{ $filterTriwulan === 'semua' ? 'Capaian Setahun' : 'Capaian TW ' . ['I', 'II', 'III', 'IV'][$filterTriwulan - 1] }}
                                            </div>
                                            <span class="badge {{ $barisBadge }}" style="font-size:14px;font-weight:800;padding:6px 12px;box-shadow:var(--sh-sm)">
                                                {{ $formatPersen($capaianBaris) }}
                                            </span>
                                        </div>
                                    </div>

                                    {{-- Strip Metrik 5 Kolom: TW I, TW II, TW III, TW IV, Setahun Penuh --}}
                                    <div class="lakin-card-5col">
                                        @foreach ([1 => 'TW I', 2 => 'TW II', 3 => 'TW III', 4 => 'TW IV'] as $tN => $tLbl)
                                            @php
                                                $tTgt = $ct ? $ct->alokasiKumulatif($tN) : null;
                                                $tReal = $ct ? $ct->realisasiKumulatif($tN) : null;
                                                $tCap = $ct ? $ct->capaianTriwulanan($tN) : null;
                                                $isTwSelected = $filterTriwulan === (string)$tN;
                                            @endphp
                                            <div class="lakin-col-metric {{ $isTwSelected ? 'highlight' : '' }}">
                                                <div class="lakin-col-metric-lbl">
                                                    <span>{{ $tLbl }}</span>
                                                    @if ($tCap !== null)
                                                        <span class="badge {{ $badgeCapaian($tCap) }}" style="font-size:9.5px;padding:1px 5px">
                                                            {{ $formatPersen($tCap) }}
                                                        </span>
                                                    @else
                                                        <span style="font-size:10px;color:var(--muted)">—</span>
                                                    @endif
                                                </div>
                                                <div class="lakin-col-metric-val">
                                                    {{ $formatAngka($tReal) }}
                                                </div>
                                                <div class="lakin-col-metric-sub">
                                                    Target: {{ $formatAngka($tTgt) }}
                                                </div>
                                            </div>
                                        @endforeach

                                        {{-- Kolom ke-5: Total Kumulatif Setahun --}}
                                        <div class="lakin-col-metric {{ $filterTriwulan === 'semua' ? 'highlight' : '' }}">
                                            <div class="lakin-col-metric-lbl">
                                                <span>🗓️ SETAHUN</span>
                                                <span class="badge {{ $badgeCapaian($baris->capaian_persen) }}" style="font-size:9.5px;padding:1px 5px">
                                                    {{ $formatPersen($baris->capaian_persen) }}
                                                </span>
                                            </div>
                                            <div class="lakin-col-metric-val" style="color:var(--blue-600)">
                                                {{ $formatAngka($baris->realisasi) }}
                                            </div>
                                            <div class="lakin-col-metric-sub">
                                                Target: {{ $formatAngka($baris->target) }}
                                            </div>
                                        </div>
                                    </div>

                                    {{-- 3 Kolom Rincian Konten: Kegiatan, Solusi & Kendala, RTL --}}
                                    <div class="lakin-card-3col">
                                        {{-- Kolom 1: Kegiatan --}}
                                        <div class="lakin-card-sec">
                                            <div class="lakin-card-sec-head">
                                                <span>📝 Kegiatan Kinerja</span>
                                                @if ($twAktif)
                                                    <span class="lakin-section-badge {{ $twColorClass('Triwulan '.$twAktif) }}">TW {{ ['I','II','III','IV'][$twAktif - 1] }}</span>
                                                @endif
                                            </div>
                                            <div class="lakin-text-cell" style="max-height:260px">
                                                {!! $renderKegiatanHtml($parseBlocks($baris->kegiatan, $twAktif)) !!}
                                            </div>
                                        </div>

                                        {{-- Kolom 2: Solusi & Kendala --}}
                                        <div class="lakin-card-sec">
                                            <div class="lakin-card-sec-head">
                                                <span>💡 Kendala &amp; Solusi</span>
                                                @if ($twAktif)
                                                    <span class="lakin-section-badge {{ $twColorClass('Triwulan '.$twAktif) }}">TW {{ ['I','II','III','IV'][$twAktif - 1] }}</span>
                                                @endif
                                            </div>
                                            <div class="lakin-text-cell" style="max-height:260px">
                                                {!! $renderSolusiKendalaHtml($parseBlocks($baris->solusi_kendala, $twAktif)) !!}
                                            </div>
                                        </div>

                                        {{-- Kolom 3: RTL --}}
                                        <div class="lakin-card-sec">
                                            <div class="lakin-card-sec-head">
                                                <span>🔄 Rencana Tindak Lanjut</span>
                                                @if ($twAktif)
                                                    <span class="lakin-section-badge {{ $twColorClass('Triwulan '.$twAktif) }}">TW {{ ['I','II','III','IV'][$twAktif - 1] }}</span>
                                                @endif
                                            </div>
                                            <div class="lakin-text-cell" style="max-height:260px">
                                                {!! $renderRtlHtml($parseBlocks($baris->rtl, $twAktif)) !!}
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Footer Kartu: PIC, Batas Waktu, & Folder Bukti Dukung --}}
                                    <div class="lakin-card-foot">
                                        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
                                            <div style="font-size:12px;color:var(--ink)">
                                                <span style="font-weight:700">👤 PIC:</span>
                                                <span>{{ $baris->pic ?: '—' }}</span>
                                            </div>
                                            <div style="font-size:12px;color:var(--muted)">
                                                <span style="font-weight:700">📅 Batas Waktu:</span>
                                                <span>{{ $baris->batas_waktu_tindak_lanjut?->format('d/m/Y') ?: '—' }}</span>
                                            </div>

                                            {{-- Google Drive Folder Bukti & RTL --}}
                                            @if ($isFolderBukti)
                                                <a href="{{ $baris->link_bukti_dukung_kinerja }}" target="_blank" rel="noopener" class="lakin-drive-pill bukti" title="Buka Folder Bukti Dukung di Google Drive">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                                                    <span>Folder Bukti Drive</span>
                                                    <span class="ext">↗</span>
                                                </a>
                                            @endif
                                            @if ($isFolderRtl)
                                                <a href="{{ $baris->link_rtl_sebelumnya }}" target="_blank" rel="noopener" class="lakin-drive-pill rtl" title="Buka Folder RTL Sebelumnya di Google Drive">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                                                    <span>Folder RTL Drive</span>
                                                    <span class="ext">↗</span>
                                                </a>
                                            @endif
                                            @if (!$isFolderBukti && !$isFolderRtl)
                                                <span class="lakin-text-empty">Folder bukti belum terhubung di sistem</span>
                                            @endif
                                        </div>

                                        @if ($this->isTimSakip())
                                            <div class="btn-row" style="margin:0;gap:8px">
                                                <button type="button" class="btn btn-ghost btn-sm" style="color:var(--red)" title="Hapus baris ini dari rekap" wire:click="confirmHapusBaris({{ $baris->id }})">
                                                    🗑 Hapus
                                                </button>
                                                <button type="button" class="btn btn-primary btn-sm" wire:click="bukaEditBaris({{ $baris->id }})">
                                                    ✏️ Sunting
                                                </button>
                                            </div>
                                        @endif
                                    </div>
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
                    <label>Link Folder Bukti Dukung (opsional)</label>
                    <input type="url" class="inp filled" placeholder="https://drive.google.com/drive/folders/…" wire:model="linkBuktiDukungKinerjaBaru">
                </div>

                <div class="field">
                    <label>Link Folder RTL Sebelumnya (opsional)</label>
                    <input type="url" class="inp filled" placeholder="https://drive.google.com/drive/folders/…" wire:model="linkRtlSebelumnyaBaru">
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
                            <label>Uraian Kegiatan (per Triwulan)</label>
                            <textarea class="inp filled" rows="4" placeholder="[Triwulan II]&#10;1. Kegiatan A&#10;&#10;[Triwulan III]&#10;1. Kegiatan B" wire:model="edit.{{ $editingBarisId }}.kegiatan"></textarea>
                        </div>

                        <div class="field" style="grid-column: 1 / -1">
                            <label>Kendala &amp; Solusi (per Triwulan)</label>
                            <textarea class="inp filled" rows="4" placeholder="[Triwulan II]&#10;1. Kendala: ...&#10;   Solusi: ..." wire:model="edit.{{ $editingBarisId }}.solusi_kendala"></textarea>
                        </div>

                        <div class="field" style="grid-column: 1 / -1">
                            <label>RTL (Rencana Tindak Lanjut per Triwulan)</label>
                            <textarea class="inp filled" rows="4" placeholder="[Triwulan III]&#10;1. Rencana A&#10;&#10;[Triwulan IV]&#10;1. Rencana B" wire:model="edit.{{ $editingBarisId }}.rtl"></textarea>
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
                            <label>Link Folder Bukti Dukung (Google Drive)</label>
                            <input type="url" class="inp filled" placeholder="https://drive.google.com/drive/folders/…" wire:model="edit.{{ $editingBarisId }}.link_bukti_dukung_kinerja">
                        </div>

                        <div class="field">
                            <label>Link Folder RTL Sebelumnya (Google Drive)</label>
                            <input type="url" class="inp filled" placeholder="https://drive.google.com/drive/folders/…" wire:model="edit.{{ $editingBarisId }}.link_rtl_sebelumnya">
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
        message="Segarkan angka capaian dan rincian tindak lanjut per triwulan (kegiatan, kendala &amp; solusi, RTL, PIC, tautan folder bukti) dari data capaian &amp; verifikasi terbaru? Baris custom tidak akan berubah.">
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