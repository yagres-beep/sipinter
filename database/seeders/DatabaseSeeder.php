<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

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

        // Di produksi, akun admin TIDAK boleh memakai kata sandi bawaan "password".
        // Ambil dari env ADMIN_PASSWORD; bila kosong, buat acak lalu tampilkan sekali
        // di terminal supaya bisa dicatat dan segera diganti lewat halaman Profil.
        $produksi = app()->environment('production');
        $passwordAdmin = env('ADMIN_PASSWORD') ?: ($produksi ? Str::password(16, symbols: false) : 'password');

        $admin = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'nama' => 'Admin Tim SAKIP',
                'email' => 'admin@sipinter.bps.go.id',
                'password' => $passwordAdmin,
                'role_id' => $timSakip->id,
                'status_verifikasi' => 'terverifikasi',
            ]
        );

        if ($produksi && $admin->wasRecentlyCreated && ! env('ADMIN_PASSWORD')) {
            $this->command?->warn("Akun admin dibuat dengan kata sandi: {$passwordAdmin} — catat & segera ganti.");
        }

        // Data contoh (akun demo berkata sandi "password") hanya untuk lokal/demo.
        if (! $produksi) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
