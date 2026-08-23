{{--
    Konten OTOMATIS Bagian I (RF-42), disusun dari data capaian yang sudah
    terverifikasi. Hasil render method ini disimpan ke Notula::bagian1_html
    agar Tim SAKIP bisa menyuntingnya (RF-42) sebelum digabungkan — jadi berkas
    ini hanya dipakai SEKALI saat "Susun Ulang Otomatis" ditekan, bukan dirender
    ulang tiap kali PDF dibuat (yang dipakai saat itu adalah bagian1_html tersimpan).
--}}
@forelse ($kegiatanPerIku as $ikuId => $daftarKegiatan)
    @php $iku = $daftarKegiatan->first()->masterIku; $linkFolder = $linkFolderPerIku[$ikuId] ?? null; @endphp
    <h3>{{ $iku->kode }} — {{ $iku->indikator }}</h3>
    @if ($linkFolder)
        <p><em><a href="{{ $linkFolder }}">📁 Buka folder bukti dukung {{ $iku->kode }} di Drive</a></em></p>
    @endif
    @foreach ($daftarKegiatan as $kegiatan)
        @php $capaian = $kegiatan->capaian(); @endphp
        <p>
            <b>{{ $kegiatan->uraian_kegiatan }}</b><br>
            {{ $capaian?->analisis_capaian ?: 'Analisis capaian belum diisi Tim SAKIP.' }}<br>
            Target PK: {{ \App\Models\PengaturanCapaian::formatAngka($capaian?->target_pk) }} ·
            Target TW: {{ \App\Models\PengaturanCapaian::formatAngka($capaian?->target_tw) }} ·
            Realisasi: {{ \App\Models\PengaturanCapaian::formatAngka($capaian?->realisasi) }} ·
            Capaian: {{ \App\Models\PengaturanCapaian::formatPersen($capaian?->persentase_capaian) }}
        </p>
    @endforeach
@empty
    <p><em>Belum ada kegiatan terverifikasi pada triwulan ini.</em></p>
@endforelse

<h3>Kendala &amp; Solusi (Kumulatif)</h3>
@forelse ($kendalaSolusiPerTriwulan as $triwulanKe => $daftar)
    <p><b>Triwulan {{ ['I', 'II', 'III', 'IV'][$triwulanKe - 1] }}:</b></p>
    <ul>
        @foreach ($daftar as $item)
            <li>
                {{ $item->masterIku->kode }}: {{ $item->kendala }}
                @if ($item->solusi)
                    → {{ $item->solusi }}
                @endif
            </li>
        @endforeach
    </ul>
@empty
    <p><em>Belum ada kendala &amp; solusi tercatat.</em></p>
@endforelse

<h3>Rencana Tindak Lanjut</h3>
<ul>
    @forelse ($rtlBerjalan as $rtl)
        <li>{{ $rtl->masterIku->kode }}: {{ $rtl->rtl_teks }} ({{ $rtl->berlaku_bulan }}, PIC: {{ $rtl->pic }})</li>
    @empty
        <li><em>Belum ada RTL ditetapkan untuk triwulan ini.</em></li>
    @endforelse
</ul>

@foreach ($bagianKustomPerBagian as $bagian)
    <h3>{{ $bagian->nama }}</h3>
    <ul>
        @foreach ($bagian->poin as $poin)
            <li>{{ $poin->masterIku->kode }}: {{ $poin->teks }}</li>
        @endforeach
    </ul>
@endforeach
