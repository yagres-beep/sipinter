<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Daftar penerima tiap jenis pengingat email (satu baris per jenis) yang bisa
 * diubah Tim SAKIP lewat halaman Pengingat — dipakai App\Console\Commands\Pengingat*
 * dan App\Listeners\KirimPengingatStatus* lewat resolveUsers(), menggantikan
 * daftar penerima yang tadinya dikodekan langsung (mis. selalu "Tim SAKIP").
 *
 * 'roles' berisi campuran nama Role asli ('tim_sakip', 'kepala', 'ketua_tim' —
 * lihat User::olehRole()) dan satu pseudo-peran 'penanggung_jawab' (penanggung
 * jawab IKU/notula terkait kejadian itu sendiri, lihat MasterIku::
 * semuaPenanggungJawab(), bukan baris di tabel roles).
 */
class PengaturanPenerimaPengingat extends Model
{
    use HasFactory;

    protected $table = 'pengaturan_penerima_pengingat';

    protected $fillable = [
        'jenis',
        'roles',
    ];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
        ];
    }

    /**
     * Metadata tiap jenis pengingat: label & deskripsi untuk halaman pengaturan,
     * 'opsi' = pilihan peran yang masuk akal untuk jenis itu, 'default' = penerima
     * bawaan (perilaku sebelum pengaturan ini ada) bila baris belum pernah diubah.
     */
    public const JENIS = [
        'deadline_iku' => [
            'label' => 'Tenggat Pengajuan IKU',
            'deskripsi' => 'IKU mendekati/melewati tenggat pengajuan bulan berjalan (dicek terjadwal).',
            'opsi' => ['penanggung_jawab', 'tim_sakip'],
            'default' => ['penanggung_jawab'],
        ],
        'iku_diajukan' => [
            'label' => 'IKU Diajukan',
            'deskripsi' => 'Ketua Tim mengajukan capaian IKU untuk diperiksa.',
            'opsi' => ['tim_sakip'],
            'default' => ['tim_sakip'],
        ],
        'iku_dikembalikan' => [
            'label' => 'IKU Dikembalikan',
            'deskripsi' => 'Tim SAKIP mengembalikan capaian IKU untuk diperbaiki.',
            'opsi' => ['penanggung_jawab', 'tim_sakip'],
            'default' => ['penanggung_jawab'],
        ],
        'iku_lengkap' => [
            'label' => 'IKU Triwulan Lengkap',
            'deskripsi' => 'Semua IKU triwulan berjalan sudah terverifikasi, siap disusun jadi Notula (dicek terjadwal).',
            'opsi' => ['tim_sakip'],
            'default' => ['tim_sakip'],
        ],
        'google_reconnect' => [
            'label' => 'Google Perlu Disambungkan Ulang',
            'deskripsi' => 'Akun Google Drive untuk unggah bukti dukung kedaluwarsa/tidak valid (dicek terjadwal).',
            'opsi' => ['tim_sakip'],
            'default' => ['tim_sakip'],
        ],
        'notula_menunggu_persetujuan' => [
            'label' => 'Notula Siap Disetujui',
            'deskripsi' => 'Tim SAKIP selesai menggabungkan Notula, siap ditandatangani.',
            'opsi' => ['kepala', 'tim_sakip'],
            'default' => ['kepala'],
        ],
        'notula_dikembalikan' => [
            'label' => 'Notula Dikembalikan',
            'deskripsi' => 'Kepala mengembalikan Notula untuk diperbaiki.',
            'opsi' => ['tim_sakip', 'kepala'],
            'default' => ['tim_sakip'],
        ],
    ];

    public const ROLE_LABEL = [
        'penanggung_jawab' => 'Penanggung Jawab Terkait',
        'tim_sakip' => 'Tim SAKIP',
        'kepala' => 'Kepala',
        'ketua_tim' => 'Semua Ketua Tim',
    ];

    protected const NAMA_ROLE_ASLI = [
        'tim_sakip' => 'Tim SAKIP',
        'kepala' => 'Kepala',
        'ketua_tim' => 'Ketua Tim',
    ];

    /** Semua baris pengaturan, di-cache permanen, dikunci pada jenis. */
    public static function semua(): Collection
    {
        return Cache::rememberForever(
            'pengaturan-penerima-pengingat',
            fn () => static::all()->keyBy('jenis')
        );
    }

    public static function lupakanCache(): void
    {
        Cache::forget('pengaturan-penerima-pengingat');
    }

    /**
     * Daftar peran/pseudo-peran penerima untuk satu jenis — jatuh ke nilai
     * default bawaan bila baris untuk jenis itu belum ada (mis. sebelum
     * migrasi/seed sempat jalan, atau jenis baru ditambahkan di kode).
     */
    public static function rolesUntuk(string $jenis): array
    {
        $baris = static::semua()->get($jenis);

        return $baris?->roles ?? static::JENIS[$jenis]['default'] ?? [];
    }

    public static function simpan(string $jenis, array $roles): void
    {
        static::updateOrCreate(['jenis' => $jenis], ['roles' => $roles]);
        static::lupakanCache();
    }

    /**
     * Resolusi peran/pseudo-peran jadi daftar User sungguhan untuk satu kejadian
     * pengingat. $penanggungJawab dibutuhkan hanya untuk jenis yang punya opsi
     * 'penanggung_jawab' (lihat MasterIku::semuaPenanggungJawab()) — diabaikan
     * untuk jenis lain.
     *
     * @return Collection<int, User>
     */
    public static function resolveUsers(string $jenis, ?Collection $penanggungJawab = null): Collection
    {
        $hasil = collect();

        foreach (static::rolesUntuk($jenis) as $role) {
            $hasil = match ($role) {
                'penanggung_jawab' => $hasil->concat($penanggungJawab ?? collect()),
                default => $hasil->concat(User::olehRole(static::NAMA_ROLE_ASLI[$role] ?? '')),
            };
        }

        return $hasil->unique('id')->values();
    }
}
