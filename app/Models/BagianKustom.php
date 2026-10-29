<?php

namespace App\Models;

use App\Support\AlurBagianKustom;
use App\Support\AturanBuktiBagianKustom;
use App\Support\FormatAngkaKolom;
use App\Support\RumusBagianKustom;
use App\Support\JenisKolomBagianKustom;
use App\Support\MenuBagianKustom;
use App\Support\TemplateBagianKustom;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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

    /**
     * Aksi yang bisa diatur per peran pengisi (matriks hak akses). "Lihat" tidak ada di
     * sini: siapa yang melihat diatur lewat `peran` (pengisi) dan `peran_pembaca`.
     */
    public const AKSI = [
        'buat' => 'Buat poin',
        'ubah' => 'Ubah poin',
        'hapus' => 'Hapus poin',
        'unggah_bukti' => 'Unggah bukti',
        'hapus_bukti' => 'Hapus bukti',
    ];

    public const TIPE_KOLOM = JenisKolomBagianKustom::TIPE;

    /** Batas kolom isian per bagian (semua subbagian). */
    public const KOLOM_MAKS = 40;

    /** Batas subbagian per bagian. */
    public const SUBBAGIAN_MAKS = 15;

    /** Pola kode internal: huruf kecil, angka, garis bawah; diawali huruf. */
    public const POLA_KODE = '/^[a-z][a-z0-9_]{1,59}$/';

    protected $fillable = [
        'kode',
        'nama',
        'nama_folder',
        'deskripsi',
        'peran',
        'peran_pembaca',
        'hak_aksi',
        'penempatan',
        'label_uraian',
        'kolom',
        'subbagian',
        'tampilan',
        'frekuensi_wajib',
        'bukti_wajib',
        'aturan_bukti',
        'masuk_notula',
        'judul_notula',
        'format_notula',
        'alur',
        'periode_berlaku',
        'aktif',
        'urutan',
    ];

    protected function casts(): array
    {
        return [
            'alur' => 'array',
            'periode_berlaku' => 'array',
            'peran' => 'array',
            'penempatan' => 'array',
            'kolom' => 'array',
            'subbagian' => 'array',
            'peran_pembaca' => 'array',
            'hak_aksi' => 'array',
            'tampilan' => 'array',
            'aturan_bukti' => 'array',
            'bukti_wajib' => 'boolean',
            'masuk_notula' => 'boolean',
            'aktif' => 'boolean',
        ];
    }

    /**
     * Kode internal dan nama folder Drive diisi sekali saat bagian dibuat, lalu tetap:
     * mengganti nama bagian tidak mengubah keduanya.
     */
    protected static function booted(): void
    {
        static::creating(function (self $bagian) {
            if (blank($bagian->kode)) {
                $bagian->kode = self::kodeBaru((string) $bagian->nama);
            }

            if (blank($bagian->nama_folder)) {
                $bagian->nama_folder = $bagian->nama;
            }
        });
    }

    /**
     * Kode unik dari nama (mis. "Manajemen Risiko" → manajemen_risiko), diberi akhiran
     * _2, _3, … bila sudah dipakai bagian lain (aktif maupun nonaktif).
     */
    public static function kodeBaru(string $nama, ?int $kecualiId = null): string
    {
        $dasar = Str::of($nama)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(50, '')->trim('_')->value();
        $dasar = preg_match('/^[a-z]/', $dasar) ? $dasar : rtrim('bagian_'.$dasar, '_');

        $terpakai = static::query()
            ->when($kecualiId, fn ($q) => $q->where('id', '!=', $kecualiId))
            // "_" di LIKE berarti satu karakter apa saja — hasilnya superset, dicocokkan
            // persis di bawah.
            ->where('kode', 'like', $dasar.'%')
            ->pluck('kode')
            ->all();

        $kode = $dasar;
        $n = 2;

        while (in_array($kode, $terpakai, true)) {
            $kode = $dasar.'_'.$n++;
        }

        return $kode;
    }

    /** Nama folder Drive tempat bukti dukung bagian ini diunggah. */
    public function namaFolderEfektif(): string
    {
        return filled($this->nama_folder) ? $this->nama_folder : (string) $this->nama;
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

    /**
     * Peran pembaca: boleh MELIHAT isian bagian ini (semua konteks pengisi) di menu
     * tempat bagian ditempatkan, tanpa hak mengubah. Peran yang juga pengisi tidak
     * dihitung sebagai pembaca (formulir isiannya sudah tampil).
     */
    public function bisaDibacaDi(string $peran, string $menu): bool
    {
        return in_array($peran, $this->peran_pembaca ?? [], true)
            && ! in_array($peran, $this->peran ?? [], true)
            && in_array($menu, $this->penempatan ?? [], true)
            && in_array($peran, MenuBagianKustom::peranDiizinkan($menu), true);
    }

    /**
     * Aksi yang boleh dilakukan satu peran pada isian bagian ini. Hanya peran pengisi
     * yang punya aksi; pembaca dan peran lain tidak punya aksi apa pun.
     *
     * hak_aksi NULL, atau peran pengisi belum punya entri = semua aksi (perilaku lama,
     * sebelum matriks hak akses ada).
     *
     * @return array<int, string>
     */
    public function aksiPeran(?string $peran): array
    {
        if ($peran === null || ! in_array($peran, $this->peran ?? [], true)) {
            return [];
        }

        $hak = $this->hak_aksi;

        if (! is_array($hak) || ! array_key_exists($peran, $hak)) {
            return array_keys(self::AKSI);
        }

        return array_values(array_intersect(array_keys(self::AKSI), (array) $hak[$peran]));
    }

    public function bolehAksi(?string $peran, string $aksi): bool
    {
        return in_array($aksi, $this->aksiPeran($peran), true);
    }

    /**
     * Peta aksi → boleh/tidak untuk satu peran, untuk tampilan.
     *
     * @return array<string, bool>
     */
    public function petaAksi(?string $peran): array
    {
        $boleh = $this->aksiPeran($peran);

        return collect(array_keys(self::AKSI))->mapWithKeys(fn ($a) => [$a => in_array($a, $boleh, true)])->all();
    }

    /**
     * Matriks hak tersimpan dalam bentuk lengkap untuk semua peran pengisi, mis.
     * ['ketua_tim' => ['buat', 'ubah', …]]. Dipakai riwayat konfigurasi dan form.
     *
     * @return array<string, array<int, string>>
     */
    public function matriksAksi(): array
    {
        return collect($this->peran ?? [])->mapWithKeys(fn ($p) => [$p => $this->aksiPeran($p)])->all();
    }

    /** Peran pembaca dalam bentuk nama tampilan. */
    public function labelPeranPembaca(): string
    {
        return collect($this->peran_pembaca ?? [])->map(fn ($p) => self::NAMA_PERAN[$p] ?? $p)->implode(', ') ?: '—';
    }

    /**
     * Aturan bukti dukung bagian ini (lihat AturanBuktiBagianKustom::normalkan()).
     *
     * @return array{mode: string, min: int, maks: ?int, format: array<int, string>, ukuran_maks_mb: ?int, petunjuk: ?string, diatur: bool}
     */
    public function aturanBukti(): array
    {
        return AturanBuktiBagianKustom::normalkan($this->aturan_bukti, (bool) $this->bukti_wajib);
    }

    /**
     * Aturan bukti yang berlaku di satu menu. Bagian yang belum pernah diatur aturan
     * buktinya mempertahankan perilaku lama: bukti hanya ada di Isian Kegiatan.
     *
     * @return array<string, mixed>
     */
    public function aturanBuktiDi(string $menu): array
    {
        $aturan = $this->aturanBukti();

        if (! $aturan['diatur'] && $menu !== MenuBagianKustom::PENGISIAN) {
            $aturan['mode'] = AturanBuktiBagianKustom::MODE_TIDAK;
            $aturan['min'] = 0;
        }

        // Isian Kegiatan punya alur pengajuan sendiri: jumlah minimal selalu diperiksa
        // saat simpan/ajukan Isian Kegiatan. Di menu lain, "saat diajukan/disetujui"
        // hanya berlaku bila alur verifikasi dipakai.
        if ($menu === MenuBagianKustom::PENGISIAN || ! AlurBagianKustom::pakaiVerifikasi($this->alurKerja())) {
            $aturan['saat'] = AturanBuktiBagianKustom::SAAT_SIMPAN;
        }

        return $aturan;
    }

    /**
     * Pengaturan alur kerja (lihat AlurBagianKustom::normalkan()).
     *
     * @return array{mode: string, pengaju: array<int, string>, pemeriksa: array<int, string>, ubah_setelah_disetujui: bool, notula: string, diatur: bool}
     */
    public function alurKerja(): array
    {
        return AlurBagianKustom::normalkan($this->alur, array_values($this->peran ?? []));
    }

    /**
     * Periode tampil bagian ini: triwulan yang dipilih (kosong = semua) dan rentang tahun.
     *
     * @return array{triwulan: array<int, int>, tahun_mulai: ?int, tahun_selesai: ?int}
     */
    public function periodeBerlaku(): array
    {
        return self::normalkanPeriode($this->periode_berlaku);
    }

    /**
     * @return array{triwulan: array<int, int>, tahun_mulai: ?int, tahun_selesai: ?int}
     */
    public static function normalkanPeriode(?array $p): array
    {
        $tw = array_values(array_unique(array_filter(array_map('intval', (array) ($p['triwulan'] ?? [])), fn ($t) => $t >= 1 && $t <= 4)));
        sort($tw);

        return [
            'triwulan' => count($tw) === 4 ? [] : $tw,
            'tahun_mulai' => filled($p['tahun_mulai'] ?? null) ? (int) $p['tahun_mulai'] : null,
            'tahun_selesai' => filled($p['tahun_selesai'] ?? null) ? (int) $p['tahun_selesai'] : null,
        ];
    }

    /** Bagian tampil untuk diisi pada periode (tahun, triwulan). Tanpa periode = selalu. */
    public function berlakuPada(?int $tahun, ?int $triwulan): bool
    {
        $p = $this->periodeBerlaku();

        if ($tahun !== null && (($p['tahun_mulai'] !== null && $tahun < $p['tahun_mulai']) || ($p['tahun_selesai'] !== null && $tahun > $p['tahun_selesai']))) {
            return false;
        }

        return $triwulan === null || $p['triwulan'] === [] || in_array($triwulan, $p['triwulan'], true);
    }

    /** "TW I & III, 2026–2027" — untuk daftar & pratinjau. */
    public function labelPeriodeBerlaku(): string
    {
        $p = $this->periodeBerlaku();
        $romawi = ['I', 'II', 'III', 'IV'];
        $tw = $p['triwulan'] === [] ? 'Semua triwulan' : 'TW '.implode(', ', array_map(fn ($t) => $romawi[$t - 1], $p['triwulan']));
        $tahun = match (true) {
            $p['tahun_mulai'] !== null && $p['tahun_selesai'] !== null => ', '.$p['tahun_mulai'].'–'.$p['tahun_selesai'],
            $p['tahun_mulai'] !== null => ', mulai '.$p['tahun_mulai'],
            $p['tahun_selesai'] !== null => ', sampai '.$p['tahun_selesai'],
            default => '',
        };

        return $tw.$tahun;
    }

    public const TAMPILAN_ISIAN = ['kartu' => 'Kartu per poin', 'tabel' => 'Tabel (satu baris per poin)'];

    public const TAMPILAN_BACA = ['daftar' => 'Daftar berbutir', 'tabel' => 'Tabel', 'paragraf' => 'Paragraf'];

    /** Bentuk formulir isian di menu Isian Bagian Kustom: kartu (bawaan) atau tabel. */
    public function tampilanIsian(): string
    {
        $v = (string) ($this->tampilan['isian'] ?? 'kartu');

        return array_key_exists($v, self::TAMPILAN_ISIAN) ? $v : 'kartu';
    }

    /** Bentuk penyajian isian tersimpan (baca-saja): daftar (bawaan), tabel, paragraf. */
    public function tampilanBaca(): string
    {
        $v = (string) ($this->tampilan['baca'] ?? 'daftar');

        return array_key_exists($v, self::TAMPILAN_BACA) ? $v : 'daftar';
    }

    /**
     * Ringkasan kolom angka untuk sekumpulan poin, mis. ["Nilai risiko" => "Jumlah: 12,5"].
     * Dihitung dari nilai tersimpan (kanonik) tanpa mengubahnya; tampil memakai format
     * kolomnya. Nilai yang bukan angka dilewati.
     *
     * @param  iterable<int, BagianKustomPoin>  $poin
     * @return array<int, array{label: string, jenis: string, nilai: string, jumlah_data: int}>
     */
    public function ringkasanNilai(iterable $poin): array
    {
        $hasil = [];

        foreach ($this->kolomList() as $kolom) {
            if (! JenisKolomBagianKustom::numerik($kolom['tipe']) || ($kolom['ringkasan'] ?? null) === null) {
                continue;
            }

            $angka = [];

            foreach ($poin as $p) {
                $v = FormatAngkaKolom::urai(trim((string) (($p->nilai_kolom ?? [])[$kolom['kunci']] ?? '')), null);

                if ($v !== null) {
                    $angka[] = $v;
                }
            }

            if ($angka === []) {
                continue;
            }

            $nilai = match ($kolom['ringkasan']) {
                'rata_rata' => self::bagiDesimal(self::jumlahDesimal($angka), count($angka)),
                'min' => (string) array_reduce($angka, fn ($a, $b) => $a === null || bccomp($b, $a, 12) < 0 ? $b : $a),
                'maks' => (string) array_reduce($angka, fn ($a, $b) => $a === null || bccomp($b, $a, 12) > 0 ? $b : $a),
                default => self::jumlahDesimal($angka),
            };

            $hasil[] = [
                'label' => $kolom['label'],
                'jenis' => self::RINGKASAN[$kolom['ringkasan']] ?? $kolom['ringkasan'],
                'nilai' => JenisKolomBagianKustom::tampil(FormatAngkaKolom::urai($nilai, null) ?? $nilai, $kolom),
                'jumlah_data' => count($angka),
            ];
        }

        return $hasil;
    }

    public const RINGKASAN = ['jumlah' => 'Jumlah', 'rata_rata' => 'Rata-rata', 'min' => 'Terkecil', 'maks' => 'Terbesar'];

    /** @param  array<int, string>  $angka  angka kanonik */
    private static function jumlahDesimal(array $angka): string
    {
        return array_reduce($angka, fn ($a, $b) => bcadd($a, $b, 12), '0');
    }

    private static function bagiDesimal(string $a, int $b): string
    {
        return bcdiv($a, (string) $b, 12);
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
        $katalog = collect($this->kolom ?? [])->contains(fn ($k) => filled($k['variabel'] ?? null)) ? VariabelKatalog::peta() : [];

        return collect($this->kolom ?? [])
            ->filter(fn ($k) => filled($k['kunci'] ?? null) && filled($k['label'] ?? null))
            ->map(fn ($k) => self::normalkanKolom(self::gabungKatalog($k, $katalog)))
            ->values()
            ->all();
    }

    /**
     * Kolom yang menautkan variabel katalog mengikuti definisi katalog (jenis, opsi,
     * format angka, batas). Label, kunci, wajib, subbagian, petunjuk tetap milik kolom.
     * Variabel yang tidak ditemukan = definisi lokal kolom tetap dipakai.
     *
     * @param  array<string, mixed>  $k
     * @param  array<string, array<string, mixed>>  $katalog
     * @return array<string, mixed>
     */
    public static function gabungKatalog(array $k, array $katalog): array
    {
        $kode = $k['variabel'] ?? null;

        if (! filled($kode) || ! isset($katalog[$kode])) {
            return $k;
        }

        $definisi = array_diff_key($katalog[$kode], ['aktif_katalog' => 1, 'nama_katalog' => 1]);

        return array_merge($k, $definisi);
    }

    /**
     * @param  array<string, mixed>  $k
     * @return array{kunci: string, label: string, tipe: string, opsi: array<int, string>, wajib: bool, aktif: bool, mulai_tahun: ?int, mulai_triwulan: ?int, satuan: ?string, desimal: ?int, pemisah: string, ribuan: bool}
     */
    public static function normalkanKolom(array $k): array
    {
        $tipe = array_key_exists($k['tipe'] ?? '', self::TIPE_KOLOM) ? $k['tipe'] : 'teks';
        $angka = JenisKolomBagianKustom::numerik($tipe);
        $mulaiTahun = filled($k['mulai_tahun'] ?? null) ? (int) $k['mulai_tahun'] : null;
        $mulaiTriwulan = filled($k['mulai_triwulan'] ?? null) ? (int) $k['mulai_triwulan'] : null;
        $berlakuLengkap = $mulaiTahun !== null && $mulaiTriwulan !== null;

        $teks = JenisKolomBagianKustom::teks($tipe);

        return [
            'kunci' => (string) $k['kunci'],
            'label' => (string) $k['label'],
            'tipe' => $tipe,
            'opsi' => in_array($tipe, ['pilihan', 'pilihan_ganda'], true) || ! empty($k['opsi']) ? array_values((array) ($k['opsi'] ?? [])) : [],
            'wajib' => (bool) ($k['wajib'] ?? false),
            'aktif' => (bool) ($k['aktif'] ?? true),
            'mulai_tahun' => $berlakuLengkap ? $mulaiTahun : null,
            'mulai_triwulan' => $berlakuLengkap ? max(1, min(4, $mulaiTriwulan)) : null,
            'satuan' => $angka && filled($k['satuan'] ?? null) ? trim((string) $k['satuan']) : ($tipe === 'persentase' ? '%' : null),
            'desimal' => $angka && isset($k['desimal']) && $k['desimal'] !== '' && $k['desimal'] !== null
                ? max(0, min(FormatAngkaKolom::DESIMAL_MAKS, (int) $k['desimal']))
                : null,
            'pemisah' => FormatAngkaKolom::pemisahDari($k),
            'ribuan' => (bool) ($k['ribuan'] ?? true),
            // Struktur & petunjuk (opsional; kolom lama tanpa kunci ini = perilaku lama).
            'subbagian' => filled($k['subbagian'] ?? null) ? (string) $k['subbagian'] : null,
            'petunjuk' => filled($k['petunjuk'] ?? null) ? trim((string) $k['petunjuk']) : null,
            'placeholder' => filled($k['placeholder'] ?? null) ? trim((string) $k['placeholder']) : null,
            // Validasi tambahan.
            'min' => $angka ? self::angkaAtauNull($k['min'] ?? null) : null,
            'maks' => $angka ? self::angkaAtauNull($k['maks'] ?? null) : null,
            'panjang_min' => $teks && filled($k['panjang_min'] ?? null) ? max(0, (int) $k['panjang_min']) : null,
            'panjang_maks' => $teks && filled($k['panjang_maks'] ?? null) ? max(1, (int) $k['panjang_maks']) : null,
            'pesan' => filled($k['pesan'] ?? null) ? trim((string) $k['pesan']) : null,
            'format_tanggal' => $tipe === 'tanggal' && array_key_exists((string) ($k['format_tanggal'] ?? ''), JenisKolomBagianKustom::FORMAT_TANGGAL)
                ? (string) $k['format_tanggal']
                : ($tipe === 'tanggal' ? 'panjang' : null),
            // Katalog variabel (kode) dan ringkasan nilai angka di tampilan tabel.
            'variabel' => filled($k['variabel'] ?? null) ? (string) $k['variabel'] : null,
            'ringkasan' => $angka && array_key_exists((string) ($k['ringkasan'] ?? ''), self::RINGKASAN) ? (string) $k['ringkasan'] : null,
            // Perhitungan otomatis, tabel, dan keterlihatan bersyarat (opsional).
            'rumus' => $tipe === 'hitung' && filled($k['rumus'] ?? null) ? trim((string) $k['rumus']) : null,
            'tabel_kolom' => $tipe === 'tabel' ? JenisKolomBagianKustom::normalkanSubkolom($k['tabel_kolom'] ?? []) : [],
            'baris_min' => $tipe === 'tabel' && filled($k['baris_min'] ?? null) ? max(0, min(JenisKolomBagianKustom::BARIS_TABEL_MAKS, (int) $k['baris_min'])) : null,
            'baris_maks' => $tipe === 'tabel' && filled($k['baris_maks'] ?? null) ? max(1, min(JenisKolomBagianKustom::BARIS_TABEL_MAKS, (int) $k['baris_maks'])) : null,
            'tampil_jika' => self::normalkanSyarat($k['tampil_jika'] ?? null),
        ];
    }

    public const OPERATOR_SYARAT = [
        'sama' => 'bernilai / memuat',
        'tidak_sama' => 'tidak bernilai',
        'terisi' => 'sudah diisi',
    ];

    /**
     * Syarat tampil kolom/subbagian: tampil hanya bila kolom lain (kunci) memenuhi
     * operator terhadap nilai. Null = selalu tampil.
     *
     * @return array{kunci: string, operator: string, nilai: string}|null
     */
    public static function normalkanSyarat(mixed $s): ?array
    {
        if (! is_array($s) || ! filled($s['kunci'] ?? null)) {
            return null;
        }

        $operator = array_key_exists((string) ($s['operator'] ?? ''), self::OPERATOR_SYARAT) ? (string) $s['operator'] : 'sama';
        $nilai = trim((string) ($s['nilai'] ?? ''));

        if ($operator !== 'terisi' && $nilai === '') {
            return null;
        }

        return ['kunci' => (string) $s['kunci'], 'operator' => $operator, 'nilai' => $operator === 'terisi' ? '' : $nilai];
    }

    /**
     * Syarat terpenuhi oleh nilai formulir. Pilihan ganda dicocokkan per pilihan.
     *
     * @param  array<string, mixed>  $nilaiForm
     */
    public static function syaratTerpenuhi(?array $syarat, array $nilaiForm): bool
    {
        if ($syarat === null) {
            return true;
        }

        $v = $nilaiForm[$syarat['kunci']] ?? '';

        if ($syarat['operator'] === 'terisi') {
            return JenisKolomBagianKustom::terisi($v);
        }

        $daftar = is_array($v) ? array_map(fn ($x) => trim((string) $x), $v) : JenisKolomBagianKustom::daftarPilihan(is_string($v) ? $v : (string) $v);
        $cocok = in_array($syarat['nilai'], $daftar, true);

        return $syarat['operator'] === 'sama' ? $cocok : ! $cocok;
    }

    /**
     * Kolom tampil (dan divalidasi) untuk nilai formulir ini: syarat kolomnya dan syarat
     * subbagiannya terpenuhi.
     *
     * @param  array<string, mixed>  $kolom  hasil kolomList()
     * @param  array<string, mixed>  $nilaiForm
     */
    public function kolomTerlihat(array $kolom, array $nilaiForm): bool
    {
        if (! self::syaratTerpenuhi($kolom['tampil_jika'] ?? null, $nilaiForm)) {
            return false;
        }

        $sub = ($kolom['subbagian'] ?? null) !== null ? collect($this->subbagianList())->firstWhere('kunci', $kolom['subbagian']) : null;

        return $sub === null || self::syaratTerpenuhi($sub['tampil_jika'] ?? null, $nilaiForm);
    }

    /**
     * SATU aturan validasi nilai kolom satu poin, dipakai Isian Kegiatan, Isian Bagian
     * Kustom, pengajuan, dan pratinjau: kolom berlaku & terlihat (wajib, jenis, rentang,
     * panjang, tabel) + subbagian wajib. Kolom yang disembunyikan syarat tidak diperiksa.
     *
     * @param  array<string, mixed>  $nilaiForm  kunci => nilai formulir
     * @return array<string, string>  kunci kolom => pesan galat
     */
    public function galatNilaiPoin(array $nilaiForm, ?int $tahun, ?int $triwulan): array
    {
        $galat = [];
        $berlaku = $this->kolomBerlaku($tahun, $triwulan);

        foreach ($berlaku as $kolom) {
            if ($kolom['tipe'] === 'hitung' || ! $this->kolomTerlihat($kolom, $nilaiForm)) {
                continue;
            }

            if (($pesan = self::pesanGalatKolom($kolom, $nilaiForm[$kolom['kunci']] ?? '')) !== null) {
                $galat[$kolom['kunci']] = $pesan;
            }
        }

        foreach ($this->subbagianList() as $sub) {
            if (! $sub['aktif'] || ! $sub['wajib'] || ! self::syaratTerpenuhi($sub['tampil_jika'], $nilaiForm)) {
                continue;
            }

            $isi = array_values(array_filter($berlaku, fn ($k) => $k['subbagian'] === $sub['kunci'] && $k['tipe'] !== 'hitung' && $this->kolomTerlihat($k, $nilaiForm)));

            if ($isi !== [] && ! collect($isi)->contains(fn ($k) => JenisKolomBagianKustom::terisi($nilaiForm[$k['kunci']] ?? ''))) {
                $galat[$isi[0]['kunci']] ??= 'Subbagian "'.$sub['nama'].'" wajib diisi (minimal satu isian).';
            }
        }

        return $galat;
    }

    /**
     * Hasil kolom perhitungan otomatis dari nilai kolom lain (formulir atau tersimpan).
     * Null bila belum bisa dihitung.
     *
     * @param  array<string, mixed>  $kolom  kolom hitung (hasil kolomList())
     * @param  array<string, mixed>  $nilai  kunci => nilai
     * @param  array<int, array<string, mixed>>  $semuaKolom
     */
    public static function nilaiHitung(array $kolom, array $nilai, array $semuaKolom): ?string
    {
        if (($kolom['rumus'] ?? null) === null) {
            return null;
        }

        $variabel = [];
        $peta = collect($semuaKolom)->keyBy('kunci');

        foreach (RumusBagianKustom::variabel($kolom['rumus']) as $kunci) {
            $def = $peta->get($kunci);
            $mentah = trim(is_array($nilai[$kunci] ?? null) ? '' : (string) ($nilai[$kunci] ?? ''));
            $pemisah = $def && FormatAngkaKolom::diatur(JenisKolomBagianKustom::sebagaiAngka($def)) ? FormatAngkaKolom::pemisahDari($def) : null;
            $variabel[$kunci] = $mentah === '' ? null : (FormatAngkaKolom::urai($mentah, $pemisah) ?? FormatAngkaKolom::urai($mentah, null));
        }

        return RumusBagianKustom::hitung($kolom['rumus'], $variabel);
    }

    /** Batas angka kanonik ("-12.5") dari pengaturan, null bila kosong/tidak sah. */
    private static function angkaAtauNull(mixed $nilai): ?string
    {
        if ($nilai === null || trim((string) $nilai) === '') {
            return null;
        }

        return FormatAngkaKolom::urai((string) $nilai, null);
    }

    /**
     * Subbagian dalam bentuk seragam, urut sesuai tampilan. Bagian tanpa subbagian
     * (semua bagian lama) mengembalikan daftar kosong.
     *
     * @return array<int, array{kunci: string, nama: string, deskripsi: ?string, aktif: bool}>
     */
    public function subbagianList(): array
    {
        return collect($this->subbagian ?? [])
            ->filter(fn ($s) => filled($s['kunci'] ?? null) && filled($s['nama'] ?? null))
            ->map(fn ($s) => self::normalkanSubbagian($s))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array{kunci: string, nama: string, deskripsi: ?string, aktif: bool}
     */
    public static function normalkanSubbagian(array $s): array
    {
        return [
            'kunci' => (string) $s['kunci'],
            'nama' => trim((string) $s['nama']),
            'deskripsi' => filled($s['deskripsi'] ?? null) ? trim((string) $s['deskripsi']) : null,
            'aktif' => (bool) ($s['aktif'] ?? true),
            // Opsional (subbagian lama tanpa kunci ini = tidak wajib, selalu tampil).
            'wajib' => (bool) ($s['wajib'] ?? false),
            'tampil_jika' => self::normalkanSyarat($s['tampil_jika'] ?? null),
        ];
    }

    /**
     * Kolom berlaku di bagian ini: berlaku pada periodenya DAN subbagiannya (bila ada)
     * masih aktif. Kolom yang menunjuk subbagian yang tidak ada dianggap kolom langsung.
     *
     * @param  array<string, mixed>  $kolom  hasil kolomList()
     */
    public function kolomBerlakuDi(array $kolom, ?int $tahun, ?int $triwulan): bool
    {
        if (! self::kolomBerlakuPada($kolom, $tahun, $triwulan)) {
            return false;
        }

        $sub = $kolom['subbagian'] ?? null;

        if ($sub === null) {
            return true;
        }

        $subbagian = collect($this->subbagianList())->firstWhere('kunci', $sub);

        return $subbagian === null || $subbagian['aktif'];
    }

    /**
     * Kolom berlaku untuk formulir, dikelompokkan: kolom langsung di bagian lebih dulu,
     * lalu tiap subbagian aktif sesuai urutannya. Kelompok tanpa kolom berlaku dilewati.
     *
     * @return array<int, array{subbagian: ?array{kunci: string, nama: string, deskripsi: ?string, aktif: bool}, kolom: array<int, array<string, mixed>>}>
     */
    public function kelompokKolomBerlaku(?int $tahun, ?int $triwulan): array
    {
        $berlaku = $this->kolomBerlaku($tahun, $triwulan);

        return self::kelompokkan($berlaku, $this->subbagianList());
    }

    /**
     * @param  array<int, array<string, mixed>>  $kolom
     * @param  array<int, array<string, mixed>>  $subbagian
     * @return array<int, array{subbagian: ?array<string, mixed>, kolom: array<int, array<string, mixed>>}>
     */
    public static function kelompokkan(array $kolom, array $subbagian): array
    {
        $kunciSub = array_column($subbagian, 'kunci');
        $langsung = array_values(array_filter($kolom, fn ($k) => ! in_array($k['subbagian'] ?? null, $kunciSub, true)));
        $hasil = $langsung !== [] ? [['subbagian' => null, 'kolom' => $langsung]] : [];

        foreach ($subbagian as $s) {
            $isi = array_values(array_filter($kolom, fn ($k) => ($k['subbagian'] ?? null) === $s['kunci']));

            if ($isi !== []) {
                $hasil[] = ['subbagian' => $s, 'kolom' => $isi];
            }
        }

        return $hasil;
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
        return array_values(array_filter($this->kolomList(), fn ($k) => $this->kolomBerlakuDi($k, $tahun, $triwulan)));
    }

    /** Triwulan (1-4) dari nomor bulan (1-12). */
    public static function triwulanDariBulan(int $bulan): int
    {
        return intdiv(max(1, min(12, $bulan)) - 1, 3) + 1;
    }

    /**
     * Pesan galat satu nilai kolom (wajib, jenis, rentang, panjang), null bila sah —
     * aturan yang sama untuk Isian Kegiatan dan Isian Bagian Kustom.
     *
     * @param  array<string, mixed>  $kolom  hasil kolomList()
     */
    public static function pesanGalatKolom(array $kolom, mixed $nilai): ?string
    {
        return JenisKolomBagianKustom::pesanGalat(self::normalkanKolom($kolom + ['kunci' => '', 'label' => 'Kolom']), $nilai);
    }

    /** Nilai formulir satu poin sudah berisi (teks atau daftar centang). */
    public static function nilaiFormTerisi(mixed $nilai): bool
    {
        return JenisKolomBagianKustom::terisi($nilai);
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
        $semua = $this->kolomList();
        $hitung = [];

        foreach ($semua as $kolom) {
            $kunci = $kolom['kunci'];

            if (! $this->kolomBerlakuDi($kolom, $tahun, $triwulan)) {
                $nilai = trim((string) ($tersimpan[$kunci] ?? ''));
            } elseif ($kolom['tipe'] === 'hitung') {
                // Dihitung setelah semua kolom lain diketahui nilainya.
                $hitung[] = $kolom;

                continue;
            } elseif (! $this->kolomTerlihat($kolom, $mentah)) {
                // Disembunyikan syarat (mis. "Tindak lanjut" hanya bila Status = Ya):
                // isian sebelumnya tidak lagi berlaku untuk jawaban saat ini.
                $nilai = '';
            } else {
                $nilai = JenisKolomBagianKustom::untukDisimpan($mentah[$kunci] ?? '', $kolom);
            }

            if ($nilai !== '') {
                $hasil[$kunci] = $nilai;
            }
        }

        foreach ($hitung as $kolom) {
            $nilai = $this->kolomTerlihat($kolom, $mentah) ? self::nilaiHitung($kolom, $hasil, $semua) : null;

            if ($nilai !== null) {
                $hasil[$kolom['kunci']] = $nilai;
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
     * Nilai kosong untuk poin baru di formulir ('' atau [] untuk pilihan ganda).
     *
     * @return array<string, string|array<int, string>>
     */
    public function nilaiKolomKosongForm(): array
    {
        return collect($this->kolomList())
            ->mapWithKeys(fn ($k) => [$k['kunci'] => JenisKolomBagianKustom::kosong($k)])
            ->all();
    }

    /**
     * Nilai tersimpan dalam bentuk yang diketik ulang di formulir (pilihan ganda =
     * daftar centang).
     *
     * @param  array<string, mixed>|null  $tersimpan
     * @return array<string, string|array<int, string>>
     */
    public function nilaiKolomUntukForm(?array $tersimpan): array
    {
        return collect($this->kolomList())
            ->mapWithKeys(fn ($k) => [$k['kunci'] => JenisKolomBagianKustom::untukForm((string) ($tersimpan[$k['kunci']] ?? ''), $k)])
            ->all();
    }

    /**
     * Nilai semua kolom dalam format tampilan (satuan, desimal, pemisah, tanggal,
     * pilihan ganda) — dipakai notula.
     *
     * @param  array<string, mixed>|null  $tersimpan
     * @return array<string, string>
     */
    public function nilaiKolomTampil(?array $tersimpan): array
    {
        return collect($this->kolomList())
            ->mapWithKeys(fn ($k) => [$k['kunci'] => JenisKolomBagianKustom::tampil((string) ($tersimpan[$k['kunci']] ?? ''), $k)])
            ->all();
    }

    /**
     * Teks tampilan satu nilai kolom (dipakai Blade riwayat isian & Verifikasi).
     *
     * @param  array<string, mixed>  $kolom  hasil kolomList()
     */
    public static function nilaiTampil(array $kolom, mixed $nilai): string
    {
        return JenisKolomBagianKustom::tampil(is_array($nilai) ? (string) json_encode($nilai) : (string) $nilai, self::normalkanKolom($kolom + ['kunci' => '', 'label' => 'Kolom']));
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

        if ($kolom['tipe'] === 'hitung' && ($kolom['rumus'] ?? null) !== null) {
            $bagian[] = 'rumus '.$kolom['rumus'];
        }

        if ($kolom['tipe'] === 'tabel') {
            $bagian[] = count($kolom['tabel_kolom'] ?? []).' kolom tabel: '.collect($kolom['tabel_kolom'] ?? [])->pluck('label')->implode(', ');

            if (($kolom['baris_min'] ?? null) !== null || ($kolom['baris_maks'] ?? null) !== null) {
                $bagian[] = 'baris '.($kolom['baris_min'] ?? 0).'–'.($kolom['baris_maks'] ?? '…');
            }
        }

        if (($kolom['tampil_jika'] ?? null) !== null) {
            $s = $kolom['tampil_jika'];
            $bagian[] = 'tampil bila {'.$s['kunci'].'} '.(self::OPERATOR_SYARAT[$s['operator']] ?? $s['operator']).($s['operator'] === 'terisi' ? '' : ' "'.$s['nilai'].'"');
        }

        if (JenisKolomBagianKustom::numerik($kolom['tipe'])) {
            $bagian[] = $kolom['desimal'] === null
                ? 'tampil apa adanya'
                : $kolom['desimal'].' desimal, '.($kolom['pemisah'] === FormatAngkaKolom::PEMISAH_TITIK ? 'titik' : 'koma').($kolom['ribuan'] ? ' + ribuan' : '');

            if ($kolom['satuan'] !== null) {
                $bagian[] = 'satuan '.$kolom['satuan'];
            }

            if (($kolom['min'] ?? null) !== null || ($kolom['maks'] ?? null) !== null) {
                $bagian[] = 'rentang '.($kolom['min'] ?? '…').'–'.($kolom['maks'] ?? '…');
            }
        }

        if (($kolom['panjang_min'] ?? null) !== null || ($kolom['panjang_maks'] ?? null) !== null) {
            $bagian[] = 'panjang '.($kolom['panjang_min'] ?? 0).'–'.($kolom['panjang_maks'] ?? '…').' karakter';
        }

        if (($kolom['tipe'] ?? '') === 'tanggal') {
            $bagian[] = 'format '.(JenisKolomBagianKustom::FORMAT_TANGGAL[$kolom['format_tanggal'] ?? 'panjang'] ?? '');
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
            'kode' => $this->kode,
            'nama' => $this->nama,
            'nama_folder' => $this->nama_folder,
            'deskripsi' => $this->deskripsi,
            'peran' => array_values($this->peran ?? []),
            'peran_pembaca' => array_values($this->peran_pembaca ?? []),
            'hak_aksi' => $this->matriksAksi(),
            'penempatan' => array_values($this->penempatan ?? []),
            'label_uraian' => $this->label_uraian,
            'frekuensi_wajib' => $this->frekuensi_wajib,
            'bukti_wajib' => (bool) $this->bukti_wajib,
            'aturan_bukti' => $this->aturan_bukti,
            'tampilan' => $this->tampilan,
            'masuk_notula' => (bool) $this->masuk_notula,
            'judul_notula' => $this->judul_notula,
            'format_notula' => $this->format_notula,
            'alur' => $this->alur,
            'periode_berlaku' => $this->periode_berlaku,
            'aktif' => (bool) $this->aktif,
            'subbagian' => $this->subbagianList(),
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
