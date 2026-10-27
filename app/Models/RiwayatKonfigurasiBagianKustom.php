<?php

namespace App\Models;

use App\Support\MenuBagianKustom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu catatan perubahan definisi Bagian Kustom. Hanya rekam jejak — tidak membatasi
 * dan tidak memengaruhi isian maupun perhitungan apa pun.
 */
class RiwayatKonfigurasiBagianKustom extends Model
{
    protected $table = 'riwayat_konfigurasi_bagian_kustom';

    public const UPDATED_AT = null;

    public const AKSI = [
        'buat' => 'Dibuat',
        'salin' => 'Dibuat dari salinan',
        'ubah' => 'Diubah',
        'aktifkan' => 'Diaktifkan',
        'nonaktifkan' => 'Dinonaktifkan',
        'urutan' => 'Urutan diubah',
        'hapus' => 'Dihapus',
    ];

    private const LABEL_FIELD = [
        'nama' => 'nama',
        'deskripsi' => 'deskripsi',
        'peran' => 'peran pengisi',
        'penempatan' => 'menu',
        'label_uraian' => 'label uraian',
        'frekuensi_wajib' => 'kewajiban isi',
        'bukti_wajib' => 'bukti wajib',
        'aturan_bukti' => 'aturan bukti dukung',
        'peran_pembaca' => 'peran pembaca',
        'tampilan' => 'bentuk tampilan',
        'masuk_notula' => 'masuk notula',
        'judul_notula' => 'judul notula',
        'format_notula' => 'format notula',
        'aktif' => 'status aktif',
    ];

    protected $fillable = [
        'bagian_kustom_id',
        'nama_bagian',
        'user_id',
        'nama_pengguna',
        'aksi',
        'ringkasan',
        'sebelum',
        'sesudah',
    ];

    protected function casts(): array
    {
        return [
            'sebelum' => 'array',
            'sesudah' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function labelAksi(): string
    {
        return self::AKSI[$this->aksi] ?? $this->aksi;
    }

    /**
     * Catat satu perubahan. $ringkasan kosong = dibentuk otomatis dari selisih potret.
     *
     * @param  array<string, mixed>|null  $sebelum
     * @param  array<string, mixed>|null  $sesudah
     */
    public static function catat(?BagianKustom $bagian, string $namaBagian, string $aksi, ?array $sebelum, ?array $sesudah, ?string $ringkasan = null): self
    {
        return static::create([
            'bagian_kustom_id' => $bagian?->exists ? $bagian->id : null,
            'nama_bagian' => $namaBagian,
            'user_id' => auth()->id(),
            'nama_pengguna' => auth()->user()?->nama,
            'aksi' => $aksi,
            'ringkasan' => $ringkasan ?? (self::ringkasSelisih($sebelum ?? [], $sesudah ?? []) ?: (self::AKSI[$aksi] ?? $aksi)),
            'sebelum' => $sebelum,
            'sesudah' => $sesudah,
        ]);
    }

    /**
     * Uraian singkat perubahan antara dua potret definisi (BagianKustom::potretDefinisi()).
     *
     * @param  array<string, mixed>  $sebelum
     * @param  array<string, mixed>  $sesudah
     */
    public static function ringkasSelisih(array $sebelum, array $sesudah): string
    {
        $bagian = [];

        foreach (self::LABEL_FIELD as $field => $label) {
            $lama = $sebelum[$field] ?? null;
            $baru = $sesudah[$field] ?? null;

            if ($lama == $baru) {
                continue;
            }

            $bagian[] = match ($field) {
                'nama' => "nama \"{$lama}\" → \"{$baru}\"",
                'peran' => 'peran → '.self::daftar($baru, fn ($p) => BagianKustom::NAMA_PERAN[$p] ?? $p),
                'penempatan' => 'menu → '.self::daftar($baru, fn ($m) => MenuBagianKustom::label($m)),
                'peran_pembaca' => 'peran pembaca → '.self::daftar($baru, fn ($p) => BagianKustom::NAMA_PERAN[$p] ?? $p),
                'aturan_bukti' => 'aturan bukti dukung → '.\App\Support\AturanBuktiBagianKustom::ringkasan(\App\Support\AturanBuktiBagianKustom::normalkan(is_array($baru) ? $baru : null, (bool) ($sesudah['bukti_wajib'] ?? false))),
                default => $label.' diubah',
            };
        }

        $subLama = collect($sebelum['subbagian'] ?? [])->keyBy('kunci');
        $subBaru = collect($sesudah['subbagian'] ?? [])->keyBy('kunci');

        foreach ($subBaru as $kunci => $s) {
            $l = $subLama->get($kunci);

            if ($l === null) {
                $bagian[] = "subbagian \"{$s['nama']}\" ditambah";
            } elseif (($l['aktif'] ?? true) !== ($s['aktif'] ?? true)) {
                $bagian[] = "subbagian \"{$s['nama']}\" ".(($s['aktif'] ?? true) ? 'diaktifkan lagi' : 'dinonaktifkan');
            } elseif (($l['nama'] ?? null) !== ($s['nama'] ?? null) || ($l['deskripsi'] ?? null) !== ($s['deskripsi'] ?? null)) {
                $bagian[] = "subbagian \"{$s['nama']}\" diubah";
            }
        }

        foreach ($subLama as $kunci => $l) {
            if (! $subBaru->has($kunci)) {
                $bagian[] = "subbagian \"{$l['nama']}\" dihapus";
            }
        }

        if ($subLama->keys()->intersect($subBaru->keys())->values()->all() !== $subBaru->keys()->intersect($subLama->keys())->values()->all()) {
            $bagian[] = 'urutan subbagian diubah';
        }

        $kolomLama = collect($sebelum['kolom'] ?? [])->keyBy('kunci');
        $kolomBaru = collect($sesudah['kolom'] ?? [])->keyBy('kunci');

        foreach ($kolomBaru as $kunci => $k) {
            $l = $kolomLama->get($kunci);

            if ($l === null) {
                $bagian[] = "kolom \"{$k['label']}\" ditambah".(($k['mulai_tahun'] ?? null) ? ' (mulai TW '.(['I', 'II', 'III', 'IV'][$k['mulai_triwulan'] - 1] ?? $k['mulai_triwulan']).' '.$k['mulai_tahun'].')' : '');

                continue;
            }

            if (($l['aktif'] ?? true) && ! ($k['aktif'] ?? true)) {
                $bagian[] = "kolom \"{$k['label']}\" dinonaktifkan";
            } elseif (! ($l['aktif'] ?? true) && ($k['aktif'] ?? true)) {
                $bagian[] = "kolom \"{$k['label']}\" diaktifkan lagi";
            }

            $berubah = collect(['label', 'tipe', 'opsi', 'wajib', 'mulai_tahun', 'mulai_triwulan', 'satuan', 'desimal', 'pemisah', 'ribuan',
                'subbagian', 'petunjuk', 'placeholder', 'min', 'maks', 'panjang_min', 'panjang_maks', 'pesan', 'format_tanggal', 'variabel', 'ringkasan'])
                ->filter(fn ($f) => ($l[$f] ?? null) != ($k[$f] ?? null))
                ->values()
                ->all();

            if ($berubah !== []) {
                $bagian[] = "kolom \"{$k['label']}\" diubah (".implode(', ', $berubah).')';
            }
        }

        foreach ($kolomLama as $kunci => $l) {
            if (! $kolomBaru->has($kunci)) {
                $bagian[] = "kolom \"{$l['label']}\" dihapus";
            }
        }

        $lamaUrut = $kolomLama->keys()->intersect($kolomBaru->keys())->values()->all();
        $baruUrut = $kolomBaru->keys()->intersect($kolomLama->keys())->values()->all();

        if ($lamaUrut !== $baruUrut) {
            $bagian[] = 'urutan kolom diubah';
        }

        return implode('; ', $bagian);
    }

    private static function daftar(mixed $nilai, callable $label): string
    {
        return collect((array) $nilai)->map($label)->implode(', ') ?: '—';
    }
}
