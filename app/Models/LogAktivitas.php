<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;

/**
 * Satu baris = satu aktivitas yang terjadi di SIPINTER (jejak audit). Dipakai sebagai
 * JEJAK perubahan supaya Tim SAKIP bisa menelusuri siapa mengubah apa — BUKAN sebagai
 * pembatas kewenangan (mis. pilihan IKU Ketua Tim tetap bebas).
 *
 * Aturan pencatatan:
 * - Pelaku selalu dari sesi login server (auth()), tidak pernah dari nilai request.
 * - Keterangan disusun dari data yang sudah divalidasi server, dan disimpan sebagai
 *   salinan saat kejadian — tetap benar walau nama bagian/IKU berubah kemudian.
 * - Catatan "berhasil" untuk perubahan data ditulis DI DALAM transaksi yang sama,
 *   jadi ikut batal bila transaksinya batal.
 * - Catatan tidak bisa diubah/dihapus lewat model (lihat booted()).
 *
 * Status alur verifikasi Capaian (diajukan/diverifikasi/dikembalikan/disetujui)
 * TIDAK dicatat ulang di sini — sudah ada di riwayat_status_capaian.
 */
class LogAktivitas extends Model
{
    protected $table = 'log_aktivitas';

    public const UPDATED_AT = null;

    public const STATUS_BERHASIL = 'berhasil';

    public const STATUS_DITOLAK = 'ditolak';

    public const STATUS_GAGAL = 'gagal';

    public const OBJEK_BAGIAN = 'bagian_kustom';

    public const OBJEK_POIN = 'bagian_kustom_poin';

    public const OBJEK_BERKAS = 'berkas';

    public const OBJEK_VARIABEL = 'variabel_katalog';

    public const OBJEK_RINCIAN_N = 'rincian_n';

    public const OBJEK_CAPAIAN_TAHUNAN = 'capaian_tahunan';

    /** Kunci konteks yang boleh diisi pemanggil — selain ini diabaikan. */
    protected const KUNCI_KONTEKS = ['objek_tipe', 'objek_id', 'menu', 'bagian_kustom_id', 'iku_id', 'periode_id', 'perubahan', 'korelasi'];

    /** Nama kunci yang tidak pernah boleh masuk ke ringkasan perubahan. */
    protected const POLA_RAHASIA = '/(pass|token|secret|rahasia|kredensial|credential|api[_-]?key|session|cookie)/i';

    /** Kunci yang tidak pernah ditampilkan di halaman baca log (rincian teknis penyimpanan). */
    protected const KUNCI_TERSEMBUNYI = ['path', 'drive_file_id', 'storage_account_id', 'korelasi'];

    /** Percobaan ditolak yang identik dalam rentang ini hanya dicatat sekali (cegah banjir log). */
    public const JEDA_TOLAK_SAMA_DETIK = 60;

    /** Label ramah tiap jenis aksi untuk halaman baca log & filter. */
    public const LABEL_AKSI = [
        'bagian_kustom_poin.dibuat' => 'Poin isian ditambahkan',
        'bagian_kustom_poin.diubah' => 'Poin isian diubah',
        'bagian_kustom_poin.dihapus' => 'Poin isian dihapus',
        'bagian_kustom_poin.simpan' => 'Percobaan simpan isian',
        'bagian_kustom_poin.ditandai_sesuai' => 'Poin ditandai Sesuai',
        'bagian_kustom_poin.ditandai_tidak_sesuai' => 'Poin ditandai Tidak Sesuai',
        'bagian_kustom_poin.diajukan' => 'Poin isian diajukan',
        'bagian_kustom_poin.ditarik' => 'Pengajuan poin ditarik',
        'bukti.diunggah' => 'Bukti dukung diunggah',
        'bukti.diganti' => 'Bukti dukung diganti',
        'bukti.dihapus' => 'Bukti dukung dihapus',
        'bukti.tersinkron' => 'Bukti tersalin ke Google Drive',
        'bukti.lokal_yatim_dibersihkan' => 'Pembersihan berkas lokal',
        'bagian_kustom_poin.dilanjutkan' => 'Poin isian dilanjutkan dari periode sebelumnya',
        'variabel_katalog.dibuat' => 'Variabel katalog dibuat',
        'variabel_katalog.diubah' => 'Variabel katalog diubah',
        'variabel_katalog.dinonaktifkan' => 'Variabel katalog dinonaktifkan',
        'variabel_katalog.diaktifkan' => 'Variabel katalog diaktifkan',
        'isian_kegiatan.simpan' => 'Percobaan simpan Isian Kegiatan',
        'akses.ditolak' => 'Akses ditolak',
        'target_tahunan.n_diubah' => 'Angka N Target Tahunan diubah',
        'rincian_n.dibuat' => 'Rincian n/N ditambahkan',
        'rincian_n.diubah' => 'Uraian rincian n/N diubah',
        'rincian_n.dilepas' => 'Rincian target dilepas dari N',
        'rincian_n.dihapus' => 'Rincian n/N dihapus',
        'rincian_n.realisasi_diubah' => 'Status realisasi rincian n diubah',
    ];

    protected const NAMA_BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    protected const ROMAWI = [1 => 'I', 'II', 'III', 'IV'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'perubahan' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Catatan log aktivitas tidak boleh diubah.'));
        static::deleting(fn () => throw new LogicException('Catatan log aktivitas tidak boleh dihapus.'));
    }

    /**
     * Tulis satu catatan aktivitas untuk pengguna yang sedang login (tanpa login =
     * proses otomatis sistem, mis. sinkron Drive terjadwal).
     *
     * Percobaan DITOLAK yang identik (pelaku, aksi, keterangan, objek sama) dalam
     * JEDA_TOLAK_SAMA_DETIK hanya dicatat sekali — mengembalikan null untuk yang dilewati.
     * Galat penulisan TIDAK ditelan: pemanggil di dalam transaksi ikut batal.
     *
     * @param  array<string, mixed>  $konteks  lihat KUNCI_KONTEKS
     */
    public static function catat(string $aksi, string $keterangan, array $konteks = [], string $status = self::STATUS_BERHASIL): ?self
    {
        $user = auth()->user();
        $konteks = array_intersect_key($konteks, array_flip(self::KUNCI_KONTEKS));

        if (isset($konteks['perubahan'])) {
            $konteks['perubahan'] = self::bersihkan((array) $konteks['perubahan']) ?: null;
        }

        $sidik = null;

        if ($status === self::STATUS_DITOLAK) {
            $sidik = 'log-tolak:'.sha1((string) json_encode([$user?->getKey(), $aksi, $keterangan, array_diff_key($konteks, ['korelasi' => true])]));

            if (! Cache::add($sidik, true, self::JEDA_TOLAK_SAMA_DETIK)) {
                return null;
            }
        }

        try {
            return static::create($konteks + [
                'created_at' => now(),
                'user_id' => $user?->getKey(),
                'nama_pengguna' => $user?->nama,
                'peran' => $user?->namaRole(),
                'aksi' => $aksi,
                'status' => $status,
                'keterangan' => Str::limit($keterangan, 2000),
            ]);
        } catch (\Throwable $e) {
            // Percobaan berikutnya tetap harus bisa tercatat.
            if ($sidik !== null) {
                Cache::forget($sidik);
            }

            throw $e;
        }
    }

    /** Kode korelasi untuk mengikat beberapa catatan dari satu proses simpan. */
    public static function korelasiBaru(): string
    {
        return (string) Str::uuid();
    }

    /** Subjek kalimat keterangan: nama peran pelaku (dari server). */
    public static function pelaku(): string
    {
        $user = auth()->user();

        if (! $user) {
            return 'Sistem (proses otomatis)';
        }

        return $user->namaRole() ?: 'Pengguna';
    }

    /**
     * Catat akses/aksi yang ditolak karena peran tidak sesuai. Dipakai sebelum 403 —
     * kegagalan menulis log di sini tidak boleh mengubah penolakan menjadi galat lain,
     * jadi galatnya hanya dilaporkan ke log aplikasi.
     */
    public static function aksesDitolak(string $tempat): void
    {
        try {
            self::catat('akses.ditolak', 'Akses '.$tempat.' oleh '.self::pelaku().' ditolak: peran pengguna tidak berwenang.', [], self::STATUS_DITOLAK);
        } catch (\Throwable $e) {
            Log::warning('Gagal mencatat penolakan akses ke log aktivitas: '.$e->getMessage());
        }
    }

    public static function labelAksi(string $aksi): string
    {
        return self::LABEL_AKSI[$aksi] ?? $aksi;
    }

    /**
     * Baris "data — sebelum — sesudah" untuk halaman baca log. Kunci teknis/rahasia
     * tidak ditampilkan; nilai bertingkat diratakan ("kolom › target").
     *
     * @return array<int, array{label: string, sebelum: string, sesudah: string}>
     */
    public function barisPerubahan(): array
    {
        $perubahan = (array) ($this->perubahan ?? []);

        // Bentuk {sebelum: {...}, sesudah: {...}} (buat/hapus/bukti) diubah menjadi
        // bentuk per kolom {kunci: {sebelum, sesudah}} seperti hasil selisih().
        if (array_key_exists('sebelum', $perubahan) || array_key_exists('sesudah', $perubahan)) {
            $sebelum = self::ratakan((array) ($perubahan['sebelum'] ?? []));
            $sesudah = self::ratakan((array) ($perubahan['sesudah'] ?? []));
            $lain = array_diff_key($perubahan, ['sebelum' => 1, 'sesudah' => 1]);
            $perubahan = [];

            foreach (array_unique([...array_keys($sebelum), ...array_keys($sesudah)]) as $kunci) {
                $perubahan[$kunci] = ['sebelum' => $sebelum[$kunci] ?? null, 'sesudah' => $sesudah[$kunci] ?? null];
            }

            foreach (self::ratakan($lain) as $kunci => $nilai) {
                $perubahan[$kunci] = ['sebelum' => null, 'sesudah' => $nilai];
            }
        }

        $hasil = [];

        foreach ($perubahan as $kunci => $isi) {
            $kunci = (string) $kunci;

            if (self::tersembunyi($kunci)) {
                continue;
            }

            if (is_array($isi) && (array_key_exists('sebelum', $isi) || array_key_exists('sesudah', $isi))) {
                $lama = $isi['sebelum'] ?? null;
                $baru = $isi['sesudah'] ?? null;

                // Kolom bertingkat (mis. nilai_kolom) dirinci per kunci di dalamnya.
                if (is_array($lama) || is_array($baru)) {
                    $l = self::ratakan((array) $lama);
                    $b = self::ratakan((array) $baru);

                    foreach (array_unique([...array_keys($l), ...array_keys($b)]) as $sub) {
                        if (self::tersembunyi((string) $sub) || self::normal($l[$sub] ?? null) === self::normal($b[$sub] ?? null)) {
                            continue;
                        }

                        $hasil[] = ['label' => $kunci.' › '.$sub, 'sebelum' => self::teks($l[$sub] ?? null), 'sesudah' => self::teks($b[$sub] ?? null)];
                    }

                    continue;
                }

                $hasil[] = ['label' => $kunci, 'sebelum' => self::teks($lama), 'sesudah' => self::teks($baru)];

                continue;
            }

            $hasil[] = ['label' => $kunci, 'sebelum' => '', 'sesudah' => self::teks($isi)];
        }

        return $hasil;
    }

    protected static function tersembunyi(string $kunci): bool
    {
        $akhir = str_contains($kunci, ' › ') ? substr($kunci, strrpos($kunci, ' › ') + 5) : $kunci;

        return in_array($akhir, self::KUNCI_TERSEMBUNYI, true) || preg_match(self::POLA_RAHASIA, $akhir) === 1;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    protected static function ratakan(array $data, string $awalan = ''): array
    {
        $hasil = [];

        foreach ($data as $kunci => $nilai) {
            $nama = $awalan === '' ? (string) $kunci : $awalan.' › '.$kunci;

            if (is_array($nilai) && ! array_is_list($nilai)) {
                $hasil += self::ratakan($nilai, $nama);
            } else {
                $hasil[$nama] = $nilai;
            }
        }

        return $hasil;
    }

    protected static function teks(mixed $nilai): string
    {
        return match (true) {
            $nilai === null, $nilai === '' => '—',
            is_bool($nilai) => $nilai ? 'ya' : 'tidak',
            is_array($nilai) => implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $nilai)) ?: '—',
            default => (string) $nilai,
        };
    }

    public static function labelIku(?MasterIku $iku): string
    {
        if (! $iku) {
            return 'IKU (tidak ditemukan)';
        }

        return 'IKU '.$iku->kode.' ('.Str::limit((string) $iku->indikator, 80).')';
    }

    public static function labelPeriode(?Periode $periode): string
    {
        if (! $periode) {
            return 'periode (tidak ditemukan)';
        }

        return (self::NAMA_BULAN[(int) $periode->bulan] ?? $periode->bulan).' '.$periode->tahun
            .' (Triwulan '.(self::ROMAWI[(int) $periode->triwulan] ?? $periode->triwulan).')';
    }

    /**
     * Ringkasan perubahan: hanya field yang nilainya berbeda.
     *
     * @param  array<string, mixed>  $sebelum
     * @param  array<string, mixed>  $sesudah
     * @return array<string, array{sebelum: mixed, sesudah: mixed}>
     */
    public static function selisih(array $sebelum, array $sesudah): array
    {
        $hasil = [];

        foreach (array_unique([...array_keys($sebelum), ...array_keys($sesudah)]) as $kunci) {
            $lama = $sebelum[$kunci] ?? null;
            $baru = $sesudah[$kunci] ?? null;

            if (self::normal($lama) !== self::normal($baru)) {
                $hasil[$kunci] = ['sebelum' => $lama, 'sesudah' => $baru];
            }
        }

        return $hasil;
    }

    /** Nilai pembanding: kosong/null dianggap sama, angka dibandingkan sebagai teks. */
    protected static function normal(mixed $nilai): string
    {
        if ($nilai === null || $nilai === '' || $nilai === []) {
            return '';
        }

        if (is_array($nilai)) {
            return json_encode(self::urutkan($nilai));
        }

        return is_bool($nilai) ? ($nilai ? '1' : '0') : trim((string) $nilai);
    }

    protected static function urutkan(array $nilai): array
    {
        if (! array_is_list($nilai)) {
            ksort($nilai);
        }

        return array_map(fn ($v) => is_array($v) ? self::urutkan($v) : $v, $nilai);
    }

    /**
     * Buang kunci rahasia dan potong teks panjang — log bukan salinan payload.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    protected static function bersihkan(array $data): array
    {
        $hasil = [];

        foreach ($data as $kunci => $nilai) {
            if (is_string($kunci) && preg_match(self::POLA_RAHASIA, $kunci)) {
                continue;
            }

            $hasil[$kunci] = match (true) {
                is_array($nilai) => self::bersihkan($nilai),
                is_string($nilai) => Str::limit($nilai, 500),
                is_scalar($nilai), $nilai === null => $nilai,
                default => Str::limit((string) json_encode($nilai), 500),
            };
        }

        return $hasil;
    }
}
