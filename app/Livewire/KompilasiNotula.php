<?php

namespace App\Livewire;

use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\User;
use App\Services\NotulaService;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Tim SAKIP — Kompilasi Notula 3 Bagian (RF-41 s.d. RF-44a).
 *
 * Bagian I disusun otomatis (RF-42) dan bisa disunting langsung di sini sebelum
 * digabung. Bagian II & III diunggah lalu dikonversi jadi konten inline (RF-42a/b —
 * lihat NotulaService::terimaUploadBagian()). Setelah ketiganya lengkap,
 * "Gabungkan → PDF" merender KETIGANYA sebagai satu dokumen mengalir (bukan tiga
 * PDF terpisah yang digabung) dan langsung menandainya menunggu persetujuan Kepala
 * (mockup tidak punya tombol "kirim" terpisah).
 */
class KompilasiNotula extends Component
{
    use WithFileUploads;

    public int $tahun;

    public int $triwulan;

    public $bagian2File = null;

    public $bagian3File = null;

    public string $bagian1EditText = '';

    public string $hariTanggal = '';

    public string $waktu = '';

    public string $tempat = '';

    public string $pimpinanRapat = '';

    public string $nipPimpinanRapat = '';

    public string $notulis = '';

    public string $nipNotulis = '';

    public string $kepalaSatker = '';

    public string $nipKepalaSatker = '';

    public string $kotaTtd = '';

    public string $linkLampiranBasisData = '';

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
        $this->muatDetailRapat();
    }

    public function updatedTahun(): void
    {
        $this->muatBagian1EditText();
        $this->muatDetailRapat();
        $this->dispatchKontenBagian1();
    }

    public function updatedTriwulan(): void
    {
        $this->muatBagian1EditText();
        $this->muatDetailRapat();
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
     * @return Collection<int, array{sasaran: string, iku_siap: int, iku_total: int}>
     */
    protected function kesiapanPerSasaran(): Collection
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

    /**
     * Muat ulang Hari/Tanggal, Waktu, Tempat, Pimpinan Rapat milik notula
     * tahun/triwulan yang sedang dipilih.
     */
    protected function muatDetailRapat(): void
    {
        $notula = $this->notula();

        $this->hariTanggal = $notula->hari_tanggal ?? '';
        $this->waktu = $notula->waktu ?? '';
        $this->tempat = $notula->tempat ?? '';
        $this->pimpinanRapat = $notula->pimpinan_rapat ?? '';
        $this->nipPimpinanRapat = $notula->nip_pimpinan_rapat ?? '';
        $this->notulis = $notula->notulis ?? '';
        $this->nipNotulis = $notula->nip_notulis ?? '';
        $this->kepalaSatker = $notula->kepala_satker ?? '';
        $this->nipKepalaSatker = $notula->nip_kepala_satker ?? '';
        $this->kotaTtd = $notula->kota_ttd ?? '';
        $this->linkLampiranBasisData = $notula->link_lampiran_basis_data ?? '';
    }

    public function simpanDetailRapat(): void
    {
        $data = $this->validate([
            'hariTanggal' => ['nullable', 'string', 'max:255'],
            'waktu' => ['nullable', 'string', 'max:255'],
            'tempat' => ['nullable', 'string', 'max:255'],
            'pimpinanRapat' => ['nullable', 'string', 'max:255'],
            'nipPimpinanRapat' => ['nullable', 'string', 'max:30'],
            'notulis' => ['nullable', 'string', 'max:255'],
            'nipNotulis' => ['nullable', 'string', 'max:30'],
            'kepalaSatker' => ['nullable', 'string', 'max:255'],
            'nipKepalaSatker' => ['nullable', 'string', 'max:30'],
            'kotaTtd' => ['nullable', 'string', 'max:255'],
            'linkLampiranBasisData' => ['nullable', 'string', 'max:2048'],
        ]);

        $this->notula()->update([
            'hari_tanggal' => $data['hariTanggal'],
            'waktu' => $data['waktu'],
            'tempat' => $data['tempat'],
            'pimpinan_rapat' => $data['pimpinanRapat'],
            'nip_pimpinan_rapat' => $data['nipPimpinanRapat'],
            'notulis' => $data['notulis'],
            'nip_notulis' => $data['nipNotulis'],
            'kepala_satker' => $data['kepalaSatker'],
            'nip_kepala_satker' => $data['nipKepalaSatker'],
            'kota_ttd' => $data['kotaTtd'],
            'link_lampiran_basis_data' => $data['linkLampiranBasisData'],
        ]);

        session()->flash('status', 'Detail rapat berhasil disimpan.');
    }

    /**
     * Nama & NIP seluruh pengguna, dipakai formulir Detail Rapat supaya Pimpinan
     * Rapat/Notulis/Kepala Satker bisa dipilih dari daftar pengguna (dengan NIP
     * ikut terisi otomatis) atau tetap diketik manual bila bukan pengguna terdaftar.
     *
     * @return Collection<int, array{nama: string, nip: ?string}>
     */
    protected function daftarPegawai(): Collection
    {
        return User::whereNotNull('nama')->where('nama', '!=', '')
            ->orderBy('nama')
            ->get(['nama', 'nip'])
            ->map(fn (User $u) => ['nama' => $u->nama, 'nip' => $u->nip]);
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

    /**
     * Format yang didukung untuk Bagian II/III (lihat NotulaService::konversiKeKontenInline()):
     * dokumen teks (docx/xlsx/dll., dikonversi ke HTML inline yang bisa reflow/menyambung),
     * gambar (ditempel langsung), atau PDF (dirasterisasi jadi blok gambar per halaman —
     * mis. untuk berkas hasil pindai/tanda tangan basah).
     */
    public const FORMAT_BAGIAN_DIDUKUNG = 'docx,doc,xlsx,xls,odt,ods,jpg,jpeg,png,pdf';

    public function unggahBagian(int $bagianKe): void
    {
        $field = $bagianKe === 2 ? 'bagian2File' : 'bagian3File';

        $this->validate([
            $field => ['required', 'file', 'mimes:'.self::FORMAT_BAGIAN_DIDUKUNG, 'max:10240'],
        ], [
            "{$field}.mimes" => 'Berkas Bagian '.($bagianKe === 2 ? 'II' : 'III').' harus berformat docx/xlsx/odt/ods, gambar (jpg/png), atau PDF.',
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
            'daftarPegawai' => $this->daftarPegawai(),
        ]);
    }
}
