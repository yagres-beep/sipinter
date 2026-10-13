<?php

namespace App\Livewire;

use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\User;
use App\Services\NotulaService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
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

    public string $notulis = '';

    public string $kepalaSatker = '';

    public string $kotaTtd = '';

    public string $linkLampiranBasisData = '';

    /**
     * Menahan aksi "Susun Ulang Otomatis"/"Pulihkan Suntingan Sebelumnya" di balik
     * modal konfirmasi bertema SIPINTER (x-confirm-modal) alih-alih wire:confirm
     * bawaan Livewire, yang me-render confirm() native browser dan tidak ikut
     * tema/dark-mode situs -- lihat resources/views/components/confirm-modal.blade.php.
     */
    public bool $konfirmasiSusunUlang = false;

    public bool $konfirmasiPulihkan = false;

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
        $this->resetErrorBag();
        $notula = $this->notula();

        // Kegagalan penyusunan otomatis (mis. LibreOffice tidak terpasang/timeout,
        // template rusak) TIDAK boleh meruntuhkan seluruh halaman -- tampilkan pesannya
        // di atas editor, Tim SAKIP tetap bisa mengisi Detail Rapat/unggah Bagian II-III.
        if (blank($notula->bagian1_html)) {
            try {
                app(NotulaService::class)->susunBagianSatu($notula);
                $notula->refresh();
            } catch (\Throwable $e) {
                report($e);
                $this->addError('bagian1', 'Bagian I belum bisa disusun otomatis: '.$e->getMessage());
            }
        }

        $this->bagian1EditText = $notula->bagian1_html ?? '';
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
        $this->notulis = $notula->notulis ?? '';
        $this->kepalaSatker = $notula->kepala_satker ?? '';
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
            'notulis' => ['nullable', 'string', 'max:255'],
            'kepalaSatker' => ['nullable', 'string', 'max:255'],
            'kotaTtd' => ['nullable', 'string', 'max:255'],
            'linkLampiranBasisData' => ['nullable', 'string', 'max:2048'],
        ]);

        $notula = $this->notula();
        $notula->update([
            'hari_tanggal' => $data['hariTanggal'],
            'waktu' => $data['waktu'],
            'tempat' => $data['tempat'],
            'pimpinan_rapat' => $data['pimpinanRapat'],
            'notulis' => $data['notulis'],
            'kepala_satker' => $data['kepalaSatker'],
            'kota_ttd' => $data['kotaTtd'],
            'link_lampiran_basis_data' => $data['linkLampiranBasisData'],
        ]);

        // Hari/tanggal, waktu, tempat, pimpinan rapat & link lampiran TERCETAK di
        // dalam bagian1_html (diisi saat Bagian I disusun) -- tidak diperbarui
        // diam-diam di HTML yang mungkin sudah disunting manual, jadi Tim SAKIP diberi
        // tahu bahwa Bagian I masih memuat nilai lama. Notulis/Kota/tanggal TTD tidak
        // perlu, karena blok TTD akhir selalu dibaca langsung dari data terbaru.
        $tercetakDiBagian1 = ['hari_tanggal', 'waktu', 'tempat', 'pimpinan_rapat', 'link_lampiran_basis_data'];
        $pesan = 'Detail rapat berhasil disimpan.';
        if (filled($notula->bagian1_html) && $notula->wasChanged($tercetakDiBagian1)) {
            $pesan .= ' Bagian I masih memuat detail rapat sebelumnya -- tekan "Susun Ulang Otomatis" supaya ikut diperbarui (bagian lain yang sudah Anda sunting tetap dipertahankan).';
        }

        session()->flash('status', $pesan);
    }

    /**
     * Nama seluruh pengguna, dipakai formulir Detail Rapat supaya Pimpinan Rapat/
     * Notulis/Kepala Satker bisa dipilih dari daftar pengguna atau tetap diketik
     * manual bila bukan pengguna terdaftar — lihat datalist di kompilasi-notula.blade.php.
     *
     * @return Collection<int, string>
     */
    protected function daftarPegawai(): Collection
    {
        return User::whereNotNull('nama')->where('nama', '!=', '')
            ->orderBy('nama')
            ->pluck('nama');
    }

    /**
     * "Susun Ulang Otomatis" memperbarui pratinjau dari data terverifikasi terkini
     * sambil mempertahankan bagian yang sudah disunting (lihat susunUlangOtomatis()).
     * Isi LAMANYA tetap disalin dulu ke bagian1_html_cadangan supaya kalau tombolnya
     * kepencet tidak sengaja / hasil susun ulangnya ternyata tidak diinginkan, Tim
     * SAKIP masih bisa memulihkannya lewat pulihkanSuntinganBagian1() -- lihat tombol
     * "Pulihkan Suntingan Sebelumnya" di Blade (cuma tampil bila cadangan ini terisi).
     * Konfirmasi sebelum menimpa (modal $konfirmasiSusunUlang) sudah dipasang di
     * tombolnya sendiri -- lihat mintaSusunUlangOtomatis()/batalSusunUlangOtomatis().
     */
    public function mintaSusunUlangOtomatis(): void
    {
        $this->konfirmasiSusunUlang = true;
    }

    public function batalSusunUlangOtomatis(): void
    {
        $this->konfirmasiSusunUlang = false;
    }

    /**
     * Default-nya MENGGABUNGKAN: isi baru dari data (IKU baru, angka/kendala/RTL yang
     * berubah) masuk, tapi bagian yang sudah disunting di pratinjau dipertahankan --
     * lihat NotulaService::susunUlangBagianSatu(). $timpaSemua = susun penuh dari nol
     * (tombol "Timpa Semua Suntingan" di modal konfirmasi).
     */
    public function susunUlangOtomatis(bool $timpaSemua = false): void
    {
        $this->konfirmasiSusunUlang = false;

        $notula = $this->notula();
        $isiLama = $notula->bagian1_html;
        $suntinganSaatIni = filled($this->bagian1EditText) ? $this->bagian1EditText : $isiLama;

        // Cadangan baru ditulis SETELAH penyusunan berhasil -- bila gagal, isi Bagian I
        // maupun cadangan sebelumnya tetap utuh (tidak tertimpa diam-diam).
        try {
            $hasil = app(NotulaService::class)->susunUlangBagianSatu($notula, $suntinganSaatIni, $timpaSemua);
            $html = $hasil['html'];
        } catch (\Throwable $e) {
            report($e);
            $this->addError('bagian1', 'Susun ulang gagal, Bagian I tidak diubah: '.$e->getMessage());

            return;
        }

        if (filled($isiLama)) {
            $notula->update(['bagian1_html_cadangan' => $isiLama]);
        }

        // Isi notula berubah -> hasil gabungan lama tidak valid lagi, dan kalau notula
        // triwulan ini sudah terlanjur ber-TTD, ia dibuka kembali sebagai versi baru
        // (lihat Notula::tandaiPerluDigabungUlang()) supaya dokumen final yang beredar
        // tidak pernah berbeda isi dari yang benar-benar disetujui Kepala.
        $notula->tandaiPerluDigabungUlang(Auth::user(), 'Bagian I disusun ulang otomatis setelah notula disetujui — notula dibuka kembali sebagai versi baru dan perlu digabung serta disetujui ulang.');

        $this->bagian1EditText = $html;
        $this->dispatchKontenBagian1();

        if ($hasil['digabung']) {
            $pesan = 'Bagian I diperbarui dari data terverifikasi terbaru. '
                .($hasil['dipertahankan'] > 0
                    ? $hasil['dipertahankan'].' bagian yang sudah Anda sunting tetap dipertahankan.'
                    : 'Belum ada bagian yang disunting, jadi seluruhnya mengikuti data terbaru.');
        } elseif ($timpaSemua || blank($isiLama)) {
            $pesan = 'Bagian I berhasil disusun ulang penuh dari data terverifikasi.';
        } else {
            $pesan = 'Bagian I disusun ulang penuh dari data terverifikasi -- isi sebelumnya disusun dengan versi lama sehingga suntingannya belum bisa dipertahankan otomatis. Mulai sekarang suntingan Anda akan dipertahankan saat Susun Ulang.';
        }

        session()->flash('status', $pesan.' Isi sebelumnya dicadangkan -- tombol "Pulihkan Suntingan Sebelumnya" tersedia bila diperlukan.'.$this->imbuhanVersiBaru($notula));
    }

    /**
     * Imbuhan pesan "notula dibuka jadi versi N" untuk aksi-aksi yang mengubah isi
     * notula (sunting/susun ulang/pulihkan Bagian I, unggah Bagian II/III) — hanya
     * muncul bila aksi tsb BENAR-BENAR menarik notula yang sudah ber-TTD kembali ke
     * draft (lihat Notula::tandaiPerluDigabungUlang()), supaya Tim SAKIP langsung tahu
     * bahwa notula perlu digabung & dikirim ulang, bukan diam-diam berubah status.
     */
    protected function imbuhanVersiBaru(Notula $notula): string
    {
        if ($notula->status !== Notula::STATUS_DRAFT || $notula->versiSaatIni() <= 1) {
            return '';
        }

        return ' Notula yang sudah disetujui dibuka kembali sebagai versi '.$notula->versiSaatIni().' — gabungkan lalu kirim ulang ke Kepala untuk disetujui lagi.';
    }

    /**
     * Pulihkan bagian1_html_cadangan (lihat susunUlangOtomatis()) jadi isi pratinjau
     * aktif lagi -- cadangannya SENGAJA tidak langsung dikosongkan setelah dipulihkan,
     * supaya kalau Tim SAKIP tidak sengaja klik Susun Ulang Otomatis LAGI setelah ini,
     * isi yang baru saja dipulihkan tetap tercadangkan (bukan langsung hilang tanpa
     * jejak di percobaan kedua).
     */
    public function mintaPulihkanSuntinganBagian1(): void
    {
        $this->konfirmasiPulihkan = true;
    }

    public function batalPulihkanSuntinganBagian1(): void
    {
        $this->konfirmasiPulihkan = false;
    }

    public function pulihkanSuntinganBagian1(): void
    {
        $this->konfirmasiPulihkan = false;

        $notula = $this->notula();

        if (blank($notula->bagian1_html_cadangan)) {
            return;
        }

        $notula->update(['bagian1_html' => $notula->bagian1_html_cadangan]);
        $notula->tandaiPerluDigabungUlang(Auth::user(), 'Suntingan Bagian I dipulihkan setelah notula disetujui — notula dibuka kembali sebagai versi baru dan perlu digabung serta disetujui ulang.');

        $this->bagian1EditText = $notula->bagian1_html_cadangan;
        $this->dispatchKontenBagian1();

        session()->flash('status', 'Suntingan sebelumnya berhasil dipulihkan.'.$this->imbuhanVersiBaru($notula));
    }

    /**
     * Editor Bagian I ditandai wire:ignore (lihat Blade) supaya suntingan langsung
     * di area WYSIWYG tidak diganggu morph Livewire saat mengetik — jadi begitu
     * kontennya diganti dari server (susun ulang / ganti triwulan), sisi klien perlu
     * diberi tahu lewat event browser untuk menimpa isinya secara eksplisit.
     */
    protected function dispatchKontenBagian1(): void
    {
        $this->dispatch('bagian1-diperbarui', html: $this->bagian1PreviewHtml());
    }

    /**
     * $bagian1EditText untuk PRATINJAU/EDIT di layar ini: Bagian II/III disisipkan
     * di posisi penanda {{bagian_2}}/{{bagian_3}} (lihat
     * NotulaService::sisipkanBagianDuaTiga()) supaya urutannya sesuai template docx
     * (sebelum tabel "Mengetahui/Kepala Satker/Notulis"), BUKAN ditempel di akhir
     * seperti sebelumnya. $bagian1EditText SENDIRI (yang disimpan lewat
     * simpanSuntinganBagian1()) tetap apa adanya dengan penanda mentahnya — hanya
     * tampilannya di sini yang disisipi.
     */
    protected function bagian1PreviewHtml(): string
    {
        $notula = $this->notula();

        return app(NotulaService::class)->sisipkanBagianDuaTiga($this->bagian1EditText, $notula->bagian2_html, $notula->bagian3_html, untukEditor: true);
    }

    /**
     * Isi editor dikirim balik lengkap dengan Bagian II/III yang tersisip untuk
     * pratinjau -- dikembalikan jadi penanda {{bagian_2}}/{{bagian_3}} supaya isi
     * unggahan tidak ikut tersimpan permanen di bagian1_html.
     */
    public function updatedBagian1EditText(string $nilai): void
    {
        $this->bagian1EditText = app(NotulaService::class)->kembalikanPenandaBagianDuaTiga($nilai);
    }

    public function simpanSuntinganBagian1(): void
    {
        $this->bagian1EditText = app(NotulaService::class)->kembalikanPenandaBagianDuaTiga($this->bagian1EditText);
        $notula = $this->notula();
        $notula->update(['bagian1_html' => $this->bagian1EditText]);
        $notula->tandaiPerluDigabungUlang(Auth::user(), 'Bagian I disunting setelah notula disetujui — notula dibuka kembali sebagai versi baru dan perlu digabung serta disetujui ulang.');

        session()->flash('status', 'Pratinjau Bagian I berhasil disimpan.'.$this->imbuhanVersiBaru($notula));
    }

    /**
     * Format yang didukung untuk Bagian II/III: hanya .docx, supaya bisa dikonversi
     * ke HTML inline yang reflow/menyambung mengikuti tata letak Bagian I
     * (lihat NotulaService::konversiKeKontenInline()).
     */
    public const FORMAT_BAGIAN_DIDUKUNG = 'docx';

    public function unggahBagian(int $bagianKe): void
    {
        $field = $bagianKe === 2 ? 'bagian2File' : 'bagian3File';

        $this->validate([
            $field => ['required', 'file', 'mimes:'.self::FORMAT_BAGIAN_DIDUKUNG, 'max:10240'],
        ], [
            "{$field}.mimes" => 'Berkas Bagian '.($bagianKe === 2 ? 'II' : 'III').' harus berformat .docx.',
        ]);

        try {
            app(NotulaService::class)->terimaUploadBagian($this->notula(), $bagianKe, $this->{$field}, Auth::user());
            session()->flash('status', "Bagian {$bagianKe} berhasil diunggah & dikonversi ke PDF.".$this->imbuhanVersiBaru($this->notula()));
        } catch (RuntimeException $e) {
            $this->addError($field, $e->getMessage());

            return;
        }

        $this->reset($field);
    }

    public function gabungkan(): void
    {
        try {
            app(NotulaService::class)->gabungkan($this->notula(), Auth::user());
            session()->flash('status', 'Ketiga bagian berhasil digabungkan. Notula menunggu persetujuan Kepala.');
        } catch (RuntimeException $e) {
            $this->addError('gabung', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            $this->addError('gabung', 'Notula gagal digabungkan: '.$e->getMessage());
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
            'bagian1PreviewHtml' => $this->bagian1PreviewHtml(),
        ]);
    }
}
