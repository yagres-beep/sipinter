@extends('legal.layout')

@section('title', 'Ketentuan Layanan — SIPINTER')
@section('judul', 'Ketentuan Layanan')

@section('isi')
    <p>Dengan menggunakan SIPINTER (Sistem Informasi Pelaporan Kinerja Terpadu), Anda menyetujui ketentuan berikut.</p>

    <h2>1. Pengguna</h2>
    <p>SIPINTER adalah aplikasi internal BPS Kabupaten Buton Utara dan hanya diperuntukkan bagi pegawai yang akunnya sudah diverifikasi oleh Tim SAKIP.</p>

    <h2>2. Kewajiban pengguna</h2>
    <ul>
        <li>Menjaga kerahasiaan username dan kata sandi, serta tidak meminjamkan akun kepada orang lain.</li>
        <li>Mengisi capaian kinerja dan mengunggah bukti dukung yang benar dan dapat dipertanggungjawabkan.</li>
        <li>Tidak mengunggah berkas yang berisi virus, konten yang melanggar hukum, atau data yang tidak berkaitan dengan pelaporan kinerja.</li>
    </ul>

    <h2>3. Akun Google storage</h2>
    <p>Akun Gmail institusi yang dihubungkan ke SIPINTER dipakai sebagai tempat arsip bukti dukung sesuai <a href="{{ route('kebijakan-privasi') }}">Kebijakan Privasi</a>. Tim SAKIP bertanggung jawab atas akun yang dihubungkannya dan dapat mencabut aksesnya kapan saja.</p>

    <h2>4. Ketersediaan layanan</h2>
    <p>SIPINTER diupayakan selalu tersedia, tetapi dapat terhenti sementara untuk pemeliharaan atau karena gangguan layanan pihak ketiga (misalnya Google Drive). Pengguna disarankan memeriksa notifikasi hasil unggah dan mengunggah ulang berkas yang dinyatakan gagal.</p>

    <h2>5. Perubahan ketentuan</h2>
    <p>Ketentuan ini dapat diperbarui sewaktu-waktu. Versi terbaru selalu tersedia di halaman ini.</p>

    <h2>6. Kontak</h2>
    <p>Tim SAKIP BPS Kabupaten Buton Utara — <a href="mailto:7409sakip@gmail.com">7409sakip@gmail.com</a>.</p>
@endsection
