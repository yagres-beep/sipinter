{{--
    Badge status terpusat — memetakan NILAI STATUS asli dari backend (status_dokumen,
    status_verifikasi berkas/akun, status notula, status_cocok RTL) ke kelas & label
    badge sesuai mockup (b-draft/b-ajukan/b-verif/b-kembali/b-approve/b-tunggu).
    Dipakai di semua halaman daftar supaya tampilan badge konsisten satu tempat.

    Pemakaian: <x-badge-status :status="$kegiatan->status_dokumen" />
    Label boleh ditimpa manual: <x-badge-status status="diajukan" label="Menunggu Tim SAKIP" />
--}}
@props(['status', 'label' => null])

@php
    $peta = [
        // Kegiatan / alur dokumen umum
        'draft' => ['b-draft', 'Draf'],
        'diajukan' => ['b-ajukan', 'Diajukan'],
        'diverifikasi' => ['b-verif', 'Diverifikasi'],
        'dikembalikan' => ['b-kembali', 'Dikembalikan'],
        'disetujui' => ['b-approve', 'Disetujui'],
        // Notula
        'menunggu_persetujuan' => ['b-tunggu', 'Menunggu Persetujuan'],
        // Berkas & akun pengguna
        'menunggu' => ['b-tunggu', 'Menunggu'],
        'terverifikasi' => ['b-approve', 'Terverifikasi'],
        'ditolak' => ['b-kembali', 'Ditolak'],
        'pending' => ['b-tunggu', 'Menunggu'],
        // Kecocokan realisasi RTL
        'cocok' => ['b-approve', 'Cocok'],
        'perlu_ditinjau' => ['b-tunggu', 'Perlu Ditinjau'],
        'tidak_cocok' => ['b-kembali', 'Tidak Cocok'],
    ];

    [$kelas, $labelDefault] = $peta[$status] ?? ['b-draft', ucfirst(str_replace('_', ' ', (string) $status))];
@endphp

<span {{ $attributes->merge(['class' => "badge {$kelas}"]) }}>{{ $label ?? $labelDefault }}</span>
