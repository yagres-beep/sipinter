<?php

namespace App\Support;

use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\LogAktivitas;
use App\Models\MasterIku;
use App\Models\Periode;

/**
 * Penyusun catatan log aktivitas untuk isian Bagian Kustom: poin, bukti dukung, dan
 * penandaan verifikasinya. Semua keterangan dibentuk dari model yang sudah divalidasi
 * dan tersimpan di server — tidak pernah dari teks kiriman browser.
 *
 * Perubahan DEFINISI bagian (nama, kolom, menu, dst.) tidak dicatat di sini, melainkan
 * di riwayat_konfigurasi_bagian_kustom (lihat RiwayatKonfigurasiBagianKustom), supaya
 * tidak ada catatan ganda.
 */
final class JejakAktivitasBagianKustom
{
    /**
     * Potret isi poin yang diaudit (bukan seluruh kolom tabel).
     *
     * @return array{iku_id: int, teks: string, nilai_kolom: array<string, mixed>}
     */
    public static function potret(BagianKustomPoin $poin): array
    {
        return [
            'iku_id' => (int) $poin->iku_id,
            'teks' => (string) $poin->teks,
            'nilai_kolom' => (array) ($poin->nilai_kolom ?? []),
        ];
    }

    public static function poinDibuat(BagianKustom $bagian, BagianKustomPoin $poin, ?string $korelasi = null): void
    {
        LogAktivitas::catat(
            'bagian_kustom_poin.dibuat',
            LogAktivitas::pelaku().' menambahkan poin isian "'.$bagian->nama.'" pada '.self::lokasi($poin).'.',
            self::konteks($bagian, $poin, $korelasi, ['sesudah' => self::potret($poin)]),
        );
    }

    /**
     * Poin baru yang melanjutkan poin periode sebelumnya (relasi antarperiode). Poin
     * asal tidak berubah; yang dicatat adalah asal, akar rangkaian, dan isi awal.
     */
    public static function poinDilanjutkan(BagianKustom $bagian, BagianKustomPoin $poin, BagianKustomPoin $sumber, ?string $korelasi = null): void
    {
        LogAktivitas::catat(
            'bagian_kustom_poin.dilanjutkan',
            LogAktivitas::pelaku().' melanjutkan poin isian "'.$bagian->nama.'" dari '.LogAktivitas::labelPeriode(Periode::find($sumber->periode_id))
                .' ke '.self::lokasi($poin).'. Poin asal dan bukti dukungnya tidak diubah.',
            self::konteks($bagian, $poin, $korelasi, [
                'sesudah' => self::potret($poin) + ['lanjutan_dari_id' => $sumber->id, 'akar_id' => $poin->akar_id],
            ]),
        );
    }

    /**
     * Bukti dukung dihapus pengisinya sendiri di menu Isian Bagian Kustom. Ditulis di
     * transaksi yang sama dengan penghapusan baris; salinan Drive dipindah ke Sampah
     * (bisa dipulihkan), bukan dihapus permanen.
     */
    public static function buktiDihapusPengisi(BagianKustom $bagian, BagianKustomPoin $poin, Berkas $berkas): void
    {
        LogAktivitas::catat(
            'bukti.dihapus',
            LogAktivitas::pelaku().' menghapus bukti dukung "'.$berkas->nama_file.'" dari poin isian "'.$bagian->nama.'", '.self::lokasi($poin).'. '
                .($berkas->drive_file_id ? 'Salinan di Google Drive dipindah ke Sampah (masih bisa dipulihkan dari Drive).' : 'Berkas ini belum punya salinan di Google Drive.'),
            self::konteksBerkas($bagian, $poin, $berkas, null, [
                'sebelum' => ['berkas_id' => $berkas->id, 'kode' => $berkas->kode, 'nama_file' => $berkas->nama_file, 'drive_file_id' => $berkas->drive_file_id],
            ]),
        );
    }

    /**
     * Perubahan katalog variabel (dibuat/diubah/aktif/nonaktif).
     *
     * @param  array<string, mixed>  $perubahan
     */
    public static function variabel(string $aksi, \App\Models\VariabelKatalog $variabel, string $uraian, array $perubahan = []): void
    {
        LogAktivitas::catat(
            'variabel_katalog.'.$aksi,
            LogAktivitas::pelaku().' '.$uraian.' variabel katalog "'.$variabel->nama.'" ('.$variabel->kode.').',
            ['objek_tipe' => LogAktivitas::OBJEK_VARIABEL, 'objek_id' => $variabel->id, 'perubahan' => $perubahan],
        );
    }

    /**
     * Catat perubahan poin — TIDAK mencatat apa pun bila isinya sama persis (simpan
     * ulang tanpa perubahan tidak menghasilkan catatan ganda).
     *
     * @param  array<string, mixed>  $sebelum  hasil potret() sebelum diubah
     */
    public static function poinDiubah(BagianKustom $bagian, BagianKustomPoin $poin, array $sebelum, ?string $korelasi = null): void
    {
        $selisih = LogAktivitas::selisih($sebelum, self::potret($poin));

        if ($selisih === []) {
            return;
        }

        $uraian = [];

        if (isset($selisih['iku_id'])) {
            $uraian[] = 'mengganti IKU dari '.LogAktivitas::labelIku(MasterIku::find($selisih['iku_id']['sebelum']))
                .' menjadi '.LogAktivitas::labelIku(MasterIku::find($selisih['iku_id']['sesudah']));
        }

        if (isset($selisih['teks'])) {
            $uraian[] = 'mengubah '.mb_strtolower($bagian->labelUraianEfektif());
        }

        if (isset($selisih['nilai_kolom'])) {
            $uraian[] = 'mengubah '.self::ringkasKolom($bagian, $selisih['nilai_kolom']['sebelum'] ?? [], $selisih['nilai_kolom']['sesudah'] ?? []);
        }

        $catatanStatus = $poin->status_verifikasi === 'ditolak' ? ' Poin ini sebelumnya ditandai "Tidak Sesuai" oleh Tim SAKIP.' : '';

        LogAktivitas::catat(
            'bagian_kustom_poin.diubah',
            LogAktivitas::pelaku().' '.implode(', ', $uraian).' pada poin isian "'.$bagian->nama.'", '.self::lokasi($poin).'.'.$catatanStatus,
            self::konteks($bagian, $poin, $korelasi, $selisih),
        );
    }

    public static function poinDihapus(BagianKustom $bagian, BagianKustomPoin $poin, ?string $korelasi = null): void
    {
        LogAktivitas::catat(
            'bagian_kustom_poin.dihapus',
            LogAktivitas::pelaku().' menghapus poin isian "'.$bagian->nama.'" pada '.self::lokasi($poin).'.',
            self::konteks($bagian, $poin, $korelasi, ['sebelum' => self::potret($poin)]),
        );
    }

    /**
     * Percobaan perubahan yang ditolak server — dicatat SEBELUM penolakan dilempar,
     * di luar transaksi, jadi tidak ada data yang berubah.
     *
     * @param  array<string, mixed>  $konteks
     */
    public static function ditolak(?BagianKustom $bagian, string $alasan, array $konteks = []): void
    {
        LogAktivitas::catat(
            'bagian_kustom_poin.simpan',
            'Percobaan perubahan isian'.($bagian ? ' "'.$bagian->nama.'"' : ' bagian kustom').' oleh '.LogAktivitas::pelaku().' ditolak: '.$alasan.'.',
            $konteks + ['objek_tipe' => LogAktivitas::OBJEK_POIN, 'bagian_kustom_id' => $bagian?->id],
            LogAktivitas::STATUS_DITOLAK,
        );
    }

    /**
     * Bukti dukung baru tersimpan. $diDrive = hasil sinkron ke Google Drive yang
     * sebenarnya — tersimpan lokal tidak pernah disebut "terunggah ke Drive".
     */
    public static function buktiDiunggah(BagianKustom $bagian, BagianKustomPoin $poin, Berkas $berkas, bool $diDrive, ?string $korelasi = null): void
    {
        LogAktivitas::catat(
            'bukti.diunggah',
            LogAktivitas::pelaku().' mengunggah bukti dukung "'.$berkas->nama_file.'" pada poin isian "'.$bagian->nama.'", '.self::lokasi($poin).'. '.self::statusDrive($diDrive),
            self::konteksBerkas($bagian, $poin, $berkas, $korelasi, ['sesudah' => ['nama_file' => $berkas->nama_file, 'di_drive' => $diDrive]]),
        );
    }

    public static function buktiDiganti(BagianKustom $bagian, BagianKustomPoin $poin, Berkas $lama, Berkas $baru, bool $diDrive): void
    {
        LogAktivitas::catat(
            'bukti.diganti',
            LogAktivitas::pelaku().' mengganti bukti dukung "'.$lama->nama_file.'" (ditandai Tidak Sesuai) dengan "'.$baru->nama_file.'" pada poin isian "'.$bagian->nama.'", '.self::lokasi($poin).'. '.self::statusDrive($diDrive),
            self::konteksBerkas($bagian, $poin, $baru, null, [
                'sebelum' => ['berkas_id' => $lama->id, 'nama_file' => $lama->nama_file, 'status_verifikasi' => $lama->status_verifikasi],
                'sesudah' => ['berkas_id' => $baru->id, 'nama_file' => $baru->nama_file, 'status_verifikasi' => $baru->status_verifikasi, 'di_drive' => $diDrive],
            ]),
        );
    }

    /**
     * Salinan Google Drive bukti dukung Bagian Kustom berhasil dibuat — saat simpan
     * (setelah transaksi) maupun saat disusulkan `berkas:sinkron-drive`. Dipanggil di
     * transaksi yang sama dengan pencatatan drive_file_id. Berkas milik selain poin
     * Bagian Kustom tidak dicatat di sini (di luar cakupan log tahap ini).
     */
    public static function buktiTersinkron(Berkas $berkas, bool $susulan = false): void
    {
        $poin = $berkas->ref_type === BagianKustomPoin::class ? BagianKustomPoin::find($berkas->ref_id) : null;
        $bagian = $poin?->bagianKustom;

        if (! $poin || ! $bagian) {
            return;
        }

        LogAktivitas::catat(
            'bukti.tersinkron',
            'Bukti dukung "'.$berkas->nama_file.'" pada poin isian "'.$bagian->nama.'", '.self::lokasi($poin).' tersalin ke Google Drive'
                .($susulan ? ' (disusulkan otomatis oleh sistem).' : '.'),
            self::konteksBerkas($bagian, $poin, $berkas, null, ['sesudah' => ['nama_file' => $berkas->nama_file, 'di_drive' => true]]),
        );
    }

    /**
     * Ditulis di transaksi yang sama dengan penghapusan, SEBELUM salinan Google Drive
     * sumbernya dihapus permanen (PengisianKegiatan::hapusBuktiTersimpan()) — bila
     * Drive gagal, catatan ini ikut batal dan buktinya tetap ada.
     */
    public static function buktiDihapus(BagianKustom $bagian, BagianKustomPoin $poin, Berkas $berkas): void
    {
        LogAktivitas::catat(
            'bukti.dihapus',
            LogAktivitas::pelaku().' menghapus bukti dukung "'.$berkas->nama_file.'" (ditandai Tidak Sesuai) dari poin isian "'.$bagian->nama.'", '.self::lokasi($poin).'. '
                .($berkas->drive_file_id ? 'Salinan di Google Drive ikut dihapus permanen.' : 'Berkas ini belum punya salinan di Google Drive.'),
            self::konteksBerkas($bagian, $poin, $berkas, null, [
                'sebelum' => ['nama_file' => $berkas->nama_file, 'status_verifikasi' => $berkas->status_verifikasi],
            ]),
        );
    }

    /**
     * Pengisi mengajukan atau menarik pengajuan poin (alur verifikasi Bagian Kustom).
     * Dicatat di transaksi yang sama dengan perubahan statusnya.
     */
    public static function poinBeralur(BagianKustom $bagian, BagianKustomPoin $poin, string $aksi, string $statusSebelum, ?string $korelasi = null): void
    {
        LogAktivitas::catat(
            'bagian_kustom_poin.'.$aksi,
            LogAktivitas::pelaku().($aksi === 'diajukan' ? ' mengajukan' : ' menarik pengajuan').' poin isian "'.$bagian->nama.'" pada '.self::lokasi($poin).'.',
            self::konteks($bagian, $poin, $korelasi, LogAktivitas::selisih(
                ['status' => $statusSebelum],
                ['status' => \App\Support\AlurBagianKustom::statusPoin($poin)],
            )),
        );
    }

    /** Tim SAKIP menandai poin Sesuai / Tidak Sesuai di halaman Verifikasi. */
    public static function poinDitandai(BagianKustomPoin $poin, string $statusSebelum, ?string $catatanSebelum): void
    {
        $poin->refresh();
        $bagian = $poin->bagianKustom;
        $sesuai = $poin->status_verifikasi === 'terverifikasi';

        LogAktivitas::catat(
            $sesuai ? 'bagian_kustom_poin.ditandai_sesuai' : 'bagian_kustom_poin.ditandai_tidak_sesuai',
            LogAktivitas::pelaku().' menandai poin isian "'.($bagian?->nama ?? 'bagian kustom').'" pada '.self::lokasi($poin)
                .($sesuai ? ' sebagai "Sesuai".' : ' sebagai "Tidak Sesuai" dengan catatan revisi.'),
            [
                'objek_tipe' => LogAktivitas::OBJEK_POIN,
                'objek_id' => $poin->id,
                'menu' => $poin->menu ?? MenuBagianKustom::PENGISIAN,
                'bagian_kustom_id' => $poin->bagian_kustom_id,
                'iku_id' => $poin->iku_id,
                'periode_id' => $poin->periode_id,
                'perubahan' => LogAktivitas::selisih(
                    ['status_verifikasi' => $statusSebelum, 'catatan' => $catatanSebelum],
                    ['status_verifikasi' => $poin->status_verifikasi, 'catatan' => $poin->catatan],
                ),
            ],
        );
    }

    /** "IKU 1131 (…), April 2026 (Triwulan II)" — dari relasi tersimpan poin. */
    protected static function lokasi(BagianKustomPoin $poin): string
    {
        return LogAktivitas::labelIku(MasterIku::find($poin->iku_id)).', '.LogAktivitas::labelPeriode(Periode::find($poin->periode_id));
    }

    /**
     * Keadaan salinan Drive SAAT catatan ditulis. Unggahan Drive dijalankan setelah
     * transaksi simpan, jadi pada saat ini berkas baru tersimpan di server; berhasilnya
     * salinan Drive dicatat terpisah (bukti.tersinkron).
     */
    protected static function statusDrive(bool $diDrive): string
    {
        return $diDrive
            ? 'Berkas tersalin ke Google Drive.'
            : 'Berkas tersimpan di server; salinan Google Drive BELUM ada saat dicatat (berhasilnya dicatat terpisah sebagai "Bukti tersalin ke Google Drive").';
    }

    /**
     * @param  array<string, mixed>  $sebelum
     * @param  array<string, mixed>  $sesudah
     */
    protected static function ringkasKolom(BagianKustom $bagian, mixed $sebelum, mixed $sesudah): string
    {
        $label = collect($bagian->kolomList())->pluck('label', 'kunci');
        $berubah = collect(array_keys(LogAktivitas::selisih((array) $sebelum, (array) $sesudah)))
            ->map(fn ($kunci) => '"'.($label[$kunci] ?? $kunci).'"');

        return 'kolom '.($berubah->isEmpty() ? 'isian' : $berubah->implode(', '));
    }

    /**
     * @param  array<string, mixed>  $perubahan
     * @return array<string, mixed>
     */
    protected static function konteks(BagianKustom $bagian, BagianKustomPoin $poin, ?string $korelasi, array $perubahan): array
    {
        return [
            'objek_tipe' => LogAktivitas::OBJEK_POIN,
            'objek_id' => $poin->id,
            'menu' => $poin->menu,
            'bagian_kustom_id' => $bagian->id,
            'iku_id' => $poin->iku_id,
            'periode_id' => $poin->periode_id,
            'perubahan' => $perubahan,
            'korelasi' => $korelasi,
        ];
    }

    /**
     * @param  array<string, mixed>  $perubahan
     * @return array<string, mixed>
     */
    protected static function konteksBerkas(BagianKustom $bagian, BagianKustomPoin $poin, Berkas $berkas, ?string $korelasi, array $perubahan): array
    {
        return ['objek_tipe' => LogAktivitas::OBJEK_BERKAS, 'objek_id' => $berkas->id] + self::konteks($bagian, $poin, $korelasi, $perubahan);
    }
}
