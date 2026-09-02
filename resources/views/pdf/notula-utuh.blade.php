<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@php
  // dompdf tidak punya Calibri/Cambria (font tema default template .docx) di
  // 5 font bawaannya -- tanpa @font-face ini dompdf diam-diam menggantinya
  // dengan Helvetica, bikin ukuran teks "beleset" dari template asli. Carlito
  // & Caladea adalah pengganti metric-compatible (lebar huruf identik) untuk
  // Calibri & Cambria, dipasang di resources/fonts/.
  $fontDir = 'file://'.str_replace('\\', '/', base_path('resources/fonts'));
@endphp
<style>
  @page { margin: 22px 26px; }

  @font-face { font-family: 'Calibri'; font-weight: normal; font-style: normal; src: url('{{ $fontDir }}/Carlito-Regular.ttf'); }
  @font-face { font-family: 'Calibri'; font-weight: bold; font-style: normal; src: url('{{ $fontDir }}/Carlito-Bold.ttf'); }
  @font-face { font-family: 'Calibri'; font-weight: normal; font-style: italic; src: url('{{ $fontDir }}/Carlito-Italic.ttf'); }
  @font-face { font-family: 'Calibri'; font-weight: bold; font-style: italic; src: url('{{ $fontDir }}/Carlito-BoldItalic.ttf'); }
  @font-face { font-family: 'Cambria'; font-weight: normal; font-style: normal; src: url('{{ $fontDir }}/Caladea-Regular.ttf'); }
  @font-face { font-family: 'Cambria'; font-weight: bold; font-style: normal; src: url('{{ $fontDir }}/Caladea-Bold.ttf'); }
  @font-face { font-family: 'Cambria'; font-weight: normal; font-style: italic; src: url('{{ $fontDir }}/Caladea-Italic.ttf'); }
  @font-face { font-family: 'Cambria'; font-weight: bold; font-style: italic; src: url('{{ $fontDir }}/Caladea-BoldItalic.ttf'); }

  body { font-family: 'Calibri', sans-serif; font-size: 11pt; line-height: 1.5; color: #0f172a; }
  h1, h3 { font-family: 'Cambria', serif; }
  h1 { font-size: 16px; margin-bottom: 2px; }
  h3 { font-size: 13px; margin-top: 16px; margin-bottom: 4px; text-align: center; }
  .sub { color: #64748b; margin-bottom: 14px; font-size: 11px; text-align: center; }
  .nsub { color: #64748b; margin-bottom: 14px; font-size: 11px; text-align: center; }
  ul { margin: 0 0 8px 18px; padding: 0; }
  .nrow { margin-bottom: 8px; }
  .nlabel { font-weight: bold; }
  table { width: 100%; border-collapse: collapse; margin: 8px 0; }
  td, th { border: 1px solid #999; padding: 5px 6px; text-align: left; }
  th { background: #f2f2f2; }

  {{-- Blok TTD SELALU di paling akhir (setelah Bagian III) — lihat NotulaService.
       page-break-inside:avoid HANYA di sini supaya tidak terpotong dua halaman,
       tapi tetap boleh menyambung di halaman yang sama bila muat. --}}
  .ttd-blok { margin-top: 40px; page-break-inside: avoid; }
  .ttd-kolom { width: 46%; }
  .ttd-kolom.kiri { float: left; text-align: left; }
  .ttd-kolom.kanan { float: right; text-align: center; }
  .ttd-spasi { height: 46px; }
</style>
</head>
<body>
  {{-- TANPA judul sampul tambahan di sini -- $bagian1Html SUDAH memuat judul
       resminya sendiri ("NOTULA RAPAT" dst.) persis seperti berkas .docx template
       yang diunggah Tim SAKIP, jadi menambahkan judul lain di sini cuma bikin PDF
       berbeda dari template aslinya. --}}
  {{-- Bagian II/III SUDAH tersisip di posisi penanda {{bagian_2}}/{{bagian_3}} di
       dalam $bagian1Html, judulnya pun sudah ikut terbawa dari berkas .docx yang
       diunggah Tim SAKIP -- lihat NotulaService::sisipkanBagianDuaTiga(). --}}
  {!! $bagian1Html !!}

  @if ($sertakanTtd)
    <div class="ttd-blok">
      <div class="ttd-kolom kiri">
        Mengetahui,<br>
        Kepala Badan Pusat Statistik Kabupaten Buton Utara
        <div class="ttd-spasi"></div>
        <b>{{ $namaKepala ?: '.......................................' }}</b>
      </div>
      <div class="ttd-kolom kanan">
        {{ trim(($kotaTtd ?: '').($kotaTtd && $tanggal ? ', ' : '').($tanggal ?: '')) }}
        <div class="ttd-spasi"></div>
        Notulis,
        <div class="ttd-spasi"></div>
        <b>{{ $namaNotulis ?: '.......................................' }}</b>
      </div>
      <div style="clear:both"></div>
    </div>
  @endif
</body>
</html>
