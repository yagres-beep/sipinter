<?php

namespace App\Models;

use App\Exceptions\InvalidStatusTransitionException;
use App\Livewire\PengisianKegiatan;
use App\Services\FolderStructureService;
use App\Services\GoogleDriveService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Kegiatan extends Model
{
    use HasFactory;

    protected $table = 'kegiatan';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_DIAJUKAN = 'diajukan';

    public const STATUS_DIVERIFIKASI = 'diverifikasi';

    public const STATUS_DIKEMBALIKAN = 'dikembalikan';

    public const STATUS_DISETUJUI = 'disetujui';

    /**
     * Alur status dokumen sesuai SIPINTER_Flowchart.html (swimlane Ketua Tim / Tim SAKIP / Kepala):
     *
     *   draft ──ajukan()──> diajukan ──verifikasi()──> diverifikasi ──setujui()──> disetujui
     *                           │                            │  (dikonfirmasi lewat kompilasi notula
     *                           │                            │   oleh Kepala — lihat catatan setujui())
     *                           └──kembalikan()──> dikembalikan ──ajukan()──┘
     *                                                    ▲
     *                           diverifikasi ──kembalikan()──┘
     *
     * "Berkas sesuai?" pada diagram adalah keputusan Tim SAKIP saat status masih diajukan:
     * Ya → diverifikasi, Tidak → dikembalikan. Kegiatan yang sudah diverifikasi juga bisa
     * ditarik balik ke dikembalikan bila Kepala menemukan isian tsb bermasalah saat meninjau
     * notula (lihat NotulaService::kembalikanIsian()) — jalur cepat langsung ke Ketua Tim
     * tanpa menunggu Tim SAKIP meneruskannya secara manual. Ketua Tim memperbaiki isian yang
     * dikembalikan (dari kedua sumber ini) lalu mengajukan ulang (dikembalikan → diajukan).
     *
     * @var array<string, list<string>>
     */
    protected const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_DIAJUKAN],
        self::STATUS_DIAJUKAN => [self::STATUS_DIVERIFIKASI, self::STATUS_DIKEMBALIKAN],
        self::STATUS_DIKEMBALIKAN => [self::STATUS_DIAJUKAN],
        self::STATUS_DIVERIFIKASI => [self::STATUS_DISETUJUI, self::STATUS_DIKEMBALIKAN],
        self::STATUS_DISETUJUI => [],
    ];

    protected $fillable = [
        'iku_id',
        'rtl_evaluasi_id',
        'periode_id',
        'uraian_kegiatan',
        'status_verifikasi_uraian',
        'catatan_uraian',
        'catatan_bukti_dihapus',
        'jenis',
        'tahapan_survei',
        'nama_folder_auto',
        'nama_folder_kustom',
        'drive_folder_id',
        'status_dokumen',
    ];

    public function masterIku(): BelongsTo
    {
        return $this->belongsTo(MasterIku::class, 'iku_id');
    }

    /**
     * Nol atau lebih Rincian Output (RO) milik kegiatan ini (RF baru) — lihat
     * App\Models\RincianOutput. Diurutkan sesuai id (urutan input) supaya penomoran
     * "RO 1", "RO 2", ... di formulir & notula konsisten antar tampilan.
     */
    public function rincianOutput(): HasMany
    {
        return $this->hasMany(RincianOutput::class)->orderBy('id');
    }

    /**
     * Poin RTL triwulan berjalan yang "dilaksanakan" oleh kegiatan ini — diisi saat
     * uraian kegiatan dipilih dari dropdown rencana RTL (lihat PengisianKegiatan).
     */
    public function rtlEvaluasi(): BelongsTo
    {
        return $this->belongsTo(RtlEvaluasi::class, 'rtl_evaluasi_id');
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class, 'periode_id');
    }

    /**
     * Angka capaian dibagikan seluruh kegiatan pada IKU+periode yang sama (RF-38) —
     * bukan relasi Eloquent langsung karena kuncinya (iku_id, periode_id) bukan
     * kegiatan_id. Dipakai di tempat yang butuh angka capaian dari satu kegiatan.
     */
    public function capaian(): ?Capaian
    {
        return Capaian::where('iku_id', $this->iku_id)
            ->where('periode_id', $this->periode_id)
            ->first();
    }

    /**
     * Bukti capaian (PDF) milik kegiatan ini sendiri (RF-23) — wajib per kegiatan,
     * boleh lebih dari satu berkas.
     */
    public function berkas(): MorphMany
    {
        return $this->morphMany(Berkas::class, 'ref', 'ref_type', 'ref_id');
    }

    /**
     * Label LOKASI folder bukti dukung kegiatan ini (bukan nama capaian — itu
     * namaCapaian()): survei/sensus "<Tahapan> <uraian>" (mis. "Persiapan Survei
     * Sakernas", satu baris per tahapan), selain itu nama folder kegiatan. Keduanya
     * mengikuti nama yang diganti pengguna. Nama otomatis lama berformat
     * "[Persiapan] ..." ikut dirapikan tanpa kurung siku.
     */
    public function uraianTampil(): string
    {
        return (string) preg_replace('/^\[([^\]]+)\]\s*/u', '$1 ', $this->namaFolder());
    }

    /**
     * Nama capaian di notula = nama kegiatan utama (uraian_kegiatan, spasi berlebih
     * dirapikan), apa pun jenisnya. Kegiatan yang dipisah ke beberapa tahapan tetap
     * satu baris per tahapan di DB & Drive (folder "<Tahapan> <kegiatan>", diverifikasi
     * per tahapan), tapi nama folder tahapan — termasuk yang diganti pengguna — TIDAK
     * dipakai sebagai nama capaian. Lihat daftarNamaCapaian() untuk penyatuannya.
     */
    public function namaCapaian(): string
    {
        $uraian = (string) $this->uraian_kegiatan;

        return trim(preg_replace('/\s+/u', ' ', $uraian) ?? $uraian);
    }

    /**
     * Nama kegiatan induk hasil Kegiatan::terapkanNamaInduk() — tidak disimpan di DB.
     */
    public ?string $namaIndukTerhitung = null;

    /**
     * Nama utama yang ditampilkan untuk kegiatan ini: nama kegiatan induknya bila sudah
     * dihitung bersama tahapan-tahapan lainnya (terapkanNamaInduk()), selain itu
     * uraiannya sendiri (namaCapaian()).
     */
    public function namaKegiatanUtama(): string
    {
        return $this->namaIndukTerhitung ?? $this->namaCapaian();
    }

    /**
     * Daftar nama capaian untuk sekumpulan kegiatan SATU triwulan (pemanggil yang
     * menyaring triwulannya, lihat NotulaService::kumpulkanDataBagianSatu()) — satu
     * nama per kegiatan induk (kelompokInduk()), per IKU, urut kegiatan yang lebih
     * dulu dibuat. Dua kegiatan induk berbeda tetap tercatat dua kali walau namanya sama.
     *
     * @param  iterable<int, self>  $kegiatan
     * @return Collection<int, string>
     */
    public static function daftarNamaCapaian(iterable $kegiatan): Collection
    {
        return collect($kegiatan)
            ->sortBy('id')
            ->groupBy('iku_id')
            ->flatMap(fn (Collection $satuIku) => self::kelompokInduk($satuIku))
            ->sortBy(fn (Collection $kelompok) => $kelompok->min('id'))
            ->map(fn (Collection $kelompok) => self::namaUtamaKelompok($kelompok))
            ->filter(fn (string $nama) => $nama !== '')
            ->values();
    }

    /**
     * Kelompokkan baris-baris kegiatan menjadi kegiatan INDUK — satu kegiatan yang
     * dipisah ke beberapa tahapan tersimpan satu baris per tahapan (lihat
     * PengisianKegiatan::simpanBagianIsian()), dan kegiatan yang sama dari bulan lain
     * triwulan itu juga baris tersendiri. Belum ada kolom id induk, jadi identitasnya
     * diturunkan dari data yang sudah ada (lihat bisaSatuInduk()). Urutan masukan
     * dipertahankan (kelompok urut anggota pertamanya) begitu juga kunci masukan.
     *
     * @param  iterable<array-key, self>  $kegiatan
     * @return Collection<int, Collection<array-key, self>>
     */
    public static function kelompokInduk(iterable $kegiatan): Collection
    {
        $kelompok = [];

        foreach ($kegiatan as $kunci => $k) {
            foreach ($kelompok as $g => $anggota) {
                if (self::bisaSatuInduk($anggota, $k)) {
                    $kelompok[$g][$kunci] = $k;

                    continue 2;
                }
            }

            $kelompok[] = [$kunci => $k];
        }

        return collect($kelompok)->map(fn (array $anggota) => collect($anggota));
    }

    /**
     * Nomor "Kegiatan N" per kunci masukan — tahapan-tahapan satu kegiatan induk
     * berbagi nomor yang sama. Dipakai Isian Kegiatan & Verifikasi Capaian dengan
     * baris & urutan yang sama (semua kegiatan IKU+bulan itu, urut id), supaya
     * nomornya cocok di kedua menu.
     *
     * @param  iterable<array-key, self>  $kegiatan
     * @return array<array-key, int>
     */
    public static function nomorKegiatanInduk(iterable $kegiatan): array
    {
        $nomor = [];

        foreach (self::kelompokInduk($kegiatan)->values() as $i => $kelompok) {
            foreach ($kelompok->keys() as $kunci) {
                $nomor[$kunci] = $i + 1;
            }
        }

        return $nomor;
    }

    /**
     * Isi namaIndukTerhitung tiap kegiatan (per IKU) dengan nama kegiatan induknya.
     *
     * @param  iterable<array-key, self>  $kegiatan
     * @return Collection<int, Collection<array-key, self>> kelompok induk, urut id terkecil
     */
    public static function terapkanNamaInduk(iterable $kegiatan): Collection
    {
        $kelompok = collect($kegiatan)
            ->sortBy('id')
            ->groupBy('iku_id', preserveKeys: true)
            ->flatMap(fn (Collection $satuIku) => self::kelompokInduk($satuIku))
            ->values();

        foreach ($kelompok as $anggota) {
            $nama = self::namaUtamaKelompok($anggota);
            $anggota->each(fn (self $k) => $k->namaIndukTerhitung = $nama);
        }

        return $kelompok;
    }

    /**
     * Nama utama satu kegiatan induk: uraian tahapan yang dikoreksi Tim SAKIP
     * (uraianDikoreksi()) bila ada — koreksi itu yang terbaru & disengaja — selain itu
     * uraian tahapan pertamanya. Uraian tiap tahapan sendiri tidak diubah.
     *
     * @param  Collection<array-key, self>  $kelompok
     */
    public static function namaUtamaKelompok(Collection $kelompok): string
    {
        $terisi = $kelompok->filter(fn (self $k) => $k->namaCapaian() !== '');

        return ($terisi->first(fn (self $k) => $k->uraianDikoreksi()) ?? $terisi->first())?->namaCapaian() ?? '';
    }

    /**
     * Boleh tidaknya $k masuk kelompok $anggota: TIDAK bertentangan dengan satu pun
     * anggota, DAN ada bukti positif dengan setidaknya satu anggota.
     *
     * Bertentangan (pasti kegiatan berbeda): IKU/jenis berbeda, triwulan berbeda (bila
     * periodenya dimuat), tertaut ke poin RTL yang berbeda, atau bulan & tahapan sama
     * (satu kegiatan tidak mungkin punya tahapan yang sama dua kali dalam satu bulan —
     * dicegah PengisianKegiatan::tahapanTerpakai()).
     *
     * Bukti positif: uraian sama (abaikan besar/kecil huruf & spasi, definisi "kegiatan
     * yang sama" di PengisianKegiatan::kegiatanSama()), jejak uraian asal sama
     * (uraianAsal() — tetap walau uraian salah satu tahapan dikoreksi Tim SAKIP), atau
     * folder Drive yang sama. Poin RTL yang sama SAJA tidak cukup: satu poin RTL bisa
     * dilaksanakan oleh lebih dari satu kegiatan.
     *
     * @param  array<array-key, self>  $anggota
     */
    protected static function bisaSatuInduk(array $anggota, self $k): bool
    {
        $adaBukti = false;

        foreach ($anggota as $lain) {
            if (self::bertentangan($lain, $k)) {
                return false;
            }

            $adaBukti = $adaBukti || self::buktiSatuInduk($lain, $k);
        }

        return $adaBukti;
    }

    protected static function bertentangan(self $a, self $b): bool
    {
        if ((int) $a->iku_id !== (int) $b->iku_id || $a->jenis !== $b->jenis) {
            return true;
        }

        if ($a->rtl_evaluasi_id && $b->rtl_evaluasi_id && (int) $a->rtl_evaluasi_id !== (int) $b->rtl_evaluasi_id) {
            return true;
        }

        if ($a->relationLoaded('periode') && $b->relationLoaded('periode') && $a->periode && $b->periode
            && ((int) $a->periode->tahun !== (int) $b->periode->tahun || (int) $a->periode->triwulan !== (int) $b->periode->triwulan)) {
            return true;
        }

        return (int) $a->periode_id === (int) $b->periode_id && $a->kunciTahapan() === $b->kunciTahapan();
    }

    protected static function buktiSatuInduk(self $a, self $b): bool
    {
        $uraian = self::uraianNormal($a->uraian_kegiatan);

        if ($uraian !== '' && $uraian === self::uraianNormal($b->uraian_kegiatan)) {
            return true;
        }

        $asal = $a->uraianAsal();

        if ($asal !== null && $asal === $b->uraianAsal()) {
            return true;
        }

        return filled($a->drive_folder_id) && $a->drive_folder_id === $b->drive_folder_id;
    }

    protected function kunciTahapan(): ?string
    {
        return $this->jenis === 'survei_sensus' && filled($this->tahapan_survei) ? (string) $this->tahapan_survei : null;
    }

    /**
     * Jejak uraian kegiatan SAAT terakhir disimpan Ketua Tim, dibaca dari
     * nama_folder_auto ("<Tahapan> <uraian>", maks. 100 karakter, lihat
     * PengisianKegiatan::namaFolderOtomatis()) — kolom itu tidak ikut berubah saat
     * Tim SAKIP mengoreksi uraian di Verifikasi Capaian. Null bila tidak bisa
     * dipastikan (kosong / awalan tahapannya tidak cocok).
     */
    public function uraianAsal(): ?string
    {
        $auto = $this->namaFolderAutoRapi();

        if ($auto === '') {
            return null;
        }

        if (($tahapan = $this->kunciTahapan()) !== null) {
            $awalan = mb_strtolower(trim(PengisianKegiatan::uraianDenganTahapan('', $tahapan))).' ';

            if (! str_starts_with($auto, $awalan)) {
                return null;
            }

            $auto = mb_substr($auto, mb_strlen($awalan));
        }

        return $auto !== '' ? $auto : null;
    }

    /**
     * Uraian kegiatan ini sudah berbeda dari jejak uraian asalnya (uraianAsal()) —
     * artinya dikoreksi setelah disimpan Ketua Tim, yaitu oleh Tim SAKIP.
     */
    public function uraianDikoreksi(): bool
    {
        $auto = $this->namaFolderAutoRapi();

        if ($auto === '') {
            return false;
        }

        $seharusnya = Str::limit(PengisianKegiatan::uraianDenganTahapan((string) $this->uraian_kegiatan, $this->kunciTahapan()), 100, '');

        return self::uraianNormal($seharusnya) !== $auto;
    }

    /**
     * nama_folder_auto dinormalkan: format lama "[Persiapan] ..." tanpa kurung siku,
     * huruf kecil, spasi dirapikan.
     */
    protected function namaFolderAutoRapi(): string
    {
        return self::uraianNormal(preg_replace('/^\[([^\]]+)\]\s*/u', '$1 ', (string) $this->nama_folder_auto));
    }

    protected static function uraianNormal(?string $teks): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $teks) ?? ''));
    }

    /**
     * Nama folder Drive yang berlaku: nama pilihan pengguna (nama_folder_kustom)
     * bila diisi, selain itu rekomendasi sistem (nama_folder_auto, RF-17).
     */
    public function namaFolder(): string
    {
        return $this->nama_folder_kustom ?: ($this->nama_folder_auto ?: $this->uraian_kegiatan);
    }

    /**
     * Ganti nama folder Drive kegiatan ini (Ketua Tim/Tim SAKIP). Nama kosong atau
     * sama persis dengan rekomendasi sistem berarti kembali ke nama otomatis. Bila
     * foldernya sudah dibuat di Drive, ikut diganti di sana (ID folder tetap sama,
     * tautan lama tidak rusak) — gagal di Drive TIDAK membatalkan perubahan di
     * aplikasi, pesan galatnya dikembalikan sebagai peringatan (pola Berkas::gantiNama()).
     *
     * @return array{nama_folder: string, peringatan: ?string}
     */
    public function gantiNamaFolder(?string $namaBaru): array
    {
        $nama = FolderStructureService::namaOtomatis((string) $namaBaru, 150);
        $kustom = ($nama === '' || $nama === $this->nama_folder_auto) ? null : $nama;

        $namaLama = $this->namaFolder();
        $this->nama_folder_kustom = $kustom;
        $namaBerlaku = $this->namaFolder();
        $peringatan = null;

        if ($this->drive_folder_id && $namaBerlaku !== $namaLama) {
            try {
                app(GoogleDriveService::class)->renameFile($this->drive_folder_id, FolderStructureService::namaOtomatis($namaBerlaku, 150));
            } catch (\Throwable $e) {
                $peringatan = 'Nama folder di aplikasi sudah diubah, tapi gagal mengubah nama di Google Drive: '.$e->getMessage();
            }
        }

        $this->save();

        // Kegiatan yang sama dari bulan lain triwulan ini bisa memakai folder Drive
        // yang SAMA (lihat FolderStructureService::resolveKegiatanFolder()) — nama
        // foldernya ikut disamakan supaya tampilan & notula tidak berbeda.
        if ($this->drive_folder_id && $namaBerlaku !== $namaLama) {
            static::where('drive_folder_id', $this->drive_folder_id)->whereKeyNot($this->id)->get()
                ->each(fn (Kegiatan $lain) => $lain->update([
                    'nama_folder_kustom' => $namaBerlaku === $lain->nama_folder_auto ? null : $namaBerlaku,
                ]));
        }

        return ['nama_folder' => $namaBerlaku, 'peringatan' => $peringatan];
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status_dokumen] ?? [], true);
    }

    /**
     * Rincian jumlah kegiatan per status_dokumen dalam satu Capaian (IKU+bulan) —
     * mis. 3 diverifikasi + 2 dikembalikan — dipakai di tabel dasbor/daftar verifikasi
     * SUPAYA status besar satu Capaian (Capaian::status, satu-satunya yang menentukan
     * alur bisa-diverifikasi/tidak) tetap bisa ditelusuri sampai ke rincian per
     * kegiatannya tanpa harus membuka detail. Hanya status yang benar-benar ada
     * (jumlah > 0) yang disertakan, diurutkan sesuai alur (draft→...→disetujui) supaya
     * tampilannya konsisten di semua tabel.
     *
     * @param  \Illuminate\Support\Collection<int, self>  $kegiatanSatuIkuPeriode
     * @return \Illuminate\Support\Collection<string, int>
     */
    public static function rincianStatus($kegiatanSatuIkuPeriode)
    {
        $jumlahPerStatus = $kegiatanSatuIkuPeriode->countBy('status_dokumen');

        return collect([
            self::STATUS_DRAFT,
            self::STATUS_DIAJUKAN,
            self::STATUS_DIVERIFIKASI,
            self::STATUS_DIKEMBALIKAN,
            self::STATUS_DISETUJUI,
        ])->mapWithKeys(fn ($status) => [$status => $jumlahPerStatus->get($status, 0)])
            ->filter(fn ($jumlah) => $jumlah > 0);
    }

    protected function transitionTo(string $status): void
    {
        if (! $this->canTransitionTo($status)) {
            throw new InvalidStatusTransitionException($this->status_dokumen, $status);
        }

        $this->update(['status_dokumen' => $status]);
    }

    /**
     * Ketua Tim mengajukan isian (draft/dikembalikan → diajukan).
     */
    public function ajukan(): void
    {
        $this->transitionTo(self::STATUS_DIAJUKAN);
    }

    /**
     * Tim SAKIP menandai isian terverifikasi setelah seluruh berkas sesuai (diajukan → diverifikasi).
     * catatan_bukti_dihapus (pengingat bukti tertolak yang sudah dihapus Ketua Tim, lihat
     * migrasi 2026_08_29_000001) ikut dikosongkan di sini — begitu Kegiatan ini
     * "diverifikasi", pengingatnya sudah tidak relevan lagi.
     */
    public function verifikasi(): void
    {
        $this->transitionTo(self::STATUS_DIVERIFIKASI);
        $this->update(['catatan_bukti_dihapus' => null]);
    }

    /**
     * Mengembalikan isian ke Ketua Tim untuk diperbaiki — dipicu Tim SAKIP saat ada berkas
     * tidak sesuai (diajukan → dikembalikan), ATAU Kepala saat meninjau notula dan menemukan
     * isian yang sudah diverifikasi ternyata bermasalah (diverifikasi → dikembalikan, lihat
     * NotulaService::kembalikanIsian()).
     */
    public function kembalikan(): void
    {
        $this->transitionTo(self::STATUS_DIKEMBALIKAN);
    }

    /**
     * Isian ikut disetujui saat notula triwulanan yang memuatnya disetujui Kepala (diverifikasi → disetujui).
     *
     * Belum ada pemicu konkret di controller — disediakan agar model siap dipakai begitu
     * alur kompilasi & persetujuan notula (RF-41 s.d. RF-44a) dibangun.
     */
    public function setujui(): void
    {
        $this->transitionTo(self::STATUS_DISETUJUI);
    }
}
