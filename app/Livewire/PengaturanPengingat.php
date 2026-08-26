<?php

namespace App\Livewire;

use App\Models\PengaturanPengingat as PengaturanPengingatModel;
use Carbon\Carbon;
use Livewire\Component;

/**
 * Pengaturan waktu pengingat email terjadwal (Tim SAKIP) — jam pengecekan harian &
 * H-berapa hari sebelum tenggat pengajuan IKU mulai diingatkan, tanpa dikodekan
 * langsung (lihat routes/console.php & PengingatDeadlineIkuCommand), supaya Tim
 * SAKIP bisa menyesuaikan sendiri tanpa rilis kode baru. Hanya berlaku untuk
 * pengingat terjadwal — pengingat real-time (status diajukan/dikembalikan/
 * disetujui) selalu terkirim langsung, tidak dipengaruhi pengaturan ini.
 */
class PengaturanPengingat extends Component
{
    public string $jamKirim = '08:00';

    public int $deadlineHMinus = 3;

    public function mount(): void
    {
        $pengaturan = PengaturanPengingatModel::ambil();
        $this->jamKirim = $pengaturan->jamKirimFormat();
        $this->deadlineHMinus = (int) $pengaturan->deadline_h_minus;
    }

    protected function rules(): array
    {
        return [
            'jamKirim' => ['required', 'date_format:H:i'],
            'deadlineHMinus' => ['required', 'integer', 'min:0', 'max:27'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'jamKirim' => 'jam pengecekan harian',
            'deadlineHMinus' => 'H- pengingat tenggat',
        ];
    }

    /**
     * Jam kirim disimpan & dipakai apa adanya oleh Schedule::dailyAt() di
     * routes/console.php, yang berjalan di timezone app (config('app.timezone') =
     * UTC, tidak diubah ke Asia/Jakarta) — jadi jam yang diisi di sini sebenarnya
     * jam UTC, bukan WIB. Hint ini menampilkan padanannya di WIB/WITA/WIT supaya
     * Tim SAKIP tidak salah kira.
     */
    public function konversiJam(): ?string
    {
        if (! preg_match('/^\d{2}:\d{2}$/', $this->jamKirim)) {
            return null;
        }

        $utc = Carbon::createFromFormat('H:i', $this->jamKirim, 'UTC');

        return sprintf(
            '%s WIB · %s WITA · %s WIT',
            $utc->copy()->setTimezone('Asia/Jakarta')->format('H:i'),
            $utc->copy()->setTimezone('Asia/Makassar')->format('H:i'),
            $utc->copy()->setTimezone('Asia/Jayapura')->format('H:i'),
        );
    }

    public function simpan(): void
    {
        $this->validate();

        PengaturanPengingatModel::ambil()->update([
            'jam_kirim' => $this->jamKirim,
            'deadline_h_minus' => $this->deadlineHMinus,
        ]);

        PengaturanPengingatModel::lupakanCache();

        session()->flash('status', 'Pengaturan waktu pengingat berhasil disimpan. Berlaku mulai pengecekan terjadwal berikutnya.');
    }

    public function render()
    {
        return view('livewire.pengaturan-pengingat');
    }
}
