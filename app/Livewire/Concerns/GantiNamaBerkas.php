<?php

namespace App\Livewire\Concerns;

use App\Models\Berkas;

/**
 * Aksi "ganti nama berkas" langsung dari web — dipakai bersama oleh setiap halaman
 * yang menampilkan daftar berkas bukti (lihat komponen Blade <x-nama-berkas>).
 * Nama bawaan (otomatis, RF-17) tetap ditampilkan lebih dulu; pengunggahnya
 * sendiri atau Tim SAKIP boleh menggantinya kapan saja.
 */
trait GantiNamaBerkas
{
    /**
     * @return array{ok: bool, nama_file?: string, pesan?: string}
     */
    public function gantiNamaBerkas(int $berkasId, string $namaBaru): array
    {
        $berkas = Berkas::find($berkasId);

        if (! $berkas) {
            return ['ok' => false, 'pesan' => 'Berkas tidak ditemukan.'];
        }

        if (! $berkas->bisaDiubahNamaOleh(auth()->user())) {
            return ['ok' => false, 'pesan' => 'Hanya pengunggah berkas atau Tim SAKIP yang dapat mengganti nama berkas ini.'];
        }

        if (mb_strlen(trim($namaBaru)) > 150) {
            return ['ok' => false, 'pesan' => 'Nama berkas maksimal 150 karakter.'];
        }

        try {
            $hasil = $berkas->gantiNama($namaBaru);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'pesan' => $e->getMessage()];
        }

        // Halaman yang menyimpan daftar berkas sebagai array di properti publik
        // (bukan query ulang tiap render) bisa memperbarui salinannya di sini.
        if (method_exists($this, 'setelahNamaBerkasDiubah')) {
            $this->setelahNamaBerkasDiubah($berkas->id, $hasil['nama_file']);
        }

        if ($hasil['peringatan']) {
            $this->dispatch('notify', type: 'warning', message: $hasil['peringatan']);
        } else {
            $this->dispatch('notify', type: 'success', message: "Nama berkas diubah menjadi \"{$hasil['nama_file']}\".");
        }

        return ['ok' => true, 'nama_file' => $hasil['nama_file']];
    }
}
