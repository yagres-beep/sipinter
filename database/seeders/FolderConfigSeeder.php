<?php

namespace Database\Seeders;

use App\Models\FolderConfig;
use Illuminate\Database\Seeder;

class FolderConfigSeeder extends Seeder
{
    /**
     * Pastikan baris pola folder singleton sudah ada (RF-14, RF-15).
     */
    public function run(): void
    {
        FolderConfig::current();
    }
}
