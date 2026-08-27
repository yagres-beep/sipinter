<?php

namespace App\Services;

use App\Models\FolderConfig;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\PengaturanCapaian;
use App\Support\RumusMarkup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\TemplateProcessor;
use ReflectionProperty;
use RuntimeException;

/**
 * Isi template Bagian I bervariabel {{...}} (template_notula/SIPINTER_Template_Bagian_I_Mesin.docx)
 * dari data yang sama dipakai NotulaService::kumpulkanDataBagianSatu() -- dipakai LANGSUNG oleh
 * DUA jalur: (1) unduhan Bagian I sebagai .docx asli (NotulaDownloadController::unduhBagian1Docx()),
 * dan (2) NotulaService::susunBagianSatu(), yang mengonversi hasilnya ke HTML lewat LibreOffice
 * untuk pratinjau/notula gabungan -- supaya format Bagian I di web SELALU persis mengikuti template
 * resmi yang diunggah Tim SAKIP, bukan tata letak HTML terpisah yang harus disunting manual tiap
 * kali format resmi berubah (lihat NotulaService::susunBagianSatu()).
 *
 * Template-nya berisi SATU blok {{iku_blok}}...{{/iku_blok}} yang DIGANDAKAN di sini sekali per
 * Master IKU (RF baru) -- BUKAN satu macro bernama per kode IKU seperti versi lama. Ini supaya
 * penomoran/kode IKU boleh berubah kapan pun (mis. lewat impor Master IKU dari Excel) tanpa harus
 * menulis ulang template-nya; satu-satunya alasan template perlu disunting lagi adalah bila
 * STRUKTUR dokumen resmi sendiri berubah dari pusat. Blok RO ({{ro_row}}...{{/ro_row}}) di dalamnya
 * ikut digandakan per Kegiatan pada IKU tsb (Kegiatan::rincian_output/volume_ro/progres_persen),
 * dan salah satu dari tiga varian {{blok_sakip}}/{{blok_berakhlak}}/{{blok_ro}} dipilih sesuai teks
 * indikator (lihat isiSatuIku() di bawah untuk deteksinya).
 *
 * Template SUMBER diutamakan dari berkas yang diunggah Tim SAKIP lewat menu
 * Pengaturan > Template Notula (App\Livewire\TemplateNotula, disimpan di
 * FolderConfig::template_notula_path) -- supaya begitu format resmi berubah dari
 * pusat, Tim SAKIP tinggal mengunggah template baru ke situ TANPA perlu developer
 * mengganti berkas bawaan di kode (lihat DEFAULT_TEMPLATE_PATH di bawah, dipakai
 * hanya sebagai cadangan bila belum ada yang diunggah sama sekali).
 */
class NotulaBagian1DocxService
{
    private const DEFAULT_TEMPLATE_PATH = 'template_notula/SIPINTER_Template_Bagian_I_Mesin.docx';

    /**
     * $data WAJIB hasil NotulaService::kumpulkanDataBagianSatu($notula) milik
     * pemanggil sendiri -- diterima sebagai parameter (bukan resolve NotulaService
     * lewat constructor) supaya kelas ini TIDAK bergantung balik ke NotulaService,
     * yang sejak susunBagianSatu() memakai kelas ini untuk menghasilkan HTML Bagian I
     * (lihat NotulaService) sudah jadi dependensi searah lainnya -- dependensi dua
     * arah di antara keduanya akan bikin container Laravel gagal resolve (circular).
     */
    public function generate(Notula $notula, array $data, string $outputPath): string
    {
        $templatePath = $this->resolveTemplatePath();

        $processor = new TemplateProcessor($templatePath);
        // Template ini pakai delimiter {{...}} (bukan ${...} bawaan PhpWord) supaya
        // sesuai konvensi yang diminta -- lihat TemplateProcessor::setMacroChars().
        // Dikembalikan ke default di akhir generate() karena properti ini STATIC
        // (berlaku global selama request), supaya tidak memengaruhi pemakaian
        // TemplateProcessor lain di kode yang sama.
        $processor->setMacroChars('{{', '}}');

        $this->isiPerIkuDinamis($processor, $templatePath, $data);
        $this->isiHeader($processor, $notula, $data);
        $this->isiPenutup($processor, $notula);

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $processor->saveAs($outputPath);
        $processor->setMacroChars('${', '}');

        return $outputPath;
    }

    /**
     * Berkas Template Notula yang sedang diunggah (Pengaturan > Template Notula) diutamakan
     * bila ada dan masih tersimpan di disk -- jatuh ke berkas bawaan proyek kalau belum
     * pernah diunggah sama sekali (instalasi baru) atau berkasnya hilang dari disk (mis. di
     * hosting tanpa storage persisten, lihat catatan fidelitas serupa di BerkasDownloadController).
     */
    private function resolveTemplatePath(): string
    {
        $config = FolderConfig::current();

        if ($config->template_notula_path && Storage::disk('local')->exists($config->template_notula_path)) {
            return Storage::disk('local')->path($config->template_notula_path);
        }

        $bawaan = base_path(self::DEFAULT_TEMPLATE_PATH);
        abort_unless(file_exists($bawaan), 404, 'Template Bagian I (mesin) belum tersedia.');

        return $bawaan;
    }

    /**
     * Satu nilai teks bebas -- newline TETAP newline asli (TemplateProcessor::setValue
     * otomatis mengubahnya jadi <w:br/> lewat replaceCarriageReturns(), sudah diverifikasi
     * lewat smoke test manual), null jadi placeholder "…".
     */
    private function set(TemplateProcessor $p, string $nama, ?string $nilai): void
    {
        $bersih = $nilai !== null ? trim($nilai) : '';
        $p->setValue($nama, $bersih !== '' ? $bersih : '…', -1);
    }

    /**
     * Susun daftar bernomor "\n1. ...\n2. ..." dari kumpulan teks -- dipakai untuk daftar
     * kegiatan/kendala/solusi/RTL supaya formatnya sama seperti jalur PDF (<ol> bernomor).
     *
     * Diawali newline (bukan cuma DI ANTARA item) karena macro-nya selalu ditaruh
     * langsung setelah label pada baris yang sama di template (mis. "Kendala : {{kendala}}")
     * -- tanpa newline pembuka ini, poin 1 akan menempel di baris label sementara hanya
     * poin 2 dst. yang pindah baris baru.
     */
    private function daftarBernomor(iterable $items): ?string
    {
        $daftar = collect($items)->values();
        if ($daftar->isEmpty()) {
            return null;
        }

        return "\n".$daftar->map(fn ($teks, $i) => ($i + 1).'. '.$teks)->implode("\n");
    }

    private function isiHeader(TemplateProcessor $p, Notula $notula, array $data): void
    {
        $periode = $data['periode'];

        $this->set($p, 'tahun', (string) $periode->tahun);
        $this->set($p, 'triwulan_angka', (string) $data['labelTriwulan']);
        $this->set($p, 'nama_satker', 'BPS KABUPATEN BUTON UTARA');
        $this->set($p, 'agenda_pembahasan', "Monitoring Kinerja Triwulan {$data['labelTriwulan']} Tahun {$periode->tahun}");
        $this->set($p, 'hari_tanggal', $notula->hari_tanggal);
        $this->set($p, 'waktu', $notula->waktu);
        $this->set($p, 'tempat', $notula->tempat);
        $this->set($p, 'pimpinan_rapat', $notula->pimpinan_rapat);
        $this->set($p, 'capaian_triwulanan_persen', $data['rataCapaianTw'] !== null ? (string) $data['rataCapaianTw'] : null);
        $this->set($p, 'capaian_pk_persen', $data['rataCapaianPk'] !== null ? (string) $data['rataCapaianPk'] : null);

        // Satu token global dipakai berulang di setiap blok IKU (linknya sama untuk
        // seluruh IKU pada triwulan yang sama) -- diisi SEKALI di sini, bukan per IKU,
        // karena setValue() sudah mengganti SEMUA kemunculannya di seluruh dokumen.
        $this->set($p, 'lampiran_basis_data', $notula->link_lampiran_basis_data);
    }

    /**
     * Gandakan blok {{iku_blok}}...{{/iku_blok}} sekali per Master IKU (urutan sasaran+kode
     * yang sama dipakai jalur PDF), lalu selipkan hasilnya kembali ke tempat blok aslinya.
     *
     * Setiap salinan diproses lewat TemplateProcessor SEMENTARA (bukan $processor utama)
     * supaya nama macro yang SAMA (mis. {{kode}}, {{target_pk}}) bisa diisi nilai yang
     * BERBEDA untuk tiap IKU -- setValue() bawaan PhpWord mengganti SEMUA kemunculan macro
     * di seluruh dokumen sekaligus, jadi tidak bisa dipakai langsung untuk blok yang berulang.
     */
    private function isiPerIkuDinamis(TemplateProcessor $processor, string $templatePath, array $data): void
    {
        $mainXml = $this->getMainPart($processor);

        [$before, $ikuBlokTemplate, $after] = $this->splitOnMarkers($mainXml, '{{iku_blok}}', '{{/iku_blok}}', 'p');

        $daftarIku = $data['sasaranPerIku']->flatten();

        $sub = new TemplateProcessor($templatePath);
        $sub->setMacroChars('{{', '}}');

        $potongan = $daftarIku->map(function ($iku) use ($data, $ikuBlokTemplate, $sub) {
            return $this->isiSatuIku($sub, $ikuBlokTemplate, $iku, $data);
        });

        $isiBaru = $potongan->implode('<w:p/>').$this->bagianKustomXml($data['bagianKustomPerBagian']);

        $this->setMainPart($processor, $before.$isiBaru.$after);
    }

    /**
     * Bagian kustom (mis. "Manajemen Risiko", App\Livewire\BagianKustomManager) TIDAK
     * punya macro sendiri di template resmi (bagian ini bukan bagian baku Kertas Kerja
     * BPS, murni tambahan per-instansi) -- makanya disisipkan sebagai paragraf XML
     * mentah alih-alih lewat TemplateProcessor::setValue(), tepat setelah blok IKU
     * terakhir (sebelum {{bagian_2}}), sama seperti posisinya dulu di jalur HTML/blade.
     */
    private function bagianKustomXml(Collection $bagianKustomPerBagian): string
    {
        $xml = '';

        foreach ($bagianKustomPerBagian as $bagian) {
            $xml .= '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">'
                .htmlspecialchars($bagian->nama, ENT_QUOTES | ENT_XML1)
                .'</w:t></w:r></w:p>';

            foreach ($bagian->poin as $poin) {
                $teks = ($poin->masterIku->kode ?? '').': '.$poin->teks;
                $xml .= '<w:p><w:r><w:t xml:space="preserve">• '
                    .htmlspecialchars($teks, ENT_QUOTES | ENT_XML1)
                    .'</w:t></w:r></w:p>';
            }
        }

        return $xml;
    }

    /**
     * Isi SATU salinan blok per-IKU: pilih varian SAKIP/BerAKHLAK/RO yang sesuai (dideteksi
     * dari teks indikator, bukan kode -- penomoran kode IKU beda tiap instansi & bisa
     * berubah), gandakan baris RO per Kegiatan, lalu isi seluruh macro sisa lewat
     * TemplateProcessor sekali pakai.
     */
    private function isiSatuIku(TemplateProcessor $sub, string $blokTemplate, $iku, array $data): string
    {
        $kode = $iku->kode;
        $rekap = $data['rekapPerIku']->get($iku->id, []);
        $capaian = $data['capaianPerIku']->get($iku->id);
        $kegiatanIku = $data['kegiatanPerIku']->get($iku->id, collect());
        $roIku = $data['roPerIku']->get($iku->id, collect());
        $kendalaSolusiTriwulan = $data['kendalaSolusiPerIku']->get($iku->id, collect());
        $rtlIku = $data['rtlPerIku']->get($iku->id, collect());
        $rtlSebelumnyaIku = $data['rtlSebelumnyaPerIku']->get($iku->id, collect());
        $linkFolder = $data['linkFolderPerIku'][$iku->id] ?? null;
        $linkFolderTwSebelumnya = $data['linkFolderTwSebelumnyaPerIku'][$iku->id] ?? null;

        // Deteksi SAKIP/BerAKHLAK dari TEKS indikator (bukan kode -- penomoran kode IKU
        // beda tiap instansi & bisa berubah).
        $indikatorLower = mb_strtolower($iku->indikator);
        $isSakip = str_contains($indikatorLower, 'sakip');
        $isBerakhlak = str_contains($indikatorLower, 'berakhlak');
        // Tabel RO hanya tampil bila BENAR-BENAR ada RO terisi -- sebelumnya tetap
        // tercetak (satu baris placeholder "...") walau IKU ini belum punya RO sama
        // sekali, padahal seharusnya seluruh blok baru muncul begitu ada isiannya.
        $tampilkanRo = ! $isSakip && ! $isBerakhlak && empty($rekap['realisasi'] ?? null) && $roIku->isNotEmpty();

        $xml = $blokTemplate;
        $xml = $this->resolveBlokKondisional($xml, 'blok_sakip', $isSakip);
        $xml = $this->resolveBlokKondisional($xml, 'blok_berakhlak', $isBerakhlak);
        $xml = $this->resolveBlokKondisional($xml, 'blok_ro', $tampilkanRo);
        if ($tampilkanRo) {
            $xml = $this->gandakanBarisRo($xml, $roIku);
        }

        $sub->setMacroChars('{{', '}}');
        $this->setMainPart($sub, $xml);

        $this->set($sub, 'kode', $kode);
        $this->set($sub, 'indikator', $iku->indikator);
        $this->set($sub, 'sasaran', $iku->sasaran);
        $this->set($sub, 'target_pk', PengaturanCapaian::formatAngka($rekap['target_pk'] ?? null));
        $this->set($sub, 'target_tw', PengaturanCapaian::formatAngka($rekap['target_tw'] ?? null));
        $this->set($sub, 'realisasi_tw', PengaturanCapaian::formatAngka($rekap['realisasi'] ?? null));
        $this->set($sub, 'capaian_tw', PengaturanCapaian::formatPersen($rekap['capaian_tw'] ?? null));
        $this->set($sub, 'capaian_pk', PengaturanCapaian::formatPersen($rekap['capaian_pk'] ?? null));

        // Analisis Capaian Kinerja: narasi bebas dari Tim SAKIP, DIIKUTI daftar
        // bernomor kegiatan pendukung IKU ini pada triwulan berjalan (RF-37) --
        // sama seperti pola "...disamping itu, terdapat beberapa kegiatan..." yang
        // sudah dipakai Tim SAKIP di jalur PDF. Ditampilkan untuk SEMUA varian
        // (termasuk SAKIP/BerAKHLAK) -- kotak "Analisis Capaian Kinerja" tidak lagi
        // dirangkap jadi pertanyaan proksi seperti versi template lama.
        $analisis = trim((string) $capaian?->analisis_capaian);
        $daftarKegiatan = $this->daftarBernomor($kegiatanIku->pluck('uraian_kegiatan')->filter());
        // $daftarKegiatan SUDAH diawali "\n" sendiri (lihat daftarBernomor()) -- pemisahnya
        // cukup SATU "\n" lagi di sini supaya total ada baris kosong di antara narasi dan
        // daftar (bukan dua "\n\n" + "\n" bawaan yang bikin tiga baris kosong).
        $analisisLengkap = $analisis !== '' && $daftarKegiatan !== null
            ? $analisis."\n".$daftarKegiatan
            : ($analisis !== '' ? $analisis : $daftarKegiatan);
        $this->set($sub, 'analisis_capaian', $analisisLengkap);

        if ($isSakip) {
            $this->set($sub, 'target_proksi', null);
            $this->set($sub, 'realisasi_proksi', null);
        }

        // Kendala & Solusi sebagai DUA variabel terpisah (mengikuti dua baris terpisah
        // pada dokumen resmi), KUMULATIF TW1..TW berjalan (RF-28), disusun sebagai
        // daftar bernomor per indikator -- sama seperti jalur PDF.
        $semuaKendalaSolusi = $kendalaSolusiTriwulan->flatten(1);
        $this->set($sub, 'kendala', $this->daftarBernomor($semuaKendalaSolusi->pluck('kendala')->filter()));
        $this->set($sub, 'solusi', $this->daftarBernomor($semuaKendalaSolusi->pluck('solusi')->filter()));

        $rtlTeks = $this->daftarBernomor($rtlIku->pluck('rtl_teks')->filter());
        // PIC Tindak Lanjut = tim yang ditugaskan pada IKU ini di Master IKU
        // (master_iku.tim), BUKAN nama orang yang diketik bebas per poin RTL.
        $this->set($sub, 'rtl', $rtlTeks);
        $this->set($sub, 'pic_rtl', $iku->tim);
        // Batas Waktu Tindak Lanjut SELALU akhir bulan triwulan tsb (RF-34, sesuai
        // Kertas Kerja resmi -- satu batas waktu yang sama untuk SEMUA poin RTL
        // triwulan yang sama) -- dihitung LANGSUNG dari triwulan/tahun periode
        // notula ini (yang sama dipakai memfilter $rtlIku, lihat
        // NotulaService::kumpulkanDataBagianSatu()), BUKAN dibaca dari kolom
        // rtl_evaluasi.batas_waktu tersimpan (bisa berbeda-beda kalau pernah
        // diketik manual sebelum field ini dikunci di form RtlEvaluasi).
        $this->set($sub, 'batas_waktu_rtl', $rtlIku->isNotEmpty() ? $this->akhirTriwulan($data['periode']->tahun, $data['periode']->triwulan)->translatedFormat('F Y') : null);

        // IKU bersatuan Persen SEMUA memakai rumus baku "y = n/N x 100%" (beda dari
        // IKU bersatuan Poin yang rumusnya unik per indikator, mis. IPP/TPSS) --
        // dirakit otomatis di formulaPersenOtomatis() supaya Tim SAKIP tidak perlu
        // mengetik ulang rumus & memperbarui angkanya tiap triwulan. Kolom
        // dasar_hitung sendiri (kalau diisi) TETAP ditampilkan sebagai keterangan
        // tambahan setelahnya (mis. rincian "Target 2026: N = 2 mencakup: ..."),
        // bukan lagi wajib memuat rumus lengkap.
        //
        // [[a|b]] (pecahan bersusun di PDF, lihat App\Support\RumusMarkup) diratakan
        // jadi notasi biasa "a/b" -- .docx tidak mendukung pecahan bersusun lewat
        // penggantian teks biasa (TemplateProcessor::setValue).
        $dasarHitungGabungan = collect([$this->formulaPersenOtomatis($iku, $rekap), $iku->dasar_hitung])
            ->filter(fn ($t) => filled($t))
            ->implode("\n\n");
        $dasarHitungTeks = RumusMarkup::keTeksPolos($dasarHitungGabungan);
        $dasarHitung = trim(($dasarHitungTeks ?: '').($iku->basis_data ? ' Basis Data: '.$iku->basis_data : ''));
        $this->set($sub, 'dasar_hitung', $dasarHitung !== '' ? $dasarHitung : null);
        $this->set($sub, 'bukti_realisasi', $linkFolder);
        $this->set($sub, 'bukti_rtl_sebelumnya', $rtlSebelumnyaIku->isNotEmpty() ? $linkFolderTwSebelumnya : null);
        $this->set($sub, 'penjelasan_lainnya', $capaian?->catatan);

        return $this->getMainPart($sub);
    }

    /**
     * Akhir bulan triwulan $triwulan pada tahun $tahun (TW I->Maret, II->Juni,
     * III->September, IV->Desember) -- dipakai untuk Batas Waktu Tindak Lanjut,
     * lihat pemanggilnya di isiSatuIku().
     */
    private function akhirTriwulan(int $tahun, int $triwulan): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::create($tahun, $triwulan * 3, 1)->endOfMonth();
    }

    /**
     * Rumus "Dasar Hitung" baku untuk IKU bersatuan Persen: "y = n/N x 100%" --
     * SEMUA IKU % memakai pola yang SAMA (beda dari IKU bersatuan Poin, mis. IPP/
     * TPSS, yang rumusnya unik per indikator dan tetap diketik manual). n & N di
     * sini BUKAN pasangan yang dipakai capaian_tw/capaian_pk (itu memakai kumulatif
     * TW I s.d. TW berjalan, lihat CapaianTahunan::realisasiKumulatif() -- TIDAK
     * disentuh):
     * - n = nilai MENTAH triwulan berjalan SAJA (rekap['x_realisasi_tw'], apa adanya
     *   yang diketik Tim SAKIP di Verifikasi Capaian, BUKAN kumulatif).
     * - N = Target Tahunan Y (rekap['y_target'], konstan sepanjang tahun -- BUKAN
     *   dijumlahkan per triwulan seperti alokasi/realisasi).
     *
     * Null (bukan dirakit) bila datanya belum lengkap (satuan bukan Persen, atau
     * Deskripsi X/Y / target Y / realisasi triwulan berjalan belum diisi) -- supaya
     * jatuh ke isian manual kolom `dasar_hitung` apa adanya, bukan menampilkan
     * rumus dengan bagian kosong.
     */
    private function formulaPersenOtomatis(MasterIku $iku, array $rekap): ?string
    {
        if ($iku->satuan !== 'Persen' || ! $iku->deskripsi_x || ! $iku->deskripsi_y) {
            return null;
        }

        $n = $rekap['x_realisasi_tw'] ?? null;
        $besarN = $rekap['y_target'] ?? null;
        if ($n === null || $besarN === null) {
            return null;
        }

        $nTeks = PengaturanCapaian::formatAngka($n);
        $besarNTeks = PengaturanCapaian::formatAngka($besarN);

        return "y = [[n|N]] × 100% = [[{$nTeks}|{$besarNTeks}]] × 100%\n\ndimana:\ny = {$iku->indikator}\nn = {$iku->deskripsi_x}\nN = {$iku->deskripsi_y}";
    }

    /**
     * {{blok_x}}...{{/blok_x}} -- kalau $tampilkan true, baris marker-nya dibuang tapi ISINYA
     * dipertahankan; kalau false, marker DAN isinya sekaligus dibuang (tidak ada blok lain yang
     * dipilih untuk IKU ini, mis. sudah ada realisasi & bukan SAKIP/BerAKHLAK).
     */
    private function resolveBlokKondisional(string $xml, string $namaBlok, bool $tampilkan): string
    {
        [$before, $inner, $after] = $this->splitOnMarkers($xml, "{{{$namaBlok}}}", "{{/{$namaBlok}}}", 'tr');

        return $before.($tampilkan ? $inner : '').$after;
    }

    /**
     * Gandakan baris template RO ({{ro_row}}...{{/ro_row}}, 3 kolom: Rincian Output/Realisasi
     * Volume RO/Progres) sekali per RincianOutput pada IKU ini (RF baru: satu Kegiatan boleh
     * punya banyak RO), bukan katalog kode RO tetap seperti versi lama. Kosong (belum ada RO)
     * -> baris ini tidak ditampilkan sama sekali (lihat $tampilkanRo di isiSatuIku()).
     */
    private function gandakanBarisRo(string $xml, Collection $roIku): string
    {
        [$before, $rowTemplate, $after] = $this->splitOnMarkers($xml, '{{ro_row}}', '{{/ro_row}}', 'tr');

        if ($roIku->isEmpty()) {
            $baris = $this->isiBarisRo($rowTemplate, '…', '…', '…');
        } else {
            $baris = $roIku->map(function ($ro) use ($rowTemplate) {
                $progres = $ro->progres_persen !== null
                    ? number_format((float) $ro->progres_persen, 2, ',', '.').'%'
                    : '…';

                return $this->isiBarisRo(
                    $rowTemplate,
                    $ro->uraian ?: $ro->kegiatan->uraian_kegiatan ?: '…',
                    $ro->volume_ro ?: '…',
                    $progres
                );
            })->implode('');
        }

        return $before.$baris.$after;
    }

    private function isiBarisRo(string $rowXml, string $uraian, string $vol, string $progres): string
    {
        return str_replace(
            ['{{ro_uraian}}', '{{ro_vol}}', '{{ro_progres}}'],
            [htmlspecialchars($uraian, ENT_QUOTES), htmlspecialchars($vol, ENT_QUOTES), htmlspecialchars($progres, ENT_QUOTES)],
            $rowXml
        );
    }

    /**
     * {{bagian_2}} dan {{bagian_3}} SENGAJA dibiarkan sebagai kode mentah (tidak
     * disubstitusi) -- keduanya jadi penanda tempat Tim SAKIP menempelkan/menggabungkan
     * dokumen Bagian II dan III yang disusun terpisah di luar sistem.
     */
    private function isiPenutup(TemplateProcessor $p, Notula $notula): void
    {
        $this->set($p, 'kota_tanggal_ttd', $notula->kota_ttd);
        $this->set($p, 'ttd_kepala', $notula->kepala_satker);
        $this->set($p, 'ttd_notulis', $notula->notulis);
    }

    // ------------------------------------------------------------------
    // Utilitas manipulasi XML mentah -- dipakai untuk hal yang TIDAK didukung
    // TemplateProcessor bawaan: menggandakan satu blok/baris tabel dengan isi yang
    // berbeda tiap salinan sebelum digabung kembali ke dokumen utama (lihat catatan
    // kelas di atas). tempDocumentMainPart bersifat protected di PhpWord, jadi diakses
    // lewat Reflection -- dipakai murni untuk baca/tulis mentah, tidak mengubah
    // perilaku TemplateProcessor itu sendiri.
    // ------------------------------------------------------------------

    private function getMainPart(TemplateProcessor $p): string
    {
        $ref = new ReflectionProperty(TemplateProcessor::class, 'tempDocumentMainPart');
        $ref->setAccessible(true);

        return $ref->getValue($p);
    }

    private function setMainPart(TemplateProcessor $p, string $xml): void
    {
        $ref = new ReflectionProperty(TemplateProcessor::class, 'tempDocumentMainPart');
        $ref->setAccessible(true);
        $ref->setValue($p, $xml);
    }

    /**
     * Cari elemen ('p' = <w:p>, 'tr' = <w:tr>) yang MEMUAT posisi $pos, kembalikan
     * [awal, akhir] byte offset elemen tsb (termasuk tag pembuka & penutupnya).
     */
    private function elementBounds(string $xml, int $pos, string $unit): array
    {
        $openTag = $unit === 'p' ? '<w:p' : '<w:tr';
        $closeTag = $unit === 'p' ? '</w:p>' : '</w:tr>';
        $start = strrpos(substr($xml, 0, $pos), $openTag);
        $endTagPos = strpos($xml, $closeTag, $pos);

        if ($start === false || $endTagPos === false) {
            throw new RuntimeException("Elemen ({$unit}) tidak ditemukan di sekitar posisi {$pos} -- template Bagian I (mesin) mungkin rusak/berubah strukturnya.");
        }

        return [$start, $endTagPos + strlen($closeTag)];
    }

    /**
     * Belah $xml jadi [sebelum, isi-di-antara, sesudah] berdasarkan dua penanda literal
     * $openNeedle/$closeNeedle -- keduanya HARUS berupa baris/paragraf penanda buatan sendiri
     * (bukan teks konten asli), karena "isi" di sini SELALU tidak termasuk elemen milik kedua
     * penanda itu sendiri.
     */
    private function splitOnMarkers(string $xml, string $openNeedle, string $closeNeedle, string $unit): array
    {
        $openPos = strpos($xml, $openNeedle);
        if ($openPos === false) {
            throw new RuntimeException("Penanda pembuka tidak ditemukan di template: {$openNeedle}");
        }
        [$openStart, $openEnd] = $this->elementBounds($xml, $openPos, $unit);

        $closePos = strpos($xml, $closeNeedle, $openEnd);
        if ($closePos === false) {
            throw new RuntimeException("Penanda penutup tidak ditemukan di template: {$closeNeedle}");
        }
        [$closeStart, $closeEnd] = $this->elementBounds($xml, $closePos, $unit);

        return [substr($xml, 0, $openStart), substr($xml, $openEnd, $closeStart - $openEnd), substr($xml, $closeEnd)];
    }
}
