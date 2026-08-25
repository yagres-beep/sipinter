{{--
    Konten OTOMATIS Bagian I (RF-42), disusun dari data capaian yang sudah
    terverifikasi mengikuti struktur resmi Template Notula Monitoring Kinerja
    Triwulanan BPS Provinsi/Kabupaten/Kota — dikelompokkan per Sasaran, satu tabel
    capaian + Analisis Capaian Kinerja + Kendala/Solusi (kumulatif) + Rencana Tindak
    Lanjut + Dasar Hitung/Bukti Dukung per indikator. Hasil render method ini
    disimpan ke Notula::bagian1_html agar Tim SAKIP bisa menyuntingnya (mengisi
    bagian yang masih placeholder "…") sebelum digabungkan — jadi berkas ini hanya
    dipakai SEKALI saat "Susun Ulang Otomatis" ditekan, bukan dirender ulang tiap
    kali PDF dibuat (yang dipakai saat itu adalah bagian1_html tersimpan).
--}}
@php
    $fmt = \App\Models\PengaturanCapaian::formatAngka(...);
    $fmtPersen = \App\Models\PengaturanCapaian::formatPersen(...);
    $romawi = ['', 'I', 'II', 'III', 'IV'];
    $fmtProgres = fn ($v) => $v !== null ? number_format((float) $v, 2, ',', '.').'%' : '…';
@endphp

<h3>NOTULA RAPAT</h3>
<div class="nsub">MONITORING KINERJA TRIWULAN {{ $labelTriwulan }} TAHUN {{ $periode->tahun }}</div>

<table style="width:100%">
    <tr><td style="width:22%"><b>Agenda Pembahasan</b></td><td>Monitoring Kinerja Triwulan {{ $labelTriwulan }} Tahun {{ $periode->tahun }}</td></tr>
    <tr><td><b>Hari/Tanggal</b></td><td>{{ $notula->hari_tanggal ?: '…' }}</td></tr>
    <tr><td><b>Waktu</b></td><td>{{ $notula->waktu ?: '…' }}</td></tr>
    <tr><td><b>Tempat</b></td><td>{{ $notula->tempat ?: '…' }}</td></tr>
    <tr><td><b>Pimpinan Rapat</b></td><td>{{ $notula->pimpinan_rapat ?: '…' }}</td></tr>
</table>

<h3>I. Capaian Kinerja Triwulan {{ $labelTriwulan }} Tahun {{ $periode->tahun }}</h3>
<p>
    Capaian Kinerja IKU triwulan {{ $labelTriwulan }} tahun {{ $periode->tahun }} terhadap target triwulanan sebesar
    <b>{{ $rataCapaianTw !== null ? $rataCapaianTw.' persen' : '… persen' }}</b>. Sedangkan terhadap target tahun {{ $periode->tahun }} (target PK {{ $periode->tahun }}), capaian
    kinerjanya sebesar <b>{{ $rataCapaianPk !== null ? $rataCapaianPk.' persen' : '… persen' }}</b>. Adapun penjelasan detail capaian untuk setiap indikator kinerja disampaikan di
    bawah ini.
</p>

@forelse ($sasaranPerIku as $sasaran => $daftarIku)
    <table style="width:100%">
        <tr><th colspan="6" style="text-align:left">Sasaran: {{ $sasaran }}</th></tr>
        <tr>
            <th rowspan="2">No.</th>
            <th rowspan="2">Indikator Kinerja</th>
            <th rowspan="2">Target PK {{ $periode->tahun }}</th>
            <th colspan="3">Triwulan {{ $labelTriwulan }}</th>
            <th rowspan="2">Capaian Thd Target PK</th>
        </tr>
        <tr>
            <th>Target</th>
            <th>Realisasi</th>
            <th>Capaian Thd Target Triwulanan</th>
        </tr>
        @foreach ($daftarIku as $iku)
            @php $rekap = $rekapPerIku->get($iku->id, []); @endphp
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $iku->indikator }}</td>
                <td>{{ $fmt($rekap['target_pk'] ?? null) }}</td>
                <td>{{ $fmt($rekap['target_tw'] ?? null) }}</td>
                <td>{{ $fmt($rekap['realisasi'] ?? null) }}</td>
                <td>{{ $fmtPersen($rekap['capaian_tw'] ?? null) }}</td>
                <td>{{ $fmtPersen($rekap['capaian_pk'] ?? null) }}</td>
            </tr>
        @endforeach
    </table>

    @foreach ($daftarIku as $iku)
        @php
            $capaian = $capaianPerIku->get($iku->id);
            $rekap = $rekapPerIku->get($iku->id, []);
            $kegiatanIku = $kegiatanPerIku->get($iku->id, collect());
            $kendalaSolusiTriwulan = $kendalaSolusiPerIku->get($iku->id, collect());
            $rtlIku = $rtlPerIku->get($iku->id, collect());
            $linkFolder = $linkFolderPerIku[$iku->id] ?? null;
            $linkFolderTwSebelumnya = $linkFolderTwSebelumnyaPerIku[$iku->id] ?? null;

            // Sesuai Template Notula Monitoring Kinerja Triwulanan BPS resmi: dua
            // indikator tetap "Nilai SAKIP oleh Inspektorat" dan "Nilai/Indeks
            // BerAKHLAK" (sasaran Dukungan Manajemen) TIDAK memakai tabel Rincian
            // Output biasa — SAKIP memakai tabel "Indikator Proksi" (Target/Realisasi
            // s.d. triwulan berjalan), BerAKHLAK tanpa tabel sama sekali, keduanya
            // dengan pertanyaan analisis berbeda dari indikator lain. Dideteksi dari
            // teks indikator (bukan kode, karena penomoran kode IKU berbeda tiap
            // instansi) — kedua nama ini baku secara nasional di seluruh BPS.
            $indikatorLower = mb_strtolower($iku->indikator);
            $isSakip = str_contains($indikatorLower, 'sakip');
            $isBerakhlak = str_contains($indikatorLower, 'berakhlak');
        @endphp
        <div class="nrow" style="margin-top:16px"><span class="nlabel">{{ $iku->kode }} — {{ $iku->indikator }}</span></div>

        <div class="nrow">
            <span class="nlabel">Analisis Capaian Kinerja:</span>
            <span class="ntxt">{{ $capaian?->analisis_capaian ?: '…' }}</span>
        </div>

        @if ($isSakip)
            <p><b>Jelaskan mengenai persentase monitoring capaian kinerja triwulanan yang terlaksana tepat waktu:</b></p>
            <table style="width:100%">
                <tr><th>Indikator Proksi</th><th>Target s.d Triwulan {{ $labelTriwulan }}</th><th>Realisasi s.d Triwulan {{ $labelTriwulan }}</th></tr>
                <tr>
                    <td>Persentase monitoring capaian kinerja triwulanan yang terlaksana tepat waktu</td>
                    <td>…</td>
                    <td>…</td>
                </tr>
            </table>
        @elseif ($isBerakhlak)
            <p><b>Jelaskan mengenai Persentase kegiatan untuk mengoptimalkan implementasi BerAKHLAK yang terlaksana sesuai rencana:</b> …</p>
        @elseif (empty($rekap['realisasi']))
            <p><b>Realisasi Volume RO dan Progress Pelaksanaan Kegiatan sampai dengan Triwulan Berjalan</b>
                <em>(hanya terisi jika belum ada realisasi IKU pada triwulan berjalan — hapus tabel ini bila realisasi sudah ada)</em>:</p>
            <table style="width:100%">
                <tr><th>Rincian Output</th><th>Realisasi Volume RO</th><th>Progres Pelaksanaan Kegiatan (%)</th></tr>
                @forelse ($kegiatanIku as $kegiatan)
                    <tr>
                        <td>{{ $kegiatan->uraian_kegiatan }}</td>
                        <td>{{ $kegiatan->volume_ro ?: '…' }}</td>
                        <td>{{ $fmtProgres($kegiatan->progres_persen) }}</td>
                    </tr>
                @empty
                    <tr><td>…</td><td>…</td><td>…</td></tr>
                @endforelse
            </table>
        @endif

        <div class="nrow">
            <span class="nlabel">Kendala:</span>
            <span class="ntxt">
                @forelse ($kendalaSolusiTriwulan as $twKe => $daftarKendala)
                    <b>TW {{ $romawi[$twKe] ?? $twKe }}:</b>
                    {{ $daftarKendala->pluck('kendala')->filter()->implode('; ') ?: '…' }}<br>
                @empty
                    …
                @endforelse
            </span>
        </div>
        <div class="nrow">
            <span class="nlabel">Solusi:</span>
            <span class="ntxt">
                @forelse ($kendalaSolusiTriwulan as $twKe => $daftarKendala)
                    <b>TW {{ $romawi[$twKe] ?? $twKe }}:</b>
                    {{ $daftarKendala->pluck('solusi')->filter()->implode('; ') ?: '…' }}<br>
                @empty
                    …
                @endforelse
            </span>
        </div>

        <table style="width:100%">
            <tr><th style="width:60%">Rencana Tindak Lanjut (RTL)</th><th>PIC Tindak Lanjut / Batas Waktu</th></tr>
            @forelse ($rtlIku as $rtl)
                <tr>
                    <td>{{ $rtl->rtl_teks }}</td>
                    <td>
                        PIC: {{ $rtl->pic ?: '…' }}<br>
                        Batas Waktu: {{ $rtl->batas_waktu?->translatedFormat('d F Y') ?: '…' }}
                    </td>
                </tr>
            @empty
                <tr><td>…</td><td>PIC: …<br>Batas Waktu: …</td></tr>
            @endforelse
        </table>

        <div class="nrow">
            <span class="nlabel">Dasar Hitung dan Basis Data Realisasi IKU:</span>
            <span class="ntxt">
                @if ($iku->dasar_hitung || $iku->basis_data)
                    @if ($iku->dasar_hitung)Dasar Hitung: {{ $iku->dasar_hitung }}@endif
                    @if ($iku->dasar_hitung && $iku->basis_data)<br>@endif
                    @if ($iku->basis_data)Basis Data: {{ $iku->basis_data }}@endif
                @else
                    …
                @endif
            </span>
        </div>
        <div class="nrow">
            <span class="nlabel">Tautan Bukti Dukung Realisasi Target:</span>
            <span class="ntxt">
                @if ($linkFolder)
                    <a href="{{ $linkFolder }}">{{ $linkFolder }}</a>
                @else
                    …
                @endif
            </span>
        </div>
        <div class="nrow">
            <span class="nlabel">Tautan Bukti Dukung Tindak Lanjut Triwulan Sebelumnya:</span>
            <span class="ntxt">
                @if ($linkFolderTwSebelumnya)
                    <a href="{{ $linkFolderTwSebelumnya }}">{{ $linkFolderTwSebelumnya }}</a>
                @else
                    …
                @endif
            </span>
        </div>
        <div class="nrow"><span class="nlabel">Penjelasan/pembahasan lainnya:</span> <span class="ntxt">{{ $capaian?->catatan ?: '…' }}</span></div>
    @endforeach
@empty
    <p><em>Belum ada Master IKU dengan Sasaran terisi. Lengkapi kolom Sasaran di halaman Master IKU terlebih dahulu.</em></p>
@endforelse

@foreach ($bagianKustomPerBagian as $bagian)
    <h3>{{ $bagian->nama }}</h3>
    <ul>
        @foreach ($bagian->poin as $poin)
            <li>{{ $poin->masterIku->kode }}: {{ $poin->teks }}</li>
        @endforeach
    </ul>
@endforeach
