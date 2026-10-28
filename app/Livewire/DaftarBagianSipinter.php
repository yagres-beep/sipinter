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

        $this->sunting = $kode;
        $this->formLabel = [
            'label_menu' => (string) $baris?->label_menu,
            'judul' => (string) $baris?->judul,
            'petunjuk' => (string) $baris?->petunjuk,
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
        $this->formLabel = ['label_menu' => '', 'judul' => '', 'petunjuk' => ''];
    }

    public function simpan(): void
    {
        $kode = (string) $this->sunting;
        abort_unless(RegistriBagian::bisaDiberiLabel($kode), 403);

        $this->validate([
            'formLabel.label_menu' => ['nullable', 'string', 'max:40'],
            'formLabel.judul' => ['nullable', 'string', 'max:120'],
            'formLabel.petunjuk' => ['nullable', 'string', 'max:1000'],
        ], [
            'formLabel.label_menu.max' => 'Label menu maksimal 40 karakter.',
            'formLabel.judul.max' => 'Judul maksimal 120 karakter.',
            'formLabel.petunjuk.max' => 'Petunjuk maksimal 1000 karakter.',
        ]);

        $baru = [
            'label_menu' => self::bersih($this->formLabel['label_menu'] ?? ''),
            'judul' => self::bersih($this->formLabel['judul'] ?? ''),
            'petunjuk' => self::bersih($this->formLabel['petunjuk'] ?? ''),
        ];
        $bawaan = RegistriBagian::bawaan()[$kode];

        $hasil = DB::transaction(function () use ($kode, $baru, $bawaan) {
            $baris = PengaturanBagianBawaan::where('kode', $kode)->lockForUpdate()->first();

            if (self::versi($baris) !== $this->versiLabel) {
                return 'bentrok';
            }

            $sebelum = [
                'label_menu' => $baris?->label_menu,
                'judul' => $baris?->judul,
                'petunjuk' => $baris?->petunjuk,
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
            $this->addError('formLabel', 'Label bagian ini sudah diubah Tim SAKIP lain sejak Anda membukanya. Perubahan Anda belum disimpan. Tutup lalu buka kembali untuk memuat yang terbaru.');

            return;
        }

        PengaturanBagianBawaan::lupakanCache();
        $this->tutup();

        session()->flash('status-bagian-bawaan', $hasil === 'sama'
            ? 'Tidak ada perubahan.'
            : 'Label "'.$bawaan['label'].'" disimpan. Langsung berlaku di Isian Kegiatan. Notula, DOCX, dan folder Drive tidak berubah.');
    }

    private static function bersih(string $teks): ?string
    {
        $teks = trim($teks);

        return $teks === '' ? null : $teks;
    }

    private static function versi(?PengaturanBagianBawaan $baris): string
    {
        return sha1((string) json_encode([$baris?->label_menu, $baris?->judul, $baris?->petunjuk]));
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
