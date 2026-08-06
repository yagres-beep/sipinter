<?php

namespace Database\Seeders;

use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\Role;
use App\Models\RtlEvaluasi;
use App\Models\StorageAccount;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Data CONTOH untuk demonstrasi (bukan untuk produksi): satu akun per peran,
 * beberapa Master IKU, dan satu siklus data capaian LENGKAP (kegiatan tiga
 * bulan → capaian terverifikasi → kendala-solusi → RTL dievaluasi & RTL baru)
 * supaya fitur Verifikasi, Kendala & Solusi, RTL & Evaluasi, Kompilasi Notula,
 * Rekapitulasi, dan Dasbor semuanya langsung terlihat terisi tanpa perlu
 * mengisi manual dari awal.
 *
 * Jalankan lewat:
 *   php artisan migrate --seed
 * atau bila migrasi sudah pernah dijalankan sebelumnya:
 *   php artisan db:seed
 *
 * Aman dijalankan berulang kali — seluruh baris dibuat lewat firstOrCreate()
 * sehingga tidak menghasilkan duplikat pada eksekusi kedua dan seterusnya.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->buatAkunDemo();
        $storageAccount = $this->buatStorageAccountDemo();
        $ikuList = $this->buatMasterIkuDemo();

        $tahun = (int) now()->year;
        $triwulanSekarang = (int) ceil(((int) now()->month) / 3);
        $triwulanSebelumnya = $triwulanSekarang === 1 ? 4 : $triwulanSekarang - 1;
        $tahunSebelumnya = $triwulanSekarang === 1 ? $tahun - 1 : $tahun;

        $this->buatSiklusCapaianLengkap($ikuList[0], $storageAccount, $tahun, $triwulanSekarang, $tahunSebelumnya, $triwulanSebelumnya);
        $this->buatCapaianRingan($ikuList[1], $storageAccount, $tahun, $triwulanSekarang);
        $this->buatCapaianRingan($ikuList[2], $storageAccount, $tahun, $triwulanSekarang);
    }

    /**
     * Satu akun per peran (RoleSeeder sudah menjamin ketiga peran ada). Akun Tim
     * SAKIP memakai email yang sama dengan bootstrap admin di DatabaseSeeder —
     * firstOrCreate menemukan baris yang sama, jadi tidak membuat akun ganda.
     */
    protected function buatAkunDemo(): void
    {
        $roles = Role::all()->keyBy('nama');

        User::firstOrCreate(
            ['email' => 'ketuatim@sipinter.bps.go.id'],
            [
                'nama' => 'Yulinda Agrestina',
                'password' => 'password',
                'role_id' => $roles['Ketua Tim']->id,
                'status_verifikasi' => 'terverifikasi',
            ]
        );

        User::firstOrCreate(
            ['email' => 'admin@sipinter.bps.go.id'],
            [
                'nama' => 'Admin Tim SAKIP',
                'password' => 'password',
                'role_id' => $roles['Tim SAKIP']->id,
                'status_verifikasi' => 'terverifikasi',
            ]
        );

        User::firstOrCreate(
            ['email' => 'kepala@sipinter.bps.go.id'],
            [
                'nama' => 'Kepala BPS Buton Utara',
                'password' => 'password',
                'role_id' => $roles['Kepala']->id,
                'status_verifikasi' => 'terverifikasi',
            ]
        );
    }

    protected function buatStorageAccountDemo(): StorageAccount
    {
        $akun = StorageAccount::firstOrCreate(
            ['email_gmail_institusi' => 'sipinter.demo@gmail.com'],
            [
                'status' => StorageAccount::STATUS_PENUH,
                'kuota_terpakai' => 2.4,
                'kuota_total' => 15,
            ]
        );

        if ($akun->status !== StorageAccount::STATUS_AKTIF) {
            $akun->jadikanAktif();
        }

        return $akun->fresh();
    }

    /**
     * @return list<MasterIku>
     */
    protected function buatMasterIkuDemo(): array
    {
        $data = [
            [
                'kode' => 'IKU-1131',
                'indikator' => 'Persentase publikasi statistik yang terbit tepat waktu',
                'tim' => 'Distribusi dan Layanan Statistik',
                'penanggung_jawab' => 'Kepala Seksi IPDS',
                'sasaran' => 'Kualitas Layanan dan Diseminasi Statistik',
            ],
            [
                'kode' => 'IKU-2112',
                'indikator' => 'Persentase konsumen yang puas dengan kualitas data statistik',
                'tim' => 'Statistik Produksi',
                'penanggung_jawab' => 'Kepala Seksi Statistik Produksi',
                'sasaran' => 'Peningkatan Kepuasan Konsumen Data Statistik',
            ],
            [
                'kode' => 'IKU-3105',
                'indikator' => 'Jumlah publikasi statistik sektoral yang terbit',
                'tim' => 'Statistik Distribusi',
                'penanggung_jawab' => 'Kepala Seksi Statistik Distribusi',
                'sasaran' => 'Pembinaan Statistik Sektoral',
            ],
        ];

        return collect($data)
            ->map(fn ($row) => MasterIku::firstOrCreate(['kode' => $row['kode']], $row))
            ->all();
    }

    /**
     * Satu siklus data capaian LENGKAP untuk satu IKU: kegiatan + capaian
     * terverifikasi di tiga bulan triwulan berjalan (RF-18 s.d. RF-25, RF-36 s.d.
     * RF-40), kendala-solusi (RF-26/27), RTL triwulan sebelumnya yang sudah
     * dievaluasi, dan RTL triwulan berjalan yang baru ditetapkan (RF-29 s.d. RF-34).
     */
    protected function buatSiklusCapaianLengkap(MasterIku $iku, StorageAccount $storageAccount, int $tahun, int $triwulan, int $tahunSebelumnya, int $triwulanSebelumnya): void
    {
        $tahapanPerBulanKe = ['persiapan', 'pelaksanaan', 'pengolahan'];
        $uraianPerBulanKe = [
            'Persiapan instrumen dan pelatihan petugas',
            'Pencacahan lapangan',
            'Pengolahan dan pemeriksaan data',
        ];

        $bulanPertama = ($triwulan - 1) * 3 + 1;

        foreach (range(0, 2) as $i) {
            $bulan = $bulanPertama + $i;

            $periode = Periode::firstOrCreate(
                ['tahun' => $tahun, 'bulan' => $bulan],
                [
                    'triwulan' => $triwulan,
                    'bulan_ke' => $i + 1,
                    'flag_bulan_terlewat' => ! ($tahun === (int) now()->year && $bulan === (int) now()->month),
                ]
            );

            $kegiatan = Kegiatan::firstOrCreate(
                ['iku_id' => $iku->id, 'periode_id' => $periode->id],
                [
                    'uraian_kegiatan' => $uraianPerBulanKe[$i],
                    'jenis' => 'survei_sensus',
                    'tahapan_survei' => $tahapanPerBulanKe[$i],
                    'nama_folder_auto' => '['.ucfirst($tahapanPerBulanKe[$i]).'] '.$uraianPerBulanKe[$i],
                    'status_dokumen' => Kegiatan::STATUS_DRAFT,
                ]
            );

            if ($kegiatan->status_dokumen === Kegiatan::STATUS_DRAFT) {
                $kegiatan->ajukan();
            }

            Capaian::firstOrCreate(
                ['iku_id' => $iku->id, 'periode_id' => $periode->id],
                [
                    'analisis_capaian' => 'Capaian tahap '.$tahapanPerBulanKe[$i].' berjalan sesuai rencana.',
                    'target_pk' => 100,
                    'target_tw' => 75,
                    'realisasi' => 24,
                    'persentase_capaian' => 96,
                ]
            );

            Berkas::firstOrCreate(
                ['ref_id' => $kegiatan->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian'],
                [
                    'nama_file' => 'bukti-capaian.pdf',
                    'status_verifikasi' => 'terverifikasi',
                    'storage_account_id' => $storageAccount->id,
                ]
            );

            if ($kegiatan->status_dokumen === Kegiatan::STATUS_DIAJUKAN) {
                $kegiatan->verifikasi();
            }
        }

        $periodePertama = Periode::where('tahun', $tahun)->where('bulan', $bulanPertama)->first();

        // Kendala & solusi (kumulatif) — dicatat pada bulan pertama triwulan berjalan.
        $kendalaSolusi = KendalaSolusi::firstOrCreate(
            ['iku_id' => $iku->id, 'periode_id' => $periodePertama->id],
            [
                'kendala' => 'Keterlambatan pengumpulan dokumen dari mitra kerja.',
                'solusi' => 'Percepatan koordinasi dan pengingat berkala kepada mitra.',
            ]
        );

        Berkas::firstOrCreate(
            ['ref_id' => $kendalaSolusi->id, 'ref_type' => KendalaSolusi::class, 'kategori' => 'solusi'],
            [
                'nama_file' => 'bukti-solusi.pdf',
                'status_verifikasi' => 'terverifikasi',
                'storage_account_id' => $storageAccount->id,
            ]
        );

        // RTL triwulan sebelumnya — sudah dievaluasi, menunjukkan siklus RF-29 s.d. RF-35 penuh.
        $bulanPertamaSebelumnya = ($triwulanSebelumnya - 1) * 3 + 1;
        $bulanTerakhirSebelumnya = $bulanPertamaSebelumnya + 2;

        $periodeSebelumnya = Periode::firstOrCreate(
            ['tahun' => $tahunSebelumnya, 'bulan' => $bulanPertamaSebelumnya],
            ['triwulan' => $triwulanSebelumnya, 'bulan_ke' => 1, 'flag_bulan_terlewat' => true]
        );

        RtlEvaluasi::firstOrCreate(
            ['iku_id' => $iku->id, 'periode_id' => $periodeSebelumnya->id],
            [
                'rtl_teks' => 'Melakukan pelatihan intensif petugas lapangan mengenai teknik wawancara.',
                'berlaku_bulan' => 'RTL untuk seluruh bulan triwulan sebelumnya',
                'pic' => 'Koordinator Lapangan',
                'batas_waktu' => Carbon::create($tahunSebelumnya, $bulanTerakhirSebelumnya, 1)->endOfMonth()->toDateString(),
                'realisasi' => 'Pelatihan telah dilaksanakan untuk seluruh petugas lapangan sebelum pencacahan dimulai.',
                'status_cocok' => RtlEvaluasi::STATUS_COCOK,
            ]
        );

        // RTL triwulan berjalan — baru ditetapkan, belum dievaluasi (RF-32).
        $bulanTerakhirBerjalan = $bulanPertama + 2;

        RtlEvaluasi::firstOrCreate(
            ['iku_id' => $iku->id, 'periode_id' => $periodePertama->id],
            [
                'rtl_teks' => 'Meningkatkan pengawasan lapangan untuk menjaga kualitas data.',
                'berlaku_bulan' => 'RTL untuk seluruh bulan triwulan berjalan',
                'pic' => 'Koordinator Lapangan',
                'batas_waktu' => Carbon::create($tahun, $bulanTerakhirBerjalan, 1)->endOfMonth()->toDateString(),
            ]
        );
    }

    /**
     * Versi ringan: satu kegiatan + capaian terverifikasi saja (tanpa kendala/RTL),
     * supaya Rekapitulasi & Dasbor punya lebih dari satu IKU untuk dibandingkan.
     */
    protected function buatCapaianRingan(MasterIku $iku, StorageAccount $storageAccount, int $tahun, int $triwulan): void
    {
        $bulan = ($triwulan - 1) * 3 + 2;

        $periode = Periode::firstOrCreate(
            ['tahun' => $tahun, 'bulan' => $bulan],
            ['triwulan' => $triwulan, 'bulan_ke' => 2, 'flag_bulan_terlewat' => false]
        );

        $kegiatan = Kegiatan::firstOrCreate(
            ['iku_id' => $iku->id, 'periode_id' => $periode->id],
            [
                'uraian_kegiatan' => 'Kompilasi dan analisis data '.$iku->indikator,
                'jenis' => 'bukan_survei_sensus',
                'nama_folder_auto' => 'Kompilasi dan analisis data '.$iku->indikator,
                'status_dokumen' => Kegiatan::STATUS_DRAFT,
            ]
        );

        if ($kegiatan->status_dokumen === Kegiatan::STATUS_DRAFT) {
            $kegiatan->ajukan();
        }

        Capaian::firstOrCreate(
            ['iku_id' => $iku->id, 'periode_id' => $periode->id],
            [
                'analisis_capaian' => 'Capaian sesuai target triwulan berjalan.',
                'target_pk' => 100,
                'target_tw' => 80,
                'realisasi' => 78,
                'persentase_capaian' => 97.5,
            ]
        );

        Berkas::firstOrCreate(
            ['ref_id' => $kegiatan->id, 'ref_type' => Kegiatan::class, 'kategori' => 'capaian'],
            [
                'nama_file' => 'bukti-capaian.pdf',
                'status_verifikasi' => 'terverifikasi',
                'storage_account_id' => $storageAccount->id,
            ]
        );

        if ($kegiatan->status_dokumen === Kegiatan::STATUS_DIAJUKAN) {
            $kegiatan->verifikasi();
        }
    }
}
