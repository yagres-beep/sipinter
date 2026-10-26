<?php

namespace App\Support;

use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Periode;
use Illuminate\Support\Collection;

/**
 * Relasi isian Bagian Kustom antarperiode.
 *
 * Pola yang dipakai: "objek baru yang terkait dengan objek sebelumnya". Melanjutkan
 * sebuah poin membuat POIN BARU di periode tujuan yang menunjuk poin asalnya
 * (lanjutan_dari_id) dan akar rangkaiannya (akar_id). Nilai teks & kolom disalin
 * sebagai isian awal yang bisa diubah; poin asal, bukti dukungnya, dan status
 * verifikasinya tidak pernah diubah. Bukti dukung TIDAK disalin (tetap di poin asal
 * dan bisa dilihat lewat riwayat) — tidak ada unggahan ulang ke Drive.
 *
 * Isian yang baru ditambahkan di suatu periode hanya ada di periode itu dan sesudahnya
 * bila dilanjutkan; tidak pernah muncul di periode yang lebih lama.
 *
 * Cakupan: periode sebelumnya dalam TAHUN yang sama (siklus pelaporan SAKIP tahunan).
 */
final class LanjutanBagianKustom
{
    /**
     * Versi terakhir tiap objek dari periode sebelumnya (tahun yang sama) yang BELUM
     * punya versi di periode tujuan — calon "Lanjutkan".
     *
     * @return Collection<int, BagianKustomPoin>
     */
    public static function objekTerbuka(BagianKustom $bagian, string $menu, ?string $peran, Periode $periode, ?int $ikuId = null): Collection
    {
        $dasar = fn () => BagianKustomPoin::konteks($menu, (string) $peran)
            ->where('bagian_kustom_id', $bagian->id)
            ->when($ikuId !== null, fn ($q) => $q->where('iku_id', $ikuId));

        $sudahAda = $dasar()->where('periode_id', $periode->id)->get(['id', 'akar_id'])
            ->map(fn (BagianKustomPoin $p) => $p->identitasObjek())
            ->all();

        return $dasar()
            ->with(['periode', 'berkas'])
            ->whereHas('periode', fn ($q) => $q->where('tahun', $periode->tahun)->where('bulan', '<', $periode->bulan))
            ->get()
            ->groupBy(fn (BagianKustomPoin $p) => $p->identitasObjek())
            ->reject(fn ($grup, $identitas) => in_array((int) $identitas, $sudahAda, true))
            ->map(fn ($grup) => $grup->sortBy(fn (BagianKustomPoin $p) => sprintf('%02d-%012d', $p->periode->bulan, $p->id))->last())
            ->sortBy(fn (BagianKustomPoin $p) => sprintf('%02d-%012d', $p->periode->bulan, $p->id))
            ->values();
    }

    /**
     * Poin sumber yang SAH dilanjutkan ke periode tujuan oleh konteks ini, atau null.
     * Diperiksa ulang di server setiap kali (id dari browser tidak dipercaya).
     */
    public static function sumberSah(BagianKustom $bagian, int $sumberId, string $menu, ?string $peran, Periode $periode, ?int $ikuId = null): ?BagianKustomPoin
    {
        return self::objekTerbuka($bagian, $menu, $peran, $periode, $ikuId)->firstWhere('id', $sumberId);
    }

    /**
     * Kolom relasi untuk poin baru yang melanjutkan $sumber.
     *
     * @return array{lanjutan_dari_id: int, akar_id: int}
     */
    public static function atribut(BagianKustomPoin $sumber): array
    {
        return ['lanjutan_dari_id' => $sumber->id, 'akar_id' => $sumber->identitasObjek()];
    }

    /** "Februari 2026" untuk label "Lanjutan dari …". */
    public static function labelPeriode(?Periode $periode): string
    {
        if (! $periode) {
            return 'periode sebelumnya';
        }

        $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        return ($bulan[(int) $periode->bulan] ?? $periode->bulan).' '.$periode->tahun;
    }
}
