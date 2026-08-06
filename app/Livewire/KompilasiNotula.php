<?php

namespace App\Livewire;

use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Services\NotulaService;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Tim SAKIP — Kompilasi Notula 3 Bagian (RF-41 s.d. RF-44a).
 *
 * Bagian I disusun otomatis (RF-42) dan bisa disunting langsung di sini sebelum
 * digabung. Bagian II & III diunggah lalu dikonversi ke PDF (RF-42a/b). Setelah
 * ketiganya lengkap, "Gabungkan → PDF" menghasilkan satu PDF notula dan langsung
 * menandainya menunggu persetujuan Kepala (mockup tidak punya tombol "kirim" terpisah).
 */
class KompilasiNotula extends Component
{
    use WithFileUploads;

    public int $tahun;

    public int $triwulan;

    public $bagian2File = null;

    public $bagian3File = null;

    public string $bagian1EditText = '';

    /**
     * Cache dalam satu siklus request — notula() dipanggil di banyak method
     * (mount, render, tiap aksi) untuk tahun/triwulan yang sama; tanpa memoisasi
     * ini query yang sama (DB remote, ~400ms) terulang tiap kali dipanggil.
     */
    protected ?Notula $cacheNotula = null;

    public function mount(): void
    {
        $this->tahun = (int) now()->year;
        $this->triwulan = (int) ceil(((int) now()->month) / 3);

        $this->muatBagian1EditText();
    }

    public function updatedTahun(): void
    {
        $this->muatBagian1EditText();
        $this->dispatchKontenBagian1();
    }

    public function updatedTriwulan(): void
    {
        $this->muatBagian1EditText();
        $this->dispatchKontenBagian1();
    }

    protected function notula(): Notula
    {
        return $this->cacheNotula ??= app(NotulaService::class)->untukTriwulan($this->tahun, $this->triwulan);
    }

    /**
     * RF-42c: ringkasan jumlah IKU siap (seluruh kegiatannya sudah diverifikasi/disetujui
     * pada triwulan ini) per Sasaran, ditampilkan sebelum notula disusun. IKU tanpa
     * sasaran terisi tidak diikutkan — Sasaran diisi manual lewat halaman Master IKU.
     *
     * @return \Illuminate\Support\Collection<int, array{sasaran: string, iku_siap: int, iku_total: int}>
     */
    protected function kesiapanPerSasaran(): \Illuminate\Support\Collection
    {
        $ikuBerSasaran = MasterIku::whereNotNull('sasaran')->where('sasaran', '!=', '')->get();

        // Satu query untuk kegiatan seluruh IKU ber-sasaran pada triwulan ini,
        // dikelompokkan per IKU di PHP — bukan satu query Kegiatan per baris IKU.
        $kegiatanPerIku = Kegiatan::whereIn('iku_id', $ikuBerSasaran->pluck('id'))
            ->whereHas('periode', fn ($q) => $q->where('tahun', $this->tahun)->where('triwulan', $this->triwulan))
            ->get(['iku_id', 'status_dokumen'])
            ->groupBy('iku_id');

        return $ikuBerSasaran
            ->groupBy('sasaran')
            ->map(function ($ikuGroup) use ($kegiatanPerIku) {
                $ikuSiap = $ikuGroup->filter(function ($iku) use ($kegiatanPerIku) {
                    $kegiatan = $kegiatanPerIku->get($iku->id, collect());

                    return $kegiatan->isNotEmpty()
                        && $kegiatan->every(fn ($k) => in_array($k->status_dokumen, [Kegiatan::STATUS_DIVERIFIKASI, Kegiatan::STATUS_DISETUJUI]));
                })->count();

                return [
                    'sasaran' => $ikuGroup->first()->sasaran,
                    'iku_siap' => $ikuSiap,
                    'iku_total' => $ikuGroup->count(),
                ];
            })
            ->sortBy('sasaran')
            ->values();
    }

    /**
     * Bila Bagian I belum pernah disusun sama sekali (notula baru), susun otomatis
     * sekali di awal supaya Tim SAKIP tidak melihat pratinjau kosong.
     */
    protected function muatBagian1EditText(): void
    {
        $notula = $this->notula();

        if (blank($notula->bagian1_html)) {
            app(NotulaService::class)->susunBagianSatu($notula);
            $notula->refresh();
        }

        $this->bagian1EditText = $notula->bagian1_html ?? '';
        $this->resetErrorBag();
    }

    public function susunUlangOtomatis(): void
    {
        $html = app(NotulaService::class)->susunBagianSatu($this->notula());
        $this->bagian1EditText = $html;
        $this->dispatchKontenBagian1();

        session()->flash('status', 'Bagian I berhasil disusun ulang otomatis dari data terverifikasi.');
    }

    /**
     * Editor Bagian I ditandai wire:ignore (lihat Blade) supaya suntingan langsung
     * di area WYSIWYG tidak diganggu morph Livewire saat mengetik — jadi begitu
     * kontennya diganti dari server (susun ulang / ganti triwulan), sisi klien perlu
     * diberi tahu lewat event browser untuk menimpa isinya secara eksplisit.
     */
    protected function dispatchKontenBagian1(): void
    {
        $this->dispatch('bagian1-diperbarui', html: $this->bagian1EditText);
    }

    public function simpanSuntinganBagian1(): void
    {
        $notula = $this->notula();
        $notula->update(['bagian1_html' => $this->bagian1EditText]);
        $notula->tandaiPerluDigabungUlang();

        session()->flash('status', 'Pratinjau Bagian I berhasil disimpan.');
    }

    public function unggahBagian(int $bagianKe): void
    {
        $field = $bagianKe === 2 ? 'bagian2File' : 'bagian3File';

        $this->validate([
            $field => ['required', 'file', 'mimes:docx,xlsx,pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        try {
            app(NotulaService::class)->terimaUploadBagian($this->notula(), $bagianKe, $this->{$field});
            session()->flash('status', "Bagian {$bagianKe} berhasil diunggah & dikonversi ke PDF.");
        } catch (RuntimeException $e) {
            $this->addError($field, $e->getMessage());

            return;
        }

        $this->reset($field);
    }

    public function gabungkan(): void
    {
        try {
            app(NotulaService::class)->gabungkan($this->notula());
            session()->flash('status', 'Ketiga bagian berhasil digabungkan. Notula menunggu persetujuan Kepala.');
        } catch (RuntimeException $e) {
            $this->addError('gabung', $e->getMessage());
        }
    }

    /**
     * Riwayat notula yang sudah disetujui (ber-TTD) di triwulan-triwulan lain (RF-44a).
     */
    protected function riwayatDisetujui(Notula $notulaSaatIni)
    {
        return Notula::with('periode')
            ->where('status', Notula::STATUS_DISETUJUI)
            ->whereKeyNot($notulaSaatIni->id)
            ->get()
            ->sortByDesc(fn ($n) => $n->periode->tahun * 10 + $n->periode->triwulan)
            ->take(5)
            ->values();
    }

    public function render()
    {
        $notula = $this->notula();

        return view('livewire.kompilasi-notula', [
            'notula' => $notula,
            'semuaTerverifikasi' => app(NotulaService::class)->semuaBuktiTerverifikasi($notula->periode),
            'kesiapanSasaran' => $this->kesiapanPerSasaran(),
            'riwayatDisetujui' => $this->riwayatDisetujui($notula),
        ]);
    }
}
