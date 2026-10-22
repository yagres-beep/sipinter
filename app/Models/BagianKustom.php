<?php

namespace App\Models;

use App\Support\MenuBagianKustom;
use App\Support\TemplateBagianKustom;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

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
     * Kolom isian dalam bentuk seragam (selalu ada key-nya), dengan opsi pilihan sudah
     * berupa array.
     *
     * @return array<int, array{kunci: string, label: string, tipe: string, opsi: array<int, string>, wajib: bool}>
     */
    public function kolomList(): array
    {
        return collect($this->kolom ?? [])
            ->filter(fn ($k) => filled($k['kunci'] ?? null) && filled($k['label'] ?? null))
            ->map(fn ($k) => [
                'kunci' => (string) $k['kunci'],
                'label' => (string) $k['label'],
                'tipe' => array_key_exists($k['tipe'] ?? '', self::TIPE_KOLOM) ? $k['tipe'] : 'teks',
                'opsi' => array_values((array) ($k['opsi'] ?? [])),
                'wajib' => (bool) ($k['wajib'] ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * Ambil hanya nilai kolom yang dikenali, dalam bentuk string (kosong = null).
     *
     * @param  array<string, mixed>  $mentah
     * @return array<string, string>
     */
    public function bersihkanNilaiKolom(array $mentah): array
    {
        $hasil = [];

        foreach ($this->kolomList() as $kolom) {
            $nilai = trim((string) ($mentah[$kolom['kunci']] ?? ''));

            if ($nilai !== '') {
                $hasil[$kolom['kunci']] = $nilai;
            }
        }

        return $hasil;
    }

    /**
     * Semua kolom selalu ada key-nya (kosong bila belum diisi), supaya variabel tanpa
     * isian tercetak kosong — bukan sebagai teks {kunci} mentah di notula.
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
     * Bagian kustom AKTIF yang tampil untuk satu peran di satu menu.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function daftarUntuk(string $peran, string $menu): Collection
    {
        return static::daftarAktif()->filter(fn (self $b) => $b->tampilDi($peran, $menu))->values();
    }

    /**
     * Daftar bagian kustom AKTIF, di-cache 1 jam. Dilupakan lewat lupakanCache() tiap
     * kali Tim SAKIP menyimpan perubahan di BagianKustomManager.
     *
     * @return \Illuminate\Support\Collection<int, self>
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
