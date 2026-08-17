<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(FolderConfigSeeder::class);

        $timSakip = Role::where('nama', 'Tim SAKIP')->firstOrFail();

        User::firstOrCreate(
            ['username' => 'admin'],
            [
                'nama' => 'Admin Tim SAKIP',
                'email' => 'admin@sipinter.bps.go.id',
                'password' => 'password',
                'role_id' => $timSakip->id,
                'status_verifikasi' => 'terverifikasi',
            ]
        );

        $this->call(DemoDataSeeder::class);
    }
}
