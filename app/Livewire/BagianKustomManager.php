<?php

namespace App\Livewire;

use App\Exceptions\KonflikPenyuntinganException;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Capaian;
use App\Models\FolderConfig;
use App\Models\LogAktivitas;
use App\Models\RiwayatKonfigurasiBagianKustom;
use App\Support\FormatAngkaKolom;
use App\Support\MenuBagianKustom;
use App\Support\TemplateBagianKustom;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Tim SAKIP — atur SEMUA bagian tambahan (mis. "Manajemen Risiko") langsung dari layar:
 * siapa yang mengisi, kolom isian (seperti tabel), frekuensi & bukti, dan apakah serta
 * bagaimana tampil di notula. Tidak perlu mengubah kode.
 */
class BagianKustomManager extends Component
{
    public string $namaBaru = '';

    public string $deskripsiBaru = '';

    /** @var array<int, string> peran yang boleh melihat/mengisi bagian baru */
    public array $peranBaru = [BagianKustom::PERAN_KETUA_TIM];

    /** @var array<int, string> menu tempat bagian baru ditempatkan */
    public array $penempatanBaru = [MenuBagianKustom::PENGISIAN];

    public string $frekuensiWajibBaru = BagianKustom::FREKUENSI_OPSIONAL;

    /**
     * Bagian sumber bila bagian baru dibuat dengan MENYALIN pengaturannya (null = mulai
     * dari kosong). Yang disalin hanya definisi — tidak pernah poin isian, bukti dukung,
     * status verifikasi, atau riwayat milik bagian sumber.
     */
    public ?int $sumberSalinId = null;

    /**
     * Keadaan form per bagian, dikunci pada id. Kolom isian disimpan sebagai daftar baris
     * (label, tipe, opsi_teks, wajib, kunci) agar bisa diedit langsung di layar.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $form = [];

    /** Bagian yang sedang dibuka untuk diatur (null = semua tertutup). */
    public ?int $bukaId = null;

    /** Tab yang tampil pada kartu yang sedang dibuka: dasar | isian | notula | pratinjau. */
    public string $tab = 'dasar';

    public ?int $pendingHapusId = null;

    public string $pendingHapusNama = '';

    /**
     * Sidik jari definisi tiap bagian saat form-nya dimuat. Simpan ditolak bila definisi
     * di database sudah berbeda (diubah Tim SAKIP lain) — perubahan orang lain tidak
     * tertimpa diam-diam. Dikunci: hanya server yang mengisinya.
     *
     * @var array<int, string>
     */
    #[Locked]
    public array $versiForm = [];

    /**
     * Hanya Tim SAKIP yang boleh mengelola definisi bagian kustom. boot() berjalan di
     * SETIAP request Livewire (mount dan setiap aksi), jadi pengguna yang kehilangan
     * peran Tim SAKIP saat halaman masih terbuka tetap ditolak di aksi berikutnya —
     * tidak hanya mengandalkan middleware rute halaman atau tombol yang disembunyikan.
     */
    public function boot(): void
    {
        if (auth()->user()?->namaRole() !== BagianKustom::NAMA_PERAN[BagianKustom::PERAN_TIM_SAKIP]) {
            LogAktivitas::aksesDitolak('pengaturan Bagian Kustom');
            abort(403);
        }
    }

    public function mount(): void
    {
        foreach (BagianKustom::orderBy('urutan')->orderBy('id')->get() as $bagian) {
            $this->muatForm($bagian->id);
        }
    }

    public function daftar()
    {
        return BagianKustom::orderBy('urutan')->orderBy('id')->withCount('poin')->get();
    }

    /**
     * Isi form dari data tersimpan (juga dipakai untuk "Batal ubah").
     */
    protected function muatForm(int $id): void
    {
        $bagian = BagianKustom::findOrFail($id);

        $this->versiForm[$id] = self::versi($bagian);

        $this->form[$id] = [
            'nama' => $bagian->nama,
            'deskripsi' => $bagian->deskripsi,
            'peran' => $bagian->peran ?? [],
            'penempatan' => $bagian->penempatan ?? [],
            'label_uraian' => $bagian->label_uraian,
            'frekuensi_wajib' => $bagian->frekuensi_wajib,
            'bukti_wajib' => (bool) $bagian->bukti_wajib,
            'masuk_notula' => (bool) $bagian->masuk_notula,
            'judul_notula' => $bagian->judul_notula,
            'format_notula' => $bagian->format_notula,
            'kolom' => collect($bagian->kolomList())->map(fn ($k) => [
                'kunci' => $k['kunci'],
                'label' => $k['label'],
                'tipe' => $k['tipe'],
                'opsi_teks' => implode(', ', $k['opsi']),
                'wajib' => $k['wajib'],
                'aktif' => $k['aktif'],
                'mulai_tahun' => $k['mulai_tahun'] !== null ? (string) $k['mulai_tahun'] : '',
                'mulai_triwulan' => $k['mulai_triwulan'] !== null ? (string) $k['mulai_triwulan'] : '',
                'satuan' => (string) $k['satuan'],
                'desimal' => $k['desimal'] !== null ? (string) $k['desimal'] : '',
                'pemisah' => $k['pemisah'],
                'ribuan' => $k['ribuan'],
            ])->values()->all(),
        ];
    }

    /**
     * Sidik jari isi definisi yang ditulis tombol Simpan (bukan waktu ubah) untuk deteksi
     * bentrok. Status aktif & urutan tidak ikut: keduanya diubah lewat tombol sendiri
     * dan tidak ditimpa oleh Simpan.
     */
    protected static function versi(BagianKustom $bagian): string
    {
        return sha1((string) json_encode(array_diff_key($bagian->potretDefinisi(), ['aktif' => true])));
    }

    /**
     * Jalankan $ubah di dalam SATU transaksi bersama pencatatan riwayatnya: bila
     * pencatatan riwayat gagal, perubahan konfigurasi ikut batal (tidak ada perubahan
     * tanpa jejak).
     *
     * @template T
     *
     * @param  callable(): T  $ubah
     * @return T
     */
    protected function dalamTransaksi(callable $ubah): mixed
    {
        return DB::transaction($ubah);
    }

    /** Baris form kolom baru. */
    protected function kolomKosong(int $id): array
    {
        // Bagian yang sudah punya isian: kolom baru default berlaku mulai triwulan
        // berjalan, supaya isian periode sebelumnya tidak dianggap kurang satu kolom.
        // Tim SAKIP tetap bisa mengubah atau mengosongkannya.
        $sudahBerisi = BagianKustomPoin::where('bagian_kustom_id', $id)->exists();

        return [
            'kunci' => '', 'label' => '', 'tipe' => 'teks', 'opsi_teks' => '', 'wajib' => false, 'aktif' => true,
            'mulai_tahun' => $sudahBerisi ? (string) now()->year : '',
            'mulai_triwulan' => $sudahBerisi ? (string) BagianKustom::triwulanDariBulan((int) now()->month) : '',
            'satuan' => '', 'desimal' => '', 'pemisah' => FormatAngkaKolom::PEMISAH_KOMA, 'ribuan' => true,
        ];
    }

    public function buka(int $id): void
    {
        if ($this->bukaId === $id) {
            $this->bukaId = null;

            return;
        }

        $this->bukaId = $id;
        $this->tab = 'dasar';
        $this->resetErrorBag();
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['dasar', 'isian', 'notula', 'pratinjau', 'riwayat'], true)) {
            $this->tab = $tab;
        }
    }

    /**
     * Saat bagian sumber dipilih, isi peran, menu, frekuensi, dan deskripsi form tambah
     * dari bagian tersebut — semuanya masih bisa disesuaikan sebelum disimpan.
     */
    public function updatedSumberSalinId($nilai): void
    {
        $this->resetErrorBag('sumberSalinId');
        if (blank($nilai)) {
            $this->sumberSalinId = null;

            return;
        }

        $sumber = BagianKustom::find((int) $nilai);

        if (! $sumber) {
            // Dibiarkan apa adanya supaya tambah() menolaknya lewat validasi exists.
            $this->addError('sumberSalinId', 'Bagian sumber tidak ditemukan.');

            return;
        }

        $this->sumberSalinId = $sumber->id;
        $this->peranBaru = array_values($sumber->peran ?? []);
        $this->penempatanBaru = array_values($sumber->penempatan ?? []);
        $this->frekuensiWajibBaru = (string) $sumber->frekuensi_wajib;

        if (trim($this->deskripsiBaru) === '') {
            $this->deskripsiBaru = (string) $sumber->deskripsi;
        }
    }

    /**
     * Kolom yang ikut disalin: hanya kolom AKTIF, dengan kunci yang sama (kunci hanya
     * berlaku di dalam satu bagian, jadi data dua bagian tidak pernah tercampur) dan
     * tanpa periode mulai berlaku (bagian baru belum punya riwayat).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function kolomSalinan(BagianKustom $sumber): array
    {
        return collect($sumber->kolomList())
            ->filter(fn ($k) => $k['aktif'])
            ->map(fn ($k) => ['mulai_tahun' => null, 'mulai_triwulan' => null] + $k)
            ->values()
            ->all();
    }

    /**
     * Ringkasan yang ditampilkan sebelum bagian salinan disimpan.
     *
     * @return array{nama: string, kolom: array<int, array<string, mixed>>, kolom_nonaktif: int, label_uraian: string, notula: string, bukti: bool, poin_sumber: int}|null
     */
    protected function ringkasanSalin(): ?array
    {
        $sumber = $this->sumberSalinId ? BagianKustom::withCount('poin')->find($this->sumberSalinId) : null;

        if (! $sumber) {
            return null;
        }

        return [
            'nama' => $sumber->nama,
            'kolom' => $this->kolomSalinan($sumber),
            'kolom_nonaktif' => collect($sumber->kolomList())->where('aktif', false)->count(),
            'label_uraian' => $sumber->labelUraianEfektif(),
            'notula' => $sumber->masuk_notula ? 'Masuk notula, format: '.$sumber->formatNotulaEfektif() : 'Tidak masuk notula',
            'bukti' => (bool) $sumber->bukti_wajib,
            'poin_sumber' => (int) $sumber->poin_count,
        ];
    }

    /**
     * Tukar urutan dengan bagian TEPAT DI ATASNYA pada daftar yang sedang tampil.
     */
    public function naikkan(int $id): void
    {
        $daftar = $this->daftar()->values();
        $posisi = $daftar->search(fn ($b) => $b->id === $id);

        if ($posisi === false || $posisi === 0) {
            return;
        }

        $this->tukarUrutan($daftar[$posisi], $daftar[$posisi - 1], 'atas');
    }

    /**
     * Tukar urutan dua bagian + catat riwayat dalam satu transaksi. Urutan dibaca ulang
     * dengan kunci baris supaya dua klik bersamaan tidak saling menimpa.
     */
    protected function tukarUrutan(BagianKustom $ini, BagianKustom $lain, string $arah): void
    {
        $this->dalamTransaksi(function () use ($ini, $lain, $arah) {
            $terkunci = BagianKustom::whereKey([$ini->id, $lain->id])->lockForUpdate()->get()->keyBy('id');

            if ($terkunci->count() !== 2) {
                return;
            }

            $urutanIni = $terkunci[$ini->id]->urutan;
            $urutanLain = $terkunci[$lain->id]->urutan;

            BagianKustom::whereKey($ini->id)->update(['urutan' => $urutanLain]);
            BagianKustom::whereKey($lain->id)->update(['urutan' => $urutanIni]);

            RiwayatKonfigurasiBagianKustom::catat($ini, $ini->nama, 'urutan', ['urutan' => $urutanIni], ['urutan' => $urutanLain], 'Dipindah ke '.$arah.' "'.$lain->nama.'"');
        });

        BagianKustom::lupakanCache();
    }

    /**
     * Tukar urutan dengan bagian TEPAT DI BAWAHNYA pada daftar yang sedang tampil.
     */
    public function turunkan(int $id): void
    {
        $daftar = $this->daftar()->values();
        $posisi = $daftar->search(fn ($b) => $b->id === $id);

        if ($posisi === false || $posisi === $daftar->count() - 1) {
            return;
        }

        $this->tukarUrutan($daftar[$posisi], $daftar[$posisi + 1], 'bawah');
    }

    public function tambah(): void
    {
        $this->validate([
            'namaBaru' => ['required', 'string', 'max:255'],
            'deskripsiBaru' => ['nullable', 'string', 'max:1000'],
            'peranBaru' => ['required', 'array', 'min:1'],
            'peranBaru.*' => [Rule::in(array_keys(BagianKustom::NAMA_PERAN))],
            'penempatanBaru' => ['required', 'array', 'min:1'],
            'penempatanBaru.*' => [Rule::in(array_keys(MenuBagianKustom::katalog()))],
            'frekuensiWajibBaru' => ['required', Rule::in([BagianKustom::FREKUENSI_OPSIONAL, BagianKustom::FREKUENSI_SETIAP_BULAN, BagianKustom::FREKUENSI_AKHIR_TRIWULAN])],
            'sumberSalinId' => ['nullable', 'integer', Rule::exists('bagian_kustom', 'id')],
        ], [], ['namaBaru' => 'nama bagian', 'sumberSalinId' => 'bagian sumber']);

        $sumber = $this->sumberSalinId ? BagianKustom::find($this->sumberSalinId) : null;

        $sudahAda = BagianKustom::whereRaw('LOWER(nama) = ?', [mb_strtolower(trim($this->namaBaru))])->exists();

        if ($sudahAda) {
            $this->addError('namaBaru', 'Bagian dengan nama tersebut sudah ada.');

            return;
        }

        $peran = array_values(array_unique($this->peranBaru));
        $penempatan = array_values(array_unique($this->penempatanBaru));

        if (! $this->penempatanValid($peran, $penempatan)) {
            $this->addError('penempatanBaru', 'Ada menu yang tidak bisa dibuka oleh peran yang dipilih.');

            return;
        }

        // Bagian baru, riwayatnya, dan kategori foldernya dibuat dalam satu transaksi:
        // bila salah satu gagal, tidak ada yang tersimpan.
        $bagian = $this->dalamTransaksi(function () use ($sumber, $peran, $penempatan) {
            $bagian = BagianKustom::create([
                'nama' => trim($this->namaBaru),
                'deskripsi' => trim($this->deskripsiBaru) ?: null,
                'peran' => $peran,
                'penempatan' => $penempatan,
                'frekuensi_wajib' => $this->frekuensiWajibBaru,
                // Bagian baru dari kosong: bukti wajib bila memang didukung (perilaku lama).
                // Salinan: ikut pengaturan sumber, tetap hanya bila didukung peran & menu.
                'bukti_wajib' => $this->bukti_wajib_boleh($peran, $penempatan) && ($sumber ? (bool) $sumber->bukti_wajib : true),
                'masuk_notula' => $sumber ? (bool) $sumber->masuk_notula : true,
                'label_uraian' => $sumber?->label_uraian,
                // Judul notula TIDAK disalin: kosong = memakai nama bagian baru.
                'format_notula' => $sumber?->format_notula,
                'kolom' => $sumber ? $this->kolomSalinan($sumber) : [],
                'aktif' => true,
                'urutan' => ((int) BagianKustom::max('urutan')) + 1,
            ]);

            RiwayatKonfigurasiBagianKustom::catat(
                $bagian,
                $bagian->nama,
                $sumber ? 'salin' : 'buat',
                null,
                $bagian->potretDefinisi(),
                $sumber ? 'Dibuat dengan menyalin pengaturan "'.$sumber->nama.'" (tanpa isian, bukti, maupun riwayatnya)' : 'Dibuat dari kosong',
            );

            FolderConfig::tambahKategoriDariBagianKustom($bagian->nama);

            return $bagian;
        });

        $this->reset(['namaBaru', 'deskripsiBaru', 'sumberSalinId']);
        $this->peranBaru = [BagianKustom::PERAN_KETUA_TIM];
        $this->penempatanBaru = [MenuBagianKustom::PENGISIAN];
        $this->frekuensiWajibBaru = BagianKustom::FREKUENSI_OPSIONAL;

        BagianKustom::lupakanCache();
        $this->muatForm($bagian->id);
        $this->bukaId = $bagian->id;
        $this->tab = 'isian';

        session()->flash('status', $sumber
            ? 'Bagian "'.$bagian->nama.'" dibuat dari salinan pengaturan "'.$sumber->nama.'". Periksa kolom, peran, dan notula di bawah ini — isian milik "'.$sumber->nama.'" tidak ikut disalin.'
            : 'Bagian "'.$bagian->nama.'" dibuat. Atur kolom isian, peran pengisi, dan notula di bawah ini — folder kategorinya sudah ditambahkan ke Struktur Folder.');
    }

    /**
     * Setiap menu yang dipilih harus bisa dibuka setidaknya oleh salah satu peran yang
     * dipilih (mis. menu "Verifikasi & Notula" hanya untuk Tim SAKIP).
     *
     * @param  array<int, string>  $peran
     * @param  array<int, string>  $penempatan
     */
    protected function penempatanValid(array $peran, array $penempatan): bool
    {
        foreach ($penempatan as $menu) {
            if (array_intersect($peran, MenuBagianKustom::peranDiizinkan($menu)) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bukti PDF hanya berlaku untuk isian di menu Isian Kegiatan oleh Ketua Tim.
     *
     * @param  array<int, string>  $peran
     * @param  array<int, string>  $penempatan
     */
    protected function bukti_wajib_boleh(array $peran, array $penempatan): bool
    {
        return in_array(MenuBagianKustom::PENGISIAN, $penempatan, true) && in_array(BagianKustom::PERAN_KETUA_TIM, $peran, true);
    }

    /* ---------- Kolom isian (baris di form) ---------- */

    public function tambahKolom(int $id): void
    {
        abort_unless(isset($this->form[$id]), 404);

        $this->form[$id]['kolom'][] = $this->kolomKosong($id);
    }

    public function hapusKolom(int $id, int $index): void
    {
        unset($this->form[$id]['kolom'][$index]);
        $this->form[$id]['kolom'] = array_values($this->form[$id]['kolom']);
    }

    public function naikkanKolom(int $id, int $index): void
    {
        if ($index <= 0) {
            return;
        }

        $kolom = $this->form[$id]['kolom'];
        [$kolom[$index - 1], $kolom[$index]] = [$kolom[$index], $kolom[$index - 1]];
        $this->form[$id]['kolom'] = $kolom;
    }

    public function turunkanKolom(int $id, int $index): void
    {
        $kolom = $this->form[$id]['kolom'];

        if ($index >= count($kolom) - 1) {
            return;
        }

        [$kolom[$index + 1], $kolom[$index]] = [$kolom[$index], $kolom[$index + 1]];
        $this->form[$id]['kolom'] = $kolom;
    }

    /**
     * Isi kotak format notula dengan salah satu contoh siap pakai.
     */
    public function pakaiFormatContoh(int $id, string $jenis): void
    {
        $kolom = collect($this->form[$id]['kolom'] ?? [])->pluck('kunci')->filter()->all();

        $this->form[$id]['format_notula'] = match ($jenis) {
            'lengkap' => $kolom === []
                ? TemplateBagianKustom::FORMAT_STANDAR
                : '{kode_iku}: {teks}'.' ('.implode(', ', array_map(fn ($k) => '{'.$k.'}', $kolom)).')',
            default => TemplateBagianKustom::FORMAT_STANDAR,
        };
    }

    /**
     * Batalkan perubahan yang belum disimpan pada satu bagian.
     */
    public function batalUbah(int $id): void
    {
        $this->resetErrorBag();
        $this->muatForm($id);
    }

    /* ---------- Simpan ---------- */

    protected function aturanForm(int $id): array
    {
        return [
            "form.{$id}.nama" => ['required', 'string', 'max:255'],
            "form.{$id}.deskripsi" => ['nullable', 'string', 'max:1000'],
            "form.{$id}.peran" => ['required', 'array', 'min:1'],
            "form.{$id}.peran.*" => [Rule::in(array_keys(BagianKustom::NAMA_PERAN))],
            "form.{$id}.penempatan" => ['required', 'array', 'min:1'],
            "form.{$id}.penempatan.*" => [Rule::in(array_keys(MenuBagianKustom::katalog()))],
            "form.{$id}.label_uraian" => ['nullable', 'string', 'max:100'],
            "form.{$id}.frekuensi_wajib" => ['required', Rule::in([BagianKustom::FREKUENSI_OPSIONAL, BagianKustom::FREKUENSI_SETIAP_BULAN, BagianKustom::FREKUENSI_AKHIR_TRIWULAN])],
            "form.{$id}.bukti_wajib" => ['boolean'],
            "form.{$id}.masuk_notula" => ['boolean'],
            "form.{$id}.judul_notula" => ['nullable', 'string', 'max:255'],
            "form.{$id}.format_notula" => ['nullable', 'string', 'max:500'],
            "form.{$id}.kolom" => ['array', 'max:12'],
            "form.{$id}.kolom.*.label" => ['required', 'string', 'max:100'],
            "form.{$id}.kolom.*.tipe" => ['required', Rule::in(array_keys(BagianKustom::TIPE_KOLOM))],
            "form.{$id}.kolom.*.opsi_teks" => ['nullable', 'string', 'max:500'],
            "form.{$id}.kolom.*.wajib" => ['boolean'],
            "form.{$id}.kolom.*.aktif" => ['boolean'],
            "form.{$id}.kolom.*.mulai_tahun" => ['nullable', 'integer', 'min:2020', 'max:2100'],
            "form.{$id}.kolom.*.mulai_triwulan" => ['nullable', 'integer', 'min:1', 'max:4'],
            "form.{$id}.kolom.*.satuan" => ['nullable', 'string', 'max:30'],
            "form.{$id}.kolom.*.desimal" => ['nullable', 'integer', 'min:0', 'max:'.FormatAngkaKolom::DESIMAL_MAKS],
            "form.{$id}.kolom.*.pemisah" => ['nullable', Rule::in(array_keys(FormatAngkaKolom::PEMISAH))],
            "form.{$id}.kolom.*.ribuan" => ['boolean'],
        ];
    }

    protected function pesanForm(int $id): array
    {
        return [
            "form.{$id}.nama.required" => 'Nama bagian wajib diisi.',
            "form.{$id}.kolom.*.label.required" => 'Label kolom wajib diisi (atau hapus baris kolomnya).',
        ];
    }

    /**
     * Bentuk kolom yang tersimpan dari baris form. Kunci kolom lama DIPERTAHANKAN (dipakai
     * sebagai nama data), kolom baru dibuat dari labelnya. Mengembalikan error bila
     * pilihan kurang dari dua opsi.
     *
     * @return array<int, array{kunci: string, label: string, tipe: string, opsi: array<int, string>, wajib: bool}>
     */
    protected function kolomDariForm(int $id): array
    {
        $baris = $this->form[$id]['kolom'] ?? [];

        $kunciTerpakai = collect($baris)->pluck('kunci')->filter()->values()->all();
        $hasil = [];

        foreach ($baris as $i => $row) {
            $kunci = (string) ($row['kunci'] ?? '');

            if ($kunci === '') {
                $kunci = TemplateBagianKustom::kunciBaru((string) $row['label'], $kunciTerpakai);
                $kunciTerpakai[] = $kunci;
            }

            $opsi = $this->opsiDariTeks((string) ($row['opsi_teks'] ?? ''));

            if ($row['tipe'] === 'pilihan' && count($opsi) < 2) {
                $this->addError("form.{$id}.kolom.{$i}.opsi_teks", 'Kolom pilihan butuh minimal dua opsi, dipisah koma.');
            }

            if (filled($row['mulai_tahun'] ?? null) xor filled($row['mulai_triwulan'] ?? null)) {
                $this->addError("form.{$id}.kolom.{$i}.mulai_triwulan", 'Isi tahun dan triwulan mulai berlaku bersamaan, atau kosongkan keduanya.');
            }

            $hasil[] = $this->kolomTersimpan($kunci, $row, $opsi);
        }

        return $hasil;
    }

    /**
     * Bentuk satu kolom yang disimpan di bagian_kustom.kolom. Pengaturan format hanya
     * untuk kolom angka.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $opsi
     * @return array<string, mixed>
     */
    protected function kolomTersimpan(string $kunci, array $row, array $opsi): array
    {
        $tipe = (string) ($row['tipe'] ?? 'teks');
        $angka = $tipe === 'angka';

        return BagianKustom::normalkanKolom([
            'kunci' => $kunci,
            'label' => trim((string) ($row['label'] ?? '')),
            'tipe' => $tipe,
            'opsi' => $tipe === 'pilihan' ? $opsi : [],
            'wajib' => (bool) ($row['wajib'] ?? false),
            'aktif' => (bool) ($row['aktif'] ?? true),
            'mulai_tahun' => $row['mulai_tahun'] ?? null,
            'mulai_triwulan' => $row['mulai_triwulan'] ?? null,
            'satuan' => $angka ? ($row['satuan'] ?? null) : null,
            'desimal' => $angka ? ($row['desimal'] ?? null) : null,
            'pemisah' => $row['pemisah'] ?? FormatAngkaKolom::PEMISAH_KOMA,
            'ribuan' => (bool) ($row['ribuan'] ?? true),
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function opsiDariTeks(string $teks): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,\n]+/', $teks)), fn ($o) => $o !== '')));
    }

    public function simpan(int $id): void
    {
        $bagian = BagianKustom::findOrFail($id);

        $this->resetErrorBag();
        $this->validate($this->aturanForm($id), $this->pesanForm($id));

        $f = $this->form[$id];
        $namaSama = BagianKustom::where('id', '!=', $id)->whereRaw('LOWER(nama) = ?', [mb_strtolower(trim($f['nama']))])->exists();

        if ($namaSama) {
            $this->addError("form.{$id}.nama", 'Sudah ada bagian lain dengan nama tersebut.');
        }

        $kolom = $this->kolomDariForm($id);

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        // Kolom yang sudah berisi data di poin tersimpan tidak boleh dihapus — datanya
        // akan hilang dari notula. Pulihkan dengan "Batal ubah" bila tidak jadi.
        $kunciBaru = collect($kolom)->pluck('kunci')->all();
        $kunciDihapus = collect($bagian->kolomList())->pluck('kunci')->diff($kunciBaru);

        if ($kunciDihapus->isNotEmpty()) {
            $nilaiTersimpan = BagianKustomPoin::where('bagian_kustom_id', $id)->whereNotNull('nilai_kolom')->pluck('nilai_kolom');

            foreach ($kunciDihapus as $kunci) {
                $jumlah = $nilaiTersimpan->filter(fn ($n) => filled($n[$kunci] ?? null))->count();

                if ($jumlah > 0) {
                    $label = collect($bagian->kolomList())->firstWhere('kunci', $kunci)['label'] ?? $kunci;
                    $this->addError("form.{$id}.kolom", "Kolom \"{$label}\" sudah berisi data di {$jumlah} poin, jadi tidak bisa dihapus. Klik \"Batal ubah\", lalu hapus centang \"Aktif\" pada kolom itu untuk berhenti memakainya — data lamanya tetap tersimpan dan tetap tampil di notula.");

                    return;
                }
            }
        }

        // Mengganti jenis isian kolom yang sudah berisi data hanya boleh bila data lama
        // cocok dengan jenis baru (mis. teks "12,5" → angka boleh; "tinggi" → angka tidak).
        if (($galatTipe = $this->galatGantiTipe($bagian, $kolom)) !== null) {
            $this->addError("form.{$id}.kolom", $galatTipe);

            return;
        }

        $tidakDikenal = TemplateBagianKustom::variabelTidakDikenal((string) ($f['format_notula'] ?? ''), $kunciBaru);

        if ($tidakDikenal !== []) {
            $this->addError("form.{$id}.format_notula", 'Variabel tidak dikenal: '.implode(', ', $tidakDikenal).'. Pakai daftar variabel di tab Notula.');

            return;
        }

        $peran = array_values(array_unique($f['peran']));
        $penempatan = array_values(array_unique($f['penempatan']));

        if (! $this->penempatanValid($peran, $penempatan)) {
            $this->addError("form.{$id}.penempatan", 'Ada menu yang tidak bisa dibuka oleh peran yang dipilih. Hapus centang menu itu, atau tambahkan peran yang sesuai.');

            return;
        }

        // Melepas menu Isian Kegiatan membuat poin bagian ini hilang dari layar
        // Verifikasi (lihat VerifikasiCapaian::bagianKustomList()) — tolak selama masih
        // ada poin yang sedang menunggu pemeriksaan Tim SAKIP.
        $lepasDariIsianKegiatan = in_array(MenuBagianKustom::PENGISIAN, $bagian->penempatan ?? [], true)
            && ! in_array(MenuBagianKustom::PENGISIAN, $penempatan, true);

        if ($lepasDariIsianKegiatan && ($jumlahMenunggu = $this->jumlahPoinMenungguVerifikasi($id)) > 0) {
            $this->addError("form.{$id}.penempatan", "Menu Isian Kegiatan belum bisa dilepas: masih ada {$jumlahMenunggu} poin bagian ini yang sedang menunggu verifikasi. Selesaikan verifikasinya lebih dulu.");

            return;
        }

        // Menu/peran yang dilepas tetapi masih punya poin tersimpan: datanya tetap ada
        // dan tetap masuk notula, hanya tidak tampil lagi untuk diisi.
        $konteksDilepas = $this->konteksDilepasBerisi($bagian, $peran, $penempatan);

        // Perubahan + riwayatnya dalam satu transaksi, dengan baris bagian dikunci dan
        // dicocokkan dengan versi saat form dimuat: bila Tim SAKIP lain sudah mengubah
        // bagian ini, simpan dibatalkan (isian form tetap di layar).
        try {
            $this->dalamTransaksi(function () use ($id, $f, $peran, $penempatan, $kolom) {
                $bagian = BagianKustom::whereKey($id)->lockForUpdate()->firstOrFail();

                if (! isset($this->versiForm[$id]) || self::versi($bagian) !== $this->versiForm[$id]) {
                    throw new KonflikPenyuntinganException(['bagian' => [$bagian->nama]]);
                }

                $sebelum = $bagian->potretDefinisi();

                $bagian->update([
                    'nama' => trim($f['nama']),
                    'deskripsi' => trim((string) ($f['deskripsi'] ?? '')) ?: null,
                    'peran' => $peran,
                    'penempatan' => $penempatan,
                    'label_uraian' => trim((string) ($f['label_uraian'] ?? '')) ?: null,
                    'frekuensi_wajib' => $f['frekuensi_wajib'],
                    // Bukti dukung (PDF) hanya didukung untuk isian di menu Isian Kegiatan (Ketua Tim).
                    'bukti_wajib' => $this->bukti_wajib_boleh($peran, $penempatan) && (bool) $f['bukti_wajib'],
                    'masuk_notula' => (bool) $f['masuk_notula'],
                    'judul_notula' => trim((string) ($f['judul_notula'] ?? '')) ?: null,
                    'format_notula' => trim((string) ($f['format_notula'] ?? '')) ?: null,
                    'kolom' => $kolom,
                ]);

                $sesudah = $bagian->fresh()->potretDefinisi();

                if ($sebelum != $sesudah) {
                    RiwayatKonfigurasiBagianKustom::catat($bagian, $bagian->nama, 'ubah', $sebelum, $sesudah);
                }
            });
        } catch (KonflikPenyuntinganException) {
            $this->addError("form.{$id}.bentrok", 'Pengaturan "'.$bagian->nama.'" sudah diubah Tim SAKIP lain sejak Anda membukanya, jadi perubahan Anda BELUM disimpan (isian Anda masih di layar). Catat perubahan Anda, klik "Batal ubah" untuk memuat pengaturan terbaru, lalu terapkan kembali.');

            return;
        }

        $bagian->refresh();
        BagianKustom::lupakanCache();
        $this->muatForm($id);

        session()->flash('status', 'Perubahan "'.$bagian->nama.'" disimpan. Isian yang sudah tersimpan tetap ada di notula.'
            .($konteksDilepas !== [] ? ' Perhatian: poin yang sudah diisi di '.implode(', ', $konteksDilepas).' tetap tersimpan, tetapi tidak tampil lagi untuk diisi.' : ''));
    }

    /**
     * Pesan galat bila jenis isian kolom yang sudah berisi data diganti ke jenis yang
     * tidak cocok dengan data lamanya, null bila aman.
     *
     * @param  array<int, array<string, mixed>>  $kolomBaru
     */
    protected function galatGantiTipe(BagianKustom $bagian, array $kolomBaru): ?string
    {
        $lama = collect($bagian->kolomList())->keyBy('kunci');
        $berganti = collect($kolomBaru)->filter(fn ($k) => $lama->has($k['kunci']) && $lama[$k['kunci']]['tipe'] !== $k['tipe'] && $k['tipe'] !== 'teks');

        if ($berganti->isEmpty()) {
            return null;
        }

        $nilaiTersimpan = BagianKustomPoin::where('bagian_kustom_id', $bagian->id)->whereNotNull('nilai_kolom')->pluck('nilai_kolom');

        foreach ($berganti as $k) {
            $tidakCocok = $nilaiTersimpan
                ->map(fn ($n) => trim((string) ($n[$k['kunci']] ?? '')))
                ->filter(fn ($v) => $v !== '' && match ($k['tipe']) {
                    'angka' => FormatAngkaKolom::urai($v, null) === null,
                    'pilihan' => ! in_array($v, $k['opsi'], true),
                    default => false,
                })
                ->unique()
                ->values();

            if ($tidakCocok->isNotEmpty()) {
                return "Jenis kolom \"{$k['label']}\" tidak bisa diganti menjadi ".BagianKustom::TIPE_KOLOM[$k['tipe']]
                    .' karena data lama tidak cocok: "'.$tidakCocok->take(3)->implode('", "').'"'.($tidakCocok->count() > 3 ? ' dan lainnya' : '')
                    .'. Biarkan jenisnya, atau nonaktifkan kolom ini dan buat kolom baru.';
            }
        }

        return null;
    }

    /**
     * Poin konteks Isian Kegiatan yang sedang dalam antrean verifikasi: poinnya belum
     * diperiksa dan Capaian IKU+periode-nya sedang diajukan/ditangani Tim SAKIP.
     */
    protected function jumlahPoinMenungguVerifikasi(int $bagianId): int
    {
        return BagianKustomPoin::konteksIsianKegiatan()
            ->where('bagian_kustom_id', $bagianId)
            ->where('status_verifikasi', 'menunggu')
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('capaian')
                ->whereColumn('capaian.iku_id', 'bagian_kustom_poin.iku_id')
                ->whereColumn('capaian.periode_id', 'bagian_kustom_poin.periode_id')
                ->whereIn('capaian.status', [Capaian::STATUS_DIAJUKAN, Capaian::STATUS_SEDANG_DITANGANI]))
            ->count();
    }

    /**
     * Label "menu (peran)" yang tidak lagi tampil setelah perubahan tetapi masih punya
     * poin tersimpan.
     *
     * @param  array<int, string>  $peranBaru
     * @param  array<int, string>  $penempatanBaru
     * @return array<int, string>
     */
    protected function konteksDilepasBerisi(BagianKustom $bagian, array $peranBaru, array $penempatanBaru): array
    {
        $hasil = [];

        foreach ($bagian->penempatan ?? [] as $menu) {
            foreach (array_intersect($bagian->peran ?? [], MenuBagianKustom::peranDiizinkan($menu)) as $peran) {
                $masihTampil = in_array($menu, $penempatanBaru, true) && in_array($peran, $peranBaru, true);

                if ($masihTampil || ! BagianKustomPoin::konteks($menu, $peran)->where('bagian_kustom_id', $bagian->id)->exists()) {
                    continue;
                }

                $hasil[] = MenuBagianKustom::label($menu).' ('.(BagianKustom::NAMA_PERAN[$peran] ?? $peran).')';
            }
        }

        return $hasil;
    }

    /**
     * Aktifkan/nonaktifkan. $aktifkan = keadaan yang dituju sesuai tombol yang diklik
     * (null = balik keadaan, perilaku lama) — bila keadaan di database sudah seperti itu
     * (diubah Tim SAKIP lain), tidak ada yang diubah, supaya klik tidak membalik
     * keputusan orang lain.
     */
    public function toggleAktif(int $id, ?bool $aktifkan = null): void
    {
        $this->dalamTransaksi(function () use ($id, $aktifkan) {
            $bagian = BagianKustom::whereKey($id)->lockForUpdate()->firstOrFail();
            $tujuan = $aktifkan ?? ! $bagian->aktif;

            if ((bool) $bagian->aktif === $tujuan) {
                return;
            }

            $bagian->update(['aktif' => $tujuan]);

            RiwayatKonfigurasiBagianKustom::catat($bagian, $bagian->nama, $tujuan ? 'aktifkan' : 'nonaktifkan', ['aktif' => ! $tujuan], ['aktif' => $tujuan],
                $tujuan ? 'Bagian diaktifkan' : 'Bagian dinonaktifkan (isian lama tetap tersimpan dan tetap masuk notula)');
        });

        if (isset($this->form[$id])) {
            $this->versiForm[$id] = self::versi(BagianKustom::findOrFail($id));
        }

        BagianKustom::lupakanCache();
    }

    public function confirmHapus(int $id): void
    {
        $bagian = BagianKustom::findOrFail($id);

        $this->pendingHapusId = $bagian->id;
        $this->pendingHapusNama = $bagian->nama;
    }

    public function batalkanHapus(): void
    {
        $this->pendingHapusId = null;
        $this->pendingHapusNama = '';
    }

    /**
     * Hapus permanen HANYA bila belum ada satu pun poin tersimpan untuk bagian ini —
     * bila sudah ada isian, nonaktifkan saja supaya data historis tidak hilang.
     */
    public function hapus(): void
    {
        if (! $this->pendingHapusId) {
            return;
        }

        $bagian = BagianKustom::findOrFail($this->pendingHapusId);

        // Pemeriksaan "belum punya isian" diulang di dalam transaksi dengan baris bagian
        // dikunci, dan riwayat ditulis di transaksi yang sama dengan penghapusannya.
        $dihapus = $this->dalamTransaksi(function () use ($bagian) {
            $terkunci = BagianKustom::whereKey($bagian->id)->lockForUpdate()->first();

            if (! $terkunci || BagianKustomPoin::where('bagian_kustom_id', $bagian->id)->exists()) {
                return false;
            }

            RiwayatKonfigurasiBagianKustom::catat($terkunci, $terkunci->nama, 'hapus', $terkunci->potretDefinisi(), null, 'Bagian dihapus (belum pernah punya isian)');
            $terkunci->delete();

            return true;
        });

        if (! $dihapus) {
            $this->batalkanHapus();
            $this->addError('hapus', 'Bagian "'.$bagian->nama.'" sudah punya isian tersimpan — nonaktifkan saja, jangan dihapus, supaya data lama tidak hilang.');

            return;
        }

        unset($this->form[$bagian->id], $this->versiForm[$bagian->id]);

        if ($this->bukaId === $bagian->id) {
            $this->bukaId = null;
        }

        $this->batalkanHapus();

        BagianKustom::lupakanCache();
        session()->flash('status', 'Bagian kustom dihapus.');
    }

    /* ---------- Pratinjau (dihitung dari form, termasuk yang belum disimpan) ---------- */

    /**
     * Contoh hasil notula dari keadaan form saat ini — dua poin contoh.
     *
     * @return array{judul: string, baris: array<int, string>, peringatan: array<int, string>}
     */
    protected function pratinjauNotula(int $id): array
    {
        $f = $this->form[$id];
        $kolom = $this->kolomDariFormTanpaError($id);
        $kunci = collect($kolom)->pluck('kunci')->all();
        $format = trim((string) ($f['format_notula'] ?? '')) ?: TemplateBagianKustom::FORMAT_STANDAR;

        $contohNilai = collect($kolom)->mapWithKeys(fn ($k) => [$k['kunci'] => TemplateBagianKustom::contohKolom($k)])->all();

        $baris = [];

        foreach ([['1131', 'Persentase rekomendasi yang ditindaklanjuti', 'Contoh uraian poin pertama'], ['1131', 'Persentase rekomendasi yang ditindaklanjuti', 'Contoh uraian poin kedua']] as $i => [$kode, $indikator, $teks]) {
            $konteks = TemplateBagianKustom::konteks($kode, $indikator, $teks, trim((string) $f['nama']), 2026, 1, 3 - $i, $contohNilai);
            $baris[] = TemplateBagianKustom::render($format, $konteks);
        }

        return [
            'judul' => trim((string) ($f['judul_notula'] ?? '')) ?: trim((string) $f['nama']),
            'baris' => $baris,
            'peringatan' => TemplateBagianKustom::variabelTidakDikenal($format, $kunci),
        ];
    }

    /**
     * Tempat bagian ini muncul, per menu yang dipilih — dihitung dari form (belum harus
     * disimpan). Menu yang tidak bisa dibuka peran yang dipilih dilewati.
     *
     * @return array<int, array{menu: string, peran: string, tempat: string}>
     */
    protected function lokasiTampil(): array
    {
        if ($this->bukaId === null || ! isset($this->form[$this->bukaId])) {
            return [];
        }

        $f = $this->form[$this->bukaId];
        $hasil = [];

        foreach ($f['penempatan'] ?? [] as $menu) {
            $peranMenu = array_values(array_intersect($f['peran'] ?? [], MenuBagianKustom::peranDiizinkan($menu)));

            if ($peranMenu === []) {
                continue;
            }

            $hasil[] = [
                'menu' => MenuBagianKustom::label($menu),
                'peran' => collect($peranMenu)->map(fn ($p) => BagianKustom::NAMA_PERAN[$p] ?? $p)->implode(', '),
                'tempat' => $menu === MenuBagianKustom::PENGISIAN
                    ? 'Isian Kegiatan → pilih IKU → bagian ini tampil di paling bawah, setelah bagian 5 (RTL).'
                    : 'Di paling bawah halaman '.MenuBagianKustom::label($menu).' (setelah isi halaman yang biasa). Diisi per IKU & bulan.',
            ];
        }

        return $hasil;
    }

    public function render()
    {
        $pratinjau = null;
        $variabel = [];
        $kolomTampil = [];

        if ($this->bukaId !== null && isset($this->form[$this->bukaId])) {
            $kolomTampil = $this->kolomDariFormTanpaError($this->bukaId);
            $variabel = TemplateBagianKustom::semuaVariabel($kolomTampil);
            $pratinjau = $this->pratinjauNotula($this->bukaId);
        }

        return view('livewire.bagian-kustom-manager', [
            'daftar' => $this->daftar(),
            'pratinjau' => $pratinjau,
            'variabel' => $variabel,
            'kolomTampil' => $kolomTampil,
            'namaPeran' => BagianKustom::NAMA_PERAN,
            'tipeKolom' => BagianKustom::TIPE_KOLOM,
            'pilihanPemisah' => FormatAngkaKolom::PEMISAH,
            'katalogMenu' => MenuBagianKustom::katalog(),
            'lokasi' => $this->lokasiTampil(),
            'ringkasanSalin' => $this->ringkasanSalin(),
            'riwayat' => $this->bukaId !== null && $this->tab === 'riwayat'
                ? RiwayatKonfigurasiBagianKustom::with('user')->where('bagian_kustom_id', $this->bukaId)->latest('id')->limit(30)->get()
                : collect(),
        ]);
    }

    /**
     * Versi kolom untuk tampilan (render) — tanpa menambah error, dan kunci baru
     * ditampilkan sebagai "akan dibuat" sampai disimpan.
     *
     * @return array<int, array{kunci: string, label: string, tipe: string, opsi: array<int, string>, wajib: bool}>
     */
    protected function kolomDariFormTanpaError(int $id): array
    {
        $baris = $this->form[$id]['kolom'] ?? [];
        $kunciTerpakai = collect($baris)->pluck('kunci')->filter()->values()->all();
        $hasil = [];

        foreach ($baris as $row) {
            $kunci = (string) ($row['kunci'] ?? '');

            if ($kunci === '') {
                $kunci = TemplateBagianKustom::kunciBaru((string) ($row['label'] ?? ''), $kunciTerpakai);
                $kunciTerpakai[] = $kunci;
            }

            if (trim((string) ($row['label'] ?? '')) === '') {
                continue;
            }

            $hasil[] = $this->kolomTersimpan($kunci, $row, $this->opsiDariTeks((string) ($row['opsi_teks'] ?? '')));
        }

        return $hasil;
    }
}
