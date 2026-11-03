<?php

namespace App\Livewire;

use App\Models\BagianKustom;
use App\Models\LogAktivitas;
use App\Models\PengaturanBagianBawaan;
use App\Models\RiwayatKonfigurasiBagianKustom;
use App\Support\RegistriBagian;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Pusat pengelolaan: daftar SELURUH bagian SIPINTER (bawaan + Bagian Kustom) dengan
 * jenis, hak akses, alur, dan batasannya. Bagian bawaan jenis B bisa diganti label
 * menu, judul, dan petunjuknya; Bagian Kustom diatur di BagianKustomManager di bawahnya.
 *
 * Hanya Tim SAKIP (keputusan pemilik proses 27 Okt 2026).
 */
class DaftarBagianSipinter extends Component
{
    /** Kode bagian bawaan yang sedang disunting (null = tidak ada). */
    public ?string $sunting = null;

    /** Kode bagian bawaan yang rinciannya dibuka. */
    public ?string $rinci = null;

    /** @var array{label_menu: string, judul: string, petunjuk: string} */
    public array $formLabel = ['label_menu' => '', 'judul' => '', 'petunjuk' => ''];

    /** Sidik jari pengaturan saat form dibuka — simpan ditolak bila sudah diubah orang lain. */
    #[Locked]
    public ?string $versiLabel = null;

    public function boot(): void
    {
        if (auth()->user()?->namaRole() !== BagianKustom::NAMA_PERAN[BagianKustom::PERAN_TIM_SAKIP]) {
            LogAktivitas::aksesDitolak('pusat pengelolaan bagian');
            abort(403);
        }
    }

    /**
     * Dipicu BagianKustomManager setelah bagian kustom dibuat, diduplikasi, diubah,
     * diurutkan, diaktifkan/nonaktifkan, atau dihapus — cukup render ulang daftar.
     */
    #[On('bagian-kustom-berubah')]
    public function segarkan(): void {}

    public function lihat(string $kode): void
    {
        abort_unless(array_key_exists($kode, RegistriBagian::bawaan()), 404);
        $this->rinci = $this->rinci === $kode ? null : $kode;
    }

    public function buka(string $kode): void
    {
        abort_unless(RegistriBagian::bisaDiberiLabel($kode), 403);

        $baris = PengaturanBagianBawaan::where('kode', $kode)->first();
        $bawaan = RegistriBagian::bawaan()[$kode] ?? [];

        $this->sunting = $kode;
        $this->formLabel = [
            'label_menu' => (string) $baris?->label_menu,
            'judul' => (string) ($baris?->judul ?: ($bawaan['judul'] ?? '')),
            'petunjuk' => (string) $baris?->petunjuk,
            'masuk_notula' => $baris ? (bool) $baris->masuk_notula : (($bawaan['notula'] ?? '—') !== '—'),
            'judul_notula' => (string) ($baris?->judul_notula ?: ($bawaan['notula'] ?? '')),
            'notula_periode' => (string) ($baris?->notula_periode ?: 'triwulan'),
            'aktif' => $baris ? (bool) $baris->aktif : true,
            'catatan' => (string) $baris?->catatan,
        ];
        $this->versiLabel = self::versi($baris);
        $this->resetErrorBag();
    }

    public function tutup(): void
    {
        $this->sunting = null;
        $this->versiLabel = null;
        $this->resetErrorBag();
    }

    /** Kosongkan semua isian form = kembali ke label bawaan (setelah disimpan). */
    public function kosongkan(): void
    {
        $bawaan = RegistriBagian::bawaan()[$this->sunting] ?? [];
        $this->formLabel = [
            'label_menu' => '',
            'judul' => (string) ($bawaan['judul'] ?? ''),
            'petunjuk' => '',
            'masuk_notula' => (($bawaan['notula'] ?? '—') !== '—'),
            'judul_notula' => (string) ($bawaan['notula'] ?? ''),
            'notula_periode' => 'triwulan',
            'aktif' => true,
            'catatan' => '',
        ];
    }

    public function simpan(): void
    {
        $kode = (string) $this->sunting;
        abort_unless(RegistriBagian::bisaDiberiLabel($kode), 403);

        $this->validate([
            'formLabel.label_menu' => ['nullable', 'string', 'max:60'],
            'formLabel.judul' => ['nullable', 'string', 'max:150'],
            'formLabel.petunjuk' => ['nullable', 'string', 'max:1000'],
            'formLabel.judul_notula' => ['nullable', 'string', 'max:150'],
            'formLabel.notula_periode' => ['required', 'string', 'in:triwulan,kumulatif'],
            'formLabel.masuk_notula' => ['boolean'],
            'formLabel.aktif' => ['boolean'],
            'formLabel.catatan' => ['nullable', 'string', 'max:2000'],
        ]);

        $bawaan = RegistriBagian::bawaan()[$kode];
        $baru = [
            'label_menu' => self::bersih($this->formLabel['label_menu'] ?? ''),
            'judul' => self::bersih($this->formLabel['judul'] ?? ''),
            'petunjuk' => self::bersih($this->formLabel['petunjuk'] ?? ''),
            'masuk_notula' => (bool) ($this->formLabel['masuk_notula'] ?? true),
            'judul_notula' => self::bersih($this->formLabel['judul_notula'] ?? ''),
            'notula_periode' => (string) ($this->formLabel['notula_periode'] ?? 'triwulan'),
            'aktif' => (bool) ($this->formLabel['aktif'] ?? true),
            'catatan' => self::bersih($this->formLabel['catatan'] ?? ''),
        ];

        $hasil = DB::transaction(function () use ($kode, $baru, $bawaan) {
            $baris = PengaturanBagianBawaan::where('kode', $kode)->lockForUpdate()->first();

            if (self::versi($baris) !== $this->versiLabel) {
                return 'bentrok';
            }

            $sebelum = [
                'label_menu' => $baris?->label_menu,
                'judul' => $baris?->judul,
                'petunjuk' => $baris?->petunjuk,
                'masuk_notula' => (bool) ($baris?->masuk_notula ?? true),
                'judul_notula' => $baris?->judul_notula,
                'notula_periode' => (string) ($baris?->notula_periode ?? 'triwulan'),
                'aktif' => (bool) ($baris?->aktif ?? true),
                'catatan' => $baris?->catatan,
            ];

            if ($sebelum === $baru) {
                return 'sama';
            }

            // Baris tidak dihapus walau semua kolom dikosongkan: kosong = label bawaan.
            PengaturanBagianBawaan::updateOrCreate(['kode' => $kode], $baru + ['diubah_oleh' => auth()->id()]);

            RiwayatKonfigurasiBagianKustom::catat(
                null,
                'Bawaan: '.$bawaan['label'],
                'ubah',
                ['kode_bawaan' => $kode] + $sebelum,
                ['kode_bawaan' => $kode] + $baru,
                self::ringkasan($bawaan, $sebelum, $baru),
            );

            return 'ok';
        });

        if ($hasil === 'bentrok') {
            $this->addError('formLabel', 'Pengaturan bagian ini sudah diubah Tim SAKIP lain sejak Anda membukanya. Perubahan Anda belum disimpan. Tutup lalu buka kembali untuk memuat yang terbaru.');

            return;
        }

        PengaturanBagianBawaan::lupakanCache();
        $this->tutup();

        session()->flash('status-bagian-bawaan', $hasil === 'sama'
            ? 'Tidak ada perubahan.'
            : 'Pengaturan bagian "'.$bawaan['label'].'" berhasil disimpan.');
    }

    private static function bersih(string $teks): ?string
    {
        $teks = trim($teks);

        return $teks === '' ? null : $teks;
    }

    private static function versi(?PengaturanBagianBawaan $baris): string
    {
        return sha1((string) json_encode([$baris?->label_menu, $baris?->judul, $baris?->petunjuk, $baris?->masuk_notula, $baris?->judul_notula, $baris?->notula_periode, $baris?->aktif, $baris?->catatan]));
    }

    /**
     * @param  array<string, mixed>  $bawaan
     * @param  array<string, ?string>  $sebelum
     * @param  array<string, ?string>  $sesudah
     */
    private static function ringkasan(array $bawaan, array $sebelum, array $sesudah): string
    {
        $nama = ['label_menu' => 'label menu', 'judul' => 'judul', 'petunjuk' => 'petunjuk'];
        $asal = ['label_menu' => $bawaan['label'], 'judul' => $bawaan['judul'], 'petunjuk' => '(tanpa petunjuk)'];
        $bagian = [];

        foreach ($nama as $kunci => $label) {
            if ($sebelum[$kunci] !== $sesudah[$kunci]) {
                $bagian[] = $label.' "'.($sebelum[$kunci] ?? $asal[$kunci]).'" → "'.($sesudah[$kunci] ?? $asal[$kunci]).'"'.($sesudah[$kunci] === null ? ' (bawaan)' : '');
            }
        }

        return 'Bagian bawaan: '.implode('; ', $bagian);
    }

    public function render()
    {
        return view('livewire.daftar-bagian-sipinter', [
            'bawaan' => RegistriBagian::bawaan(),
            'tampilan' => RegistriBagian::tampilanBawaan(),
            'aturan' => PengaturanBagianBawaan::with('pengubah')->get()->keyBy('kode'),
            'kustom' => BagianKustom::orderBy('urutan')->orderBy('id')->get(),
            'namaPeran' => BagianKustom::NAMA_PERAN,
            'jenis' => RegistriBagian::JENIS,
            'riwayatBawaan' => RiwayatKonfigurasiBagianKustom::whereNull('bagian_kustom_id')
                ->where('nama_bagian', 'like', 'Bawaan:%')
                ->latest('id')->limit(10)->get(),
        ]);
    }
}
