<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { margin: 22px 26px; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; line-height: 1.5; color: #0f172a; }
  h1 { font-size: 16px; margin-bottom: 2px; }
  h3 { font-size: 13px; margin-top: 16px; margin-bottom: 4px; text-align: center; }
  .sub { color: #64748b; margin-bottom: 14px; font-size: 11px; text-align: center; }
  ul { margin: 0 0 8px 18px; padding: 0; }
  .nrow { margin-bottom: 8px; }
  .nlabel { font-weight: bold; }
  table { width: 100%; border-collapse: collapse; margin: 8px 0; }
  td, th { border: 1px solid #999; padding: 5px 6px; text-align: left; }
  th { background: #f2f2f2; }

  {{-- Judul antar-bagian: pemisah RINGAN (garis tipis + jarak), BUKAN page-break —
       Bagian II/III harus bisa menyambung di sisa ruang halaman sebelumnya. --}}
  .bagian-judul { font-size: 13px; font-weight: bold; margin: 18px 0 6px; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; }

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
  <h1>NOTULA MONITORING KINERJA</h1>
  <div class="sub">Triwulan {{ $labelTriwulan }} {{ $tahun }} — BPS Kabupaten Buton Utara</div>

  {!! $bagian1Html !!}

  @if (trim((string) $bagian2Html) !== '')
    <div class="bagian-judul">BAGIAN II — Peran BPS dalam Prioritas Nasional &amp; Isu Strategis</div>
    {!! $bagian2Html !!}
  @endif

  @if (trim((string) $bagian3Html) !== '')
    <div class="bagian-judul">BAGIAN III — Realisasi Anggaran &amp; Upaya Efisiensi</div>
    {!! $bagian3Html !!}
  @endif

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
