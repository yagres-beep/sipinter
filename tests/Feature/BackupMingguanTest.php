<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Mockery;
use Tests\TestCase;

class BackupMingguanTest extends TestCase
{
    use RefreshDatabase;

    private function catatBackup(string $status, int $hariLalu = 0, int $jamLalu = 0): Backup
    {
        $backup = Backup::create([
            'nama_file' => 'backup_'.uniqid().'.sql',
            'status' => $status,
        ]);
        $backup->created_at = now()->subDays($hariLalu)->subHours($jamLalu);
        $backup->save();

        return $backup;
    }

    private function harapkanBackupDibuat(string $status = Backup::STATUS_BERHASIL): void
    {
        $service = Mockery::mock(BackupService::class);
        $service->shouldReceive('buat')->once()->with(null)->andReturnUsing(
            fn () => Backup::create(['nama_file' => 'backup_baru.sql', 'status' => $status, 'catatan' => $status === Backup::STATUS_GAGAL ? 'pg_dump error' : null])
        );
        $this->app->instance(BackupService::class, $service);
    }

    private function harapkanTidakAdaBackup(): void
    {
        $service = Mockery::mock(BackupService::class);
        $service->shouldNotReceive('buat');
        $this->app->instance(BackupService::class, $service);
    }

    public function test_membuat_backup_bila_belum_pernah_ada(): void
    {
        $this->harapkanBackupDibuat();

        $this->artisan('backup:mingguan')->assertSuccessful();
    }

    public function test_dilewati_bila_ada_backup_berhasil_dalam_7_hari(): void
    {
        $this->catatBackup(Backup::STATUS_BERHASIL, hariLalu: 6);
        $this->harapkanTidakAdaBackup();

        $this->artisan('backup:mingguan')->assertSuccessful();
    }

    public function test_membuat_backup_bila_backup_berhasil_terakhir_lebih_dari_7_hari(): void
    {
        $this->catatBackup(Backup::STATUS_BERHASIL, hariLalu: 7, jamLalu: 1);
        $this->harapkanBackupDibuat();

        $this->artisan('backup:mingguan')->assertSuccessful();
    }

    public function test_gagal_otomatis_tidak_dicoba_ulang_sebelum_24_jam(): void
    {
        $this->catatBackup(Backup::STATUS_GAGAL, jamLalu: 3);
        $this->harapkanTidakAdaBackup();

        $this->artisan('backup:mingguan')->assertSuccessful();
    }

    public function test_gagal_otomatis_dicoba_ulang_setelah_24_jam(): void
    {
        $this->catatBackup(Backup::STATUS_GAGAL, jamLalu: 25);
        $this->harapkanBackupDibuat();

        $this->artisan('backup:mingguan')->assertSuccessful();
    }

    public function test_perintah_gagal_bila_pg_dump_gagal(): void
    {
        $this->harapkanBackupDibuat(Backup::STATUS_GAGAL);

        $this->artisan('backup:mingguan')->assertFailed();
    }

    public function test_opsi_paksa_mengabaikan_jadwal(): void
    {
        $this->catatBackup(Backup::STATUS_BERHASIL, jamLalu: 1);
        $this->harapkanBackupDibuat();

        $this->artisan('backup:mingguan --paksa')->assertSuccessful();
    }

    public function test_terdaftar_di_scheduler(): void
    {
        $terjadwal = collect(Schedule::events())
            ->contains(fn ($event) => str_contains((string) $event->command, 'backup:mingguan'));

        $this->assertTrue($terjadwal);
    }
}
