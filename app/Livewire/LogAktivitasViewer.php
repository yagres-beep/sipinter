<?php

namespace App\Livewire;

use App\Models\BagianKustom;
use App\Models\LogAktivitas;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Halaman baca Log Aktivitas (tab di Data Master & Konfigurasi) — HANYA untuk Tim
 * SAKIP dan HANYA baca. Tidak ada aksi yang mengubah atau menghapus catatan log.
 *
 * Tahap pertama: Kepala belum diberi akses sampai ada kebutuhan & aturan aksesnya.
 */
class LogAktivitasViewer extends Component
{
    use WithPagination;

    public const PER_HALAMAN = 25;

    /** Filter tanggal (Y-m-d, zona WITA). */
    public string $dari = '';

    public string $sampai = '';

    /** Filter pelaku: id pengguna. */
    public string $pelaku = '';

    public string $aksi = '';

    public string $status = '';

    public string $ikuId = '';

    public string $tahun = '';

    public string $bulan = '';

    public string $bagianId = '';

    /** Filter satu proses simpan (diisi dari tombol di panel rinci). */
    public string $korelasi = '';

    protected const FILTER = ['dari', 'sampai', 'pelaku', 'aksi', 'status', 'ikuId', 'tahun', 'bulan', 'bagianId', 'korelasi'];

    /**
     * Dicek di SETIAP request Livewire (bukan hanya saat halaman dibuka), jadi
     * pengguna yang perannya dicabut saat halaman masih terbuka langsung ditolak.
     */
    public function boot(): void
    {
        if (auth()->user()?->namaRole() !== 'Tim SAKIP') {
            LogAktivitas::aksesDitolak('halaman Log Aktivitas');
            abort(403);
        }
    }

    public function updating(string $nama): void
    {
        if (in_array($nama, self::FILTER, true)) {
            $this->resetPage();
        }
    }

    public function resetFilter(): void
    {
        $this->reset(self::FILTER);
        $this->resetPage();
    }

    public function filterKorelasi(string $korelasi): void
    {
        $this->korelasi = preg_match('/^[0-9a-f-]{36}$/i', $korelasi) ? $korelasi : '';
        $this->resetPage();
    }

    /** Tanggal Y-m-d (WITA) → batas UTC; null bila kosong/tidak sah. */
    protected function batasTanggal(string $tanggal, bool $akhir): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            return null;
        }

        try {
            $waktu = Carbon::createFromFormat('Y-m-d', $tanggal, 'Asia/Makassar');
        } catch (\Throwable) {
            return null;
        }

        return ($akhir ? $waktu->endOfDay() : $waktu->startOfDay())->utc();
    }

    protected function angka(string $nilai): ?int
    {
        return ctype_digit($nilai) ? (int) $nilai : null;
    }

    protected function query(): Builder
    {
        $q = LogAktivitas::query();

        if ($dari = $this->batasTanggal($this->dari, false)) {
            $q->where('created_at', '>=', $dari);
        }

        if ($sampai = $this->batasTanggal($this->sampai, true)) {
            $q->where('created_at', '<=', $sampai);
        }

        if (($pelaku = $this->angka($this->pelaku)) !== null) {
            $q->where('user_id', $pelaku);
        }

        if (array_key_exists($this->aksi, LogAktivitas::LABEL_AKSI)) {
            $q->where('aksi', $this->aksi);
        }

        if (in_array($this->status, [LogAktivitas::STATUS_BERHASIL, LogAktivitas::STATUS_DITOLAK, LogAktivitas::STATUS_GAGAL], true)) {
            $q->where('status', $this->status);
        }

        if (($iku = $this->angka($this->ikuId)) !== null) {
            $q->where('iku_id', $iku);
        }

        if (($bagian = $this->angka($this->bagianId)) !== null) {
            $q->where('bagian_kustom_id', $bagian);
        }

        $tahun = $this->angka($this->tahun);
        $bulan = $this->angka($this->bulan);

        if ($tahun !== null || $bulan !== null) {
            $q->whereIn('periode_id', Periode::query()
                ->when($tahun !== null, fn ($p) => $p->where('tahun', $tahun))
                ->when($bulan !== null, fn ($p) => $p->where('bulan', $bulan))
                ->select('id'));
        }

        if ($this->korelasi !== '') {
            $q->where('korelasi', $this->korelasi);
        }

        return $q;
    }

    public function render()
    {
        $log = $this->query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->simplePaginate(self::PER_HALAMAN);

        // Label IKU & periode untuk baris di halaman ini saja (satu query masing-masing).
        $iku = MasterIku::whereIn('id', $log->pluck('iku_id')->filter()->unique())->get()->keyBy('id');
        $periode = Periode::whereIn('id', $log->pluck('periode_id')->filter()->unique())->get()->keyBy('id');
        $bagian = BagianKustom::orderBy('urutan')->orderBy('id')->get(['id', 'nama']);

        return view('livewire.log-aktivitas-viewer', [
            'log' => $log,
            'ikuLabel' => $iku,
            'periodeLabel' => $periode,
            'daftarBagian' => $bagian,
            'namaBagian' => $bagian->pluck('nama', 'id'),
            'daftarIku' => MasterIku::daftarUrutKode(),
            'daftarPengguna' => User::orderBy('nama')->get(['id', 'nama']),
            'daftarAksi' => LogAktivitas::LABEL_AKSI,
            'daftarTahun' => range((int) now()->year - 3, (int) now()->year + 1),
            'namaBulan' => [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'],
        ]);
    }
}
