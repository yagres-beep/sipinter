<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: 'Times New Roman', Times, serif; font-size: 11px; color: #0f172a; }
  h1 { font-size: 16px; margin-bottom: 2px; }
  h3 { font-size: 13px; margin-top: 16px; margin-bottom: 4px; text-align: center; }
  .sub, .nsub { color: #64748b; margin-bottom: 14px; font-size: 11px; text-align: center; }
  ul { margin: 0 0 8px 18px; padding: 0; }
  .ttd { margin-top: 50px; text-align: right; font-size: 11px; }
  .nrow { margin-bottom: 8px; }
  .nlabel { font-weight: bold; }
  table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 10.5px; }
  td, th { border: 1px solid #999; padding: 5px 6px; text-align: left; }
  th { background: #f2f2f2; }
</style>
</head>
<body>
  <h1>BAGIAN I — CAPAIAN KINERJA</h1>
  <div class="sub">Notula Triwulan {{ $labelTriwulan }} {{ $tahun }} — BPS Kabupaten Buton Utara</div>

  {!! $kontenHtml !!}

  @if ($sertakanTtd)
    <div class="ttd">
      ttd<br>
      <b>{{ $namaKepala }}</b><br>
      {{ $tanggal }}
    </div>
  @endif
</body>
</html>
