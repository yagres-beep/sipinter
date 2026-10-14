@extends('legal.layout')

@section('title', 'Kebijakan Privasi — SIPINTER')
@section('judul', 'Kebijakan Privasi')

@section('isi')
    <p>SIPINTER (Sistem Informasi Pelaporan Kinerja Terpadu) adalah aplikasi internal BPS Kabupaten Buton Utara untuk mencatat capaian kinerja SAKIP, mengelola bukti dukung, dan menyusun notula. Halaman ini menjelaskan data apa yang dikumpulkan SIPINTER, untuk apa, dan bagaimana data itu dijaga.</p>

    <h2>1. Data yang dikumpulkan</h2>
    <ul>
        <li><strong>Data akun pegawai:</strong> nama, username, email, nomor WhatsApp, peran, dan kata sandi (disimpan dalam bentuk terenkripsi/hash).</li>
        <li><strong>Isian kinerja:</strong> uraian kegiatan, kendala &amp; solusi, rencana tindak lanjut, catatan verifikasi, dan notula.</li>
        <li><strong>Berkas bukti dukung:</strong> dokumen PDF yang diunggah pegawai sebagai bukti capaian kinerja.</li>
        <li><strong>Data akun Google storage:</strong> alamat Gmail institusi dan token akses OAuth Google Drive milik akun penyimpanan yang dihubungkan oleh Tim SAKIP.</li>
    </ul>

    <h2>2. Penggunaan data Google</h2>
    <p>SIPINTER meminta akses Google Drive <strong>hanya</strong> pada akun Gmail institusi yang sengaja dihubungkan oleh Tim SAKIP sebagai tempat penyimpanan arsip. Akses itu dipakai semata-mata untuk:</p>
    <ul>
        <li>membuat dan menata folder arsip SIPINTER (per tahun, triwulan, IKU, dan kategori bukti);</li>
        <li>mengunggah, membuka, mengganti nama, dan menghapus berkas bukti dukung, notula, dan cadangan database milik SIPINTER di folder tersebut;</li>
        <li>membagikan folder arsip antar-akun penyimpanan SIPINTER saat akun penyimpanan baru ditambahkan.</li>
    </ul>
    <p>SIPINTER tidak membaca, mengubah, atau menghapus berkas lain di Google Drive di luar folder arsip SIPINTER. Data dari Google <strong>tidak</strong> dijual, <strong>tidak</strong> dipakai untuk iklan, dan <strong>tidak</strong> dibagikan kepada pihak ketiga mana pun.</p>
    <p>Penggunaan dan pemindahan informasi yang diterima dari Google API oleh SIPINTER mematuhi <a href="https://developers.google.com/terms/api-services-user-data-policy" target="_blank" rel="noopener">Google API Services User Data Policy</a>, termasuk ketentuan <em>Limited Use</em>.</p>

    <h2>3. Penggunaan data lainnya</h2>
    <p>Data akun dan isian kinerja hanya dipakai untuk keperluan pelaporan kinerja internal BPS Kabupaten Buton Utara: pengisian, verifikasi oleh Tim SAKIP, persetujuan oleh Kepala, serta pengiriman pengingat lewat email dan WhatsApp kepada pegawai yang bersangkutan.</p>

    <h2>4. Penyimpanan &amp; keamanan</h2>
    <ul>
        <li>Data disimpan di server aplikasi dan database SIPINTER; berkas bukti diarsipkan di Google Drive akun institusi.</li>
        <li>Akses ke SIPINTER dibatasi dengan login dan peran (Ketua Tim, Tim SAKIP, Kepala); akun baru harus diverifikasi Tim SAKIP.</li>
        <li>Token Google disimpan di database dan hanya dipakai oleh server SIPINTER untuk menghubungi Google Drive.</li>
    </ul>

    <h2>5. Mencabut akses &amp; menghapus data</h2>
    <ul>
        <li>Akses SIPINTER ke Google Drive dapat dicabut kapan saja lewat <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">pengaturan akun Google</a> (Keamanan → Koneksi pihak ketiga), atau dengan menghapus akun penyimpanan di menu Akun &amp; Storage SIPINTER.</li>
        <li>Pegawai dapat meminta penghapusan akun dan datanya kepada Tim SAKIP BPS Kabupaten Buton Utara.</li>
    </ul>

    <h2>6. Kontak</h2>
    <p>Pertanyaan tentang kebijakan ini dapat disampaikan kepada Tim SAKIP BPS Kabupaten Buton Utara melalui email <a href="mailto:7409sakip@gmail.com">7409sakip@gmail.com</a>.</p>
@endsection
