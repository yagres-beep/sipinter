<?php

namespace App\Livewire;

use App\Exceptions\KonflikPenyuntinganException;
use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\Capaian;
use App\Models\FolderConfig;
use App\Models\LogAktivitas;
use App\Models\RiwayatKonfigurasiBagianKustom;
use App\Models\VariabelKatalog;
use App\Support\AlurBagianKustom;
use App\Support\AturanBuktiBagianKustom;
use App\Support\FormatAngkaKolom;
use App\Support\KelengkapanBagianKustom;
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
     * Deskripsi yang diisikan otomatis dari bagian sumber (null = tidak ada). Dipakai
     * untuk membersihkannya lagi bila pilihan diganti ke "Pengaturan kosong" atau ke
     * sumber lain — deskripsi yang diketik sendiri oleh pengguna tidak disentuh.
     */
    public ?string $deskripsiDariSumber = null;

    /** Kode internal bagian baru (opsional; kosong = dibentuk dari nama). */
    public string $kodeBaru = '';

    /* ---------- Pencarian, filter, dan urutan daftar ---------- */

    public string $cari = '';

    /** semua | aktif | nonaktif */
    public string $filterStatus = 'semua';

    /** '' = semua peran */
    public string $filterPeran = '';

    /** '' = semua menu */
    public string $filterMenu = '';

    /** urutan | nama | diubah */
    public string $urut = 'urutan';

    /** Bagian yang sedang dilihat detailnya (baca-saja), null = tidak ada. */
    public ?int $detailId = null;

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
     * Isian contoh di tab Pratinjau (tidak pernah disimpan ke database): [id bagian][kunci] => nilai.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $pratinjauNilai = [];

    /**
     * Hasil uji pratinjau terakhir: nilai yang AKAN tersimpan & tampil (termasuk hasil rumus).
     *
     * @var array<int, array<string, string>>
     */
    public array $hasilPratinjau = [];

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

    /** Semua bagian menurut urutan tampil — dasar tombol naik/turun dan pilihan sumber salin. */
    public function daftar()
    {
        return BagianKustom::orderBy('urutan')->orderBy('id')->withCount('poin')->get();
    }

    /** Ada pencarian/filter/urutan selain bawaan — tombol naik/turun disembunyikan. */
    public function filterAktif(): bool
    {
        return trim($this->cari) !== '' || $this->filterStatus !== 'semua' || $this->filterPeran !== '' || $this->filterMenu !== '' || $this->urut !== 'urutan';
    }

    /**
     * Daftar yang ditampilkan sesuai pencarian (nama, kode, deskripsi), filter status,
     * peran, dan menu, serta pilihan urutan.
     */
    public function daftarTampil()
    {
        $cari = mb_strtolower(trim($this->cari));

        $hasil = $this->daftar()->filter(function (BagianKustom $b) use ($cari) {
            if ($cari !== '' && ! str_contains(mb_strtolower($b->nama.' '.$b->kode.' '.$b->deskripsi), $cari)) {
                return false;
            }

            if ($this->filterStatus === 'aktif' && ! $b->aktif) {
                return false;
            }

            if ($this->filterStatus === 'nonaktif' && $b->aktif) {
                return false;
            }

            if ($this->filterPeran !== '' && ! in_array($this->filterPeran, $b->peran ?? [], true)) {
                return false;
            }

            return $this->filterMenu === '' || in_array($this->filterMenu, $b->penempatan ?? [], true);
        });

        return match ($this->urut) {
            'nama' => $hasil->sortBy(fn ($b) => mb_strtolower($b->nama))->values(),
            'diubah' => $hasil->sortByDesc(fn ($b) => optional($b->updated_at)->timestamp ?? 0)->values(),
            default => $hasil->values(),
        };
    }

    public function resetFilter(): void
    {
        $this->reset(['cari', 'filterStatus', 'filterPeran', 'filterMenu', 'urut']);
    }

    /** Buka/tutup ringkasan baca-saja satu bagian. */
    public function lihatDetail(int $id): void
    {
        BagianKustom::findOrFail($id);
        $this->detailId = $this->detailId === $id ? null : $id;
    }

    /**
     * Duplikasi: langsung membuat bagian BARU dari pengaturan bagian ini (identitas,
     * kode, dan folder Drive baru; tanpa isian, bukti, maupun riwayatnya). Salinan
     * dibuat NONAKTIF supaya tidak langsung muncul ke pengisi sebelum diperiksa, lalu
     * editornya dibuka untuk diganti nama/disesuaikan. Form Tambah tidak disentuh.
     */
    public function duplikasi(int $id): void
    {
        $sumber = BagianKustom::findOrFail($id);
        $nama = $this->namaSalinanUnik($sumber->nama);

        $peran = array_values($sumber->peran ?? []) ?: [BagianKustom::PERAN_KETUA_TIM];
        $penempatan = array_values($sumber->penempatan ?? []) ?: [MenuBagianKustom::PENGISIAN];

        $this->resetErrorBag('duplikasi');

        try {
            $bagian = $this->buatBagianBaru([
                'kode' => null,
                'nama' => $nama,
                'deskripsi' => $sumber->deskripsi,
                'peran' => $peran,
                'penempatan' => $penempatan,
                'frekuensi_wajib' => (string) $sumber->frekuensi_wajib,
                'aktif' => false,
            ], $sumber);
        } catch (\Throwable $e) {
            report($e);
            $this->addError('duplikasi', 'Duplikasi "'.$sumber->nama.'" gagal, tidak ada bagian baru yang dibuat dan "'.$sumber->nama.'" tidak berubah. Coba lagi; bila berulang, laporkan ke pengelola aplikasi.');

            return;
        }

        $this->setelahDibuat($bagian, 'dasar');

        session()->flash('status', 'Bagian "'.$bagian->nama.'" dibuat dari duplikasi "'.$sumber->nama.'" dalam keadaan NONAKTIF (belum tampil ke pengisi). Ganti nama dan periksa pengaturannya di bawah, simpan, lalu klik "Aktifkan kembali" bila sudah siap. Isian, bukti, dan riwayat "'.$sumber->nama.'" tidak ikut disalin.');
    }

    /** Setelah bagian baru tersimpan: muat form-nya, buka editornya, segarkan daftar lain. */
    protected function setelahDibuat(BagianKustom $bagian, string $tab): void
    {
        BagianKustom::lupakanCache();
        $this->muatForm($bagian->id);
        $this->bukaId = $bagian->id;
        $this->tab = $tab;
        $this->detailId = null;
        $this->beritahuDaftar();
    }

    /** Daftar "Seluruh Bagian" (komponen lain di halaman yang sama) ikut diperbarui. */
    protected function beritahuDaftar(): void
    {
        $this->dispatch('bagian-kustom-berubah');
    }

    /** Nama "X (salinan)", "X (salinan 2)", … yang belum dipakai bagian lain. */
    protected function namaSalinanUnik(string $namaSumber): string
    {
        $nama = $namaSumber.' (salinan)';
        $n = 2;

        while (BagianKustom::whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])->exists()) {
            $nama = $namaSumber.' (salinan '.$n++.')';
        }

        return $nama;
    }

    /**
     * Buat bagian baru (kosong atau salinan $sumber) + riwayat + kategori folder dalam
     * SATU transaksi: bila salah satu gagal, tidak ada yang tersimpan. Pengaturan sumber
     * selalu dibaca ulang dari database (tidak dari isian browser).
     *
     * @param  array<string, mixed>  $identitas  kode, nama, deskripsi, peran, penempatan, frekuensi_wajib, aktif
     */
    protected function buatBagianBaru(array $identitas, ?BagianKustom $sumber): BagianKustom
    {
        return $this->dalamTransaksi(function () use ($identitas, $sumber) {
            $sumber = $sumber ? BagianKustom::whereKey($sumber->id)->firstOrFail() : null;

            $konfigurasi = $sumber
                ? $this->konfigurasiSalinan($sumber, $identitas['peran'], $identitas['penempatan'])
                : [
                    // Bagian baru dari kosong: bukti wajib bila memang didukung (perilaku lama).
                    'bukti_wajib' => $this->bukti_wajib_boleh($identitas['peran'], $identitas['penempatan']),
                    'aturan_bukti' => null, 'peran_pembaca' => null, 'hak_aksi' => null, 'tampilan' => null,
                    'masuk_notula' => true, 'label_uraian' => null, 'format_notula' => null,
                    'kolom' => [], 'subbagian' => [],
                ];

            $bagian = BagianKustom::create($identitas + $konfigurasi + [
                // Judul notula TIDAK disalin: kosong = memakai nama bagian baru.
                'judul_notula' => null,
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
    }

    /**
     * SATU-SATUNYA tempat yang menentukan pengaturan apa yang disalin dari bagian sumber
     * — dipakai "Mulai dari: Salin pengaturan" maupun tombol Duplikasi. Hanya definisi:
     * tidak pernah poin isian, bukti dukung/ID Drive, status verifikasi, atau riwayat.
     *
     * @param  array<int, string>  $peran  peran pengisi bagian baru
     * @param  array<int, string>  $penempatan  menu bagian baru
     * @return array<string, mixed>
     */
    protected function konfigurasiSalinan(BagianKustom $sumber, array $peran, array $penempatan): array
    {
        $subbagian = $this->subbagianSalinan($sumber);
        $kolom = $this->kolomSalinan($sumber, array_column($subbagian, 'kunci'));
        $format = $this->formatNotulaSalinan($sumber, $kolom);

        // Peran pembaca hanya yang bukan pengisi dan masih bisa membuka salah satu menu.
        $pembaca = array_values(array_filter(
            array_diff($sumber->peran_pembaca ?? [], $peran),
            fn ($p) => collect($penempatan)->contains(fn ($m) => in_array($p, MenuBagianKustom::peranDiizinkan($m), true))
        ));

        return [
            // Aturan bukti eksplisit ikut sumber; aturan lama (NULL) hanya bila didukung peran & menu.
            'bukti_wajib' => $sumber->aturan_bukti !== null
                ? (bool) $sumber->bukti_wajib
                : $this->bukti_wajib_boleh($peran, $penempatan) && (bool) $sumber->bukti_wajib,
            'aturan_bukti' => $sumber->aturan_bukti,
            'peran_pembaca' => $pembaca ?: null,
            // Hak aksi ikut disalin hanya untuk peran pengisi yang sama; peran lain = penuh.
            'hak_aksi' => is_array($sumber->hak_aksi)
                ? (array_intersect_key($sumber->hak_aksi, array_flip($peran)) ?: null)
                : null,
            'tampilan' => $sumber->tampilan,
            'masuk_notula' => (bool) $sumber->masuk_notula,
            'label_uraian' => $sumber->label_uraian,
            'format_notula' => $format,
            // Alur & periode ikut disalin; pengaju dibatasi ke peran pengisi bagian baru.
            'alur' => is_array($sumber->alur)
                ? array_merge($sumber->alur, ['pengaju' => array_values(array_intersect((array) ($sumber->alur['pengaju'] ?? $peran), $peran))],
                    // Tanpa menu selain Isian Kegiatan, alur pemeriksaan tidak berlaku.
                    array_diff($penempatan, [MenuBagianKustom::PENGISIAN]) === [] ? ['mode' => AlurBagianKustom::MODE_LANGSUNG] : [])
                : null,
            'periode_berlaku' => $sumber->periode_berlaku,
            'kolom' => $kolom,
            'subbagian' => $subbagian,
        ];
    }

    /**
     * Format notula sumber, atau null (format standar) bila merujuk kolom yang tidak ikut
     * disalin — supaya salinan tidak lahir dengan format yang tidak bisa disimpan.
     *
     * @param  array<int, array<string, mixed>>  $kolom
     */
    protected function formatNotulaSalinan(BagianKustom $sumber, array $kolom): ?string
    {
        $format = trim((string) $sumber->format_notula);

        if ($format === '') {
            return null;
        }

        return TemplateBagianKustom::variabelTidakDikenal($format, array_column($kolom, 'kunci')) === [] ? $format : null;
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
            'peran_pembaca' => array_values($bagian->peran_pembaca ?? []),
            'hak_aksi' => self::hakAksiForm($bagian),
            'penempatan' => $bagian->penempatan ?? [],
            'label_uraian' => $bagian->label_uraian,
            'frekuensi_wajib' => $bagian->frekuensi_wajib,
            'bukti_wajib' => (bool) $bagian->bukti_wajib,
            'aturan_bukti' => self::aturanBuktiForm($bagian->aturanBukti()),
            'tampilan' => ['isian' => $bagian->tampilanIsian(), 'baca' => $bagian->tampilanBaca()],
            'masuk_notula' => (bool) $bagian->masuk_notula,
            'judul_notula' => $bagian->judul_notula,
            'format_notula' => $bagian->format_notula,
            'alur' => self::alurForm($bagian->alurKerja()),
            'periode_berlaku' => self::periodeForm($bagian->periodeBerlaku()),
            'subbagian' => collect($bagian->subbagianList())->map(fn ($s) => [
                'kunci' => $s['kunci'],
                'nama' => $s['nama'],
                'deskripsi' => (string) $s['deskripsi'],
                'aktif' => $s['aktif'],
                'wajib' => $s['wajib'],
            ] + self::syaratForm($s['tampil_jika']))->values()->all(),
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
                'subbagian' => (string) $k['subbagian'],
                'petunjuk' => (string) $k['petunjuk'],
                'placeholder' => (string) $k['placeholder'],
                'min' => (string) $k['min'],
                'maks' => (string) $k['maks'],
                'panjang_min' => $k['panjang_min'] !== null ? (string) $k['panjang_min'] : '',
                'panjang_maks' => $k['panjang_maks'] !== null ? (string) $k['panjang_maks'] : '',
                'pesan' => (string) $k['pesan'],
                'format_tanggal' => (string) ($k['format_tanggal'] ?? 'panjang'),
                'variabel' => (string) ($k['variabel'] ?? ''),
                'ringkasan' => (string) ($k['ringkasan'] ?? ''),
                'rumus' => (string) ($k['rumus'] ?? ''),
                'tabel_kolom' => collect($k['tabel_kolom'] ?? [])->map(fn ($s) => [
                    'kunci' => $s['kunci'], 'label' => $s['label'], 'tipe' => $s['tipe'], 'wajib' => $s['wajib'], 'opsi_teks' => implode(', ', $s['opsi']),
                ])->values()->all(),
                'baris_min' => $k['baris_min'] !== null ? (string) $k['baris_min'] : '',
                'baris_maks' => $k['baris_maks'] !== null ? (string) $k['baris_maks'] : '',
                'bulat' => (bool) ($k['bulat'] ?? false),
                'pola' => (string) ($k['pola'] ?? 'bebas'),
                'sumber' => (string) ($k['sumber'] ?? \App\Support\ReferensiBagianKustom::SUMBER_BAWAAN),
                'isi_info' => (string) ($k['isi_info'] ?? ''),
            ] + self::syaratForm($k['tampil_jika'] ?? null))->values()->all(),
        ];
    }

    /**
     * Syarat tampil dalam bentuk form (tiga isian datar).
     *
     * @param  array{kunci: string, operator: string, nilai: string}|null  $syarat
     * @return array{syarat_kunci: string, syarat_operator: string, syarat_nilai: string}
     */
    protected static function syaratForm(?array $syarat): array
    {
        return [
            'syarat_kunci' => (string) ($syarat['kunci'] ?? ''),
            'syarat_operator' => (string) ($syarat['operator'] ?? 'sama'),
            'syarat_nilai' => (string) ($syarat['nilai'] ?? ''),
        ];
    }

    /** @param  array<string, mixed>  $row */
    protected static function syaratDariForm(array $row): ?array
    {
        return BagianKustom::normalkanSyarat([
            'kunci' => $row['syarat_kunci'] ?? '',
            'operator' => $row['syarat_operator'] ?? 'sama',
            'nilai' => $row['syarat_nilai'] ?? '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $alur  hasil BagianKustom::alurKerja()
     * @return array<string, mixed>
     */
    protected static function alurForm(array $alur): array
    {
        // Form: tahap 1 = "pemeriksa" + "label_tahap1" (bentuk lama), tahap berikutnya di
        // "tahap_lanjut". Label status kosong = label bawaan.
        $tahapan = $alur['tahapan'] ?? [['label' => AlurBagianKustom::LABEL_TAHAP_BAWAAN, 'pemeriksa' => $alur['pemeriksa']]];

        return [
            'mode' => $alur['mode'],
            'pengaju' => $alur['pengaju'],
            'pemeriksa' => $tahapan[0]['pemeriksa'],
            'label_tahap1' => $tahapan[0]['label'] === AlurBagianKustom::LABEL_TAHAP_BAWAAN ? '' : $tahapan[0]['label'],
            'tahap_lanjut' => collect(array_slice($tahapan, 1))->map(fn ($t) => ['label' => $t['label'], 'pemeriksa' => $t['pemeriksa']])->values()->all(),
            'label_status' => collect(AlurBagianKustom::LABEL_STATUS)
                ->mapWithKeys(fn ($bawaan, $s) => [$s => ($alur['label_status'][$s] ?? $bawaan) === $bawaan ? '' : $alur['label_status'][$s]])->all(),
            'ubah_setelah_disetujui' => $alur['ubah_setelah_disetujui'],
            'notula' => $alur['notula'],
        ];
    }

    /** Tambah satu tahap pemeriksaan (mis. Persetujuan Kepala) di form alur. */
    public function tambahTahapAlur(int $id): void
    {
        abort_unless(isset($this->form[$id]), 404);
        $lanjut = $this->form[$id]['alur']['tahap_lanjut'] ?? [];

        if (count($lanjut) + 1 >= AlurBagianKustom::TAHAP_MAKS) {
            $this->addError("form.{$id}.alur", 'Maksimal '.AlurBagianKustom::TAHAP_MAKS.' tahap pemeriksaan.');

            return;
        }

        $lanjut[] = ['label' => '', 'pemeriksa' => []];
        $this->form[$id]['alur']['tahap_lanjut'] = $lanjut;
    }

    public function hapusTahapAlur(int $id, int $index): void
    {
        abort_unless(isset($this->form[$id]['alur']['tahap_lanjut'][$index]), 404);
        $lanjut = $this->form[$id]['alur']['tahap_lanjut'];
        array_splice($lanjut, $index, 1);
        $this->form[$id]['alur']['tahap_lanjut'] = array_values($lanjut);
    }

    /**
     * @param  array{triwulan: array<int, int>, tahun_mulai: ?int, tahun_selesai: ?int}  $p
     * @return array<string, mixed>
     */
    protected static function periodeForm(array $p): array
    {
        return [
            'triwulan' => array_map('strval', $p['triwulan']),
            'tahun_mulai' => $p['tahun_mulai'] !== null ? (string) $p['tahun_mulai'] : '',
            'tahun_selesai' => $p['tahun_selesai'] !== null ? (string) $p['tahun_selesai'] : '',
        ];
    }

    /**
     * Matriks hak aksi dalam bentuk form: [peran][aksi] => bool untuk SEMUA peran.
     * Peran yang belum menjadi pengisi diisi penuh, jadi saat dicentang sebagai pengisi
     * hak awalnya sama dengan perilaku lama (semua aksi).
     *
     * @return array<string, array<string, bool>>
     */
    protected static function hakAksiForm(BagianKustom $bagian): array
    {
        return collect(array_keys(BagianKustom::NAMA_PERAN))->mapWithKeys(fn ($p) => [
            $p => in_array($p, $bagian->peran ?? [], true)
                ? $bagian->petaAksi($p)
                : array_fill_keys(array_keys(BagianKustom::AKSI), true),
        ])->all();
    }

    /**
     * Hak aksi yang disimpan dari form: hanya peran pengisi, hanya aksi yang dikenal.
     * Bagian lama (hak_aksi NULL) yang semua pengisinya tetap berhak penuh dibiarkan
     * NULL supaya tidak ada perubahan data tanpa perubahan arti.
     *
     * @param  array<int, string>  $peran
     * @return array<string, array<int, string>>|null
     */
    protected static function hakAksiDariForm(BagianKustom $bagian, array $form, array $peran): ?array
    {
        $hak = [];

        foreach ($peran as $p) {
            $hak[$p] = array_values(array_filter(
                array_keys(BagianKustom::AKSI),
                fn ($a) => (bool) ($form[$p][$a] ?? true)
            ));
        }

        $semuaPenuh = collect($hak)->every(fn ($aksi) => count($aksi) === count(BagianKustom::AKSI));

        return $bagian->hak_aksi === null && $semuaPenuh ? null : $hak;
    }

    /**
     * Aturan bukti dalam bentuk form (semua nilai teks/daftar untuk wire:model).
     *
     * @param  array<string, mixed>  $aturan  hasil AturanBuktiBagianKustom::normalkan()
     * @return array<string, mixed>
     */
    protected static function aturanBuktiForm(array $aturan): array
    {
        return [
            'mode' => $aturan['mode'],
            'min' => (string) $aturan['min'],
            'maks' => $aturan['maks'] !== null ? (string) $aturan['maks'] : '',
            'format' => $aturan['format'],
            'ukuran_maks_mb' => $aturan['ukuran_maks_mb'] !== null ? (string) $aturan['ukuran_maks_mb'] : '',
            'petunjuk' => (string) $aturan['petunjuk'],
            'saat' => $aturan['saat'] ?? AturanBuktiBagianKustom::SAAT_SIMPAN,
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
    protected function kolomKosong(int $id, string $subbagian = ''): array
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
            'subbagian' => $subbagian, 'petunjuk' => '', 'placeholder' => '', 'min' => '', 'maks' => '',
            'panjang_min' => '', 'panjang_maks' => '', 'pesan' => '', 'format_tanggal' => 'panjang',
            'variabel' => '', 'ringkasan' => '',
            'rumus' => '', 'tabel_kolom' => [], 'baris_min' => '', 'baris_maks' => '',
            'syarat_kunci' => '', 'syarat_operator' => 'sama', 'syarat_nilai' => '',
            'bulat' => false, 'pola' => 'bebas', 'sumber' => \App\Support\ReferensiBagianKustom::SUMBER_BAWAAN, 'isi_info' => '',
        ];
    }

    /* ---------- Kolom tabel (subkolom di dalam satu kolom bertipe tabel) ---------- */

    public function tambahKolomTabel(int $id, int $index): void
    {
        abort_unless(isset($this->form[$id]['kolom'][$index]), 404);

        if (count($this->form[$id]['kolom'][$index]['tabel_kolom'] ?? []) >= \App\Support\JenisKolomBagianKustom::SUBKOLOM_MAKS) {
            $this->addError("form.{$id}.kolom.{$index}.tabel_kolom", 'Maksimal '.\App\Support\JenisKolomBagianKustom::SUBKOLOM_MAKS.' kolom tabel.');

            return;
        }

        $this->form[$id]['kolom'][$index]['tabel_kolom'][] = ['kunci' => '', 'label' => '', 'tipe' => 'teks', 'wajib' => false, 'opsi_teks' => ''];
    }

    public function hapusKolomTabel(int $id, int $index, int $sub): void
    {
        unset($this->form[$id]['kolom'][$index]['tabel_kolom'][$sub]);
        $this->form[$id]['kolom'][$index]['tabel_kolom'] = array_values($this->form[$id]['kolom'][$index]['tabel_kolom'] ?? []);
    }

    public function geserKolomTabel(int $id, int $index, int $sub, int $arah): void
    {
        $daftar = $this->form[$id]['kolom'][$index]['tabel_kolom'] ?? [];
        $tujuan = $sub + ($arah < 0 ? -1 : 1);

        if (isset($daftar[$sub], $daftar[$tujuan])) {
            [$daftar[$tujuan], $daftar[$sub]] = [$daftar[$sub], $daftar[$tujuan]];
            $this->form[$id]['kolom'][$index]['tabel_kolom'] = $daftar;
        }
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
        if (in_array($tab, ['dasar', 'isian', 'alur', 'bukti', 'notula', 'pratinjau', 'riwayat'], true)) {
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

        // Deskripsi yang tadi diisikan otomatis dari sumber sebelumnya (bukan ketikan
        // pengguna) ikut diganti/dibersihkan; ketikan pengguna dipertahankan.
        $deskripsiOtomatis = trim($this->deskripsiBaru) === '' || $this->deskripsiBaru === $this->deskripsiDariSumber;

        if (blank($nilai)) {
            // "Pengaturan kosong": kembalikan semua yang tadi dimuat dari sumber ke awal.
            $this->sumberSalinId = null;
            $this->peranBaru = [BagianKustom::PERAN_KETUA_TIM];
            $this->penempatanBaru = [MenuBagianKustom::PENGISIAN];
            $this->frekuensiWajibBaru = BagianKustom::FREKUENSI_OPSIONAL;

            if ($deskripsiOtomatis) {
                $this->deskripsiBaru = '';
            }

            $this->deskripsiDariSumber = null;
            $this->resetErrorBag(['peranBaru', 'penempatanBaru']);

            return;
        }

        $sumber = BagianKustom::find((int) $nilai);

        if (! $sumber) {
            // Dibiarkan apa adanya supaya tambah() menolaknya lewat validasi exists.
            $this->addError('sumberSalinId', 'Bagian sumber tidak ditemukan. Pilih bagian lain atau "Pengaturan kosong".');

            return;
        }

        $this->sumberSalinId = $sumber->id;
        $this->peranBaru = array_values($sumber->peran ?? []) ?: [BagianKustom::PERAN_KETUA_TIM];
        $this->penempatanBaru = array_values($sumber->penempatan ?? []) ?: [MenuBagianKustom::PENGISIAN];
        $this->frekuensiWajibBaru = (string) $sumber->frekuensi_wajib;

        if ($deskripsiOtomatis) {
            $this->deskripsiBaru = (string) $sumber->deskripsi;
            $this->deskripsiDariSumber = $this->deskripsiBaru;
        }

        $this->resetErrorBag(['peranBaru', 'penempatanBaru']);
    }

    /**
     * Kolom yang ikut disalin: hanya kolom AKTIF, dengan kunci yang sama (kunci hanya
     * berlaku di dalam satu bagian, jadi data dua bagian tidak pernah tercampur) dan
     * tanpa periode mulai berlaku (bagian baru belum punya riwayat). Kolom yang menunjuk
     * subbagian yang tidak ikut tersalin dipindah menjadi kolom langsung di bagian.
     *
     * @param  array<int, string>|null  $kunciSubbagian  kunci subbagian yang ikut disalin
     * @return array<int, array<string, mixed>>
     */
    protected function kolomSalinan(BagianKustom $sumber, ?array $kunciSubbagian = null): array
    {
        $kunciSubbagian ??= array_column($this->subbagianSalinan($sumber), 'kunci');

        return collect($sumber->kolomList())
            ->filter(fn ($k) => $sumber->kolomBerlakuDi(['mulai_tahun' => null] + $k, null, null))
            ->map(fn ($k) => ['mulai_tahun' => null, 'mulai_triwulan' => null] + $k)
            ->map(fn ($k) => filled($k['subbagian'] ?? null) && ! in_array($k['subbagian'], $kunciSubbagian, true)
                ? array_merge($k, ['subbagian' => null])
                : $k)
            ->values()
            ->all();
    }

    /**
     * Subbagian yang ikut disalin: hanya yang aktif, dengan kunci yang sama.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function subbagianSalinan(BagianKustom $sumber): array
    {
        return collect($sumber->subbagianList())->where('aktif', true)->values()->all();
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

        $kolom = $this->kolomSalinan($sumber);
        $formatSalinan = $this->formatNotulaSalinan($sumber, $kolom);

        return [
            'nama' => $sumber->nama,
            'peran' => $sumber->labelPeran(),
            'menu' => $sumber->labelPenempatan(),
            'frekuensi' => $sumber->labelFrekuensi(),
            'pembaca' => $sumber->labelPeranPembaca(),
            'hak_aksi' => RiwayatKonfigurasiBagianKustom::ringkasHak($sumber->matriksAksi()),
            'tampilan' => 'isian '.mb_strtolower(BagianKustom::TAMPILAN_ISIAN[$sumber->tampilanIsian()]).', penyajian '.mb_strtolower(BagianKustom::TAMPILAN_BACA[$sumber->tampilanBaca()]),
            'kolom' => $kolom,
            'kolom_nonaktif' => count($sumber->kolomList()) - count($kolom),
            'subbagian' => array_column($this->subbagianSalinan($sumber), 'nama'),
            'label_uraian' => $sumber->labelUraianEfektif(),
            'notula' => $sumber->masuk_notula
                ? 'Masuk notula, format: '.($formatSalinan ?? TemplateBagianKustom::FORMAT_STANDAR)
                : 'Tidak masuk notula',
            'format_diganti' => filled($sumber->format_notula) && $formatSalinan === null,
            'bukti' => (bool) $sumber->bukti_wajib,
            'aturan_bukti' => AturanBuktiBagianKustom::ringkasan($sumber->aturanBukti()),
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
        $this->beritahuDaftar();
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
            'kodeBaru' => ['nullable', 'string', 'regex:'.BagianKustom::POLA_KODE, Rule::unique('bagian_kustom', 'kode')],
        ], [
            'kodeBaru.regex' => 'Kode hanya boleh huruf kecil, angka, dan garis bawah, diawali huruf (2–60 karakter), mis. manajemen_risiko.',
            'kodeBaru.unique' => 'Kode tersebut sudah dipakai bagian lain.',
        ], ['namaBaru' => 'nama bagian', 'sumberSalinId' => 'bagian sumber', 'kodeBaru' => 'kode internal']);

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

        // Bagian baru, riwayatnya, dan kategori foldernya dibuat dalam satu transaksi
        // (buatBagianBaru): bila salah satu gagal, tidak ada yang tersimpan.
        try {
            $bagian = $this->buatBagianBaru([
                // Kosong = dibentuk dari nama oleh model (unik). Tetap walau nama diganti.
                'kode' => trim($this->kodeBaru) ?: null,
                'nama' => trim($this->namaBaru),
                'deskripsi' => trim($this->deskripsiBaru) ?: null,
                'peran' => $peran,
                'penempatan' => $penempatan,
                'frekuensi_wajib' => $this->frekuensiWajibBaru,
                'aktif' => true,
            ], $sumber);
        } catch (\Throwable $e) {
            report($e);
            $this->addError('tambah', 'Bagian baru gagal dibuat — tidak ada yang tersimpan'.($sumber ? ' dan "'.$sumber->nama.'" tidak berubah' : '').'. Coba lagi; bila berulang, laporkan ke pengelola aplikasi.');

            return;
        }

        $this->reset(['namaBaru', 'deskripsiBaru', 'sumberSalinId', 'kodeBaru', 'deskripsiDariSumber']);
        $this->peranBaru = [BagianKustom::PERAN_KETUA_TIM];
        $this->penempatanBaru = [MenuBagianKustom::PENGISIAN];
        $this->frekuensiWajibBaru = BagianKustom::FREKUENSI_OPSIONAL;

        $this->setelahDibuat($bagian, 'isian');

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

    /**
     * Tambah kolom langsung di bagian ($subbagian kosong) atau di dalam satu subbagian.
     */
    public function tambahKolom(int $id, string $subbagian = ''): void
    {
        abort_unless(isset($this->form[$id]), 404);

        if ($subbagian !== '' && ! in_array($subbagian, array_column($this->form[$id]['subbagian'] ?? [], 'kunci'), true)) {
            $subbagian = '';
        }

        $this->form[$id]['kolom'][] = $this->kolomKosong($id, $subbagian);
    }

    public function hapusKolom(int $id, int $index): void
    {
        unset($this->form[$id]['kolom'][$index]);
        $this->form[$id]['kolom'] = array_values($this->form[$id]['kolom']);
    }

    /** Kunci kelompok satu baris kolom di form ('' = langsung di bagian). */
    protected function kelompokBaris(int $id, array $baris): string
    {
        $sub = (string) ($baris['subbagian'] ?? '');

        return in_array($sub, array_column($this->form[$id]['subbagian'] ?? [], 'kunci'), true) ? $sub : '';
    }

    /** Tukar dengan kolom sebelumnya/berikutnya di KELOMPOK yang sama (bagian/subbagian). */
    protected function geserKolom(int $id, int $index, int $arah): void
    {
        $kolom = $this->form[$id]['kolom'] ?? [];

        if (! isset($kolom[$index])) {
            return;
        }

        $kelompok = $this->kelompokBaris($id, $kolom[$index]);

        for ($j = $index + $arah; $j >= 0 && $j < count($kolom); $j += $arah) {
            if ($this->kelompokBaris($id, $kolom[$j]) === $kelompok) {
                [$kolom[$j], $kolom[$index]] = [$kolom[$index], $kolom[$j]];
                $this->form[$id]['kolom'] = $kolom;

                return;
            }
        }
    }

    public function naikkanKolom(int $id, int $index): void
    {
        $this->geserKolom($id, $index, -1);
    }

    public function turunkanKolom(int $id, int $index): void
    {
        $this->geserKolom($id, $index, 1);
    }

    /* ---------- Subbagian (baris di form) ---------- */

    public function tambahSubbagian(int $id): void
    {
        abort_unless(isset($this->form[$id]), 404);

        $terpakai = array_column($this->form[$id]['subbagian'] ?? [], 'kunci');
        $n = count($terpakai) + 1;

        while (in_array('sub_'.$n, $terpakai, true)) {
            $n++;
        }

        // Kunci dibuat sekarang (bukan saat simpan) supaya kolom bisa langsung
        // ditempatkan di subbagian baru. Kunci tetap walau nama subbagian diganti.
        $this->form[$id]['subbagian'][] = ['kunci' => 'sub_'.$n, 'nama' => '', 'deskripsi' => '', 'aktif' => true, 'wajib' => false] + self::syaratForm(null);
    }

    /**
     * Hapus subbagian dari form — hanya bila tidak ada kolom di dalamnya. Subbagian
     * yang masih berisi kolom cukup dinonaktifkan, atau pindahkan kolomnya dulu.
     */
    public function hapusSubbagian(int $id, int $index): void
    {
        $sub = $this->form[$id]['subbagian'][$index] ?? null;

        if ($sub === null) {
            return;
        }

        $jumlahKolom = collect($this->form[$id]['kolom'] ?? [])->where('subbagian', $sub['kunci'])->count();

        if ($jumlahKolom > 0) {
            $this->addError("form.{$id}.subbagian.{$index}.nama", "Subbagian ini masih berisi {$jumlahKolom} kolom. Pindahkan atau hapus kolomnya dulu, atau hapus centang \"Aktif\" untuk berhenti memakainya.");

            return;
        }

        unset($this->form[$id]['subbagian'][$index]);
        $this->form[$id]['subbagian'] = array_values($this->form[$id]['subbagian']);
    }

    public function naikkanSubbagian(int $id, int $index): void
    {
        $this->geserSubbagian($id, $index, -1);
    }

    public function turunkanSubbagian(int $id, int $index): void
    {
        $this->geserSubbagian($id, $index, 1);
    }

    protected function geserSubbagian(int $id, int $index, int $arah): void
    {
        $sub = $this->form[$id]['subbagian'] ?? [];
        $tujuan = $index + $arah;

        if (! isset($sub[$index], $sub[$tujuan])) {
            return;
        }

        [$sub[$tujuan], $sub[$index]] = [$sub[$index], $sub[$tujuan]];
        $this->form[$id]['subbagian'] = $sub;
    }

    /**
     * Salin satu subbagian beserta kolom-kolomnya ke subbagian BARU tepat di bawahnya
     * (belum tersimpan sampai Simpan Pengaturan). Salinan mendapat kode subbagian dan
     * variabel kolom baru, jadi tidak pernah terhubung ke data isian subbagian asal.
     */
    public function duplikasiSubbagian(int $id, int $index): void
    {
        $sub = $this->form[$id]['subbagian'][$index] ?? null;

        if ($sub === null) {
            return;
        }

        $terpakai = array_column($this->form[$id]['subbagian'], 'kunci');
        $n = count($terpakai) + 1;

        while (in_array('sub_'.$n, $terpakai, true)) {
            $n++;
        }

        $baru = ['kunci' => 'sub_'.$n, 'nama' => trim((string) $sub['nama']).' (salinan)'] + $sub;
        $daftar = $this->form[$id]['subbagian'];
        array_splice($daftar, $index + 1, 0, [$baru]);
        $this->form[$id]['subbagian'] = $daftar;

        foreach ($this->form[$id]['kolom'] ?? [] as $kol) {
            if (($kol['subbagian'] ?? '') === $sub['kunci']) {
                $this->form[$id]['kolom'][] = $this->salinanBarisKolom($kol, $baru['kunci'], false);
            }
        }
    }

    /** Salin satu kolom (definisi saja) tepat di bawahnya, dengan variabel baru. */
    public function duplikasiKolom(int $id, int $index): void
    {
        $kol = $this->form[$id]['kolom'][$index] ?? null;

        if ($kol === null) {
            return;
        }

        $daftar = $this->form[$id]['kolom'];
        array_splice($daftar, $index + 1, 0, [$this->salinanBarisKolom($kol, (string) ($kol['subbagian'] ?? ''), true)]);
        $this->form[$id]['kolom'] = $daftar;
    }

    /**
     * Baris form salinan kolom: kunci dikosongkan, jadi variabel baru dibuat dari label
     * saat disimpan (kunci subkolom tabel hanya berlaku di dalam kolomnya, tetap sama).
     *
     * @param  array<string, mixed>  $kol
     * @return array<string, mixed>
     */
    protected function salinanBarisKolom(array $kol, string $subbagian, bool $tandaiLabel): array
    {
        $kol['kunci'] = '';
        $kol['subbagian'] = $subbagian;

        if ($tandaiLabel) {
            $kol['label'] = trim((string) ($kol['label'] ?? '')).' (salinan)';
        }

        return $kol;
    }

    /** Buka editor bagian pada tab tempat satu masalah kelengkapan diperbaiki. */
    public function perbaiki(int $id, string $tab): void
    {
        if ($this->bukaId !== $id) {
            $this->buka($id);
        }

        $this->setTab($tab);
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
     * Form bagian ini berbeda dari pengaturan tersimpan (dipakai peringatan "belum
     * disimpan" saat menutup editor atau meninggalkan halaman).
     */
    public function adaPerubahanBelumDisimpan(int $id): bool
    {
        if (! isset($this->form[$id]) || ! BagianKustom::whereKey($id)->exists()) {
            return false;
        }

        // muatForm() juga memperbarui versiForm — dipulihkan supaya deteksi bentrok
        // tetap memakai versi saat form dibuka.
        $sekarang = $this->form[$id];
        $versi = $this->versiForm[$id] ?? null;
        $this->muatForm($id);
        $tersimpan = $this->form[$id];
        $this->form[$id] = $sekarang;

        if ($versi !== null) {
            $this->versiForm[$id] = $versi;
        } else {
            unset($this->versiForm[$id]);
        }

        return self::bandingkan($sekarang) !== self::bandingkan($tersimpan);
    }

    /** Bentuk pembanding: nilai skalar sebagai teks, supaya "1" dan 1 dianggap sama. */
    protected static function bandingkan(mixed $nilai): mixed
    {
        if (is_array($nilai)) {
            return array_map(fn ($v) => self::bandingkan($v), $nilai);
        }

        return is_bool($nilai) ? ($nilai ? '1' : '0') : trim((string) $nilai);
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
            "form.{$id}.subbagian" => ['array', 'max:'.BagianKustom::SUBBAGIAN_MAKS],
            "form.{$id}.subbagian.*.kunci" => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            "form.{$id}.subbagian.*.nama" => ['required', 'string', 'max:150'],
            "form.{$id}.subbagian.*.deskripsi" => ['nullable', 'string', 'max:1000'],
            "form.{$id}.subbagian.*.aktif" => ['boolean'],
            "form.{$id}.kolom" => ['array', 'max:'.BagianKustom::KOLOM_MAKS],
            "form.{$id}.kolom.*.label" => ['required', 'string', 'max:100'],
            "form.{$id}.kolom.*.subbagian" => ['nullable', 'string', 'max:60'],
            "form.{$id}.kolom.*.petunjuk" => ['nullable', 'string', 'max:500'],
            "form.{$id}.kolom.*.placeholder" => ['nullable', 'string', 'max:150'],
            "form.{$id}.kolom.*.min" => ['nullable', 'string', 'max:30'],
            "form.{$id}.kolom.*.maks" => ['nullable', 'string', 'max:30'],
            "form.{$id}.kolom.*.panjang_min" => ['nullable', 'integer', 'min:0', 'max:5000'],
            "form.{$id}.kolom.*.panjang_maks" => ['nullable', 'integer', 'min:1', 'max:5000'],
            "form.{$id}.kolom.*.pesan" => ['nullable', 'string', 'max:255'],
            "form.{$id}.kolom.*.format_tanggal" => ['nullable', Rule::in(array_keys(\App\Support\JenisKolomBagianKustom::FORMAT_TANGGAL))],
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
            "form.{$id}.kolom.*.variabel" => ['nullable', 'string', 'max:60'],
            "form.{$id}.kolom.*.ringkasan" => ['nullable', Rule::in(array_keys(BagianKustom::RINGKASAN))],
            // Peran pembaca, aturan bukti, dan bentuk tampilan — semua dibatasi daftar tetap.
            "form.{$id}.peran_pembaca" => ['array'],
            "form.{$id}.peran_pembaca.*" => [Rule::in(array_keys(BagianKustom::NAMA_PERAN))],
            // Matriks hak aksi: hanya kunci peran & aksi yang dikenal yang dibaca saat simpan.
            "form.{$id}.hak_aksi" => ['nullable', 'array'],
            "form.{$id}.hak_aksi.*" => ['array'],
            "form.{$id}.hak_aksi.*.*" => ['boolean'],
            "form.{$id}.aturan_bukti" => ['nullable', 'array'],
            "form.{$id}.aturan_bukti.mode" => ['nullable', Rule::in(array_keys(AturanBuktiBagianKustom::MODE))],
            "form.{$id}.aturan_bukti.min" => ['nullable', 'integer', 'min:0', 'max:'.AturanBuktiBagianKustom::JUMLAH_MAKS],
            "form.{$id}.aturan_bukti.maks" => ['nullable', 'integer', 'min:1', 'max:'.AturanBuktiBagianKustom::JUMLAH_MAKS],
            "form.{$id}.aturan_bukti.format" => ['nullable', 'array'],
            "form.{$id}.aturan_bukti.format.*" => [Rule::in(array_keys(AturanBuktiBagianKustom::FORMAT))],
            "form.{$id}.aturan_bukti.ukuran_maks_mb" => ['nullable', 'integer', 'min:1', 'max:'.AturanBuktiBagianKustom::UKURAN_MAKS_MB],
            "form.{$id}.aturan_bukti.petunjuk" => ['nullable', 'string', 'max:500'],
            "form.{$id}.tampilan" => ['nullable', 'array'],
            "form.{$id}.tampilan.isian" => ['nullable', Rule::in(array_keys(BagianKustom::TAMPILAN_ISIAN))],
            "form.{$id}.tampilan.baca" => ['nullable', Rule::in(array_keys(BagianKustom::TAMPILAN_BACA))],
            "form.{$id}.aturan_bukti.saat" => ['nullable', Rule::in(array_keys(AturanBuktiBagianKustom::SAAT))],
            // Alur kerja & periode berlaku.
            "form.{$id}.alur" => ['nullable', 'array'],
            "form.{$id}.alur.mode" => ['nullable', Rule::in(array_keys(AlurBagianKustom::MODE))],
            "form.{$id}.alur.pengaju" => ['nullable', 'array'],
            "form.{$id}.alur.pengaju.*" => [Rule::in(array_keys(BagianKustom::NAMA_PERAN))],
            "form.{$id}.alur.pemeriksa" => ['nullable', 'array'],
            "form.{$id}.alur.pemeriksa.*" => [Rule::in(array_keys(BagianKustom::NAMA_PERAN))],
            "form.{$id}.alur.ubah_setelah_disetujui" => ['boolean'],
            "form.{$id}.alur.label_tahap1" => ['nullable', 'string', 'max:60'],
            "form.{$id}.alur.tahap_lanjut" => ['nullable', 'array', 'max:'.(AlurBagianKustom::TAHAP_MAKS - 1)],
            "form.{$id}.alur.tahap_lanjut.*.label" => ['nullable', 'string', 'max:60'],
            "form.{$id}.alur.tahap_lanjut.*.pemeriksa" => ['nullable', 'array'],
            "form.{$id}.alur.tahap_lanjut.*.pemeriksa.*" => [Rule::in(array_keys(BagianKustom::NAMA_PERAN))],
            "form.{$id}.alur.label_status" => ['nullable', 'array'],
            "form.{$id}.alur.label_status.*" => ['nullable', 'string', 'max:60'],
            "form.{$id}.alur.notula" => ['nullable', Rule::in(array_keys(AlurBagianKustom::NOTULA))],
            "form.{$id}.periode_berlaku" => ['nullable', 'array'],
            "form.{$id}.periode_berlaku.triwulan" => ['nullable', 'array'],
            "form.{$id}.periode_berlaku.triwulan.*" => [Rule::in(['1', '2', '3', '4', 1, 2, 3, 4])],
            "form.{$id}.periode_berlaku.tahun_mulai" => ['nullable', 'integer', 'min:2020', 'max:2100'],
            "form.{$id}.periode_berlaku.tahun_selesai" => ['nullable', 'integer', 'min:2020', 'max:2100'],
            // Subbagian & kolom: wajib, syarat tampil, rumus, tabel.
            "form.{$id}.subbagian.*.wajib" => ['boolean'],
            "form.{$id}.subbagian.*.syarat_kunci" => ['nullable', 'string', 'max:60'],
            "form.{$id}.subbagian.*.syarat_operator" => ['nullable', Rule::in(array_keys(BagianKustom::OPERATOR_SYARAT))],
            "form.{$id}.subbagian.*.syarat_nilai" => ['nullable', 'string', 'max:150'],
            "form.{$id}.kolom.*.syarat_kunci" => ['nullable', 'string', 'max:60'],
            "form.{$id}.kolom.*.syarat_operator" => ['nullable', Rule::in(array_keys(BagianKustom::OPERATOR_SYARAT))],
            "form.{$id}.kolom.*.syarat_nilai" => ['nullable', 'string', 'max:150'],
            "form.{$id}.kolom.*.rumus" => ['nullable', 'string', 'max:'.\App\Support\RumusBagianKustom::PANJANG_MAKS],
            "form.{$id}.kolom.*.baris_min" => ['nullable', 'integer', 'min:0', 'max:'.\App\Support\JenisKolomBagianKustom::BARIS_TABEL_MAKS],
            "form.{$id}.kolom.*.baris_maks" => ['nullable', 'integer', 'min:1', 'max:'.\App\Support\JenisKolomBagianKustom::BARIS_TABEL_MAKS],
            "form.{$id}.kolom.*.tabel_kolom" => ['nullable', 'array', 'max:'.\App\Support\JenisKolomBagianKustom::SUBKOLOM_MAKS],
            "form.{$id}.kolom.*.tabel_kolom.*.label" => ['required', 'string', 'max:100'],
            "form.{$id}.kolom.*.tabel_kolom.*.tipe" => ['required', Rule::in(array_keys(\App\Support\JenisKolomBagianKustom::TIPE_SUBKOLOM))],
            "form.{$id}.kolom.*.tabel_kolom.*.wajib" => ['boolean'],
            "form.{$id}.kolom.*.tabel_kolom.*.opsi_teks" => ['nullable', 'string', 'max:500'],
            // Jenis kolom tambahan: bilangan bulat, pola kode, sumber referensi, teks informasi.
            "form.{$id}.kolom.*.bulat" => ['boolean'],
            "form.{$id}.kolom.*.pola" => ['nullable', Rule::in(array_keys(\App\Support\JenisKolomBagianKustom::POLA_KODE))],
            "form.{$id}.kolom.*.sumber" => ['nullable', Rule::in(array_keys(\App\Support\ReferensiBagianKustom::SUMBER))],
            "form.{$id}.kolom.*.isi_info" => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function pesanForm(int $id): array
    {
        return [
            "form.{$id}.nama.required" => 'Nama bagian wajib diisi.',
            "form.{$id}.kolom.*.label.required" => 'Label kolom wajib diisi (atau hapus baris kolomnya).',
            "form.{$id}.subbagian.*.nama.required" => 'Nama subbagian wajib diisi (atau hapus subbagiannya).',
            "form.{$id}.kolom.max" => 'Maksimal '.BagianKustom::KOLOM_MAKS.' kolom per bagian.',
            "form.{$id}.subbagian.max" => 'Maksimal '.BagianKustom::SUBBAGIAN_MAKS.' subbagian per bagian.',
            "form.{$id}.aturan_bukti.maks.min" => 'Jumlah maksimal bukti minimal 1.',
            "form.{$id}.aturan_bukti.ukuran_maks_mb.max" => 'Ukuran maksimal per berkas paling besar '.AturanBuktiBagianKustom::UKURAN_MAKS_MB.' MB.',
            "form.{$id}.kolom.*.tabel_kolom.*.label.required" => 'Nama kolom tabel wajib diisi (atau hapus kolom tabelnya).',
        ];
    }

    /**
     * Bentuk subbagian yang disimpan dari baris form (urutan = urutan baris).
     *
     * @return array<int, array{kunci: string, nama: string, deskripsi: ?string, aktif: bool}>
     */
    protected function subbagianDariForm(int $id): array
    {
        return collect($this->form[$id]['subbagian'] ?? [])
            ->filter(fn ($s) => filled($s['kunci'] ?? null))
            ->map(fn ($s) => BagianKustom::normalkanSubbagian(['tampil_jika' => self::syaratDariForm($s)] + $s + ['nama' => '']))
            ->values()
            ->all();
    }

    /**
     * Periksa syarat tampil kolom/subbagian: kolom acuan harus ada, bukan dirinya, dan
     * nilai pembanding harus salah satu pilihan bila kolom acuannya berpilihan.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, array<string, mixed>>  $kolomDikenal  kolom tersimpan (kunci tetap)
     */
    protected function periksaSyarat(string $jalur, array $row, array $kolomDikenal, ?string $kunciSendiri = null): void
    {
        if (! filled($row['syarat_kunci'] ?? null)) {
            return;
        }

        $acuan = collect($kolomDikenal)->firstWhere('kunci', $row['syarat_kunci']);

        if (! $acuan || $row['syarat_kunci'] === $kunciSendiri || ! \App\Support\JenisKolomBagianKustom::bisaJadiAcuan($acuan['tipe'])) {
            $this->addError("{$jalur}.syarat_kunci", 'Kolom acuan syarat tampil tidak ditemukan atau jenisnya tidak bisa dijadikan acuan. Pilih kolom lain atau kosongkan syaratnya.');

            return;
        }

        $operator = (string) ($row['syarat_operator'] ?? 'sama');
        $nilai = trim((string) ($row['syarat_nilai'] ?? ''));
        $tanpaNilai = in_array($operator, BagianKustom::OPERATOR_TANPA_NILAI, true);
        $opsiAcuan = \App\Support\JenisKolomBagianKustom::opsiTetap($acuan);

        if (! $tanpaNilai && $nilai === '') {
            $this->addError("{$jalur}.syarat_nilai", 'Isi nilai pembanding syarat tampil, atau pilih "sudah diisi" / "masih kosong".');
        } elseif (in_array($operator, BagianKustom::OPERATOR_BANDING, true)) {
            // Perbandingan hanya untuk angka/tanggal/waktu; pembanding angka harus angka sah.
            if (! \App\Support\JenisKolomBagianKustom::bisaDibandingkan($acuan['tipe'])) {
                $this->addError("{$jalur}.syarat_operator", '"Lebih besar / lebih kecil" hanya untuk kolom acuan berjenis angka, mata uang, persentase, tanggal, atau waktu.');
            } elseif (\App\Support\JenisKolomBagianKustom::numerik($acuan['tipe']) && BagianKustom::angkaSyarat($nilai, '') === null) {
                $this->addError("{$jalur}.syarat_nilai", 'Nilai pembanding harus berupa angka, mis. 50 atau 12,5.');
            } elseif ($acuan['tipe'] === 'tanggal' && ! \App\Support\JenisKolomBagianKustom::tanggalSah($nilai)) {
                $this->addError("{$jalur}.syarat_nilai", 'Nilai pembanding tanggal ditulis tahun-bulan-hari, mis. 2026-06-30.');
            } elseif ($acuan['tipe'] === 'waktu' && \App\Support\JenisKolomBagianKustom::waktuKanonik($nilai) === null) {
                $this->addError("{$jalur}.syarat_nilai", 'Nilai pembanding jam ditulis jam:menit, mis. 14:30.');
            }
        } elseif (! $tanpaNilai && $operator !== 'berisi' && $opsiAcuan !== [] && ! in_array($nilai, $opsiAcuan, true)) {
            $this->addError("{$jalur}.syarat_nilai", 'Nilai pembanding harus salah satu pilihan kolom "'.$acuan['label'].'": '.implode(', ', $opsiAcuan).'.');
        }
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
        $kunciSub = array_column($this->subbagianDariForm($id), 'kunci');
        $hasil = [];

        foreach ($baris as $i => $row) {
            $kunci = (string) ($row['kunci'] ?? '');

            if ($kunci === '') {
                $kunci = TemplateBagianKustom::kunciBaru((string) $row['label'], $kunciTerpakai);
                $kunciTerpakai[] = $kunci;
            }

            $opsi = $this->opsiDariTeks((string) ($row['opsi_teks'] ?? ''));

            $bertautKatalog = filled($row['variabel'] ?? null);

            if (! $bertautKatalog && $row['tipe'] === 'pilihan' && count($opsi) < 2) {
                $this->addError("form.{$id}.kolom.{$i}.opsi_teks", 'Kolom pilihan butuh minimal dua opsi, dipisah koma.');
            }

            if (! $bertautKatalog && $row['tipe'] === 'pilihan_ganda' && count($opsi) < 2) {
                $this->addError("form.{$id}.kolom.{$i}.opsi_teks", 'Kolom pilihan ganda butuh minimal dua opsi, dipisah koma.');
            }

            if (filled($row['mulai_tahun'] ?? null) xor filled($row['mulai_triwulan'] ?? null)) {
                $this->addError("form.{$id}.kolom.{$i}.mulai_triwulan", 'Isi tahun dan triwulan mulai berlaku bersamaan, atau kosongkan keduanya.');
            }

            if (filled($row['subbagian'] ?? null) && ! in_array($row['subbagian'], $kunciSub, true)) {
                $this->addError("form.{$id}.kolom.{$i}.subbagian", 'Subbagian kolom ini sudah tidak ada. Pilih subbagian lain atau "Langsung di bagian".');
            }

            if ($row['tipe'] === 'informasi' && ! filled(trim((string) ($row['isi_info'] ?? '')))) {
                $this->addError("form.{$id}.kolom.{$i}.isi_info", 'Tulis isi teks informasi yang ditampilkan di formulir.');
            }

            if (in_array($row['tipe'], ['angka', 'mata_uang'], true)) {
                foreach (['min', 'maks'] as $batas) {
                    if (filled($row[$batas] ?? null) && FormatAngkaKolom::urai((string) $row[$batas], null) === null) {
                        $this->addError("form.{$id}.kolom.{$i}.{$batas}", 'Batas harus berupa angka, mis. 0 atau 12,5.');
                    }
                }

                $min = filled($row['min'] ?? null) ? FormatAngkaKolom::urai((string) $row['min'], null) : null;
                $maks = filled($row['maks'] ?? null) ? FormatAngkaKolom::urai((string) $row['maks'], null) : null;

                if ($min !== null && $maks !== null && (float) $min > (float) $maks) {
                    $this->addError("form.{$id}.kolom.{$i}.maks", 'Nilai maksimal harus lebih besar atau sama dengan nilai minimal.');
                }
            }

            if (filled($row['panjang_min'] ?? null) && filled($row['panjang_maks'] ?? null) && (int) $row['panjang_min'] > (int) $row['panjang_maks']) {
                $this->addError("form.{$id}.kolom.{$i}.panjang_maks", 'Panjang maksimal harus lebih besar atau sama dengan panjang minimal.');
            }

            // Variabel katalog harus ada; menautkan BARU hanya ke variabel aktif. Tautan
            // lama ke variabel yang kemudian dinonaktifkan tetap boleh dipertahankan.
            if (filled($row['variabel'] ?? null)) {
                $var = VariabelKatalog::where('kode', $row['variabel'])->first();
                $tautanLama = collect(BagianKustom::find($id)?->kolom ?? [])->contains(fn ($k) => ($k['kunci'] ?? null) === $kunci && ($k['variabel'] ?? null) === $row['variabel']);

                if (! $var || (! $var->aktif && ! $tautanLama)) {
                    $this->addError("form.{$id}.kolom.{$i}.variabel", 'Variabel katalog tidak ditemukan atau sudah nonaktif.');
                }
            }

            if ($row['tipe'] === 'tabel') {
                $subkolom = $row['tabel_kolom'] ?? [];

                if ($subkolom === []) {
                    $this->addError("form.{$id}.kolom.{$i}.tabel_kolom", 'Kolom tabel butuh minimal satu kolom di dalamnya. Klik "＋ Kolom tabel".');
                }

                foreach ($subkolom as $si => $s) {
                    if (($s['tipe'] ?? '') === 'pilihan' && count($this->opsiDariTeks((string) ($s['opsi_teks'] ?? ''))) < 2) {
                        $this->addError("form.{$id}.kolom.{$i}.tabel_kolom.{$si}.opsi_teks", 'Kolom tabel pilihan butuh minimal dua opsi, dipisah koma.');
                    }
                }

                if (filled($row['baris_min'] ?? null) && filled($row['baris_maks'] ?? null) && (int) $row['baris_min'] > (int) $row['baris_maks']) {
                    $this->addError("form.{$id}.kolom.{$i}.baris_maks", 'Jumlah baris maksimal harus lebih besar atau sama dengan minimal.');
                }
            }

            $hasil[] = $this->kolomTersimpan($kunci, $row, $opsi);
        }

        // Rumus & syarat tampil merujuk kolom lain lewat kunci tetapnya — diperiksa
        // setelah semua kunci diketahui.
        $kunciAngka = collect($hasil)->filter(fn ($k) => \App\Support\JenisKolomBagianKustom::angkaIsian($k['tipe']))->pluck('kunci')->all();

        foreach ($baris as $i => $row) {
            if (($row['tipe'] ?? '') === 'hitung' && ($galat = \App\Support\RumusBagianKustom::galat((string) ($row['rumus'] ?? ''), $kunciAngka)) !== null) {
                $this->addError("form.{$id}.kolom.{$i}.rumus", $galat);
            }

            $this->periksaSyarat("form.{$id}.kolom.{$i}", $row, $hasil, $hasil[$i]['kunci'] ?? null);
        }

        foreach ($this->form[$id]['subbagian'] ?? [] as $si => $s) {
            // Subbagian tidak boleh bersyarat pada kolom di dalamnya sendiri.
            $kolomLuar = array_values(array_filter($hasil, fn ($k) => $k['subbagian'] !== ($s['kunci'] ?? null)));
            $this->periksaSyarat("form.{$id}.subbagian.{$si}", $s, $kolomLuar);
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
        $variabel = filled($row['variabel'] ?? null) ? (string) $row['variabel'] : null;
        $katalog = $variabel !== null ? VariabelKatalog::peta() : [];

        // Kolom bertaut katalog: definisinya (jenis, opsi, format, batas) disalin dari
        // katalog saat disimpan dan tetap diikuti saat dibaca (BagianKustom::kolomList()).
        if ($variabel !== null && isset($katalog[$variabel])) {
            $def = $katalog[$variabel];
            $row = array_merge($row, [
                'tipe' => $def['tipe'], 'satuan' => $def['satuan'], 'desimal' => $def['desimal'], 'pemisah' => $def['pemisah'],
                'ribuan' => $def['ribuan'], 'min' => $def['min'], 'maks' => $def['maks'],
                'panjang_min' => $def['panjang_min'], 'panjang_maks' => $def['panjang_maks'],
            ]);
            $opsi = $def['opsi'];
        }

        $tipe = (string) ($row['tipe'] ?? 'teks');
        $angka = \App\Support\JenisKolomBagianKustom::numerik($tipe);

        // Kunci subkolom tabel: dipertahankan bila sudah ada, dibuat dari label bila baru.
        $subkolom = [];
        $kunciSub = collect($row['tabel_kolom'] ?? [])->pluck('kunci')->filter()->values()->all();

        foreach ($tipe === 'tabel' ? ($row['tabel_kolom'] ?? []) : [] as $s) {
            $k = (string) ($s['kunci'] ?? '');

            if ($k === '') {
                $k = TemplateBagianKustom::kunciBaru((string) ($s['label'] ?? ''), $kunciSub);
                $kunciSub[] = $k;
            }

            $subkolom[] = ['kunci' => $k, 'label' => $s['label'] ?? '', 'tipe' => $s['tipe'] ?? 'teks', 'wajib' => (bool) ($s['wajib'] ?? false), 'opsi' => $this->opsiDariTeks((string) ($s['opsi_teks'] ?? ''))];
        }

        return BagianKustom::normalkanKolom([
            'rumus' => $row['rumus'] ?? null,
            'tabel_kolom' => $subkolom,
            'baris_min' => $row['baris_min'] ?? null,
            'baris_maks' => $row['baris_maks'] ?? null,
            'tampil_jika' => self::syaratDariForm($row),
            'variabel' => $variabel,
            'ringkasan' => $row['ringkasan'] ?? null,
            'kunci' => $kunci,
            'label' => trim((string) ($row['label'] ?? '')),
            'tipe' => $tipe,
            'opsi' => in_array($tipe, ['pilihan', 'pilihan_ganda'], true) ? $opsi : [],
            'subbagian' => $row['subbagian'] ?? null,
            'petunjuk' => $row['petunjuk'] ?? null,
            'placeholder' => $row['placeholder'] ?? null,
            'min' => $row['min'] ?? null,
            'maks' => $row['maks'] ?? null,
            'panjang_min' => $row['panjang_min'] ?? null,
            'panjang_maks' => $row['panjang_maks'] ?? null,
            'pesan' => $row['pesan'] ?? null,
            'format_tanggal' => $row['format_tanggal'] ?? null,
            'wajib' => (bool) ($row['wajib'] ?? false),
            'aktif' => (bool) ($row['aktif'] ?? true),
            'mulai_tahun' => $row['mulai_tahun'] ?? null,
            'mulai_triwulan' => $row['mulai_triwulan'] ?? null,
            'satuan' => $angka ? ($row['satuan'] ?? null) : null,
            'desimal' => $angka ? ($row['desimal'] ?? null) : null,
            'pemisah' => $row['pemisah'] ?? FormatAngkaKolom::PEMISAH_KOMA,
            'ribuan' => (bool) ($row['ribuan'] ?? true),
            'bulat' => (bool) ($row['bulat'] ?? false),
            'pola' => $row['pola'] ?? null,
            'sumber' => $row['sumber'] ?? null,
            'isi_info' => $row['isi_info'] ?? null,
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

        // Kolom di dalam tabel yang sudah berisi data juga tidak boleh dihapus.
        if (($galatSub = $this->galatSubkolomDihapus($bagian, $kolom)) !== null) {
            $this->addError("form.{$id}.kolom", $galatSub);

            return;
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

        // Peran pembaca: hanya melihat. Harus bisa membuka minimal satu menu penempatan.
        $pembaca = array_values(array_diff(array_unique((array) ($f['peran_pembaca'] ?? [])), $peran));

        foreach ($pembaca as $p) {
            if (! collect($penempatan)->contains(fn ($menu) => in_array($p, MenuBagianKustom::peranDiizinkan($menu), true))) {
                $this->addError("form.{$id}.peran_pembaca", 'Peran pembaca '.(BagianKustom::NAMA_PERAN[$p] ?? $p).' tidak bisa membuka menu mana pun yang dipilih. Tambahkan menu yang bisa dibukanya, atau hapus centangnya.');

                return;
            }
        }

        // Aturan bukti: bagian yang belum pernah diatur dan formnya tidak diubah tetap
        // NULL (perilaku lama, bukti hanya di Isian Kegiatan). Selain itu disimpan eksplisit.
        $aturanLama = $bagian->aturanBukti();
        $aturanForm = (array) ($f['aturan_bukti'] ?? self::aturanBuktiForm($aturanLama));
        $aturanBaru = ! $aturanLama['diatur'] && $aturanForm == self::aturanBuktiForm($aturanLama)
            ? null
            : AturanBuktiBagianKustom::untukDisimpan($aturanForm);

        if ($aturanBaru !== null && $aturanBaru['mode'] !== AturanBuktiBagianKustom::MODE_TIDAK && filled($aturanForm['maks'] ?? null) && (int) ($aturanForm['min'] ?? 0) > (int) $aturanForm['maks']) {
            $this->addError("form.{$id}.aturan_bukti.maks", 'Jumlah maksimal bukti harus lebih besar atau sama dengan jumlah minimal.');

            return;
        }

        $buktiWajib = $aturanBaru === null
            ? $this->bukti_wajib_boleh($peran, $penempatan) && (bool) $f['bukti_wajib']
            : $aturanBaru['mode'] === AturanBuktiBagianKustom::MODE_WAJIB;

        // Alur kerja: berlaku di menu selain Isian Kegiatan.
        [$alurBaru, $galatAlur] = $this->alurDariForm($bagian, $f, $peran, $penempatan);

        if ($galatAlur !== null) {
            $this->addError("form.{$id}.alur", $galatAlur);

            return;
        }

        $alurEfektif = AlurBagianKustom::normalkan($alurBaru, $peran);

        $saatBukti = $aturanBaru['saat'] ?? AturanBuktiBagianKustom::SAAT_SIMPAN;

        if ($aturanBaru !== null && $aturanBaru['mode'] === AturanBuktiBagianKustom::MODE_WAJIB && $saatBukti !== AturanBuktiBagianKustom::SAAT_SIMPAN && ! AlurBagianKustom::pakaiVerifikasi($alurEfektif)) {
            $this->addError("form.{$id}.aturan_bukti.saat", 'Bukti "'.mb_strtolower(AturanBuktiBagianKustom::SAAT[$saatBukti]).'" hanya bisa dipakai bila alur kerja memakai pengajuan & pemeriksaan (tab Alur Kerja & Periode).');

            return;
        }

        $periodeBaru = BagianKustom::normalkanPeriode((array) ($f['periode_berlaku'] ?? []));

        if ($periodeBaru['tahun_mulai'] !== null && $periodeBaru['tahun_selesai'] !== null && $periodeBaru['tahun_mulai'] > $periodeBaru['tahun_selesai']) {
            $this->addError("form.{$id}.periode_berlaku", 'Tahun selesai harus sama atau setelah tahun mulai.');

            return;
        }

        $periodeSimpan = $periodeBaru === ['triwulan' => [], 'tahun_mulai' => null, 'tahun_selesai' => null] ? null : $periodeBaru;

        $tampilan = [
            'isian' => array_key_exists((string) ($f['tampilan']['isian'] ?? ''), BagianKustom::TAMPILAN_ISIAN) ? $f['tampilan']['isian'] : 'kartu',
            'baca' => array_key_exists((string) ($f['tampilan']['baca'] ?? ''), BagianKustom::TAMPILAN_BACA) ? $f['tampilan']['baca'] : 'daftar',
        ];
        $tampilan = $tampilan === ['isian' => 'kartu', 'baca' => 'daftar'] && $bagian->tampilan === null ? null : $tampilan;

        // Berkas lama yang tidak cocok dengan aturan baru TIDAK diubah/dihapus — hanya
        // diberitahukan supaya Tim SAKIP tahu.
        $peringatanBukti = $aturanBaru !== null ? $this->peringatanBerkasLama($bagian, AturanBuktiBagianKustom::normalkan($aturanBaru, false)) : null;

        // Menu/peran yang dilepas tetapi masih punya poin tersimpan: datanya tetap ada
        // dan tetap masuk notula, hanya tidak tampil lagi untuk diisi.
        $konteksDilepas = $this->konteksDilepasBerisi($bagian, $peran, $penempatan);

        // Perubahan + riwayatnya dalam satu transaksi, dengan baris bagian dikunci dan
        // dicocokkan dengan versi saat form dimuat: bila Tim SAKIP lain sudah mengubah
        // bagian ini, simpan dibatalkan (isian form tetap di layar).
        try {
            $this->dalamTransaksi(function () use ($id, $f, $peran, $penempatan, $kolom, $pembaca, $aturanBaru, $buktiWajib, $tampilan, $alurBaru, $periodeSimpan) {
                $bagian = BagianKustom::whereKey($id)->lockForUpdate()->firstOrFail();

                if (! isset($this->versiForm[$id]) || self::versi($bagian) !== $this->versiForm[$id]) {
                    throw new KonflikPenyuntinganException(['bagian' => [$bagian->nama]]);
                }

                $sebelum = $bagian->potretDefinisi();

                $bagian->update([
                    'nama' => trim($f['nama']),
                    'deskripsi' => trim((string) ($f['deskripsi'] ?? '')) ?: null,
                    'peran' => $peran,
                    'peran_pembaca' => $pembaca === [] ? null : $pembaca,
                    'hak_aksi' => self::hakAksiDariForm($bagian, (array) ($f['hak_aksi'] ?? []), $peran),
                    'penempatan' => $penempatan,
                    'label_uraian' => trim((string) ($f['label_uraian'] ?? '')) ?: null,
                    'frekuensi_wajib' => $f['frekuensi_wajib'],
                    // bukti_wajib tetap diisi (dibaca kode lama) — selaras dengan aturan bukti.
                    'bukti_wajib' => $buktiWajib,
                    'aturan_bukti' => $aturanBaru,
                    'tampilan' => $tampilan,
                    'masuk_notula' => (bool) $f['masuk_notula'],
                    'judul_notula' => trim((string) ($f['judul_notula'] ?? '')) ?: null,
                    'format_notula' => trim((string) ($f['format_notula'] ?? '')) ?: null,
                    'alur' => $alurBaru,
                    'periode_berlaku' => $periodeSimpan,
                    'kolom' => $kolom,
                    'subbagian' => $this->subbagianDariForm($id),
                ]);

                $sesudah = $bagian->fresh()->potretDefinisi();

                if ($sebelum != $sesudah) {
                    // Versi definisi naik satu; isian yang disimpan sesudahnya mencatat
                    // versi baru ini (BagianKustomPoin::booted), isian lama tetap versi lamanya.
                    $bagian->forceFill(['versi_konfigurasi' => $bagian->versiKonfigurasi() + 1])->save();
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
        $this->beritahuDaftar();

        session()->flash('status', 'Perubahan "'.$bagian->nama.'" disimpan. Isian yang sudah tersimpan tetap ada di notula.'
            .($konteksDilepas !== [] ? ' Perhatian: poin yang sudah diisi di '.implode(', ', $konteksDilepas).' tetap tersimpan, tetapi tidak tampil lagi untuk diisi.' : '')
            .($peringatanBukti !== null ? ' '.$peringatanBukti : ''));
    }

    /**
     * Peringatan bila bukti dukung yang SUDAH tersimpan tidak cocok dengan aturan baru
     * (format di luar daftar, atau jumlah per poin melebihi batas). Tidak mengubah apa pun.
     */
    protected function peringatanBerkasLama(BagianKustom $bagian, array $aturan): ?string
    {
        $poinIds = BagianKustomPoin::where('bagian_kustom_id', $bagian->id)->pluck('id');

        if ($poinIds->isEmpty()) {
            return null;
        }

        $berkas = \App\Models\Berkas::where('ref_type', BagianKustomPoin::class)->whereIn('ref_id', $poinIds)->get(['id', 'ref_id', 'nama_file']);
        $formatLain = $berkas->reject(fn ($b) => AturanBuktiBagianKustom::namaCocok($aturan, $b->nama_file))->count();
        $lebih = $aturan['maks'] !== null ? $berkas->groupBy('ref_id')->filter(fn ($g) => $g->count() > $aturan['maks'])->count() : 0;
        $pesan = [];

        if (! AturanBuktiBagianKustom::dipakai($aturan) && $berkas->isNotEmpty()) {
            $pesan[] = "{$berkas->count()} bukti dukung lama tetap tersimpan dan tetap bisa dibuka, walau bagian ini kini tidak memakai bukti";
        } else {
            if ($formatLain > 0) {
                $pesan[] = "{$formatLain} bukti lama berformat di luar aturan baru";
            }

            if ($lebih > 0) {
                $pesan[] = "{$lebih} poin punya bukti melebihi batas maksimal baru";
            }
        }

        return $pesan === [] ? null : 'Catatan bukti dukung: '.implode('; ', $pesan).'. Berkas lama tidak diubah maupun dihapus.';
    }

    /**
     * Pesan galat bila jenis isian kolom yang sudah berisi data diganti ke jenis yang
     * tidak cocok dengan data lamanya, null bila aman.
     *
     * @param  array<int, array<string, mixed>>  $kolomBaru
     */
    /**
     * Pengaturan alur dari form + pesan galat. Bagian lama (alur NULL) yang tetap pada
     * pengaturan bawaan dibiarkan NULL — tidak ada perubahan data tanpa perubahan arti.
     *
     * @param  array<string, mixed>  $f
     * @param  array<int, string>  $peran
     * @param  array<int, string>  $penempatan
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    protected function alurDariForm(BagianKustom $bagian, array $f, array $peran, array $penempatan): array
    {
        $a = (array) ($f['alur'] ?? []);
        $mode = array_key_exists((string) ($a['mode'] ?? ''), AlurBagianKustom::MODE) ? (string) $a['mode'] : AlurBagianKustom::MODE_LANGSUNG;
        $pengaju = array_values(array_intersect($peran, (array) ($a['pengaju'] ?? $peran)));
        $pemeriksa = array_values(array_intersect(array_keys(BagianKustom::NAMA_PERAN), (array) ($a['pemeriksa'] ?? [])));
        $alur = [
            'mode' => $mode,
            'pengaju' => $pengaju,
            'pemeriksa' => $pemeriksa,
            'ubah_setelah_disetujui' => (bool) ($a['ubah_setelah_disetujui'] ?? false),
            'notula' => array_key_exists((string) ($a['notula'] ?? ''), AlurBagianKustom::NOTULA) ? (string) $a['notula'] : AlurBagianKustom::NOTULA_SEMUA,
        ];

        // Tahapan berjenjang: disimpan hanya bila ada tahap lanjutan atau label tahap 1
        // diubah — bagian satu tahap tetap berbentuk lama.
        $lanjut = array_values(array_filter((array) ($a['tahap_lanjut'] ?? []), 'is_array'));
        $labelTahap1 = trim((string) ($a['label_tahap1'] ?? ''));

        if ($mode === AlurBagianKustom::MODE_VERIFIKASI && ($lanjut !== [] || $labelTahap1 !== '')) {
            $alur['tahapan'] = [['label' => $labelTahap1 ?: AlurBagianKustom::LABEL_TAHAP_BAWAAN, 'pemeriksa' => $pemeriksa]];

            foreach ($lanjut as $i => $t) {
                $peranTahap = array_values(array_intersect(array_keys(BagianKustom::NAMA_PERAN), (array) ($t['pemeriksa'] ?? [])));

                if ($peranTahap === []) {
                    return [null, 'Tahap pemeriksaan '.($i + 2).' belum punya peran pemeriksa. Centang perannya atau hapus tahap itu.'];
                }

                $alur['tahapan'][] = ['label' => trim((string) ($t['label'] ?? '')) ?: 'Tahap '.($i + 2), 'pemeriksa' => $peranTahap];
            }
        }

        $labelStatus = collect((array) ($a['label_status'] ?? []))
            ->only(array_keys(AlurBagianKustom::LABEL_STATUS))
            ->map(fn ($l) => trim((string) $l))
            ->filter(fn ($l, $s) => $l !== '' && $l !== AlurBagianKustom::LABEL_STATUS[$s])
            ->all();

        if ($mode === AlurBagianKustom::MODE_VERIFIKASI && $labelStatus !== []) {
            $alur['label_status'] = $labelStatus;
        }

        if ($mode === AlurBagianKustom::MODE_VERIFIKASI) {
            // Semua pemeriksa (semua tahap) harus bisa membuka menu penempatan.
            $pemeriksa = AlurBagianKustom::normalkan($alur, $peran)['pemeriksa'];
            $menuLain = array_values(array_diff($penempatan, [MenuBagianKustom::PENGISIAN]));

            if ($menuLain === []) {
                return [null, 'Alur pengajuan & pemeriksaan berlaku di menu selain Isian Kegiatan. Tempatkan bagian ini di menu lain (tab Informasi), atau pilih "Langsung sah". Isian Kegiatan selalu mengikuti alur verifikasi Isian Kegiatan.'];
            }

            if ($pengaju === []) {
                return [null, 'Pilih minimal satu peran pengisi yang boleh mengajukan.'];
            }

            if ($alur['pemeriksa'] === []) {
                return [null, 'Pilih minimal satu peran pemeriksa'.(isset($alur['tahapan']) ? ' untuk tahap 1.' : '.')];
            }

            foreach ($pemeriksa as $p) {
                if (! collect($menuLain)->contains(fn ($m) => in_array($p, MenuBagianKustom::peranDiizinkan($m), true))) {
                    return [null, 'Pemeriksa '.(BagianKustom::NAMA_PERAN[$p] ?? $p).' tidak bisa membuka menu mana pun tempat bagian ini ditempatkan. Tambahkan menu yang bisa dibukanya atau hapus centangnya.'];
                }
            }
        }

        // Bagian lama (alur NULL) yang tetap pada pengaturan bawaan dibiarkan NULL.
        $bawaan = AlurBagianKustom::normalkan(null, $peran);
        $efektif = AlurBagianKustom::normalkan($alur, $peran);
        unset($bawaan['diatur'], $efektif['diatur']);

        return [$bagian->alur === null && $efektif == $bawaan ? null : $alur, null];
    }

    /**
     * Pesan bila subkolom tabel yang sudah berisi data dihapus dari pengaturan.
     *
     * @param  array<int, array<string, mixed>>  $kolomBaru
     */
    protected function galatSubkolomDihapus(BagianKustom $bagian, array $kolomBaru): ?string
    {
        $baru = collect($kolomBaru)->keyBy('kunci');

        foreach ($bagian->kolomList() as $lama) {
            if ($lama['tipe'] !== 'tabel' || ($baru->get($lama['kunci'])['tipe'] ?? null) !== 'tabel') {
                continue;
            }

            $dihapus = collect($lama['tabel_kolom'])->pluck('kunci')->diff(collect($baru[$lama['kunci']]['tabel_kolom'])->pluck('kunci'));

            if ($dihapus->isEmpty()) {
                continue;
            }

            $nilai = BagianKustomPoin::where('bagian_kustom_id', $bagian->id)->whereNotNull('nilai_kolom')->pluck('nilai_kolom');

            foreach ($dihapus as $kunciSub) {
                $berisi = $nilai->filter(fn ($n) => collect(\App\Support\JenisKolomBagianKustom::barisTabel($n[$lama['kunci']] ?? ''))->contains(fn ($b) => filled($b[$kunciSub] ?? null)))->count();

                if ($berisi > 0) {
                    $label = collect($lama['tabel_kolom'])->firstWhere('kunci', $kunciSub)['label'] ?? $kunciSub;

                    return "Kolom tabel \"{$label}\" di \"{$lama['label']}\" sudah berisi data di {$berisi} poin, jadi tidak bisa dihapus. Klik \"Batal ubah\" untuk memulihkannya.";
                }
            }
        }

        return null;
    }

    protected function galatGantiTipe(BagianKustom $bagian, array $kolomBaru): ?string
    {
        $lama = collect($bagian->kolomList())->keyBy('kunci');
        $berganti = collect($kolomBaru)->filter(fn ($k) => $lama->has($k['kunci']) && (
            $lama[$k['kunci']]['tipe'] !== $k['tipe']
            // Sumber referensi diganti: identitas lama (mis. id IKU) tidak bermakna di sumber baru.
            || ($k['tipe'] === 'referensi' && ($lama[$k['kunci']]['sumber'] ?? null) !== ($k['sumber'] ?? null))
            // Pola kode diperketat: nilai lama harus tetap memenuhi pola baru.
            || ($k['tipe'] === 'kode' && ($lama[$k['kunci']]['pola'] ?? 'bebas') !== ($k['pola'] ?? 'bebas'))
        ));

        if ($berganti->isEmpty()) {
            return null;
        }

        $nilaiTersimpan = BagianKustomPoin::where('bagian_kustom_id', $bagian->id)->whereNotNull('nilai_kolom')->pluck('nilai_kolom');

        foreach ($berganti as $k) {
            // Jenis sama tetapi sumber/pola berubah: diperiksa sebagai "jenis berbeda" supaya
            // cocokDenganTipe() benar-benar menguji nilai lama terhadap aturan baru.
            $tipeLama = $lama[$k['kunci']]['tipe'] === $k['tipe'] ? '__'.$k['tipe'].'_lama' : $lama[$k['kunci']]['tipe'];

            // Nilai lama tidak pernah dikonversi — penggantian jenis hanya boleh bila
            // semua nilai tersimpan tetap bermakna dengan jenis baru.
            $gantiSumber = $tipeLama === '__referensi_lama';
            $tidakCocok = $nilaiTersimpan
                ->map(fn ($n) => trim((string) ($n[$k['kunci']] ?? '')))
                // Ganti sumber referensi: semua isian lama ditolak (id di sumber baru bisa
                // kebetulan ada tetapi menunjuk data yang berbeda).
                ->filter(fn ($v) => $gantiSumber ? $v !== '' : ! \App\Support\JenisKolomBagianKustom::cocokDenganTipe($v, $tipeLama, $k))
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
        $this->beritahuDaftar();
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
        $this->beritahuDaftar();
        session()->flash('status', 'Bagian kustom dihapus.');
    }

    /* ---------- Pratinjau (dihitung dari form, termasuk yang belum disimpan) ---------- */

    /**
     * Bagian SEMENTARA (tidak disimpan) dari isian form saat ini — dipakai pratinjau
     * formulir supaya yang dicoba persis sama dengan aturan yang akan diterbitkan.
     */
    protected function bagianDariForm(int $id): BagianKustom
    {
        $f = $this->form[$id];

        return new BagianKustom([
            'nama' => (string) ($f['nama'] ?? ''),
            'peran' => array_values((array) ($f['peran'] ?? [])),
            'penempatan' => array_values((array) ($f['penempatan'] ?? [])),
            'kolom' => $this->kolomDariFormTanpaError($id),
            'subbagian' => $this->subbagianDariForm($id),
        ]);
    }

    /** Jalankan validasi sungguhan atas isian contoh di tab Pratinjau. Tidak menyimpan apa pun. */
    public function ujiPratinjau(int $id): void
    {
        abort_unless(isset($this->form[$id]), 404);
        $this->resetErrorBag();

        $bagian = $this->bagianDariForm($id);
        $nilai = (array) ($this->pratinjauNilai[$id] ?? []);
        $galat = $bagian->galatNilaiPoin($nilai, null, null);

        foreach ($galat as $kunci => $pesan) {
            $this->addError("pratinjauNilai.{$id}.{$kunci}", $pesan);
        }

        $this->hasilPratinjau[$id] = $galat === [] ? $bagian->nilaiKolomTampil($bagian->bersihkanNilaiKolom($nilai)) : [];

        session()->flash('pratinjau-'.$id, $galat === []
            ? 'Isian contoh lolos semua aturan. Begini nilai yang akan tersimpan & tampil di notula.'
            : count($galat).' isian belum sesuai aturan — lihat pesan merah di formulir.');
    }

    public function kosongkanPratinjau(int $id): void
    {
        $this->resetErrorBag();
        unset($this->pratinjauNilai[$id], $this->hasilPratinjau[$id]);
    }

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
            $konteks = TemplateBagianKustom::konteks($kode, $indikator, $teks, trim((string) $f['nama']), 2026, 1, 3 - $i, $contohNilai,
                TemplateBagianKustom::VARIABEL_BAWAAN['sasaran_iku']['contoh'], TemplateBagianKustom::VARIABEL_BAWAAN['satuan_iku']['contoh']);
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
        $kelompokPratinjau = [];
        $bagianPratinjau = null;

        if ($this->bukaId !== null && isset($this->form[$this->bukaId])) {
            $kolomTampil = $this->kolomDariFormTanpaError($this->bukaId);
            $variabel = TemplateBagianKustom::semuaVariabel($kolomTampil);
            $pratinjau = $this->pratinjauNotula($this->bukaId);

            // Formulir pratinjau: hanya kolom & subbagian aktif, persis seperti formulir sungguhan.
            $bagianPratinjau = $this->bagianDariForm($this->bukaId);
            $kelompokPratinjau = $bagianPratinjau->kelompokKolomBerlaku(null, null);

            // Setiap kolom pratinjau harus punya nilai (kosong) supaya isian yang terikat
            // lewat entangle (tabel, pilihan ganda) menemukan propertinya.
            $this->pratinjauNilai[$this->bukaId] = ((array) ($this->pratinjauNilai[$this->bukaId] ?? [])) + $bagianPratinjau->nilaiKolomKosongForm();
        }

        $detail = $this->detailId ? BagianKustom::withCount('poin')->find($this->detailId) : null;
        $daftar = $this->daftar();

        return view('livewire.bagian-kustom-manager', [
            'katalogVariabel' => VariabelKatalog::orderBy('nama')->get(),
            'belumDisimpan' => $this->bukaId !== null && $this->adaPerubahanBelumDisimpan($this->bukaId),
            'modeBukti' => AturanBuktiBagianKustom::MODE,
            'formatBukti' => AturanBuktiBagianKustom::FORMAT,
            'daftar' => $daftar,
            'daftarTampil' => $this->daftarTampil(),
            'filterAktif' => $this->filterAktif(),
            'detail' => $detail,
            'kelengkapan' => $daftar->mapWithKeys(fn (BagianKustom $b) => [$b->id => KelengkapanBagianKustom::periksa($b)])->all(),
            'kelompokDetail' => $detail ? BagianKustom::kelompokkan($detail->kolomList(), $detail->subbagianList()) : [],
            'kelompokPratinjau' => $kelompokPratinjau,
            'bagianPratinjau' => $bagianPratinjau,
            'modeAlur' => AlurBagianKustom::MODE,
            'pilihanNotula' => AlurBagianKustom::NOTULA,
            'saatBukti' => AturanBuktiBagianKustom::SAAT,
            'operatorSyarat' => BagianKustom::OPERATOR_SYARAT,
            'tipeSubkolom' => \App\Support\JenisKolomBagianKustom::TIPE_SUBKOLOM,
            'formatTanggal' => \App\Support\JenisKolomBagianKustom::FORMAT_TANGGAL,
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
