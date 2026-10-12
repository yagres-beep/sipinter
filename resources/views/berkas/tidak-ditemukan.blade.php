<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Berkas tidak dapat dibuka</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f8fafc; color: #0f172a; }
        .box { max-width: 520px; margin: 48px auto; padding: 0 16px; text-align: center; }
        h1 { font-size: 17px; margin: 12px 0 8px; }
        p { font-size: 13px; line-height: 1.6; color: #475569; margin: 0 0 10px; }
        code { font-size: 12px; background: #e2e8f0; padding: 2px 6px; border-radius: 6px; }
    </style>
</head>
<body>
    <div class="box">
        <div style="font-size:34px">📄❌</div>
        <h1>Berkas “{{ $berkas->nama_file }}” tidak dapat dibuka</h1>
        <p>{{ $sebab }}</p>
        <p>Kode berkas: <code>{{ $berkas->kode }}</code></p>
    </div>
</body>
</html>
