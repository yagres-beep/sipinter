<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach (['Ketua Tim', 'Tim SAKIP', 'Kepala'] as $nama) {
            Role::firstOrCreate(['nama' => $nama]);
        }
    }
}
