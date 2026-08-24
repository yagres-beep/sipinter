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
@endphp

<h3>NOTULA RAPAT</h3>
<div class="nsub">MONITORING KINERJA TRIWULAN {{ $labelTriwulan }} TAHUN {{ $periode->tahun }}</div>

<div class="nrow"><span class="nlabel">Agenda Pembahasan:</span> <span class="ntxt">Monitoring Kinerja Triwulan {{ $labelTriwulan }} Tahun {{ $periode->tahun }}</span></div>
<div class="nrow"><span class="nlabel">Hari/Tanggal:</span> <span class="ntxt">{{ $notula->hari_tanggal ?: '…' }}</span></div>
<div class="nrow"><span class="nlabel">Waktu:</span> <span class="ntxt">{{ $notula->waktu ?: '…' }}</span></div>
<div class="nrow"><span class="nlabel">Tempat:</span> <span class="ntxt">{{ $notula->tempat ?: '…' }}</span></div>
<div class="nrow"><span class="nlabel">Pimpinan Rapat:</span> <span class="ntxt">{{ $notula->pimpinan_rapat ?: '…' }}</span></div>

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
            <th>No.</th>
            <th>Indikator Kinerja</th>
            <th>Target PK {{ $periode->tahun }}</th>
            <th>Target TW {{ $labelTriwulan }}</th>
            <th>Realisasi TW {{ $labelTriwulan }}</th>
            <th>Capaian Thd Target TW</th>
            <th>Capaian Thd Target PK</th>
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
        @endphp
        <div class="nrow" style="margin-top:16px"><span class="nlabel">{{ $iku->kode }} — {{ $iku->indikator }}</span></div>

        <div class="nrow">
            <span class="nlabel">Analisis Capaian Kinerja:</span>
            <span class="ntxt">{{ $capaian?->analisis_capaian ?: '…' }}</span>
        </div>

        @if (empty($rekap['realisasi']))
            <p><b>Realisasi Volume RO dan Progress Pelaksanaan Kegiatan sampai dengan Triwulan Berjalan</b>
                <em>(hanya terisi jika belum ada realisasi IKU pada triwulan berjalan — hapus tabel ini bila realisasi sudah ada)</em>:</p>
            <table style="width:100%">
                <tr><th>Rincian Output</th><th>Realisasi Volume RO</th><th>Progres Pelaksanaan Kegiatan (%)</th></tr>
                @forelse ($kegiatanIku as $kegiatan)
                    <tr><td>{{ $kegiatan->uraian_kegiatan }}</td><td>…</td><td>…</td></tr>
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
            <tr><th>Rencana Tindak Lanjut (RTL)</th><th>PIC</th><th>Batas Waktu</th></tr>
            @forelse ($rtlIku as $rtl)
                <tr>
                    <td>{{ $rtl->rtl_teks }}</td>
                    <td>{{ $rtl->pic ?: '…' }}</td>
                    <td>{{ $rtl->batas_waktu?->translatedFormat('d F Y') ?: '…' }}</td>
                </tr>
            @empty
                <tr><td>…</td><td>…</td><td>…</td></tr>
            @endforelse
        </table>

        <div class="nrow"><span class="nlabel">Dasar Hitung dan Basis Data Realisasi IKU:</span> <span class="ntxt">…</span></div>
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
        <div class="nrow"><span class="nlabel">Tautan Bukti Dukung Tindak Lanjut Triwulan Sebelumnya:</span> <span class="ntxt">…</span></div>
        <div class="nrow"><span class="nlabel">Penjelasan/pembahasan lainnya:</span> <span class="ntxt">…</span></div>
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
