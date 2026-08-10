<?php

namespace Tests\Feature;

use App\Livewire\MasterIku;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MasterIkuTest extends TestCase
{
    use RefreshDatabase;

    public function test_tim_sakip_bisa_mengunduh_template_excel(): void
    {
        $peran = Role::create(['nama' => 'Tim SAKIP']);
        $this->actingAs(User::create([
            'nama' => 'SAKIP Uji', 'email' => 'sakip@example.test', 'password' => 'password',
            'role_id' => $peran->id, 'status_verifikasi' => 'terverifikasi',
        ]));

        // Regresi: import Excel facade sempat salah namespace (Illuminate\Support\Facades\Excel,
        // seharusnya Maatwebsite\Excel\Facades\Excel) sehingga tombol ini melempar
        // "Class not found" alih-alih mengunduh berkas — lihat perbaikan di app/Livewire/MasterIku.php.
        Livewire::test(MasterIku::class)
            ->call('downloadTemplate')
            ->assertFileDownloaded('template-master-iku.xlsx');
    }
}
