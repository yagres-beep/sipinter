<?php

namespace App\Services;

use App\Models\BagianKustom;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi;
use App\Models\Notula;
use App\Models\Periode;
use App\Models\RtlEvaluasi;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf as PdfFacade;
use Illuminate\Http\UploadedFile;
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
        protected PdfMergeService $penggabung,
        protected FolderStructureService $folder,
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
     */
    public function susunBagianSatu(Notula $notula): string
    {
        $periode = $notula->periode;

        $kegiatanPerIku = Kegiatan::with('masterIku')
            ->whereHas('periode', fn ($q) => $q->where('tahun', $periode->tahun)->where('triwulan', $periode->triwulan))
            ->whereIn('status_dokumen', [Kegiatan::STATUS_DIVERIFIKASI, Kegiatan::STATUS_DISETUJUI])
            ->get()
            ->groupBy('iku_id');

        // RF-28: kumulatif dari triwulan 1 s.d. triwulan berjalan, tahun yang sama.
        $kendalaSolusiPerTriwulan = KendalaSolusi::with(['masterIku', 'periode'])
            ->whereHas('periode', fn ($q) => $q->where('tahun', $periode->tahun)->where('triwulan', '<=', $periode->triwulan))
            ->get()
            ->sortBy(fn ($k) => $k->periode->triwulan)
            ->groupBy(fn ($k) => $k->periode->triwulan);

        $rtlBerjalan = RtlEvaluasi::with('masterIku')
            ->whereHas('periode', fn ($q) => $q->where('tahun', $periode->tahun)->where('triwulan', $periode->triwulan))
            ->get();

        // Bagian kustom (mis. Manajemen Risiko) — SEMUA bagian yang punya poin di
        // triwulan ini ikut ditampilkan, termasuk yang sudah dinonaktifkan Tim SAKIP
        // setelahnya, supaya data historis notula tidak pernah hilang dari catatan.
        $bagianKustomPerBagian = BagianKustom::query()
            ->whereHas('poin', fn ($q) => $q->whereHas(
                'periode',
                fn ($q2) => $q2->where('tahun', $periode->tahun)->where('triwulan', $periode->triwulan)
            ))
            ->with(['poin' => function ($q) use ($periode) {
                $q->with('masterIku')->whereHas(
                    'periode',
                    fn ($q2) => $q2->where('tahun', $periode->tahun)->where('triwulan', $periode->triwulan)
                );
            }])
            ->get();

        // RF baru: link bukti dukung per IKU (folder Drive IKU tsb), supaya Tim SAKIP/
        // Kepala bisa langsung buka bukti dari dalam dokumen notula. Dihitung sekali per
        // IKU yang benar-benar tampil di sini (bukan seluruh Master IKU) — gagal Drive
        // pada satu IKU (mis. storage belum aktif) tidak menggagalkan penyusunan notula.
        $linkFolderPerIku = $kegiatanPerIku->mapWithKeys(function ($daftarKegiatan, $ikuId) use ($periode) {
            return [$ikuId => $this->folder->linkBuktiDukungIku($periode, $daftarKegiatan->first()->masterIku)];
        });

        $html = view('pdf.notula-bagian1-konten', [
            'kegiatanPerIku' => $kegiatanPerIku,
            'kendalaSolusiPerTriwulan' => $kendalaSolusiPerTriwulan,
            'rtlBerjalan' => $rtlBerjalan,
            'bagianKustomPerBagian' => $bagianKustomPerBagian,
            'linkFolderPerIku' => $linkFolderPerIku,
        ])->render();

        $notula->update(['bagian1_html' => $html]);

        return $html;
    }

    /**
     * Render Bagian I (dari bagian1_html TERSIMPAN — sudah termasuk suntingan manual
     * Tim SAKIP bila ada) menjadi PDF lewat dompdf.
     */
    public function renderBagianSatuPdf(Notula $notula, bool $sertakanTtd, string $outputPath): string
    {
        $periode = $notula->periode;

        $pdf = PdfFacade::loadView('pdf.notula-bagian1', [
            'kontenHtml' => $notula->bagian1_html ?? '',
            'labelTriwulan' => ['I', 'II', 'III', 'IV'][$periode->triwulan - 1] ?? $periode->triwulan,
            'tahun' => $periode->tahun,
            'sertakanTtd' => $sertakanTtd,
            'namaKepala' => $sertakanTtd ? $notula->disetujuiOleh?->nama : null,
            'tanggal' => $sertakanTtd ? $notula->disetujui_pada?->translatedFormat('d F Y') : null,
        ]);

        $this->pastikanFolder(dirname($outputPath));
        $pdf->save($outputPath);

        return $outputPath;
    }

    /**
     * RF-42a/42b: terima unggahan Bagian II atau III, konversi ke PDF bila perlu
     * (LibreOffice headless), simpan hasilnya di disk lokal. Mengganti berkas yang
     * sudah ada sebelumnya (RF-42e) otomatis membatalkan hasil gabungan lama.
     */
    public function terimaUploadBagian(Notula $notula, int $bagianKe, UploadedFile $file): void
    {
        if (! in_array($bagianKe, [2, 3], true)) {
            throw new RuntimeException('Bagian harus 2 atau 3.');
        }

        $pathAsli = $file->store('notula-sementara', 'local');
        $fullPathAsli = Storage::disk('local')->path($pathAsli);

        $fullPathPdf = $this->konversi->sudahPdf($fullPathAsli)
            ? $fullPathAsli
            : $this->konversi->convertToPdf($fullPathAsli, dirname($fullPathAsli));

        $relatifTujuan = "notula/{$notula->id}/bagian{$bagianKe}.pdf";
        $fullPathTujuan = Storage::disk('local')->path($relatifTujuan);

        $this->pastikanFolder(dirname($fullPathTujuan));
        copy($fullPathPdf, $fullPathTujuan);

        @unlink($fullPathAsli);
        if ($fullPathPdf !== $fullPathAsli) {
            @unlink($fullPathPdf);
        }

        $kolom = $bagianKe === 2 ? 'bagian2_pdf' : 'bagian3_pdf';
        $notula->update([$kolom => $relatifTujuan]);

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
     * RF-42d: gabungkan Bagian I (dirender ulang dari bagian1_html tersimpan, TANPA
     * TTD) + II + III menjadi satu PDF draf. Notula otomatis dianggap terkirim ke
     * Kepala begitu berhasil digabungkan — mockup tidak menyediakan tombol "kirim"
     * terpisah, jadi "sudah digabung" DAN "menunggu persetujuan" adalah hal yang sama.
     */
    public function gabungkan(Notula $notula): void
    {
        if (! $notula->bagianLengkap()) {
            throw new RuntimeException('Bagian I, II, dan III harus lengkap terlebih dahulu sebelum digabungkan.');
        }

        $dir = storage_path("app/private/notula/{$notula->id}");
        $this->pastikanFolder($dir);

        $bagian1Path = $dir.'/bagian1-draf.pdf';
        $this->renderBagianSatuPdf($notula, sertakanTtd: false, outputPath: $bagian1Path);

        $bagian2Path = Storage::disk('local')->path($notula->bagian2_pdf);
        $bagian3Path = Storage::disk('local')->path($notula->bagian3_pdf);

        $gabunganPath = $dir.'/gabungan-draf.pdf';
        $this->penggabung->merge([$bagian1Path, $bagian2Path, $bagian3Path], $gabunganPath);

        $notula->update(['pdf_gabungan' => "notula/{$notula->id}/gabungan-draf.pdf"]);

        if (in_array($notula->status, [Notula::STATUS_DRAFT, Notula::STATUS_DIKEMBALIKAN], true)) {
            $notula->kirimKePersetujuan();
        }
    }

    /**
     * RF-44 & RF-44a: Kepala menyetujui — buat ulang Bagian I DENGAN blok TTD,
     * gabungkan jadi pdf_final, lalu arsipkan ke Drive institusi. Kegagalan Drive
     * tidak membatalkan persetujuan (sama seperti pola di fitur unggah lain);
     * hanya dicatat sebagai peringatan, PDF final tetap tersimpan lokal.
     */
    public function setujui(Notula $notula, User $kepala): void
    {
        DB::transaction(function () use ($notula, $kepala) {
            $notula->setujui($kepala);
            $this->setujuiKegiatanTriwulan($notula->periode, $kepala);
        });

        $dir = storage_path("app/private/notula/{$notula->id}");
        $this->pastikanFolder($dir);

        $bagian1FinalPath = $dir.'/bagian1-final.pdf';
        $this->renderBagianSatuPdf($notula, sertakanTtd: true, outputPath: $bagian1FinalPath);

        $bagian2Path = Storage::disk('local')->path($notula->bagian2_pdf);
        $bagian3Path = Storage::disk('local')->path($notula->bagian3_pdf);

        $gabunganFinalPath = $dir.'/gabungan-final.pdf';
        $this->penggabung->merge([$bagian1FinalPath, $bagian2Path, $bagian3Path], $gabunganFinalPath);

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

    protected function pastikanFolder(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
