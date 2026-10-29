<?php

namespace App\Livewire;

use App\Exceptions\KonflikPenyuntinganException;
use App\Livewire\Concerns\GantiNamaBerkas;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi as KendalaSolusiModel;
use App\Models\LogAktivitas;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\RiwayatStatusCapaian;
use App\Models\RtlEvaluasi as RtlEvaluasiModel;
use App\Models\SalinanBuktiRtl;
use App\Services\FolderStructureService;
use App\Services\SinkronBuktiRtlService;
use App\Support\AturanBuktiBagianKustom;
use App\Support\JejakAktivitasBagianKustom;
use App\Support\LanjutanBagianKustom;
use App\Support\MenuBagianKustom;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Ketua Tim — Isian Kegiatan (satu alur, sesuai mockup S4): IKU → Kegiatan →
 * Kendala & Solusi → Evaluasi RTL triwulan sebelumnya → RTL triwulan berikutnya,
 * diajukan bersamaan lewat satu tombol "Ajukan ke Tim SAKIP". Menggabungkan logika
 * yang semula terpisah di PengisianKegiatan, KendalaSolusi, dan RtlEvaluasi.
 */
class PengisianKegiatan extends Component
{
    use GantiNamaBerkas, WithFileUploads;

    public int $tahun;

    public int $bulan;

    public ?int $iku_id = null;

    /**
     * @var array<int, array{id: ?int, status_dokumen: ?string, uraian_kegiatan: string, jenis: string, tahapan_survei: ?string, bukti: array, existing_bukti: array}>
     */
    public array $blocks = [];

    /**
     * Versi (sidik jari isi) baris yang dimuat server ke formulir, per jenis:
     * - kegiatan / kendala / rtl / poin: [id => sidik] untuk baris yang BISA diedit saat
     *   dimuat — dibandingkan ulang (dengan kunci baris) saat menyimpan; bila berbeda,
     *   seluruh penyimpanan dibatalkan supaya perubahan pengguna lain tidak tertimpa.
     * - kegiatan_lain: [id] kegiatan hanya-baca yang ikut dimuat.
     * Hanya id di sini yang boleh dikirim balik di formulir (bukan id sembarang dari
     * browser). Dikunci: tidak bisa diubah lewat request.
     *
     * @var array<string, array<int|string, mixed>>
     */
    #[Locked]
    public array $versiDimuat = [];

    /**
     * Bagian yang bentrok pada percobaan simpan terakhir: [jenis => [label]]. Dipakai
     * layar untuk menampilkan peringatan + tombol muat ulang bagian tersebut.
     *
     * @var array<string, array<int, string>>
     */
    #[Locked]
    public array $bentrok = [];

    /**
     * Berkas baru yang unggahan Drive-nya ditunda sampai transaksi simpan berhasil
     * (lihat antreUnggah()/jalankanAntreanUnggah()). Hanya hidup selama satu request.
     *
     * @var array<int, array{berkas: Berkas, ref: \Illuminate\Database\Eloquent\Model, nama_asli: string, konteks: string, label_gagal: string}>
     */
    protected array $antreanUnggah = [];

    /**
     * Pekerjaan Google Drive lain yang ditunda sampai transaksi simpan berhasil (mis.
     * menyusun ulang salinan bukti di folder Evaluasi-RTL saat tautan RTL berubah).
     *
     * @var list<\Closure>
     */
    protected array $tugasSetelahCommit = [];

    /** Path lokal yang disimpan selama transaksi — dihapus bila transaksinya batal. */
    protected array $berkasLokalBaru = [];

    /**
     * Baris yang dikunci & diperiksa versinya di awal transaksi simpan, per jenis
     * [id => model] — sumber status sebenarnya (bukan status kiriman browser).
     *
     * @var array<string, Collection<int, \Illuminate\Database\Eloquent\Model>>
     */
    protected array $barisTerkunci = [];

    /**
     * Nama kustom untuk berkas bukti yang BARU dipilih (belum tersimpan), diisi
     * lewat ✏️ di chip berkas — kunci = kunciBuktiBaru() berkas sementaranya, nilai =
     * nama tanpa ekstensi. Kosong berarti pakai nama default (mis. "Kegiatan <uraian>").
     *
     * @var array<string, string>
     */
    public array $namaBuktiBaru = [];

    /**
     * Kunci stabil satu berkas sementara Livewire (nama file tmp-nya unik per
     * unggahan) — di-md5 supaya aman dipakai sebagai kunci array/JS (tanpa titik).
     */
    public function kunciBuktiBaru($file): string
    {
        return md5($file->getFilename());
    }

    /**
     * Cara menamai berkas bukti BARU yang tidak diberi nama kustom lewat ✏️:
     * 'asli' = nama berkas seperti saat diunggah dari komputer (bawaan), 'sistem' =
     * rekomendasi sistem (mis. "Kegiatan <uraian> 1"). Berlaku untuk semua unggahan
     * di form ini (Kegiatan, Evaluasi RTL, Bagian Kustom).
     */
    public string $modeNamaBerkas = 'asli';

    public function updatedModeNamaBerkas(string $nilai): void
    {
        if (! in_array($nilai, ['asli', 'sistem'], true)) {
            $this->modeNamaBerkas = 'asli';
        }
    }

    /**
     * Nama berkas asli dari komputer pengguna (tanpa .pdf), dibersihkan supaya aman
     * jadi nama berkas di Drive.
     */
    public function namaAsliBuktiBaru($file): string
    {
        return FolderStructureService::namaOtomatis(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 150);
    }

    /**
     * Nama akhir berkas baru: nama kustom (✏️) bila diisi, selain itu nama asli atau
     * rekomendasi sistem sesuai $modeNamaBerkas. Dipakai juga oleh tampilan chip
     * berkas supaya nama yang terlihat sama persis dengan yang nanti tersimpan.
     */
    public function namaBuktiBaruAtauDefault($file, string $namaDefault): string
    {
        $kustom = trim($this->namaBuktiBaru[$this->kunciBuktiBaru($file)] ?? '');

        if ($kustom !== '') {
            return $kustom;
        }

        if ($this->modeNamaBerkas === 'asli') {
            $asli = $this->namaAsliBuktiBaru($file);

            if ($asli !== '') {
                return $asli;
            }
        }

        return $namaDefault;
    }

    /**
     * Dipanggil <x-nama-berkas aksi="aturNamaBuktiBaru"> untuk berkas yang belum
     * tersimpan — cukup dicatat, baru dipakai saat Simpan Draf/Ajukan.
     *
     * @return array{ok: bool, nama_file?: string, pesan?: string}
     */
    public function aturNamaBuktiBaru(string $kunci, string $nama): array
    {
        $nama = trim($nama);
        if (str_ends_with(strtolower($nama), '.pdf')) {
            $nama = substr($nama, 0, -4);
        }

        $nama = FolderStructureService::namaOtomatis($nama, 150);

        if ($nama === '') {
            return ['ok' => false, 'pesan' => 'Nama berkas tidak boleh kosong.'];
        }

        $this->namaBuktiBaru[$kunci] = $nama;

        return ['ok' => true, 'nama_file' => $nama.'.pdf'];
    }

    /**
     * Tahapan tujuan tiap berkas bukti BARU di blok yang memilih lebih dari satu
     * tahapan — kunci = kunciBuktiBaru(). Kosong/tidak valid = tahapan pertama.
     *
     * @var array<string, string>
     */
    public array $tahapanBuktiBaru = [];

    /**
     * Subfolder khusus tujuan tiap berkas bukti BARU — kunci = kunciBuktiBaru().
     * Kosong = langsung di folder kegiatan.
     *
     * @var array<string, string>
     */
    public array $subfolderBuktiBaru = [];

    /** Saran nama subfolder khusus yang bisa langsung diklik. */
    public const SARAN_SUBFOLDER = ['Undangan', 'Daftar Hadir', 'Notula', 'Dokumentasi', 'Laporan'];

    /** Saran subfolder khusus per tahapan bawaan — tahapan lain memakai SARAN_SUBFOLDER. */
    public const SARAN_SUBFOLDER_TAHAPAN = [
        'persiapan' => ['Surat Tugas', 'Undangan', 'Daftar Hadir', 'Notula', 'Bahan Pelatihan'],
        'pelaksanaan' => ['Surat Tugas', 'Dokumentasi', 'Laporan Lapangan', 'Daftar Sampel'],
        'pengolahan' => ['Laporan Pengolahan', 'Hasil Validasi', 'Dokumentasi'],
        'diseminasi' => ['Publikasi', 'Bahan Rilis', 'Undangan', 'Dokumentasi'],
    ];

    public const DAFTAR_TAHAPAN = ['persiapan' => 'Persiapan', 'pelaksanaan' => 'Pelaksanaan', 'pengolahan' => 'Pengolahan', 'diseminasi' => 'Diseminasi'];

    /**
     * Label tampil satu tahapan: tahapan bawaan dari DAFTAR_TAHAPAN, tahapan buatan
     * Ketua Tim (lihat tambahTahapan()) disimpan apa adanya sebagai namanya sendiri.
     */
    public static function labelTahapan(string $tahapan): string
    {
        return self::DAFTAR_TAHAPAN[$tahapan] ?? $tahapan;
    }

    /**
     * Tahapan sah untuk blok ini: tahapan bawaan atau tahapan tambahan buatan sendiri.
     */
    protected function tahapanSah(array $block, mixed $tahapan): bool
    {
        return is_string($tahapan) && $tahapan !== ''
            && (array_key_exists($tahapan, self::DAFTAR_TAHAPAN) || in_array($tahapan, $block['tahapan_kustom'] ?? [], true));
    }

    /**
     * Tambah tahapan selain empat tahapan bawaan, langsung tercentang. Nama yang
     * sama dengan tahapan bawaan cukup mencentang tahapan bawaan itu.
     */
    public function tambahTahapan(int $index, string $nama): void
    {
        $block = $this->blocks[$index] ?? null;

        // Titik dibuang karena tahapan dipakai sebagai kunci array bertitik (mis.
        // blocks.*.nama_folder_tahapan.<tahapan>).
        $nama = FolderStructureService::namaOtomatis(str_replace('.', ' ', $nama), 50);

        if (! $block || $nama === '' || $this->blokTerkunci($block['status_dokumen'] ?? null)) {
            return;
        }

        $bawaan = array_search(mb_strtolower($nama), array_map('mb_strtolower', self::DAFTAR_TAHAPAN), true);
        $kustomSama = collect($block['tahapan_kustom'] ?? [])->first(fn ($t) => mb_strtolower($t) === mb_strtolower($nama));
        $tahapan = $bawaan !== false ? $bawaan : ($kustomSama ?? $nama);

        if (in_array(mb_strtolower($tahapan), array_map('mb_strtolower', $this->tahapanDipilih($block)), true)) {
            return;
        }

        if (in_array($tahapan, $this->tahapanTerpakai($index), true)) {
            $this->dispatch('notify', type: 'error', message: 'Tahapan "'.self::labelTahapan($tahapan).'" sudah dipilih untuk kegiatan ini pada triwulan ini.');

            return;
        }

        if ($bawaan === false && $kustomSama === null) {
            $this->blocks[$index]['tahapan_kustom'] = [...($block['tahapan_kustom'] ?? []), $nama];
        }

        $this->toggleTahapan($index, $tahapan);
    }

    /** Hapus tahapan buatan sendiri dari blok (sekaligus melepas centangnya). */
    public function hapusTahapanKustom(int $index, string $tahapan): void
    {
        $block = $this->blocks[$index] ?? null;

        if (! $block || $this->blokTerkunci($block['status_dokumen'] ?? null) || ! in_array($tahapan, $block['tahapan_kustom'] ?? [], true)) {
            return;
        }

        if (in_array($tahapan, $this->tahapanDipilih($block), true)) {
            $this->toggleTahapan($index, $tahapan);
        }

        $this->blocks[$index]['tahapan_kustom'] = array_values(array_diff($block['tahapan_kustom'], [$tahapan]));
        $this->adaPerubahan = true;
    }

    /**
     * Semua tahapan yang dipilih satu blok, berurutan: tahapan utama lalu tambahan.
     *
     * @return list<string>
     */
    public function tahapanDipilih(array $block): array
    {
        return collect([$block['tahapan_survei'] ?? ''])
            ->concat($block['tahapan_tambahan'] ?? [])
            ->filter(fn ($t) => is_string($t) && $t !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Centang/lepas satu tahapan di blok $index. Tahapan pertama yang dipilih jadi
     * tahapan utama (tahapan_survei, milik baris Kegiatan blok ini); berikutnya
     * masuk tahapan_tambahan.
     */
    public function toggleTahapan(int $index, string $tahapan): void
    {
        $block = $this->blocks[$index] ?? null;

        if (! $block || ! $this->tahapanSah($block, $tahapan) || $this->blokTerkunci($block['status_dokumen'] ?? null)) {
            return;
        }

        $dipilih = $this->tahapanDipilih($block);

        if (in_array($tahapan, $dipilih, true)) {
            $dipilih = array_values(array_diff($dipilih, [$tahapan]));
        } elseif (! in_array($tahapan, $this->tahapanTerpakai($index), true)) {
            $dipilih[] = $tahapan;
        } else {
            return;
        }

        $this->blocks[$index]['tahapan_survei'] = $dipilih[0] ?? '';
        $this->blocks[$index]['tahapan_tambahan'] = array_slice($dipilih, 1);
        $this->adaPerubahan = true;
    }

    /**
     * Tahapan tujuan satu berkas bukti baru di blok ini.
     */
    public function tahapanBerkasBaru(array $block, $file): ?string
    {
        $dipilih = $this->tahapanDipilih($block);

        if (($block['jenis'] ?? '') !== 'survei_sensus' || $dipilih === []) {
            return null;
        }

        $pilihan = $this->tahapanBuktiBaru[$this->kunciBuktiBaru($file)] ?? '';

        return in_array($pilihan, $dipilih, true) ? $pilihan : $dipilih[0];
    }

    /**
     * Subfolder khusus tujuan satu berkas bukti baru di blok ini ('' = folder kegiatan).
     */
    public function subfolderBerkasBaru(array $block, $file): string
    {
        $daftar = $this->subfolderBlok($block, $this->tahapanBerkasBaru($block, $file));
        $pilihan = $this->subfolderBuktiBaru[$this->kunciBuktiBaru($file)] ?? '';

        return in_array($pilihan, $daftar, true) ? $pilihan : '';
    }

    /**
     * Susunan folder bukti diatur sendiri-sendiri per tahapan (survei/sensus) —
     * mis. Persiapan dipisah ke Undangan & Notula, Pengolahan cukup satu folder.
     * Kegiatan satu folder (tanpa tahapan) memakai blocks.*.pakai_subfolder &
     * blocks.*.subfolders; tiap tahapan memakai blocks.*.pakai_subfolder_tahapan.<t>
     * & blocks.*.subfolders_tahapan.<t>. Poin Evaluasi RTL (evaluasi.<id>) memakai
     * susunan yang sama persis, dengan pisah_tahapan menggantikan jenis survei/sensus.
     *
     * @return array{0: string, 1: string} [kunci pakai, kunci daftar] relatif ke blok
     */
    protected function kunciSusunanFolder(array $block, ?string $tahapan): array
    {
        return (($block['jenis'] ?? '') === 'survei_sensus' || ! empty($block['pisah_tahapan'])) && filled($tahapan)
            ? ["pakai_subfolder_tahapan.{$tahapan}", "subfolders_tahapan.{$tahapan}"]
            : ['pakai_subfolder', 'subfolders'];
    }

    /** Apakah folder kegiatan/tahapan ini dipisah ke beberapa subfolder. */
    public function pakaiSubfolder(array $block, ?string $tahapan): bool
    {
        [$kunciPakai] = $this->kunciSusunanFolder($block, $tahapan);

        return ! empty(data_get($block, $kunciPakai));
    }

    /**
     * Daftar nama subfolder khusus (tersimpan) untuk folder kegiatan/tahapan ini,
     * tanpa memandang pilihan "1 folder".
     *
     * @return list<string>
     */
    public function daftarSubfolder(array $block, ?string $tahapan): array
    {
        [, $kunciDaftar] = $this->kunciSusunanFolder($block, $tahapan);

        return array_values((array) data_get($block, $kunciDaftar, []));
    }

    /**
     * Subfolder khusus yang berlaku untuk folder kegiatan/tahapan ini (kosong bila
     * folder itu memakai "1 folder").
     *
     * @return list<string>
     */
    public function subfolderBlok(array $block, ?string $tahapan): array
    {
        return $this->pakaiSubfolder($block, $tahapan) ? $this->daftarSubfolder($block, $tahapan) : [];
    }

    /**
     * Pesan bila "Pisah ke beberapa subfolder" dipilih dengan kurang dari dua subfolder.
     * Syarat ini HANYA untuk mode pisah — "1 folder (bawaan)" tetap boleh dipakai tanpa
     * subfolder sama sekali.
     */
    public const PESAN_MINIMAL_DUA_SUBFOLDER = 'Pilih minimal 2 folder tujuan untuk menggunakan fitur pemisahan folder. Tambah subfolder lagi atau pilih "1 folder (bawaan)".';

    /**
     * Folder ini dipilih "Pisah ke beberapa subfolder" tapi subfolder yang benar-benar
     * berbeda (nama dinormalisasi, tanpa beda huruf besar/kecil) belum sampai dua —
     * satu subfolder saja sama dengan "1 folder". Dikecualikan: folder yang subfolder-
     * nya sudah berisi berkas TERSIMPAN. Susunan subfolder kosong tidak ikut tersimpan
     * (muatBlocksKegiatan()/muatFormEvaluasi() menyusunnya ulang dari berkas), jadi
     * folder sah yang baru diisi di satu subfolder termuat dengan satu subfolder dan
     * tidak boleh terkunci karenanya.
     *
     * @param  list<string|null>  $subfolderTersimpan  subfolder berkas tersimpan di folder ini
     */
    protected function susunanKurangDariDua(array $data, ?string $tahapan, array $subfolderTersimpan): bool
    {
        if (! $this->pakaiSubfolder($data, $tahapan)) {
            return false;
        }

        $daftar = $this->daftarSubfolder($data, $tahapan);
        $berbeda = collect($daftar)
            ->map(fn ($nama) => mb_strtolower(FolderStructureService::namaOtomatis((string) $nama, 100)))
            ->filter()
            ->unique();

        return $berbeda->count() < 2 && array_intersect($daftar, array_filter($subfolderTersimpan)) === [];
    }

    /** susunanKurangDariDua() untuk satu folder kegiatan/tahapan di blok kegiatan. */
    public function subfolderKurangDariDua(array $block, ?string $tahapan): bool
    {
        $tahapanTersimpan = ($block['jenis'] ?? '') === 'survei_sensus' ? ($block['tahapan_survei'] ?? null) : null;
        $tersimpan = filled($block['id'] ?? null) && ($tahapan === null || $tahapan === $tahapanTersimpan)
            ? array_column($block['existing_bukti'] ?? [], 'subfolder')
            : [];

        return $this->susunanKurangDariDua($block, $tahapan, $tersimpan);
    }

    /**
     * susunanKurangDariDua() untuk satu folder tahapan/poin Evaluasi RTL. Subfolder
     * hanya berlaku bila Struktur Folder Drive memakai folder per poin (lihat blade).
     */
    public function subfolderRtlKurangDariDua(int $rtlId, ?string $tahapan): bool
    {
        $poin = $this->rtlTriwulanBerjalan()->firstWhere('id', $rtlId);

        if (! $poin || ! $this->polaFolderRtl($poin)['per_kegiatan']) {
            return false;
        }

        $berkas = $tahapan === null ? $poin->berkas->whereNull('tahapan') : $poin->berkas->where('tahapan', $tahapan);

        return $this->susunanKurangDariDua($this->evaluasi[$rtlId] ?? [], $tahapan, $berkas->pluck('subfolder')->all());
    }

    /**
     * Galat susunan folder semua kegiatan & poin Evaluasi RTL yang masih bisa diubah —
     * dipakai simpanDraft() (termasuk simpan otomatis setelah unggah) dan buatValidator()
     * (Ajukan), jadi susunan yang tidak sah tidak pernah tersimpan walau tombol di layar
     * dilewati.
     *
     * @param  bool  $hanyaBlokTerisi  lewati blok tanpa uraian (tidak ikut tersimpan, lihat aturanBlokTerisiSaja())
     * @return array<string, list<string>> kunci blocks.<i>.susunan_folder / evaluasi.<id>.susunan_folder => pesan
     */
    protected function galatSusunanFolder(bool $hanyaBlokTerisi = false): array
    {
        $galat = [];

        foreach ($this->blocks as $i => $block) {
            if ($this->blokTerkunci($block['status_dokumen'] ?? null)
                || ($hanyaBlokTerisi && trim((string) ($block['uraian_kegiatan'] ?? '')) === '')) {
                continue;
            }

            $daftarTahapan = ($block['jenis'] ?? '') === 'survei_sensus' ? $this->tahapanDipilih($block) : [];

            foreach ($daftarTahapan ?: [null] as $tahapan) {
                if ($this->subfolderKurangDariDua($block, $tahapan)) {
                    $folder = $tahapan !== null ? 'tahapan '.self::labelTahapan($tahapan) : 'kegiatan';
                    $galat["blocks.{$i}.susunan_folder"][] = "Folder {$folder}: ".self::PESAN_MINIMAL_DUA_SUBFOLDER;
                }
            }
        }

        foreach (array_keys($this->evaluasi) as $rtlId) {
            if (! $this->poinRtlBisaDiunggah((int) $rtlId)) {
                continue;
            }

            foreach ($this->tahapanRtlDipilih((int) $rtlId) ?: [null] as $tahapan) {
                if ($this->subfolderRtlKurangDariDua((int) $rtlId, $tahapan)) {
                    $folder = $tahapan !== null ? 'tahapan '.$tahapan : 'poin RTL';
                    $galat["evaluasi.{$rtlId}.susunan_folder"][] = "Folder {$folder}: ".self::PESAN_MINIMAL_DUA_SUBFOLDER;
                }
            }
        }

        return $galat;
    }

    /** Saran nama subfolder untuk satu tahapan (atau kegiatan tanpa tahapan). */
    public static function saranSubfolder(?string $tahapan): array
    {
        return self::SARAN_SUBFOLDER_TAHAPAN[$tahapan ?? ''] ?? self::SARAN_SUBFOLDER;
    }

    protected function simpanSusunanFolder(int $index, ?string $tahapan, ?bool $pakai, ?array $daftar): void
    {
        [$kunciPakai, $kunciDaftar] = $this->kunciSusunanFolder($this->blocks[$index], $tahapan);

        if ($pakai !== null) {
            data_set($this->blocks[$index], $kunciPakai, $pakai);
        }
        if ($daftar !== null) {
            data_set($this->blocks[$index], $kunciDaftar, array_values($daftar));
        }
        $this->resetErrorBag("blocks.{$index}.susunan_folder");
        $this->adaPerubahan = true;
    }

    /** Pilih "1 folder" ($pakai = false) atau "Pisah ke beberapa subfolder". */
    public function aturSusunanFolder(int $index, bool $pakai, ?string $tahapan = null): void
    {
        if (! isset($this->blocks[$index])) {
            return;
        }

        $this->simpanSusunanFolder($index, $tahapan, $pakai, null);
    }

    public function tambahSubfolder(int $index, string $nama, ?string $tahapan = null): void
    {
        if (! isset($this->blocks[$index])) {
            return;
        }

        $nama = FolderStructureService::namaOtomatis($nama, 100);
        $daftar = $this->daftarSubfolder($this->blocks[$index], $tahapan);

        if ($nama === '' || in_array(mb_strtolower($nama), array_map('mb_strtolower', $daftar), true)) {
            return;
        }

        $this->simpanSusunanFolder($index, $tahapan, true, [...$daftar, $nama]);
    }

    public function hapusSubfolder(int $index, int $urutan, ?string $tahapan = null): void
    {
        if (! isset($this->blocks[$index])) {
            return;
        }

        $daftar = $this->daftarSubfolder($this->blocks[$index], $tahapan);

        if (! isset($daftar[$urutan])) {
            return;
        }

        unset($daftar[$urutan]);
        $this->simpanSusunanFolder($index, $tahapan, null, $daftar);
    }

    /**
     * Ganti nama satu subfolder khusus; berkas baru yang sudah diarahkan ke
     * subfolder itu (di tahapan yang sama) ikut pindah ke nama barunya.
     */
    public function gantiNamaSubfolder(int $index, int $urutan, string $nama, ?string $tahapan = null): void
    {
        $block = $this->blocks[$index] ?? null;

        if (! $block) {
            return;
        }

        $daftar = $this->daftarSubfolder($block, $tahapan);
        $lama = $daftar[$urutan] ?? null;
        $nama = FolderStructureService::namaOtomatis($nama, 100);

        if ($lama === null || $nama === '' || $nama === $lama) {
            return;
        }

        $lainnya = array_map('mb_strtolower', array_diff_key($daftar, [$urutan => true]));
        if (in_array(mb_strtolower($nama), $lainnya, true)) {
            return;
        }

        $tahapanTujuan = ($block['jenis'] ?? '') === 'survei_sensus' && filled($tahapan) ? $tahapan : null;
        foreach ($block['bukti'] ?? [] as $file) {
            $kunci = $this->kunciBuktiBaru($file);
            if (($this->subfolderBuktiBaru[$kunci] ?? '') === $lama && $this->tahapanBerkasBaru($block, $file) === $tahapanTujuan) {
                $this->subfolderBuktiBaru[$kunci] = $nama;
            }
        }

        $daftar[$urutan] = $nama;
        $this->simpanSusunanFolder($index, $tahapan, null, $daftar);
    }

    /**
     * Ganti nama folder satu tahapan tambahan (kosong = rekomendasi sistem). Lewat
     * method — bukan wire:model — karena nama tahapan buatan sendiri bisa berspasi.
     */
    public function gantiNamaFolderTahapan(int $index, string $tahapan, string $nama): void
    {
        if (! isset($this->blocks[$index]) || ! $this->tahapanSah($this->blocks[$index], $tahapan)) {
            return;
        }

        $this->blocks[$index]['nama_folder_tahapan'][$tahapan] = Str::limit(trim($nama), 150, '');
        $this->adaPerubahan = true;
    }

    /**
     * Penampung sementara unggahan lewat tombol "Unggah" milik SATU folder tujuan —
     * kunci "<blok>.<urutan tahapan>.<urutan folder>" (folder 0 = langsung di folder
     * kegiatan/tahapan, 1.. = subfolder ke-n). Begitu selesai terunggah, berkasnya
     * dipindah ke blocks.*.bukti beserta tahapan & subfolder tujuannya (lihat
     * updatedUnggahFolder()), jadi pengguna tidak perlu memilih tujuan per berkas.
     *
     * @var array<int|string, mixed>
     */
    public array $unggahFolder = [];

    public function updatedUnggahFolder($nilai, ?string $kunci = null): void
    {
        $bagian = explode('.', (string) $kunci);

        if (count($bagian) < 3) {
            return;
        }

        [$index, $urutanTahapan, $urutanFolder] = array_map('intval', array_slice($bagian, 0, 3));
        unset($this->unggahFolder[$index]);

        $block = $this->blocks[$index] ?? null;

        if (! $block || $this->blokTerkunci($block['status_dokumen'] ?? null)) {
            return;
        }

        $daftarBerkas = array_values(array_filter(is_array($nilai) ? $nilai : [$nilai]));

        $validator = \Illuminate\Support\Facades\Validator::make(
            ['bukti' => $daftarBerkas],
            ['bukti.*' => ['file', 'mimes:pdf']],
            ['bukti.*.mimes' => 'Bukti capaian harus berupa PDF.']
        );

        if ($validator->fails()) {
            $this->addError("blocks.{$index}.bukti", $validator->errors()->first());

            return;
        }

        $tahapan = ($block['jenis'] ?? '') === 'survei_sensus' ? ($this->tahapanDipilih($block)[$urutanTahapan] ?? null) : null;

        // Tombol unggahnya sudah dinonaktifkan di layar; ini pengaman bila request
        // tetap terkirim. Berkas ditolak utuh — tidak ada yang masuk Drive — sedangkan
        // susunan folder & berkas lain di form tetap seperti semula.
        if ($this->subfolderKurangDariDua($block, $tahapan)) {
            $this->addError("blocks.{$index}.susunan_folder", self::PESAN_MINIMAL_DUA_SUBFOLDER);

            return;
        }

        $subfolder = $urutanFolder > 0 ? ($this->subfolderBlok($block, $tahapan)[$urutanFolder - 1] ?? '') : '';

        foreach ($daftarBerkas as $berkas) {
            $this->blocks[$index]['bukti'][] = $berkas;
            $kunciBerkas = $this->kunciBuktiBaru($berkas);

            if ($tahapan) {
                $this->tahapanBuktiBaru[$kunciBerkas] = $tahapan;
            }
            if ($subfolder !== '') {
                $this->subfolderBuktiBaru[$kunciBerkas] = $subfolder;
            }
        }

        $this->resetErrorBag("blocks.{$index}.bukti");
        $this->adaPerubahan = true;

        if (trim((string) ($block['uraian_kegiatan'] ?? '')) === '') {
            $this->addError("blocks.{$index}.uraian_kegiatan", 'Isi uraian kegiatan dulu — berkas baru bisa tersimpan ke Google Drive setelah uraian diisi.');

            return;
        }

        $this->simpanOtomatisSetelahUnggah();
    }

    /**
     * Folder tujuan unggahan satu blok untuk tombol "Unggah" per folder: tiap tahapan
     * survei terpilih (atau satu folder kegiatan bila bukan survei), masing-masing
     * berisi folder langsung + subfolder khusus pilihan pengguna.
     *
     * @return list<array{urutan: int, tahapan: ?string, label: string, folder: list<array{urutan: int, subfolder: string, label: string}>}>
     */
    public function folderUnggahBlok(int $index): array
    {
        $block = $this->blocks[$index] ?? null;

        if (! $block) {
            return [];
        }

        $daftarTahapan = ($block['jenis'] ?? '') === 'survei_sensus' ? $this->tahapanDipilih($block) : [];
        $hasil = [];

        foreach ($daftarTahapan ?: [null] as $ti => $tahapan) {
            $subfolders = $this->subfolderBlok($block, $tahapan);
            // Tahapan utama (dan kegiatan bukan survei) memakai nama folder pilihan
            // Ketua Tim bila diisi; tahapan tambahan memakai nama tahapannya.
            $kustomTahapan = trim($block['nama_folder_tahapan'][$tahapan] ?? '');
            $labelInduk = match (true) {
                $ti === 0 => $this->namaFolderBerlaku($index),
                $kustomTahapan !== '' => FolderStructureService::namaOtomatis($kustomTahapan, 150),
                default => $this->namaFolderRekomendasi(array_merge($block, ['tahapan_survei' => $tahapan])),
            };

            $folder = [['urutan' => 0, 'subfolder' => '', 'label' => $labelInduk]];
            foreach ($subfolders as $si => $namaSub) {
                $folder[] = ['urutan' => $si + 1, 'subfolder' => $namaSub, 'label' => $labelInduk.' / '.$namaSub];
            }

            $hasil[] = [
                'urutan' => $ti,
                'tahapan' => $tahapan,
                'label' => $labelInduk,
                'otomatis' => $this->namaFolderRekomendasi(array_merge($block, ['tahapan_survei' => $tahapan ?? ''])),
                'folder' => $folder,
            ];
        }

        return $hasil;
    }

    /**
     * Nama tampil/akhir satu berkas bukti BARU di blok kegiatan, sama persis dengan
     * yang disimpan. Nama bawaan selalu atas nama Uraian Kegiatan induk ("Kegiatan
     * <uraian>"), juga bila berkasnya masuk folder salah satu tahapan — tahapan cuma
     * menentukan folder tujuan, bukan nama bukti. Nomor urut berlanjut di seluruh blok.
     */
    public function namaBuktiBaruKegiatan(array $block, $file): string
    {
        $semua = array_values($block['bukti'] ?? []);
        $urutan = (int) array_search($this->kunciBuktiBaru($file), array_map(fn ($f) => $this->kunciBuktiBaru($f), $semua), true);

        return $this->namaBuktiBaruAtauDefault($file, $this->namaDefaultBuktiBaru(
            'Kegiatan '.$block['uraian_kegiatan'], count($block['existing_bukti'] ?? []), $urutan, count($semua)
        ));
    }

    // ================================================================
    // Evaluasi RTL — susunan folder bukti realisasi unggahan mandiri, sama
    // dengan Kegiatan: satu folder untuk seluruh bukti atau pisah ke beberapa
    // tahapan, dan tiap folder bisa "1 folder" atau dipisah ke subfolder.
    // Susunannya disimpan di evaluasi.<id> (pisah_tahapan, tahapan,
    // pakai_subfolder[_tahapan], subfolders[_tahapan]) dan dipakai helper yang
    // sama dengan Kegiatan (pakaiSubfolder(), daftarSubfolder(), subfolderBlok()).
    // ================================================================

    /** Saran nama tahapan poin RTL (bebas diketik sendiri). */
    public const SARAN_TAHAPAN_RTL = ['Tahapan 1', 'Tahapan 2', 'Persiapan', 'Pelaksanaan', 'Pelaporan'];

    /**
     * Penampung unggahan tombol "Unggah" per folder poin RTL — kunci
     * "<id poin>.<urutan tahapan>.<urutan folder>", pola sama dengan $unggahFolder.
     *
     * @var array<int|string, mixed>
     */
    public array $unggahRtl = [];

    protected function evaluasiKosong(): array
    {
        return [
            'bukti' => [],
            'pisah_tahapan' => false,
            'tahapan' => [],
            'pakai_subfolder' => false,
            'subfolders' => [],
            'pakai_subfolder_tahapan' => [],
            'subfolders_tahapan' => [],
        ];
    }

    /** Poin RTL triwulan berjalan yang buktinya masih boleh diunggah mandiri dari form ini. */
    protected function poinRtlBisaDiunggah(int $rtlId): bool
    {
        $poin = $this->rtlTriwulanBerjalan()->firstWhere('id', $rtlId);

        return $poin !== null && ! $this->capaianTerkunci() && ! $this->formTerkunciSedangDitangani() && ! $this->poinRtlTertautKegiatan($poin);
    }

    /** @return list<string> tahapan poin RTL ini (kosong bila satu folder untuk seluruh bukti). */
    public function tahapanRtlDipilih(int $rtlId): array
    {
        $data = $this->evaluasi[$rtlId] ?? [];

        return ! empty($data['pisah_tahapan']) ? array_values($data['tahapan'] ?? []) : [];
    }

    /** "Satu folder untuk seluruh bukti" ($pisah = false) atau "Pisah ke beberapa tahapan". */
    public function aturTahapanRtl(int $rtlId, bool $pisah): void
    {
        if (! $this->poinRtlBisaDiunggah($rtlId)) {
            return;
        }

        $this->evaluasi[$rtlId] = array_merge($this->evaluasiKosong(), $this->evaluasi[$rtlId] ?? [], ['pisah_tahapan' => $pisah]);
        $this->adaPerubahan = true;
    }

    public function tambahTahapanRtl(int $rtlId, string $nama): void
    {
        // Titik dibuang: nama tahapan dipakai sebagai kunci array bertitik (lihat tambahTahapan()).
        $nama = FolderStructureService::namaOtomatis(str_replace('.', ' ', $nama), 100);

        if ($nama === '' || ! $this->poinRtlBisaDiunggah($rtlId)) {
            return;
        }

        $data = array_merge($this->evaluasiKosong(), $this->evaluasi[$rtlId] ?? []);

        if (! in_array(mb_strtolower($nama), array_map('mb_strtolower', $data['tahapan']), true)) {
            $data['tahapan'][] = $nama;
        }

        $data['pisah_tahapan'] = true;
        $this->evaluasi[$rtlId] = $data;
        $this->adaPerubahan = true;
    }

    public function hapusTahapanRtl(int $rtlId, string $nama): void
    {
        if (! $this->poinRtlBisaDiunggah($rtlId)) {
            return;
        }

        $data = array_merge($this->evaluasiKosong(), $this->evaluasi[$rtlId] ?? []);
        $data['tahapan'] = array_values(array_diff($data['tahapan'], [$nama]));
        unset($data['pakai_subfolder_tahapan'][$nama], $data['subfolders_tahapan'][$nama]);
        $this->evaluasi[$rtlId] = $data;
        $this->adaPerubahan = true;
    }

    public function aturSusunanFolderRtl(int $rtlId, bool $pakai, ?string $tahapan = null): void
    {
        $this->simpanSusunanFolderRtl($rtlId, $tahapan, $pakai, null);
    }

    public function tambahSubfolderRtl(int $rtlId, string $nama, ?string $tahapan = null): void
    {
        $nama = FolderStructureService::namaOtomatis($nama, 100);
        $daftar = $this->daftarSubfolder($this->evaluasi[$rtlId] ?? [], $tahapan);

        if ($nama === '' || in_array(mb_strtolower($nama), array_map('mb_strtolower', $daftar), true)) {
            return;
        }

        $this->simpanSusunanFolderRtl($rtlId, $tahapan, true, [...$daftar, $nama]);
    }

    public function hapusSubfolderRtl(int $rtlId, int $urutan, ?string $tahapan = null): void
    {
        $daftar = $this->daftarSubfolder($this->evaluasi[$rtlId] ?? [], $tahapan);

        if (! isset($daftar[$urutan])) {
            return;
        }

        unset($daftar[$urutan]);
        $this->simpanSusunanFolderRtl($rtlId, $tahapan, null, $daftar);
    }

    protected function simpanSusunanFolderRtl(int $rtlId, ?string $tahapan, ?bool $pakai, ?array $daftar): void
    {
        if (! $this->poinRtlBisaDiunggah($rtlId)) {
            return;
        }

        if ($tahapan !== null && ! in_array($tahapan, $this->tahapanRtlDipilih($rtlId), true)) {
            return;
        }

        $data = array_merge($this->evaluasiKosong(), $this->evaluasi[$rtlId] ?? []);
        [$kunciPakai, $kunciDaftar] = $this->kunciSusunanFolder($data, $tahapan);

        if ($pakai !== null) {
            data_set($data, $kunciPakai, $pakai);
        }
        if ($daftar !== null) {
            data_set($data, $kunciDaftar, array_values($daftar));
        }

        $this->evaluasi[$rtlId] = $data;
        $this->resetErrorBag("evaluasi.{$rtlId}.susunan_folder");
        $this->adaPerubahan = true;
    }

    /**
     * Folder tujuan unggahan mandiri satu poin RTL, bentuk sama dengan folderUnggahBlok():
     * tiap tahapan (atau satu folder poin bila tidak dipisah), masing-masing berisi
     * folder langsung + subfolder khusus.
     *
     * @return list<array{urutan: int, tahapan: ?string, label: string, folder: list<array{urutan: int, subfolder: string, label: string}>}>
     */
    public function folderUnggahRtl(int $rtlId): array
    {
        $data = $this->evaluasi[$rtlId] ?? $this->evaluasiKosong();
        $hasil = [];

        foreach ($this->tahapanRtlDipilih($rtlId) ?: [null] as $ti => $tahapan) {
            $label = $tahapan ?? 'Folder poin RTL';
            $folder = [['urutan' => 0, 'subfolder' => '', 'label' => $label]];

            foreach ($this->subfolderBlok($data, $tahapan) as $si => $namaSub) {
                $folder[] = ['urutan' => $si + 1, 'subfolder' => $namaSub, 'label' => $label.' / '.$namaSub];
            }

            $hasil[] = ['urutan' => $ti, 'tahapan' => $tahapan, 'label' => $label, 'folder' => $folder];
        }

        return $hasil;
    }

    /** Tahapan tujuan satu berkas baru poin RTL (null = tidak dipisah tahapan). */
    public function tahapanBerkasBaruRtl(int $rtlId, $file): ?string
    {
        $dipilih = $this->tahapanRtlDipilih($rtlId);

        if ($dipilih === []) {
            return null;
        }

        $pilihan = $this->tahapanBuktiBaru[$this->kunciBuktiBaru($file)] ?? '';

        return in_array($pilihan, $dipilih, true) ? $pilihan : $dipilih[0];
    }

    /** Subfolder tujuan satu berkas baru poin RTL ('' = langsung di folder tahapan/poin). */
    public function subfolderBerkasBaruRtl(int $rtlId, $file): string
    {
        $daftar = $this->subfolderBlok($this->evaluasi[$rtlId] ?? [], $this->tahapanBerkasBaruRtl($rtlId, $file));
        $pilihan = $this->subfolderBuktiBaru[$this->kunciBuktiBaru($file)] ?? '';

        return in_array($pilihan, $daftar, true) ? $pilihan : '';
    }

    public function updatedUnggahRtl($nilai, ?string $kunci = null): void
    {
        $bagian = explode('.', (string) $kunci);

        if (count($bagian) < 3) {
            return;
        }

        [$rtlId, $urutanTahapan, $urutanFolder] = array_map('intval', array_slice($bagian, 0, 3));
        unset($this->unggahRtl[$rtlId]);

        if (! $this->poinRtlBisaDiunggah($rtlId)) {
            return;
        }

        $daftarBerkas = array_values(array_filter(is_array($nilai) ? $nilai : [$nilai]));

        $validator = \Illuminate\Support\Facades\Validator::make(
            ['bukti' => $daftarBerkas],
            ['bukti.*' => ['file', 'mimes:pdf']],
            ['bukti.*.mimes' => 'Bukti harus berupa PDF.']
        );

        if ($validator->fails()) {
            $this->addError("evaluasi.{$rtlId}.bukti", $validator->errors()->first());

            return;
        }

        $data = array_merge($this->evaluasiKosong(), $this->evaluasi[$rtlId] ?? []);
        $tahapan = $this->tahapanRtlDipilih($rtlId)[$urutanTahapan] ?? null;

        // Pengaman sisi server, sama dengan updatedUnggahFolder().
        if ($this->subfolderRtlKurangDariDua($rtlId, $tahapan)) {
            $this->addError("evaluasi.{$rtlId}.susunan_folder", self::PESAN_MINIMAL_DUA_SUBFOLDER);

            return;
        }

        $subfolder = $urutanFolder > 0 ? ($this->subfolderBlok($data, $tahapan)[$urutanFolder - 1] ?? '') : '';

        foreach ($daftarBerkas as $berkas) {
            $data['bukti'][] = $berkas;
            $kunciBerkas = $this->kunciBuktiBaru($berkas);

            if ($tahapan) {
                $this->tahapanBuktiBaru[$kunciBerkas] = $tahapan;
            }
            if ($subfolder !== '') {
                $this->subfolderBuktiBaru[$kunciBerkas] = $subfolder;
            }
        }

        $this->evaluasi[$rtlId] = $data;
        $this->resetErrorBag("evaluasi.{$rtlId}.bukti");
        $this->adaPerubahan = true;

        $this->simpanOtomatisSetelahUnggah();
    }

    /**
     * Pratinjau letak folder Evaluasi-RTL satu poin menurut Struktur Folder Drive
     * yang berlaku untuk IKU ini (tanpa menyentuh Drive).
     *
     * @return array{jalur: list<string>, per_kegiatan: bool, per_bulan: bool}
     */
    public function polaFolderRtl(RtlEvaluasiModel $poin, ?Periode $periode = null): array
    {
        $iku = $this->ikuTerpilih();
        // Unggahan mandiri tersimpan di folder bulan isian ini; salinan dari Kegiatan
        // di folder bulan kegiatannya ($periode).
        $periode ??= $this->periodeSaatIni() ?? $poin->periode;

        return $this->cachePolaRtl[$poin->id.'|'.$periode?->id] ??= $iku
            ? FolderStructureService::polaFolderRtl($iku, (string) $poin->rtl_teks, $periode)
            : ['jalur' => [FolderStructureService::KATEGORI_KE_FOLDER['evaluasi_rtl']], 'per_kegiatan' => false, 'per_bulan' => false];
    }

    /** Memoisasi per request polaFolderRtl() — dipanggil berulang saat render. */
    protected array $cachePolaRtl = [];

    /**
     * Coba lagi salinan Evaluasi-RTL satu bukti kegiatan yang gagal tersalin (tombol
     * "Coba lagi salin" di Bagian 4). Hanya bukti kegiatan tertaut ke poin RTL form ini.
     */
    public function cobaLagiSalinanRtl(int $berkasId): void
    {
        $berkas = Berkas::where('ref_type', Kegiatan::class)->find($berkasId);
        $kegiatan = $berkas?->ref;

        if (! $kegiatan instanceof Kegiatan || ! $this->rtlTriwulanBerjalan()->contains('id', (int) $kegiatan->rtl_evaluasi_id)) {
            return;
        }

        $sinkron = app(SinkronBuktiRtlService::class);
        $sinkron->sinkronkanBerkas($berkas);
        $sinkron->cobaLagi(berkasId: $berkas->id);
        $tersalin = SalinanBuktiRtl::where('berkas_id', $berkas->id)->where('status', SalinanBuktiRtl::STATUS_TERSALIN)->exists();

        $this->cacheRtlBerjalan = null;
        Cache::forget($this->cacheKeyPeriodeIku('rtl-berjalan'));

        $this->dispatch('notify', type: $tersalin ? 'success' : 'error', message: $tersalin
            ? "✅ \"{$berkas->nama_file}\" sudah tersalin ke folder Evaluasi-RTL."
            : "\"{$berkas->nama_file}\" masih GAGAL disalin ke folder Evaluasi-RTL — berkas di folder Kegiatan tetap aman; coba lagi nanti.");
    }

    /**
     * @var array<int, array{kendala: string, solusi: string}>
     */
    public array $kendalaBlocks = [];

    /**
     * Poin isian untuk tiap bagian kustom aktif (mis. Manajemen Risiko), dikunci pada
     * id bagian_kustom — satu bagian bisa punya banyak poin, sama seperti kendalaBlocks.
     *
     * @var array<int, array<int, array{teks: string, bukti: array}>>
     */
    public array $bagianKustomBlocks = [];

    /**
     * Form realisasi per poin RTL triwulan sebelumnya yang belum dievaluasi, dikunci pada id baris rtl_evaluasi.
     *
     * @var array<int, array{realisasi: string, status_cocok: string, bukti: array}>
     */
    public array $evaluasi = [];

    /**
     * Blok RTL baru untuk triwulan berikutnya — hanya dipakai bila belum pernah ditetapkan untuk triwulan itu.
     * PIC, batas waktu, dan bulan berlaku (seluruh bulan triwulan berikutnya) berlaku SATU untuk seluruh
     * poin dalam satu triwulan, bukan per poin — cukup ditampilkan sebagai keterangan di judul bagian.
     *
     * @var array<int, array{rtl_teks: string}>
     */
    public array $rtlBaru = [];

    /**
     * PIC Tindak Lanjut — tim (BUKAN perorangan) penanggung jawab IKU terpilih,
     * HANYA untuk ditampilkan (lihat pilihPicOtomatis()). Ketua Tim tidak perlu &
     * tidak bisa mengisinya; nilai yang disimpan selalu dibaca ulang dari tim IKU
     * saat simpan (lihat simpanBagianIsian()). Kalau tim IKU belum dikonfigurasi,
     * Tim SAKIP yang mengisinya saat verifikasi (VerifikasiCapaian::verifikasiSelesai()).
     *
     * @var list<string>
     */
    public array $rtlBaruPicTerpilih = [];

    public string $rtlBaruBatasWaktu = '';

    /**
     * True selama ada isian (teks, pilihan, berkas yang baru dipilih) yang sudah
     * diubah di layar tapi BELUM tersimpan ke database lewat "Simpan Draft"/"Ajukan" —
     * dibaca <x-status-simpan> untuk bilah peringatan, peringatan tutup tab, dan
     * konfirmasi pindah menu/IKU/periode, supaya perubahan tidak hilang diam-diam.
     */
    public bool $adaPerubahan = false;

    /** Jam (HH:MM) Simpan Draft terakhir di sesi ini, untuk keterangan "✓ tersimpan". */
    public ?string $terakhirDisimpan = null;

    /**
     * Properti isian yang baru tersimpan lewat simpanDraft()/ajukanIsian() — lihat updated().
     *
     * @var list<string>
     */
    protected const PROPERTI_ISIAN = ['blocks', 'kendalaBlocks', 'bagianKustomBlocks', 'evaluasi', 'rtlBaru', 'rtlBaruBatasWaktu'];

    public function updated(string $property): void
    {
        if (in_array(Str::before($property, '.'), self::PROPERTI_ISIAN, true)) {
            $this->adaPerubahan = true;
        }

        // Bukti evaluasi RTL & bagian kustom terikat langsung lewat wire:model —
        // begitu selesai terunggah, langsung disimpan ke Drive (lihat simpanOtomatisSetelahUnggah()).
        if (preg_match('/^(evaluasi\.\d+|bagianKustomBlocks\.\d+\.\d+)\.bukti(\.\d+)?$/', $property, $cocok)) {
            $this->unggahLangsung($cocok[1]);
        }

        if (preg_match('/^blocks\.(\d+)\.uraian_kegiatan$/', $property, $cocok)) {
            $this->samakanUraianTahapan((int) $cocok[1]);
        }

        // Berkas yang tertahan karena uraian kegiatan/teks poin masih kosong langsung
        // diunggah begitu uraiannya diisi.
        if (preg_match('/^(blocks\.\d+)\.uraian_kegiatan$|^(bagianKustomBlocks\.\d+\.\d+)\.teks$/', $property, $cocok)) {
            $jalur = $cocok[1] ?: $cocok[2];
            $teks = data_get($this, $jalur.($cocok[1] ? '.uraian_kegiatan' : '.teks'));

            if (trim((string) $teks) !== '' && ! empty(data_get($this, "{$jalur}.bukti"))) {
                $this->simpanOtomatisSetelahUnggah();
            }
        }
    }

    /**
     * Bukti evaluasi RTL/bagian kustom yang baru terunggah ke $jalur (mis. "evaluasi.5")
     * diperiksa formatnya, lalu langsung disimpan ke Drive.
     */
    protected function unggahLangsung(string $jalur): void
    {
        $daftarBerkas = array_values(array_filter(Arr::wrap(data_get($this, "{$jalur}.bukti", []))));
        data_set($this, "{$jalur}.bukti", $daftarBerkas);

        // Poin evaluasi RTL hanya milik IKU & triwulan form ini — id dari browser tidak dipercaya.
        if (preg_match('/^evaluasi\.(\d+)$/', $jalur, $cocok) && ! $this->rtlTriwulanBerjalan()->contains('id', (int) $cocok[1])) {
            unset($this->evaluasi[(int) $cocok[1]]);

            return;
        }

        if ($daftarBerkas === []) {
            return;
        }

        // Bukti Bagian Kustom mengikuti aturan bagiannya (format, ukuran, jumlah
        // maksimal, atau tidak memakai bukti); bukti evaluasi RTL tetap PDF. Kekurangan
        // jumlah minimal baru diperiksa saat diajukan.
        $aturanBukti = null;

        if (preg_match('/^bagianKustomBlocks\.(\d+)\.(\d+)$/', $jalur, $cocokBagian)) {
            $aturanBukti = $this->bagianKustomAktif()->firstWhere('id', (int) $cocokBagian[1])?->aturanBuktiDi(MenuBagianKustom::PENGISIAN);
            $jumlahLama = count(data_get($this, "{$jalur}.existing_bukti", []));
            $galatJumlah = match (true) {
                $aturanBukti === null => 'Bagian ini tidak tersedia untuk Anda.',
                ! AturanBuktiBagianKustom::dipakai($aturanBukti) => 'Bagian ini tidak memakai bukti dukung.',
                $aturanBukti['maks'] !== null && $jumlahLama + count($daftarBerkas) > $aturanBukti['maks'] => AturanBuktiBagianKustom::galatJumlah($aturanBukti, $jumlahLama, count($daftarBerkas)),
                default => null,
            };

            if ($galatJumlah !== null) {
                data_set($this, "{$jalur}.bukti", []);
                $this->addError("{$jalur}.bukti", $galatJumlah);

                return;
            }
        }

        $validator = \Illuminate\Support\Facades\Validator::make(
            ['bukti' => $daftarBerkas],
            ['bukti.*' => $aturanBukti !== null ? AturanBuktiBagianKustom::aturanBerkas($aturanBukti) : ['file', 'mimes:pdf']],
            [
                'bukti.*.mimes' => $aturanBukti !== null ? 'Bukti harus berformat '.AturanBuktiBagianKustom::labelFormat($aturanBukti).'.' : 'Bukti harus berupa PDF.',
                'bukti.*.max' => 'Ukuran bukti melebihi batas '.($aturanBukti['ukuran_maks_mb'] ?? '').' MB.',
            ]
        );

        if ($validator->fails()) {
            data_set($this, "{$jalur}.bukti", []);
            $this->addError("{$jalur}.bukti", $validator->errors()->first());

            return;
        }

        if (str_starts_with($jalur, 'bagianKustomBlocks.') && trim((string) data_get($this, "{$jalur}.teks", '')) === '') {
            $this->addError("{$jalur}.teks", 'Isi poinnya dulu — berkas baru bisa tersimpan ke Google Drive setelah poin ini diisi.');

            return;
        }

        $this->simpanOtomatisSetelahUnggah();
    }

    /**
     * Berkas bukti yang baru dipilih LANGSUNG diunggah ke Google Drive tanpa menunggu
     * klik "Simpan Draft"/"Ajukan" — supaya bukti tidak hilang bila halaman ditutup,
     * sesi habis, atau pengguna lupa menyimpan. Caranya menjalankan Simpan Draft
     * biasa (isian lain ikut tersimpan sebagai draf, status tidak berubah), karena
     * berkas harus menempel ke baris Kegiatan/poin yang tersimpan di database.
     * Bila isian belum lengkap (validasi draf gagal), berkas tetap menunggu di form
     * dan ikut terunggah saat Simpan Draft berikutnya.
     */
    protected function simpanOtomatisSetelahUnggah(): void
    {
        $this->simpanDraft(otomatis: true);
    }

    /**
     * Form baru saja dimuat ulang dari database (ganti IKU/periode) — tidak ada lagi
     * perubahan yang tertunda.
     */
    protected function resetStatusPerubahan(): void
    {
        $this->adaPerubahan = false;
        $this->terakhirDisimpan = null;
    }

    /**
     * Cache dalam satu siklus request saja (di-reset otomatis tiap request baru karena
     * properti non-public tidak ikut disinkronkan Livewire) — beberapa query berat
     * (RTL berjalan, IKU terpilih, dst.) dipakai ulang di banyak tempat saat render()
     * DAN formLengkap() dipanggil di request yang sama; tanpa cache ini query yang sama
     * terulang belasan kali per klik/blur dan bikin halaman terasa lambat.
     */
    protected ?Collection $cacheRtlBerjalan = null;

    protected ?bool $cacheRtlBerikutnyaSudahAda = null;

    protected ?Collection $cacheRtlBerikutnyaDitolak = null;

    protected ?Collection $cacheRtlBerikutnyaAktif = null;

    protected ?Collection $cacheKendalaAktif = null;

    protected ?MasterIku $cacheIkuTerpilih = null;

    protected bool $cacheIkuTerpilihDihitung = false;

    protected ?Periode $cachePeriodeSaatIni = null;

    protected bool $cachePeriodeSaatIniDihitung = false;

    /**
     * Livewire mengirim SETIAP aksi (blur, klik pill, unggah berkas, dst.) sebagai
     * request AJAX terpisah yang membawa "snapshot" kondisi form versi client saat
     * itu. Kalau pengguna memicu beberapa aksi hampir bersamaan — paling sering
     * kejadian saat mengunggah lebih dari satu berkas bukti berurutan sambil masih
     * mengetik kolom lain — lebih dari satu request bisa diproses dari snapshot yang
     * SUDAH SALING BASI: unggahan berkas Livewire mengirim data mentahnya lewat jalur
     * terpisah dari commit properti biasa, jadi selama transfer berkas berlangsung
     * (bisa beberapa detik), Livewire menganggap TIDAK ADA request aktif untuk
     * komponen ini dan aksi lain bebas jalan duluan. Begitu unggahan itu akhirnya
     * "menyimpan" hasilnya, ia bisa memakai snapshot yang sudah ketinggalan
     * dibanding aksi-aksi lain yang keburu selesai duluan — hasilnya salah satu
     * perubahan tertimpa/hilang begitu balasannya datang belakangan. Ini akar
     * penyebab bug "berkas kedua hilang setelah diunggah" dan "uraian kegiatan
     * hilang saat klik Tambah Kegiatan" yang pernah dilaporkan.
     *
     * Guna mengunci supaya SEMUA request Livewire untuk komponen ini, per sesi
     * pengguna, benar-benar diproses satu per satu di server — request kedua
     * menunggu request pertama selesai (dan snapshot-nya sudah mutakhir) sebelum
     * mulai diproses, bukan langsung jalan dari snapshot yang sudah basi.
     */
    protected ?Lock $requestLock = null;

    public function boot(): void
    {
        // TTL kunci (40 detik) sengaja lebih panjang dari batas tunggu block() (25 detik)
        // supaya kunci tidak pernah kedaluwarsa sendiri sementara masih benar-benar dipegang.
        $this->requestLock = Cache::lock('pengisian-kegiatan-lock:'.session()->getId(), 40);

        try {
            $this->requestLock->block(25);
        } catch (LockTimeoutException $e) {
            // Kunci basi tertinggal dari request sebelumnya yang mati mendadak (mis. timeout
            // DB/Google Drive) sehingga dehydrate() tidak sempat memanggil release(). Daripada
            // membiarkan exception ini menjadi halaman 500 kosong, lewati kunci untuk request
            // ini saja — risikonya cuma snapshot sedikit basi, jauh lebih baik daripada crash.
            Log::warning('Kunci pengisian-kegiatan basi, dilewati.', ['session' => session()->getId()]);
            $this->requestLock = null;
            $this->dispatch('notify', type: 'warning', message: 'Sistem sedang sibuk memproses aksi sebelumnya — jika perubahan tidak muncul, coba ulangi.');
        }
    }

    public function dehydrate(): void
    {
        $this->requestLock?->release();
    }

    public function mount(): void
    {
        $this->tahun = request()->integer('tahun') ?: (int) now()->year;
        $this->bulan = request()->integer('bulan') ?: (int) now()->month;
        $this->blocks = [$this->emptyBlock()];
        $this->kendalaBlocks = [$this->emptyKendalaBlock()];
        $this->rtlBaru = [$this->emptyRtlBlock()];
        $this->rtlBaruBatasWaktu = $this->akhirTriwulanBerikutnya()->toDateString();
        $this->terakhirDisimpan = session('terakhirDisimpan');

        foreach ($this->bagianKustomAktif() as $bagian) {
            $this->bagianKustomBlocks[$bagian->id] = [$this->emptyBagianKustomBlock()];
        }

        // Deep-link dari baris tabel dasbor (App\Livewire\DasborUtama) — begitu IKU dipilih
        // dari URL, langsung muat evaluasi & PIC otomatis seperti updatedIkuId() supaya
        // pengguna tidak perlu memilih ulang IKU yang sudah jelas diklik.
        $ikuId = request()->integer('iku_id') ?: null;

        if ($ikuId && MasterIku::whereKey($ikuId)->exists()) {
            $this->iku_id = $ikuId;
            $this->muatFormEvaluasi();
            $this->muatBlocksKegiatan();
            $this->muatKendalaBlocks();
            $this->muatBagianKustomBlocks();
            $this->pilihPicOtomatis();
            $this->muatRtlBaruBlocks();
        }
    }

    protected function emptyBlock(): array
    {
        return [
            'id' => null,
            'status_dokumen' => null,
            'rtl_evaluasi_id' => null,
            'uraian_kegiatan' => '',
            'jenis' => '',
            'tahapan_survei' => '',
            // Tahapan lain yang dipilih sekaligus di blok ini (survei/sensus) — saat
            // disimpan tiap tahapan menjadi baris Kegiatan sendiri (lihat
            // simpanBagianIsian()), foldernya dikelompokkan di bawah folder induk kegiatan.
            'tahapan_tambahan' => [],
            // Nama folder Drive pilihan Ketua Tim — kosong = pakai rekomendasi sistem
            // (namaFolderPreview()), lihat Kegiatan::nama_folder_kustom.
            'nama_folder' => '',
            // Nama folder pilihan Ketua Tim untuk tahapan TAMBAHAN (kunci = tahapan);
            // tahapan utama memakai nama_folder di atas.
            'nama_folder_tahapan' => [],
            // Tahapan buatan Ketua Tim selain DAFTAR_TAHAPAN (lihat tambahTahapan()).
            'tahapan_kustom' => [],
            // Bawaan 1 folder; bila true, berkas bisa dimasukkan ke subfolder khusus
            // ($subfolders) di dalam folder kegiatan. Survei/sensus mengaturnya per
            // tahapan lewat *_tahapan (kunci = tahapan), lihat kunciSusunanFolder().
            'pakai_subfolder' => false,
            'subfolders' => [],
            'pakai_subfolder_tahapan' => [],
            'subfolders_tahapan' => [],
            'bukti' => [],
            'existing_bukti' => [],
            'catatan_bukti_dihapus' => null,
        ];
    }

    /**
     * Status kegiatan yang sudah masuk proses Tim SAKIP — blok dengan status ini
     * ditampilkan hanya-baca di form (lihat muatBlocksKegiatan()) supaya Ketua Tim
     * tidak menimpa data yang sedang/sudah diverifikasi. Hanya 'draft' & 'dikembalikan'
     * yang tetap bisa diedit/diajukan ulang lewat form ini.
     *
     * Juga dipakai untuk mengunci blok Bagian Kustom lewat statusCapaianSaatIni()
     * (lihat muatBagianKustomBlocks()/riwayatBagianKustom()) — makanya
     * Capaian::STATUS_SEDANG_DITANGANI ikut disertakan di sini walau bukan nilai
     * status_dokumen Kegiatan, supaya Bagian Kustom tetap terkunci selama Tim
     * SAKIP masih menangani (bukan cuma saat sudah 'diajukan').
     *
     * @var list<string>
     */
    protected const STATUS_KEGIATAN_TERKUNCI = [
        Kegiatan::STATUS_DIAJUKAN,
        Kegiatan::STATUS_DIVERIFIKASI,
        Kegiatan::STATUS_DISETUJUI,
        Capaian::STATUS_SEDANG_DITANGANI,
    ];

    /**
     * Pengajuan yang BARU diajukan (Capaian "diajukan") dan belum mulai diperiksa Tim
     * SAKIP masih boleh disunting kembali oleh Ketua Tim — baru terkunci begitu Tim
     * SAKIP mulai menangani ("sedang ditangani", lihat VerifikasiCapaian::
     * tandaiSedangDitangani()) atau sudah "diverifikasi"/"disetujui". Dicek ULANG tanpa
     * cache (formTerkunciSedangDitanganiFresh()) di setiap aksi yang menyimpan/menghapus,
     * supaya tidak ada perubahan yang menyelinap setelah Tim SAKIP mulai memeriksa.
     */
    public function pengajuanBisaDiedit(): bool
    {
        return $this->statusCapaianSaatIni() === Capaian::STATUS_DIAJUKAN;
    }

    /**
     * Blok kegiatan berstatus $statusDokumen terkunci hanya-baca? Sama seperti
     * STATUS_KEGIATAN_TERKUNCI, kecuali kegiatan "diajukan" selagi pengajuannya masih
     * bisa disunting (pengajuanBisaDiedit()).
     */
    public function blokTerkunci(?string $statusDokumen): bool
    {
        if ($statusDokumen === Kegiatan::STATUS_DIAJUKAN && $this->pengajuanBisaDiedit()) {
            return false;
        }

        return in_array($statusDokumen, self::STATUS_KEGIATAN_TERKUNCI, true);
    }

    /**
     * Versi level-Capaian dari blokTerkunci() — dipakai Bagian Kustom & Evaluasi RTL
     * yang tidak punya status_dokumen sendiri.
     */
    protected function capaianTerkunci(): bool
    {
        return $this->blokTerkunci($this->statusCapaianSaatIni());
    }

    /**
     * Akhiran kunci cache untuk data yang bentuknya bergantung pada pengajuanBisaDiedit()
     * — supaya hasil yang di-cache saat isian masih bisa disunting tidak terpakai lagi
     * setelah statusnya berpindah (atau sebaliknya).
     */
    protected function akhiranModeEdit(): string
    {
        return $this->pengajuanBisaDiedit() ? '-edit' : '';
    }

    protected function emptyKendalaBlock(): array
    {
        return [
            'id' => null,
            'kendala' => '',
            'solusi' => '',
            'status_verifikasi' => null,
            'catatan' => null,
        ];
    }

    protected function emptyRtlBlock(): array
    {
        return [
            'id' => null,
            'rtl_teks' => '',
        ];
    }

    protected function emptyBagianKustomBlock(): array
    {
        return [
            'id' => null,
            'teks' => '',
            'kolom' => [],
            'bukti' => [],
            'existing_bukti' => [],
            'catatan_bukti_dihapus' => null,
        ];
    }

    /**
     * Daftar bagian kustom aktif yang diisi Ketua Tim — dipakai untuk merender bagian
     * tambahan di form (mis. Manajemen Risiko) dan untuk validasi/penyimpanannya.
     * Bagian yang diisi Tim SAKIP/Kepala ada di halaman IsianBagianKustom.
     *
     * @return Collection<int, BagianKustom>
     */
    protected function bagianKustomAktif()
    {
        // Hanya bagian yang berlaku pada triwulan & tahun form ini (pengaturan "Periode
        // berlaku" di Data Master). Bagian lain tidak disentuh sama sekali oleh form ini.
        $triwulan = $this->bulan ? BagianKustom::triwulanDariBulan((int) $this->bulan) : null;

        return BagianKustom::daftarUntuk(BagianKustom::PERAN_KETUA_TIM, \App\Support\MenuBagianKustom::PENGISIAN)
            ->filter(fn (BagianKustom $b) => $b->berlakuPada($this->tahun ? (int) $this->tahun : null, $triwulan))
            ->values();
    }

    /**
     * Blok poin terisi bila uraian ATAU salah satu kolom tambahan sudah diisi.
     */
    protected function blokBagianKustomTerisi(array $blok): bool
    {
        return filled(trim((string) ($blok['teks'] ?? '')))
            || collect($blok['kolom'] ?? [])->contains(fn ($v) => BagianKustom::nilaiFormTerisi($v));
    }

    /**
     * Pratinjau nama folder Drive otomatis untuk satu blok kegiatan (RF-17, RF-22) —
     * dihitung sama seperti nama_folder_auto yang benar-benar disimpan saat diajukan,
     * supaya ketua tim tahu persis nama folder sebelum mengajukan.
     */
    public function namaFolderPreview(int $index): string
    {
        $block = $this->blocks[$index] ?? null;

        if (! $block || trim($block['uraian_kegiatan']) === '') {
            return '';
        }

        return $this->namaFolderRekomendasi($block);
    }

    /**
     * Rekomendasi sistem untuk nama folder Drive satu blok, sama dengan
     * Kegiatan::namaFolderRekomendasi(): folder tahapan (di dalam folder induk bernama
     * uraian kegiatan) cukup bernama tahapannya, mis. "Kegiatan X/Persiapan/";
     * kegiatan 1 folder bernama uraian kegiatannya.
     */
    protected function namaFolderRekomendasi(array $block): string
    {
        $tahapan = ($block['jenis'] ?? '') === 'survei_sensus' ? ($block['tahapan_survei'] ?? '') : '';

        return filled($tahapan) ? self::labelTahapan($tahapan) : $this->namaFolderOtomatis($block);
    }

    /**
     * nama_folder_auto yang disimpan simpanBagianIsian(): "<Tahapan> <uraian>" tanpa
     * kurung siku. Bukan lagi nama folder tahapan (lihat namaFolderRekomendasi()),
     * tetapi tetap disimpan sebagai jejak uraian asal (Kegiatan::uraianAsal()).
     */
    protected function namaFolderOtomatis(array $block): string
    {
        $tahapan = $block['jenis'] === 'survei_sensus' ? $block['tahapan_survei'] : null;

        return Str::limit(self::uraianDenganTahapan($block['uraian_kegiatan'], $tahapan), 100, '');
    }

    /**
     * Nama capaian satu tahapan survei/sensus: "<Tahapan> <uraian>", mis.
     * "Persiapan Survei Sakernas". Bukan survei/tanpa tahapan = uraian apa adanya.
     */
    public static function uraianDenganTahapan(string $uraian, ?string $tahapan): string
    {
        return filled($tahapan) ? (self::DAFTAR_TAHAPAN[$tahapan] ?? ucfirst($tahapan)).' '.$uraian : $uraian;
    }

    /**
     * Nama folder yang benar-benar dipakai satu blok: nama pilihan Ketua Tim
     * (blocks.*.nama_folder) bila diisi, selain itu rekomendasi sistem.
     */
    public function namaFolderBerlaku(int $index): string
    {
        $kustom = trim($this->blocks[$index]['nama_folder'] ?? '');

        return $kustom !== '' ? FolderStructureService::namaOtomatis($kustom, 150) : $this->namaFolderPreview($index);
    }

    /**
     * Nama bawaan berkas bukti BARU ke-$urutanBaru (0-based) — bila satu kegiatan/poin
     * punya lebih dari satu berkas (lama + baru), diberi nomor urut di paling belakang
     * ("Kegiatan X 1", "Kegiatan X 2", ...) supaya langsung terbedakan. Nomor
     * melanjutkan jumlah berkas yang sudah tersimpan. Ketua Tim tetap bisa menggantinya
     * lewat ✏️ (aturNamaBuktiBaru()), dan Tim SAKIP lewat gantiNamaBerkas() setelah tersimpan.
     */
    public function namaDefaultBuktiBaru(string $namaDasar, int $jumlahLama, int $urutanBaru, int $jumlahBaru): string
    {
        $namaDasar = FolderStructureService::namaOtomatis($namaDasar, 140);

        if ($jumlahLama + $jumlahBaru <= 1) {
            return $namaDasar;
        }

        return $namaDasar.' '.($jumlahLama + $urutanBaru + 1);
    }

    /**
     * Alur: IKU dulu, baru bagian lain (Kegiatan/Kendala/Evaluasi/RTL) bisa diisi bebas urutannya.
     */
    public function ikuDipilih(): bool
    {
        return filled($this->iku_id);
    }

    /**
     * Di dalam satu blok Kegiatan, tiap kolom terkunci sampai kolom sebelumnya terisi
     * (uraian → jenis → tahapan survei → bukti).
     */
    public function jenisTerkunci(int $index): bool
    {
        return trim($this->blocks[$index]['uraian_kegiatan'] ?? '') === '';
    }

    public function tahapanTerkunci(int $index): bool
    {
        return $this->jenisTerkunci($index) || blank($this->blocks[$index]['jenis'] ?? '');
    }

    public function buktiTerkunci(int $index): bool
    {
        $block = $this->blocks[$index] ?? null;

        if (! $block || blank($block['jenis'] ?? '')) {
            return true;
        }

        if ($block['jenis'] === 'survei_sensus') {
            return blank($block['tahapan_survei'] ?? '');
        }

        return false;
    }

    public function addBlock(): void
    {
        if ($this->formTerkunciDisetujui() || $this->formTerkunciSedangDitangani()) {
            return;
        }

        $this->blocks[] = $this->emptyBlock();
    }

    /**
     * Blok yang belum tersimpan (id === null) cukup dibuang dari array in-memory.
     * Blok yang SUDAH punya baris Kegiatan di DB hanya boleh dihapus PERMANEN
     * selagi statusnya masih 'draft' atau 'dikembalikan' (belum diajukan/dikunci
     * Tim SAKIP, lihat STATUS_KEGIATAN_TERKUNCI) — mis. Kepala mengembalikan isian
     * (NotulaService::kembalikanIsian()) karena satu kegiatan memang salah/tidak
     * relevan dan Ketua Tim perlu membuangnya sama sekali, bukan cuma menyunting
     * teksnya. Baris Kegiatan & seluruh berkas buktinya (file lokal + baris DB)
     * ikut dihapus di sini — TIDAK cukup dibuang dari $this->blocks saja, karena
     * muatBlocksKegiatan() akan memuat ulang SEMUA baris Kegiatan milik IKU+periode
     * ini dari DB tiap kali form dibuka, jadi kegiatan itu akan "muncul lagi" kalau
     * baris DB-nya sendiri tidak ikut dihapus.
     */
    public function removeBlock(int $index): void
    {
        $block = $this->blocks[$index] ?? null;

        if (! $block) {
            return;
        }

        if ($block['id'] === null) {
            unset($this->blocks[$index]);
            $this->blocks = array_values($this->blocks);

            return;
        }

        if ($this->formTerkunciDisetujuiFresh() || $this->formTerkunciSedangDitanganiFresh()) {
            return;
        }

        // Hanya kegiatan milik form ini (id dimuat server, IKU & periode cocok) dan status
        // kuncinya dibaca dari database — id/status kiriman browser tidak dipercaya.
        $kegiatan = $this->kegiatanMilikForm((int) $block['id']);

        if (! $kegiatan || $this->blokTerkunci($kegiatan->status_dokumen)) {
            return;
        }

        $kegiatan->load('berkas');

        // Bukti kegiatan TIDAK dihapus permanen dari Drive, hanya dipindah ke Sampah
        // (bisa dipulihkan 30 hari) — kegiatan bisa saja bagian dari RTL triwulan ini.
        // Bukti yang gagal dibuang dibiarkan tetap ada, dan kegiatannya tidak dihapus.
        $gagal = [];
        $alasan = null;
        foreach ($kegiatan->berkas as $berkas) {
            $alasanBerkas = null;

            if (! $this->hapusBuktiTersimpan($berkas, keSampah: true, tampilkanGagal: false, alasanGagal: $alasanBerkas)) {
                $gagal[$berkas->id] = $berkas->nama_file;
                $alasan ??= $alasanBerkas;
            }
        }

        $this->lupakanCachePeriodeIku();

        if ($gagal !== []) {
            $this->blocks[$index]['existing_bukti'] = array_values(array_filter(
                $this->blocks[$index]['existing_bukti'] ?? [],
                fn ($file) => isset($gagal[$file['id']])
            ));
            $this->dispatch('notify', type: 'error', message: 'Kegiatan TIDAK dihapus — '.count($gagal).' bukti gagal dipindahkan ke Sampah Google Drive ('.$alasan.'): '.implode(', ', $gagal).'. Bukti tersebut masih ada di SIPINTER dan Google Drive; coba lagi nanti.');

            return;
        }

        $jumlahBukti = $kegiatan->berkas->count();
        $kegiatan->delete();

        unset($this->versiDimuat['kegiatan'][$kegiatan->id]);
        unset($this->blocks[$index]);
        $this->blocks = array_values($this->blocks);

        if (empty($this->blocks)) {
            $this->blocks = [$this->emptyBlock()];
        }

        $this->dispatch('notify', type: 'success', message: $jumlahBukti > 0
            ? "Kegiatan dihapus. {$jumlahBukti} bukti dipindahkan ke Sampah Google Drive (masih bisa dipulihkan dari Sampah selama 30 hari)."
            : 'Kegiatan dihapus.');
    }

    /**
     * Hapus SATU bukti yang sudah tersimpan, dengan perilaku Drive yang sama seperti
     * sebelumnya: salinan Drive dihapus permanen (atau dipindah ke Sampah bila
     * $keSampah), dan bila Drive GAGAL bukti dibiarkan utuh.
     *
     * Urutannya:
     * 1. salinan di folder Evaluasi-RTL dibuang lebih dulu (sama seperti sebelumnya;
     *    status salinannya tercatat apa adanya, dan salinan dibuat ulang otomatis bila
     *    sumbernya ternyata tidak jadi dihapus);
     * 2. SATU transaksi: perubahan database pendamping + log ($sebelumHapus) → buang
     *    salinan Drive berkas sumber → hapus baris berkas. Kegagalan database atau
     *    pencatatan log terjadi SEBELUM salinan sumber disentuh dan membatalkan
     *    semuanya; kegagalan Drive juga membatalkan seluruh transaksi;
     * 3. berkas lokal baru dihapus setelah commit.
     *
     * @return bool false = Drive gagal (bila $tampilkanGagal, alasannya sudah ditampilkan)
     *
     * @throws \Throwable kegagalan database/log diteruskan apa adanya
     */
    protected function hapusBuktiTersimpan(Berkas $berkas, ?\Closure $sebelumHapus = null, bool $keSampah = false, bool $tampilkanGagal = true, ?string &$alasanGagal = null): bool
    {
        $galatDrive = null;

        try {
            try {
                $berkas->buangSalinanRtl($keSampah);
            } catch (\Throwable $e) {
                $galatDrive = $e;

                throw $e;
            }

            DB::transaction(function () use ($berkas, $sebelumHapus, $keSampah, &$galatDrive) {
                if ($sebelumHapus) {
                    $sebelumHapus();
                }

                try {
                    $berkas->buangSalinanDrive($keSampah);
                } catch (\Throwable $e) {
                    $galatDrive = $e;

                    throw $e;
                }

                $berkas->delete();
            });
        } catch (\Throwable $e) {
            if ($galatDrive === null) {
                throw $e;
            }

            $alasanGagal = self::alasanGagalDrive($galatDrive->getMessage());

            if ($tampilkanGagal) {
                $this->dispatch('notify', type: 'error', message: "Gagal menghapus \"{$berkas->nama_file}\" dari Google Drive — {$alasanGagal}. Bukti TIDAK dihapus dan masih ada di SIPINTER maupun Google Drive; coba lagi nanti.");
            }

            return false;
        }

        if ($berkas->path) {
            Storage::disk('local')->delete($berkas->path);
        }

        return true;
    }

    /**
     * Coba lagi unggah SATU berkas yang tersimpan tapi belum masuk Google Drive
     * (tombol "Coba lagi" di <x-belum-di-drive>) — tanpa mengulang berkas lain.
     * Berkas yang sebenarnya sudah masuk Drive tidak diunggah dobel (lihat
     * Berkas::sinkronUlangKeDrive()).
     */
    public function cobaLagiDrive(int $berkasId): void
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;
        $berkas = $periode ? Berkas::belumDiDrive()->whereKey($berkasId)->first() : null;

        if (! $berkas || ! $this->berkasMilikForm($berkas, $periode)) {
            return;
        }

        try {
            $berhasil = $berkas->sinkronUlangKeDrive();
        } catch (\Throwable $e) {
            Log::warning("Coba lagi berkas {$berkas->kode} ke Drive gagal: {$e->getMessage()}");
            $this->dispatch('notify', type: 'error', message: "\"{$berkas->nama_file}\" masih BELUM masuk Google Drive — ".self::alasanGagalDrive($e->getMessage()).'. Berkas tetap tersimpan di server; coba lagi nanti.');

            return;
        }

        if (! $berhasil) {
            $this->dispatch('notify', type: 'error', message: "\"{$berkas->nama_file}\" tidak bisa diunggah ulang — salinannya di server sudah tidak ada. Hapus berkas ini lalu unggah ulang dari komputer Anda.");

            return;
        }

        $this->dispatch('notify', type: 'success', message: "✅ \"{$berkas->nama_file}\" sekarang sudah tersimpan di Google Drive.");
        $this->lupakanCachePeriodeIku();
        $this->muatUlangForm($this->catatIsianBelumTersimpan());
    }

    /** Berkas ini milik kegiatan/poin IKU & periode yang sedang dibuka di form ini? */
    protected function berkasMilikForm(Berkas $berkas, Periode $periode): bool
    {
        $ref = $berkas->ref;

        return match (true) {
            $ref instanceof Kegiatan, $ref instanceof BagianKustomPoin => (int) $ref->iku_id === (int) $this->iku_id && (int) $ref->periode_id === (int) $periode->id,
            $ref instanceof RtlEvaluasiModel => $this->rtlTriwulanBerjalan()->contains('id', $ref->id),
            default => false,
        };
    }

    protected function notifikasiBuktiDihapus(Berkas $berkas): void
    {
        $this->dispatch('notify', type: 'success', message: "Bukti \"{$berkas->nama_file}\" dihapus permanen dari SIPINTER".($berkas->drive_file_id ? ' dan Google Drive.' : '.'));
    }

    /**
     * Menautkan sebuah blok kegiatan ke poin RTL triwulan berjalan lewat dropdown
     * (bukan lagi lewat pencocokan teks otomatis) — begitu dipilih, uraian kegiatan
     * ikut terisi dari teks RTL-nya sebagai titik awal, tapi Ketua Tim tetap bebas
     * menyunting teksnya sesudahnya; rtl_evaluasi_id TETAP tertaut walau teksnya
     * berubah (baru lepas lagi kalau dropdown dikembalikan ke "Ketik bebas").
     * Satu poin RTL BOLEH dipilih berulang di beberapa blok (mis. survei yang
     * dilaksanakan lewat beberapa tahapan dalam satu triwulan: Persiapan lalu
     * Pelaksanaan, atau keadaan khusus pada kegiatan bukan survei) — opsi yang sudah
     * pernah dipilih cukup diberi penanda (lihat rtlBerjalanOptions()). Yang dijaga
     * tidak dobel hanyalah TAHAPAN survei untuk kegiatan yang sama (lihat tahapanTerpakai()).
     *
     * Mengembalikan dropdown ke "Ketik bebas" SENGAJA mengosongkan uraian kegiatan
     * (bukan cuma melepas tautannya) — kalau teks lama dibiarkan, kolom uraian di
     * bawah kelihatan tetap terisi/"terkunci" dan Ketua Tim mengira tidak bisa
     * mengetik uraiannya sendiri, padahal cuma perlu diklik & ditimpa.
     */
    public function pilihRtlUntukBlock(int $index, $rtlId): void
    {
        if (! isset($this->blocks[$index])) {
            return;
        }

        $rtlId = $rtlId !== '' && $rtlId !== null ? (int) $rtlId : null;

        if ($rtlId === null) {
            $this->blocks[$index]['rtl_evaluasi_id'] = null;
            $this->blocks[$index]['uraian_kegiatan'] = '';
            $this->adaPerubahan = true;

            return;
        }

        $poin = $this->rtlTriwulanBerjalan()->firstWhere('id', $rtlId);

        if (! $poin) {
            return;
        }

        $this->blocks[$index]['rtl_evaluasi_id'] = $poin->id;
        $this->blocks[$index]['uraian_kegiatan'] = $poin->rtl_teks;
        $this->adaPerubahan = true;
        $this->samakanUraianTahapan($index);
    }

    /**
     * Indeks blok tersimpan lain yang masih bisa disunting dalam kartu kegiatan induk
     * yang sama dengan blok $index (berdampingan, nomor Kegiatan N sama — lihat
     * nomorKegiatanBlok(), yang menilai blok tersimpan dari baris DB-nya).
     *
     * @return list<int>
     */
    public function saudaraTahapanBlok(int $index): array
    {
        if (empty($this->blocks[$index]['id'])) {
            return [];
        }

        $nomor = $this->nomorKegiatanBlok();
        $saudara = [];

        foreach ([-1, 1] as $arah) {
            for ($j = $index + $arah; isset($this->blocks[$j]) && ($nomor[$j] ?? null) === ($nomor[$index] ?? null); $j += $arah) {
                if (! empty($this->blocks[$j]['id']) && ! $this->blokTerkunci($this->blocks[$j]['status_dokumen'] ?? null)) {
                    $saudara[] = $j;
                }
            }
        }

        sort($saudara);

        return $saudara;
    }

    /**
     * Uraian (dan poin RTL) adalah milik kegiatan INDUK, bukan per tahapan — diisi
     * sekali di kartu "Kegiatan N", lalu disamakan ke tahapan-tahapan lain yang masih
     * bisa disunting supaya satu kegiatan tidak terpecah jadi beberapa kegiatan.
     */
    protected function samakanUraianTahapan(int $index): void
    {
        foreach ($this->saudaraTahapanBlok($index) as $j) {
            $this->blocks[$j]['uraian_kegiatan'] = $this->blocks[$index]['uraian_kegiatan'];
            $this->blocks[$j]['rtl_evaluasi_id'] = $this->blocks[$index]['rtl_evaluasi_id'] ?? null;
        }
    }

    public function removeBuktiKegiatan(int $blockIndex, int $fileIndex): void
    {
        unset($this->blocks[$blockIndex]['bukti'][$fileIndex]);
        $this->blocks[$blockIndex]['bukti'] = array_values($this->blocks[$blockIndex]['bukti']);
    }

    /**
     * Hapus SATU berkas bukti capaian yang SUDAH TERSIMPAN (existing_bukti) — hanya
     * dipakai saat Tim SAKIP menandainya "Tidak Sesuai", supaya Ketua Tim bisa membuang
     * bukti yang salah lalu mengunggah gantinya (tidak ada jalan lain untuk mengganti
     * bukti lama sebelum ini — sebelumnya hanya bisa MENAMBAH berkas baru di samping
     * yang lama). Berkas yang sudah "terverifikasi" (Sesuai) TIDAK boleh dihapus/diedit
     * dari sini — sekali Tim SAKIP menandainya Sesuai, berkas itu terkunci permanen
     * bagi Ketua Tim, walau kegiatan induknya sendiri masih terbuka untuk berkas lain
     * yang ditolak (lihat "Rincian N" — status per-berkas, bukan per-kegiatan). Dicek
     * di sini, bukan cuma disembunyikan tombolnya di blade — defense in depth, pola
     * yang sama dipakai VerifikasiCapaian::tandaiTolak() dkk.
     * File fisik lokal & salinan di Google Drive ikut dihapus permanen (lihat
     * Berkas::buangSalinan()); konfirmasinya lewat dialog pendingHapus di blade.
     */
    public function hapusBuktiLama(int $blockIndex, int $berkasId): void
    {
        $block = $this->blocks[$blockIndex] ?? null;

        if (! $block || ! $block['id']) {
            return;
        }

        // Kegiatan harus milik formulir ini (dimuat server, IKU & periode cocok) dan
        // status kuncinya dibaca dari database — bukan dari data kiriman browser.
        $kegiatan = $this->kegiatanMilikForm((int) $block['id']);

        if (! $kegiatan) {
            return;
        }

        $berkas = Berkas::where('id', $berkasId)
            ->where('ref_type', Kegiatan::class)
            ->where('ref_id', $kegiatan->id)
            ->first();

        if (! $berkas || ! $this->buktiLamaBisaDikelola($berkas->status_verifikasi, $this->blokTerkunci($kegiatan->status_dokumen), fresh: true)) {
            return;
        }

        // Catatan penolakan disalin ke Kegiatan-nya SENDIRI sebelum berkasnya benar-benar
        // hilang — begitu berkas ini dihapus, baris & catatannya lenyap dari tabel
        // `berkas`, jadi Tim SAKIP tidak akan tahu lagi APA yang tadinya salah saat
        // memeriksa bukti pengganti. Pengingat ini dikosongkan lagi begitu Kegiatan
        // ditandai "diverifikasi" (lihat Kegiatan::verifikasi()). Satu transaksi dengan
        // penghapusan (lihat hapusBuktiTersimpan()).
        $terhapus = $this->hapusBuktiTersimpan($berkas, function () use ($berkas, $kegiatan) {
            if ($berkas->catatan) {
                Kegiatan::whereKey($kegiatan->id)->update(['catatan_bukti_dihapus' => $berkas->catatan]);
            }
        });

        if (! $terhapus) {
            return;
        }

        if ($berkas->catatan) {
            $this->blocks[$blockIndex]['catatan_bukti_dihapus'] = $berkas->catatan;
        }

        $this->notifikasiBuktiDihapus($berkas);

        $this->blocks[$blockIndex]['existing_bukti'] = collect($this->blocks[$blockIndex]['existing_bukti'])
            ->reject(fn ($file) => $file['id'] === $berkasId)
            ->values()
            ->all();

        // Bukti yang sama juga tampil di Evaluasi RTL bila kegiatannya tertaut RTL.
        $this->cacheRtlBerjalan = null;
        Cache::forget($this->cacheKeyPeriodeIku('rtl-berjalan'));
    }

    /**
     * Bagian kustom di Isian Kegiatan hanya diisi Ketua Tim — dicek dari peran terkini
     * di setiap aksi yang mengubah poin/bukti, jadi pengguna yang kehilangan peran itu
     * saat halaman masih terbuka tetap ditolak.
     */
    protected function penggunaKetuaTim(): bool
    {
        return BagianKustom::peranDariNama(auth()->user()?->namaRole()) === BagianKustom::PERAN_KETUA_TIM;
    }

    /**
     * Poin bagian kustom milik form ini: bagian aktif untuk Ketua Tim di Isian
     * Kegiatan, IKU + periode yang sedang dipilih, dan konteks Isian Kegiatan (bukan
     * poin Tim SAKIP/Kepala di menu lain).
     */
    protected function poinBagianKustomForm(int $bagianId, int $poinId, Periode $periode): ?BagianKustomPoin
    {
        if (! $this->bagianKustomAktif()->contains('id', $bagianId)) {
            return null;
        }

        return BagianKustomPoin::konteksIsianKegiatan()
            ->whereKey($poinId)
            ->where('bagian_kustom_id', $bagianId)
            ->where('iku_id', $this->iku_id)
            ->where('periode_id', $periode->id)
            ->first();
    }

    /**
     * Periksa SEMUA blok bagian kustom sebelum apa pun disimpan/diunggah: peran harus
     * Ketua Tim, setiap id poin harus milik form ini (lihat poinBagianKustomForm()),
     * tidak ganda, dan poin lama hanya boleh diubah selagi isian belum terkunci (status
     * dibaca langsung dari DB, tanpa cache). Pelanggaran ditolak 403 tanpa mengubah
     * data apa pun.
     */
    protected function pastikanPoinBagianKustomSah(?Periode $periode): void
    {
        $adaIsian = false;
        $idDikirim = [];

        foreach ($this->bagianKustomBlocks as $bagianId => $daftar) {
            foreach ((array) $daftar as $blok) {
                if (filled($blok['id'] ?? null)) {
                    $idDikirim[] = [(int) $bagianId, (int) $blok['id']];
                    $adaIsian = true;
                } elseif (is_array($blok) && $this->blokBagianKustomTerisi($blok)) {
                    $adaIsian = true;
                }
            }
        }

        if (! $adaIsian) {
            return;
        }

        // Penolakan dicatat ke log aktivitas lebih dulu (di luar transaksi, belum ada
        // data yang diubah atau berkas yang diunggah), baru kemudian 403.
        $tolak = function (string $alasan, ?int $bagianId = null) use ($periode, $idDikirim): never {
            JejakAktivitasBagianKustom::ditolak($bagianId ? BagianKustom::find($bagianId) : null, $alasan, [
                'menu' => MenuBagianKustom::PENGISIAN,
                'iku_id' => $this->iku_id,
                'periode_id' => $periode?->id,
                'perubahan' => ['id_poin_dikirim' => array_map(fn ($p) => $p[1], $idDikirim)],
            ]);
            abort(403);
        };

        if (! $this->penggunaKetuaTim()) {
            $tolak('hanya Ketua Tim yang dapat mengisi bagian kustom di Isian Kegiatan');
        }

        // Matriks hak akses per bagian (Data Master): poin baru = buat, berkas baru =
        // unggah bukti. Perubahan poin lama (ubah) diperiksa di bawah setelah poinnya
        // dipastikan milik form ini.
        $peranKetua = BagianKustom::PERAN_KETUA_TIM;

        foreach ($this->bagianKustomBlocks as $bagianId => $daftar) {
            $bagian = $this->bagianKustomAktif()->firstWhere('id', (int) $bagianId);

            if (! $bagian) {
                continue;
            }

            foreach ((array) $daftar as $blok) {
                if (! is_array($blok)) {
                    continue;
                }

                if (blank($blok['id'] ?? null) && $this->blokBagianKustomTerisi($blok) && ! $bagian->bolehAksi($peranKetua, 'buat')) {
                    $tolak('peran pengguna tidak punya hak "'.BagianKustom::AKSI['buat'].'" di bagian ini', (int) $bagianId);
                }

                if (! empty($blok['bukti']) && ! $bagian->bolehAksi($peranKetua, 'unggah_bukti')) {
                    $tolak('peran pengguna tidak punya hak "'.BagianKustom::AKSI['unggah_bukti'].'" di bagian ini', (int) $bagianId);
                }
            }
        }

        if ($idDikirim === []) {
            return;
        }

        if (collect($idDikirim)->pluck(1)->duplicates()->isNotEmpty()) {
            $tolak('id poin dikirim ganda');
        }

        // Poin lama hanya ada pada periode yang sudah ada di database.
        if (! $periode) {
            $tolak('konteks data tidak sesuai (periode formulir belum ada, tetapi id poin dikirim)');
        }

        // Poin lama hanya dimuat ke form selagi isian belum terkunci (lihat
        // muatBagianKustomBlocks()) — id poin yang dikirim saat terkunci pasti bukan
        // dari form yang sah.
        $status = Capaian::where('iku_id', $this->iku_id)->where('periode_id', $periode->id)->value('status');

        if (in_array($status, [Capaian::STATUS_DIVERIFIKASI, Capaian::STATUS_DISETUJUI, Capaian::STATUS_SEDANG_DITANGANI], true)) {
            $tolak('isian IKU ini sedang terkunci (status '.$status.')');
        }

        foreach ($idDikirim as [$bagianId, $poinId]) {
            $poin = $this->poinBagianKustomForm($bagianId, $poinId, $periode);

            if (! $poin) {
                $tolak('konteks data tidak sesuai (poin bukan milik bagian, IKU, periode, atau menu Isian Kegiatan pada form ini)', $bagianId);
            }

            $bagian = $this->bagianKustomAktif()->firstWhere('id', $bagianId);

            if ($bagian && ! $bagian->bolehAksi($peranKetua, 'ubah') && $this->poinBagianKustomBerubah($bagian, $poin, $bagianId)) {
                $tolak('peran pengguna tidak punya hak "'.BagianKustom::AKSI['ubah'].'" di bagian ini', $bagianId);
            }
        }
    }

    /** Isi blok form untuk poin lama ini berbeda dari yang tersimpan (teks atau kolom). */
    protected function poinBagianKustomBerubah(BagianKustom $bagian, BagianKustomPoin $poin, int $bagianId): bool
    {
        $blok = collect($this->bagianKustomBlocks[$bagianId] ?? [])->first(fn ($b) => (int) ($b['id'] ?? 0) === $poin->id);

        if (! is_array($blok)) {
            return false;
        }

        $kolomBaru = $bagian->bersihkanNilaiKolom($blok['kolom'] ?? [], $poin->nilai_kolom, $this->tahun, BagianKustom::triwulanDariBulan($this->bulan));
        $kolomLama = array_filter((array) ($poin->nilai_kolom ?? []), fn ($v) => trim((string) $v) !== '');

        return trim((string) ($blok['teks'] ?? '')) !== trim((string) $poin->teks) || $kolomBaru != $kolomLama;
    }

    /**
     * Hapus SATU berkas bukti dukung bagian kustom (mis. Manajemen Risiko) yang SUDAH
     * TERSIMPAN dan ditandai "Tidak Sesuai" oleh Tim SAKIP — pasangan dari
     * hapusBuktiLama() di atas, tapi untuk BagianKustomPoin. Poin dicocokkan ke
     * iku_id+periode_id form ini supaya Ketua Tim tidak bisa menghapus bukti milik
     * IKU/periode lain lewat manipulasi parameter. Salinan lokal & Google Drive ikut
     * dihapus permanen (sama seperti hapusBuktiLama()).
     */
    public function hapusBuktiLamaBagianKustom(int $poinId, int $berkasId): void
    {
        abort_unless($this->penggunaKetuaTim(), 403);

        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        if (! $periode) {
            return;
        }

        $poin = BagianKustomPoin::konteksIsianKegiatan()
            ->where('id', $poinId)
            ->where('iku_id', $this->iku_id)
            ->where('periode_id', $periode->id)
            ->whereIn('bagian_kustom_id', $this->bagianKustomAktif()->pluck('id'))
            ->first();

        if (! $poin) {
            return;
        }

        if (! $poin->bagianKustom?->bolehAksi(BagianKustom::PERAN_KETUA_TIM, 'hapus_bukti')) {
            JejakAktivitasBagianKustom::ditolak($poin->bagianKustom, 'peran pengguna tidak punya hak "'.BagianKustom::AKSI['hapus_bukti'].'" di bagian ini', [
                'menu' => MenuBagianKustom::PENGISIAN,
                'iku_id' => $this->iku_id,
                'periode_id' => $periode->id,
            ]);
            abort(403);
        }

        $berkas = Berkas::where('id', $berkasId)
            ->where('ref_type', BagianKustomPoin::class)
            ->where('ref_id', $poin->id)
            ->first();

        if (! $berkas || ! $this->buktiLamaBisaDikelola($berkas->status_verifikasi, $this->capaianTerkunci(), fresh: true)) {
            return;
        }

        // Sama seperti hapusBuktiLama() untuk Kegiatan — salin catatan penolakan ke
        // poin ini sendiri sebelum berkasnya hilang. Dikosongkan lagi begitu poin
        // ditandai "Sesuai" (lihat VerifikasiCapaian::tandaiBagianKustomSesuai()).
        // Perubahan DB + log aktivitas satu transaksi dengan penghapusan, dan ditulis
        // SEBELUM salinan Drive dibuang (lihat hapusBuktiTersimpan()).
        $terhapus = $this->hapusBuktiTersimpan($berkas, function () use ($poin, $berkas) {
            if ($berkas->catatan) {
                $poin->update(['catatan_bukti_dihapus' => $berkas->catatan]);
            }

            JejakAktivitasBagianKustom::buktiDihapus($poin->bagianKustom, $poin, $berkas);
        });

        if (! $terhapus) {
            return;
        }

        $this->notifikasiBuktiDihapus($berkas);

        Cache::forget($this->cacheKeyPeriodeIku("riwayat-bagian-{$poin->bagian_kustom_id}"));
        Cache::forget($this->cacheKeyPeriodeIku("riwayat-bagian-{$poin->bagian_kustom_id}-edit"));

        // Cerminkan penghapusan ke state in-memory (dimuat lewat muatBagianKustomBlocks())
        // supaya blade langsung mencerminkannya tanpa reload — pola sama seperti
        // hapusBuktiLama() di atas untuk existing_bukti milik Kegiatan.
        foreach ($this->bagianKustomBlocks[$poin->bagian_kustom_id] ?? [] as $i => $blok) {
            if (($blok['id'] ?? null) !== $poin->id) {
                continue;
            }

            $this->bagianKustomBlocks[$poin->bagian_kustom_id][$i]['existing_bukti'] = collect($blok['existing_bukti'])
                ->reject(fn ($file) => $file['id'] === $berkasId)
                ->values()
                ->all();

            if ($berkas->catatan) {
                $this->bagianKustomBlocks[$poin->bagian_kustom_id][$i]['catatan_bukti_dihapus'] = $berkas->catatan;
            }
        }
    }

    /**
     * Hapus SATU berkas bukti realisasi RTL triwulan berjalan yang SUDAH TERSIMPAN dan
     * ditandai "Tidak Sesuai" oleh Tim SAKIP — pasangan dari hapusBuktiLama() di atas,
     * tapi untuk RtlEvaluasi. $rtlId dicocokkan ke rtlTriwulanBerjalan() (sudah di-scope
     * ke iku_id+tahun+triwulan form ini) supaya tidak bisa menghapus bukti milik
     * IKU/periode lain. Cache in-memory & lintas-request 'rtl-berjalan' ikut dibuang
     * supaya $poin->berkas di blade langsung mencerminkan penghapusan tanpa reload.
     */
    public function hapusBuktiLamaEvaluasi(int $rtlId, int $berkasId): void
    {
        $poin = $this->rtlTriwulanBerjalan()->firstWhere('id', $rtlId);

        if (! $poin) {
            return;
        }

        $berkas = Berkas::where('id', $berkasId)
            ->where('ref_type', RtlEvaluasiModel::class)
            ->where('ref_id', $poin->id)
            ->first();

        if (! $berkas || ! $this->buktiLamaBisaDikelola($berkas->status_verifikasi, $this->capaianTerkunci(), fresh: true)) {
            return;
        }

        // Sama seperti hapusBuktiLama() untuk Kegiatan — salin catatan penolakan ke
        // poin RTL ini sendiri sebelum berkasnya hilang. Dikosongkan lagi begitu poin
        // ditandai "Sesuai" (lihat VerifikasiCapaian::tandaiRtlSesuai()).
        $terhapus = $this->hapusBuktiTersimpan($berkas, function () use ($poin, $berkas) {
            if ($berkas->catatan) {
                $poin->update(['catatan_bukti_dihapus' => $berkas->catatan]);
            }
        });

        if (! $terhapus) {
            return;
        }

        $this->notifikasiBuktiDihapus($berkas);

        $this->cacheRtlBerjalan = null;
        Cache::forget($this->cacheKeyPeriodeIku('rtl-berjalan'));
    }

    /**
     * Status yang dianggap "sudah diverifikasi Tim SAKIP" — pada status ini isian
     * terkunci, tapi Ketua Tim tetap boleh MENAMBAH bukti dukung baru (lihat
     * $buktiSusulan). Berkas lama tidak bisa diubah/dihapus.
     *
     * @var list<string>
     */
    public const STATUS_BOLEH_BUKTI_SUSULAN = [Kegiatan::STATUS_DIVERIFIKASI, Kegiatan::STATUS_DISETUJUI];

    /** Kegiatan blok ini sudah diverifikasi → boleh ditambah bukti susulan. */
    public function kegiatanBolehBuktiSusulan(array $block): bool
    {
        return ! empty($block['id']) && in_array($block['status_dokumen'] ?? null, self::STATUS_BOLEH_BUKTI_SUSULAN, true);
    }

    /**
     * Bolehkah Ketua Tim menghapus/mengganti/mengganti nama SATU berkas yang sudah
     * tersimpan? "Sesuai" (terverifikasi) = hanya-lihat. Berkas yang ditandai "Tidak
     * Sesuai" Tim SAKIP selalu bisa dikelola walau bagiannya terkunci (mis. kegiatan
     * sudah diverifikasi), kecuali selagi Tim SAKIP sedang menanganinya. Berkas yang
     * belum diperiksa hanya bisa dikelola selagi bagiannya belum terkunci.
     */
    public function buktiLamaBisaDikelola(?string $status, bool $bagianTerkunci, bool $fresh = false): bool
    {
        if ($status === 'terverifikasi') {
            return false;
        }

        if ($fresh ? $this->formTerkunciSedangDitanganiFresh() : $this->formTerkunciSedangDitangani()) {
            return false;
        }

        return ! $bagianTerkunci || ($status === 'ditolak' && $this->verifikasiTerlihat());
    }

    /**
     * Berkas pengganti untuk berkas yang ditandai "Tidak Sesuai" — kunci = id berkas
     * lama. Begitu dipilih, berkas lama langsung diganti (updatedGantiBerkas()).
     *
     * @var array<int|string, mixed>
     */
    public array $gantiBerkas = [];

    public function updatedGantiBerkas($nilai, ?string $kunci = null): void
    {
        $berkasId = (int) explode('.', (string) $kunci)[0];
        unset($this->gantiBerkas[$berkasId]);

        $file = is_array($nilai) ? (array_values(array_filter($nilai))[0] ?? null) : $nilai;
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;
        $lama = $berkasId ? Berkas::find($berkasId) : null;

        if (! $file || ! $periode || ! $lama || $lama->status_verifikasi !== 'ditolak') {
            return;
        }

        $validator = \Illuminate\Support\Facades\Validator::make(
            ['bukti' => $file],
            ['bukti' => ['file', 'mimes:pdf']],
            ['bukti.mimes' => 'Berkas pengganti harus berupa PDF.']
        );

        if ($validator->fails()) {
            $this->addError("gantiBerkas.{$berkasId}", $validator->errors()->first());

            return;
        }

        // Pastikan berkas ini milik IKU + periode form ini, dan tentukan bagiannya terkunci atau tidak.
        $ref = $lama->ref;
        [$milikForm, $terkunci] = match (true) {
            $ref instanceof Kegiatan => [(int) $ref->iku_id === (int) $this->iku_id && (int) $ref->periode_id === (int) $periode->id, $this->blokTerkunci($ref->status_dokumen)],
            $ref instanceof RtlEvaluasiModel => [$this->rtlTriwulanBerjalan()->contains('id', $ref->id), $this->capaianTerkunci()],
            $ref instanceof BagianKustomPoin => [
                $this->penggunaKetuaTim() && $this->poinBagianKustomForm((int) $ref->bagian_kustom_id, (int) $ref->id, $periode) !== null,
                $this->capaianTerkunci(),
            ],
            default => [false, true],
        };

        if (! $milikForm || ! $this->buktiLamaBisaDikelola($lama->status_verifikasi, $terkunci, fresh: true)) {
            // Ditolak SEBELUM berkas pengganti disimpan/diunggah ke Drive.
            if ($ref instanceof BagianKustomPoin && ! $milikForm) {
                JejakAktivitasBagianKustom::ditolak($ref->bagianKustom, 'penggantian bukti dukung untuk poin di luar form/konteks pengguna ini', [
                    'objek_tipe' => LogAktivitas::OBJEK_BERKAS,
                    'objek_id' => $lama->id,
                    'menu' => MenuBagianKustom::PENGISIAN,
                    'iku_id' => $this->iku_id,
                    'periode_id' => $periode->id,
                ]);
            }

            return;
        }

        // Matriks hak akses Bagian Kustom: mengganti bukti = mengunggah berkas baru.
        if ($ref instanceof BagianKustomPoin && ! $ref->bagianKustom?->bolehAksi(BagianKustom::PERAN_KETUA_TIM, 'unggah_bukti')) {
            JejakAktivitasBagianKustom::ditolak($ref->bagianKustom, 'peran pengguna tidak punya hak "'.BagianKustom::AKSI['unggah_bukti'].'" di bagian ini', [
                'objek_tipe' => LogAktivitas::OBJEK_BERKAS,
                'objek_id' => $lama->id,
                'menu' => MenuBagianKustom::PENGISIAN,
                'iku_id' => $this->iku_id,
                'periode_id' => $periode->id,
            ]);
            abort(403);
        }

        // Satu penggantian per berkas lama dalam satu waktu (lintas pengguna/jendela) —
        // tanpa ini dua penggantian bersamaan sama-sama membuat berkas pengganti.
        $kunciGanti = Cache::lock('ganti-berkas-'.$lama->id, 120);

        if (! $kunciGanti->get()) {
            $this->addError("gantiBerkas.{$berkasId}", 'Berkas ini sedang diganti dari jendela/pengguna lain. Muat ulang halaman sebentar lagi.');

            return;
        }

        try {
            $namaBerkas = $this->namaAsliBuktiBaru($file) ?: pathinfo($lama->nama_file, PATHINFO_FILENAME);
            $path = $file->store(dirname((string) $lama->path) !== '.' ? dirname((string) $lama->path) : 'bukti-capaian', 'local');

            // Berkas lama dibaca ulang & dikunci: bila sudah diganti/dihapus/berubah status
            // sejak halaman dimuat, tidak ada berkas pengganti yang dibuat.
            try {
                $baru = DB::transaction(function () use ($lama, $namaBerkas, $path) {
                    $lamaTerkunci = Berkas::whereKey($lama->id)->lockForUpdate()->first();

                    if (! $lamaTerkunci || $lamaTerkunci->status_verifikasi !== 'ditolak') {
                        return null;
                    }

                    return Berkas::create([
                        'ref_id' => $lama->ref_id,
                        'ref_type' => $lama->ref_type,
                        'kategori' => $lama->kategori,
                        'nama_file' => $namaBerkas.'.pdf',
                        'subfolder' => $lama->subfolder,
                        // Berkas pengganti menempati folder yang sama dengan berkas yang diganti.
                        'tahapan' => $lama->tahapan,
                        'periode_id' => $lama->periode_id,
                        'path' => $path,
                        'status_verifikasi' => 'menunggu',
                    ]);
                });
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($path);

                throw $e;
            }

            if (! $baru) {
                Storage::disk('local')->delete($path);
                $this->addError("gantiBerkas.{$berkasId}", 'Berkas ini sudah diganti atau statusnya berubah. Muat ulang halaman untuk melihat keadaan terbaru.');

                return;
            }

            // Unggah setelah baris pengganti tersimpan (commit). Gagal unggah tidak
            // membatalkan apa pun: berkas aman di server dan bisa dicoba lagi.
            $diDrive = false;
            try {
                $baru->setRelation('ref', $ref)->unggahKeDrive();
                $diDrive = true;
                $this->notifikasiHasilUnggah($file->getClientOriginalName(), 'berkas pengganti', true);
            } catch (\Throwable $e) {
                Log::warning('Gagal mengunggah berkas pengganti ke Google Drive, disimpan lokal saja: '.$e->getMessage());
                $this->notifikasiHasilUnggah($file->getClientOriginalName(), 'berkas pengganti', false, $e->getMessage());
            }

            // Berkas lama baru dihapus bila salinan Drive-nya berhasil dihapus (permanen) —
            // bila gagal, berkas lama dibiarkan tetap ada (tampil di samping berkas
            // pengganti). Alasan penolakan disimpan di kegiatan/poinnya supaya Tim SAKIP
            // tetap tahu apa yang diperbaiki (sama seperti hapusBuktiLama()); catatan, log
            // penggantian, buang salinan Drive & hapus baris lama satu transaksi.
            $alasanLamaGagal = null;
            $this->hapusBuktiTersimpan($lama, function () use ($lama, $ref, $baru, $diDrive) {
                if ($lama->catatan) {
                    $ref->update(['catatan_bukti_dihapus' => $lama->catatan]);
                }

                if ($ref instanceof BagianKustomPoin) {
                    JejakAktivitasBagianKustom::buktiDiganti($ref->bagianKustom, $ref, $lama, $baru, $diDrive);
                }
            }, tampilkanGagal: false, alasanGagal: $alasanLamaGagal);

            if ($alasanLamaGagal !== null && $ref instanceof BagianKustomPoin) {
                // Bukti lama masih ada (Drive gagal) — yang tercatat: bukti baru diunggah.
                JejakAktivitasBagianKustom::buktiDiunggah($ref->bagianKustom, $ref, $baru, $diDrive);
            }
        } finally {
            $kunciGanti->release();
        }

        $hapusLama = $alasanLamaGagal === null ? 1 : 0;

        $baru->refresh();
        $dataBaru = [
            'id' => $baru->id,
            'kode' => $baru->kode,
            'nama_file' => $baru->nama_file,
            'subfolder' => $baru->subfolder,
            'diunggah_oleh' => $baru->diunggah_oleh,
            'di_drive' => filled($baru->drive_file_id),
            'status_verifikasi' => $baru->status_verifikasi,
            'catatan' => $baru->catatan,
        ];

        foreach ($this->blocks as $i => $block) {
            foreach ($block['existing_bukti'] ?? [] as $j => $f) {
                if ($f['id'] === $berkasId) {
                    array_splice($this->blocks[$i]['existing_bukti'], $j + 1 - $hapusLama, $hapusLama, [$this->maskBerkas($dataBaru)]);
                    if ($hapusLama) {
                        $this->blocks[$i]['catatan_bukti_dihapus'] = $lama->catatan ?: ($block['catatan_bukti_dihapus'] ?? null);
                    }
                }
            }
        }

        foreach ($this->bagianKustomBlocks as $bagianId => $daftar) {
            foreach ($daftar as $j => $blok) {
                foreach ($blok['existing_bukti'] ?? [] as $k => $f) {
                    if ($f['id'] === $berkasId) {
                        unset($dataBaru['subfolder']);
                        array_splice($this->bagianKustomBlocks[$bagianId][$j]['existing_bukti'], $k + 1 - $hapusLama, $hapusLama, [$this->maskBerkas($dataBaru)]);
                    }
                }
            }
        }

        // Bukti kegiatan tertaut RTL ikut tampil di Evaluasi RTL (Bagian 4).
        if ($ref instanceof RtlEvaluasiModel || $ref instanceof Kegiatan) {
            $this->cacheRtlBerjalan = null;
            Cache::forget($this->cacheKeyPeriodeIku('rtl-berjalan'));
        }
        if ($ref instanceof BagianKustomPoin) {
            Cache::forget($this->cacheKeyPeriodeIku("riwayat-bagian-{$ref->bagian_kustom_id}"));
            Cache::forget($this->cacheKeyPeriodeIku("riwayat-bagian-{$ref->bagian_kustom_id}-edit"));
        }

        $this->resetErrorBag("gantiBerkas.{$berkasId}");

        if ($alasanLamaGagal !== null) {
            $this->dispatch('notify', type: 'error', message: "Berkas pengganti \"{$baru->nama_file}\" sudah tersimpan, tetapi berkas lama \"{$lama->nama_file}\" GAGAL dihapus dari Google Drive ({$alasanLamaGagal}) — berkas lama masih ada di SIPINTER dan Google Drive. Hapus berkas lama lagi nanti.");

            return;
        }

        $this->dispatch('notify', type: 'success', message: "Berkas diganti dengan \"{$baru->nama_file}\" — berkas lama dihapus permanen dari SIPINTER".($lama->drive_file_id ? ' dan Google Drive' : '').'. Menunggu diperiksa ulang Tim SAKIP.');
    }

    /** Evaluasi RTL periode ini sudah diverifikasi → boleh ditambah bukti susulan. */
    public function evaluasiBolehBuktiSusulan(): bool
    {
        return in_array($this->statusCapaianSaatIni(), self::STATUS_BOLEH_BUKTI_SUSULAN, true);
    }

    /**
     * Bukti dukung TAMBAHAN untuk kegiatan / poin evaluasi RTL yang SUDAH diverifikasi
     * Tim SAKIP — kunci "kegiatan-<id>" atau "rtl-<id>". Begitu selesai dipilih,
     * langsung disimpan & diunggah ke Drive (updatedBuktiSusulan()), tanpa membuka
     * ulang isian yang sudah terkunci. Berkasnya berstatus "menunggu" supaya Tim
     * SAKIP bisa memeriksanya.
     *
     * @var array<string, mixed>
     */
    public array $buktiSusulan = [];

    public function updatedBuktiSusulan($nilai, ?string $kunci = null): void
    {
        $kunci = explode('.', (string) $kunci)[0];
        unset($this->buktiSusulan[$kunci]);

        [$jenis, $id] = array_pad(explode('-', $kunci, 2), 2, null);
        $id = (int) $id;
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        if (! $periode || ! $id) {
            return;
        }

        $daftarBerkas = array_values(array_filter(is_array($nilai) ? $nilai : [$nilai]));
        $validator = \Illuminate\Support\Facades\Validator::make(
            ['bukti' => $daftarBerkas],
            ['bukti' => ['required', 'array'], 'bukti.*' => ['file', 'mimes:pdf']],
            ['bukti.*.mimes' => 'Bukti dukung harus berupa PDF.']
        );

        if ($validator->fails()) {
            $this->addError("buktiSusulan.{$kunci}", $validator->errors()->first());

            return;
        }

        if ($jenis === 'kegiatan') {
            $index = collect($this->blocks)->search(fn ($b) => (int) ($b['id'] ?? 0) === $id);
            $kegiatan = $index === false ? null : Kegiatan::with(['periode', 'masterIku'])
                ->where('iku_id', $this->iku_id)->where('periode_id', $periode->id)->find($id);

            if (! $kegiatan || ! in_array($kegiatan->status_dokumen, self::STATUS_BOLEH_BUKTI_SUSULAN, true)) {
                return;
            }

            $ref = $kegiatan;
            $kategori = 'capaian';
            $konteks = 'bukti dukung tambahan kegiatan';
        } elseif ($jenis === 'rtl') {
            $ref = $this->evaluasiBolehBuktiSusulan() ? $this->rtlTriwulanBerjalan()->firstWhere('id', $id) : null;

            if (! $ref) {
                return;
            }

            $ref->loadMissing(['periode', 'masterIku']);
            $kategori = 'evaluasi_rtl';
            $konteks = 'bukti dukung tambahan evaluasi RTL';
        } else {
            return;
        }

        $folderService = app(FolderStructureService::class);
        $driveGagal = [];

        foreach ($daftarBerkas as $file) {
            $namaBerkas = $this->namaAsliBuktiBaru($file) ?: 'Bukti dukung tambahan';
            $path = $file->store($kategori === 'capaian' ? 'bukti-capaian' : 'bukti-evaluasi-rtl', 'local');

            $berkas = Berkas::create([
                'ref_id' => $ref->id,
                'ref_type' => $ref::class,
                'kategori' => $kategori,
                'nama_file' => $namaBerkas.'.pdf',
                'periode_id' => $kategori === 'evaluasi_rtl' ? $periode->id : null,
                'path' => $path,
                'status_verifikasi' => 'menunggu',
            ]);

            try {
                $lokal = Storage::disk('local')->path($path);
                $berkas->setRelation('ref', $ref)->unggahKeDrive();
                $this->notifikasiHasilUnggah($file->getClientOriginalName(), $konteks, true);
            } catch (\Throwable $e) {
                Log::warning("Gagal mengunggah {$konteks} ke Google Drive, disimpan lokal saja: ".$e->getMessage());
                $driveGagal[] = $file->getClientOriginalName();
                $this->notifikasiHasilUnggah($file->getClientOriginalName(), $konteks, false, $e->getMessage());
            }

            if ($kategori === 'capaian') {
                $berkas->refresh();
                $this->blocks[$index]['existing_bukti'][] = $this->maskBerkas([
                    'id' => $berkas->id,
                    'kode' => $berkas->kode,
                    'nama_file' => $berkas->nama_file,
                    'subfolder' => $berkas->subfolder,
                    'diunggah_oleh' => $berkas->diunggah_oleh,
                    'di_drive' => filled($berkas->drive_file_id),
                    'status_verifikasi' => $berkas->status_verifikasi,
                    'catatan' => $berkas->catatan,
                ]);
            }
        }

        // Bukti kegiatan tertaut RTL juga tampil di Evaluasi RTL (Bagian 4).
        $this->cacheRtlBerjalan = null;
        Cache::forget($this->cacheKeyPeriodeIku('rtl-berjalan'));

        Cache::forget($this->kunciCacheLinkFolderIku());
        $this->resetErrorBag("buktiSusulan.{$kunci}");

        if ($driveGagal === []) {
            $this->dispatch('notify', type: 'success', message: count($daftarBerkas).' bukti dukung tambahan tersimpan — menunggu diperiksa Tim SAKIP.');
        }
    }

    /**
     * Ketua Tim mengganti nama folder Drive kegiatan yang sudah terkunci (dipanggil
     * <x-nama-folder>) — langsung tersimpan & ikut diganti di Drive, sama seperti
     * VerifikasiCapaian::gantiNamaFolderKegiatan(). Kegiatan yang masih bisa diedit
     * memakai ✏️ di form (tersimpan saat Simpan Draft/Ajukan).
     *
     * @return array{ok: bool, nama_folder?: string, pesan?: string}
     */
    public function gantiNamaFolderKegiatan(int $kegiatanId, string $namaBaru): array
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;
        $kegiatan = $periode ? Kegiatan::where('iku_id', $this->iku_id)->where('periode_id', $periode->id)->find($kegiatanId) : null;

        if (! $kegiatan || $this->formTerkunciSedangDitanganiFresh()) {
            return ['ok' => false, 'pesan' => 'Folder kegiatan ini tidak bisa diganti namanya sekarang.'];
        }

        if (mb_strlen(trim($namaBaru)) > 150) {
            return ['ok' => false, 'pesan' => 'Nama folder maksimal 150 karakter.'];
        }

        $hasil = $kegiatan->gantiNamaFolder($namaBaru);

        // gantiNamaFolder() juga menyamakan nama folder baris lain yang berbagi folder
        // Drive yang sama — versinya diperbarui supaya simpanan berikutnya tidak
        // dianggap bentrok dengan perubahan pengguna ini sendiri.
        $this->segarkanVersiKegiatan(Kegiatan::whereKey($kegiatan->id)
            ->when($kegiatan->drive_folder_id, fn ($q) => $q->orWhere('drive_folder_id', $kegiatan->drive_folder_id))
            ->get());

        foreach ($this->blocks as $i => $block) {
            if ((int) ($block['id'] ?? 0) === $kegiatan->id) {
                $this->blocks[$i]['nama_folder'] = (string) $kegiatan->fresh()->nama_folder_kustom;
            }
        }

        $this->dispatch('notify', type: $hasil['peringatan'] ? 'warning' : 'success', message: $hasil['peringatan'] ?: "Nama folder diubah menjadi \"{$hasil['nama_folder']}\".");

        return ['ok' => true, 'nama_folder' => $hasil['nama_folder']];
    }

    protected function kunciCacheLinkFolderIku(): string
    {
        return 'pengisian-kegiatan.link-folder-iku.'.$this->iku_id.'.'.$this->tahun.'.'.$this->triwulanDari($this->bulan);
    }

    /**
     * Tautan folder Google Drive IKU ini (periode berjalan) — hanya bila foldernya
     * memang sudah terbentuk, yaitu sudah ada berkas IKU ini pada triwulan ini yang
     * masuk Drive. Di-cache supaya Drive tidak ditanya di setiap render.
     */
    public function linkFolderIku(): ?string
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        if (! $periode) {
            return null;
        }

        $hasil = Cache::remember($this->kunciCacheLinkFolderIku(), now()->addMinutes(30), function () use ($periode) {
            $sudahDiDrive = Berkas::whereNotNull('drive_file_id')
                ->whereHasMorph('ref', [Kegiatan::class, RtlEvaluasiModel::class, BagianKustomPoin::class], fn ($q) => $q
                    ->where('iku_id', $this->iku_id)
                    ->whereHas('periode', fn ($p) => $p->where('tahun', $periode->tahun)->where('triwulan', $periode->triwulan)))
                ->exists();

            if (! $sudahDiDrive) {
                return ['link' => null];
            }

            try {
                return ['link' => app(FolderStructureService::class)->linkBuktiDukungIku($periode, MasterIku::findOrFail($this->iku_id))];
            } catch (\Throwable $e) {
                Log::warning('Gagal mengambil tautan folder IKU: '.$e->getMessage());

                return ['link' => null];
            }
        });

        return $hasil['link'] ?? null;
    }

    public function addKendalaBlock(): void
    {
        if ($this->formTerkunciSedangDitangani()) {
            return;
        }

        $this->kendalaBlocks[] = $this->emptyKendalaBlock();
    }

    /**
     * Hanya blok yang belum tersimpan (id === null) yang boleh dihapus dari sini —
     * sama seperti removeBlock()/removeRtlBlock(): pasangan yang sudah punya baris
     * KendalaSolusi di DB (draft sendiri ATAU ditolak yang sedang diperbaiki) tidak
     * bisa dihapus lewat tombol ini, supaya tidak diam-diam tertinggal yatim di DB
     * (tidak ter-update maupun terhapus saat form disimpan, lihat
     * simpanBagianIsian()) — cukup dikosongkan teksnya bila memang ingin dibatalkan.
     */
    public function removeKendalaBlock(int $index): void
    {
        if (($this->kendalaBlocks[$index]['id'] ?? null) !== null) {
            return;
        }

        unset($this->kendalaBlocks[$index]);
        $this->kendalaBlocks = array_values($this->kendalaBlocks);
    }

    public function addBagianKustomBlock(int $bagianId): void
    {
        $bagianHak = $this->bagianKustomAktif()->firstWhere('id', $bagianId);

        if ($bagianHak && ! $bagianHak->bolehAksi(BagianKustom::PERAN_KETUA_TIM, 'buat')) {
            JejakAktivitasBagianKustom::ditolak($bagianHak, 'peran pengguna tidak punya hak "'.BagianKustom::AKSI['buat'].'" di bagian ini', ['menu' => MenuBagianKustom::PENGISIAN]);
            abort(403);
        }

        if ($this->formTerkunciSedangDitangani()) {
            return;
        }

        $this->bagianKustomBlocks[$bagianId][] = $this->emptyBagianKustomBlock();
    }

    /**
     * Periode tujuan untuk relasi antarperiode. Baris periode baru dibuat saat simpan,
     * jadi bila belum ada dipakai objek periode sementara (tanpa id, tidak disimpan).
     */
    protected function periodeTujuanLanjutan(): Periode
    {
        return $this->periodeSaatIni() ?? (new Periode)->forceFill(['tahun' => $this->tahun, 'bulan' => $this->bulan]);
    }

    /**
     * Poin periode sebelumnya (tahun yang sama, IKU ini) yang bisa dilanjutkan ke
     * periode ini, per bagian — id poin => true. Dipakai tombol "Lanjutkan" di riwayat.
     *
     * @return array<int, array<int, bool>>
     */
    protected function bisaDilanjutkanBagianKustom(): array
    {
        if (! $this->iku_id || $this->capaianTerkunci()) {
            return [];
        }

        $periode = $this->periodeTujuanLanjutan();
        $dipakai = collect($this->bagianKustomBlocks)->flatten(1)->pluck('lanjutan_dari_id')->filter()->map(fn ($id) => (int) $id)->all();
        $hasil = [];

        foreach ($this->bagianKustomAktif() as $bagian) {
            $hasil[$bagian->id] = LanjutanBagianKustom::objekTerbuka($bagian, MenuBagianKustom::PENGISIAN, BagianKustom::PERAN_KETUA_TIM, $periode, (int) $this->iku_id)
                ->reject(fn ($p) => in_array($p->id, $dipakai, true))
                ->mapWithKeys(fn ($p) => [$p->id => true])
                ->all();
        }

        return $hasil;
    }

    /**
     * Isi poin BARU periode ini dengan isi poin periode sebelumnya (relasi antarperiode).
     * Belum tersimpan sampai Simpan Draf/Ajukan; asal diperiksa ulang saat simpan.
     */
    public function lanjutkanPoinBagianKustom(int $bagianId, int $poinId): void
    {
        if (! $this->penggunaKetuaTim() || $this->formTerkunciSedangDitangani() || $this->capaianTerkunci() || ! $this->iku_id) {
            return;
        }

        $bagian = $this->bagianKustomAktif()->firstWhere('id', $bagianId);
        abort_unless($bagian, 403);

        if (collect($this->bagianKustomBlocks[$bagianId] ?? [])->contains(fn ($b) => (int) ($b['lanjutan_dari_id'] ?? 0) === $poinId)) {
            return;
        }

        $sumber = LanjutanBagianKustom::sumberSah($bagian, $poinId, MenuBagianKustom::PENGISIAN, BagianKustom::PERAN_KETUA_TIM, $this->periodeTujuanLanjutan(), (int) $this->iku_id);

        if (! $sumber) {
            $this->addError("bagianKustomBlocks.{$bagianId}", 'Poin tersebut tidak bisa dilanjutkan (sudah dilanjutkan ke periode ini atau bukan milik IKU ini).');

            return;
        }

        $blok = ['id' => null, 'teks' => (string) $sumber->teks, 'kolom' => $bagian->nilaiKolomUntukForm($sumber->nilai_kolom), 'lanjutan_dari_id' => $sumber->id] + $this->emptyBagianKustomBlock();

        // Satu blok kosong bawaan diganti, bukan ditambah di bawahnya.
        $daftar = collect($this->bagianKustomBlocks[$bagianId] ?? [])->filter(fn ($b) => filled($b['id'] ?? null) || $this->blokBagianKustomTerisi($b) || ! empty($b['bukti']))->values()->all();
        $this->bagianKustomBlocks[$bagianId] = [...$daftar, $blok];
    }

    /**
     * Hanya poin yang belum tersimpan (id === null) yang boleh dihapus dari sini —
     * sama seperti removeBlock() untuk Kegiatan: poin yang sudah tersimpan di DB
     * hanya boleh diedit teksnya, tidak dihapus (lihat muatBagianKustomBlocks()).
     */
    public function removeBagianKustomBlock(int $bagianId, int $index): void
    {
        if (($this->bagianKustomBlocks[$bagianId][$index]['id'] ?? null) !== null) {
            return;
        }

        unset($this->bagianKustomBlocks[$bagianId][$index]);
        $this->bagianKustomBlocks[$bagianId] = array_values($this->bagianKustomBlocks[$bagianId]);
    }

    public function removeBuktiBagianKustom(int $bagianId, int $blockIndex, int $fileIndex): void
    {
        unset($this->bagianKustomBlocks[$bagianId][$blockIndex]['bukti'][$fileIndex]);
        $this->bagianKustomBlocks[$bagianId][$blockIndex]['bukti'] = array_values($this->bagianKustomBlocks[$bagianId][$blockIndex]['bukti']);
    }

    public function addRtlBlock(): void
    {
        if ($this->formTerkunciSedangDitangani()) {
            return;
        }

        $this->rtlBaru[] = $this->emptyRtlBlock();
    }

    public function removeRtlBlock(int $index): void
    {
        if (($this->rtlBaru[$index]['id'] ?? null) !== null) {
            return;
        }

        unset($this->rtlBaru[$index]);
        $this->rtlBaru = array_values($this->rtlBaru);
    }

    /**
     * Muat ulang seluruh isian dari database, sama seperti mount() setelah Simpan
     * Draft me-reload halaman — dipakai simpan otomatis setelah unggah.
     */
    /**
     * Ganti aturan "blocks.*.…" menjadi per indeks, hanya untuk blok yang uraian
     * kegiatannya sudah diisi.
     */
    protected function aturanBlokTerisiSaja(array $aturan): array
    {
        $hasil = [];
        $ganti = fn ($nilai, int $i) => is_string($nilai) ? str_replace('blocks.*.', "blocks.{$i}.", $nilai) : $nilai;

        foreach ($aturan as $kunci => $rule) {
            if (! str_starts_with($kunci, 'blocks.*.')) {
                $hasil[$kunci] = $rule;

                continue;
            }

            foreach ($this->blocks as $i => $block) {
                if (trim($block['uraian_kegiatan'] ?? '') !== '') {
                    $hasil[$ganti($kunci, $i)] = is_array($rule) ? array_map(fn ($r) => $ganti($r, $i), $rule) : $ganti($rule, $i);
                }
            }
        }

        return $hasil;
    }

    /**
     * Isian di layar yang TIDAK ikut tersimpan oleh Simpan Draft, dicatat SEBELUM
     * disimpan (simpanBagianIsian() mengosongkan tahapan tambahan) supaya dipasang
     * lagi oleh muatUlangForm():
     *  - susunan subfolder yang belum berisi berkas (per kegiatan+tahapan),
     *  - blok kegiatan/poin bagian kustom yang uraiannya masih kosong (mis. berkas
     *    yang menunggu uraian diisi, lihat updatedUnggahFolder()).
     *
     * @return array{folder: array<string, array{0: bool, 1: list<string>}>, blocks: list<array>, bagian: array<int, list<array>>}
     */
    protected function catatIsianBelumTersimpan(): array
    {
        $susunan = [];
        foreach ($this->blocks as $block) {
            foreach ($this->tahapanDipilih($block) ?: [null] as $tahapan) {
                $susunan[$this->kunciSusunanSimpan($block['uraian_kegiatan'] ?? '', $tahapan)] = [
                    $this->pakaiSubfolder($block, $tahapan),
                    $this->daftarSubfolder($block, $tahapan),
                ];
            }
        }

        $kosong = $this->emptyBlock();
        $kosongBagian = $this->emptyBagianKustomBlock();

        return [
            'folder' => $susunan,
            // Susunan folder Evaluasi RTL (tahapan/subfolder yang belum berisi berkas).
            'rtl' => array_map(fn ($data) => Arr::except($data, ['bukti']), $this->evaluasi),
            'blocks' => array_values(array_filter(
                $this->blocks,
                fn ($b) => trim($b['uraian_kegiatan'] ?? '') === '' && $b != $kosong
            )),
            'bagian' => array_map(fn ($daftar) => array_values(array_filter(
                $daftar,
                fn ($b) => trim($b['teks'] ?? '') === '' && $b != $kosongBagian
            )), $this->bagianKustomBlocks),
        ];
    }

    /**
     * @param  array{folder?: array, blocks?: list<array>, bagian?: array<int, list<array>>}  $belumTersimpan  hasil catatIsianBelumTersimpan().
     */
    protected function muatUlangForm(array $belumTersimpan = []): void
    {
        $susunanFolder = $belumTersimpan['folder'] ?? [];
        // Cache per-request di bawah masih berisi keadaan SEBELUM disimpan (mis.
        // periode yang baru saja dibuat simpanDraft() masih tercatat null).
        $this->cacheRtlBerjalan = $this->cacheRtlBerikutnyaDitolak = $this->cacheRtlBerikutnyaAktif = $this->cacheKendalaAktif = null;
        $this->cacheRtlBerikutnyaSudahAda = $this->cacheFormLengkap = null;
        $this->cacheKekuranganPengajuan = null;
        $this->cachePeriodeSaatIni = null;
        $this->cachePeriodeSaatIniDihitung = false;

        $this->blocks = [$this->emptyBlock()];
        $this->kendalaBlocks = [$this->emptyKendalaBlock()];
        $this->rtlBaru = [$this->emptyRtlBlock()];
        $this->bagianKustomBlocks = [];
        foreach ($this->bagianKustomAktif() as $bagian) {
            $this->bagianKustomBlocks[$bagian->id] = [$this->emptyBagianKustomBlock()];
        }
        $this->muatFormEvaluasi();
        $this->muatBlocksKegiatan();
        $this->muatKendalaBlocks();
        $this->muatBagianKustomBlocks();
        $this->pilihPicOtomatis();
        $this->muatRtlBaruBlocks();

        foreach ($this->blocks as $i => $block) {
            $tahapan = filled($block['tahapan_survei'] ?? '') ? $block['tahapan_survei'] : null;
            [$pakai, $daftar] = $susunanFolder[$this->kunciSusunanSimpan($block['uraian_kegiatan'] ?? '', $tahapan)] ?? [false, []];

            if ($daftar !== []) {
                $this->simpanSusunanFolder($i, $tahapan, $pakai || $this->pakaiSubfolder($block, $tahapan), array_values(array_unique([...$daftar, ...$this->daftarSubfolder($block, $tahapan)])));
            }
        }

        // Gabungkan susunan Evaluasi RTL yang tersimpan (dari berkas) dengan susunan di
        // layar yang belum berisi berkas, supaya tahapan/subfolder kosong tidak hilang.
        foreach ($belumTersimpan['rtl'] ?? [] as $rtlId => $lama) {
            if (! isset($this->evaluasi[$rtlId])) {
                continue;
            }

            $data = $this->evaluasi[$rtlId];
            $data['pisah_tahapan'] = ! empty($data['pisah_tahapan']) || ! empty($lama['pisah_tahapan']);
            $data['tahapan'] = array_values(array_unique([...($lama['tahapan'] ?? []), ...$data['tahapan']]));
            $data['pakai_subfolder'] = $data['pakai_subfolder'] || ! empty($lama['pakai_subfolder']);
            $data['subfolders'] = array_values(array_unique([...($lama['subfolders'] ?? []), ...$data['subfolders']]));

            foreach ($data['tahapan'] as $t) {
                $data['pakai_subfolder_tahapan'][$t] = ! empty($data['pakai_subfolder_tahapan'][$t]) || ! empty($lama['pakai_subfolder_tahapan'][$t]);
                $data['subfolders_tahapan'][$t] = array_values(array_unique([...($lama['subfolders_tahapan'][$t] ?? []), ...($data['subfolders_tahapan'][$t] ?? [])]));
            }

            $this->evaluasi[$rtlId] = $data;
        }

        if ($belumTersimpan['blocks'] ?? []) {
            $this->blocks = [...$this->tanpaBlokKosong($this->blocks, $this->emptyBlock()), ...$belumTersimpan['blocks']];
        }
        foreach ($belumTersimpan['bagian'] ?? [] as $bagianId => $daftar) {
            if ($daftar !== [] && isset($this->bagianKustomBlocks[$bagianId])) {
                $this->bagianKustomBlocks[$bagianId] = [...$this->tanpaBlokKosong($this->bagianKustomBlocks[$bagianId], $this->emptyBagianKustomBlock()), ...$daftar];
            }
        }

        // Masih ada isian yang belum tersimpan (blok yang dipasang lagi di atas).
        $this->adaPerubahan = ($belumTersimpan['blocks'] ?? []) !== [] || array_filter($belumTersimpan['bagian'] ?? []) !== [];
    }

    /** Buang blok kosong bawaan (placeholder) sebelum blok yang belum tersimpan dipasang lagi. */
    protected function tanpaBlokKosong(array $daftar, array $kosong): array
    {
        return array_values(array_filter($daftar, fn ($b) => $b != $kosong));
    }

    protected function kunciSusunanSimpan(string $uraian, ?string $tahapan): string
    {
        return mb_strtolower(trim($uraian)).'|'.($tahapan ?? '');
    }

    public function updatedIkuId(): void
    {
        $this->resetStatusPerubahan();
        $this->muatFormEvaluasi();
        $this->muatBlocksKegiatan();
        $this->muatKendalaBlocks();
        $this->muatBagianKustomBlocks();
        $this->pilihPicOtomatis();
        $this->muatRtlBaruBlocks();
    }

    public function updatedBulan(): void
    {
        $this->resetStatusPerubahan();
        $this->muatFormEvaluasi();
        $this->muatBlocksKegiatan();
        $this->muatKendalaBlocks();
        $this->muatBagianKustomBlocks();
        $this->muatRtlBaruBlocks();
    }

    public function updatedTahun(): void
    {
        $this->resetStatusPerubahan();
        $this->muatFormEvaluasi();
        $this->muatBlocksKegiatan();
        $this->muatKendalaBlocks();
        $this->muatBagianKustomBlocks();
        $this->muatRtlBaruBlocks();
    }

    protected function triwulanDari(int $bulan): int
    {
        return (int) ceil($bulan / 3);
    }

    protected function bulanKeDari(int $bulan): int
    {
        return $bulan - ($this->triwulanDari($bulan) - 1) * 3;
    }

    protected function isBulanTerlewat(): bool
    {
        return ! ($this->tahun === (int) now()->year && $this->bulan === (int) now()->month);
    }

    /**
     * TTL cache lintas-request (Laravel Cache, BUKAN cache-per-request di atas) untuk
     * data historis/RTL yang tidak berubah selagi Ketua Tim sedang mengisi form ini
     * (evaluasi realisasi & kegiatan baru baru benar-benar tersimpan ke DB saat
     * "Ajukan"/"Simpan Draft" ditekan — lihat lupakanCachePeriodeIku()). Tanpa ini,
     * SETIAP klik (termasuk "Tambah Kegiatan" yang sendirinya tidak butuh query sama
     * sekali) tetap membayar ~6 query ke DB remote (Seoul, ~400-500ms/query) karena
     * Livewire me-render ULANG seluruh komponen di tiap aksi.
     */
    protected const CACHE_TTL_DETIK = 60;

    protected function cacheKeyPeriodeIku(string $bagian): string
    {
        return "pengisian-kegiatan.{$bagian}.{$this->iku_id}.{$this->tahun}.{$this->bulan}";
    }

    /**
     * Dipanggil setelah data IKU+periode ini benar-benar berubah di DB (submit
     * berhasil) supaya request berikutnya tidak melihat cache basi.
     */
    protected function lupakanCachePeriodeIku(): void
    {
        foreach (['riwayat', 'kendala-aktif', 'rtl-berjalan', 'rtl-berikutnya-ada', 'rtl-berikutnya-ada-edit', 'rtl-berikutnya-aktif', 'rtl-berikutnya-aktif-edit', 'rtl-berjalan-terpakai', 'capaian-status', 'catatan-penolakan', 'pengembalian-terakhir', 'periode'] as $bagian) {
            Cache::forget($this->cacheKeyPeriodeIku($bagian));
        }
        Cache::forget($this->kunciCacheLinkFolderIku());
        $this->cacheRtlBerjalan = null;

        foreach ($this->bagianKustomAktif() as $bagian) {
            Cache::forget($this->cacheKeyPeriodeIku("riwayat-bagian-{$bagian->id}"));
            Cache::forget($this->cacheKeyPeriodeIku("riwayat-bagian-{$bagian->id}-edit"));
        }
    }

    /**
     * Riwayat kendala-solusi triwulan berjalan: seluruh pasangan milik IKU terpilih
     * pada triwulan yang sedang berjalan saja (tidak kumulatif dari TW I) —
     * KECUALI pasangan periode INI SENDIRI yang belum diterima Tim SAKIP (menunggu
     * baru diajukan, atau ditolak perlu diperbaiki), supaya tidak tampil dobel:
     * yang belum diterima ditampilkan sebagai blok yang BISA diedit lewat
     * kendalaBlocks (lihat muatKendalaBlocks()), bukan di sini. Pasangan periode ini
     * yang SUDAH diterima (terverifikasi) tetap tampil di sini seperti biasa —
     * itulah cara pasangan yang sudah diterima terlihat terkunci/hanya-baca.
     *
     * @return Collection<int, Collection<int, KendalaSolusiModel>>
     */
    protected function riwayatKendalaSolusi()
    {
        if (! $this->iku_id) {
            return collect();
        }

        return Cache::remember($this->cacheKeyPeriodeIku('riwayat'), self::CACHE_TTL_DETIK, function () {
            $triwulanSekarang = $this->triwulanDari($this->bulan);
            $periodeSaatIni = $this->periodeSaatIni();

            // Pasangan periode INI SENDIRI yang sudah "terverifikasi" biasanya boleh ikut
            // tampil di sini (sudah pindah dari kendalaBlocks yang editable ke riwayat
            // hanya-baca). TAPI hanya bila verifikasiTerlihat() — kalau Tim SAKIP baru
            // menandainya di tengah pemeriksaan (Capaian masih "diajukan"/"sedang
            // ditangani"), pasangan itu TETAP dianggap belum final dan harus tetap
            // muncul di kendalaBlocks (lihat muatKendalaBlocks()), bukan di sini —
            // supaya tidak bocor sebagai "sudah diterima" sebelum benar-benar final.
            $verifikasiTerlihat = $this->verifikasiTerlihat();

            return KendalaSolusiModel::with('periode')
                ->where('iku_id', $this->iku_id)
                // Hanya triwulan berjalan (bulan-bulan lain di triwulan yang sama), BUKAN
                // kumulatif dari TW I — kendala & solusi tiap triwulan berdiri sendiri.
                ->whereHas('periode', function ($q) use ($triwulanSekarang) {
                    $q->where('tahun', $this->tahun)->where('triwulan', $triwulanSekarang);
                })
                ->when($periodeSaatIni, function ($q) use ($periodeSaatIni, $verifikasiTerlihat) {
                    if ($verifikasiTerlihat) {
                        $q->where(fn ($q2) => $q2->where('periode_id', '!=', $periodeSaatIni->id)
                            ->orWhere('status_verifikasi', 'terverifikasi'));
                    } else {
                        $q->where('periode_id', '!=', $periodeSaatIni->id);
                    }
                })
                ->get()
                ->sortBy(fn ($item) => $item->periode->triwulan)
                ->groupBy(fn ($item) => $item->periode->triwulan);
        });
    }

    /**
     * Riwayat kumulatif satu bagian kustom (mis. Manajemen Risiko), pola identik dengan
     * riwayatKendalaSolusi() — bagian_kustom_poin juga tidak punya status/lifecycle
     * sendiri (poin baru ditambahkan tiap submit, bukan diedit), jadi "memuat isian
     * lama" untuk bagian ini berarti menampilkan riwayatnya, bukan edit-in-place.
     *
     * @return Collection<int, Collection<int, BagianKustomPoin>>
     */
    protected function riwayatBagianKustom(BagianKustom $bagian)
    {
        if (! $this->iku_id) {
            return collect();
        }

        $daftar = Cache::remember($this->cacheKeyPeriodeIku("riwayat-bagian-{$bagian->id}".$this->akhiranModeEdit()), self::CACHE_TTL_DETIK, function () use ($bagian) {
            $triwulanSekarang = $this->triwulanDari($this->bulan);
            $periodeSaatIni = $this->periodeSaatIni();
            $terkunci = $this->capaianTerkunci();

            return BagianKustomPoin::with(['periode', 'berkas'])
                ->konteksIsianKegiatan()
                ->where('iku_id', $this->iku_id)
                ->where('bagian_kustom_id', $bagian->id)
                ->whereHas('periode', function ($q) use ($triwulanSekarang) {
                    $q->where('tahun', $this->tahun)->where('triwulan', '<=', $triwulanSekarang);
                })
                // Poin periode INI SENDIRI yang belum terkunci ditampilkan sebagai blok
                // EDITABLE lewat bagianKustomBlocks (lihat muatBagianKustomBlocks()),
                // bukan di sini — supaya tidak tampil dobel (sekali hanya-baca, sekali
                // bisa disunting). Pola sama seperti riwayatKendalaSolusi() di atas.
                ->when($periodeSaatIni && ! $terkunci, function ($q) use ($periodeSaatIni) {
                    $q->where('periode_id', '!=', $periodeSaatIni->id);
                })
                ->get()
                ->sortBy(fn ($item) => $item->periode->triwulan)
                ->groupBy(fn ($item) => $item->periode->triwulan);
        });

        // Kasus di atas ($terkunci tapi status masih "diajukan"/"sedang_ditangani",
        // BELUM verifikasiTerlihat()) tetap menyertakan poin periode ini di riwayat —
        // masking di sini (SETELAH Cache::remember, bukan di dalamnya, supaya nilai
        // asli tetap yang disimpan ke cache) mencegah status_verifikasi/catatan
        // Tim SAKIP yang masih berjalan ikut bocor lewat jalur riwayat ini.
        if (! $this->verifikasiTerlihat() && ($periodeSaatIni = $this->periodeSaatIni())) {
            foreach ($daftar->flatten() as $poin) {
                if ($poin->periode_id !== $periodeSaatIni->id) {
                    continue;
                }

                foreach ($poin->berkas as $berkas) {
                    $berkas->status_verifikasi = 'menunggu';
                    $berkas->catatan = null;
                }
            }
        }

        return $daftar;
    }

    /**
     * Catatan penolakan Tim SAKIP untuk IKU+periode terpilih, dikumpulkan lintas
     * SEMUA kategori berkas (bukti kegiatan, bukti solusi, bukti evaluasi RTL, bukti
     * bagian kustom) — dipakai untuk banner ringkasan di puncak form saat isian
     * periode ini berstatus dikembalikan. Pola pengumpulan mirip
     * VerifikasiCapaian::berkasList(), tapi di-scope ke iku_id+periode (bukan
     * capaian_id) karena komponen ini belum tentu sedang menampilkan satu Capaian.
     *
     * @return Collection<int, string>
     */
    protected function catatanPenolakan()
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        // Banner ini merangkum ALASAN penolakan Tim SAKIP — baru boleh tampil setelah
        // siklus pemeriksaannya benar-benar final (lihat verifikasiTerlihat()), supaya
        // tidak menampilkan alasan yang masih bisa berubah selagi Tim SAKIP masih
        // memeriksa (belum tentu jadi "Kembalikan ke Ketua Tim" beneran).
        if (! $periode || ! $this->verifikasiTerlihat()) {
            return collect();
        }

        // Kegiatan yang dihitung di sini SELALU baris yang sudah tersimpan (id
        // terisi) — blok kosong yang baru ditambah lewat addBlock() punya id null
        // dan sudah dibuang lewat filter(), jadi cache di bawah tetap benar walau
        // $this->blocks berubah bentuk tanpa iku/periode ikut berubah.
        //
        // Di-cache seperti data lain di cacheKeyPeriodeIku() — sebelumnya method ini
        // TIDAK di-cache sama sekali padahal dipanggil di SETIAP render() (yaitu di
        // setiap aksi Livewire, termasuk yang sepele seperti addBlock()), jadi tiap
        // klik membayar ~5 query ke DB remote hanya untuk banner catatan penolakan
        // yang isinya nyaris selalu sama dalam rentang TTL yang sama.
        return Cache::remember($this->cacheKeyPeriodeIku('catatan-penolakan'), self::CACHE_TTL_DETIK, function () use ($periode) {
            $kegiatanIds = collect($this->blocks)->pluck('id')->filter();
            $kendalaIds = KendalaSolusiModel::where('iku_id', $this->iku_id)->where('periode_id', $periode->id)->pluck('id');
            $bagianKustomIds = BagianKustomPoin::konteksIsianKegiatan()->where('iku_id', $this->iku_id)->where('periode_id', $periode->id)->pluck('id');
            $rtlIds = $this->rtlTriwulanBerjalan()->pluck('id');

            $catatanBerkas = Berkas::where('status_verifikasi', 'ditolak')
                ->where(function ($q) use ($kegiatanIds, $kendalaIds, $bagianKustomIds, $rtlIds) {
                    $q->where(fn ($q2) => $q2->where('ref_type', Kegiatan::class)->whereIn('ref_id', $kegiatanIds))
                        ->orWhere(fn ($q2) => $q2->where('ref_type', KendalaSolusiModel::class)->whereIn('ref_id', $kendalaIds))
                        ->orWhere(fn ($q2) => $q2->where('ref_type', BagianKustomPoin::class)->whereIn('ref_id', $bagianKustomIds))
                        ->orWhere(fn ($q2) => $q2->where('ref_type', RtlEvaluasiModel::class)->whereIn('ref_id', $rtlIds));
                })
                ->pluck('catatan');

            // Kendala & Solusi tidak lagi punya bukti dukung sendiri (RF-27 dicabut) —
            // penolakannya langsung disimpan di kolom catatan miliknya sendiri (lihat
            // App\Livewire\VerifikasiCapaian::tandaiKendalaTolak()), bukan lewat Berkas.
            $catatanKendala = KendalaSolusiModel::whereIn('id', $kendalaIds)
                ->where('status_verifikasi', 'ditolak')
                ->pluck('catatan');

            return $catatanBerkas->concat($catatanKendala)
                ->filter()
                ->unique()
                ->values();
        });
    }

    /**
     * Baris riwayat status "dikembalikan" TERAKHIR milik Capaian (IKU+periode) ini —
     * dipakai banner di puncak form untuk memberitahu Ketua Tim SIAPA yang
     * mengembalikan (Kepala lewat NotulaService::kembalikanIsian(), ATAU Tim SAKIP
     * lewat VerifikasiCapaian::kembalikanKeKetuaTim()/bukaKembali()) beserta
     * catatannya — sebelumnya banner ini SELALU berbunyi "oleh Tim SAKIP" tanpa
     * pernah mengecek siapa penggunanya, jadi salah atribusi setiap kali Kepala yang
     * mengembalikan langsung dari halaman Persetujuan Notula (RF-44), dan catatan
     * Kepala (kolom `catatan` pada riwayat_status_capaian) tidak pernah ditampilkan
     * sama sekali karena catatanPenolakan() di atas hanya mengumpulkan catatan
     * per-berkas/kendala, bukan catatan level-Capaian ini.
     *
     * Sama seperti catatanPenolakan(), baru boleh terlihat setelah verifikasiTerlihat()
     * supaya tidak membocorkan hasil pemeriksaan yang masih berjalan.
     */
    protected function pengembalianTerakhir(): ?RiwayatStatusCapaian
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        if (! $periode || ! $this->verifikasiTerlihat()) {
            return null;
        }

        return Cache::remember($this->cacheKeyPeriodeIku('pengembalian-terakhir'), self::CACHE_TTL_DETIK, function () use ($periode) {
            $capaianId = Capaian::where('iku_id', $this->iku_id)->where('periode_id', $periode->id)->value('id');

            if (! $capaianId) {
                return null;
            }

            return RiwayatStatusCapaian::where('capaian_id', $capaianId)
                ->where('status', Capaian::STATUS_DIKEMBALIKAN)
                ->with('user')
                ->latest('id')
                ->first();
        });
    }

    /**
     * Siapkan form bukti realisasi untuk SEMUA poin RTL triwulan ini (bukan hanya yang
     * belum ada buktinya) — poin yang sudah punya bukti tetap boleh ditambahkan lagi,
     * tidak dikunci hanya-baca seperti alur lama berbasis teks realisasi.
     *
     * RTL yang dievaluasi di sini adalah RTL YANG SAMA dengan sumber dropdown uraian
     * kegiatan (rtlTriwulanBerjalan()) — yaitu poin yang DITETAPKAN pada triwulan
     * sebelumnya untuk DILAKSANAKAN pada triwulan berjalan ini. Sebelumnya bagian ini
     * keliru mengambil dari periode triwulan-1 (satu triwulan terlalu jauh ke belakang,
     * poin yang seharusnya sudah dievaluasi saat mengisi triwulan itu sendiri).
     */
    protected function muatFormEvaluasi(): void
    {
        $this->evaluasi = [];

        // Susunan folder dibaca ulang dari bukti yang sudah tersimpan (tahapan &
        // subfolder tiap berkas), sama seperti muatBlocksKegiatan() — tidak ada kolom
        // susunan tersendiri, jadi tidak pernah berbeda dari isi Drive-nya.
        foreach ($this->rtlTriwulanBerjalan() as $poin) {
            $data = $this->evaluasiKosong();
            $tahapan = $poin->berkas->pluck('tahapan')->filter()->unique()->values();

            $data['pisah_tahapan'] = $tahapan->isNotEmpty();
            $data['tahapan'] = $tahapan->all();
            $data['subfolders'] = $poin->berkas->whereNull('tahapan')->pluck('subfolder')->filter()->unique()->values()->all();
            $data['pakai_subfolder'] = $data['subfolders'] !== [];

            foreach ($tahapan as $t) {
                $sub = $poin->berkas->where('tahapan', $t)->pluck('subfolder')->filter()->unique()->values()->all();
                $data['subfolders_tahapan'][$t] = $sub;
                $data['pakai_subfolder_tahapan'][$t] = $sub !== [];
            }

            $this->evaluasi[$poin->id] = $data;
        }
    }

    /**
     * Periode tahun+bulan yang sedang dipilih di form — lookup saja (BEDA dari
     * firstOrCreate di simpanDraft()/ajukanIsian()), supaya membuka form untuk
     * melihat/memuat data tidak ikut membuat baris periode baru yang belum tentu
     * jadi dipakai.
     *
     * Dipanggil berkali-kali dalam satu request yang sama (statusCapaianSaatIni,
     * muatBlocksKegiatan, muatKendalaBlocks, catatanPenolakan, riwayatKendalaSolusi,
     * dst.) — di-cache per-request seperti ikuTerpilih() supaya tiap pemanggilan
     * tidak membayar query DB remote sendiri-sendiri (sebelumnya bisa 6-7 query
     * identik untuk satu aksi "pilih IKU" saja, bikin form terasa sangat lambat
     * memuat isian yang sudah ada).
     */
    protected function periodeSaatIni(): ?Periode
    {
        if ($this->cachePeriodeSaatIniDihitung) {
            return $this->cachePeriodeSaatIni;
        }

        $this->cachePeriodeSaatIniDihitung = true;

        if (! $this->iku_id) {
            return $this->cachePeriodeSaatIni = Periode::where('tahun', $this->tahun)->where('bulan', $this->bulan)->first();
        }

        // Cache::remember lintas-request (bukan cuma memoisasi di atas) — periode
        // hampir tidak pernah berubah selama Ketua Tim mengisi form, tapi sebelumnya
        // di-query ULANG di SETIAP request Livewire (tiap klik/blur), jadi ini yang
        // bikin form terasa sangat lambat memuat isian yang sudah ada.
        //
        // Dibungkus array — Cache::remember() TIDAK BISA membedakan "belum pernah
        // di-cache" dari "sudah di-cache tapi nilainya null" (keduanya baca sebagai
        // null dari store), jadi kalau periode belum ada (nilai asli null) closure-nya
        // akan diulang TIAP request, cache-nya tidak pernah benar-benar kepakai.
        // Membungkusnya dalam array membuat nilai yang disimpan selalu non-null.
        return $this->cachePeriodeSaatIni = Cache::remember(
            $this->cacheKeyPeriodeIku('periode'),
            self::CACHE_TTL_DETIK,
            fn () => ['periode' => Periode::where('tahun', $this->tahun)->where('bulan', $this->bulan)->first()]
        )['periode'];
    }

    /**
     * Status Capaian (satu status per IKU+bulan, lihat App\Models\Capaian) milik
     * IKU+periode yang sedang dipilih di form ini — null bila belum pernah ada isian
     * sama sekali untuk kombinasi ini. Di-cache 60 detik seperti data lain di
     * cacheKeyPeriodeIku() supaya addBlock()/render() yang dipanggil berkali-kali
     * tidak membayar query DB remote tiap klik (lihat lupakanCachePeriodeIku()).
     */
    protected function statusCapaianSaatIni(): ?string
    {
        if (! $this->iku_id) {
            return null;
        }

        return Cache::remember(
            $this->cacheKeyPeriodeIku('capaian-status'),
            self::CACHE_TTL_DETIK,
            function () {
                $periode = $this->periodeSaatIni();

                if (! $periode) {
                    return null;
                }

                return Capaian::where('iku_id', $this->iku_id)->where('periode_id', $periode->id)->value('status');
            }
        );
    }

    /**
     * Tim SAKIP menandai berkas/kendala Sesuai/Tidak Sesuai satu per satu SELAGI
     * masih memeriksa (lihat App\Livewire\VerifikasiCapaian::tandaiSesuai() dkk.) —
     * itu langsung mengubah status_verifikasi & catatan di DB pada saat itu juga,
     * SEBELUM Tim SAKIP menekan "Verifikasi Selesai"/"Kembalikan ke Ketua Tim".
     * Tanpa gerbang ini, Ketua Tim yang membuka halaman ini di tengah proses
     * pemeriksaan akan melihat tanda "Tidak Sesuai"+catatan yang masih berubah-ubah
     * (bisa saja belum final, Tim SAKIP masih bisa menandainya ulang) — membingungkan
     * karena badge status besar Capaian sendiri masih "Diajukan"/belum dikembalikan.
     *
     * Hasil pemeriksaan Tim SAKIP baru boleh terlihat Ketua Tim setelah SATU siklus
     * pemeriksaan benar-benar selesai (allow-list eksplisit, bukan daftar
     * kecuali — status baru di masa depan default TERSEMBUNYI sampai ditambahkan
     * sengaja ke sini): "dikembalikan" (perlu diperbaiki), "diverifikasi"/"disetujui"
     * (diterima). Dipakai bersama maskBerkas() di seluruh method muat*()/riwayat*()
     * di bawah supaya satu aturan ini konsisten di semua tempat status_verifikasi/
     * catatan ditampilkan ke Ketua Tim.
     */
    protected function verifikasiTerlihat(): bool
    {
        return in_array($this->statusCapaianSaatIni(), [
            Capaian::STATUS_DIKEMBALIKAN,
            Capaian::STATUS_DIVERIFIKASI,
            Capaian::STATUS_DISETUJUI,
        ], true);
    }

    /**
     * Dipanggil GantiNamaBerkas setelah nama berkas diubah — existing_bukti disimpan
     * sebagai array di properti publik (bukan query ulang tiap render), jadi salinan
     * namanya diperbarui di sini supaya pratinjau & daftar langsung ikut nama baru.
     */
    protected function setelahNamaBerkasDiubah(int $berkasId, string $namaFile): void
    {
        // Bukti kegiatan tertaut RTL juga tampil di Evaluasi RTL (Bagian 4).
        $this->cacheRtlBerjalan = null;
        Cache::forget($this->cacheKeyPeriodeIku('rtl-berjalan'));

        foreach ($this->blocks as &$block) {
            foreach ($block['existing_bukti'] ?? [] as $i => $file) {
                if ($file['id'] === $berkasId) {
                    $block['existing_bukti'][$i]['nama_file'] = $namaFile;
                }
            }
        }
        unset($block);

        foreach ($this->bagianKustomBlocks as $bagianId => $daftar) {
            foreach ($daftar as $j => $blok) {
                foreach ($blok['existing_bukti'] ?? [] as $i => $file) {
                    if ($file['id'] === $berkasId) {
                        $this->bagianKustomBlocks[$bagianId][$j]['existing_bukti'][$i]['nama_file'] = $namaFile;
                    }
                }
            }
        }
    }

    /**
     * Sembunyikan status_verifikasi & catatan satu berkas (array bentuk existing_bukti,
     * lihat muatBlocksKegiatan()/muatBagianKustomBlocks()) selagi verifikasiTerlihat()
     * masih false — dipakai supaya seluruh tempat yang menyusun existing_bukti tidak
     * perlu mengulang pengecekan yang sama.
     *
     * @param  array{id: int, kode: ?string, nama_file: string, diunggah_oleh: ?int, status_verifikasi: string, catatan: ?string}  $berkas
     * @return array{id: int, kode: ?string, nama_file: string, diunggah_oleh: ?int, status_verifikasi: string, catatan: ?string}
     */
    protected function maskBerkas(array $berkas): array
    {
        if ($this->verifikasiTerlihat()) {
            return $berkas;
        }

        $berkas['status_verifikasi'] = 'menunggu';
        $berkas['catatan'] = null;

        return $berkas;
    }

    /**
     * Sekali Capaian berstatus "disetujui" (sudah masuk notula final yang
     * ditandatangani Kepala), isian ini dikunci TOTAL — Ketua Tim tidak bisa lagi
     * menambah kegiatan maupun menyimpan draf/mengajukan lewat form ini sama sekali,
     * supaya notula yang sudah final tidak diam-diam "terbuka" lagi. Kalau memang
     * perlu revisi, Tim SAKIP harus membuka kembali secara eksplisit dulu (lihat
     * VerifikasiCapaian::bukaKembali()), yang menariknya ke status "dikembalikan".
     *
     * Dipakai untuk UI (sembunyikan/nonaktifkan tombol) — SELALU dicek ULANG tanpa
     * cache di simpanDraft()/ajukanIsian() sebelum benar-benar menyimpan apa pun
     * (defense in depth), karena cache 60 detik di atas bisa saja basi kalau Tim
     * SAKIP baru saja menyetujui dari halaman lain.
     */
    public function formTerkunciDisetujui(): bool
    {
        return $this->statusCapaianSaatIni() === Capaian::STATUS_DISETUJUI;
    }

    /**
     * Versi TANPA cache dari formTerkunciDisetujui() — satu-satunya yang boleh dipakai
     * sebagai gerbang sebelum benar-benar menyimpan (simpanDraft()/ajukanIsian()).
     */
    protected function formTerkunciDisetujuiFresh(): bool
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        if (! $periode) {
            return false;
        }

        $status = Capaian::where('iku_id', $this->iku_id)->where('periode_id', $periode->id)->value('status');

        return $status === Capaian::STATUS_DISETUJUI;
    }

    /**
     * Selagi Capaian berstatus "sedang ditangani" (Tim SAKIP sudah mulai menandai
     * sebagian bukti/kendala-solusi & "Simpan Sementara", lihat
     * Capaian::STATUS_SEDANG_DITANGANI), isian ini dikunci hanya-baca TOTAL bagi
     * Ketua Tim — tidak bisa menambah kegiatan/kendala/RTL/Bagian Kustom baru maupun
     * menyimpan draf/mengajukan — supaya data yang sedang dipegang & di-cache Tim
     * SAKIP (lihat App\Livewire\VerifikasiCapaian::kegiatanList() dkk., di-cache per
     * request) tidak diam-diam berubah di bawahnya selagi diperiksa. BEDA dari
     * formTerkunciDisetujui(): begitu Tim SAKIP selesai (Verifikasi Selesai/
     * Kembalikan ke Ketua Tim), status berpindah ke diverifikasi/dikembalikan dan
     * form ini otomatis terbuka lagi (dikembalikan) atau tetap terkunci lewat
     * STATUS_KEGIATAN_TERKUNCI per-blok seperti biasa (diverifikasi).
     *
     * Dipakai untuk UI (sembunyikan/nonaktifkan tombol) — SELALU dicek ULANG tanpa
     * cache di simpanDraft()/ajukanIsian() sebelum benar-benar menyimpan apa pun,
     * sama seperti formTerkunciDisetujui() di atas (defense in depth).
     */
    public function formTerkunciSedangDitangani(): bool
    {
        return $this->statusCapaianSaatIni() === Capaian::STATUS_SEDANG_DITANGANI;
    }

    /**
     * Versi TANPA cache dari formTerkunciSedangDitangani() — satu-satunya yang boleh
     * dipakai sebagai gerbang sebelum benar-benar menyimpan (simpanDraft()/ajukanIsian()).
     */
    protected function formTerkunciSedangDitanganiFresh(): bool
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        if (! $periode) {
            return false;
        }

        $status = Capaian::where('iku_id', $this->iku_id)->where('periode_id', $periode->id)->value('status');

        return $status === Capaian::STATUS_SEDANG_DITANGANI;
    }

    /**
     * Muat kegiatan (+ berkas) yang sudah ada untuk IKU+periode terpilih ke $this->blocks
     * supaya isian yang pernah dibuat (termasuk yang dikembalikan Tim SAKIP) tampil lagi
     * saat form dibuka ulang — bukan selalu kosong seperti sebelumnya. Kegiatan berstatus
     * 'diajukan'/'diverifikasi'/'disetujui' tetap dimuat tapi ditandai lewat status_dokumen
     * supaya blade merendernya hanya-baca (lihat STATUS_KEGIATAN_TERKUNCI).
     */
    protected function muatBlocksKegiatan(): void
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;
        $this->versiDimuat['kegiatan'] = [];
        $this->versiDimuat['kegiatan_lain'] = [];

        if (! $periode) {
            $this->blocks = [$this->emptyBlock()];

            return;
        }

        $kegiatanList = Kegiatan::where('iku_id', $this->iku_id)
            ->where('periode_id', $periode->id)
            ->with('berkas')
            ->orderBy('id')
            ->get();

        foreach ($kegiatanList as $kegiatan) {
            if ($this->blokTerkunci($kegiatan->status_dokumen)) {
                $this->versiDimuat['kegiatan_lain'][] = $kegiatan->id;
            } else {
                $this->versiDimuat['kegiatan'][$kegiatan->id] = self::sidikBaris($kegiatan);
            }
        }

        if ($kegiatanList->isEmpty()) {
            $this->blocks = [$this->emptyBlock()];

            return;
        }

        // Tahapan-tahapan satu kegiatan induk dimuat berdampingan (kelompok urut
        // anggota pertamanya) supaya blade bisa menampilkannya dalam SATU kartu
        // "Kegiatan N" — bukan kartu terpisah per tahapan.
        $kegiatanList = Kegiatan::kelompokInduk($kegiatanList)->flatMap(fn ($kelompok) => $kelompok->values())->values();

        $this->blocks = $kegiatanList->map(function (Kegiatan $kegiatan) {
            $subfolders = $kegiatan->berkas->pluck('subfolder')->filter()->unique()->values()->all();
            $tahapan = $kegiatan->jenis === 'survei_sensus' ? $kegiatan->tahapan_survei : null;

            return [
                'id' => $kegiatan->id,
                'status_dokumen' => $kegiatan->status_dokumen,
                'rtl_evaluasi_id' => $kegiatan->rtl_evaluasi_id,
                'uraian_kegiatan' => $kegiatan->uraian_kegiatan,
                'jenis' => $kegiatan->jenis,
                'tahapan_survei' => $kegiatan->tahapan_survei ?? '',
                'tahapan_tambahan' => [],
                'nama_folder' => $kegiatan->nama_folder_kustom ?? '',
                'nama_folder_tahapan' => [],
                'tahapan_kustom' => filled($tahapan) && ! array_key_exists($tahapan, self::DAFTAR_TAHAPAN) ? [$tahapan] : [],
                'pakai_subfolder' => $subfolders !== [],
                'subfolders' => $subfolders,
                'pakai_subfolder_tahapan' => filled($tahapan) ? [$tahapan => $subfolders !== []] : [],
                'subfolders_tahapan' => filled($tahapan) ? [$tahapan => $subfolders] : [],
                'bukti' => [],
                'existing_bukti' => $kegiatan->berkas->map(fn (Berkas $b) => $this->maskBerkas([
                    'id' => $b->id,
                    'kode' => $b->kode,
                    'nama_file' => $b->nama_file,
                    'subfolder' => $b->subfolder,
                    'diunggah_oleh' => $b->diunggah_oleh,
                    'di_drive' => filled($b->drive_file_id) || blank($b->path),
                    'status_verifikasi' => $b->status_verifikasi,
                    'catatan' => $b->catatan,
                ]))->all(),
                'catatan_bukti_dihapus' => $kegiatan->catatan_bukti_dihapus,
            ];
        })->values()->all();
    }

    /**
     * Pasangan kendala &amp; solusi milik periode ini SENDIRI yang BOLEH DIEDIT
     * Ketua Tim — draft yang belum pernah diajukan (status_dokumen=draft), atau
     * yang DITOLAK Tim SAKIP dan perlu diperbaiki. Pasangan yang SUDAH diajukan
     * (status_dokumen=diajukan) dan belum ditolak final TIDAK ikut dimuat ke sini
     * — begitu diajukan, teksnya terkunci hanya-baca sampai Tim SAKIP memutuskan
     * (lihat kendalaAktif(), sama persis pola RTL berikutnya di
     * rtlBerikutnyaAktif()/muatRtlBaruBlocks()), supaya Ketua Tim tidak diam-diam
     * menyunting pasangan yang sedang/sudah diperiksa. Dipanggil sejajar dengan
     * muatBlocksKegiatan() supaya isian lama (draft/ditolak) tetap ada saat form
     * dibuka ulang, bukan selalu kosong.
     *
     * Pasangan yang sudah "terverifikasi" (diterima) TIDAK ikut dimuat ke sini
     * sama sekali (beda dari Kegiatan), cukup tampil di riwayatKendalaSolusi()
     * sebagai riwayat hanya-baca — KECUALI selagi verifikasiTerlihat() masih false
     * (Tim SAKIP belum menyelesaikan SATU siklus pemeriksaan penuh), pasangan yang
     * SUDAH ditandai "terverifikasi" di tengah jalan tetap dianggap "aktif"
     * (terkunci lewat kendalaAktif(), BUKAN dimuat sebagai blok editable di sini)
     * supaya tidak ada pasangan yang tiba-tiba "menghilang" (lalu muncul di
     * riwayatKendalaSolusi() sebagai sudah diterima) sebelum pemeriksaannya
     * benar-benar final.
     */
    protected function muatKendalaBlocks(): void
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;
        $this->versiDimuat['kendala'] = [];

        if (! $periode) {
            $this->kendalaBlocks = [$this->emptyKendalaBlock()];

            return;
        }

        $query = KendalaSolusiModel::where('iku_id', $this->iku_id)->where('periode_id', $periode->id);

        if ($this->verifikasiTerlihat()) {
            $query->where('status_verifikasi', '!=', 'terverifikasi');
        }

        $daftar = $query->orderBy('id')->get();

        // Pasangan yang sudah diajukan (status_dokumen=diajukan) dan belum final
        // ditolak Tim SAKIP dikeluarkan dari sini — tampil terkunci lewat
        // kendalaAktif() di blade, bukan sebagai blok editable.
        // PENGECUALIAN: selagi pengajuan masih bisa disunting (pengajuanBisaDiedit(),
        // Tim SAKIP belum mulai memeriksa), pasangan yang sudah diajukan tetap dimuat
        // sebagai blok editable — kecuali yang sudah diterima pada siklus sebelumnya.
        $editable = $daftar->reject(fn (KendalaSolusiModel $ks) => $ks->status_dokumen === KendalaSolusiModel::STATUS_DIAJUKAN
            && ! $this->verifikasiTerlihat()
            && ! ($this->pengajuanBisaDiedit() && $ks->status_verifikasi !== 'terverifikasi'));

        if ($editable->isEmpty()) {
            // Satu blok kosong bawaan HANYA untuk pengisian pertama kali ($daftar
            // benar-benar kosong) — begitu ada pasangan lain yang sudah diajukan
            // (tampil terkunci lewat kendalaAktif()), biarkan $kendalaBlocks kosong
            // supaya blade cukup menampilkan tombol "+ Tambah Pasangan" saja, tanpa
            // kotak input kosong yang nongol begitu saja di bawahnya.
            $this->kendalaBlocks = $daftar->isEmpty() ? [$this->emptyKendalaBlock()] : [];

            return;
        }

        $this->versiDimuat['kendala'] = $editable->mapWithKeys(fn (KendalaSolusiModel $ks) => [$ks->id => self::sidikBaris($ks)])->all();

        $this->kendalaBlocks = $editable->map(fn (KendalaSolusiModel $ks) => [
            'id' => $ks->id,
            'kendala' => $ks->kendala,
            'solusi' => $ks->solusi ?? '',
            'status_verifikasi' => $this->verifikasiTerlihat() ? $ks->status_verifikasi : 'menunggu',
            'catatan' => $this->verifikasiTerlihat() ? $ks->catatan : null,
        ])->values()->all();
    }

    /**
     * Pasangan kendala &amp; solusi periode ini yang SUDAH diajukan ke Tim SAKIP dan
     * belum diputuskan final — ditampilkan terkunci hanya-baca di blade (badge
     * "Menunggu Verifikasi Tim SAKIP"/"Terverifikasi Tim SAKIP" sesuai status_
     * verifikasi apa adanya), pola identik dengan RtlEvaluasi berikutnya di
     * rtlBerikutnyaAktif(). Selalu kosong begitu verifikasiTerlihat() true — pada
     * titik itu tiap pasangan sudah final: pindah ke riwayatKendalaSolusi()
     * (terverifikasi) atau kembali jadi blok editable di muatKendalaBlocks()
     * (ditolak, perlu diperbaiki) — jadi tidak ada lagi yang perlu tampil "aktif"
     * di sini.
     *
     * @return Collection<int, KendalaSolusiModel>
     */
    protected function kendalaAktif()
    {
        if ($this->cacheKendalaAktif !== null) {
            return $this->cacheKendalaAktif;
        }

        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        if (! $periode || $this->verifikasiTerlihat()) {
            return $this->cacheKendalaAktif = collect();
        }

        $aktif = Cache::remember(
            $this->cacheKeyPeriodeIku('kendala-aktif'),
            self::CACHE_TTL_DETIK,
            fn () => KendalaSolusiModel::where('iku_id', $this->iku_id)
                ->where('periode_id', $periode->id)
                ->where('status_dokumen', KendalaSolusiModel::STATUS_DIAJUKAN)
                ->orderBy('id')
                ->get()
        );

        // Selagi pengajuan masih bisa disunting, pasangan yang belum diterima sudah
        // tampil sebagai blok editable (lihat muatKendalaBlocks()) — jangan tampil dobel.
        if ($this->pengajuanBisaDiedit()) {
            $aktif = $aktif->where('status_verifikasi', 'terverifikasi')->values();
        }

        return $this->cacheKendalaAktif = $aktif;
    }

    /**
     * Muat poin bagian kustom (mis. Manajemen Risiko) yang sudah tersimpan untuk
     * IKU+periode terpilih ke $this->bagianKustomBlocks supaya isian yang pernah
     * dibuat (termasuk yang dikembalikan Tim SAKIP) bisa DIEDIT lagi saat form dibuka
     * ulang — sebelumnya poin lama sama sekali tidak bisa disunting, hanya tampil
     * hanya-baca di riwayatBagianKustom() tanpa jalan untuk memperbaikinya.
     *
     * BagianKustomPoin tidak punya status_verifikasi sendiri (beda dari Kegiatan/
     * KendalaSolusi) — jadi "boleh diedit?" ditentukan dari status Capaian keseluruhan
     * periode ini: selama belum diajukan/diverifikasi/disetujui (STATUS_KEGIATAN_TERKUNCI),
     * poin periode ini dimuat ke sini sebagai editable. Begitu terkunci, poin TIDAK
     * dimuat ke sini (kembali ke satu blok kosong) dan tetap tampil hanya-baca lewat
     * riwayatBagianKustom() — lihat pengecualian yang sejalan di sana supaya tidak
     * dobel tampil.
     */
    protected function muatBagianKustomBlocks(): void
    {
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;
        $terkunci = $this->capaianTerkunci();
        $this->versiDimuat['poin'] = [];

        foreach ($this->bagianKustomAktif() as $bagian) {
            if (! $periode || $terkunci) {
                $this->bagianKustomBlocks[$bagian->id] = [$this->emptyBagianKustomBlock()];

                continue;
            }

            $daftar = BagianKustomPoin::with('berkas')
                ->konteksIsianKegiatan()
                ->where('iku_id', $this->iku_id)
                ->where('bagian_kustom_id', $bagian->id)
                ->where('periode_id', $periode->id)
                ->orderBy('id')
                ->get();

            if ($daftar->isEmpty()) {
                $this->bagianKustomBlocks[$bagian->id] = [$this->emptyBagianKustomBlock()];

                continue;
            }

            foreach ($daftar as $poin) {
                $this->versiDimuat['poin'][$poin->id] = self::sidikBaris($poin);
            }

            $this->bagianKustomBlocks[$bagian->id] = $daftar->map(fn (BagianKustomPoin $poin) => [
                'id' => $poin->id,
                'teks' => $poin->teks,
                'kolom' => $bagian->nilaiKolomUntukForm($poin->nilai_kolom),
                'bukti' => [],
                'existing_bukti' => $poin->berkas->map(fn (Berkas $b) => $this->maskBerkas([
                    'id' => $b->id,
                    'kode' => $b->kode,
                    'nama_file' => $b->nama_file,
                    'diunggah_oleh' => $b->diunggah_oleh,
                    'di_drive' => filled($b->drive_file_id) || blank($b->path),
                    'status_verifikasi' => $b->status_verifikasi,
                    'catatan' => $b->catatan,
                ]))->all(),
                'catatan_bukti_dihapus' => $poin->catatan_bukti_dihapus,
                // Hanya penanda tampilan — untuk poin tersimpan nilainya tidak dipakai saat simpan.
                'lanjutan_dari_id' => $poin->lanjutan_dari_id,
            ])->values()->all();
        }
    }

    /**
     * Poin RTL ini dilaksanakan oleh kegiatan (Bagian 2)? Tersimpan di database —
     * kegiatan bulan mana pun di triwulan ini — ATAU baru dipilih lewat dropdown RTL di
     * form ini. Bukti realisasinya lalu cukup diunggah sebagai bukti capaian kegiatan
     * itu (tampil otomatis di Bagian 4), jadi kotak unggah terpisah di Bagian 4 tidak
     * ditawarkan lagi supaya bukti yang sama tidak diunggah dua kali.
     */
    public function poinRtlTertautKegiatan(RtlEvaluasiModel $poin): bool
    {
        return $poin->kegiatan->isNotEmpty()
            || collect($this->blocks)->contains(fn ($b) => (int) ($b['rtl_evaluasi_id'] ?? 0) === (int) $poin->id);
    }

    /** Indeks blok form ini yang memuat baris Kegiatan $kegiatanId (null = bulan lain). */
    public function indeksBlokKegiatan(int $kegiatanId): ?int
    {
        $index = collect($this->blocks)->search(fn ($b) => (int) ($b['id'] ?? 0) === $kegiatanId);

        return $index === false ? null : (int) $index;
    }

    public function removeBuktiEvaluasi(int $rtlId, int $fileIndex): void
    {
        unset($this->evaluasi[$rtlId]['bukti'][$fileIndex]);
        $this->evaluasi[$rtlId]['bukti'] = array_values($this->evaluasi[$rtlId]['bukti']);
    }

    /**
     * RTL yang ditetapkan pada triwulan SEBELUMNYA untuk dilaksanakan pada triwulan
     * BERJALAN ini — satu-satunya sumber untuk: (1) dropdown/saran uraian kegiatan,
     * (2) bagian Evaluasi RTL (bukti realisasi), dan (3) validasi "wajib terlaksana
     * semua" di akhir triwulan. Ketiganya sengaja memakai koleksi yang sama persis.
     */
    protected function rtlTriwulanBerjalan()
    {
        if ($this->cacheRtlBerjalan !== null) {
            return $this->cacheRtlBerjalan;
        }

        if (! $this->iku_id) {
            return $this->cacheRtlBerjalan = collect();
        }

        $rtlBerjalan = Cache::remember(
            $this->cacheKeyPeriodeIku('rtl-berjalan'),
            self::CACHE_TTL_DETIK,
            function () {
                $triwulanSekarang = $this->triwulanDari($this->bulan);

                // kegiatan.berkas = bukti capaian kegiatan yang tertaut ke poin ini
                // (Bagian 2) — ikut tampil sebagai bukti realisasi, lihat
                // RtlEvaluasi::buktiDariKegiatan().
                return RtlEvaluasiModel::with(['periode', 'berkas', 'kegiatan.berkas', 'kegiatan.periode', 'salinanBukti'])
                    ->where('iku_id', $this->iku_id)
                    ->whereHas('periode', fn ($q) => $q->where('tahun', $this->tahun)->where('triwulan', $triwulanSekarang))
                    ->get();
            }
        );

        // Bukti realisasi RTL diperiksa Tim SAKIP bersamaan dengan siklus verifikasi
        // IKU+periode berjalan (lihat VerifikasiCapaian::rtlEvaluasiSebelumnya()) —
        // masking di sini SETELAH Cache::remember (bukan di dalamnya) supaya nilai asli
        // tetap disimpan ke cache, sama seperti pola di riwayatBagianKustom() di atas.
        // Bukti kegiatan tertaut mengikuti siklus verifikasi bulan kegiatannya sendiri.
        $terlihat = $this->verifikasiTerlihat();
        $periodeSaatIni = $this->periodeSaatIni();
        $periodeLain = $rtlBerjalan->flatMap(fn ($poin) => $poin->kegiatan->pluck('periode_id'))
            ->reject(fn ($id) => (int) $id === (int) $periodeSaatIni?->id)
            ->unique()->values();
        $statusBulanLain = $periodeLain->isEmpty() ? collect() : Capaian::where('iku_id', $this->iku_id)
            ->whereIn('periode_id', $periodeLain)->pluck('status', 'periode_id');
        $statusTerlihat = [Capaian::STATUS_DIKEMBALIKAN, Capaian::STATUS_DIVERIFIKASI, Capaian::STATUS_DISETUJUI];

        foreach ($rtlBerjalan as $poin) {
            $daftar = $terlihat ? collect() : $poin->berkas;

            foreach ($poin->kegiatan as $kegiatan) {
                $kegiatanTerlihat = (int) $kegiatan->periode_id === (int) $periodeSaatIni?->id
                    ? $terlihat
                    : in_array($statusBulanLain[$kegiatan->periode_id] ?? null, $statusTerlihat, true);

                if (! $kegiatanTerlihat) {
                    $daftar = $daftar->concat($kegiatan->berkas);
                }
            }

            foreach ($daftar as $berkas) {
                $berkas->status_verifikasi = 'menunggu';
                $berkas->catatan = null;
            }
        }

        return $this->cacheRtlBerjalan = $rtlBerjalan;
    }

    /**
     * ID rtl_evaluasi (triwulan berjalan) yang SEDANG tertaut ke salah satu blok
     * kegiatan di form ini — baik kegiatan lama yang dimuat dari DB maupun pilihan
     * dropdown yang baru saja dibuat, belum tentu tersimpan (lihat pilihRtlUntukBlock()).
     * Dihitung langsung dari $this->blocks (bukan query DB) supaya SELALU sinkron
     * dengan apa yang sedang terlihat di form, termasuk perubahan yang belum disimpan.
     * Dipakai bersama oleh rtlBerjalanOptions() dan poinRtlBerjalanBelumTerlaksana().
     */
    protected function rtlIdTerpilihDiBlocks(): Collection
    {
        return collect($this->blocks)
            ->pluck('rtl_evaluasi_id')
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * IKU yang sedang dipilih — dipakai berulang kali (PIC, nama tim, folder service) di
     * request yang sama, jadi cukup satu query per request.
     */
    protected function ikuTerpilih(): ?MasterIku
    {
        if ($this->cacheIkuTerpilihDihitung) {
            return $this->cacheIkuTerpilih;
        }

        $this->cacheIkuTerpilihDihitung = true;

        // Diambil dari daftarUrutKode() yang sudah di-cache (bukan query MasterIku::find
        // baru) — daftar itu dipakai juga untuk dropdown IKU, jadi tidak ada query tambahan.
        return $this->cacheIkuTerpilih = $this->iku_id
            ? MasterIku::daftarUrutKode()->firstWhere('id', $this->iku_id)
            : null;
    }

    /**
     * RTL triwulan berikutnya (bagian 5) hanya boleh diisi pada bulan TERAKHIR triwulan
     * yang sedang berjalan — sebelum itu, ditampilkan hanya-baca dengan info kapan bisa diisi.
     */
    public function rtlBaruBisaDiisi(): bool
    {
        return $this->bulanKeDari($this->bulan) === 3;
    }

    public function labelBulanTerakhirTriwulanIni(): string
    {
        $triwulan = $this->triwulanDari($this->bulan);

        return $this->namaBulanIndo(($triwulan - 1) * 3 + 3);
    }

    /**
     * Kegiatan IKU ini pada bulan LAIN di triwulan yang sama (bulan ini sendiri sudah
     * seluruhnya ada di $this->blocks, lihat muatBlocksKegiatan()) — dipakai untuk
     * penanda "sudah dipilih" di dropdown RTL dan untuk mencegah tahapan survei yang
     * sama dipilih dua kali untuk kegiatan yang sama dalam satu triwulan.
     *
     * @return Collection<int, array{rtl_evaluasi_id: ?int, uraian_kegiatan: string, jenis: ?string, tahapan_survei: ?string, bulan: int}>
     */
    protected function kegiatanBulanLainTriwulanIni(): Collection
    {
        if (! $this->iku_id) {
            return collect();
        }

        return Cache::remember($this->cacheKeyPeriodeIku('rtl-berjalan-terpakai'), self::CACHE_TTL_DETIK, function () {
            $triwulan = $this->triwulanDari($this->bulan);

            return Kegiatan::query()
                ->join('periode', 'periode.id', '=', 'kegiatan.periode_id')
                ->where('kegiatan.iku_id', $this->iku_id)
                ->where('periode.tahun', $this->tahun)
                ->where('periode.triwulan', $triwulan)
                ->where('periode.bulan', '!=', $this->bulan)
                ->orderBy('kegiatan.id')
                ->get(['kegiatan.rtl_evaluasi_id', 'kegiatan.uraian_kegiatan', 'kegiatan.jenis', 'kegiatan.tahapan_survei', 'periode.bulan'])
                ->map(fn ($k) => [
                    'rtl_evaluasi_id' => $k->rtl_evaluasi_id,
                    'uraian_kegiatan' => (string) $k->uraian_kegiatan,
                    'jenis' => $k->jenis,
                    'tahapan_survei' => $k->tahapan_survei,
                    'bulan' => (int) $k->bulan,
                ])
                ->values();
        });
    }

    /**
     * Nomor "Kegiatan N" tiap blok (kunci = indeks blok). Blok yang sudah tersimpan
     * dinilai dari baris DB-nya, urut id — sama persis dengan Verifikasi Capaian
     * (VerifikasiCapaian::infoKegiatanInduk()) — sehingga tahapan-tahapan satu
     * kegiatan induk (Kegiatan::kelompokInduk()) berbagi nomor yang sama di kedua
     * menu. Blok baru yang belum tersimpan ikut dinilai dari isian form-nya.
     *
     * @return array<int, int>
     */
    public function nomorKegiatanBlok(): array
    {
        $ids = collect($this->blocks)->pluck('id')->filter()->all();
        $tersimpan = $ids === [] ? collect() : Kegiatan::whereIn('id', $ids)->get()->keyBy('id');
        $periodeId = $tersimpan->first()?->periode_id;

        $baris = collect($this->blocks)->map(fn (array $block) => $tersimpan->get($block['id'] ?? 0) ?? new Kegiatan([
            'iku_id' => $this->iku_id,
            'periode_id' => $periodeId,
            'rtl_evaluasi_id' => $block['rtl_evaluasi_id'] ?? null,
            'uraian_kegiatan' => $block['uraian_kegiatan'] ?? '',
            'jenis' => $block['jenis'] ?? null,
            'tahapan_survei' => filled($block['tahapan_survei'] ?? null) ? $block['tahapan_survei'] : null,
        ]));

        $nomor = Kegiatan::nomorKegiatanInduk($baris->sortBy(fn (Kegiatan $k) => $k->id ?? PHP_INT_MAX));
        ksort($nomor);

        return $nomor;
    }

    /**
     * Dua blok dianggap kegiatan yang SAMA bila tertaut ke poin RTL yang sama, atau
     * uraiannya sama persis (abaikan besar/kecil huruf & spasi tepi).
     */
    protected function kegiatanSama(array $a, array $b): bool
    {
        if (! empty($a['rtl_evaluasi_id']) && (int) $a['rtl_evaluasi_id'] === (int) ($b['rtl_evaluasi_id'] ?? 0)) {
            return true;
        }

        $uraianA = trim(mb_strtolower($a['uraian_kegiatan'] ?? ''));

        return $uraianA !== '' && $uraianA === trim(mb_strtolower($b['uraian_kegiatan'] ?? ''));
    }

    /**
     * Tahapan survei yang SUDAH dipakai kegiatan yang sama (lihat kegiatanSama()) di
     * blok lain bulan ini atau di bulan lain triwulan ini — opsi ini dinonaktifkan di
     * dropdown Tahapan Survei blok $index supaya satu tahapan tidak tercatat dobel.
     *
     * $hanyaSebelumnya: untuk validasi, cukup bandingkan dengan blok-blok SEBELUM
     * $index (blok yang lebih dulu tidak ikut disalahkan atas bentrokan yang sama).
     *
     * @return list<string>
     */
    public function tahapanTerpakai(int $index, bool $hanyaSebelumnya = false): array
    {
        $block = $this->blocks[$index] ?? null;

        if (! $block) {
            return [];
        }

        $pembanding = collect($this->blocks)
            ->filter(fn ($lain, $j) => $hanyaSebelumnya ? $j < $index : $j !== $index)
            ->concat($this->kegiatanBulanLainTriwulanIni());

        return $pembanding
            ->filter(fn ($lain) => ($lain['jenis'] ?? null) === 'survei_sensus'
                && $this->kegiatanSama($block, $lain))
            ->flatMap(fn ($lain) => $this->tahapanDipilih($lain))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Aturan validasi tiap tahapan tambahan (blocks.*.tahapan_tambahan.*): valid, belum
     * dipakai kegiatan yang sama, dan tidak dobel dengan tahapan lain di blok ini.
     */
    protected function aturanTahapanTambahan(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            [, $index, , $urutan] = explode('.', $attribute);
            $block = $this->blocks[(int) $index] ?? null;

            if (! $block || ($block['jenis'] ?? '') !== 'survei_sensus' || $this->blokTerkunci($block['status_dokumen'] ?? null)) {
                return;
            }

            if (! $this->tahapanSah($block, $value)) {
                $fail('Tahapan tidak valid.');

                return;
            }

            $sebelumnya = array_merge([$block['tahapan_survei'] ?? ''], array_slice($block['tahapan_tambahan'] ?? [], 0, (int) $urutan));

            if (in_array($value, $sebelumnya, true) || in_array($value, $this->tahapanTerpakai((int) $index, hanyaSebelumnya: true), true)) {
                $fail('Tahapan "'.self::labelTahapan($value).'" sudah dipilih untuk kegiatan ini pada triwulan ini — pilih tahapan lain.');
            }
        };
    }

    /**
     * Aturan validasi blocks.*.tahapan_tambahan & blocks.*.subfolders — dipakai
     * simpanDraft() dan rules().
     *
     * @return array<string, list<mixed>>
     */
    protected function aturanTahapanDanSubfolder(): array
    {
        return [
            'blocks.*.tahapan_tambahan' => ['nullable', 'array', 'max:15'],
            'blocks.*.tahapan_tambahan.*' => [$this->aturanTahapanTambahan()],
            'blocks.*.tahapan_kustom' => ['nullable', 'array', 'max:12'],
            'blocks.*.tahapan_kustom.*' => ['string', 'max:50'],
            'blocks.*.subfolders' => ['nullable', 'array', 'max:20'],
            'blocks.*.subfolders.*' => ['string', 'max:100'],
            'blocks.*.subfolders_tahapan' => ['nullable', 'array'],
            'blocks.*.subfolders_tahapan.*' => ['array', 'max:20'],
            'blocks.*.subfolders_tahapan.*.*' => ['string', 'max:100'],
            'blocks.*.nama_folder_tahapan' => ['nullable', 'array'],
            'blocks.*.nama_folder_tahapan.*' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * Aturan validasi: tahapan survei blok ini belum dipakai kegiatan yang sama
     * (lihat tahapanTerpakai()). Blok terkunci dilewati — tidak bisa diperbaiki dari sini.
     */
    protected function aturanTahapanTidakGanda(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $index = (int) explode('.', $attribute)[1];
            $block = $this->blocks[$index] ?? null;

            if (! $block || blank($value) || $block['jenis'] !== 'survei_sensus' || $this->blokTerkunci($block['status_dokumen'] ?? null)) {
                return;
            }

            if (! $this->tahapanSah($block, $value)) {
                $fail('Tahapan tidak valid.');

                return;
            }

            if (in_array($value, $this->tahapanTerpakai($index, hanyaSebelumnya: true), true)) {
                $fail('Tahapan "'.self::labelTahapan($value).'" sudah dipilih untuk kegiatan ini pada triwulan ini — pilih tahapan lain.');
            }
        };
    }

    /**
     * Poin RTL triwulan berjalan (rencana yang ditetapkan triwulan sebelumnya) beserta
     * penanda "sudah dipilih" — dipakai sebagai opsi dropdown pemilihan RTL di Bagian 2
     * (lewat pilihRtlUntukBlock()) dan badge di Bagian 4. Opsi yang sudah dipilih TETAP
     * bisa dipilih lagi (lihat catatan di pilihRtlUntukBlock()); 'dipilih' = berapa kali
     * dipilih di blok bulan ini + bulan lain triwulan ini, 'tahapan' = tahapan survei
     * yang sudah dipakai, untuk keterangan di label opsi.
     *
     * @return Collection<int, array{poin: RtlEvaluasiModel, terpakai: bool, dipilih: int, tahapan: list<string>}>
     */
    protected function rtlBerjalanOptions()
    {
        $rtlBerjalan = $this->rtlTriwulanBerjalan();

        if ($rtlBerjalan->isEmpty()) {
            return collect();
        }

        $terpilihIds = $this->rtlIdTerpilihDiBlocks();
        $pemakaian = collect($this->blocks)->concat($this->kegiatanBulanLainTriwulanIni())
            ->filter(fn ($b) => ! empty($b['rtl_evaluasi_id']));

        return $rtlBerjalan->map(function ($poin) use ($terpilihIds, $pemakaian) {
            $dipakai = $pemakaian->filter(fn ($b) => (int) $b['rtl_evaluasi_id'] === (int) $poin->id);

            return [
                'poin' => $poin,
                'terpakai' => $terpilihIds->contains($poin->id),
                'dipilih' => $dipakai->count(),
                'tahapan' => $dipakai->where('jenis', 'survei_sensus')->pluck('tahapan_survei')->filter()->unique()->map(fn ($t) => self::labelTahapan($t))->values()->all(),
            ];
        });
    }

    /**
     * Poin RTL triwulan berjalan yang BELUM dipilih lewat dropdown RTL di blok kegiatan
     * mana pun — murni informatif (badge "Belum terlaksana sbg kegiatan" di Bagian 4);
     * SEJAK aturan jumlah minimal diterapkan (lihat buatValidator()), poin di sini boleh
     * tetap tidak dipilih dan pengajuan tetap bisa jalan selama syarat jumlah & bukti
     * evaluasi terpenuhi — kegiatan tidak wajib memakai kata-kata RTL persis lagi.
     *
     * @return Collection<int, RtlEvaluasiModel>
     */
    protected function poinRtlBerjalanBelumTerlaksana()
    {
        if (! $this->rtlBaruBisaDiisi()) {
            return collect();
        }

        $rtlBerjalan = $this->rtlTriwulanBerjalan();

        if ($rtlBerjalan->isEmpty()) {
            return collect();
        }

        $terpilihIds = $this->rtlIdTerpilihDiBlocks();

        return $rtlBerjalan->reject(fn ($poin) => $terpilihIds->contains($poin->id))->values();
    }

    /**
     * rtl_evaluasi_id final untuk sebuah blok kegiatan saat disimpan — HANYA pilihan
     * eksplisit dari dropdown RTL (blocks.*.rtl_evaluasi_id, diisi lewat pilihRtlUntukBlock()
     * dan TETAP tertaut walau uraian kegiatan-nya lalu disunting; kegiatan lama dimuat
     * beserta tautannya dari database). Tidak ada lagi pencocokan teks: tautan ini kini
     * juga membuat bukti capaian kegiatan tampil sebagai bukti realisasi RTL (lihat
     * RtlEvaluasi::buktiKegiatan()), jadi kegiatan "Ketik bebas" yang kebetulan berteks
     * sama tidak boleh ikut tertaut diam-diam. ID divalidasi ulang terhadap
     * rtlTriwulanBerjalan() supaya tidak ada ID basi/di luar triwulan berjalan yang lolos.
     */
    protected function rtlEvaluasiIdUntukBlock(array $block): ?int
    {
        $idTerpilih = $block['rtl_evaluasi_id'] ?? null;

        return $idTerpilih && $this->rtlTriwulanBerjalan()->contains('id', $idTerpilih) ? (int) $idTerpilih : null;
    }

    /**
     * PIC Tindak Lanjut — SELURUH tim penanggung jawab IKU terpilih (satu IKU boleh
     * ditugaskan ke lebih dari satu tim, lihat App\Models\MasterIku::namaTimList()),
     * sumber yang SAMA dengan kolom Tim di Penugasan IKU & "Penanggung Jawab (Tim)"
     * di Master IKU. Tidak diisi/diubah Ketua Tim — perubahan tim di sana otomatis
     * ikut ke PIC poin RTL yang belum terverifikasi (MasterIku::sinkronkanTim()).
     */
    protected function pilihPicOtomatis(): void
    {
        $this->rtlBaruPicTerpilih = $this->ikuTerpilih()?->namaTimList() ?? [];
    }

    protected function targetTriwulanBerikutnya(): array
    {
        $triwulanSekarang = $this->triwulanDari($this->bulan);

        return $triwulanSekarang === 4
            ? ['tahun' => $this->tahun + 1, 'triwulan' => 1]
            : ['tahun' => $this->tahun, 'triwulan' => $triwulanSekarang + 1];
    }

    protected function bulanBulanTarget(): array
    {
        $target = $this->targetTriwulanBerikutnya();
        $bulanPertama = ($target['triwulan'] - 1) * 3 + 1;

        return [$bulanPertama, $bulanPertama + 1, $bulanPertama + 2];
    }

    protected function akhirTriwulanBerikutnya(): Carbon
    {
        $target = $this->targetTriwulanBerikutnya();
        $bulanTerakhir = ($target['triwulan'] - 1) * 3 + 3;

        return Carbon::create($target['tahun'], $bulanTerakhir, 1)->endOfMonth();
    }

    public function namaBulanIndo(int $bulan): string
    {
        return Carbon::create(2000, $bulan, 1)->locale('id')->translatedFormat('F');
    }

    /**
     * "Sudah ada" berarti ada poin RTL berikutnya yang masih AKTIF (menunggu atau
     * sudah terverifikasi Tim SAKIP) — poin yang DITOLAK (lihat rtlBerikutnyaDitolak())
     * SENGAJA tidak dihitung di sini, supaya Bagian 5 otomatis terbuka lagi untuk
     * diperbaiki begitu Tim SAKIP menandai "Tidak Sesuai" (lihat
     * VerifikasiCapaian::tandaiRtlBerikutnyaTolak()), bukan terkunci selamanya. Sama
     * seperti catatanPenolakan(), penolakan baru dianggap FINAL (Bagian 5 terbuka)
     * setelah verifikasiTerlihat() — selagi Tim SAKIP masih memeriksa (belum tentu
     * jadi "Kembalikan ke Ketua Tim" beneran), tanda "Tidak Sesuai" sementara tidak
     * langsung membuka form ini.
     */
    protected function rtlTriwulanBerikutnyaSudahAda(): bool
    {
        if ($this->cacheRtlBerikutnyaSudahAda !== null) {
            return $this->cacheRtlBerikutnyaSudahAda;
        }

        if (! $this->iku_id) {
            return $this->cacheRtlBerikutnyaSudahAda = false;
        }

        return $this->cacheRtlBerikutnyaSudahAda = Cache::remember(
            $this->cacheKeyPeriodeIku('rtl-berikutnya-ada'.$this->akhiranModeEdit()),
            self::CACHE_TTL_DETIK,
            function () {
                $target = $this->targetTriwulanBerikutnya();

                // status_dokumen != draft SENGAJA disertakan — RTL Baru yang baru
                // tersimpan lewat "Simpan Draft" (belum diajukan ke Tim SAKIP sama
                // sekali) TIDAK dianggap "sudah ditetapkan" di sini, supaya Bagian 5
                // tetap terbuka & bisa diedit selama isian keseluruhan masih draft —
                // sama seperti Kegiatan/Kendala & Solusi yang tetap bisa diedit selagi
                // berstatus draft. Baru dianggap "sudah ditetapkan" (hanya-baca) begitu
                // benar-benar diajukan (lihat simpanBagianIsian()).
                $query = RtlEvaluasiModel::where('iku_id', $this->iku_id)
                    ->where('status_dokumen', RtlEvaluasiModel::STATUS_DIAJUKAN)
                    ->whereHas('periode', fn ($q) => $q->where('tahun', $target['tahun'])->where('triwulan', $target['triwulan']));

                if ($this->verifikasiTerlihat()) {
                    $query->where('status_verifikasi', '!=', 'ditolak');
                }

                // Selagi pengajuan masih bisa disunting, poin yang belum diterima ikut
                // dimuat editable (lihat rtlBerikutnyaBisaDisunting()) — hanya yang
                // sudah diterima yang dianggap "sudah ditetapkan".
                if ($this->pengajuanBisaDiedit()) {
                    $query->where('status_verifikasi', 'terverifikasi');
                }

                return $query->exists();
            }
        );
    }

    /**
     * Poin RTL berikutnya yang tersimpan sebagai DRAFT milik sendiri (belum pernah
     * diajukan ke Tim SAKIP sama sekali) — dipakai muatRtlBaruBlocks() supaya teks
     * yang sudah diketik & disimpan lewat "Simpan Draft" tetap tampil & bisa
     * dilanjutkan mengeditnya, bukan hilang jadi blok kosong lagi begitu form dibuka
     * ulang. Lihat catatan di rtlTriwulanBerikutnyaSudahAda() soal kenapa draft tidak
     * dianggap "sudah ditetapkan".
     *
     * @return Collection<int, RtlEvaluasiModel>
     */
    protected function rtlBerikutnyaDraftSendiri()
    {
        if (! $this->iku_id) {
            return collect();
        }

        $target = $this->targetTriwulanBerikutnya();

        return RtlEvaluasiModel::where('iku_id', $this->iku_id)
            ->where('status_dokumen', RtlEvaluasiModel::STATUS_DRAFT)
            ->whereHas('periode', fn ($q) => $q->where('tahun', $target['tahun'])->where('triwulan', $target['triwulan']))
            ->orderBy('id')
            ->get();
    }

    /**
     * Poin RTL berikutnya yang DITOLAK FINAL Tim SAKIP (lihat VerifikasiCapaian::
     * tandaiRtlBerikutnyaTolak(), digerbang verifikasiTerlihat() sama seperti
     * rtlTriwulanBerikutnyaSudahAda() di atas) — dipakai muatRtlBaruBlocks() untuk
     * membuka kembali Bagian 5 berisi poin-poin ini (siap diperbaiki & diajukan
     * ulang) dan blade untuk menampilkan alasan penolakannya.
     *
     * @return Collection<int, RtlEvaluasiModel>
     */
    protected function rtlBerikutnyaDitolak()
    {
        if ($this->cacheRtlBerikutnyaDitolak !== null) {
            return $this->cacheRtlBerikutnyaDitolak;
        }

        if (! $this->iku_id || ! $this->verifikasiTerlihat()) {
            return $this->cacheRtlBerikutnyaDitolak = collect();
        }

        $target = $this->targetTriwulanBerikutnya();

        return $this->cacheRtlBerikutnyaDitolak = RtlEvaluasiModel::where('iku_id', $this->iku_id)
            ->where('status_verifikasi', 'ditolak')
            ->whereHas('periode', fn ($q) => $q->where('tahun', $target['tahun'])->where('triwulan', $target['triwulan']))
            ->orderBy('id')
            ->get();
    }

    /**
     * Poin RTL berikutnya yang AKTIF (menunggu ATAU sudah terverifikasi Tim SAKIP —
     * kebalikan dari rtlBerikutnyaDitolak() di atas) — dipakai blade untuk MENAMPILKAN
     * isi rencana yang sudah ditetapkan begitu rtlTriwulanBerikutnyaSudahAda() true,
     * bukan cuma badge "Sudah ditetapkan" tanpa detail (beda dari bagian lain di form
     * ini yang semuanya tetap menampilkan isian sebelumnya).
     *
     * @return Collection<int, RtlEvaluasiModel>
     */
    protected function rtlBerikutnyaAktif()
    {
        if ($this->cacheRtlBerikutnyaAktif !== null) {
            return $this->cacheRtlBerikutnyaAktif;
        }

        if (! $this->iku_id) {
            return $this->cacheRtlBerikutnyaAktif = collect();
        }

        return $this->cacheRtlBerikutnyaAktif = Cache::remember(
            $this->cacheKeyPeriodeIku('rtl-berikutnya-aktif'.$this->akhiranModeEdit()),
            self::CACHE_TTL_DETIK,
            function () {
                $target = $this->targetTriwulanBerikutnya();

                return RtlEvaluasiModel::where('iku_id', $this->iku_id)
                    ->where('status_dokumen', RtlEvaluasiModel::STATUS_DIAJUKAN)
                    ->when(
                        $this->pengajuanBisaDiedit(),
                        fn ($q) => $q->where('status_verifikasi', 'terverifikasi'),
                        fn ($q) => $q->where('status_verifikasi', '!=', 'ditolak'),
                    )
                    ->whereHas('periode', fn ($q) => $q->where('tahun', $target['tahun'])->where('triwulan', $target['triwulan']))
                    ->orderBy('id')
                    ->get();
            }
        );
    }

    /**
     * Poin RTL berikutnya yang sudah diajukan (atau masih draft) tapi BELUM diterima
     * Tim SAKIP, selagi pengajuannya masih bisa disunting (pengajuanBisaDiedit()) —
     * dimuat ke $rtlBaru lengkap dengan id-nya supaya bisa diperbaiki di tempat.
     *
     * @return Collection<int, RtlEvaluasiModel>
     */
    protected function rtlBerikutnyaBisaDisunting()
    {
        if (! $this->iku_id || ! $this->pengajuanBisaDiedit()) {
            return collect();
        }

        $target = $this->targetTriwulanBerikutnya();

        return RtlEvaluasiModel::where('iku_id', $this->iku_id)
            ->whereIn('status_dokumen', [RtlEvaluasiModel::STATUS_DIAJUKAN, RtlEvaluasiModel::STATUS_DRAFT])
            ->where('status_verifikasi', '!=', 'terverifikasi')
            ->whereHas('periode', fn ($q) => $q->where('tahun', $target['tahun'])->where('triwulan', $target['triwulan']))
            ->orderBy('id')
            ->get();
    }

    /**
     * Muat ulang $rtlBaru — kosong (satu blok baru) secara bawaan, ATAU diisi dari
     * poin-poin yang ditolak Tim SAKIP, ATAU (bila tidak ada yang ditolak) dari draft
     * milik sendiri yang sudah tersimpan lewat "Simpan Draft" tapi belum diajukan
     * (lengkap dengan id-nya, supaya penyimpanan berikutnya meng-UPDATE baris lama,
     * bukan membuat duplikat baru — lihat langkah 4 di simpanBagianIsian()). Dipanggil
     * di titik yang sama dengan muatBagianKustomBlocks() (mount/updatedIkuId/
     * updatedBulan/updatedTahun).
     *
     * Satu blok kosong bawaan HANYA disiapkan otomatis untuk pengisian PERTAMA KALI
     * (belum ada batch RTL berikutnya yang diajukan sama sekali) — begitu batch sudah
     * ditetapkan (rtlTriwulanBerikutnyaSudahAda()), $rtlBaru dibiarkan KOSONG (blade
     * cukup menampilkan tombol "+ Tambah Poin RTL" saja, sama seperti Kendala & Solusi
     * di muatKendalaBlocks()) — supaya tidak ada kotak input kosong yang nongol
     * begitu saja di bawah daftar poin yang sudah terkunci, padahal belum tentu
     * Ketua Tim mau menambah poin baru saat itu juga.
     */
    protected function muatRtlBaruBlocks(): void
    {
        $this->rtlBaruBatasWaktu = $this->akhirTriwulanBerikutnya()->toDateString();
        $this->versiDimuat['rtl'] = [];

        // Urutan prioritas: ditolak → bisa disunting → draft sendiri; sumber berikutnya
        // hanya dihitung bila sumber sebelumnya kosong.
        foreach ([fn () => $this->rtlBerikutnyaDitolak(), fn () => $this->rtlBerikutnyaBisaDisunting(), fn () => $this->rtlBerikutnyaDraftSendiri()] as $sumber) {
            $daftar = $sumber();

            if ($daftar->isNotEmpty()) {
                $this->rtlBaru = $daftar->map(fn ($poin) => ['id' => $poin->id, 'rtl_teks' => $poin->rtl_teks])->values()->all();

                // Versi dibaca langsung dari DB (bukan dari cache daftar di atas).
                $this->versiDimuat['rtl'] = RtlEvaluasiModel::whereKey($daftar->pluck('id'))->get()
                    ->mapWithKeys(fn ($rtl) => [$rtl->id => self::sidikBaris($rtl)])->all();

                return;
            }
        }

        $this->rtlBaru = $this->rtlTriwulanBerikutnyaSudahAda() ? [] : [$this->emptyRtlBlock()];
    }

    protected function labelTriwulanBerikutnya(): string
    {
        $target = $this->targetTriwulanBerikutnya();

        return 'Triwulan '.['I', 'II', 'III', 'IV'][$target['triwulan'] - 1].' '.$target['tahun'];
    }

    /**
     * Validasi "IKU ini benar-benar ada" TANPA query DB fresh — dicocokkan ke
     * MasterIku::daftarUrutKode() yang sudah di-cache, karena rules() dipanggil di
     * setiap render (lewat formLengkap()) dan biasanya IKU yang dipilih sudah pasti
     * ada di daftar itu (baru query fresh kalau memang belum pernah di-cache sama sekali).
     */
    protected function aturanIkuValid(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! MasterIku::daftarUrutKode()->contains('id', (int) $value)) {
                $fail('IKU yang dipilih tidak valid.');
            }
        };
    }

    protected function rules(): array
    {
        $rules = [
            'tahun' => ['required', 'integer', 'min:2020', 'max:2100'],
            'bulan' => ['required', 'integer', 'min:1', 'max:12'],
            // Bukan 'exists:master_iku,id' dengan sengaja — aturan itu SELALU query DB
            // fresh, dan validator ini (lewat formLengkap()) jalan di SETIAP render,
            // termasuk aksi yang sama sekali tidak butuh DB (mis. "Tambah Kegiatan").
            // Cukup cocokkan ke daftarUrutKode() yang sudah di-cache (lihat MasterIku).
            'iku_id' => ['required', $this->aturanIkuValid()],

            'blocks' => ['required', 'array', 'min:1'],
            'blocks.*.uraian_kegiatan' => ['required', 'string', 'max:1000'],
            'blocks.*.jenis' => ['required', 'in:bukan_survei_sensus,survei_sensus'],
            'blocks.*.tahapan_survei' => [
                'nullable',
                'required_if:blocks.*.jenis,survei_sensus',
                $this->aturanTahapanTidakGanda(),
            ],
            'blocks.*.nama_folder' => ['nullable', 'string', 'max:150'],

            'kendalaBlocks.*.kendala' => ['nullable', 'string'],
            'kendalaBlocks.*.solusi' => ['nullable', 'string'],
        ] + $this->aturanTahapanDanSubfolder();

        // Bukti capaian per blok kegiatan — wajib minimal 1 HANYA bila blok itu belum
        // punya berkas tersimpan sama sekali (existing_bukti, dimuat di
        // muatBlocksKegiatan()); blok yang sudah punya bukti lama tidak dipaksa
        // mengunggah bukti BARU lagi cuma untuk mengedit teks/mengajukan ulang.
        // Blok terkunci (sudah diajukan/diverifikasi/disetujui) dilewati sama sekali
        // dari loop simpan (lihat simpanDraft()/ajukanIsian()), jadi aturannya cukup
        // dibuat nullable saja di sini.
        foreach ($this->blocks as $i => $block) {
            $terkunci = $this->blokTerkunci($block['status_dokumen'] ?? null);
            $adaBuktiLama = ! empty($block['existing_bukti']);

            $rules["blocks.{$i}.bukti"] = ($terkunci || $adaBuktiLama)
                ? ['nullable', 'array']
                : ['required', 'array', 'min:1'];
            $rules["blocks.{$i}.bukti.*"] = ['file', 'mimes:pdf'];
        }

        // Bagian kustom (mis. Manajemen Risiko): poin kosong dilewati saat disimpan
        // (sama seperti kendalaBlocks); poin yang TERISI wajib punya bukti dukung HANYA
        // bila bagiannya dikonfigurasi bukti_wajib (bisa dimatikan Tim SAKIP per bagian)
        // DAN belum punya bukti lama tersimpan (existing_bukti, dimuat di
        // muatBagianKustomBlocks()) — poin yang sedang diedit ulang tidak dipaksa
        // mengunggah bukti BARU lagi cuma untuk memperbaiki teksnya, sama seperti
        // aturan blocks.*.bukti untuk Kegiatan di atas.
        foreach ($this->bagianKustomAktif() as $bagian) {
            $rules["bagianKustomBlocks.{$bagian->id}.*.teks"] = ['nullable', 'string'];
            // Panjang per jenis kolom (teks pendek 500, teks panjang 5000, atau batas
            // yang diatur Tim SAKIP) diperiksa BagianKustom::pesanGalatKolom().
            $rules["bagianKustomBlocks.{$bagian->id}.*.kolom.*"] = ['nullable', 'string', 'max:5000'];

            // Aturan bukti diatur Tim SAKIP per bagian (AturanBuktiBagianKustom); bagian
            // yang belum diatur = perilaku lama (PDF, wajib bila bukti_wajib).
            $aturanBukti = $bagian->aturanBuktiDi(MenuBagianKustom::PENGISIAN);

            foreach ($this->bagianKustomBlocks[$bagian->id] ?? [] as $i => $blok) {
                $adaBuktiLama = ! empty($blok['existing_bukti']);

                $rules["bagianKustomBlocks.{$bagian->id}.{$i}.bukti"] = match (true) {
                    ! AturanBuktiBagianKustom::dipakai($aturanBukti) => ['nullable', 'array', 'max:0'],
                    $aturanBukti['mode'] === AturanBuktiBagianKustom::MODE_WAJIB && ! $adaBuktiLama => ['required_with:bagianKustomBlocks.'.$bagian->id.'.'.$i.'.teks', 'array'],
                    default => ['nullable', 'array'],
                };
            }

            $rules["bagianKustomBlocks.{$bagian->id}.*.bukti.*"] = AturanBuktiBagianKustom::aturanBerkas($aturanBukti);
        }

        // RF-29/31: poin RTL triwulan sebelumnya cukup ditampilkan + tempat unggah bukti —
        // WAJIB SEMUA (bukan cuma satu) sudah punya bukti sebelum bisa diajukan, tapi
        // aturan itu hanya digerbang pada bulan TERAKHIR triwulan (dicek di buatValidator()).
        foreach ($this->evaluasi as $rtlId => $data) {
            $rules["evaluasi.{$rtlId}.bukti.*"] = ['file', 'mimes:pdf'];
        }

        // RF-32/33/34: RTL triwulan berikutnya hanya boleh (dan wajib) ditetapkan pada bulan
        // terakhir triwulan berjalan, kecuali sudah pernah ditetapkan sebelumnya.
        //
        // PIC Tindak Lanjut TIDAK divalidasi di sini sama sekali — tidak diisi Ketua
        // Tim, selalu otomatis dari tim penanggung jawab IKU (lihat pilihPicOtomatis()).
        if ($this->rtlBaruBisaDiisi() && ! $this->rtlTriwulanBerikutnyaSudahAda()) {
            $rules['rtlBaru'] = ['required', 'array', 'min:1'];
            $rules['rtlBaru.*.rtl_teks'] = ['required', 'string'];
            $rules['rtlBaruBatasWaktu'] = ['required', 'date'];
        } elseif ($this->rtlBaruBisaDiisi()) {
            // RTL triwulan berikutnya sudah pernah ditetapkan (lihat blade: poin lama
            // tampil hanya-baca) — menambah poin BARU ke batch yang sama di sini
            // bersifat OPSIONAL (Ketua Tim boleh mengajukan perubahan lain tanpa wajib
            // menambah RTL), beda dari penetapan pertama kali di atas yang wajib.
            $rules['rtlBaru.*.rtl_teks'] = ['nullable', 'string'];
        }

        return $rules;
    }

    protected function validationAttributes(): array
    {
        $attrs = [
            'iku_id' => 'IKU',
            'blocks.*.uraian_kegiatan' => 'uraian kegiatan',
            'blocks.*.jenis' => 'pembentukan folder kegiatan',
            'blocks.*.tahapan_survei' => 'tahapan',
            'blocks.*.nama_folder' => 'nama folder',
            'blocks.*.bukti' => 'bukti capaian',
            'kendalaBlocks.*.kendala' => 'kendala',
            'kendalaBlocks.*.solusi' => 'solusi',
            'rtlBaru.*.rtl_teks' => 'RTL',
            'rtlBaruBatasWaktu' => 'batas waktu',
        ];

        foreach ($this->bagianKustomAktif() as $bagian) {
            $attrs["bagianKustomBlocks.{$bagian->id}.*.teks"] = 'poin '.$bagian->nama;
            $attrs["bagianKustomBlocks.{$bagian->id}.*.bukti"] = 'bukti dukung '.$bagian->nama;
        }

        return $attrs;
    }

    protected function messages(): array
    {
        $messages = [
            'blocks.*.tahapan_survei.required_if' => 'Pilih minimal satu tahapan bila folder kegiatan dipisah ke beberapa tahapan.',
            'blocks.*.bukti.required' => 'Bukti capaian (PDF) wajib diunggah untuk tiap kegiatan.',
        ];

        foreach ($this->bagianKustomAktif() as $bagian) {
            $messages["bagianKustomBlocks.{$bagian->id}.*.bukti.required_with"] = 'Bukti dukung wajib diunggah untuk tiap poin '.$bagian->nama.' yang diisi.';
            $messages["bagianKustomBlocks.{$bagian->id}.*.bukti.max"] = 'Bagian '.$bagian->nama.' tidak memakai bukti dukung.';
            $aturanBukti = $bagian->aturanBuktiDi(MenuBagianKustom::PENGISIAN);
            $messages["bagianKustomBlocks.{$bagian->id}.*.bukti.*.mimes"] = 'Format bukti '.$bagian->nama.' harus: '.AturanBuktiBagianKustom::labelFormat($aturanBukti).'.';

            if ($aturanBukti['ukuran_maks_mb'] !== null) {
                $messages["bagianKustomBlocks.{$bagian->id}.*.bukti.*.max"] = 'Ukuran tiap bukti '.$bagian->nama.' maksimal '.$aturanBukti['ukuran_maks_mb'].' MB.';
            }
        }

        return $messages;
    }

    /**
     * Bangun validator gabungan (rules dasar + aturan tambahan yang tidak bisa dinyatakan
     * lewat array rules biasa): minimal satu evaluasi RTL sebelumnya wajib diisi, dan seluruh
     * poin RTL triwulan berjalan wajib sudah terlaksana sebelum bulan terakhir triwulan diajukan.
     */
    protected function buatValidator(): Validator
    {
        $data = [
            'tahun' => $this->tahun,
            'bulan' => $this->bulan,
            'iku_id' => $this->iku_id,
            'blocks' => $this->blocks,
            'kendalaBlocks' => $this->kendalaBlocks,
            'evaluasi' => $this->evaluasi,
            'rtlBaru' => $this->rtlBaru,
            'rtlBaruBatasWaktu' => $this->rtlBaruBatasWaktu,
            'bagianKustomBlocks' => $this->bagianKustomBlocks,
        ];

        $validator = \Illuminate\Support\Facades\Validator::make(
            $data,
            $this->rules(),
            $this->messages(),
            $this->validationAttributes()
        );

        $validator->after(function ($validator) {
            foreach ($this->galatSusunanFolder() as $kunci => $daftarPesan) {
                foreach ($daftarPesan as $pesan) {
                    $validator->errors()->add($kunci, $pesan);
                }
            }

            // Blok dengan lebih dari satu tahapan: tiap tahapan menjadi kegiatan
            // sendiri, jadi masing-masing wajib punya minimal satu bukti (tahapan
            // utama boleh memakai bukti lama yang sudah tersimpan).
            foreach ($this->blocks as $i => $block) {
                $dipilih = $this->tahapanDipilih($block);

                if (($block['jenis'] ?? '') !== 'survei_sensus' || count($dipilih) < 2 || $this->blokTerkunci($block['status_dokumen'] ?? null)) {
                    continue;
                }

                $terisi = collect($block['bukti'] ?? [])->map(fn ($f) => $this->tahapanBerkasBaru($block, $f))->unique()->all();

                foreach ($dipilih as $ti => $tahapan) {
                    if (! in_array($tahapan, $terisi, true) && ! ($ti === 0 && ! empty($block['existing_bukti']))) {
                        $validator->errors()->add(
                            "blocks.{$i}.tahapan_tambahan",
                            'tahapan '.self::labelTahapan($tahapan).' belum diberi bukti capaian — pilih tahapan tujuan pada berkas yang diunggah.'
                        );
                    }
                }
            }

            // RF-29/31: SEMUA poin RTL triwulan sebelumnya wajib punya bukti realisasi
            // (sudah tersimpan ATAU baru dipilih di form ini) — tapi HANYA digerbang pada
            // bulan TERAKHIR triwulan berjalan, sama seperti aturan bagian kustom di bawah.
            if ($this->bulanKeDari($this->bulan) === 3) {
                $poinTanpaBukti = $this->rtlTriwulanBerjalan()->reject(function ($poin) {
                    $buktiBaru = $this->evaluasi[$poin->id]['bukti'] ?? [];
                    // Bukti capaian baru di blok kegiatan yang tertaut ke poin ini juga
                    // dihitung (tampil sebagai bukti realisasinya, tanpa unggah ulang).
                    $buktiKegiatanBaru = collect($this->blocks)->contains(
                        fn ($b) => (int) ($b['rtl_evaluasi_id'] ?? 0) === (int) $poin->id && ! empty($b['bukti'])
                    );

                    return $poin->sudahDievaluasi() || ! empty($buktiBaru) || $buktiKegiatanBaru;
                });

                if ($poinTanpaBukti->isNotEmpty()) {
                    $validator->errors()->add(
                        'evaluasi',
                        "Masih ada {$poinTanpaBukti->count()} poin evaluasi RTL triwulan sebelumnya yang belum diberi bukti realisasi — wajib diunggah semua sebelum diajukan pada bulan terakhir triwulan ini."
                    );
                }
            }

            // Kendala & Solusi boleh dikosongkan tiap bulan (lihat info banner di blade),
            // TAPI minimal satu pasangan wajib sudah tercatat untuk triwulan ini begitu
            // sampai bulan TERAKHIR triwulan — baik yang diisi bulan ini (kendalaBlocks)
            // maupun yang sudah diajukan di bulan-bulan sebelumnya triwulan yang sama
            // (riwayatKendalaSolusi(), sudah di-scope per triwulan lewat groupBy di sana).
            if ($this->bulanKeDari($this->bulan) === 3) {
                $adaDiFormIni = collect($this->kendalaBlocks)
                    ->contains(fn ($blok) => filled($blok['kendala'] ?? null))
                    || $this->kendalaAktif()->isNotEmpty();

                $adaDiBulanSebelumnya = $this->riwayatKendalaSolusi()
                    ->get($this->triwulanDari($this->bulan), collect())
                    ->isNotEmpty();

                if (! $adaDiFormIni && ! $adaDiBulanSebelumnya) {
                    $validator->errors()->add(
                        'kendalaBlocks',
                        'Minimal satu pasangan Kendala & Solusi wajib diisi sebelum diajukan pada bulan terakhir triwulan ini.'
                    );
                }
            }

            // RF-30 (diperbarui): kegiatan TIDAK wajib memakai kata-kata RTL persis lagi —
            // boleh dipilih lewat dropdown RTL (menaut otomatis) ATAU diketik bebas di luar
            // rencana RTL triwulan sebelumnya, ASALKAN jumlah kegiatan yang diisi triwulan
            // ini minimal SAMA BANYAK dengan jumlah poin RTL yang diajukan triwulan
            // sebelumnya — dan (dicek terpisah di atas) bukti evaluasi RTL sudah lengkap
            // semua. Hanya digerbang pada bulan TERAKHIR triwulan, sama seperti aturan lain.
            if ($this->bulanKeDari($this->bulan) === 3) {
                $jumlahRtl = $this->rtlTriwulanBerjalan()->count();

                if ($jumlahRtl > 0) {
                    $jumlahKegiatanTerisi = collect($this->blocks)
                        ->filter(fn ($block) => filled($block['uraian_kegiatan'] ?? null))
                        ->count();

                    if ($jumlahKegiatanTerisi < $jumlahRtl) {
                        $validator->errors()->add(
                            'blocks',
                            "Jumlah kegiatan yang diisi ({$jumlahKegiatanTerisi}) masih kurang dari jumlah poin RTL yang diajukan triwulan sebelumnya ({$jumlahRtl}) — minimal harus sama banyak sebelum diajukan pada bulan terakhir triwulan ini."
                        );
                    }
                }
            }

            // Bagian kustom: minimal satu poin wajib terisi sesuai frekuensi yang
            // dikonfigurasi Tim SAKIP (setiap bulan, atau hanya bulan TERAKHIR triwulan
            // seperti syarat RTL baru) — 'opsional' tidak pernah menggerbang apa pun.
            $bulanKeSekarang = $this->bulanKeDari($this->bulan);

            foreach ($this->bagianKustomAktif() as $bagian) {
                // Poin yang terisi (uraian atau kolom) wajib punya uraian, kolom wajib,
                // dan nilai pilihan yang benar-benar ada di daftar opsinya.
                foreach ($this->bagianKustomBlocks[$bagian->id] ?? [] as $i => $blok) {
                    if (! $this->blokBagianKustomTerisi($blok)) {
                        continue;
                    }

                    if (blank(trim((string) ($blok['teks'] ?? '')))) {
                        $validator->errors()->add("bagianKustomBlocks.{$bagian->id}.{$i}.teks", 'Isi '.mb_strtolower($bagian->labelUraianEfektif()).' untuk poin ini.');
                    }

                    // Hanya kolom yang berlaku pada periode ini (aktif & sudah mulai
                    // berlaku) dan tidak disembunyikan syaratnya, plus subbagian wajib —
                    // aturan yang sama dengan Isian Bagian Kustom (BagianKustom::galatNilaiPoin()).
                    foreach ($bagian->galatNilaiPoin($blok['kolom'] ?? [], $this->tahun, BagianKustom::triwulanDariBulan($this->bulan)) as $kunci => $galat) {
                        $validator->errors()->add("bagianKustomBlocks.{$bagian->id}.{$i}.kolom.{$kunci}", $galat);
                    }

                    // Jumlah bukti (minimal/maksimal) sesuai aturan bukti bagian. Poin
                    // tanpa bukti sama sekali sudah ditangani aturan required_with di rules().
                    $lama = count($blok['existing_bukti'] ?? []);
                    $baru = count($blok['bukti'] ?? []);

                    if ($lama + $baru > 0 && ($galat = AturanBuktiBagianKustom::galatJumlah($bagian->aturanBuktiDi(MenuBagianKustom::PENGISIAN), $lama, $baru)) !== null) {
                        $validator->errors()->add("bagianKustomBlocks.{$bagian->id}.{$i}.bukti", $galat);
                    }
                }

                if (! $bagian->wajibDiisiPada($bulanKeSekarang)) {
                    continue;
                }

                $adaTerisi = collect($this->bagianKustomBlocks[$bagian->id] ?? [])
                    ->contains(fn ($blok) => $this->blokBagianKustomTerisi($blok));

                if (! $adaTerisi) {
                    $pesanWaktu = $bagian->frekuensi_wajib === 'setiap_bulan'
                        ? 'bulan ini'
                        : 'sebelum diajukan pada bulan terakhir triwulan ini';

                    $validator->errors()->add(
                        "bagianKustomBlocks.{$bagian->id}",
                        "Minimal satu poin \"{$bagian->nama}\" wajib diisi {$pesanWaktu}."
                    );
                }
            }
        });

        return $validator;
    }

    /**
     * Dipakai untuk mengaktifkan/menonaktifkan tombol "Ajukan ke Tim SAKIP" secara proaktif
     * di tampilan — bukan hanya menunggu klik lalu menampilkan galat.
     */
    protected ?bool $cacheFormLengkap = null;

    /** @var list<string>|null */
    protected ?array $cacheKekuranganPengajuan = null;

    public function formLengkap(): bool
    {
        if ($this->cacheFormLengkap !== null) {
            return $this->cacheFormLengkap;
        }

        return $this->cacheFormLengkap = $this->kekuranganPengajuan() === [];
    }

    /**
     * Daftar ringkas (bahasa Indonesia, per bagian/per nomor blok) hal yang masih
     * kurang/tidak sesuai sehingga tombol "Ajukan ke Tim SAKIP" belum aktif —
     * ditampilkan di bawah tombol supaya Ketua Tim tahu PERSIS apa yang harus
     * dilengkapi, bukan cuma "lengkapi seluruh isian wajib". Dibangun dari validator
     * yang sama dengan ajukanIsian() (buatValidator()), jadi selalu konsisten dengan
     * syarat pengajuan sebenarnya. Di-cache per request seperti formLengkap().
     *
     * @return list<string>
     */
    public function kekuranganPengajuan(): array
    {
        if ($this->cacheKekuranganPengajuan !== null) {
            return $this->cacheKekuranganPengajuan;
        }

        if (! $this->iku_id) {
            return $this->cacheKekuranganPengajuan = ['Pilih IKU terlebih dahulu.'];
        }

        $validator = $this->buatValidator();

        if ($validator->passes()) {
            return $this->cacheKekuranganPengajuan = [];
        }

        $failed = $validator->failed();
        $daftar = [];

        foreach ($validator->errors()->messages() as $kunci => $pesan) {
            $daftar[] = $this->uraikanKekurangan($kunci, array_keys($failed[$kunci] ?? []), $pesan[0] ?? '');
        }

        return $this->cacheKekuranganPengajuan = array_values(array_unique(array_filter($daftar)));
    }

    /**
     * Terjemahkan satu kunci galat validator (mis. "blocks.1.bukti") menjadi kalimat
     * yang menyebut bagian & nomor blok di form. Galat dari validator->after()
     * (kunci tanpa aturan gagal, mis. "evaluasi"/"kendalaBlocks") sudah berbahasa
     * Indonesia, jadi pesannya dipakai apa adanya.
     *
     * @param  list<string>  $aturanGagal
     */
    protected function uraikanKekurangan(string $kunci, array $aturanGagal, string $pesanAsli): string
    {
        $kosong = array_intersect($aturanGagal, ['Required', 'RequiredIf', 'RequiredWith', 'Min']) !== [];
        $bagian = explode('.', $kunci);

        if ($kunci === 'iku_id') {
            return $kosong ? 'Pilih IKU terlebih dahulu.' : 'IKU yang dipilih tidak valid — pilih ulang IKU.';
        }

        if (in_array($kunci, ['tahun', 'bulan'], true)) {
            return 'Periode (tahun/bulan) tidak valid.';
        }

        if ($kunci === 'blocks') {
            return $aturanGagal === [] ? $pesanAsli : 'Isi minimal satu kegiatan.';
        }

        if ($bagian[0] === 'blocks' && isset($bagian[2])) {
            $label = $this->labelBlokKegiatan((int) $bagian[1]);

            return match (true) {
                $bagian[2] === 'uraian_kegiatan' && $kosong => "{$label}: uraian kegiatan belum diisi.",
                $bagian[2] === 'uraian_kegiatan' => "{$label}: uraian kegiatan terlalu panjang (maksimal 1.000 karakter).",
                $bagian[2] === 'jenis' => "{$label}: pembentukan folder kegiatan belum dipilih.",
                $bagian[2] === 'tahapan_survei' && $kosong => "{$label}: tahapan belum dipilih (wajib bila folder kegiatan dipisah ke beberapa tahapan).",
                $bagian[2] === 'tahapan_survei' => "{$label}: {$pesanAsli}",
                $bagian[2] === 'nama_folder' => "{$label}: nama folder terlalu panjang (maksimal 150 karakter).",
                $bagian[2] === 'bukti' && isset($bagian[3]) => "{$label}: berkas bukti ke-".((int) $bagian[3] + 1).' harus berupa PDF.',
                $bagian[2] === 'bukti' => "{$label}: bukti capaian (PDF) belum diunggah.",
                default => "{$label}: {$pesanAsli}",
            };
        }

        if ($bagian[0] === 'bagianKustomBlocks') {
            $nama = $this->bagianKustomAktif()->firstWhere('id', (int) ($bagian[1] ?? 0))?->nama ?? 'Bagian tambahan';

            if (! isset($bagian[2])) {
                return $pesanAsli;
            }

            $label = "{$nama} poin #".((int) $bagian[2] + 1);

            return match (true) {
                ($bagian[3] ?? null) === 'bukti' && isset($bagian[4]) => "{$label}: berkas bukti ke-".((int) $bagian[4] + 1).' harus berupa PDF.',
                ($bagian[3] ?? null) === 'bukti' => "{$label}: bukti dukung (PDF) belum diunggah.",
                default => "{$label}: {$pesanAsli}",
            };
        }

        if ($bagian[0] === 'evaluasi') {
            if (! isset($bagian[1])) {
                return $pesanAsli;
            }

            $teks = $this->rtlTriwulanBerjalan()->firstWhere('id', (int) $bagian[1])?->rtl_teks;
            $label = 'Evaluasi RTL'.($teks ? ' "'.Str::limit($teks, 40).'"' : '');

            return ($bagian[2] ?? null) === 'susunan_folder'
                ? "{$label}: {$pesanAsli}"
                : "{$label}: berkas bukti realisasi harus berupa PDF.";
        }

        if ($kunci === 'rtlBaru') {
            return 'Rencana Tindak Lanjut triwulan berikutnya: minimal satu poin RTL wajib diisi pada bulan terakhir triwulan.';
        }

        if ($bagian[0] === 'rtlBaru' && isset($bagian[1])) {
            return 'Rencana Tindak Lanjut poin #'.((int) $bagian[1] + 1).': teks rencana belum diisi (hapus poin bila tidak dipakai).';
        }

        if ($kunci === 'rtlBaruBatasWaktu') {
            return 'Rencana Tindak Lanjut: batas waktu belum diisi atau tidak valid.';
        }

        return $pesanAsli;
    }

    /**
     * Label blok kegiatan di daftar kekurangan, mis. 'Kegiatan #2 ("Pencacahan…")'
     * — nomor sesuai urutan blok di form, cuplikan uraian membantu bila bloknya banyak.
     */
    protected function labelBlokKegiatan(int $index): string
    {
        $uraian = trim($this->blocks[$index]['uraian_kegiatan'] ?? '');

        return 'Kegiatan #'.($index + 1).($uraian !== '' ? ' ("'.Str::limit($uraian, 40).'")' : '');
    }

    /**
     * Judul blok di form: "Kegiatan N", dengan N dihitung per KEGIATAN, bukan per baris —
     * tahapan lain dari kegiatan yang sama (poin RTL sama atau uraian sama, lihat
     * kegiatanSama()) yang tersimpan sebagai blok sendiri memakai nomor kegiatan
     * induknya + keterangan tahapannya, mis. "Kegiatan 1 · Tahapan Pelaksanaan".
     */
    public function judulBlokKegiatan(int $index): string
    {
        $nomor = 0;
        $induk = [];

        foreach ($this->blocks as $j => $blok) {
            $sama = ($blok['jenis'] ?? '') === 'survei_sensus' && filled($blok['id'] ?? null)
                ? collect($induk)->first(fn ($n, $k) => ($this->blocks[$k]['jenis'] ?? '') === 'survei_sensus' && $this->kegiatanSama($this->blocks[$k], $blok))
                : null;
            $induk[$j] = $sama ?? ++$nomor;

            if ($j === $index) {
                break;
            }
        }

        $block = $this->blocks[$index] ?? [];
        $tahapan = ($block['jenis'] ?? '') === 'survei_sensus' && filled($block['id'] ?? null) && filled($block['tahapan_survei'] ?? null)
            ? ' · Tahapan '.self::labelTahapan($block['tahapan_survei'])
            : '';

        return 'Kegiatan '.($induk[$index] ?? $index + 1).$tahapan;
    }

    /**
     * Simpan sementara sebagai draft — hanya kegiatan yang divalidasi (IKU + uraian +
     * jenis kegiatan), bukti capaian TIDAK wajib pada tahap ini. Kegiatan tersimpan
     * dengan status "draft" (belum diajukan ke Tim SAKIP); kendala-solusi, evaluasi
     * RTL, dan RTL baru baru divalidasi & disimpan saat benar-benar "Ajukan ke Tim SAKIP".
     */
    /* ---------- Versi baris, bentrok penyuntingan, dan unggahan setelah commit ---------- */

    /**
     * Sidik jari ISI baris yang ditulis formulir ini (bukan waktu ubah) — berubah bila
     * pengguna lain mengubah isinya atau statusnya. Aksi kecil milik pengguna sendiri
     * (mis. menyalin catatan bukti yang dihapus) tidak mengubah sidik ini.
     */
    protected static function sidikBaris(\Illuminate\Database\Eloquent\Model $baris): string
    {
        $isi = match (true) {
            $baris instanceof Kegiatan => [$baris->uraian_kegiatan, $baris->jenis, $baris->tahapan_survei, $baris->status_dokumen, $baris->rtl_evaluasi_id, $baris->nama_folder_kustom, $baris->iku_id, $baris->periode_id],
            $baris instanceof KendalaSolusiModel => [$baris->kendala, $baris->solusi, $baris->status_dokumen, $baris->status_verifikasi, $baris->iku_id, $baris->periode_id],
            $baris instanceof RtlEvaluasiModel => [$baris->rtl_teks, $baris->status_dokumen, $baris->status_verifikasi, $baris->iku_id, $baris->periode_id],
            $baris instanceof BagianKustomPoin => [(int) $baris->iku_id, (string) $baris->teks, $baris->nilai_kolom ?? [], (string) $baris->status_verifikasi, $baris->periode_id, $baris->bagian_kustom_id],
            default => throw new \InvalidArgumentException('Jenis baris tidak dikenal: '.$baris::class),
        };

        return sha1((string) json_encode($isi));
    }

    /**
     * Perbarui versi kegiatan yang baru saja diubah oleh aksi pengguna ini sendiri di
     * luar Simpan (mis. ganti nama folder lewat ✏️) — supaya simpanan berikutnya tidak
     * dianggap bentrok dengan perubahannya sendiri. Hanya baris yang memang dimuat
     * sebagai bisa-diedit.
     *
     * @param  iterable<int, Kegiatan>  $daftar
     */
    protected function segarkanVersiKegiatan(iterable $daftar): void
    {
        foreach ($daftar as $kegiatan) {
            if (array_key_exists($kegiatan->id, $this->versiDimuat['kegiatan'] ?? [])) {
                $this->versiDimuat['kegiatan'][$kegiatan->id] = self::sidikBaris($kegiatan->fresh() ?? $kegiatan);
            }
        }
    }

    /**
     * Id baris yang dikirim browser per jenis (hanya blok yang punya id).
     *
     * @return array<string, array<int, int>>
     */
    protected function idDikirimPerJenis(): array
    {
        $ambil = fn (array $daftar) => collect($daftar)->pluck('id')->filter(fn ($id) => filled($id))->map(fn ($id) => (int) $id)->values()->all();

        return [
            'kegiatan' => $ambil($this->blocks),
            'kendala' => $ambil($this->kendalaBlocks),
            'rtl' => $ambil($this->rtlBaru),
            'poin' => collect($this->bagianKustomBlocks)->flatMap(fn ($daftar) => $ambil((array) $daftar))->values()->all(),
        ];
    }

    /**
     * Semua id baris di formulir HARUS id yang dimuat server untuk IKU + periode ini —
     * id lain (dari request yang dimanipulasi) ditolak 403 SEBELUM apa pun disimpan
     * atau diunggah. Bukti Evaluasi RTL hanya untuk poin RTL triwulan berjalan form ini.
     */
    protected function pastikanIdFormSah(?Periode $periode): void
    {
        $id = $this->idDikirimPerJenis();
        $sah = [
            'kegiatan' => [...array_keys($this->versiDimuat['kegiatan'] ?? []), ...($this->versiDimuat['kegiatan_lain'] ?? [])],
            'kendala' => array_keys($this->versiDimuat['kendala'] ?? []),
            'rtl' => array_keys($this->versiDimuat['rtl'] ?? []),
        ];

        foreach ($sah as $jenis => $idSah) {
            if (array_diff($id[$jenis], $idSah) !== [] || count($id[$jenis]) !== count(array_unique($id[$jenis]))) {
                $this->tolakSimpan('id '.$jenis.' di formulir tidak sesuai data yang dimuat untuk IKU & periode ini', $periode);
            }
        }

        $evaluasiDenganBukti = collect($this->evaluasi)->filter(fn ($data) => ! empty($data['bukti'] ?? []))->keys()->map(fn ($k) => (int) $k);

        if ($evaluasiDenganBukti->isNotEmpty() && $evaluasiDenganBukti->diff($this->rtlTriwulanBerjalan()->pluck('id'))->isNotEmpty()) {
            $this->tolakSimpan('bukti evaluasi RTL untuk poin RTL di luar formulir ini', $periode);
        }
    }

    /** Catat percobaan simpan yang ditolak, lalu 403. */
    protected function tolakSimpan(string $alasan, ?Periode $periode): never
    {
        LogAktivitas::catat('isian_kegiatan.simpan', 'Percobaan simpan Isian Kegiatan oleh '.LogAktivitas::pelaku().' ditolak: '.$alasan.'.', [
            'iku_id' => $this->iku_id,
            'periode_id' => $periode?->id,
        ], LogAktivitas::STATUS_DITOLAK);

        abort(403);
    }

    /**
     * Dipanggil PALING AWAL di dalam transaksi simpan: kunci baris yang akan ditulis
     * (FOR UPDATE) lalu bandingkan isinya dengan versi saat dimuat. Bila ada yang sudah
     * diubah/dihapus pengguna lain, lempar KonflikPenyuntinganException — seluruh
     * transaksi batal sebelum apa pun ditulis atau diunggah.
     */
    protected function kunciDanPeriksaVersi(Periode $periode): void
    {
        $model = ['kegiatan' => Kegiatan::class, 'kendala' => KendalaSolusiModel::class, 'rtl' => RtlEvaluasiModel::class, 'poin' => BagianKustomPoin::class];
        $label = ['kegiatan' => 'Kegiatan', 'kendala' => 'Kendala & Solusi', 'rtl' => 'RTL triwulan berikutnya', 'poin' => 'Bagian Kustom'];
        $bentrok = [];
        $this->barisTerkunci = [];

        foreach ($this->idDikirimPerJenis() as $jenis => $ids) {
            $this->barisTerkunci[$jenis] = $ids === [] ? collect() : $model[$jenis]::whereKey($ids)->lockForUpdate()->get()->keyBy('id');

            foreach ($ids as $id) {
                // Kegiatan hanya-baca saat dimuat tidak ditulis ulang — tidak diperiksa.
                if ($jenis === 'kegiatan' && ! array_key_exists($id, $this->versiDimuat['kegiatan'] ?? [])) {
                    continue;
                }

                $baris = $this->barisTerkunci[$jenis]->get($id);

                if (! $baris || self::sidikBaris($baris) !== ($this->versiDimuat[$jenis][$id] ?? null)) {
                    $bentrok[$jenis][] = $this->labelBaris($jenis, $id, $baris) ?: $label[$jenis];
                }
            }
        }

        if ($bentrok !== []) {
            throw new KonflikPenyuntinganException($bentrok);
        }
    }

    protected function labelBaris(string $jenis, int $id, ?\Illuminate\Database\Eloquent\Model $baris): string
    {
        $teks = match ($jenis) {
            'kegiatan' => collect($this->blocks)->firstWhere('id', $id)['uraian_kegiatan'] ?? null,
            'kendala' => collect($this->kendalaBlocks)->firstWhere('id', $id)['kendala'] ?? null,
            'rtl' => collect($this->rtlBaru)->firstWhere('id', $id)['rtl_teks'] ?? null,
            'poin' => collect($this->bagianKustomBlocks)->flatten(1)->firstWhere('id', $id)['teks'] ?? null,
            default => null,
        };

        $nama = ['kegiatan' => 'Kegiatan', 'kendala' => 'Kendala', 'rtl' => 'RTL', 'poin' => 'Poin'][$jenis] ?? $jenis;

        return $nama.' "'.Str::limit(trim((string) $teks) ?: '(tanpa teks)', 60).'"'.($baris ? '' : ' (sudah dihapus)');
    }

    /**
     * Jalankan $simpan dalam satu transaksi dengan pengaman:
     * - versi baris dicek di awal (bentrok → batal seluruhnya, isian tetap di layar);
     * - bila transaksi batal karena sebab apa pun, keadaan formulir dikembalikan seperti
     *   sebelum simpan (id baris baru yang sempat diberikan tidak tertinggal → tidak ada
     *   data ganda saat simpan diulang) dan berkas lokal yang sempat disimpan dihapus;
     * - unggahan Google Drive (dan pekerjaan Drive lain) baru dijalankan SETELAH commit,
     *   masih di request yang sama — berkas tetap "langsung tersimpan ke Drive" begitu
     *   dipilih, tetapi transaksi yang batal tidak pernah meninggalkan berkas Drive yatim.
     *
     * @return bool false bila bentrok (pesan sudah ditampilkan)
     */
    protected function simpanDenganPengaman(Periode $periode, \Closure $simpan, array &$driveGagal): bool
    {
        $snapshot = [$this->blocks, $this->kendalaBlocks, $this->rtlBaru, $this->bagianKustomBlocks, $this->evaluasi];
        $this->antreanUnggah = [];
        $this->tugasSetelahCommit = [];
        $this->berkasLokalBaru = [];

        try {
            DB::transaction(function () use ($periode, $simpan) {
                $this->kunciDanPeriksaVersi($periode);
                $simpan();
            });
        } catch (\Throwable $e) {
            [$this->blocks, $this->kendalaBlocks, $this->rtlBaru, $this->bagianKustomBlocks, $this->evaluasi] = $snapshot;

            foreach ($this->berkasLokalBaru as $path) {
                Storage::disk('local')->delete($path);
            }

            $this->antreanUnggah = [];
            $this->tugasSetelahCommit = [];
            $this->berkasLokalBaru = [];

            if (! $e instanceof KonflikPenyuntinganException) {
                throw $e;
            }

            $this->tanganiBentrok($e, $periode);

            return false;
        }

        $this->berkasLokalBaru = [];
        $this->bentrok = [];
        $idDiantre = collect($this->antreanUnggah)->map(fn ($item) => $item['berkas']->id)->all();

        $this->jalankanAntreanUnggah($driveGagal);
        $this->jalankanTugasSetelahCommit();
        $this->susulkanBerkasBelumDiDrive($driveGagal, $idDiantre);

        return true;
    }

    protected function tanganiBentrok(KonflikPenyuntinganException $e, Periode $periode): void
    {
        $this->bentrok = $e->bentrok;
        $daftar = collect($e->bentrok)->flatten()->take(6)->implode('; ');

        LogAktivitas::catat('isian_kegiatan.simpan', 'Penyimpanan Isian Kegiatan oleh '.LogAktivitas::pelaku().' dibatalkan karena bentrok: data sudah diubah pengguna lain sejak dimuat ('.$daftar.'). Tidak ada data yang diubah.', [
            'iku_id' => $this->iku_id,
            'periode_id' => $periode->id,
            'perubahan' => ['bentrok' => $e->bentrok],
        ], LogAktivitas::STATUS_DITOLAK);

        $pesan = 'Belum tersimpan — sebagian isian ini sudah diubah pengguna lain sejak halaman Anda dimuat ('.$daftar.'). Isian Anda masih ada di layar dan TIDAK ada yang tersimpan (termasuk berkas yang baru dipilih). Salin perubahan Anda bila perlu, lalu klik "Muat data terbaru" pada peringatan di atas formulir.';

        $this->addError('bentrok', $pesan);
        $this->dispatch('notify', type: 'error', message: $pesan);
    }

    /**
     * Muat ulang dari database HANYA bagian yang bentrok. Baris BARU (belum tersimpan)
     * yang sudah diisi tetap dipertahankan di bawah data terbaru.
     */
    public function muatUlangBagianBentrok(): void
    {
        $jenis = array_keys($this->bentrok);

        if (in_array('kegiatan', $jenis, true)) {
            $baru = collect($this->blocks)->filter(fn ($b) => blank($b['id'] ?? null) && (trim((string) ($b['uraian_kegiatan'] ?? '')) !== '' || ! empty($b['bukti'])))->values()->all();
            $this->muatBlocksKegiatan();
            $this->blocks = $baru === [] ? $this->blocks : [...collect($this->blocks)->filter(fn ($b) => filled($b['id']))->values()->all(), ...$baru];
        }

        if (in_array('kendala', $jenis, true)) {
            $baru = collect($this->kendalaBlocks)->filter(fn ($b) => blank($b['id'] ?? null) && (trim((string) $b['kendala']) !== '' || trim((string) $b['solusi']) !== ''))->values()->all();
            $this->muatKendalaBlocks();
            $this->kendalaBlocks = [...collect($this->kendalaBlocks)->filter(fn ($b) => filled($b['id'] ?? null))->values()->all(), ...$baru] ?: [$this->emptyKendalaBlock()];
        }

        if (in_array('rtl', $jenis, true)) {
            $baru = collect($this->rtlBaru)->filter(fn ($b) => blank($b['id'] ?? null) && trim((string) ($b['rtl_teks'] ?? '')) !== '')->values()->all();
            $this->muatRtlBaruBlocks();
            $this->rtlBaru = [...collect($this->rtlBaru)->filter(fn ($b) => filled($b['id'] ?? null))->values()->all(), ...$baru] ?: $this->rtlBaru;
        }

        if (in_array('poin', $jenis, true)) {
            $baru = collect($this->bagianKustomBlocks)->map(fn ($daftar) => collect((array) $daftar)->filter(fn ($b) => blank($b['id'] ?? null) && $this->blokBagianKustomTerisi($b))->values()->all());
            $this->muatBagianKustomBlocks();

            foreach ($baru as $bagianId => $daftarBaru) {
                if ($daftarBaru !== []) {
                    $lama = collect($this->bagianKustomBlocks[$bagianId] ?? [])->filter(fn ($b) => filled($b['id'] ?? null))->values()->all();
                    $this->bagianKustomBlocks[$bagianId] = [...$lama, ...$daftarBaru];
                }
            }
        }

        $this->bentrok = [];
        $this->resetErrorBag('bentrok');
        $this->lupakanCachePeriodeIku();
        $this->dispatch('notify', type: 'info', message: 'Data terbaru dimuat. Isian baru yang belum tersimpan tetap ada — periksa lalu simpan kembali.');
    }

    /**
     * Simpan berkas unggahan ke disk lokal dan catat path-nya supaya ikut dihapus bila
     * transaksi batal.
     */
    protected function simpanBerkasLokal($file, string $folder): string
    {
        $path = $file->store($folder, 'local');
        $this->berkasLokalBaru[] = $path;

        return $path;
    }

    /**
     * Tunda unggahan Drive satu berkas yang baru dicatat di transaksi simpan sampai
     * transaksi itu berhasil (lihat simpanDenganPengaman()).
     */
    protected function antreUnggah(Berkas $berkas, \Illuminate\Database\Eloquent\Model $ref, string $namaAsli, string $konteks, string $labelGagal): void
    {
        $this->antreanUnggah[] = ['berkas' => $berkas, 'ref' => $ref, 'nama_asli' => $namaAsli, 'konteks' => $konteks, 'label_gagal' => $labelGagal];
    }

    /**
     * Unggah ke Drive berkas yang sudah tercatat di database (setelah commit) lewat
     * Berkas::unggahKeDrive() — jalur yang sama dengan tombol "Coba lagi" & sinkron
     * susulan: berkas diberi penanda kode berkasnya, hasil Drive + log "tersalin"
     * dicatat dalam satu transaksi kecil. Gagal unggah tidak membatalkan apa pun:
     * berkas tetap aman lokal, tampil "belum di Drive", dan disusulkan kemudian.
     */
    protected function jalankanAntreanUnggah(array &$driveGagal): void
    {
        foreach ($this->antreanUnggah as $item) {
            /** @var Berkas $berkas */
            $berkas = $item['berkas'];

            try {
                $this->streamProgresUnggah($item['nama_asli'], $item['konteks']);
                $berkas->setRelation('ref', $item['ref'])->unggahKeDrive();
                $this->notifikasiHasilUnggah($item['nama_asli'], $item['konteks'], true);
            } catch (\Throwable $e) {
                Log::warning("Gagal mengunggah {$item['konteks']} ke Google Drive, disimpan lokal saja: ".$e->getMessage());
                $driveGagal[] = $item['label_gagal'];
                $this->notifikasiHasilUnggah($item['nama_asli'], $item['konteks'], false, $e->getMessage());
            }
        }

        $this->antreanUnggah = [];
    }

    /**
     * Pekerjaan Drive non-unggah yang ditunda sampai commit (lihat $tugasSetelahCommit).
     * Kegagalannya dicatat dan tidak membatalkan simpanan yang sudah berhasil.
     */
    protected function jalankanTugasSetelahCommit(): void
    {
        foreach ($this->tugasSetelahCommit as $tugas) {
            try {
                $tugas();
            } catch (\Throwable $e) {
                Log::warning('Pekerjaan Google Drive setelah simpan gagal: '.$e->getMessage());
            }
        }

        $this->tugasSetelahCommit = [];
    }

    /**
     * Kegiatan milik formulir ini (id dimuat server + IKU & periode cocok di database),
     * atau null.
     */
    protected function kegiatanMilikForm(int $id): ?Kegiatan
    {
        $dimuat = array_key_exists($id, $this->versiDimuat['kegiatan'] ?? []) || in_array($id, $this->versiDimuat['kegiatan_lain'] ?? [], true);
        $periode = $this->iku_id ? $this->periodeSaatIni() : null;

        if (! $dimuat || ! $periode) {
            return null;
        }

        return Kegiatan::where('iku_id', $this->iku_id)->where('periode_id', $periode->id)->find($id);
    }

    /**
     * Periksa semua id di formulir lebih dulu (tanpa membuat apa pun), baru kemudian
     * ambil/buat baris periode — permintaan yang ditolak tidak meninggalkan periode baru.
     */
    protected function periodeUntukSimpan(): Periode
    {
        $periode = Periode::where('tahun', $this->tahun)->where('bulan', $this->bulan)->first();

        $this->pastikanIdFormSah($periode);
        $this->pastikanPoinBagianKustomSah($periode);

        return $periode ?? Periode::firstOrCreate(
            ['tahun' => $this->tahun, 'bulan' => $this->bulan],
            [
                'triwulan' => $this->triwulanDari($this->bulan),
                'bulan_ke' => $this->bulanKeDari($this->bulan),
                'flag_bulan_terlewat' => $this->isBulanTerlewat(),
            ]
        );
    }

    /**
     * @param  bool  $otomatis  true bila dipicu simpanOtomatisSetelahUnggah() — hanya
     *                          membedakan pesan yang tampil ke pengguna.
     */
    public function simpanDraft(bool $otomatis = false): void
    {
        if ($this->formTerkunciDisetujuiFresh()) {
            $this->dispatch('notify', type: 'error', message: 'Isian ini sudah disetujui dan terkunci — hubungi Tim SAKIP untuk membuka kembali bila perlu revisi.');

            return;
        }

        if ($this->formTerkunciSedangDitanganiFresh()) {
            $this->dispatch('notify', type: 'error', message: 'Isian ini sedang ditangani Tim SAKIP dan tidak bisa diubah sampai verifikasi selesai.');

            return;
        }

        $aturan = [
            'tahun' => ['required', 'integer', 'min:2020', 'max:2100'],
            'bulan' => ['required', 'integer', 'min:1', 'max:12'],
            'iku_id' => ['required', $this->aturanIkuValid()],
            'blocks.*.uraian_kegiatan' => ['required', 'string', 'max:1000'],
            'blocks.*.jenis' => ['required', 'in:bukan_survei_sensus,survei_sensus'],
            'blocks.*.tahapan_survei' => [
                'nullable',
                'required_if:blocks.*.jenis,survei_sensus',
                $this->aturanTahapanTidakGanda(),
            ],
            'blocks.*.nama_folder' => ['nullable', 'string', 'max:150'],
        ] + $this->aturanTahapanDanSubfolder();

        try {
            // Simpan otomatis setelah unggah tidak boleh gagal hanya karena ada blok
            // lain yang uraiannya belum diisi — blok seperti itu memang dilewati
            // simpanBagianIsian() dan dipasang lagi di layar oleh muatUlangForm().
            $this->validate($otomatis ? $this->aturanBlokTerisiSaja($aturan) : $aturan, $this->messages(), $this->validationAttributes());

            // Pisah subfolder wajib minimal dua folder tujuan — dicek sebelum apa pun
            // tersimpan, jadi berkas yang menunggu tidak terunggah ke susunan tak sah.
            if ($galatSusunan = $this->galatSusunanFolder(hanyaBlokTerisi: $otomatis)) {
                throw ValidationException::withMessages($galatSusunan);
            }
        } catch (ValidationException $e) {
            $this->dispatch('notify', type: 'error', message: $otomatis
                ? 'Berkas belum terunggah ke Google Drive — lengkapi isian yang ditandai merah, lalu klik "Simpan Draft".'
                : 'Draf gagal disimpan — lengkapi data yang wajib diisi lebih dulu.');

            throw $e;
        }

        $periode = $this->periodeUntukSimpan();
        $iku = MasterIku::findOrFail($this->iku_id);
        $folderService = app(FolderStructureService::class);

        // Diisi setiap kali satu berkas GAGAL disinkron ke Drive — sama seperti
        // ajukanIsian(), lihat catatan di sana.
        $driveGagal = [];
        $belumTersimpan = $otomatis ? $this->catatIsianBelumTersimpan() : [];

        $tersimpan = $this->simpanDenganPengaman(
            $periode,
            fn () => $this->simpanBagianIsian($periode, $iku, $folderService, ajukan: false),
            $driveGagal,
        );

        if (! $tersimpan) {
            return;
        }

        if ($otomatis) {
            // Tanpa reload halaman — pengguna sedang mengisi; form dimuat ulang dari
            // database di tempat supaya berkas tadi tampil sebagai bukti tersimpan.
            $this->lupakanCachePeriodeIku();
            $this->muatUlangForm($belumTersimpan);
            $this->terakhirDisimpan = now()->format('H:i');

            if ($driveGagal === []) {
                $this->dispatch('notify', type: 'success', message: 'Berkas sudah tersimpan ke Google Drive — isian di halaman ini ikut disimpan sebagai draf.');
            } else {
                $this->dispatch('notify', type: 'warning', message: 'Isian tersimpan sebagai draf, tetapi '.count($driveGagal).' berkas belum berhasil masuk Google Drive ('.($this->alasanDriveGagal ?? 'gangguan koneksi').') — otomatis dicoba lagi setiap kali Anda menyimpan.');
            }

            return;
        }

        session()->flash('status', $this->pengajuanBisaDiedit()
            ? 'Perubahan pada pengajuan berhasil disimpan — isian tetap berstatus diajukan. Kegiatan BARU yang ditambahkan baru terkirim ke Tim SAKIP setelah "Ajukan ke Tim SAKIP" diklik.'
            : 'Draf berhasil disimpan — kegiatan, kendala & solusi, evaluasi RTL, dan RTL berikutnya yang sudah diisi ikut tersimpan. Lengkapi bagian yang belum lengkap lalu ajukan ke Tim SAKIP saat siap.');

        if (! empty($driveGagal)) {
            session()->flash('driveGagal', $driveGagal);
            session()->flash('driveGagalAlasan', $this->alasanDriveGagal);
        }

        // Jam simpan dibawa lewat flash karena halaman dimuat ulang penuh di bawah —
        // dibaca lagi di mount() untuk keterangan "✓ Semua perubahan tersimpan".
        // adaPerubahan dimatikan dulu supaya redirect tidak memicu peringatan
        // "perubahan belum disimpan" di browser.
        $this->adaPerubahan = false;
        session()->flash('terakhirDisimpan', now()->format('H:i'));

        $this->lupakanCachePeriodeIku();

        // Reload halaman penuh — lihat catatan senada di akhir ajukanIsian(). Query
        // string iku_id/tahun/bulan SENGAJA disertakan supaya mount() langsung memuat
        // ulang IKU yang sama (lihat mount()) — tanpa ini, draf yang baru saja
        // tersimpan (kendala/solusi, evaluasi RTL, RTL berikutnya) akan tampak "hilang"
        // begitu halaman terbuka lagi, padahal sebenarnya sudah tersimpan di database,
        // hanya belum dimuat ulang ke form karena IKU belum otomatis terpilih lagi.
        $this->redirect(route('pengisian.index', [
            'iku_id' => $this->iku_id,
            'tahun' => $this->tahun,
            'bulan' => $this->bulan,
        ]));
    }

    /**
     * Logika penyimpanan bersama antara simpanDraft() dan ajukanIsian() — supaya
     * mengklik "Simpan Draft" TIDAK diam-diam membuang kendala & solusi, evaluasi
     * RTL, RTL berikutnya, dan bukti yang sudah diisi/diunggah (bug produksi:
     * hanya kolom Kegiatan yang tersimpan, sisanya baru benar-benar tersimpan saat
     * "Ajukan ke Tim SAKIP" diklik, padahal pengguna mengira "Simpan Draft" sudah
     * menyimpan SEMUA isian di halaman).
     *
     * Saat $ajukan === false (dipanggil dari simpanDraft()): kegiatan/kendala &
     * solusi/evaluasi RTL/RTL berikutnya/bagian kustom yang sudah diisi tetap
     * disimpan & bukti tetap diunggah, TAPI status kegiatan/capaian TIDAK
     * dipindahkan ke "diajukan" — supaya isian tetap dianggap draft dan tidak
     * masuk antrean verifikasi Tim SAKIP sebelum benar-benar diajukan.
     */
    protected function simpanBagianIsian(Periode $periode, MasterIku $iku, FolderStructureService $folderService, bool $ajukan): void
    {
        // Unggahan ke Google Drive TIDAK dilakukan di sini (di dalam transaksi) —
        // berkas dicatat di database lalu diantrekan lewat antreUnggah(), dan baru
        // diunggah setelah transaksi berhasil (simpanDenganPengaman()), masih di request
        // yang sama. Bila transaksi batal, tidak ada berkas Drive yatim dan berkas
        // lokalnya ikut dihapus. Berkas lama yang belum di Drive disusulkan setelahnya.
        $capaian = Capaian::firstOrCreate([
            'iku_id' => $this->iku_id,
            'periode_id' => $periode->id,
        ]);

        $adaKegiatanDiajukan = false;
        $adaPerbaikanLainDiajukan = false;

        // 1) Kegiatan + bukti capaian (bukti melekat langsung ke kegiatan, RF-23)
        foreach ($this->blocks as &$block) {
            // Blok yang sudah diajukan/diverifikasi/disetujui ditampilkan hanya-baca
            // di form (lihat muatBlocksKegiatan()) — tidak boleh ikut tertimpa di sini.
            // Status dibaca dari baris database yang sudah dikunci, bukan dari browser.
            $kegiatanTersimpan = filled($block['id'] ?? null) ? ($this->barisTerkunci['kegiatan'] ?? collect())->get((int) $block['id']) : null;

            if (filled($block['id'] ?? null) && (! $kegiatanTersimpan || ! array_key_exists((int) $block['id'], $this->versiDimuat['kegiatan'] ?? []) || $this->blokTerkunci($kegiatanTersimpan->status_dokumen))) {
                continue;
            }

            if (blank($block['id'] ?? null) && $this->blokTerkunci($block['status_dokumen'] ?? null)) {
                continue;
            }

            // Blok kosong (baru ditambah lewat "Tambah Kegiatan" tapi belum diisi sama
            // sekali) dilewati saat draft — beda dari ajukanIsian() yang memvalidasi
            // SEMUA blok wajib terisi, draft boleh punya blok kosong tertinggal.
            if (! $ajukan && trim($block['uraian_kegiatan']) === '') {
                continue;
            }

            // Satu blok bisa memilih beberapa tahapan sekaligus — tiap tahapan disimpan
            // sebagai baris Kegiatan sendiri (Tim SAKIP tetap memverifikasi per tahapan).
            // Tahapan pertama memakai baris blok ini (block['id']); tahapan berikutnya
            // dibuat baru. Kegiatan bukan survei cukup satu baris tanpa tahapan.
            $daftarTahapan = $block['jenis'] === 'survei_sensus' ? ($this->tahapanDipilih($block) ?: [null]) : [null];

            // Nama, tahapan & subfolder tiap berkas dihitung dulu dari blok utuh, sama
            // persis dengan yang tampil di chip berkas (namaBuktiBaruKegiatan()).
            $rencanaBerkas = collect(array_values($block['bukti']))->map(fn ($file) => [
                'file' => $file,
                'tahapan' => $this->tahapanBerkasBaru($block, $file),
                'nama' => $this->namaBuktiBaruKegiatan($block, $file),
                'subfolder' => $this->subfolderBerkasBaru($block, $file),
            ]);

            foreach ($daftarTahapan as $ti => $tahapan) {
                $utama = $ti === 0;
                $namaFolderAuto = $this->namaFolderOtomatis(array_merge($block, ['tahapan_survei' => $tahapan ?? '']));

                if ($utama && $block['id']) {
                    // UPDATE kegiatan draft/dikembalikan yang sudah ada, bukan create baru —
                    // supaya menyimpan/mengajukan ulang isian yang sudah ada tidak membuat
                    // baris duplikat. drive_folder_id SENGAJA tidak disentuh (dipakai ulang
                    // oleh FolderStructureService, lihat catatan di model Kegiatan).
                    $kegiatan = $kegiatanTersimpan;
                    $kegiatan->update([
                        'rtl_evaluasi_id' => $this->rtlEvaluasiIdUntukBlock($block),
                        'uraian_kegiatan' => $block['uraian_kegiatan'],
                        'jenis' => $block['jenis'],
                        'tahapan_survei' => $tahapan,
                        'nama_folder_auto' => $namaFolderAuto,
                    ]);

                    // Tautan RTL berubah/dilepas: salinan bukti lama di Evaluasi-RTL poin
                    // sebelumnya dibuang ke Sampah, lalu disalin ke poin yang baru (bila ada).
                    // Pekerjaan Drive ini dijalankan setelah transaksi berhasil.
                    if ($kegiatan->wasChanged('rtl_evaluasi_id')) {
                        $idKegiatan = $kegiatan->id;
                        $this->tugasSetelahCommit[] = function () use ($idKegiatan) {
                            if ($kegiatanTerbaru = Kegiatan::find($idKegiatan)) {
                                app(SinkronBuktiRtlService::class)->sinkronkanKegiatan($kegiatanTerbaru);
                            }
                        };
                    }
                } else {
                    $kegiatan = Kegiatan::create([
                        'iku_id' => $this->iku_id,
                        'rtl_evaluasi_id' => $this->rtlEvaluasiIdUntukBlock($block),
                        'periode_id' => $periode->id,
                        'uraian_kegiatan' => $block['uraian_kegiatan'],
                        'jenis' => $block['jenis'],
                        'tahapan_survei' => $tahapan,
                        'nama_folder_auto' => $namaFolderAuto,
                        'status_dokumen' => Kegiatan::STATUS_DRAFT,
                    ]);

                    if ($utama) {
                        $block['id'] = $kegiatan->id;
                    }
                }

                // Nama folder pilihan Ketua Tim (kosong = rekomendasi sistem): tahapan
                // utama dari nama_folder, tahapan tambahan dari nama_folder_tahapan.
                // Bila folder kegiatan ini sudah ada di Drive,
                // namanya ikut diganti di sana.
                $hasilFolder = $kegiatan->gantiNamaFolder($utama ? ($block['nama_folder'] ?? '') : ($block['nama_folder_tahapan'][$tahapan] ?? ''));
                if ($hasilFolder['peringatan']) {
                    $this->dispatch('notify', type: 'warning', message: $hasilFolder['peringatan']);
                }

                if ($ajukan) {
                    // draft→diajukan maupun dikembalikan→diajukan, keduanya diizinkan
                    // (lihat Kegiatan::TRANSITIONS). Kegiatan yang SUDAH diajukan dan sedang
                    // disunting ulang (pengajuanBisaDiedit()) cukup diperbarui isinya.
                    if ($kegiatan->status_dokumen !== Kegiatan::STATUS_DIAJUKAN) {
                        $kegiatan->ajukan();
                    }
                    $adaKegiatanDiajukan = true;
                }

                foreach ($rencanaBerkas->where('tahapan', $tahapan) as $rencana) {
                    $file = $rencana['file'];
                    $namaBerkas = $rencana['nama'];
                    $subfolder = $rencana['subfolder'] !== '' ? $rencana['subfolder'] : null;
                    $path = $this->simpanBerkasLokal($file, 'bukti-capaian');

                    $berkas = Berkas::create([
                        'ref_id' => $kegiatan->id,
                        'ref_type' => Kegiatan::class,
                        'kategori' => 'capaian',
                        'nama_file' => $namaBerkas.'.pdf',
                        'subfolder' => $subfolder,
                        'path' => $path,
                        'status_verifikasi' => 'menunggu',
                    ]);

                    // Gagal unggah ke Drive tidak menggagalkan penyimpanan — berkas tetap
                    // aman di disk lokal sebagai cadangan dan disusulkan kemudian.
                    $this->antreUnggah(
                        $berkas,
                        $kegiatan,
                        $file->getClientOriginalName(),
                        'bukti kegiatan',
                        "{$file->getClientOriginalName()} (bukti kegiatan: {$block['uraian_kegiatan']})",
                    );
                }
            }

            $block['tahapan_tambahan'] = [];

            // Berkas yang baru saja tersimpan di atas dipindah ke existing_bukti supaya
            // tidak dicoba diunggah ulang bila simpanBagianIsian() dipanggil lagi dalam
            // request yang sama (tidak terjadi saat ini, tapi jaga-jaga) dan supaya form
            // yang dimuat ulang tidak menampilkan input unggah kosong lagi tanpa alasan.
            $block['bukti'] = [];
        }
        unset($block);

        // 2) Kendala & Solusi (blok kosong dilewati — bagian ini opsional per periode).
        // UPDATE, bukan create, bila blok ini punya id (pasangan lama yang DRAFT
        // milik sendiri ATAU ditolak Tim SAKIP dan sedang diperbaiki — lihat
        // muatKendalaBlocks()), supaya tidak duplikat. status_verifikasi & catatan
        // SENGAJA TIDAK direset ke "menunggu" — tanda "Tidak Sesuai" + alasannya
        // tetap tampil ke Tim SAKIP sampai mereka sendiri menandainya ulang
        // (tandaiKendalaSesuai()/tandaiKendalaTolak()), supaya mereka masih ingat
        // apa yang salah sebelumnya saat memeriksa perbaikan ini. Baru kosong
        // begitu ditandai "Sesuai". status_dokumen HANYA disentuh saat benar-benar
        // mengajukan ($ajukan) — pola sama persis dengan RTL berikutnya di langkah
        // 4 di bawah — supaya pasangan ini langsung terkunci hanya-baca (lihat
        // kendalaAktif()) begitu diajukan, bukan tetap bisa diedit bebas.
        foreach ($this->kendalaBlocks as &$block) {
            if (trim($block['kendala']) === '' && trim($block['solusi']) === '') {
                continue;
            }

            if ($block['id'] ?? null) {
                $dataUpdate = [
                    'kendala' => $block['kendala'],
                    'solusi' => $block['solusi'] ?: null,
                ];

                if ($ajukan) {
                    $dataUpdate['status_dokumen'] = KendalaSolusiModel::STATUS_DIAJUKAN;
                }

                KendalaSolusiModel::whereKey($block['id'])->update($dataUpdate);
            } else {
                $model = KendalaSolusiModel::create([
                    'iku_id' => $this->iku_id,
                    'periode_id' => $periode->id,
                    'kendala' => $block['kendala'],
                    'solusi' => $block['solusi'] ?: null,
                    'status_dokumen' => $ajukan ? KendalaSolusiModel::STATUS_DIAJUKAN : KendalaSolusiModel::STATUS_DRAFT,
                ]);
                $block['id'] = $model->id;
            }

            $adaPerbaikanLainDiajukan = true;
        }
        unset($block);

        // 3) Evaluasi RTL triwulan sebelumnya — cukup bukti realisasi (SEMUA poin wajib
        // punya bukti bila sedang mengajukan pada bulan terakhir triwulan, dicek di
        // buatValidator()); baris tanpa bukti baru dilewati begitu saja di sini.
        foreach ($this->evaluasi as $rtlId => &$data) {
            if (empty($data['bukti'])) {
                continue;
            }

            // Hanya poin RTL milik IKU & triwulan form ini — id dari browser tidak dipercaya.
            if (! $this->rtlTriwulanBerjalan()->contains('id', (int) $rtlId)) {
                continue;
            }

            $poin = RtlEvaluasiModel::with(['periode', 'masterIku'])->findOrFail($rtlId);
            $namaBerkasDasar = 'Evaluasi RTL '.$poin->rtl_teks;
            $adaPerbaikanLainDiajukan = true;

            // status_verifikasi & catatan SENGAJA TIDAK direset ke "menunggu" di sini
            // walau bukti baru baru saja diunggah — tanda "Tidak Sesuai" + alasannya
            // TETAP tampil ke Tim SAKIP sampai mereka sendiri yang menandainya ulang
            // (tandaiRtlSesuai()/tandaiRtlTolak()), supaya mereka masih ingat apa yang
            // salah sebelumnya saat memeriksa bukti baru ini. Baru kosong begitu Tim
            // SAKIP menandai "Sesuai". Sama seperti bukti Kegiatan (Berkas lama yang
            // "ditolak" juga tidak pernah direset otomatis di sini).

            $jumlahBuktiLama = $poin->berkas()->count();

            foreach (array_values($data['bukti']) as $fi => $file) {
                $namaBerkas = $this->namaBuktiBaruAtauDefault($file, $this->namaDefaultBuktiBaru($namaBerkasDasar, $jumlahBuktiLama, $fi, count($data['bukti'])));
                $path = $this->simpanBerkasLokal($file, 'bukti-evaluasi-rtl');
                $subfolder = $this->subfolderBerkasBaruRtl((int) $rtlId, $file);

                $berkas = Berkas::create([
                    'ref_id' => $poin->id,
                    'ref_type' => RtlEvaluasiModel::class,
                    'kategori' => 'evaluasi_rtl',
                    'nama_file' => $namaBerkas.'.pdf',
                    // Tahapan & subfolder tujuan (Evaluasi-RTL/<poin>/<tahapan>/<subfolder>),
                    // dibaca ulang Berkas::unggahKeDrive() — juga saat dicoba lagi.
                    'tahapan' => $this->tahapanBerkasBaruRtl((int) $rtlId, $file),
                    'subfolder' => $subfolder !== '' ? $subfolder : null,
                    'periode_id' => $periode->id,
                    'path' => $path,
                    'status_verifikasi' => 'menunggu',
                ]);

                $this->antreUnggah(
                    $berkas,
                    $poin,
                    $file->getClientOriginalName(),
                    'bukti evaluasi RTL',
                    "{$file->getClientOriginalName()} (bukti evaluasi RTL)",
                );
            }

            $data['bukti'] = [];
        }
        unset($data);

        // 4) RTL triwulan berikutnya — hanya boleh diisi di bulan terakhir triwulan
        // berjalan (dicek jugu di buatValidator() saat mengajukan). TIDAK LAGI
        // digerbang pada "belum pernah ditetapkan": begitu batch pertama sudah
        // ditetapkan, poin-poin lamanya tampil hanya-baca di blade (tidak pernah
        // masuk $rtlBaru lagi kecuali ditolak Tim SAKIP), tapi Ketua Tim tetap boleh
        // menambah poin BARU ke batch yang sama sebelum Tim SAKIP menyelesaikan
        // pemeriksaan — sama seperti Kegiatan/Kendala & Solusi/Bagian Kustom yang
        // juga tetap bisa ditambah selagi Capaian masih "diajukan" (lihat addBlock()).
        // Poin dengan teks masih kosong dilewati saat draft (belum ada validator yang
        // mewajibkannya di sini).
        if ($this->rtlBaruBisaDiisi()) {
            $sudahAda = $this->rtlTriwulanBerikutnyaSudahAda();

            $target = $this->targetTriwulanBerikutnya();
            $bulanPertamaTarget = ($target['triwulan'] - 1) * 3 + 1;

            $periodeTarget = Periode::firstOrCreate(
                ['tahun' => $target['tahun'], 'bulan' => $bulanPertamaTarget],
                ['triwulan' => $target['triwulan'], 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]
            );

            // RF-33: "RTL untuk Oktober, November, dan Desember" — satu keterangan bulan
            // yang sama untuk SELURUH poin dalam triwulan ini (bukan per poin).
            $namaBulanTarget = collect($this->bulanBulanTarget())->map(fn ($b) => $this->namaBulanIndo($b));
            $berlakuBulan = 'RTL untuk '.$namaBulanTarget->join(', ', ', dan ');

            // PIC SELALU tim penanggung jawab IKU terkini (dibaca ulang dari DB, BUKAN
            // dari properti publik yang bisa dimanipulasi klien) -- rtl_evaluasi.pic
            // tetap satu kolom teks, beberapa tim digabung dipisah koma, format yang
            // sama dengan MasterIku::sinkronkanTim().
            $namaTimIku = $this->ikuTerpilih()?->namaTimList() ?? [];
            $picTim = $namaTimIku !== [] ? implode(', ', $namaTimIku) : null;

            foreach ($this->rtlBaru as &$blok) {
                if (trim($blok['rtl_teks'] ?? '') === '') {
                    continue;
                }

                // Poin RTL berikutnya (baru maupun perbaikan) ikut menjadi alasan Capaian
                // kembali "diajukan" — tanpa ini, menambah RTL berikutnya pada isian yang
                // sudah "diverifikasi" membuat poinnya "menunggu" tapi Capaian tetap
                // "diverifikasi", sehingga Tim SAKIP tidak bisa memeriksanya (lihat
                // VerifikasiCapaian::rtlBerikutnyaBisaDiverifikasi()).
                $adaPerbaikanLainDiajukan = true;

                // Blok dengan id terisi berarti poin lama — baik yang DITOLAK Tim SAKIP
                // (dimuat lewat rtlBerikutnyaDitolak()) MAUPUN draft milik sendiri yang
                // belum pernah diajukan (dimuat lewat rtlBerikutnyaDraftSendiri(), lihat
                // muatRtlBaruBlocks()) — di-UPDATE di tempat, BUKAN dibuat baris duplikat.
                // Pola sama seperti BagianKustomPoin di langkah 5 di bawah.
                if (! empty($blok['id'])) {
                    $dataUpdate = [
                        'rtl_teks' => $blok['rtl_teks'],
                        'berlaku_bulan' => $berlakuBulan,
                        'pic' => $picTim,
                        'batas_waktu' => $this->rtlBaruBatasWaktu,
                    ];

                    // status_dokumen/status_verifikasi/catatan HANYA disentuh saat benar-
                    // benar mengajukan ($ajukan) — draft yang disimpan ulang (baik draft
                    // sendiri maupun poin yang ditolak sedang diperbaiki) tetap TIDAK
                    // dianggap "sudah ditetapkan" (lihat rtlTriwulanBerikutnyaSudahAda())
                    // dan alasan penolakan lama (bila ada) tetap tampil sampai benar-benar
                    // diajukan ulang.
                    if ($ajukan) {
                        $dataUpdate['status_dokumen'] = RtlEvaluasiModel::STATUS_DIAJUKAN;
                        $dataUpdate['status_verifikasi'] = 'menunggu';
                        $dataUpdate['catatan'] = null;
                    }

                    RtlEvaluasiModel::whereKey($blok['id'])->update($dataUpdate);

                    continue;
                }

                $rtl = RtlEvaluasiModel::create([
                    'iku_id' => $this->iku_id,
                    'periode_id' => $periodeTarget->id,
                    'rtl_teks' => $blok['rtl_teks'],
                    'berlaku_bulan' => $berlakuBulan,
                    'pic' => $picTim,
                    'batas_waktu' => $this->rtlBaruBatasWaktu,
                    'status_dokumen' => $ajukan ? RtlEvaluasiModel::STATUS_DIAJUKAN : RtlEvaluasiModel::STATUS_DRAFT,
                ]);
                $blok['id'] = $rtl->id;
            }
            unset($blok);
        }

        // 5) Bagian kustom (mis. Manajemen Risiko) — poin kosong dilewati, sama
        // seperti Kendala & Solusi; bukti sudah dipastikan wajib lewat validasi saat
        // mengajukan. UPDATE, bukan create, bila blok ini punya id (poin lama yang
        // dimuat lewat muatBagianKustomBlocks() untuk diperbaiki teksnya) — supaya
        // tidak duplikat, mengikuti pola yang sama dengan Kendala & Solusi di atas.
        // Log aktivitas poin & bukti ditulis di dalam transaksi pemanggil (simpanDraft/
        // ajukanIsian) — ikut batal bila penyimpanan batal.
        $korelasiBagianKustom = LogAktivitas::korelasiBaru();

        foreach ($this->bagianKustomAktif() as $bagian) {
            foreach ($this->bagianKustomBlocks[$bagian->id] ?? [] as $i => $blok) {
                if (! $this->blokBagianKustomTerisi($blok)) {
                    continue;
                }

                $triwulanIsian = BagianKustom::triwulanDariBulan($this->bulan);

                if ($blok['id'] ?? null) {
                    // Poin dicocokkan ke bagian + IKU + periode form ini dan konteks Isian
                    // Kegiatan — bukan hanya id dari request (sudah diperiksa juga di
                    // pastikanPoinBagianKustomSah() sebelum transaksi ini dimulai).
                    $poin = $this->poinBagianKustomForm($bagian->id, (int) $blok['id'], $periode);
                    abort_unless($poin, 403);

                    // Nilai kolom yang tidak tampil di form (nonaktif / belum berlaku)
                    // dipertahankan dari data tersimpan.
                    $nilaiKolom = $bagian->bersihkanNilaiKolom($blok['kolom'] ?? [], $poin->nilai_kolom, $this->tahun, $triwulanIsian);

                    $sebelum = JejakAktivitasBagianKustom::potret($poin);

                    // status_verifikasi & catatan SENGAJA TIDAK direset — sama seperti
                    // Kendala & Solusi/Evaluasi RTL di atas, lihat penjelasan di sana.
                    $poin->update([
                        'teks' => $blok['teks'],
                        'nilai_kolom' => $nilaiKolom,
                        'menu' => $poin->menu ?? MenuBagianKustom::PENGISIAN,
                        'peran_pengisi' => $poin->peran_pengisi ?? BagianKustom::PERAN_KETUA_TIM,
                        'diubah_oleh' => auth()->id(),
                    ]);

                    JejakAktivitasBagianKustom::poinDiubah($bagian, $poin, $sebelum, $korelasiBagianKustom);
                } else {
                    $nilaiKolom = $bagian->bersihkanNilaiKolom($blok['kolom'] ?? [], null, $this->tahun, $triwulanIsian);

                    // Lanjutan dari poin periode sebelumnya: asal diperiksa ulang di server
                    // (id dari browser tidak dipercaya). Asal yang tidak sah = ditolak.
                    $sumberLanjutan = null;

                    if (filled($blok['lanjutan_dari_id'] ?? null)) {
                        $sumberLanjutan = LanjutanBagianKustom::sumberSah($bagian, (int) $blok['lanjutan_dari_id'], MenuBagianKustom::PENGISIAN, BagianKustom::PERAN_KETUA_TIM, $periode, (int) $this->iku_id);

                        // Ditangani seperti bentrok: seluruh penyimpanan batal, isian tetap di
                        // layar, dan penolakannya dicatat (tanganiBentrok()).
                        if (! $sumberLanjutan) {
                            throw new \App\Exceptions\KonflikPenyuntinganException(['poin' => ['"'.$bagian->nama.'": poin asal dari periode sebelumnya sudah dilanjutkan atau tidak tersedia']]);
                        }
                    }

                    $poin = BagianKustomPoin::create(($sumberLanjutan ? LanjutanBagianKustom::atribut($sumberLanjutan) : []) + [
                        'bagian_kustom_id' => $bagian->id,
                        'iku_id' => $this->iku_id,
                        'periode_id' => $periode->id,
                        'menu' => MenuBagianKustom::PENGISIAN,
                        'peran_pengisi' => BagianKustom::PERAN_KETUA_TIM,
                        'diisi_oleh' => auth()->id(),
                        'diubah_oleh' => auth()->id(),
                        'teks' => $blok['teks'],
                        'nilai_kolom' => $nilaiKolom,
                    ]);
                    $this->bagianKustomBlocks[$bagian->id][$i]['id'] = $poin->id;

                    $sumberLanjutan
                        ? JejakAktivitasBagianKustom::poinDilanjutkan($bagian, $poin, $sumberLanjutan, $korelasiBagianKustom)
                        : JejakAktivitasBagianKustom::poinDibuat($bagian, $poin, $korelasiBagianKustom);
                }

                $adaPerbaikanLainDiajukan = true;
                $namaBerkasDasar = $bagian->nama.' '.$poin->teks;

                $jumlahBuktiLama = count($blok['existing_bukti'] ?? []);

                foreach (array_values($blok['bukti']) as $fi => $file) {
                    $namaBerkas = $this->namaBuktiBaruAtauDefault($file, $this->namaDefaultBuktiBaru($namaBerkasDasar, $jumlahBuktiLama, $fi, count($blok['bukti'])));
                    $path = $this->simpanBerkasLokal($file, 'bukti-bagian-kustom');

                    $berkas = Berkas::create([
                        'ref_id' => $poin->id,
                        'ref_type' => BagianKustomPoin::class,
                        'kategori' => 'bagian_kustom',
                        // Ekstensi mengikuti berkas yang diunggah (format sudah divalidasi
                        // sesuai aturan bukti bagian; bawaan PDF).
                        'nama_file' => $namaBerkas.'.'.AturanBuktiBagianKustom::ekstensiUnggahan($file),
                        'path' => $path,
                        'status_verifikasi' => 'menunggu',
                    ]);

                    // Dicatat di transaksi yang sama: "tersimpan di server". Salinan Drive
                    // dicatat terpisah (bukti.tersinkron) begitu unggahannya berhasil.
                    JejakAktivitasBagianKustom::buktiDiunggah($bagian, $poin, $berkas, false, $korelasiBagianKustom);

                    $this->antreUnggah(
                        $berkas,
                        $poin,
                        $file->getClientOriginalName(),
                        "bukti {$bagian->nama}",
                        "{$file->getClientOriginalName()} (bukti {$bagian->nama})",
                    );
                }

                $this->bagianKustomBlocks[$bagian->id][$i]['bukti'] = [];
            }
        }

        // Dicek TERAKHIR, setelah SELURUH bagian (Kegiatan, Kendala & Solusi, Evaluasi
        // RTL, Bagian Kustom) selesai diproses di atas — supaya perbaikan pada bagian
        // MANA PUN yang tadinya jadi alasan "dikembalikan" ikut memindahkan Capaian ini
        // balik ke "diajukan", bukan cuma perbaikan pada Kegiatan. Hanya dilakukan saat
        // benar-benar mengajukan ($ajukan) — draft TIDAK boleh memindahkan status
        // capaian/kegiatan keluar dari "draft"/"dikembalikan".
        // Bukti perbaikan bisa sudah tersimpan LEBIH DULU lewat simpan otomatis setelah
        // unggah (lihat simpanOtomatisSetelahUnggah()), jadi di request "Ajukan" ini tidak
        // ada lagi berkas baru — tetap dihitung sebagai perbaikan bila diunggah setelah
        // isian dikembalikan.
        if ($ajukan && ! $adaKegiatanDiajukan && ! $adaPerbaikanLainDiajukan && $capaian->status === Capaian::STATUS_DIKEMBALIKAN) {
            $sejak = $capaian->riwayatStatus()->latest('id')->value('created_at') ?? $capaian->updated_at;
            $adaPerbaikanLainDiajukan = $this->adaBuktiPerbaikanSejak($sejak, $periode);
        }

        if ($ajukan && ($adaKegiatanDiajukan || $adaPerbaikanLainDiajukan)) {
            $capaian->catatStatus(Kegiatan::STATUS_DIAJUKAN, auth()->user());
        }
    }

    /**
     * Ada bukti evaluasi RTL/bagian kustom IKU & periode ini yang diunggah sejak $sejak?
     */
    protected function adaBuktiPerbaikanSejak($sejak, Periode $periode): bool
    {
        $rtlIds = $this->rtlTriwulanBerjalan()->pluck('id');
        $poinIds = BagianKustomPoin::konteksIsianKegiatan()->where('iku_id', $this->iku_id)->where('periode_id', $periode->id)->pluck('id');

        return Berkas::where('created_at', '>=', $sejak)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('ref_type', RtlEvaluasiModel::class)->whereIn('ref_id', $rtlIds))
                ->orWhere(fn ($q) => $q->where('ref_type', BagianKustomPoin::class)->whereIn('ref_id', $poinIds)))
            ->exists();
    }

    /**
     * Tampilkan "sedang mengunggah X…" SAAT ITU JUGA, sebelum panggilan ke Drive API
     * (yang bisa memakan waktu beberapa detik per berkas) dimulai — dipakai supaya Ketua
     * Tim tidak cuma melihat tombol "Mengirim…" polos selama proses lambat, tapi tahu
     * persis berkas mana yang sedang diproses.
     *
     * Dikirim lewat $this->stream() (fitur bawaan Livewire untuk mendorong pembaruan
     * HTML ke browser SEBELUM action selesai, lewat response HTTP yang di-flush
     * bertahap) — BUKAN $this->dispatch(), karena event dispatch baru sampai ke
     * browser bersama HTML/efek akhir setelah SELURUH ajukanIsian() selesai (termasuk
     * seluruh sisa berkas & transaksi DB), jadi terasa seperti "diam saja" selama
     * proses berjalan meski sebenarnya sedang bekerja.
     */
    protected function streamProgresUnggah(string $namaFile, string $konteks): void
    {
        $this->stream(
            to: 'progres-unggah',
            content: '<span class="progres-unggah-item">📤 Mengunggah ke Google Drive: <b>'.e($namaFile).'</b> ('.e($konteks).')…</span>',
            replace: true,
        );
    }

    /**
     * Satu toast per berkas yang selesai diproses ke Google Drive (RF baru) — dipanggil
     * langsung di dalam transaksi ajukanIsian() supaya Ketua Tim tahu PERSIS berkas mana
     * yang berhasil/gagal saat itu juga, bukan cuma ringkasan jumlah di akhir (lihat
     * $driveGagal, yang tetap dipertahankan untuk rincian di flash banner). Beda dari
     * streamProgresUnggah(): toast ini baru benar-benar tampil di browser di AKHIR
     * request (lihat catatan di atas), jadi tetap berguna sebagai rekap, bukan sebagai
     * indikator real-time.
     */
    protected function notifikasiHasilUnggah(string $namaFile, string $konteks, bool $berhasil, ?string $alasan = null): void
    {
        if (! $berhasil) {
            $this->alasanDriveGagal ??= self::alasanGagalDrive($alasan);
        }

        $this->dispatch(
            'notify',
            type: $berhasil ? 'success' : 'error',
            message: $berhasil
                ? "✅ {$namaFile} ({$konteks}) tersalin ke Google Drive."
                : "⚠️ {$namaFile} ({$konteks}) TIDAK berhasil diunggah ke Google Drive — ".self::alasanGagalDrive($alasan).'.'
        );
    }

    /**
     * Alasan pertama unggahan ke Drive gagal di request ini (lihat alasanGagalDrive()),
     * ditampilkan di banner peringatan setelah halaman dimuat ulang.
     */
    protected ?string $alasanDriveGagal = null;

    /**
     * Terjemahkan pesan galat Drive jadi alasan yang dimengerti Ketua Tim.
     */
    public static function alasanGagalDrive(?string $pesan): string
    {
        $pesan = mb_strtolower((string) $pesan);

        return match (true) {
            str_contains($pesan, 'storage aktif') || str_contains($pesan, 'belum terkonfigurasi') || str_contains($pesan, 'not configured')
                => 'akun Google Drive belum disambungkan/diaktifkan (menu Akun & Storage)',
            str_contains($pesan, 'invalid_grant') || str_contains($pesan, 'unauthorized') || str_contains($pesan, 'token') || str_contains($pesan, '401')
                => 'sambungan akun Google Drive kedaluwarsa — sambungkan ulang di menu Akun & Storage',
            str_contains($pesan, 'quota') || str_contains($pesan, 'kuota') || str_contains($pesan, 'storagequotaexceeded')
                => 'kuota penyimpanan Google Drive penuh',
            str_contains($pesan, 'notfound') || str_contains($pesan, 'not found') || str_contains($pesan, '404')
                => 'folder tujuan di Google Drive tidak ditemukan',
            $pesan === '' => 'gangguan koneksi ke Google Drive',
            default => 'gangguan koneksi ke Google Drive ('.Str::limit($pesan, 80).')',
        };
    }

    /**
     * Berkas unggahan Ketua Tim ini yang dulu GAGAL tersalin ke Drive (baik lewat
     * Simpan Sementara maupun Ajukan) dicoba diunggah ulang tiap kali ia menyimpan
     * lagi — salinan lokalnya ikut terhapus saat server di-deploy ulang, jadi jangan
     * ditunda sampai isiannya diajukan. Perintah berkas:sinkron-drive juga mencobanya
     * berkala di latar belakang.
     */
    /**
     * Unggahan ke Drive di dalam transaksi simpan (simpanDraft()/ajukanIsian()) yang
     * gagalnya sengaja "ditelan" (berkas tetap tersimpan lokal) dijalankan di savepoint
     * sendiri. Di PostgreSQL, SATU query yang gagal di tengah transaksi membuat SELURUH
     * transaksi batal — semua query sesudahnya ditolak ("current transaction is
     * aborted") dan simpanan berakhir sebagai halaman 500, padahal kegagalannya cuma
     * Drive. Savepoint mengembalikan transaksi ke kondisi sebelum unggahan ini saja.
     */
    protected function dalamSavepoint(callable $aksi): mixed
    {
        return DB::transaction(fn () => $aksi());
    }

    protected function susulkanBerkasBelumDiDrive(array &$driveGagal, array $kecuali = []): void
    {
        if (! auth()->id()) {
            return;
        }

        // $kecuali = berkas yang baru saja dicoba diunggah di request ini — tidak dicoba dua kali.
        foreach (Berkas::belumDiDrive()->where('diunggah_oleh', auth()->id())->whereNotIn('id', $kecuali)->get() as $berkas) {
            try {
                if ($this->dalamSavepoint(fn () => $berkas->sinkronUlangKeDrive())) {
                    $this->notifikasiHasilUnggah($berkas->nama_file, 'simpanan sebelumnya', true);
                }
            } catch (\Throwable $e) {
                Log::warning("Sinkron ulang berkas {$berkas->kode} ke Drive masih gagal: {$e->getMessage()}");
                $this->alasanDriveGagal ??= self::alasanGagalDrive($e->getMessage());
                $driveGagal[] = "{$berkas->nama_file} (simpanan sebelumnya)";

                // Drive-nya sendiri kemungkinan sedang bermasalah — sisa antrean
                // tidak perlu dicoba satu per satu di request ini.
                break;
            }
        }
    }

    public function ajukanIsian(): void
    {
        if ($this->formTerkunciDisetujuiFresh()) {
            $this->dispatch('notify', type: 'error', message: 'Isian ini sudah disetujui dan terkunci — hubungi Tim SAKIP untuk membuka kembali bila perlu revisi.');

            return;
        }

        if ($this->formTerkunciSedangDitanganiFresh()) {
            $this->dispatch('notify', type: 'error', message: 'Isian ini sedang ditangani Tim SAKIP dan tidak bisa diubah sampai verifikasi selesai.');

            return;
        }

        try {
            $this->buatValidator()->validate();
        } catch (ValidationException $e) {
            $kekurangan = $this->kekuranganPengajuan();
            $this->dispatch('notify', type: 'error', message: 'Belum bisa diajukan — '.($kekurangan[0] ?? 'masih ada data wajib yang belum lengkap.')
                .(count($kekurangan) > 1 ? ' (+'.(count($kekurangan) - 1).' hal lain, lihat daftar di bawah tombol Ajukan.)' : ''));

            throw $e;
        }

        $periode = $this->periodeUntukSimpan();
        $iku = MasterIku::findOrFail($this->iku_id);
        $folderService = app(FolderStructureService::class);

        // Diisi setiap kali satu berkas GAGAL disinkron ke Drive (jaringan, kredensial
        // Drive belum/tidak lagi valid, dst.) — berkasnya tetap aman tersimpan lokal dan
        // isian TETAP berhasil diajukan, tapi Ketua Tim perlu tahu supaya tidak mengira
        // semuanya sudah tersalin ke Drive padahal belum (lihat flash 'driveGagal' di bawah).
        $driveGagal = [];

        $tersimpan = $this->simpanDenganPengaman(
            $periode,
            fn () => $this->simpanBagianIsian($periode, $iku, $folderService, ajukan: true),
            $driveGagal,
        );

        $this->stream(to: 'progres-unggah', content: '', replace: true);

        if (! $tersimpan) {
            return;
        }

        // Menyebutkan IKU + triwulan/tahun secara eksplisit — Ketua Tim biasanya mengisi
        // beberapa IKU berturut-turut, jadi pesan generik "berhasil diajukan" saja tidak
        // cukup jelas isian YANG MANA yang baru saja terkirim.
        $angkaRomawiTriwulan = ['I', 'II', 'III', 'IV'][$this->triwulanDari($this->bulan) - 1];
        $labelIsian = "{$iku->kode} — {$iku->indikator} (Triwulan {$angkaRomawiTriwulan} {$this->tahun}, {$this->namaBulanIndo($this->bulan)})";

        session()->flash(
            'status',
            "Isian {$labelIsian} berhasil diajukan ke Tim SAKIP. "
                .(empty($driveGagal)
                    ? 'Semua berkas bukti berhasil disalin ke Google Drive.'
                    : 'Sebagian berkas bukti BELUM tersalin ke Google Drive — lihat rincian di bawah.')
        );

        if (! empty($driveGagal)) {
            session()->flash('driveGagal', $driveGagal);
            session()->flash('driveGagalAlasan', $this->alasanDriveGagal);
            $this->dispatch('notify', type: 'warning', message: 'Diajukan ke Tim SAKIP, tapi '.count($driveGagal).' berkas belum tersalin ke Google Drive — lihat rincian di bawah.');
        } else {
            $this->dispatch('notify', type: 'success', message: 'Berhasil diajukan ke Tim SAKIP. Semua berkas bukti tersalin ke Google Drive.');
        }

        $this->adaPerubahan = false;
        $this->lupakanCachePeriodeIku();

        // Muat ulang HALAMAN PENUH (bukan cuma me-reset properti komponen) supaya
        // tampilan benar-benar persis seperti pertama kali menu ini dibuka (mount()
        // jalan lagi dari nol) — termasuk state Alpine (toasts, x-data lain) dan
        // input file HTML yang tidak selalu ikut ter-reset kalau cuma properti
        // Livewire-nya yang dikosongkan. Flash 'status'/'driveGagal' di atas tetap
        // tersimpan lewat session dan tampil di halaman baru setelah reload.
        $this->redirect(route('pengisian.index'));
    }

    public function render()
    {
        $triwulan = $this->triwulanDari($this->bulan);
        $bagianKustomAktif = $this->bagianKustomAktif();

        return view('livewire.pengisian-kegiatan', [
            'ikuList' => MasterIku::daftarUrutKode(),
            'triwulan' => $triwulan,
            'bulanKe' => $this->bulanKeDari($this->bulan),
            'flagTerlewat' => $this->isBulanTerlewat(),
            'periodeLabel' => Carbon::create($this->tahun, $this->bulan, 1)->locale('id')->translatedFormat('F Y'),
            'riwayatKendala' => $this->riwayatKendalaSolusi(),
            'kendalaAktif' => $this->kendalaAktif(),
            'rtlSebelumnya' => $this->rtlTriwulanBerjalan(),
            'sudahAdaRtlBerikutnya' => $this->rtlTriwulanBerikutnyaSudahAda(),
            'rtlBerikutnyaDitolak' => $this->rtlBerikutnyaDitolak(),
            'rtlBerikutnyaAktif' => $this->rtlBerikutnyaAktif(),
            'labelBerikutnya' => $this->labelTriwulanBerikutnya(),
            'bulanTargetBerikutnya' => collect($this->bulanBulanTarget())->mapWithKeys(fn ($b) => [$b => $this->namaBulanIndo($b)]),
            'rtlBerjalanOptions' => $this->rtlBerjalanOptions(),
            'rtlBerjalanBelumTerlaksana' => $this->poinRtlBerjalanBelumTerlaksana(),
            'bagianKustomAktif' => $bagianKustomAktif,
            // Hak aksi Ketua Tim per bagian (matriks Data Master) — untuk tampilan saja;
            // aksi tetap diperiksa di server (pastikanPoinBagianKustomSah() dkk.).
            'hakBagianKustom' => $bagianKustomAktif->mapWithKeys(fn ($b) => [$b->id => $b->petaAksi(BagianKustom::PERAN_KETUA_TIM)])->all(),
            'labelBawaan' => \App\Support\RegistriBagian::tampilanBawaan(),
            'riwayatBagianKustom' => $bagianKustomAktif->mapWithKeys(fn ($b) => [$b->id => $this->riwayatBagianKustom($b)]),
            'bisaDilanjutkanBagianKustom' => $bagianKustomAktif->isNotEmpty() ? $this->bisaDilanjutkanBagianKustom() : [],
            'statusKegiatanTerkunci' => self::STATUS_KEGIATAN_TERKUNCI,
            'catatanPenolakan' => $this->catatanPenolakan(),
            'pengembalianTerakhir' => $this->pengembalianTerakhir(),
            'adaDikembalikan' => collect($this->blocks)->contains(fn ($b) => ($b['status_dokumen'] ?? null) === Kegiatan::STATUS_DIKEMBALIKAN)
                || collect($this->kendalaBlocks)->contains(fn ($b) => ($b['status_verifikasi'] ?? null) === 'ditolak'),
            'formTerkunciDisetujui' => $this->formTerkunciDisetujui(),
            'formTerkunciSedangDitangani' => $this->formTerkunciSedangDitangani(),
            'evaluasiTerkunci' => $this->capaianTerkunci(),
            'pengajuanBisaDiedit' => $this->pengajuanBisaDiedit(),
            'linkFolderIku' => $this->linkFolderIku(),
            'evaluasiBolehBuktiSusulan' => $this->evaluasiBolehBuktiSusulan(),
        ]);
    }
}
