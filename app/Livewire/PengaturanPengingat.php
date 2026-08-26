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
     * Jam yang diisi Tim SAKIP di sini adalah jam WITA (Asia/Makassar) — server
     * sendiri berjalan di UTC, tapi routes/console.php sudah menandai jadwalnya
     * dengan ->timezone('Asia/Makassar') supaya Schedule::dailyAt() menerjemahkan
     * jam WITA ini ke UTC yang benar. Hint di bawah cuma menampilkan padanannya
     * di WIB/WIT untuk Tim SAKIP yang berada di luar zona WITA.
     */
    public function konversiJam(): ?string
    {
        if (! preg_match('/^\d{2}:\d{2}$/', $this->jamKirim)) {
            return null;
        }

        $wita = Carbon::createFromFormat('H:i', $this->jamKirim, 'Asia/Makassar');

        return sprintf(
            '%s WIB · %s WIT',
            $wita->copy()->setTimezone('Asia/Jakarta')->format('H:i'),
            $wita->copy()->setTimezone('Asia/Jayapura')->format('H:i'),
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
