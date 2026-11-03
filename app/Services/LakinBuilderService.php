<?php

namespace App\Services;

use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\CapaianTahunan;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi;
use App\Models\Lakin;
use App\Models\LakinBaris;
use App\Models\MasterIku;
use App\Models\Periode;
use App\Models\RtlEvaluasi;
use App\Models\SalinanBuktiRtl;
use Illuminate\Support\Collection;

/**
 * Menyiapkan pilihan IKU yang bisa dimasukkan ke LAKIN dari Target Tahunan +
 * Realisasi Triwulanan yang sudah diisi Tim SAKIP (App\Models\CapaianTahunan, satu
 * baris per iku_id+tahun), lalu menambahkan HANYA IKU yang dipilih Tim SAKIP secara
 * eksplisit — LAKIN tidak pernah terisi otomatis-semua, karena tiap instansi bisa
 * punya alasan berbeda memasukkan/tidak memasukkan suatu indikator.
 *
 * Target = Target Tahunan. Realisasi = Realisasi Kumulatif TW I s.d. TW IV (setahun
 * penuh). Kedua asumsi ini adalah TITIK AWAL yang masuk akal, bukan aturan baku —
 * Tim SAKIP bisa menimpa angka apa pun secara manual di LakinDetail sesudahnya.
 */
class LakinBuilderService
{
    /**
     * IKU yang punya data CapaianTahunan pada tahun ini, lengkap dengan angka
     * target/realisasi/capaian% hasil hitung — dipakai untuk checklist pemilihan di
     * LakinDetail. TIDAK menyimpan apa pun; hanya pratinjau sebelum Tim SAKIP memilih.
     *
     * @return Collection<int, object{iku: \App\Models\MasterIku, target: ?string, realisasi: ?float, capaian_persen: ?float, terverifikasi: bool}> dikunci pada iku_id.
     */
    public function ikuTersediaUntukTahun(int $tahun): Collection
    {
        return CapaianTahunan::with('masterIku')
            ->where('tahun', $tahun)
            ->get()
            ->map(function (CapaianTahunan $capaianTahunan) use ($tahun) {
                $target = $capaianTahunan->targetTahunan();
                $totalRealisasi = $capaianTahunan->realisasiKumulatif(4);
                $capaianPersen = $capaianTahunan->capaianSetahun(4);

                return (object) [
                    'iku' => $capaianTahunan->masterIku,
                    'target' => $target,
                    'realisasi' => $totalRealisasi,
                    'capaian_persen' => $capaianPersen,
                    'terverifikasi' => $this->sudahTerverifikasi($capaianTahunan->iku_id, $tahun),
                ];
            })
            ->sortBy(fn ($data) => $data->iku->kode)
            ->values()
            ->keyBy(fn ($data) => $data->iku->id);
    }

    /**
     * IKU dianggap "sudah diverifikasi Tim SAKIP" untuk tahun ini bila SELURUH baris
     * Capaian (per bulan) miliknya pada tahun tsb berstatus diverifikasi/disetujui,
     * dan minimal ada satu baris — dipakai LakinDetail supaya checklist "Tambah dari
     * Data Capaian" otomatis mencentang IKU yang sudah beres diverifikasi, sisanya
     * (masih diajukan/sedang ditangani/dikembalikan/atau belum ada isian sama sekali)
     * tetap harus dipilih manual.
     */
    private function sudahTerverifikasi(int $ikuId, int $tahun): bool
    {
        $statusList = Capaian::where('iku_id', $ikuId)
            ->whereHas('periode', fn ($q) => $q->where('tahun', $tahun))
            ->pluck('status');

        if ($statusList->isEmpty()) {
            return false;
        }

        return $statusList->every(fn ($status) => in_array($status, [
            Capaian::STATUS_DIVERIFIKASI,
            Capaian::STATUS_DISETUJUI,
        ], true));
    }

    /**
     * Tambah/perbarui baris LAKIN HANYA untuk IKU yang eksplisit dipilih Tim SAKIP
     * (lewat checklist) — IKU yang tidak dipilih tidak pernah ikut masuk.
     * Ikut menyalin detail tindak lanjut (kegiatan, kendala & solusi, RTL, PIC,
     * batas waktu, tautan bukti) dari data capaian & verifikasi tahun tsb.
     *
     * @param  list<int>  $ikuIds
     */
    public function tambahDariIku(Lakin $lakin, array $ikuIds): void
    {
        $tersedia = $this->ikuTersediaUntukTahun($lakin->tahun);
        $urutan = (int) $lakin->baris()->max('urutan');

        foreach ($ikuIds as $ikuId) {
            $data = $tersedia->get($ikuId);

            if (! $data) {
                continue;
            }

            $urutan++;

            $tindakLanjut = $this->ekstrakDataTindakLanjut($data->iku, $lakin->tahun);

            LakinBaris::updateOrCreate(
                ['lakin_id' => $lakin->id, 'iku_id' => $ikuId],
                array_merge([
                    'sasaran' => $data->iku->sasaran,
                    'indikator' => $data->iku->indikator,
                    'target' => $data->target,
                    'realisasi' => $data->realisasi,
                    'capaian_persen' => $data->capaian_persen,
                    'urutan' => $urutan,
                ], $tindakLanjut)
            );
        }
    }

    /**
     * Segarkan ANGKA dan DATA baris yang SUDAH ADA di LAKIN ini (dicocokkan lewat iku_id) dari
     * data capaian & verifikasi terkini — TIDAK menambah baris baru (Tim SAKIP tetap yang memilih
     * lewat tambahDariIku()) dan TIDAK menyentuh baris custom (iku_id kosong). Kolom tindak lanjut
     * yang masih kosong akan diisi dengan data terverifikasi terkini.
     */
    public function segarkanBaris(Lakin $lakin): void
    {
        $tersedia = $this->ikuTersediaUntukTahun($lakin->tahun);

        foreach ($lakin->baris()->get() as $baris) {
            if (! $baris->iku_id) {
                continue;
            }

            $data = $tersedia->get($baris->iku_id);

            if (! $data) {
                continue;
            }

            $tindakLanjut = $this->ekstrakDataTindakLanjut($data->iku, $lakin->tahun);

            $updateData = [
                'sasaran' => $data->iku->sasaran,
                'indikator' => $data->iku->indikator,
                'target' => $data->target,
                'realisasi' => $data->realisasi,
                'capaian_persen' => $data->capaian_persen,
            ];

            foreach (['kegiatan', 'solusi_kendala', 'rtl', 'pic', 'batas_waktu_tindak_lanjut', 'link_bukti_dukung_kinerja', 'link_rtl_sebelumnya'] as $kolom) {
                if (blank($baris->{$kolom}) && ! blank($tindakLanjut[$kolom] ?? null)) {
                    $updateData[$kolom] = $tindakLanjut[$kolom];
                }
            }

            $baris->update($updateData);
        }
    }

    /**
     * Kumpulkan data tindak lanjut (kegiatan, kendala & solusi, RTL, PIC, batas waktu,
     * dan tautan bukti dukung) dari data terverifikasi IKU ini pada tahun bersangkutan.
     *
     * @return array{
     *     kegiatan: ?string,
     *     solusi_kendala: ?string,
     *     rtl: ?string,
     *     pic: ?string,
     *     batas_waktu_tindak_lanjut: ?string,
     *     link_bukti_dukung_kinerja: ?string,
     *     link_rtl_sebelumnya: ?string
     * }
     */
    public function ekstrakDataTindakLanjut(MasterIku $iku, int $tahun): array
    {
        // 1. Kegiatan: ambil dari data kegiatan terverifikasi/disetujui (atau diajukan bila belum disetujui)
        $kegiatanQuery = Kegiatan::query()
            ->where('iku_id', $iku->id)
            ->whereHas('periode', fn ($q) => $q->where('tahun', $tahun));

        $kegiatanList = (clone $kegiatanQuery)
            ->whereIn('status_dokumen', [Kegiatan::STATUS_DIVERIFIKASI, Kegiatan::STATUS_DISETUJUI])
            ->get();

        if ($kegiatanList->isEmpty()) {
            $kegiatanList = (clone $kegiatanQuery)
                ->whereNotIn('status_dokumen', [Kegiatan::STATUS_DRAFT, Kegiatan::STATUS_DIKEMBALIKAN])
                ->get();
        }
        if ($kegiatanList->isEmpty()) {
            $kegiatanList = $kegiatanQuery->get();
        }

        Kegiatan::terapkanNamaInduk($kegiatanList);
        $uraianList = $kegiatanList
            ->map(fn ($k) => trim((string) ($k->uraian_kegiatan ?: $k->nama_folder_kustom ?: '')))
            ->filter()
            ->unique()
            ->values();

        $kegiatanTeks = $uraianList->count() > 1
            ? $uraianList->map(fn ($u, $i) => ($i + 1).'. '.$u)->implode("\n")
            : ($uraianList->first() ?: null);

        // 2. Kendala & Solusi: utamakan yang terverifikasi (atau tidak ditolak)
        $ksQuery = KendalaSolusi::query()
            ->where('iku_id', $iku->id)
            ->whereHas('periode', fn ($q) => $q->where('tahun', $tahun));

        $ksList = (clone $ksQuery)
            ->where('status_verifikasi', 'terverifikasi')
            ->get();

        if ($ksList->isEmpty()) {
            $ksList = (clone $ksQuery)
                ->where('status_verifikasi', '!=', 'ditolak')
                ->get();
        }
        if ($ksList->isEmpty()) {
            $ksList = $ksQuery->get();
        }

        $poinKsList = $ksList->map(function ($ks) {
            $kendala = trim((string) ($ks->kendala ?? ''));
            $solusi = trim((string) ($ks->solusi ?? ''));

            if ($kendala !== '' && $solusi !== '') {
                return "Kendala: {$kendala}\nSolusi: {$solusi}";
            } elseif ($kendala !== '') {
                return "Kendala: {$kendala}";
            } elseif ($solusi !== '') {
                return "Solusi: {$solusi}";
            }

            return null;
        })->filter()->unique()->values();

        if ($poinKsList->count() > 1) {
            $solusiKendalaTeks = $poinKsList->map(function ($item, $i) {
                $nomor = ($i + 1).'. ';
                $indent = str_repeat(' ', strlen($nomor));
                $baris = explode("\n", $item);
                $baris[0] = $nomor.$baris[0];
                for ($b = 1; $b < count($baris); $b++) {
                    $baris[$b] = $indent.$baris[$b];
                }

                return implode("\n", $baris);
            })->implode("\n");
        } elseif ($poinKsList->count() === 1) {
            $solusiKendalaTeks = $poinKsList->first();
        } else {
            $solusiKendalaTeks = null;
        }

        // 3. RTL: utamakan yang terverifikasi (atau tidak ditolak)
        $rtlQuery = RtlEvaluasi::query()
            ->where('iku_id', $iku->id)
            ->whereHas('periode', fn ($q) => $q->where('tahun', $tahun));

        $rtlList = (clone $rtlQuery)
            ->where('status_verifikasi', 'terverifikasi')
            ->get();

        if ($rtlList->isEmpty()) {
            $rtlList = (clone $rtlQuery)
                ->where('status_verifikasi', '!=', 'ditolak')
                ->get();
        }
        if ($rtlList->isEmpty()) {
            $rtlList = $rtlQuery->get();
        }

        $rtlTeksList = $rtlList
            ->map(fn ($r) => trim((string) ($r->rtl_teks ?? '')))
            ->filter()
            ->unique()
            ->values();

        $rtlTeks = $rtlTeksList->count() > 1
            ? $rtlTeksList->map(fn ($item, $i) => ($i + 1).'. '.$item)->implode("\n")
            : ($rtlTeksList->first() ?: null);

        // 4. PIC: dari RTL atau fallback ke Tim IKU
        $picList = $rtlList->pluck('pic')->map(fn ($p) => trim((string) ($p ?? '')))->filter()->unique()->values();
        if ($picList->isNotEmpty()) {
            $pic = $picList->join(', ');
        } else {
            $timList = $iku->namaTimList();
            $pic = ! empty($timList) ? implode(', ', $timList) : ($iku->tim ?: null);
        }

        // 5. Batas Waktu Tindak Lanjut: tanggal RTL terjauh atau akhir tahun jika ada RTL
        $batasWaktu = $rtlList
            ->pluck('batas_waktu')
            ->filter()
            ->sort()
            ->last()?->format('Y-m-d');

        if (! $batasWaktu && $rtlTeks !== null) {
            $batasWaktu = "{$tahun}-12-31";
        }

        // 6. Link Bukti Dukung Kinerja: folder Drive kegiatan, berkas Drive capaian, atau tautan folder IKU
        $linkBukti = null;
        $folderId = $kegiatanList->whereNotNull('drive_folder_id')->pluck('drive_folder_id')->first();
        if ($folderId) {
            $linkBukti = "https://drive.google.com/drive/folders/{$folderId}";
        } else {
            $berkas = Berkas::where('kategori', 'capaian')
                ->whereNotNull('drive_file_id')
                ->whereHasMorph('ref', [Kegiatan::class], fn ($q) => $q->where('iku_id', $iku->id)->whereHas('periode', fn ($p) => $p->where('tahun', $tahun)))
                ->first();

            if ($berkas) {
                $linkBukti = "https://drive.google.com/file/d/{$berkas->drive_file_id}/view";
            }
        }

        if (! $linkBukti) {
            try {
                $periode = Periode::where('tahun', $tahun)->orderByDesc('triwulan')->orderByDesc('bulan')->first()
                    ?? new Periode(['tahun' => $tahun, 'triwulan' => 4]);
                $linkBukti = app(FolderStructureService::class)->linkKategoriIku($periode, $iku, 'capaian')
                    ?? app(FolderStructureService::class)->linkBuktiDukungIku($periode, $iku);
            } catch (\Throwable) {
            }
        }

        // 7. Link RTL Sebelumnya: salinan bukti RTL di Drive, berkas evaluasi RTL, atau folder evaluasi RTL
        $linkRtl = null;
        $salinan = SalinanBuktiRtl::whereNotNull('drive_file_id')
            ->whereHas('rtlEvaluasi', fn ($q) => $q->where('iku_id', $iku->id)->whereHas('periode', fn ($p) => $p->where('tahun', $tahun)))
            ->first();

        if ($salinan) {
            $linkRtl = "https://drive.google.com/file/d/{$salinan->drive_file_id}/view";
        } else {
            $berkasRtl = Berkas::where('kategori', 'evaluasi_rtl')
                ->whereNotNull('drive_file_id')
                ->whereHasMorph('ref', [RtlEvaluasi::class], fn ($q) => $q->where('iku_id', $iku->id)->whereHas('periode', fn ($p) => $p->where('tahun', $tahun)))
                ->first();

            if ($berkasRtl) {
                $linkRtl = "https://drive.google.com/file/d/{$berkasRtl->drive_file_id}/view";
            }
        }

        if (! $linkRtl) {
            try {
                $periode = Periode::where('tahun', $tahun)->orderByDesc('triwulan')->orderByDesc('bulan')->first()
                    ?? new Periode(['tahun' => $tahun, 'triwulan' => 4]);
                $linkRtl = app(FolderStructureService::class)->linkKategoriIku($periode, $iku, 'evaluasi_rtl');
            } catch (\Throwable) {
            }
        }

        return [
            'kegiatan' => $kegiatanTeks,
            'solusi_kendala' => $solusiKendalaTeks,
            'rtl' => $rtlTeks,
            'pic' => $pic,
            'batas_waktu_tindak_lanjut' => $batasWaktu,
            'link_bukti_dukung_kinerja' => $linkBukti,
            'link_rtl_sebelumnya' => $linkRtl,
        ];
    }
}
