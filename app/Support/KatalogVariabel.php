<?php

namespace App\Support;

use App\Models\BagianKustom;
use App\Models\VariabelKatalog;
use Illuminate\Support\Collection;

/**
 * Kamus variabel terpadu untuk halaman Katalog Variabel dan pemilih variabel:
 * 1. variabel sistem & turunan & penanda template — RegistriVariabel (kode),
 * 2. definisi bersama buatan Tim SAKIP — tabel variabel_katalog,
 * 3. variabel kolom Bagian Kustom — dibentuk dari konfigurasi setiap bagian.
 *
 * Semua entri berbentuk array yang sama (lihat RegistriVariabel::v()). Hanya membaca —
 * tidak pernah menulis ke database. Dua query untuk seluruh katalog (bagian & definisi).
 */
final class KatalogVariabel
{
    /** @return Collection<int, array<string, mixed>> */
    public static function semua(): Collection
    {
        $pemakaian = [];
        $kolom = [];

        foreach (BagianKustom::orderBy('urutan')->orderBy('id')->get() as $bagian) {
            foreach ($bagian->kolomList() as $k) {
                $aktif = (bool) $bagian->aktif && (bool) ($k['aktif'] ?? true);

                if (filled($k['variabel'] ?? null)) {
                    $pemakaian[$k['variabel']][] = ['bagian' => $bagian->nama, 'kolom' => $k['label'], 'aktif' => $aktif];
                }

                $kolom[] = self::entriKolom($bagian, $k, $aktif);
            }
        }

        $definisi = VariabelKatalog::orderBy('nama')->get()
            ->map(fn (VariabelKatalog $v) => self::entriDefinisi($v, $pemakaian[$v->kode] ?? []));

        return collect(RegistriVariabel::semua())->values()->concat($definisi)->concat($kolom)->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $semua
     * @param  array{cari?: string, kategori?: string, jenis?: string, tipe?: string, konteks?: string, status?: string}  $filter
     * @return Collection<int, array<string, mixed>>
     */
    public static function saring(Collection $semua, array $filter): Collection
    {
        $cari = mb_strtolower(trim((string) ($filter['cari'] ?? '')));

        return $semua->filter(function (array $v) use ($filter, $cari) {
            if ($cari !== '' && ! str_contains(mb_strtolower($v['kode'].' '.$v['nama'].' '.$v['deskripsi'].' '.($v['bagian'] ?? '')), $cari)) {
                return false;
            }

            foreach (['kategori', 'jenis', 'tipe', 'status'] as $k) {
                if (filled($filter[$k] ?? null) && $v[$k] !== $filter[$k]) {
                    return false;
                }
            }

            return ! filled($filter['konteks'] ?? null) || in_array($filter['konteks'], $v['konteks'], true);
        })->values();
    }

    /**
     * Kelompokkan per kategori, urut sesuai RegistriVariabel::KATEGORI.
     *
     * @param  Collection<int, array<string, mixed>>  $daftar
     * @return array<string, list<array<string, mixed>>>
     */
    public static function kelompokkan(Collection $daftar): array
    {
        $hasil = [];

        foreach (array_keys(RegistriVariabel::KATEGORI) as $kategori) {
            $isi = $daftar->where('kategori', $kategori)->values()->all();

            if ($isi !== []) {
                $hasil[$kategori] = $isi;
            }
        }

        return $hasil;
    }

    /**
     * @param  array<string, mixed>  $k
     * @return array<string, mixed>
     */
    private static function entriKolom(BagianKustom $bagian, array $k, bool $aktif): array
    {
        $definisi = filled($k['variabel'] ?? null) ? 'Mengikuti definisi bersama "'.$k['variabel'].'". ' : '';

        return [
            'id' => 'kolom:'.$bagian->id.':'.$k['kunci'],
            'kode' => $k['kunci'],
            'nama' => $k['label'],
            'kategori' => 'bagian_kustom',
            'jenis' => 'kustom',
            'tipe' => self::tipeKolom($k['tipe']),
            'konteks' => [RegistriVariabel::KONTEKS_FORMAT_BAGIAN_KUSTOM],
            'lingkup' => null,
            'deskripsi' => 'Isian kolom "'.$k['label'].'" ('.TemplateBagianKustom::labelTipe($k['tipe']).') di bagian "'.$bagian->nama.'".',
            'sumber' => 'bagian_kustom_poin.nilai_kolom["'.$k['kunci'].'"] — diisi pengguna di formulir bagian ini.',
            'aturan' => $definisi.'Ditampilkan sesuai pengaturan kolom (format angka, satuan, format tanggal).',
            'contoh' => TemplateBagianKustom::contohKolom($k),
            'kosong' => 'Kosong bila kolom tidak diisi; "0" tetap dicetak "0". Tanda kurung kosong " ()" dibuang otomatis.',
            'catatan' => 'Hanya tersedia di format notula bagian "'.$bagian->nama.'". Kode tetap walau label kolom diganti.',
            'status' => $aktif ? 'aktif' : 'usang',
            'pengganti' => null,
            'diproses' => true,
            'penutup' => null,
            'bagian' => $bagian->nama,
            'bagian_id' => $bagian->id,
            'pemakaian' => [],
        ];
    }

    /**
     * @param  list<array{bagian: string, kolom: string, aktif: bool}>  $pemakaian
     * @return array<string, mixed>
     */
    private static function entriDefinisi(VariabelKatalog $v, array $pemakaian): array
    {
        $kategori = array_key_exists((string) $v->kategori, RegistriVariabel::KATEGORI) ? $v->kategori : VariabelKatalog::KATEGORI_BAWAAN;
        $kolomContoh = BagianKustom::normalkanKolom(['kunci' => $v->kode, 'label' => $v->nama] + $v->definisiKolom());

        return [
            'id' => 'definisi:'.$v->id,
            'kode' => $v->kode,
            'nama' => $v->nama,
            'kategori' => $kategori,
            'jenis' => 'definisi',
            'tipe' => self::tipeKolom((string) $v->tipe),
            'konteks' => [RegistriVariabel::KONTEKS_KOLOM_BAGIAN_KUSTOM],
            'lingkup' => null,
            'deskripsi' => $v->keterangan ?: 'Definisi bersama untuk kolom isian Bagian Kustom.',
            'sumber' => VariabelKatalog::SUMBER[$v->sumber] ?? VariabelKatalog::SUMBER['isian'],
            'aturan' => 'Kolom yang menautkan variabel ini mengikuti jenis, pilihan, format, dan batasnya: '.$v->ringkasan().'. Nilai tetap disimpan per isian bagian.',
            'contoh' => filled($v->contoh) ? $v->contoh : TemplateBagianKustom::contohKolom($kolomContoh),
            'kosong' => 'Mengikuti aturan wajib kolom yang menautkannya.',
            'catatan' => $v->catatan,
            'status' => $v->statusKatalog(count($pemakaian)),
            'pengganti' => $v->pengganti_kode,
            'diproses' => true,
            'penutup' => null,
            'bagian' => null,
            'model_id' => $v->id,
            'aktif_model' => (bool) $v->aktif,
            'pemakaian' => $pemakaian,
        ];
    }

    /** Jenis kolom Bagian Kustom → tipe data katalog. */
    private static function tipeKolom(string $tipe): string
    {
        return match ($tipe) {
            'angka', 'hitung' => 'angka',
            'persentase' => 'persen',
            'tanggal' => 'tanggal',
            'tautan' => 'tautan',
            'tabel', 'pilihan_ganda' => 'daftar',
            'teks_panjang' => 'teks_panjang',
            default => 'teks',
        };
    }
}
