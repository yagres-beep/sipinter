<?php

namespace App\Services;

use App\Models\BagianKustom;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\CapaianTahunan;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\Periode;
use App\Models\RtlEvaluasi;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf as PdfFacade;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Mengorkestrasi seluruh siklus notula triwulanan (RF-41 s.d. RF-44a): menyusun
 * Bagian I otomatis, menerima & mengonversi Bagian II/III, menggabungkan ketiganya,
 * lalu memfasilitasi alur unduh draf/final dan arsip ke Drive setelah disetujui.
 *
 * Berkas Bagian I/II/III & hasil gabungan disimpan di disk LOKAL ('local', yaitu
 * storage/app/private) — HANYA PDF FINAL (setelah disetujui Kepala) yang diarsipkan
 * ke Google Drive (RF-44a), lewat FolderStructureService::unggahArsipNotula().
 */
class NotulaService
{
    public function __construct(
        protected LibreOfficeConversionService $konversi,
        protected FolderStructureService $folder,
        protected NotulaBagian1DocxService $docx,
    ) {}

    /**
     * Cari/siapkan baris Notula milik satu triwulan. Notula berskala TRIWULAN, jadi
     * dianchor ke periode BULAN PERTAMA triwulan tsb — konvensi yang sama dipakai
     * RtlEvaluasi untuk data yang sebenarnya triwulanan, supaya FK periode_id valid.
     */
    public function untukTriwulan(int $tahun, int $triwulan): Notula
    {
        $bulanPertama = ($triwulan - 1) * 3 + 1;

        $periode = Periode::firstOrCreate(
            ['tahun' => $tahun, 'bulan' => $bulanPertama],
            ['triwulan' => $triwulan, 'bulan_ke' => 1, 'flag_bulan_terlewat' => false]
        );

        return Notula::firstOrCreate(['periode_id' => $periode->id]);
    }

    /**
     * RF-42: susun ULANG Bagian I dari data terverifikasi (capaian, kendala-solusi
     * kumulatif, RTL berjalan) lalu simpan sebagai HTML. Dipanggil eksplisit lewat
     * tombol "Susun Ulang Otomatis" — akan MENIMPA suntingan manual sebelumnya,
     * jadi TIDAK dipanggil diam-diam di render() setiap request.
     *
     * Kontennya dihasilkan dari template .docx RESMI yang sama dipakai jalur unduhan
     * (NotulaBagian1DocxService, diisi dari data $data yang sama), lalu dikonversi ke
     * HTML lewat LibreOffice headless -- BUKAN blade HTML terpisah yang harus disunting
     * manual tiap kali format template berubah. Begitu Tim SAKIP mengunggah template
     * baru lewat Pengaturan > Template Notula, tata letak/font/border Bagian I ikut
     * berubah otomatis tanpa perlu ubah kode, sama seperti Bagian II/III yang sudah
     * lebih dulu memakai pola konversi ini (lihat terimaUploadBagian()).
     */
    public function susunBagianSatu(Notula $notula): string
    {
        $data = $this->kumpulkanDataBagianSatu($notula);

        $dir = storage_path("app/private/notula/{$notula->id}/bagian1-sementara");
        $this->pastikanFolder($dir);
        $docxPath = $dir.'/bagian1.docx';

        $this->docx->generate($notula, $data, $docxPath);

        try {
            $html = $this->konversi->convertToHtml($docxPath, $dir);
        } finally {
            @unlink($docxPath);
        }

        $notula->update(['bagian1_html' => $html]);

        return $html;
    }

    /**
     * Kumpulkan seluruh data siap-pakai untuk Bagian I (RF-42) — dipakai baik oleh
     * susunBagianSatu() (mengisi template lalu konversi ke HTML) maupun jalur unduhan
     * .docx murni (NotulaDownloadController::unduhBagian1Docx()), keduanya lewat
     * NotulaBagian1DocxService::generate(), supaya kedua jalur SELALU bersumber dari
     * query yang sama persis dan tidak pernah berbeda datanya.
     *
     * @return array<string, mixed>
     */
    public function kumpulkanDataBagianSatu(Notula $notula): array
    {
        $periode = $notula->periode;
        $tw = $periode->triwulan;

        // Seluruh Sasaran/IKU resmi ditampilkan sebagai kerangka baku (RF-42, sesuai
        // Template Notula Monitoring Kinerja Triwulanan BPS), bukan cuma IKU yang
        // kebetulan sudah punya kegiatan triwulan ini — supaya dokumen selalu tampil
        // lengkap sebagai "template siap isi" dan Tim SAKIP tinggal melengkapi sel
        // yang masih kosong, bukan menambah baris/tabel baru secara manual.
        $sasaranPerIku = MasterIku::whereNotNull('sasaran')->where('sasaran', '!=', '')
            ->orderBy('kode')
            ->get()
            ->groupBy('sasaran');

        $kegiatanPerIku = Kegiatan::with(['masterIku', 'rincianOutput'])
            ->whereHas('periode', fn ($q) => $q->where('tahun', $periode->tahun)->where('triwulan', $tw))
            ->whereIn('status_dokumen', [Kegiatan::STATUS_DIVERIFIKASI, Kegiatan::STATUS_DISETUJUI])
            ->get()
            ->groupBy('iku_id');

        // RF baru: satu Kegiatan boleh punya BANYAK RO (App\Models\RincianOutput),
        // jadi tabel "Realisasi Volume RO..." di notula sumbernya diratakan dari
        // SELURUH RO milik seluruh kegiatan IKU ini -- bukan satu baris per kegiatan
        // seperti sebelumnya (saat RO masih kolom tunggal langsung di tabel kegiatan).
        // Relasi kegiatan disetel manual (bukan lazy-load) supaya fallback "pakai
        // uraian_kegiatan bila RO belum diberi nama sendiri" tidak memicu query
        // tambahan per baris RO -- Kegiatan-nya sudah ada di memori lewat $kegiatanPerIku.
        $roPerIku = $kegiatanPerIku->map(function ($daftarKegiatan) {
            return $daftarKegiatan->flatMap(function ($kegiatan) {
                return $kegiatan->rincianOutput->each(fn ($ro) => $ro->setRelation('kegiatan', $kegiatan));
            });
        });

        $capaianPerIku = Capaian::where('periode_id', $periode->id)->get()->keyBy('iku_id');

        // Target PK/Target TW/Realisasi/Capaian % — sumber tunggalnya CapaianTahunan
        // (diisi Tim SAKIP sekali per tahun, lihat App\Livewire\VerifikasiCapaian),
        // BUKAN kolom target_pk/target_tw lama di Capaian — sama seperti yang dipakai
        // App\Livewire\DasborCapaian::dataRekap(), supaya angka di notula selalu
        // konsisten dengan yang tampil di dasbor.
        $capaianTahunanPerIku = CapaianTahunan::with('masterIku')->where('tahun', $periode->tahun)->get()->keyBy('iku_id');

        $rekapPerIku = $sasaranPerIku->flatten()->mapWithKeys(function (MasterIku $iku) use ($capaianTahunanPerIku, $tw) {
            $ct = $capaianTahunanPerIku->get($iku->id);

            return [$iku->id => [
                'target_pk' => $ct?->targetTahunan(),
                'target_tw' => $ct?->alokasiKumulatif($tw),
                'realisasi' => $ct ? (float) ($ct->{"realisasi_tw{$tw}"} ?? 0) : null,
                'capaian_tw' => $ct?->capaianTriwulanan($tw),
                'capaian_pk' => $ct?->capaianSetahun($tw),
                // Untuk rumus "Dasar Hitung" IKU bersatuan Persen (lihat
                // NotulaBagian1DocxService::formulaPersenOtomatis()) -- n = nilai
                // MENTAH triwulan berjalan saja (x_realisasi_twN, BUKAN kumulatif,
                // lihat docblock CapaianTahunan), N = target tahunan Y (konstan,
                // TIDAK dijumlahkan tiap triwulan seperti alokasi/realisasi).
                'x_realisasi_tw' => $ct?->{"x_realisasi_tw{$tw}"},
                'y_target' => $ct?->y_target,
            ]];
        });

        // RF-28: kumulatif dari triwulan 1 s.d. triwulan berjalan, tahun yang sama —
        // dikelompokkan per IKU dulu (kerangka resmi menampilkan Kendala/Solusi di
        // bawah tiap indikator, bukan sebagai satu daftar gabungan seperti sebelumnya),
        // baru per triwulan di dalamnya supaya kumulatifnya tetap terlihat jelas.
        $kendalaSolusiPerIku = KendalaSolusi::with('periode')
            ->whereHas('periode', fn ($q) => $q->where('tahun', $periode->tahun)->where('triwulan', '<=', $tw))
            ->get()
            ->groupBy('iku_id')
            ->map(fn ($items) => $items->sortBy(fn ($k) => $k->periode->triwulan)->groupBy(fn ($k) => $k->periode->triwulan));

        $rtlPerIku = RtlEvaluasi::whereHas('periode', fn ($q) => $q->where('tahun', $periode->tahun)->where('triwulan', $tw))
            ->get()
            ->groupBy('iku_id');

        // RTL triwulan SEBELUMNYA (tahun yang sama) per IKU — dicetak sebagai narasi
        // "RTL triwulan X yang telah dilaksanakan pada triwulan Y" di blok Dasar Hitung/
        // Bukti Dukung, sesuai Template Notula resmi. Triwulan I tidak punya triwulan
        // sebelumnya (tahun yang sama), jadi selalu kosong.
        $rtlSebelumnyaPerIku = $tw > 1
            ? RtlEvaluasi::whereHas('periode', fn ($q) => $q->where('tahun', $periode->tahun)->where('triwulan', $tw - 1))
                ->get()
                ->groupBy('iku_id')
            : collect();

        // Bagian kustom (mis. Manajemen Risiko) — SEMUA bagian yang punya poin di
        // triwulan ini ikut ditampilkan, termasuk yang sudah dinonaktifkan Tim SAKIP
        // setelahnya, supaya data historis notula tidak pernah hilang dari catatan.
        $bagianKustomPerBagian = BagianKustom::query()
            ->whereHas('poin', fn ($q) => $q->whereHas(
                'periode',
                fn ($q2) => $q2->where('tahun', $periode->tahun)->where('triwulan', $tw)
            ))
            ->with(['poin' => function ($q) use ($periode, $tw) {
                $q->with('masterIku')->whereHas(
                    'periode',
                    fn ($q2) => $q2->where('tahun', $periode->tahun)->where('triwulan', $tw)
                );
            }])
            ->get();

        // RF baru: link bukti dukung per IKU (folder Drive IKU tsb), supaya Tim SAKIP/
        // Kepala bisa langsung buka bukti dari dalam dokumen notula. Dihitung sekali per
        // IKU yang benar-benar punya kegiatan triwulan ini (bukan seluruh Master IKU) —
        // gagal Drive pada satu IKU (mis. storage belum aktif) tidak menggagalkan
        // penyusunan notula.
        $linkFolderPerIku = $kegiatanPerIku->mapWithKeys(function ($daftarKegiatan, $ikuId) use ($periode) {
            return [$ikuId => $this->folder->linkBuktiDukungIku($periode, $daftarKegiatan->first()->masterIku)];
        });

        // Tautan bukti dukung RTL triwulan SEBELUMNYA (F1.3) — periode sebelumnya dibuat
        // sebagai instance Periode SEMENTARA (tidak disimpan), karena linkBuktiDukungIku()
        // hanya membaca tahun/triwulan-nya untuk menyusun path folder, bukan query DB
        // lewat periode itu sendiri — supaya lookup ini tidak diam-diam membuat baris
        // periode baru untuk triwulan yang mungkin belum pernah tersentuh. Triwulan I
        // tidak punya triwulan sebelumnya (tahun yang sama), jadi selalu kosong.
        $linkFolderTwSebelumnyaPerIku = $tw > 1
            ? $kegiatanPerIku->mapWithKeys(function ($daftarKegiatan, $ikuId) use ($periode, $tw) {
                $periodeSebelumnya = new Periode(['tahun' => $periode->tahun, 'triwulan' => $tw - 1]);

                return [$ikuId => $this->folder->linkBuktiDukungIku($periodeSebelumnya, $daftarKegiatan->first()->masterIku)];
            })
            : collect();

        // Ringkasan capaian pada paragraf pembuka (RF-42): rata-rata capaian_tw/capaian_pk
        // HANYA atas IKU yang punya nilai — IKU strip "-" (belum dinilai, lihat
        // Capaian::hitungPersentase()) dikecualikan supaya tidak menurunkan rata-rata,
        // sama seperti pola CapaianCalculatorService::rataRataCapaianTriwulanan().
        $rataCapaianTw = $this->rataRataCapaian($rekapPerIku, 'capaian_tw');
        $rataCapaianPk = $this->rataRataCapaian($rekapPerIku, 'capaian_pk');

        return [
            'notula' => $notula,
            'periode' => $periode,
            'labelTriwulan' => ['I', 'II', 'III', 'IV'][$tw - 1] ?? $tw,
            'sasaranPerIku' => $sasaranPerIku,
            'rekapPerIku' => $rekapPerIku,
            'capaianPerIku' => $capaianPerIku,
            'kegiatanPerIku' => $kegiatanPerIku,
            'roPerIku' => $roPerIku,
            'kendalaSolusiPerIku' => $kendalaSolusiPerIku,
            'rtlPerIku' => $rtlPerIku,
            'rtlSebelumnyaPerIku' => $rtlSebelumnyaPerIku,
            'bagianKustomPerBagian' => $bagianKustomPerBagian,
            'linkFolderPerIku' => $linkFolderPerIku,
            'linkFolderTwSebelumnyaPerIku' => $linkFolderTwSebelumnyaPerIku,
            'rataCapaianTw' => $rataCapaianTw,
            'rataCapaianPk' => $rataCapaianPk,
        ];
    }

    /**
     * Render Bagian I + II + III (+ blok TTD bila $sertakanTtd) sebagai SATU HTML
     * mengalir dari `bagian*_html` TERSIMPAN — bukan tiga PDF terpisah yang digabung
     * halaman-demi-halaman, supaya tiap bagian bisa menyambung di sisa ruang halaman
     * sebelumnya. Dipakai baik untuk menghasilkan PDF (dompdf, lihat
     * renderNotulaUtuhPdf()) maupun untuk pratinjau HTML mentah.
     */
    public function renderNotulaUtuhHtml(Notula $notula, bool $sertakanTtd): string
    {
        return view('pdf.notula-utuh', $this->dataNotulaUtuh($notula, $sertakanTtd))->render();
    }

    /**
     * @return array<string, mixed>
     */
    private function dataNotulaUtuh(Notula $notula, bool $sertakanTtd): array
    {
        $periode = $notula->periode;

        return [
            'bagian1Html' => $notula->bagian1_html ?? '',
            'bagian2Html' => $notula->bagian2_html ?? '',
            'bagian3Html' => $notula->bagian3_html ?? '',
            'labelTriwulan' => ['I', 'II', 'III', 'IV'][$periode->triwulan - 1] ?? $periode->triwulan,
            'tahun' => $periode->tahun,
            'sertakanTtd' => $sertakanTtd,
            'kotaTtd' => $notula->kota_ttd,
            // disetujui_pada tersimpan UTC (config('app.timezone')) -- ->wita() dulu
            // supaya tanggal TTD tidak meleset sehari untuk persetujuan dekat tengah
            // malam WITA (mis. disetujui 23:30 WITA = 15:30 UTC hari yang sama, tapi
            // 00:30 WITA keesokan harinya = 16:30 UTC hari SEBELUMNYA).
            'tanggal' => $sertakanTtd ? $notula->disetujui_pada?->wita()->translatedFormat('d F Y') : null,
            'namaKepala' => $sertakanTtd ? $notula->disetujuiOleh?->nama : null,
            'namaNotulis' => $notula->notulis,
        ];
    }

    private function renderNotulaUtuhPdf(Notula $notula, bool $sertakanTtd, string $outputPath): string
    {
        $pdf = PdfFacade::loadView('pdf.notula-utuh', $this->dataNotulaUtuh($notula, $sertakanTtd));

        $this->pastikanFolder(dirname($outputPath));
        $pdf->save($outputPath);

        return $outputPath;
    }

    /**
     * RF-42a/42b: terima unggahan Bagian II atau III (.docx), lalu simpan DUA bentuk:
     * - bagian{2,3}_pdf: versi PDF (LibreOffice headless), dipakai HANYA untuk
     *   pratinjau iframe di layar Kompilasi Notula.
     * - bagian{2,3}_html: konten HTML inline (reflow), dipakai jalur render notula
     *   menyatu (lihat gabungkan()/setujui()) supaya bisa menyambung antar bagian.
     *
     * Mengganti berkas yang sudah ada sebelumnya (RF-42e) otomatis membatalkan
     * hasil gabungan lama.
     */
    public function terimaUploadBagian(Notula $notula, int $bagianKe, UploadedFile $file): void
    {
        if (! in_array($bagianKe, [2, 3], true)) {
            throw new RuntimeException('Bagian harus 2 atau 3.');
        }

        $pathAsli = $file->store('notula-sementara', 'local');
        $fullPathAsli = Storage::disk('local')->path($pathAsli);
        $dirKerja = dirname($fullPathAsli);

        $fullPathPdf = $this->konversi->convertToPdf($fullPathAsli, $dirKerja);

        $relatifTujuanPdf = "notula/{$notula->id}/bagian{$bagianKe}.pdf";
        $fullPathTujuanPdf = Storage::disk('local')->path($relatifTujuanPdf);
        $this->pastikanFolder(dirname($fullPathTujuanPdf));
        copy($fullPathPdf, $fullPathTujuanPdf);

        // Konten inline dibaca SEBELUM berkas sementara dihapus di bawah.
        $kontenInline = $this->konversi->convertToHtml($fullPathAsli, $dirKerja);

        @unlink($fullPathPdf);
        @unlink($fullPathAsli);

        $kolomPdf = $bagianKe === 2 ? 'bagian2_pdf' : 'bagian3_pdf';
        $kolomHtml = $bagianKe === 2 ? 'bagian2_html' : 'bagian3_html';
        $notula->update([$kolomPdf => $relatifTujuanPdf, $kolomHtml => $kontenInline]);

        $notula->tandaiPerluDigabungUlang();
    }

    /**
     * RF-43 (syarat "Unduh draf"): seluruh kegiatan pada triwulan tsb sudah
     * terverifikasi (tidak ada lagi yang masih draft/diajukan/dikembalikan).
     */
    public function semuaBuktiTerverifikasi(Periode $periode): bool
    {
        $adaYangBelum = Kegiatan::whereHas('periode', fn ($q) => $q->where('tahun', $periode->tahun)->where('triwulan', $periode->triwulan)
        )
            ->whereNotIn('status_dokumen', [Kegiatan::STATUS_DIVERIFIKASI, Kegiatan::STATUS_DISETUJUI])
            ->exists();

        return ! $adaYangBelum;
    }

    /**
     * Render pratinjau CEPAT dari Bagian I/II/III yang TERSIMPAN saat ini (boleh belum
     * lengkap) sebagai PDF sekali-pakai — TANPA menyentuh status notula atau kolom
     * pdf_gabungan (beda dari gabungkan() di bawah, yang punya efek samping mengirim
     * notula ke Kepala). Dipakai tombol "Unduh Pratinjau" di Kompilasi Notula supaya
     * Tim SAKIP bisa memeriksa hasil sewaktu-waktu tanpa terikat alur resmi kirim.
     */
    public function renderPratinjauPdf(Notula $notula, string $outputPath): string
    {
        return $this->renderNotulaUtuhPdf($notula, sertakanTtd: false, outputPath: $outputPath);
    }

    /**
     * RF-42d: render Bagian I + II + III sebagai SATU dokumen mengalir (TANPA TTD)
     * jadi PDF draf — lihat renderNotulaUtuhPdf(), menggantikan penggabungan PDF
     * halaman-demi-halaman yang dipakai sebelumnya. Notula otomatis dianggap
     * terkirim ke Kepala begitu berhasil digabungkan — mockup tidak menyediakan
     * tombol "kirim" terpisah, jadi "sudah digabung" DAN "menunggu persetujuan"
     * adalah hal yang sama.
     */
    public function gabungkan(Notula $notula): void
    {
        if (! $notula->bagianLengkap()) {
            throw new RuntimeException('Bagian I, II, dan III harus lengkap terlebih dahulu sebelum digabungkan.');
        }

        $dir = storage_path("app/private/notula/{$notula->id}");
        $gabunganPath = $dir.'/gabungan-draf.pdf';
        $this->renderNotulaUtuhPdf($notula, sertakanTtd: false, outputPath: $gabunganPath);

        $notula->update(['pdf_gabungan' => "notula/{$notula->id}/gabungan-draf.pdf"]);

        if (in_array($notula->status, [Notula::STATUS_DRAFT, Notula::STATUS_DIKEMBALIKAN], true)) {
            $notula->kirimKePersetujuan();
        }
    }

    /**
     * RF-44 & RF-44a: Kepala menyetujui — render ulang dokumen menyatu DENGAN blok
     * TTD (Kepala & Notulis, di paling akhir setelah Bagian III — lihat
     * pdf.notula-utuh) jadi pdf_final, lalu arsipkan ke Drive institusi. Kegagalan
     * Drive tidak membatalkan persetujuan (sama seperti pola di fitur unggah
     * lain); hanya dicatat sebagai peringatan, PDF final tetap tersimpan lokal.
     */
    public function setujui(Notula $notula, User $kepala): void
    {
        DB::transaction(function () use ($notula, $kepala) {
            $notula->setujui($kepala);
            $this->setujuiKegiatanTriwulan($notula->periode, $kepala);
        });

        $dir = storage_path("app/private/notula/{$notula->id}");
        $gabunganFinalPath = $dir.'/gabungan-final.pdf';
        $this->renderNotulaUtuhPdf($notula, sertakanTtd: true, outputPath: $gabunganFinalPath);

        $relatifFinal = "notula/{$notula->id}/gabungan-final.pdf";
        $notula->update(['pdf_final' => $relatifFinal]);

        try {
            $namaBerkas = "notula-tw{$notula->periode->triwulan}-{$notula->periode->tahun}-final.pdf";
            $hasil = $this->folder->unggahArsipNotula($notula->periode, $gabunganFinalPath, $namaBerkas);

            Berkas::create([
                'ref_id' => $notula->id,
                'ref_type' => Notula::class,
                'kategori' => 'notula',
                'nama_file' => $hasil['nama_file'],
                'path' => $relatifFinal,
                'drive_file_id' => $hasil['drive_file_id'],
                'storage_account_id' => $hasil['storage_account_id'],
                'status_verifikasi' => 'terverifikasi',
            ]);
        } catch (RuntimeException $e) {
            Log::warning('Gagal mengarsipkan notula final ke Google Drive, tersimpan lokal saja: '.$e->getMessage());
        }
    }

    /**
     * Kegiatan yang sudah "diverifikasi" pada triwulan notula ini ikut disetujui
     * begitu Kepala menyetujui notula-nya (RF-44) — sebelumnya Kegiatan::setujui()
     * tidak pernah dipicu di mana pun (lihat catatan lama pada method itu), sehingga
     * status kegiatan diam di "diverifikasi" selamanya walau notulanya sudah final.
     *
     * Riwayat status dicatat SEKALI per Capaian (satu per IKU+bulan dalam triwulan
     * ini), bukan per kegiatan — supaya kegiatan tambahan yang diajukan belakangan
     * pada IKU+bulan yang sama tetap tergabung sebagai satu poin riwayat.
     */
    private function setujuiKegiatanTriwulan(Periode $periode, User $kepala): void
    {
        $kegiatanPerCapaian = Kegiatan::where('status_dokumen', Kegiatan::STATUS_DIVERIFIKASI)
            ->whereHas('periode', fn ($q) => $q->where('tahun', $periode->tahun)->where('triwulan', $periode->triwulan))
            ->get()
            ->groupBy(fn ($k) => $k->iku_id.'-'.$k->periode_id);

        foreach ($kegiatanPerCapaian as $grup) {
            foreach ($grup as $kegiatan) {
                $kegiatan->setujui();
            }

            $acuan = $grup->first();

            Capaian::firstOrCreate(['iku_id' => $acuan->iku_id, 'periode_id' => $acuan->periode_id])
                ->catatStatus(Kegiatan::STATUS_DISETUJUI, $kepala);
        }
    }

    /**
     * RF-44: Kepala mengembalikan notula ke Tim SAKIP beserta catatan alasannya.
     */
    public function kembalikan(Notula $notula, string $catatan): void
    {
        $notula->kembalikan($catatan);
    }

    /**
     * Rata-rata satu kolom rekapPerIku (capaian_tw/capaian_pk), mengecualikan IKU yang
     * belum dinilai (null, lihat Capaian::hitungPersentase()) — null berarti strip "-",
     * bukan 0, jadi tidak boleh ikut menurunkan rata-rata.
     */
    private function rataRataCapaian(Collection $rekapPerIku, string $kolom): ?float
    {
        $nilai = $rekapPerIku->pluck($kolom)->filter(fn ($v) => $v !== null);

        return $nilai->isEmpty() ? null : round($nilai->avg(), 2);
    }

    protected function pastikanFolder(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
