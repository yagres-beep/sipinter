<?php

namespace App\Support;

use App\Models\MasterIku;
use App\Models\User;
use App\Models\UserTim;

/**
 * Sumber data untuk kolom berjenis "referensi": pengisi memilih data yang SUDAH ada di
 * SIPINTER (IKU, tim, pengguna) alih-alih mengetik ulang.
 *
 * Yang disimpan adalah identitas stabil (id IKU / id pengguna / nama tim), bukan teks
 * labelnya — label dibaca ulang saat ditampilkan, jadi mengganti nama IKU atau
 * pengguna tidak memutus isian lama. Data acuan yang kemudian dihapus tetap tersimpan
 * apa adanya dan ditampilkan sebagai "data tidak ditemukan".
 *
 * Daftar pilihan hanya memuat informasi yang memang sudah terlihat di layar SIPINTER
 * (kode & indikator IKU, nama tim, nama pengguna) — tanpa email, NIP, atau nomor telepon.
 */
final class ReferensiBagianKustom
{
    public const SUMBER = [
        'iku' => 'IKU (Master IKU)',
        'tim' => 'Tim kerja',
        'pengguna' => 'Pengguna SIPINTER',
    ];

    public const SUMBER_BAWAAN = 'iku';

    public static function sumberSah(?string $sumber): string
    {
        return array_key_exists((string) $sumber, self::SUMBER) ? (string) $sumber : self::SUMBER_BAWAAN;
    }

    /**
     * Pilihan satu sumber: identitas tersimpan => label tampilan. Dihitung sekali per
     * permintaan (once) supaya tampilan daftar panjang tidak mengulang query.
     *
     * @return array<string, string>
     */
    public static function opsi(string $sumber): array
    {
        $sumber = self::sumberSah($sumber);

        return once(function () use ($sumber) {
            return match ($sumber) {
                'iku' => MasterIku::query()->orderBy('kode')->get(['id', 'kode', 'indikator'])
                    ->mapWithKeys(fn (MasterIku $i) => [(string) $i->id => trim($i->kode.' — '.$i->indikator)])->all(),
                'tim' => UserTim::query()->select('tim')->distinct()->orderBy('tim')->pluck('tim')
                    ->filter(fn ($t) => filled($t))
                    ->mapWithKeys(fn ($t) => [(string) $t => (string) $t])->all(),
                'pengguna' => User::query()->orderBy('nama')->get(['id', 'nama'])
                    ->mapWithKeys(fn (User $u) => [(string) $u->id => (string) $u->nama])->all(),
            };
        });
    }

    public static function sah(string $sumber, string $nilai): bool
    {
        return array_key_exists($nilai, self::opsi($sumber));
    }

    /** Label tampilan; identitas yang sudah tidak ada ditampilkan apa adanya dengan keterangan. */
    public static function label(string $sumber, string $nilai): string
    {
        $nilai = trim($nilai);

        if ($nilai === '') {
            return '';
        }

        return self::opsi($sumber)[$nilai] ?? "{$nilai} (data acuan tidak ditemukan)";
    }
}
