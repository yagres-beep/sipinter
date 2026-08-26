<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Format pesan tiap jenis pengingat WA (satu baris per jenis) yang bisa diubah
 * Tim SAKIP lewat halaman Pengingat WA — dipakai App\Console\Commands\Pengingat*
 * dan App\Listeners\KirimPengingatStatus* lewat render(), menggantikan string
 * pesan yang tadinya dikodekan langsung (sprintf) di tiap Command/Listener.
 *
 * Placeholder ditulis "{nama_token}" di dalam template, diganti render() lewat
 * str_replace biasa — bukan lewat sprintf/positional (%s) supaya Tim SAKIP tidak
 * perlu tahu urutan argumen saat mengubah templatenya sendiri.
 *
 * Untuk 2 jenis yang punya "catatan" opsional (iku_dikembalikan,
 * notula_dikembalikan) — baris catatan itu SENGAJA tidak jadi bagian template
 * (tidak ada token {catatan}), tapi selalu ditambahkan kode setelah render()
 * kalau ada isinya, supaya catatan dari Tim SAKIP/Kepala tidak bisa hilang
 * hanya karena template diubah.
 */
class PengaturanTemplatePengingat extends Model
{
    use HasFactory;

    protected $table = 'pengaturan_template_pengingat';

    protected $fillable = [
        'jenis',
        'template',
    ];

    public const JENIS = [
        'deadline_iku_akan_tiba' => [
            'label' => 'Tenggat IKU — Mendekat',
            'deskripsi' => 'Dikirim H- sekian hari sebelum tenggat pengajuan IKU (lihat pengaturan Waktu Pengingat di atas).',
            'token' => [
                'indikator' => 'Nama indikator IKU',
                'tanggal_tenggat' => 'Tanggal akhir bulan berjalan, mis. "30 September 2026"',
            ],
            'default' => "Pengingat SIPINTER:\nTenggat pengajuan IKU \"{indikator}\" tanggal {tanggal_tenggat}, segera ajukan.",
        ],
        'deadline_iku_lewat' => [
            'label' => 'Tenggat IKU — Sudah Lewat',
            'deskripsi' => 'Dikirim tiap hari selama IKU belum diajukan padahal tenggatnya sudah lewat.',
            'token' => [
                'indikator' => 'Nama indikator IKU',
                'bulan_tenggat' => 'Bulan & tahun tenggat, mis. "September 2026"',
            ],
            'default' => "Pengingat SIPINTER:\nIKU \"{indikator}\" belum diisi padahal tenggat pengajuan (akhir {bulan_tenggat}) sudah lewat.",
        ],
        'iku_diajukan' => [
            'label' => 'IKU Diajukan',
            'deskripsi' => 'Ketua Tim mengajukan capaian IKU untuk diperiksa.',
            'token' => [
                'indikator' => 'Nama indikator IKU',
                'periode' => 'Bulan & tahun periode, mis. "Agustus 2026"',
            ],
            'default' => "Pengingat SIPINTER:\nIKU \"{indikator}\" ({periode}) sudah diajukan Ketua Tim, perlu diperiksa.",
        ],
        'iku_dikembalikan' => [
            'label' => 'IKU Dikembalikan',
            'deskripsi' => 'Tim SAKIP mengembalikan capaian IKU untuk diperbaiki. Baris "Catatan: ..." (kalau diisi) selalu ditambahkan otomatis setelah pesan ini.',
            'token' => [
                'indikator' => 'Nama indikator IKU',
                'periode' => 'Bulan & tahun periode, mis. "Agustus 2026"',
            ],
            'default' => "Pengingat SIPINTER:\nIKU \"{indikator}\" ({periode}) dikembalikan Tim SAKIP, perlu diperbaiki.",
        ],
        'iku_lengkap' => [
            'label' => 'IKU Triwulan Lengkap',
            'deskripsi' => 'Semua IKU triwulan berjalan sudah terverifikasi, siap disusun jadi Notula.',
            'token' => [
                'triwulan' => 'Nomor triwulan, mis. "3"',
                'tahun' => 'Tahun, mis. "2026"',
            ],
            'default' => "Pengingat SIPINTER:\nSeluruh IKU Triwulan {triwulan} Tahun {tahun} sudah lengkap (terverifikasi), silakan susun Notula.",
        ],
        'google_reconnect' => [
            'label' => 'Google Perlu Disambungkan Ulang',
            'deskripsi' => 'Akun Google Drive untuk unggah bukti dukung kedaluwarsa/tidak valid.',
            'token' => [
                'akun_google' => 'Email akun Google Drive institusi yang bermasalah',
            ],
            'default' => "Pengingat SIPINTER:\nAkun Google \"{akun_google}\" perlu disambungkan ulang (token kedaluwarsa/tidak valid), unggahan bukti dukung ke Drive terganggu sampai dihubungkan ulang.",
        ],
        'notula_menunggu_persetujuan' => [
            'label' => 'Notula Siap Disetujui',
            'deskripsi' => 'Tim SAKIP selesai menggabungkan Notula, siap ditandatangani.',
            'token' => [
                'triwulan_label' => 'Label triwulan, mis. "Triwulan 3 Tahun 2026"',
            ],
            'default' => 'Pengingat SIPINTER:'."\n".'Notula {triwulan_label} sudah digabungkan Tim SAKIP dan siap ditandatangani/disetujui.',
        ],
        'notula_dikembalikan' => [
            'label' => 'Notula Dikembalikan',
            'deskripsi' => 'Kepala mengembalikan Notula untuk diperbaiki. Baris "Catatan: ..." (kalau diisi) selalu ditambahkan otomatis setelah pesan ini.',
            'token' => [
                'triwulan_label' => 'Label triwulan, mis. "Triwulan 3 Tahun 2026"',
            ],
            'default' => 'Pengingat SIPINTER:'."\n".'Notula {triwulan_label} dikembalikan Kepala, perlu diperbaiki.',
        ],
    ];

    /** Semua baris pengaturan, di-cache permanen, dikunci pada jenis. */
    public static function semua(): Collection
    {
        return Cache::rememberForever(
            'pengaturan-template-pengingat',
            fn () => static::all()->keyBy('jenis')
        );
    }

    public static function lupakanCache(): void
    {
        Cache::forget('pengaturan-template-pengingat');
    }

    /** Template mentah (belum diisi token) untuk satu jenis. */
    public static function ambil(string $jenis): string
    {
        $baris = static::semua()->get($jenis);

        return $baris?->template ?? static::JENIS[$jenis]['default'] ?? '';
    }

    public static function simpan(string $jenis, string $template): void
    {
        static::updateOrCreate(['jenis' => $jenis], ['template' => $template]);
        static::lupakanCache();
    }

    /**
     * Isi template dengan nilai sungguhan — kunci $data harus cocok dengan
     * daftar token jenis itu (lihat JENIS[$jenis]['token']).
     *
     * @param  array<string, string>  $data
     */
    public static function render(string $jenis, array $data): string
    {
        $cari = array_map(fn ($token) => '{'.$token.'}', array_keys($data));

        return str_replace($cari, array_values($data), static::ambil($jenis));
    }
}
