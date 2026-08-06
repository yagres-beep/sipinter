<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: sans-serif; font-size: 11px; color: #0f172a; }
  h1 { font-size: 16px; margin-bottom: 2px; }
  h3 { font-size: 12px; margin-top: 16px; margin-bottom: 6px; }
  .sub { color: #64748b; margin-bottom: 18px; font-size: 11px; }
  ul { margin: 0 0 8px 18px; padding: 0; }
  .ttd { margin-top: 50px; text-align: right; font-size: 11px; }
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
