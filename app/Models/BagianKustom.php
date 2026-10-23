<?php

namespace App\Models;

use App\Support\FormatAngkaKolom;
use App\Support\MenuBagianKustom;
use App\Support\TemplateBagianKustom;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Definisi bagian tambahan yang dibuat Tim SAKIP tanpa mengubah kode (mis.
 * "Manajemen Risiko"). Semua pengaturannya — siapa yang mengisi, kolom isian (seperti
 * tabel), apakah masuk notula, dan format barisnya — diatur lewat layar Data Master.
 * Poin diisi per IKU & periode, bukti dukung opsional/wajib sesuai pengaturan.
 */
class BagianKustom extends Model
{
    use HasFactory;

    protected $table = 'bagian_kustom';

    /** Default bagian baru: diisi Ketua Tim di menu Isian Kegiatan (perilaku awal). */
    protected $attributes = [
        'peran' => '["ketua_tim"]',
        'penempatan' => '["pengisian"]',
    ];

    public const FREKUENSI_OPSIONAL = 'opsional';

    public const FREKUENSI_SETIAP_BULAN = 'setiap_bulan';

    public const FREKUENSI_AKHIR_TRIWULAN = 'akhir_triwulan';

    public const PERAN_KETUA_TIM = 'ketua_tim';

    public const PERAN_TIM_SAKIP = 'tim_sakip';

    public const PERAN_KEPALA = 'kepala';

    /** Nama peran pengisi, sama dengan nama Role di database. */
    public const NAMA_PERAN = [
        self::PERAN_KETUA_TIM => 'Ketua Tim',
        self::PERAN_TIM_SAKIP => 'Tim SAKIP',
        self::PERAN_KEPALA => 'Kepala',
    ];

    public const TIPE_KOLOM = [
        'teks' => 'Teks',
        'angka' => 'Angka',
        'pilihan' => 'Pilihan (daftar)',
    ];

    protected $fillable = [
        'nama',
        'deskripsi',
        'peran',
        'penempatan',
        'label_uraian',
        'kolom',
        'frekuensi_wajib',
        'bukti_wajib',
        'masuk_notula',
        'judul_notula',
        'format_notula',
        'aktif',
        'urutan',
    ];

    protected function casts(): array
    {
        return [
            'peran' => 'array',
            'penempatan' => 'array',
            'kolom' => 'array',
            'bukti_wajib' => 'boolean',
            'masuk_notula' => 'boolean',
            'aktif' => 'boolean',
        ];
    }

    public function poin(): HasMany
    {
        return $this->hasMany(BagianKustomPoin::class);
    }

    /**
     * Minimal satu poin bagian ini wajib diisi pada bulan-ke ($bulanKe, 1-3 dalam
     * triwulan) yang sedang diajukan — 'setiap_bulan' selalu wajib, 'akhir_triwulan'
     * hanya wajib di bulan terakhir, 'opsional' tidak pernah wajib.
     */
    public function wajibDiisiPada(int $bulanKe): bool
    {
        return match ($this->frekuensi_wajib) {
            self::FREKUENSI_SETIAP_BULAN => true,
            self::FREKUENSI_AKHIR_TRIWULAN => $bulanKe === 3,
            default => false,
        };
    }

    public function labelFrekuensi(): string
    {
        return match ($this->frekuensi_wajib) {
            self::FREKUENSI_SETIAP_BULAN => 'Wajib setiap bulan',
            self::FREKUENSI_AKHIR_TRIWULAN => 'Wajib akhir triwulan',
            default => 'Selalu opsional',
        };
    }

    /** Peran dalam bentuk nama tampilan, mis. "Ketua Tim, Tim SAKIP". */
    public function labelPeran(): string
    {
        return collect($this->peran ?? [])->map(fn ($p) => self::NAMA_PERAN[$p] ?? $p)->implode(', ') ?: '—';
    }

    /** Menu tempat bagian ini ditempatkan, dalam bentuk nama tampilan. */
    public function labelPenempatan(): string
    {
        return collect($this->penempatan ?? [])->map(fn ($m) => MenuBagianKustom::label($m))->implode(', ') ?: '—';
    }

    /** Peran yang memang bisa membuka setidaknya satu menu tempat bagian ini ditaruh. */
    public function tampilDi(string $peran, string $menu): bool
    {
        return in_array($peran, $this->peran ?? [], true)
            && in_array($menu, $this->penempatan ?? [], true)
            && in_array($peran, MenuBagianKustom::peranDiizinkan($menu), true);
    }

    public static function peranDariNama(?string $namaRole): ?string
    {
        return array_flip(self::NAMA_PERAN)[$namaRole ?? ''] ?? null;
    }

    public function labelUraianEfektif(): string
    {
        return filled($this->label_uraian) ? $this->label_uraian : 'Uraian';
    }

    public function judulNotulaEfektif(): string
    {
        return filled($this->judul_notula) ? $this->judul_notula : $this->nama;
    }

    public function formatNotulaEfektif(): string
    {
        return filled($this->format_notula) ? $this->format_notula : TemplateBagianKustom::FORMAT_STANDAR;
    }

    /**
     * SEMUA kolom isian (termasuk yang dinonaktifkan) dalam bentuk seragam — selalu ada
     * key-nya. Dipakai untuk menampilkan data tersimpan dan notula; untuk formulir isian
     * pakai kolomBerlaku().
     *
     * Kunci kolom adalah identitas tetap variabel: label boleh diganti tanpa memutus
     * data lama atau format notula yang memakai {kunci}.
     *
     * Pengaturan tambahan (semua opsional; kolom lama tanpa pengaturan ini berperilaku
     * seperti sebelumnya):
     * - aktif: false = tidak lagi diisi, data lama tetap tampil & masuk notula.
     * - mulai_tahun + mulai_triwulan: kolom baru diisi mulai periode ini.
     * - satuan, desimal, pemisah, ribuan: format tampilan kolom angka
     *   (lihat App\Support\FormatAngkaKolom).
     *
     * @return array<int, array{kunci: string, label: string, tipe: string, opsi: array<int, string>, wajib: bool, aktif: bool, mulai_tahun: ?int, mulai_triwulan: ?int, satuan: ?string, desimal: ?int, pemisah: string, ribuan: bool}>
     */
    public function kolomList(): array
    {
        return collect($this->kolom ?? [])
            ->filter(fn ($k) => filled($k['kunci'] ?? null) && filled($k['label'] ?? null))
            ->map(fn ($k) => self::normalkanKolom($k))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $k
     * @return array{kunci: string, label: string, tipe: string, opsi: array<int, string>, wajib: bool, aktif: bool, mulai_tahun: ?int, mulai_triwulan: ?int, satuan: ?string, desimal: ?int, pemisah: string, ribuan: bool}
     */
    public static function normalkanKolom(array $k): array
    {
        $tipe = array_key_exists($k['tipe'] ?? '', self::TIPE_KOLOM) ? $k['tipe'] : 'teks';
        $angka = $tipe === 'angka';
        $mulaiTahun = filled($k['mulai_tahun'] ?? null) ? (int) $k['mulai_tahun'] : null;
        $mulaiTriwulan = filled($k['mulai_triwulan'] ?? null) ? (int) $k['mulai_triwulan'] : null;
        $berlakuLengkap = $mulaiTahun !== null && $mulaiTriwulan !== null;

        return [
            'kunci' => (string) $k['kunci'],
            'label' => (string) $k['label'],
            'tipe' => $tipe,
            'opsi' => array_values((array) ($k['opsi'] ?? [])),
            'wajib' => (bool) ($k['wajib'] ?? false),
            'aktif' => (bool) ($k['aktif'] ?? true),
            'mulai_tahun' => $berlakuLengkap ? $mulaiTahun : null,
            'mulai_triwulan' => $berlakuLengkap ? max(1, min(4, $mulaiTriwulan)) : null,
            'satuan' => $angka && filled($k['satuan'] ?? null) ? trim((string) $k['satuan']) : null,
            'desimal' => $angka && isset($k['desimal']) && $k['desimal'] !== '' && $k['desimal'] !== null
                ? max(0, min(FormatAngkaKolom::DESIMAL_MAKS, (int) $k['desimal']))
                : null,
            'pemisah' => FormatAngkaKolom::pemisahDari($k),
            'ribuan' => (bool) ($k['ribuan'] ?? true),
        ];
    }

    /**
     * Kolom berlaku pada periode (tahun, triwulan): aktif dan sudah melewati periode
     * mulai berlakunya. Kolom tanpa periode mulai berlaku sejak awal.
     *
     * @param  array<string, mixed>  $kolom  hasil kolomList()
     */
    public static function kolomBerlakuPada(array $kolom, ?int $tahun, ?int $triwulan): bool
    {
        if (! ($kolom['aktif'] ?? true)) {
            return false;
        }

        if (($kolom['mulai_tahun'] ?? null) === null || $tahun === null || $triwulan === null) {
            return true;
        }

        return $tahun > $kolom['mulai_tahun']
            || ($tahun === (int) $kolom['mulai_tahun'] && $triwulan >= (int) $kolom['mulai_triwulan']);
    }

    /**
     * Kolom yang ditampilkan dan divalidasi di formulir isian untuk periode tertentu.
     *
     * @return array<int, array<string, mixed>>
     */
    public function kolomBerlaku(?int $tahun, ?int $triwulan): array
    {
        return array_values(array_filter($this->kolomList(), fn ($k) => self::kolomBerlakuPada($k, $tahun, $triwulan)));
    }

    /** Triwulan (1-4) dari nomor bulan (1-12). */
    public static function triwulanDariBulan(int $bulan): int
    {
        return intdiv(max(1, min(12, $bulan)) - 1, 3) + 1;
    }

    /**
     * Pesan galat satu nilai kolom (wajib, pilihan, angka), null bila sah — aturan yang
     * sama untuk Isian Kegiatan dan Isian Bagian Kustom.
     *
     * @param  array<string, mixed>  $kolom  hasil kolomList()
     */
    public static function pesanGalatKolom(array $kolom, mixed $nilai): ?string
    {
        $nilai = trim((string) $nilai);

        if ($nilai === '') {
            return $kolom['wajib'] ? "Kolom \"{$kolom['label']}\" wajib diisi." : null;
        }

        return match ($kolom['tipe']) {
            'pilihan' => in_array($nilai, $kolom['opsi'], true) ? null : "Pilih salah satu opsi untuk \"{$kolom['label']}\".",
            'angka' => FormatAngkaKolom::pesanGalat($nilai, $kolom),
            default => null,
        };
    }

    /**
     * Nilai kolom yang disimpan dari isian formulir. Hanya kolom yang berlaku pada
     * periode isian diambil dari formulir; nilai tersimpan kolom lain (nonaktif atau
     * belum berlaku) DIPERTAHANKAN, bukan dihapus. Angka berformat disimpan kanonik.
     *
     * @param  array<string, mixed>  $mentah
     * @param  array<string, mixed>|null  $tersimpan
     * @return array<string, string>
     */
    public function bersihkanNilaiKolom(array $mentah, ?array $tersimpan = null, ?int $tahun = null, ?int $triwulan = null): array
    {
        $hasil = [];

        foreach ($this->kolomList() as $kolom) {
            $kunci = $kolom['kunci'];

            $nilai = self::kolomBerlakuPada($kolom, $tahun, $triwulan)
                ? FormatAngkaKolom::untukDisimpan((string) ($mentah[$kunci] ?? ''), $kolom)
                : trim((string) ($tersimpan[$kunci] ?? ''));

            if ($nilai !== '') {
                $hasil[$kunci] = $nilai;
            }
        }

        return $hasil;
    }

    /**
     * Semua kolom selalu ada key-nya (kosong bila belum diisi), supaya variabel tanpa
     * isian tercetak kosong — bukan sebagai teks {kunci} mentah di notula.
     * Nilai MENTAH tersimpan; untuk tampilan pakai nilaiKolomTampil().
     *
     * @param  array<string, mixed>|null  $tersimpan
     * @return array<string, string>
     */
    public function nilaiKolomLengkap(?array $tersimpan): array
    {
        return collect($this->kolomList())
            ->mapWithKeys(fn ($k) => [$k['kunci'] => (string) ($tersimpan[$k['kunci']] ?? '')])
            ->all();
    }

    /**
     * Nilai tersimpan dalam bentuk yang diketik ulang di formulir.
     *
     * @param  array<string, mixed>|null  $tersimpan
     * @return array<string, string>
     */
    public function nilaiKolomUntukForm(?array $tersimpan): array
    {
        return collect($this->kolomList())
            ->mapWithKeys(fn ($k) => [$k['kunci'] => FormatAngkaKolom::untukInput((string) ($tersimpan[$k['kunci']] ?? ''), $k)])
            ->all();
    }

    /**
     * Nilai semua kolom dalam format tampilan (satuan, desimal, pemisah) — dipakai notula.
     *
     * @param  array<string, mixed>|null  $tersimpan
     * @return array<string, string>
     */
    public function nilaiKolomTampil(?array $tersimpan): array
    {
        return collect($this->kolomList())
            ->mapWithKeys(fn ($k) => [$k['kunci'] => FormatAngkaKolom::tampil((string) ($tersimpan[$k['kunci']] ?? ''), $k)])
            ->all();
    }

    /**
     * Teks tampilan satu nilai kolom (dipakai Blade riwayat isian & Verifikasi).
     *
     * @param  array<string, mixed>  $kolom  hasil kolomList()
     */
    public static function nilaiTampil(array $kolom, mixed $nilai): string
    {
        return FormatAngkaKolom::tampil((string) $nilai, $kolom);
    }

    /**
     * Keterangan format & periode kolom untuk layar pengaturan, mis.
     * "2 desimal · koma · satuan persen · mulai TW III 2026".
     *
     * @param  array<string, mixed>  $kolom  hasil kolomList()
     */
    public static function ringkasanKolom(array $kolom): string
    {
        $bagian = [];

        if ($kolom['tipe'] === 'angka') {
            $bagian[] = $kolom['desimal'] === null
                ? 'tampil apa adanya'
                : $kolom['desimal'].' desimal, '.($kolom['pemisah'] === FormatAngkaKolom::PEMISAH_TITIK ? 'titik' : 'koma').($kolom['ribuan'] ? ' + ribuan' : '');

            if ($kolom['satuan'] !== null) {
                $bagian[] = 'satuan '.$kolom['satuan'];
            }
        }

        if ($kolom['mulai_tahun'] !== null) {
            $bagian[] = 'mulai TW '.(['I', 'II', 'III', 'IV'][$kolom['mulai_triwulan'] - 1]).' '.$kolom['mulai_tahun'];
        }

        if (! $kolom['aktif']) {
            $bagian[] = 'nonaktif';
        }

        return implode(' · ', $bagian);
    }

    /**
     * Potret definisi bagian untuk riwayat perubahan konfigurasi.
     *
     * @return array<string, mixed>
     */
    public function potretDefinisi(): array
    {
        return [
            'nama' => $this->nama,
            'deskripsi' => $this->deskripsi,
            'peran' => array_values($this->peran ?? []),
            'penempatan' => array_values($this->penempatan ?? []),
            'label_uraian' => $this->label_uraian,
            'frekuensi_wajib' => $this->frekuensi_wajib,
            'bukti_wajib' => (bool) $this->bukti_wajib,
            'masuk_notula' => (bool) $this->masuk_notula,
            'judul_notula' => $this->judul_notula,
            'format_notula' => $this->format_notula,
            'aktif' => (bool) $this->aktif,
            'kolom' => $this->kolomList(),
        ];
    }

    /**
     * Bagian kustom AKTIF yang tampil untuk satu peran di satu menu.
     *
     * @return Collection<int, self>
     */
    public static function daftarUntuk(string $peran, string $menu): Collection
    {
        return static::daftarAktif()->filter(fn (self $b) => $b->tampilDi($peran, $menu))->values();
    }

    /**
     * Daftar bagian kustom AKTIF, di-cache 1 jam. Dilupakan lewat lupakanCache() tiap
     * kali Tim SAKIP menyimpan perubahan di BagianKustomManager.
     *
     * @return Collection<int, self>
     */
    public static function daftarAktif()
    {
        return Cache::remember(
            'bagian-kustom.aktif',
            3600,
            fn () => static::where('aktif', true)->orderBy('urutan')->orderBy('id')->get()
        );
    }

    public static function lupakanCache(): void
    {
        Cache::forget('bagian-kustom.aktif');
    }
}
