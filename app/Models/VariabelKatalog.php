<?php

namespace App\Models;

use App\Support\FormatAngkaKolom;
use App\Support\JenisKolomBagianKustom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Variabel yang bisa dipakai ulang oleh kolom Bagian Kustom mana pun (mis.
 * "persentase_tindak_lanjut"). Kolom yang menautkan variabel mengikuti jenis, satuan,
 * format angka, opsi, dan aturan validasinya; label kolom tetap boleh berbeda per
 * bagian. Identitas = kode (tetap, tidak bisa diubah setelah dibuat).
 *
 * Nilai tetap disimpan per poin di bagian_kustom_poin.nilai_kolom — katalog hanya
 * menyatukan DEFINISI, jadi tidak ada nilai yang digandakan.
 */
class VariabelKatalog extends Model
{
    protected $table = 'variabel_katalog';

    public const POLA_KODE = '/^[a-z][a-z0-9_]{1,59}$/';

    /** Sumber data variabel. Saat ini hanya isian pengguna yang didukung penuh. */
    public const SUMBER = [
        'isian' => 'Diisi pengguna di formulir Bagian Kustom',
    ];

    protected $fillable = [
        'kode', 'nama', 'tipe', 'satuan', 'desimal', 'pemisah', 'ribuan', 'min', 'maks',
        'panjang_min', 'panjang_maks', 'opsi', 'sumber', 'keterangan', 'aktif',
        'dibuat_oleh', 'diubah_oleh',
    ];

    protected function casts(): array
    {
        return [
            'opsi' => 'array',
            'ribuan' => 'boolean',
            'aktif' => 'boolean',
            'desimal' => 'integer',
            'panjang_min' => 'integer',
            'panjang_maks' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::lupakanCache());
    }

    /** Kunci memo per request (di container aplikasi, bukan statik, supaya tidak bocor antarrequest/tes). */
    protected const MEMO = 'variabel-katalog.memo';

    /**
     * Definisi semua variabel (aktif & nonaktif), dikunci pada kode.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function peta(): array
    {
        if (app()->bound(self::MEMO)) {
            return app(self::MEMO);
        }

        try {
            $peta = Cache::remember('variabel-katalog.peta', 3600, fn () => static::query()->get()
                ->mapWithKeys(fn (self $v) => [$v->kode => $v->definisiKolom() + ['aktif_katalog' => (bool) $v->aktif, 'nama_katalog' => $v->nama]])
                ->all());
        } catch (\Throwable) {
            // Tabel belum ada (mis. di tengah migrasi) — kolom memakai definisi lokalnya.
            $peta = [];
        }

        app()->instance(self::MEMO, $peta);

        return $peta;
    }

    public static function lupakanCache(): void
    {
        app()->forgetInstance(self::MEMO);
        Cache::forget('variabel-katalog.peta');
    }

    /**
     * Atribut kolom yang mengikuti katalog (menimpa definisi lokal kolom).
     *
     * @return array<string, mixed>
     */
    public function definisiKolom(): array
    {
        $tipe = JenisKolomBagianKustom::adaTipe((string) $this->tipe) ? $this->tipe : 'teks';

        return [
            'tipe' => $tipe,
            'opsi' => in_array($tipe, ['pilihan', 'pilihan_ganda'], true) ? array_values((array) ($this->opsi ?? [])) : [],
            'satuan' => $this->satuan,
            'desimal' => $this->desimal,
            'pemisah' => $this->pemisah ?: FormatAngkaKolom::PEMISAH_KOMA,
            'ribuan' => (bool) $this->ribuan,
            'min' => $this->min,
            'maks' => $this->maks,
            'panjang_min' => $this->panjang_min,
            'panjang_maks' => $this->panjang_maks,
        ];
    }

    /**
     * Kolom Bagian Kustom yang menautkan variabel ini: [bagian, kolom].
     *
     * @return array<int, array{bagian: BagianKustom, kolom: array<string, mixed>}>
     */
    public function pemakaian(): array
    {
        $hasil = [];

        foreach (BagianKustom::orderBy('urutan')->orderBy('id')->get() as $bagian) {
            foreach ($bagian->kolom ?? [] as $kolom) {
                if (($kolom['variabel'] ?? null) === $this->kode) {
                    $hasil[] = ['bagian' => $bagian, 'kolom' => $kolom];
                }
            }
        }

        return $hasil;
    }

    /** "2 desimal, koma · satuan persen" — keterangan singkat untuk layar. */
    public function ringkasan(): string
    {
        return BagianKustom::ringkasanKolom(BagianKustom::normalkanKolom(['kunci' => $this->kode, 'label' => $this->nama] + $this->definisiKolom()))
            ?: (JenisKolomBagianKustom::TIPE[$this->tipe] ?? $this->tipe);
    }
}
