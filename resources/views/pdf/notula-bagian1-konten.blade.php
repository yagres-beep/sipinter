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
            $rtlSebelumnyaIku = $rtlSebelumnyaPerIku->get($iku->id, collect());
        @endphp
        <div class="nrow" style="margin-top:16px"><span class="nlabel">{{ $iku->kode }} — {{ $iku->indikator }}</span></div>

        {{-- Analisis Capaian Kinerja: satu kotak berjudul, isinya teks bebas yang
             ditulis Tim SAKIP (boleh berupa beberapa paragraf/daftar bernomor sesuai
             Template Notula resmi) — nl2br supaya baris baru yang diketik tetap
             tampil sebagai baris baru, bukan menyatu jadi satu baris panjang. --}}
        <table style="width:100%">
            <tr><th style="text-align:center">Analisis Capaian Kinerja</th></tr>
            <tr><td>{!! nl2br(e($capaian?->analisis_capaian ?: '…')) !!}</td></tr>
        </table>

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
                        <td>{{ $kegiatan->rincian_output ?: $kegiatan->uraian_kegiatan }}</td>
                        <td>{{ $kegiatan->volume_ro ?: '…' }}</td>
                        <td>{{ $fmtProgres($kegiatan->progres_persen) }}</td>
                    </tr>
                @empty
                    <tr><td>…</td><td>…</td><td>…</td></tr>
                @endforelse
            </table>
        @endif

        @php
            // Kumulatif TW1..TW berjalan (lihat NotulaService — RF-28), tapi dicetak
            // sebagai SATU daftar bernomor mengalir tanpa label "TW X:" per Template
            // Notula resmi — satu baris kendala/solusi = satu KendalaSolusi tersimpan.
            $semuaKendalaSolusi = $kendalaSolusiTriwulan->flatten(1);
            $daftarKendala = $semuaKendalaSolusi->pluck('kendala')->filter();
            $daftarSolusi = $semuaKendalaSolusi->pluck('solusi')->filter();
        @endphp
        <div class="nrow">
            <span class="nlabel">Kendala :</span>
            @if ($daftarKendala->isEmpty())
                <span class="ntxt">…</span>
            @else
                <ol style="margin:4px 0 0 18px;padding:0">
                    @foreach ($daftarKendala as $teks)
                        <li>{!! nl2br(e($teks)) !!}</li>
                    @endforeach
                </ol>
            @endif
        </div>
        <div class="nrow">
            <span class="nlabel">Solusi :</span>
            @if ($daftarSolusi->isEmpty())
                <span class="ntxt">…</span>
            @else
                <ol style="margin:4px 0 0 18px;padding:0">
                    @foreach ($daftarSolusi as $teks)
                        <li>{!! nl2br(e($teks)) !!}</li>
                    @endforeach
                </ol>
            @endif
        </div>

        @php
            // PIC & Batas Waktu dicetak SEKALI untuk seluruh daftar RTL indikator ini
            // (bukan diulang per baris) — nama unik digabung, batas waktu memakai yang
            // PALING JAUH (paling longgar) di antara seluruh poin RTL triwulan ini.
            $picRtl = $rtlIku->pluck('pic')->filter()->unique()->implode(', ');
            $batasWaktuRtl = $rtlIku->pluck('batas_waktu')->filter()->sort()->last();
        @endphp
        <table style="width:100%">
            <tr><th style="width:60%">Rencana Tindak Lanjut (RTL)</th><th>PIC Tindak Lanjut</th></tr>
            <tr>
                <td>
                    @if ($rtlIku->isEmpty())
                        …
                    @else
                        <ol style="margin:0 0 0 18px;padding:0">
                            @foreach ($rtlIku as $rtl)
                                <li>{!! nl2br(e($rtl->rtl_teks)) !!}</li>
                            @endforeach
                        </ol>
                    @endif
                </td>
                <td>
                    <b>PIC Tindak Lanjut :</b><br>{{ $picRtl ?: '…' }}
                    <br><br>
                    <b>Batas Waktu Tindak Lanjut :</b><br>{{ $batasWaktuRtl?->translatedFormat('F Y') ?: '…' }}
                </td>
            </tr>
        </table>

        <table style="width:100%">
            <tr><th colspan="2" style="text-align:center">Dasar Hitung/Bukti Dukung/Lainnya</th></tr>
            <tr>
                <td colspan="2">
                    <div class="nrow">
                        <span class="nlabel">Dasar Hitung dan Basis Data Realisasi IKU :</span><br>
                        @if ($iku->dasar_hitung || $iku->basis_data)
                            @if ($iku->dasar_hitung)
                                {!! nl2br(\App\Support\RumusMarkup::keHtml($iku->dasar_hitung)) !!}
                            @endif
                            @if ($iku->dasar_hitung && $iku->basis_data)<br>@endif
                            @if ($iku->basis_data)Basis Data: {{ $iku->basis_data }}@endif
                        @else
                            …
                        @endif
                    </div>

                    @if ($notula->link_lampiran_basis_data)
                        <div class="nrow">
                            Lampiran Basis Data IKU dapat dilihat pada link
                            <a href="{{ $notula->link_lampiran_basis_data }}">{{ $notula->link_lampiran_basis_data }}</a>
                        </div>
                    @endif

                    <div class="nrow">
                        <span class="nlabel">Tautan Bukti Dukung Realisasi Target :</span><br>
                        @if ($linkFolder)
                            <a href="{{ $linkFolder }}">{{ $linkFolder }}</a>
                        @else
                            …
                        @endif
                    </div>
                    <div class="nrow">
                        <span class="nlabel">Tautan Bukti Dukung Tindak Lanjut Triwulan Sebelumnya :</span><br>
                        @if ($linkFolderTwSebelumnya)
                            <a href="{{ $linkFolderTwSebelumnya }}">{{ $linkFolderTwSebelumnya }}</a>
                        @else
                            …
                        @endif
                    </div>

                    @if ($rtlSebelumnyaIku->isNotEmpty())
                        <div class="nrow">
                            RTL triwulan {{ $romawi[$periode->triwulan - 1] ?? $periode->triwulan - 1 }} {{ $periode->tahun }} yang telah dilaksanakan
                            pada triwulan {{ $labelTriwulan }} {{ $periode->tahun }} yaitu :
                            <ol style="margin:4px 0 0 18px;padding:0">
                                @foreach ($rtlSebelumnyaIku as $rtl)
                                    <li>{!! nl2br(e($rtl->rtl_teks)) !!}</li>
                                @endforeach
                            </ol>
                        </div>
                    @endif

                    <div class="nrow">
                        <span class="nlabel">Penjelasan/pembahasan lainnya :</span><br>
                        {!! nl2br(e($capaian?->catatan ?: '-')) !!}
                    </div>
                </td>
            </tr>
        </table>
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
