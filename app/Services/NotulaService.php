<?php

namespace App\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\BagianKustom;
use App\Models\Berkas;
use App\Models\Capaian;
use App\Models\CapaianTahunan;
use App\Models\IndikatorProksi;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\Periode;
use App\Models\RtlEvaluasi;
use App\Models\User;
use App\Support\BlokBagianSatu;
use App\Support\HtmlNotulaDompdf;
use App\Support\PenggabungDocx;
use App\Support\PenggantiBerkasAman;
use App\Support\RumusMarkup;
use Barryvdh\DomPDF\Facade\Pdf as PdfFacade;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        protected PenggantiBerkasAman $berkas,
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
        $html = $this->bangunHtmlBagianSatu($notula);

        $notula->update(['bagian1_html' => $html]);

        return $html;
    }

    /**
     * "Susun Ulang Otomatis" yang MEMPERTAHANKAN suntingan manual: isi dari data
     * terbaru (IKU baru, angka/kendala/RTL yang berubah) masuk, tapi blok & baris
     * yang sudah disunting Tim SAKIP di pratinjau tidak ditimpa -- lihat
     * App\Support\BlokBagianSatu::gabungkan(). $suntingan = isi editor saat ini.
     *
     * $timpaSemua, atau $suntingan yang belum punya pembungkus blok (disusun sebelum
     * fitur ini ada), jatuh ke penyusunan penuh seperti susunBagianSatu().
     *
     * @return array{html: string, dipertahankan: int, digabung: bool}
     */
    public function susunUlangBagianSatu(Notula $notula, ?string $suntingan, bool $timpaSemua = false): array
    {
        $baru = $this->bangunHtmlBagianSatu($notula);
        $suntingan = $suntingan !== null ? $this->kembalikanPenandaBagianDuaTiga($suntingan) : null;
        $digabung = ! $timpaSemua && BlokBagianSatu::punyaBlok($suntingan) && BlokBagianSatu::punyaBlok($baru);

        $hasil = $digabung ? BlokBagianSatu::gabungkan($suntingan, $baru) : ['html' => $baru, 'dipertahankan' => 0];

        $notula->update(['bagian1_html' => $hasil['html']]);

        return $hasil + ['digabung' => $digabung];
    }

    private function bangunHtmlBagianSatu(Notula $notula): string
    {
        $data = $this->kumpulkanDataBagianSatu($notula);

        $dir = storage_path("app/private/notula/{$notula->id}/bagian1-sementara");
        $this->pastikanFolder($dir);
        // Nama unik per pemanggilan: dua penyusunan bersamaan atas notula yang sama tidak
        // saling menimpa docx-nya, dan convertToHtml() (yang membersihkan semua berkas
        // sebasename di $dir) tidak menghapus berkas milik penyusunan lain.
        $docxPath = $dir.'/bagian1-'.Str::lower(Str::random(10)).'.docx';

        // sertakanBlokTtdMandiri: false -- HTML ini disisipkan ke tengah dokumen
        // gabungan Bagian I+II+III, yang sudah punya blok TTD akhirnya sendiri di
        // paling bawah (lihat pdf.notula-utuh, .ttd-blok) -- lihat catatan parameter
        // itu di NotulaBagian1DocxService::generate().
        $this->docx->generate($notula, $data, $docxPath, sertakanBlokTtdMandiri: false, untukPratinjauHtml: true);
        $formulaUrutan = $this->docx->formulaUrutanTerakhir();

        try {
            $html = $this->konversi->convertToHtml($docxPath, $dir);
        } finally {
            if (file_exists($docxPath) && ! @unlink($docxPath)) {
                Log::warning('Berkas docx sementara Bagian I tidak bisa dihapus: '.basename($docxPath), ['path' => $docxPath]);
            }
        }

        return BlokBagianSatu::bungkus($this->gantiPenandaRumusDenganHtml($html, $formulaUrutan));
    }

    /**
     * LibreOffice MEMBUANG rumus OOXML Math (<m:oMath>) saat mengekspor .docx ke
     * HTML -- paragraf rumusnya jadi kosong di pratinjau padahal di .docx/PDF unduhan
     * tampil. Karena itu susunBagianSatu() meminta docx-nya ditulis dengan TEKS
     * PENANDA (NotulaBagian1DocxService::penandaRumus()) di posisi rumus, lalu di sini
     * tiap penanda diganti HTML bersusun yang SAMA PERSIS dipakai jalur unduhan PDF
     * langsung (RumusMarkup::keHtml()) -- tetap di paragraf rata-tengahnya sendiri,
     * persis seperti posisinya di Word. Dibungkus span.rumus (TANPA
     * contenteditable="false") supaya pembilang/penyebut & teks di sekitarnya bisa
     * disunting langsung di pratinjau; garis putus-putusnya cuma penanda visual.
     *
     * Penggantian STRING mentah (bukan reparse DOMDocument) SENGAJA -- $html di sini
     * sudah lolos perbaikan struktur tabel LibreOffice (gabungkanTbodyBersebelahan()/
     * satukanColgroup(), lihat LibreOfficeConversionService::ekstrakKontenInline())
     * yang KHUSUS ditulis karena reparse lewat libxml bisa mengacak ulang
     * <tbody>/<colgroup>.
     */
    private function gantiPenandaRumusDenganHtml(string $html, array $formulaUrutan): string
    {
        foreach ($formulaUrutan as $i => $formula) {
            $penanda = NotulaBagian1DocxService::penandaRumus($i);

            if (! str_contains($html, $penanda)) {
                Log::warning("Penanda rumus ke-{$i} tidak ditemukan di HTML hasil konversi LibreOffice -- rumusnya tidak tampil di pratinjau.");

                continue;
            }

            $html = str_replace($penanda, '<span class="rumus">'.RumusMarkup::keHtml($formula).'</span>', $html);
        }

        return $html;
    }

    /**
     * Notula LENGKAP (Bagian I + II + III) sebagai .docx -- Bagian I dibangun dari
     * bagian1_html TERSIMPAN (isi pratinjau yang sudah disunting, bukan disusun ulang
     * dari data mentah). Rumus bersusun (span.rumus) dibaca balik jadi sintaks
     * RumusMarkup (lihat RumusMarkup::dariHtml()) lalu disisipkan sebagai OOXML Math
     * sungguhan -- termasuk angka yang sudah disunting di pratinjau.
     *
     * Bagian II/III disisipkan dari .docx ASLI unggahan (bagian{2,3}_docx) di posisi
     * penanda {{bagian_2}}/{{bagian_3}} lewat App\Support\PenggabungDocx -- tabel,
     * penomoran, gaya & pengaturan halamannya tetap seperti yang diunggah, bukan hasil
     * konversi HTML yang merusak format. Unggahan lama (sebelum .docx aslinya
     * disimpan) memakai HTML hasil konversinya sebagai cadangan -- unggah ulang
     * Bagian II/III supaya formatnya persis seperti berkas aslinya.
     *
     * Notula yang Bagian I-nya belum pernah disusun jatuh ke template + data mentah
     * (NotulaBagian1DocxService::generate()) seperti sebelumnya.
     */
    public function renderNotulaLengkapDocx(Notula $notula, string $outputPath, ?bool $sertakanTtd = null): string
    {
        $sertakanTtd ??= $notula->status === Notula::STATUS_DISETUJUI;

        if (blank($notula->bagian1_html)) {
            return $this->docx->generate($notula, $this->kumpulkanDataBagianSatu($notula), $outputPath);
        }

        $dir = dirname($outputPath);
        $this->pastikanFolder($dir);
        $acak = Str::lower(Str::random(10));
        $htmlPath = $dir.'/notula-unduh-'.$acak.'.html';
        $formula = [];
        $sisipan = [];
        $sementara = [$htmlPath];

        try {
            foreach ([2 => [$notula->bagian2_docx, $notula->bagian2_html], 3 => [$notula->bagian3_docx, $notula->bagian3_html]] as $ke => [$base64, $htmlBagian]) {
                $isi = filled($base64) ? base64_decode((string) $base64, true) : false;

                if ($isi !== false && $isi !== '') {
                    $path = $dir."/bagian{$ke}-asli-{$acak}.docx";
                    file_put_contents($path, $isi);
                } elseif (filled($htmlBagian)) {
                    // Unggahan lama (sebelum .docx aslinya disimpan): HTML hasil konversinya
                    // dijadikan .docx TERSENDIRI dulu, supaya gayanya (kelas P1, T1, ...
                    // bernama sama dengan milik Bagian I) tidak bentrok dengan Bagian I.
                    $htmlBagianPath = $dir."/bagian{$ke}-html-{$acak}.html";
                    file_put_contents($htmlBagianPath, $this->dokumenHtmlUntukLibreOffice((string) $htmlBagian));
                    $sementara[] = $htmlBagianPath;
                    $path = $this->konversi->convertHtmlToDocx($htmlBagianPath, $dir);
                    // Impor HTML LibreOffice memakai kertas bawaannya -- samakan dengan
                    // template, karena PenggabungDocx mempertahankan tata halaman sumber.
                    $this->sisipkanRumusDanTataHalamanDocx($path, []);
                } else {
                    continue;
                }

                $sementara[] = $path;
                $sisipan[self::penandaBagianDocx($ke)] = $path;
            }

            file_put_contents($htmlPath, $this->htmlUntukDocx($notula, $formula, array_keys($sisipan), $sertakanTtd));
            $hasil = $this->konversi->convertHtmlToDocx($htmlPath, $dir);
            $this->sisipkanRumusDanTataHalamanDocx($hasil, $formula);

            if ($sisipan !== []) {
                (new PenggabungDocx)->sisipkan($hasil, $sisipan);
            }

            if ($hasil !== $outputPath && ! @rename($hasil, $outputPath)) {
                throw new RuntimeException('Berkas notula (.docx) gagal dipindahkan ke lokasi unduhan.');
            }
        } finally {
            foreach ($sementara as $path) {
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
        }

        return $outputPath;
    }

    /**
     * Teks paragraf penanda posisi Bagian II/III di .docx hasil konversi HTML --
     * diganti isi .docx asli oleh PenggabungDocx. Tanpa spasi supaya tidak dipecah
     * LibreOffice ke beberapa run.
     */
    public static function penandaBagianDocx(int $ke): string
    {
        return "SIPINTERBAGIAN{$ke}X";
    }

    /**
     * Dokumen HTML utuh untuk dikonversi LibreOffice jadi .docx: CSS dilepas dari
     * lingkup .notula-inline-* (pemilih turunan tidak dipahami impor HTML LibreOffice)
     * & dipindah ke <head>, tiap rumus diganti teks penanda (isi rumusnya dikumpulkan
     * ke $formula), dan blok TTD ditambahkan bila $sertakanTtd.
     *
     * Bagian II/III yang akan disisipkan sebagai .docx ($penandaDocx) diganti paragraf
     * penanda (lihat penandaBagianDocx()); sisanya dikosongkan.
     *
     * @param  list<string>  $formula
     * @param  list<string>  $penandaDocx
     */
    private function htmlUntukDocx(Notula $notula, array &$formula, array $penandaDocx = [], bool $sertakanTtd = false): string
    {
        $isiBagian = [];
        foreach ([2, 3] as $ke) {
            $penanda = self::penandaBagianDocx($ke);
            $isiBagian[$ke] = in_array($penanda, $penandaDocx, true) ? '<p>'.$penanda.'</p>' : null;
        }

        $html = $this->sisipkanBagianDuaTiga((string) $notula->bagian1_html, $isiBagian[2], $isiBagian[3]);

        return $this->dokumenHtmlUntukLibreOffice($html, function (\DOMDocument $dom, \DOMXPath $xpath) use (&$formula) {
            $daftarRumus = $xpath->query('//span[contains(concat(" ", normalize-space(@class), " "), " rumus ") or @contenteditable="false"]');
            foreach (iterator_to_array($daftarRumus) as $span) {
                $penanda = NotulaBagian1DocxService::penandaRumus(count($formula));
                $formula[] = RumusMarkup::dariHtml($span);
                $span->parentNode->replaceChild($dom->createTextNode($penanda), $span);
            }
        }, $sertakanTtd ? $this->blokTtdDocx($notula) : '');
    }

    /**
     * Potongan HTML (pratinjau tersimpan) jadi dokumen HTML utuh yang dipahami impor
     * HTML LibreOffice: <style> dipindah ke <head> dengan lingkup .notula-inline-*
     * dilepas, dan aturan berpemilih sederhana ditempelkan inline.
     */
    private function dokumenHtmlUntukLibreOffice(string $html, ?callable $olahDom = null, string $tambahanBody = ''): string
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><html><body>'.$html.'</body></html>', LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        if ($olahDom !== null) {
            $olahDom($dom, $xpath);
        }

        $css = '';
        foreach (iterator_to_array($dom->getElementsByTagName('style')) as $style) {
            $css .= $style->textContent."\n";
            $style->parentNode->removeChild($style);
        }
        $css = preg_replace('/\.notula-inline-[A-Za-z0-9]+\s+/', '', $css) ?? $css;
        $this->tempelkanCssSederhana($xpath, $css);

        $body = '';
        foreach ($dom->getElementsByTagName('body')->item(0)?->childNodes ?? [] as $anak) {
            $body .= $dom->saveHTML($anak);
        }

        $body .= $tambahanBody;

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'.$css.'</style></head><body>'.$body.'</body></html>';
    }

    /**
     * Impor HTML LibreOffice mengabaikan aturan kelas (mis. "p.western { font-family:
     * Arial }") untuk paragraf DI DALAM sel tabel -- teks tabel jatuh ke font serif
     * bawaannya. Aturan berpemilih sederhana ("p", "p.western", ".P1") ditempelkan
     * sebagai style inline; deklarasi inline yang sudah ada tetap menang (ditaruh
     * di belakang).
     */
    private function tempelkanCssSederhana(\DOMXPath $xpath, string $css): void
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $aturan, PREG_SET_ORDER);

        foreach ($aturan as [, $pemilihGabungan, $deklarasi]) {
            $deklarasi = trim($deklarasi);
            if ($deklarasi === '') {
                continue;
            }

            foreach (explode(',', $pemilihGabungan) as $pemilih) {
                if (! preg_match('/^\s*([a-z][a-z0-9]*)?(?:\.([A-Za-z0-9_-]+))?\s*$/i', $pemilih, $m) || ($m[1] ?? '') === '' && ($m[2] ?? '') === '') {
                    continue;
                }

                $query = '//'.(($m[1] ?? '') !== '' ? strtolower($m[1]) : '*')
                    .(($m[2] ?? '') !== '' ? '[contains(concat(" ", normalize-space(@class), " "), " '.$m[2].' ")]' : '');

                foreach ($xpath->query($query) as $el) {
                    $lama = trim($el->getAttribute('style'));
                    $el->setAttribute('style', rtrim($deklarasi, '; ').'; '.$lama);
                }
            }
        }
    }

    /**
     * Padanan .ttd-blok pdf.notula-utuh dalam bentuk tabel tanpa garis -- float
     * CSS tidak dipahami impor HTML LibreOffice.
     */
    private function blokTtdDocx(Notula $notula): string
    {
        $d = $this->dataNotulaUtuh($notula, true);
        $tempatTanggal = trim(($d['kotaTtd'] ?: '').($d['kotaTtd'] && $d['tanggal'] ? ', ' : '').($d['tanggal'] ?: ''));
        $titik = '.......................................';
        $sel = 'width="50%" valign="top" style="border:none; text-align:center; font-family:Arial; font-size:11pt"';

        return '<p><br></p><table width="100%" cellpadding="0" cellspacing="0" style="border:none"><tr>'
            .'<td '.$sel.'>Mengetahui,<br>Kepala Badan Pusat Statistik Kabupaten Buton Utara<br><br><br><br><b>'.e($d['namaKepala'] ?: $titik).'</b></td>'
            .'<td '.$sel.'>'.e($tempatTanggal).'<br><br>Notulis,<br><br><br><br><b>'.e($d['namaNotulis'] ?: $titik).'</b></td>'
            .'</tr></table>';
    }

    /**
     * Pasca-proses .docx hasil LibreOffice: tiap teks penanda rumus diganti OOXML
     * Math (RumusMarkup::keOmml(), sama seperti unduhan berbasis template), dan
     * ukuran kertas/margin disamakan dengan template Bagian I aktif (impor HTML
     * LibreOffice memakai kertas bawaannya sendiri).
     *
     * @param  list<string>  $formula
     */
    private function sisipkanRumusDanTataHalamanDocx(string $docxPath, array $formula): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($docxPath) !== true) {
            throw new RuntimeException('Berkas Bagian I (.docx) hasil konversi tidak bisa dibuka.');
        }

        try {
            $xml = $zip->getFromName('word/document.xml');
            if ($xml === false) {
                throw new RuntimeException('Berkas Bagian I (.docx) hasil konversi rusak (document.xml tidak ada).');
            }

            foreach ($formula as $i => $rumus) {
                $penanda = preg_quote(NotulaBagian1DocxService::penandaRumus($i), '/');
                $pola = '/<w:r>((?:(?!<\/w:r>).)*?)<w:t(?:\s[^>]*)?>([^<]*?)'.$penanda.'([^<]*)<\/w:t>((?:(?!<\/w:r>).)*?)<\/w:r>/s';

                $xml = preg_replace_callback($pola, function (array $m) use ($rumus) {
                    $rPr = preg_match('/<w:rPr>.*?<\/w:rPr>/s', $m[1], $r) ? $r[0] : '';
                    $run = fn (string $t) => $t === '' ? '' : '<w:r>'.$rPr.'<w:t xml:space="preserve">'.$t.'</w:t></w:r>';

                    return $run($m[2]).RumusMarkup::keOmml($rumus).$run($m[3]);
                }, $xml, 1) ?? $xml;
            }

            if (! str_contains($xml, 'xmlns:m=')) {
                $xml = preg_replace('/<w:document\b/', '<w:document xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"', $xml, 1) ?? $xml;
            }

            $h = $this->docx->tataHalamanTemplate();
            $twip = fn (string $k) => (int) round($h[$k] * 20);
            $xml = preg_replace('/<w:pgSz\b[^>]*\/>/', '<w:pgSz w:w="'.$twip('lebar').'" w:h="'.$twip('tinggi').'"'.($h['lebar'] > $h['tinggi'] ? ' w:orient="landscape"' : '').'/>', $xml) ?? $xml;
            $xml = preg_replace_callback('/<w:pgMar\b([^>]*)\/>/', function (array $m) use ($twip) {
                $atr = $m[1];
                foreach (['top' => 'atas', 'right' => 'kanan', 'bottom' => 'bawah', 'left' => 'kiri'] as $w => $k) {
                    $atr = preg_replace('/\bw:'.$w.'="[^"]*"/', 'w:'.$w.'="'.$twip($k).'"', $atr) ?? $atr;
                }

                return '<w:pgMar'.$atr.'/>';
            }, $xml) ?? $xml;

            $zip->addFromString('word/document.xml', $xml);
        } finally {
            $zip->close();
        }
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

        // IKU dianggap "sudah diverifikasi Tim SAKIP" bila SALAH SATU baris Capaian-nya
        // pada TRIWULAN ini (dicari lewat relasi periode->tahun/triwulan, BUKAN
        // $periode->id notula yang selalu bulan PERTAMA triwulan -- lihat
        // NotulaService::untukTriwulan()) berstatus "diverifikasi"/"disetujui". Perlu
        // dicari lintas SELURUH bulan triwulan (bukan cuma bulan pertama) karena satu
        // IKU boleh punya baris Capaian terpisah per bulan bila Kegiatan tambahan
        // diajukan di bulan berbeda dalam triwulan yang sama (lihat
        // PengisianKegiatan::ajukanIsian(), Capaian::firstOrCreate per iku_id+periode_id
        // bulan tsb) -- kalau hanya dicek bulan pertama, IKU yang verifikasinya baru
        // selesai di bulan kedua/ketiga triwulan salah hilang dari notula.
        //
        // IKU yang belum sampai situ (draft/diajukan/sedang ditangani/dikembalikan, atau
        // belum ada Capaian sama sekali triwulan ini) TIDAK ditampilkan sama sekali di
        // Bagian I (dipakai baik jalur pratinjau HTML maupun unduhan .docx, keduanya
        // lewat method ini), supaya notula (dokumen resmi yang diunduh/dibagikan) tidak
        // pernah memuat data yang belum final diperiksa Tim SAKIP.
        $ikuTerverifikasiIds = Capaian::whereHas('periode', fn ($q) => $q->where('tahun', $periode->tahun)->where('triwulan', $tw))
            ->whereIn('status', [Capaian::STATUS_DIVERIFIKASI, Capaian::STATUS_DISETUJUI])
            ->pluck('iku_id');

        $sasaranPerIku = MasterIku::whereNotNull('sasaran')->where('sasaran', '!=', '')
            ->orderBy('kode')
            ->get()
            ->filter(fn (MasterIku $iku) => $ikuTerverifikasiIds->contains($iku->id))
            ->groupBy('sasaran');

        // Capaian per IKU dipakai untuk catatan/analisis_capaian per-IKU di isiSatuIku()
        // (TIDAK untuk gerbang verifikasi di atas, lihat $ikuTerverifikasiIds) -- dicari
        // lintas SELURUH bulan triwulan ini (bukan cuma periode_id notula/bulan pertama
        // triwulan) dengan alasan yang SAMA seperti $ikuTerverifikasiIds: Capaian bisa
        // tersimpan di bulan mana pun triwulan ini tergantung kapan Kegiatan diajukan.
        // Diurutkan naik lalu keyBy() supaya baris TERBARU (id terbesar) per IKU yang
        // menang bila kebetulan ada lebih dari satu Capaian untuk IKU yang sama di
        // triwulan ini (satu per bulan yang disentuh).
        $capaianPerIku = Capaian::whereHas('periode', fn ($q) => $q->where('tahun', $periode->tahun)->where('triwulan', $tw))
            ->orderBy('id')
            ->get()
            ->keyBy('iku_id');

        $kegiatanPerIku = Kegiatan::with(['masterIku', 'rincianOutput'])
            ->whereHas('periode', fn ($q) => $q->where('tahun', $periode->tahun)->where('triwulan', $tw))
            ->whereIn('status_dokumen', [Kegiatan::STATUS_DIVERIFIKASI, Kegiatan::STATUS_DISETUJUI])
            ->get()
            ->groupBy('iku_id');

        // Nama kegiatan induk per baris tahapan (Kegiatan::namaKegiatanUtama()) — dipakai
        // fallback nama RO di bawah, supaya koreksi uraian satu tahapan saja tidak
        // memunculkan nama kegiatan yang berbeda untuk kegiatan yang sama.
        $kegiatanPerIku->each(fn ($daftarKegiatan) => Kegiatan::terapkanNamaInduk($daftarKegiatan));

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

        // Indikator Proksi yang ditambahkan Tim SAKIP di Detail Verifikasi (per IKU per
        // triwulan) — hanya baris yang benar-benar terisi yang dicetak di notula.
        $proksiPerIku = IndikatorProksi::where('tahun', $periode->tahun)->where('triwulan', $tw)
            ->orderBy('id')
            ->get()
            ->filter(fn (IndikatorProksi $p) => filled($p->uraian) || filled($p->target) || filled($p->realisasi))
            ->groupBy('iku_id');

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
                // Kolom Realisasi IKU 'rasio' = Realisasi Kumulatif (%) yang SAMA dengan
                // baris di Verifikasi Capaian (tanpa pembulatan; diformat 5 desimal oleh
                // PengaturanCapaian::formatRealisasiKumulatif() saat dicetak). 'realisasi'
                // di atas tetap apa adanya (dipakai penentuan tabel RO).
                'realisasi_kumulatif_persen' => $ct && $iku->pakaiRasio() ? $ct->realisasiKumulatifPresisi($tw) : null,
                'capaian_tw' => $ct?->capaianTriwulanan($tw),
                'capaian_pk' => $ct?->capaianSetahun($tw),
                // Untuk rumus "Dasar Hitung" IKU bersatuan Persen (lihat
                // NotulaBagian1DocxService::formulaBaris()) -- n = KUMULATIF
                // x_realisasi TW I s.d. TW berjalan (sama seperti
                // CapaianTahunan::realisasiKumulatif() & "item kumulatif s.d. TW ini"
                // di Verifikasi Capaian), N = Alokasi Y TW IV (y_alokasi_tw4,
                // konstan sepanjang tahun, SUMBER TUNGGAL sama seperti targetTahunan()
                // -- x_target/y_target lama TIDAK dipakai lagi di sini). Null bila
                // belum ada satu pun x_realisasi yang diisi s.d. TW ini.
                'x_realisasi_kumulatif' => $ct && collect(range(1, $tw))->contains(fn ($n) => $ct->{"x_realisasi_tw{$n}"} !== null)
                    ? collect(range(1, $tw))->sum(fn ($n) => (float) ($ct->{"x_realisasi_tw{$n}"} ?? 0))
                    : null,
                'y_target' => $ct?->y_alokasi_tw4,
            ]];
        });

        // Kendala/Solusi HANYA triwulan notula ini (tidak lagi kumulatif dari TW I) —
        // dikelompokkan per IKU (kerangka resmi menampilkan Kendala/Solusi di bawah tiap
        // indikator), lalu per triwulan supaya bentuk datanya tetap sama bagi pemakai.
        $kendalaSolusiPerIku = KendalaSolusi::with('periode')
            ->whereHas('periode', fn ($q) => $q->where('tahun', $periode->tahun)->where('triwulan', $tw))
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

        // RTL triwulan BERIKUTNYA (Bagian 5 Isian Kegiatan) — disimpan pada periode
        // triwulan target-nya (lihat PengisianKegiatan::targetTriwulanBerikutnya()),
        // jadi TW IV menyambung ke TW I tahun berikutnya. Draft yang belum diajukan
        // Ketua Tim tidak ikut dicetak.
        [$tahunBerikutnya, $twBerikutnya] = (int) $tw === 4 ? [$periode->tahun + 1, 1] : [$periode->tahun, $tw + 1];
        $rtlBerikutnyaPerIku = RtlEvaluasi::whereHas('periode', fn ($q) => $q->where('tahun', $tahunBerikutnya)->where('triwulan', $twBerikutnya))
            ->where('status_dokumen', '!=', RtlEvaluasi::STATUS_DRAFT)
            ->get()
            ->groupBy('iku_id');

        // Bagian kustom (mis. Manajemen Risiko) — SEMUA bagian yang punya poin di
        // triwulan ini ikut ditampilkan, termasuk yang sudah dinonaktifkan Tim SAKIP
        // setelahnya, supaya data historis notula tidak pernah hilang dari catatan.
        // Hanya bagian yang diatur "Tampilkan di notula" (masuk_notula) di Data Master.
        $bagianKustomPerBagian = BagianKustom::query()
            ->where('masuk_notula', true)
            ->whereHas('poin', fn ($q) => $q->whereHas(
                'periode',
                fn ($q2) => $q2->where('tahun', $periode->tahun)->where('triwulan', $tw)
            ))
            ->with(['poin' => function ($q) use ($periode, $tw) {
                $q->with(['masterIku', 'periode'])->whereHas(
                    'periode',
                    fn ($q2) => $q2->where('tahun', $periode->tahun)->where('triwulan', $tw)
                );
            }])
            ->orderBy('urutan')
            ->orderBy('id')
            ->get()
            // Pengaturan notula per bagian (tab Notula di Data Master): bila "hanya isian
            // yang disetujui", poin draf/diajukan/dikembalikan tidak dicetak; bagian tanpa
            // poin tersisa tidak ditampilkan. Satu sumber data untuk notula web, DOCX, PDF.
            ->each(function (BagianKustom $bagian) {
                if ($bagian->alurKerja()['notula'] === \App\Support\AlurBagianKustom::NOTULA_DISETUJUI) {
                    $bagian->setRelation('poin', $bagian->poin->where('status_verifikasi', 'terverifikasi')->values());
                }
            })
            ->filter(fn (BagianKustom $bagian) => $bagian->poin->isNotEmpty())
            ->values();

        // RF baru: link bukti dukung realisasi per IKU (subfolder "Capaian" milik IKU tsb,
        // tempat bukti capaian diunggah -- bukan folder IKU induknya), supaya Tim SAKIP/
        // Kepala bisa langsung buka bukti dari dalam dokumen notula. Dihitung sekali per
        // IKU yang benar-benar punya kegiatan triwulan ini (bukan seluruh Master IKU) —
        // gagal Drive pada satu IKU (mis. storage belum aktif) tidak menggagalkan
        // penyusunan notula.
        $linkFolderPerIku = $kegiatanPerIku->mapWithKeys(function ($daftarKegiatan, $ikuId) use ($periode) {
            return [$ikuId => $this->folder->linkKategoriIku($periode, $daftarKegiatan->first()->masterIku, 'capaian')];
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

        // Tautan folder bukti Evaluasi RTL (Bagian 4 Isian Kegiatan) — bukti diunggah ke
        // folder "Evaluasi-RTL" pada periode poin RTL itu sendiri (= triwulan notula ini,
        // lihat PengisianKegiatan), hanya untuk IKU yang memang punya poin RTL triwulan ini.
        $linkFolderEvaluasiRtlPerIku = $rtlPerIku->mapWithKeys(function ($daftarRtl, $ikuId) {
            $rtl = $daftarRtl->first();

            return [$ikuId => $rtl->masterIku ? $this->folder->linkKategoriIku($rtl->periode, $rtl->masterIku, 'evaluasi_rtl') : null];
        });

        // Ringkasan capaian pada paragraf pembuka (RF-42): rata-rata Capaian Kinerja
        // (Rumus 2.3/2.4) SELURUH CapaianTahunan tahun ini -- BUKAN $rekapPerIku (yang
        // dibatasi ke IKU terverifikasi TRIWULAN INI saja, lihat $ikuTerverifikasiIds di
        // atas) -- lewat rumus RESMI CapaianCalculatorService::rataRataCapaianTriwulanan()/
        // rataRataCapaianSetahun(), PERSIS pola App\Livewire\DasborCapaian::
        // capaianKinerjaPerTriwulan()/capaianSetahunPerTriwulan(), supaya angka di kalimat
        // pembuka SELALU sama dengan kartu "Capaian Kinerja IKU per Triwulan" di Dasbor
        // Capaian -- sama seperti baris 87 AVERAGEIFS (kolom Terhadap Target Triwulanan)
        // dan SUMIF/COUNTIF (kolom Terhadap Target Setahun) pada sheet "LK_Kabkot", Kertas
        // Kerja Pengukuran Kinerja Triwulanan resmi. TIDAK boleh dituker jadi
        // rataRataCapaianTriwulanan() untuk keduanya -- pembagi keduanya SENGAJA beda
        // (rataRataCapaianSetahun() membagi dengan jumlah TOTAL indikator, bukan cuma yang
        // sudah ternilai, lihat docblock-nya).
        $capaianTahunanTahunIni = CapaianTahunan::where('tahun', $periode->tahun)->get();

        $capaianTwList = $capaianTahunanTahunIni
            ->map(fn (CapaianTahunan $ct) => $ct->capaianTriwulanan($tw) ?? CapaianCalculatorService::TIDAK_DINILAI)
            ->all();
        $capaianPkList = $capaianTahunanTahunIni
            ->map(fn (CapaianTahunan $ct) => $ct->capaianSetahun($tw) ?? CapaianCalculatorService::TIDAK_DINILAI)
            ->all();

        $rataCapaianTw = $this->bulatkanAtauNull(CapaianCalculatorService::rataRataCapaianTriwulanan($capaianTwList));
        $rataCapaianPk = $this->bulatkanAtauNull(CapaianCalculatorService::rataRataCapaianSetahun($capaianPkList));

        return [
            'notula' => $notula,
            'periode' => $periode,
            'labelTriwulan' => ['I', 'II', 'III', 'IV'][$tw - 1] ?? $tw,
            'sasaranPerIku' => $sasaranPerIku,
            'rekapPerIku' => $rekapPerIku,
            'capaianPerIku' => $capaianPerIku,
            'kegiatanPerIku' => $kegiatanPerIku,
            'roPerIku' => $roPerIku,
            'proksiPerIku' => $proksiPerIku,
            'kendalaSolusiPerIku' => $kendalaSolusiPerIku,
            'rtlPerIku' => $rtlPerIku,
            'rtlSebelumnyaPerIku' => $rtlSebelumnyaPerIku,
            'rtlBerikutnyaPerIku' => $rtlBerikutnyaPerIku,
            'tahunTwBerikutnya' => $tahunBerikutnya,
            'twBerikutnya' => $twBerikutnya,
            'bagianKustomPerBagian' => $bagianKustomPerBagian,
            'linkFolderPerIku' => $linkFolderPerIku,
            'linkFolderTwSebelumnyaPerIku' => $linkFolderTwSebelumnyaPerIku,
            'linkFolderEvaluasiRtlPerIku' => $linkFolderEvaluasiRtlPerIku,
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
     * Sisipkan Bagian II/III LANGSUNG di posisi penanda {{bagian_2}}/{{bagian_3}} di
     * dalam $bagian1Html -- lihat NotulaBagian1DocxService::isiPenutup(), posisi ASLI
     * kedua penanda ini di template docx SELALU sebelum tabel "Mengetahui/Kepala
     * Satker/Notulis" (penanda hadir/tidaknya rapat, BUKAN blok TTD persetujuan akhir
     * di renderNotulaUtuhHtml()) -- BUKAN ditempel di akhir dokumen seperti
     * sebelumnya, supaya isi Bagian II/III tidak jatuh ke BAWAH tabel tsb.
     *
     * Judul "BAGIAN II/III — ..." TIDAK ditambahkan lagi di sini -- berkas .docx yang
     * diunggah Tim SAKIP (lihat template_notula/SIPINTER_Template_Bagian_II_Prioritas.docx
     * & _Bagian_III_Anggaran.docx) SUDAH memuat judulnya sendiri sebagai bagian dari
     * konten yang dikonversi, jadi menambahkannya lagi di sini cuma bikin dobel.
     *
     * Fallback: bila $bagian1Html TIDAK memuat penanda (mis. template lama/kustom
     * tanpa {{bagian_2}}/{{bagian_3}}), Bagian II/III tetap ditempel di akhir
     * (perilaku lama) alih-alih hilang begitu saja.
     *
     * Paragraf yang memuat penanda diganti UTUH (bukan cuma teks penandanya) --
     * penandanya ada di dalam <p><font><i>, dan isi Bagian II/III (berisi <div>/
     * tabel) yang ditaruh di dalamnya akan dipecah browser keluar dari <p> sambil
     * membawa format miringnya ke seluruh isi Bagian II/III.
     *
     * $untukEditor: isi sisipan dibuat tidak bisa disunting dan diapit komentar
     * penanda, supaya saat pratinjau disimpan isi Bagian II/III bisa dikembalikan
     * jadi penanda {{bagian_2}}/{{bagian_3}} (kembalikanPenandaBagianDuaTiga()) dan
     * tidak ikut terbakar permanen ke dalam bagian1_html.
     */
    public function sisipkanBagianDuaTiga(string $bagian1Html, ?string $bagian2Html, ?string $bagian3Html, bool $untukEditor = false): string
    {
        $hasil = $bagian1Html;

        foreach (['bagian_2' => trim((string) $bagian2Html), 'bagian_3' => trim((string) $bagian3Html)] as $nama => $isi) {
            if ($untukEditor && $isi !== '') {
                $isi = "<!--sipinter:{$nama}--><div data-sisipan=\"{$nama}\" contenteditable=\"false\">{$isi}</div><!--/sipinter:{$nama}-->";
            }

            $penanda = '{{'.$nama.'}}';
            if (! str_contains($hasil, $penanda)) {
                $hasil .= $isi;

                continue;
            }

            $pola = '/<p\b[^>]*>(?:(?!<\/p>).)*?'.preg_quote($penanda, '/').'(?:(?!<\/p>).)*?<\/p>/s';
            $diganti = preg_replace_callback($pola, fn () => $isi, $hasil, 1, $jumlah);
            $hasil = $jumlah > 0 && $diganti !== null ? $diganti : str_replace($penanda, $isi, $hasil);
        }

        return $hasil;
    }

    /**
     * Kebalikan sisipkanBagianDuaTiga(untukEditor: true) untuk HTML yang dikirim
     * balik dari editor pratinjau: isi Bagian II/III diganti lagi jadi paragraf
     * penanda {{bagian_2}}/{{bagian_3}}.
     */
    public function kembalikanPenandaBagianDuaTiga(string $html): string
    {
        return preg_replace('/<!--sipinter:(bagian_[23])-->.*?<!--\/sipinter:\1-->/s', '<p>{{$1}}</p>', $html) ?? $html;
    }

    /**
     * @return array<string, mixed>
     */
    private function dataNotulaUtuh(Notula $notula, bool $sertakanTtd): array
    {
        $periode = $notula->periode;
        $halaman = $this->docx->tataHalamanTemplate();

        // HtmlNotulaDompdf hanya menyesuaikan salinan untuk dompdf (baris tabel
        // panjang dipecah supaya tidak terpotong, font dipetakan) -- bagian*_html
        // tersimpan & pratinjau WYSIWYG tidak berubah.
        $isi = $this->sisipkanBagianDuaTiga($notula->bagian1_html ?? '', $notula->bagian2_html, $notula->bagian3_html);

        return [
            'bagian1Html' => HtmlNotulaDompdf::siapkan($isi, $halaman),
            'halaman' => $halaman,
            'labelTriwulan' => ['I', 'II', 'III', 'IV'][$periode->triwulan - 1] ?? $periode->triwulan,
            'tahun' => $periode->tahun,
            'sertakanTtd' => $sertakanTtd,
            'kotaTtd' => $notula->kota_ttd,
            // Tanggal TTD (tanggal SAJA, tanpa nama hari -- lihat Notula::tanggalTtd())
            // mengikuti hari_tanggal RAPAT yang sudah diisi Tim SAKIP di Detail Rapat --
            // BUKAN tanggal klik "Setuju" di sistem (Notula::disetujui_pada), yang bisa
            // berbeda dari tanggal rapatnya sendiri. Konsisten dengan macro {{tanggal}}
            // di jalur .docx Bagian I, lihat NotulaBagian1DocxService::isiPenutup().
            'tanggal' => $sertakanTtd ? $notula->tanggalTtd() : null,
            'namaKepala' => $sertakanTtd ? $notula->disetujuiOleh?->nama : null,
            'namaNotulis' => $notula->notulis,
        ];
    }

    /**
     * Render ke berkas sementara di folder tujuan, validasi (tidak kosong, berkepala
     * %PDF-), lalu pasang lewat PenggantiBerkasAman: berkas lama di $outputPath (mis.
     * draf versi yang sama dari penggabungan sebelumnya) baru benar-benar diganti
     * setelah berkas baru terpasang DAN $lanjutan (pembaruan DB) berhasil -- bila
     * $lanjutan gagal, berkas lama dipulihkan sehingga PDF di disk selalu cocok dengan
     * status/kolom di DB.
     *
     * Kegagalan dompdf (Dompdf\Exception BUKAN turunan RuntimeException) dibungkus jadi
     * RuntimeException "PDF notula gagal dibuat: ..." supaya ditangkap & ditampilkan
     * pemanggil (KompilasiNotula, PersetujuanNotula). Exception dari $lanjutan
     * diteruskan apa adanya.
     */
    private function renderNotulaUtuhPdf(Notula $notula, bool $sertakanTtd, string $outputPath, ?callable $lanjutan = null, array $opsi = []): string
    {
        $sementara = $outputPath.'.'.Str::random(8).'.tmp';

        try {
            $this->berkas->pastikanFolder(dirname($outputPath));

            if (! $this->renderPdfDariDocx($notula, $sertakanTtd, $sementara)) {
                $data = $this->dataNotulaUtuh($notula, $sertakanTtd);
                PdfFacade::loadView('pdf.notula-utuh', $data)
                    ->setPaper([0, 0, $data['halaman']['lebar'], $data['halaman']['tinggi']])
                    ->save($sementara);
            }

            $kepala = is_file($sementara) ? (string) @file_get_contents($sementara, false, null, 0, 5) : '';
            if ($kepala !== '%PDF-') {
                throw new RuntimeException('hasil render bukan PDF yang valid.');
            }
        } catch (\Throwable $e) {
            if (file_exists($sementara) && ! @unlink($sementara)) {
                Log::warning('Berkas sementara render PDF tidak bisa dihapus: '.basename($sementara), ['path' => $sementara]);
            }
            Log::error('Gagal membuat PDF notula: '.$e->getMessage(), ['notula_id' => $notula->id]);

            throw new RuntimeException('PDF notula gagal dibuat: '.$e->getMessage(), 0, $e);
        }

        $this->berkas->pasang($sementara, $outputPath, $lanjutan, $opsi + self::OPSI_PDF);

        return $outputPath;
    }

    /**
     * PDF notula dari .docx lengkap (renderNotulaLengkapDocx()) lewat LibreOffice --
     * isinya SAMA dengan .docx unduhan: tabel Bagian I tidak terpotong seperti di
     * dompdf, dan Bagian II/III tampil persis seperti berkas yang diunggah. False bila
     * gagal (mis. LibreOffice tidak tersedia), supaya pemanggil jatuh ke dompdf.
     */
    private function renderPdfDariDocx(Notula $notula, bool $sertakanTtd, string $tujuan): bool
    {
        if (! config('services.libreoffice.pdf_notula')) {
            return false;
        }

        $dir = dirname($tujuan).'/pdf-docx-'.Str::lower(Str::random(10));
        $docxPath = $dir.'/notula.docx';

        try {
            $this->pastikanFolder($dir);
            $this->renderNotulaLengkapDocx($notula, $docxPath, $sertakanTtd);
            $pdf = $this->konversi->convertToPdf($docxPath, $dir);

            if (! @rename($pdf, $tujuan)) {
                throw new RuntimeException('PDF hasil konversi gagal dipindahkan.');
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('PDF notula dari .docx gagal, memakai dompdf: '.$e->getMessage(), ['notula_id' => $notula->id]);
            if (file_exists($tujuan)) {
                @unlink($tujuan);
            }

            return false;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** Validasi format PDF hasil render/konversi sebelum menggantikan berkas lama. */
    private const OPSI_PDF = ['awalan' => '%PDF-', 'akhiran_memuat' => '%%EOF'];

    /** Kolom notula berisi teks panjang -- dicatat di jurnal sebagai hash, bukan isinya. */
    private const KOLOM_HASH = ['bagian1_html', 'bagian2_html', 'bagian3_html'];

    /**
     * Kondisi DB sebelum & sesudah lanjutan untuk jurnal PenggantiBerkasAman -- dipakai
     * perintah notula:penggantian-berkas untuk membuktikan apakah transaksi sempat
     * ter-commit bila proses mati di tengah jalan (lihat PemulihPenggantianBerkas).
     */
    private function verifikasiNotula(Notula $notula, array $sesudah): array
    {
        $sebelum = [];
        foreach (array_keys($sesudah) as $kolom) {
            $nilai = $notula->getRawOriginal($kolom);
            $sebelum[$kolom] = in_array($kolom, self::KOLOM_HASH, true) && $nilai !== null ? 'sha256:'.hash('sha256', (string) $nilai) : $nilai;
        }

        return ['model' => Notula::class, 'id' => $notula->getKey(), 'sebelum' => $sebelum, 'sesudah' => $sesudah];
    }

    /**
     * RF-42a/42b: terima unggahan Bagian II atau III (.docx), lalu simpan DUA bentuk:
     * - bagian{2,3}_pdf: versi PDF (LibreOffice headless), dipakai HANYA untuk
     *   pratinjau iframe di layar Kompilasi Notula.
     * - bagian{2,3}_html: konten HTML inline (reflow), dipakai jalur render notula
     *   menyatu (lihat gabungkan()/setujui()) supaya bisa menyambung antar bagian.
     *
     * Mengganti berkas yang sudah ada sebelumnya (RF-42e) otomatis membatalkan
     * hasil gabungan lama — dan bila notula-nya sudah terlanjur disetujui Kepala,
     * membukanya kembali sebagai VERSI BARU (lihat Notula::tandaiPerluDigabungUlang()).
     * $pelaku hanya dipakai untuk mencatat siapa yang memicu pembukaan versi itu.
     */
    public function terimaUploadBagian(Notula $notula, int $bagianKe, UploadedFile $file, ?User $pelaku = null): void
    {
        if (! in_array($bagianKe, [2, 3], true)) {
            throw new RuntimeException('Bagian harus 2 atau 3.');
        }

        $pathAsli = $file->store('notula-sementara', 'local');
        if (! is_string($pathAsli) || $pathAsli === '') {
            throw new RuntimeException('Berkas unggahan gagal disimpan sementara di server. Periksa izin tulis folder storage.');
        }
        $fullPathAsli = Storage::disk('local')->path($pathAsli);
        $dirKerja = dirname($fullPathAsli);
        $fullPathPdf = null;
        $pdfSementaraTujuan = null;

        $relatifTujuanPdf = "notula/{$notula->id}/bagian{$bagianKe}.pdf";
        $fullPathTujuanPdf = Storage::disk('local')->path($relatifTujuanPdf);
        $kolomPdf = $bagianKe === 2 ? 'bagian2_pdf' : 'bagian3_pdf';
        $kolomHtml = $bagianKe === 2 ? 'bagian2_html' : 'bagian3_html';
        $kolomDocx = $bagianKe === 2 ? 'bagian2_docx' : 'bagian3_docx';
        $romawi = $bagianKe === 2 ? 'II' : 'III';

        // Urutan: konversi PDF -> salin ke berkas sementara di folder tujuan (WAJIB
        // sebelum convertToHtml(), yang membersihkan semua berkas sebasename di
        // $dirKerja termasuk PDF ini) -> konversi HTML -> pasang PDF lewat
        // PenggantiBerkasAman dengan pembaruan DB sebagai lanjutannya. Jadi bagian{2,3}.pdf
        // lama baru benar-benar diganti bila DB juga berhasil diperbarui -- PDF baru tidak
        // pernah berpasangan dengan HTML lama (atau sebaliknya). Berkas sementara selalu
        // dibersihkan (finally; sumber yang sudah terpasang sudah tidak ada lagi).
        try {
            // .docx ASLI disimpan juga -- dipakai notula lengkap (.docx/PDF) supaya isi
            // unggahan tersisip apa adanya (lihat renderNotulaLengkapDocx()). Tidak
            // terbaca/kosong: null, notula lengkap memakai HTML hasil konversinya.
            $docxAsli = @file_get_contents($fullPathAsli);
            $docxAsli = $docxAsli !== false && $docxAsli !== '' ? base64_encode($docxAsli) : null;

            $fullPathPdf = $this->konversi->convertToPdf($fullPathAsli, $dirKerja);

            $this->berkas->pastikanFolder(dirname($fullPathTujuanPdf));
            $pdfSementaraTujuan = $fullPathTujuanPdf.'.'.Str::random(8).'.tmp';
            if (! @copy($fullPathPdf, $pdfSementaraTujuan) || filesize($pdfSementaraTujuan) !== filesize($fullPathPdf)) {
                throw new RuntimeException("PDF Bagian {$romawi} gagal disalin ke folder penyimpanan (izin ditolak atau disk penuh) -- berkas lama tidak diubah.");
            }

            $kontenInline = $this->konversi->convertToHtml($fullPathAsli, $dirKerja);

            $sumber = $pdfSementaraTujuan;
            $pdfSementaraTujuan = null; // sejak sini tanggung jawab PenggantiBerkasAman

            $this->berkas->pasang($sumber, $fullPathTujuanPdf, function () use ($notula, $kolomPdf, $kolomHtml, $kolomDocx, $docxAsli, $relatifTujuanPdf, $kontenInline, $pelaku, $romawi) {
                DB::transaction(function () use ($notula, $kolomPdf, $kolomHtml, $kolomDocx, $docxAsli, $relatifTujuanPdf, $kontenInline, $pelaku, $romawi) {
                    $notula->update([$kolomPdf => $relatifTujuanPdf, $kolomHtml => $kontenInline, $kolomDocx => $docxAsli]);
                    $notula->tandaiPerluDigabungUlang($pelaku, "Berkas Bagian {$romawi} diganti setelah notula disetujui — notula dibuka kembali sebagai versi baru dan perlu digabung serta disetujui ulang.");
                });
            }, self::OPSI_PDF + [
                'label' => "unggah Bagian {$romawi} notula #{$notula->id}",
                'verifikasi' => $this->verifikasiNotula($notula, [$kolomPdf => $relatifTujuanPdf, $kolomHtml => 'sha256:'.hash('sha256', $kontenInline)]),
            ]);
        } catch (\Throwable $e) {
            // Atribut model yang terlanjur diubah di memori (transaksi di-rollback)
            // dikembalikan ke isi DB supaya pemanggil tidak memakai data setengah jadi.
            if ($notula->exists) {
                $notula->refresh();
            }

            throw $e;
        } finally {
            foreach ([$fullPathPdf, $pdfSementaraTujuan, $fullPathAsli] as $sisa) {
                if ($sisa !== null && file_exists($sisa) && ! @unlink($sisa)) {
                    Log::warning('Berkas sementara unggahan notula tidak bisa dihapus: '.basename($sisa), ['path' => $sisa]);
                }
            }
        }
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
     * Render ULANG pratinjau PDF Bagian II/III langsung dari bagian{2,3}_html
     * tersimpan — dipakai NotulaDownloadController sebagai fallback bila berkas
     * bagian{2,3}_pdf hasil konversi LibreOffice saat unggah (terimaUploadBagian())
     * sudah tidak ada lagi di disk (mis. storage sempat dibersihkan/direset) walau
     * kontennya sendiri masih utuh di DB — supaya pratinjau tetap bisa dibuka tanpa
     * memaksa Tim SAKIP mengunggah ulang berkas yang isinya sebenarnya masih valid.
     * TIDAK disimpan balik ke bagian{2,3}_pdf (sekali-pakai, sama seperti
     * renderPratinjauPdf() di atas) — biar tetap konsisten dengan sumber aslinya
     * (bagian{2,3}_html) tiap kali diminta, bukan cache yang bisa basi lagi.
     */
    public function renderPratinjauBagianPdf(Notula $notula, int $bagianKe): string
    {
        $html = $bagianKe === 2 ? $notula->bagian2_html : $notula->bagian3_html;
        $halaman = $this->docx->tataHalamanTemplate();

        return PdfFacade::loadHTML(HtmlNotulaDompdf::siapkan($html ?? '', $halaman))
            ->setPaper([0, 0, $halaman['lebar'], $halaman['tinggi']])
            ->output();
    }

    /**
     * RF-42d: render Bagian I + II + III sebagai SATU dokumen mengalir (TANPA TTD)
     * jadi PDF draf — lihat renderNotulaUtuhPdf(), menggantikan penggabungan PDF
     * halaman-demi-halaman yang dipakai sebelumnya. Notula otomatis dianggap
     * terkirim ke Kepala begitu berhasil digabungkan — mockup tidak menyediakan
     * tombol "kirim" terpisah, jadi "sudah digabung" DAN "menunggu persetujuan"
     * adalah hal yang sama.
     *
     * Nama berkasnya bernomor versi (lihat Notula::namaUnduhan()) supaya hasil
     * gabungan versi baru tidak menimpa berkas versi sebelumnya yang sudah beredar.
     */
    public function gabungkan(Notula $notula, ?User $user = null): void
    {
        if (! $notula->bagianLengkap()) {
            throw new RuntimeException('Bagian I, II, dan III harus lengkap terlebih dahulu sebelum digabungkan.');
        }

        $namaBerkas = $notula->namaUnduhan('draf');
        $dir = storage_path("app/private/notula/{$notula->id}");
        $gabunganPath = $dir.'/'.$namaBerkas;

        // Penggabungan ulang versi yang SAMA menimpa berkas draf bernama sama -- DB
        // diperbarui sebagai lanjutan pemasangan berkas (PenggantiBerkasAman), jadi bila
        // pembaruan status gagal, berkas draf lama (yang mungkin sedang ditinjau Kepala)
        // dipulihkan, tidak diam-diam berisi data yang belum terkirim.
        $akanDikirim = in_array($notula->status, [Notula::STATUS_DRAFT, Notula::STATUS_DIKEMBALIKAN], true);
        $verifikasi = $this->verifikasiNotula($notula, [
            'pdf_gabungan' => "notula/{$notula->id}/{$namaBerkas}",
            'status' => $akanDikirim ? Notula::STATUS_MENUNGGU_PERSETUJUAN : $notula->getRawOriginal('status'),
        ]);

        try {
            $this->renderNotulaUtuhPdf($notula, sertakanTtd: false, outputPath: $gabunganPath, lanjutan: function () use ($notula, $namaBerkas, $user) {
                DB::transaction(function () use ($notula, $namaBerkas, $user) {
                    $notula->update(['pdf_gabungan' => "notula/{$notula->id}/{$namaBerkas}"]);

                    if (in_array($notula->status, [Notula::STATUS_DRAFT, Notula::STATUS_DIKEMBALIKAN], true)) {
                        $notula->kirimKePersetujuan($user);
                    }
                });
            }, opsi: ['label' => "gabungkan notula #{$notula->id}", 'verifikasi' => $verifikasi]);
        } catch (\Throwable $e) {
            $notula->refresh();

            throw $e;
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
        // Nama berkas final bernomor versi (lihat Notula::namaUnduhan()) — versi lama
        // yang pernah ditandatangani Kepala TETAP utuh di disk maupun di arsip Drive,
        // tidak ditimpa oleh versi berikutnya.
        $namaBerkas = $notula->namaUnduhan('final');
        $dir = storage_path("app/private/notula/{$notula->id}");
        $gabunganFinalPath = $dir.'/'.$namaBerkas;
        $relatifFinal = "notula/{$notula->id}/{$namaBerkas}";

        if (! $notula->canTransitionTo(Notula::STATUS_DISETUJUI)) {
            throw new InvalidStatusTransitionException($notula->status, Notula::STATUS_DISETUJUI);
        }

        // PDF final dirender DULU, SEBELUM status apa pun diubah -- bila render gagal,
        // notula tetap "menunggu persetujuan" dan tidak ada notifikasi status yang
        // terlanjur terkirim (antrean "sync" di produksi mengirim email langsung saat
        // status Capaian berubah). Sebelumnya notula bisa tercatat disetujui tanpa PDF
        // final sama sekali. Nama Kepala untuk blok TTD dipasang di memori saja
        // (setRelation), baru disimpan lewat Notula::setujui() di lanjutan.
        //
        // Transaksi persetujuan dijalankan sebagai lanjutan pemasangan berkas
        // (PenggantiBerkasAman): bila gagal, berkas di path final dikembalikan ke isi
        // sebelumnya (atau dihapus bila memang belum ada) -- BUKAN dihapus begitu saja.
        $notula->setRelation('disetujuiOleh', $kepala);
        $verifikasi = $this->verifikasiNotula($notula, ['status' => Notula::STATUS_DISETUJUI, 'pdf_final' => $relatifFinal]);

        try {
            $this->renderNotulaUtuhPdf($notula, sertakanTtd: true, outputPath: $gabunganFinalPath, lanjutan: function () use ($notula, $kepala, $relatifFinal) {
                DB::transaction(function () use ($notula, $kepala, $relatifFinal) {
                    $notula->setujui($kepala);
                    $this->setujuiKegiatanTriwulan($notula->periode, $kepala);
                    $notula->update(['pdf_final' => $relatifFinal]);
                });
            }, opsi: ['label' => "setujui notula #{$notula->id}", 'verifikasi' => $verifikasi]);
        } catch (\Throwable $e) {
            $notula->unsetRelation('disetujuiOleh');
            $notula->refresh();

            throw $e;
        }

        try {
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
    public function kembalikan(Notula $notula, string $catatan, ?User $user = null): void
    {
        $notula->kembalikan($catatan, $user);
    }

    /**
     * Kepala mengembalikan SATU isian IKU+periode langsung ke Ketua Tim, tanpa menunggu Tim
     * SAKIP meneruskannya secara manual (skip satu hop) — dipakai dari halaman Persetujuan
     * Notula, terpisah dari kembalikan() di atas yang mengembalikan SELURUH dokumen notula.
     *
     * Tim SAKIP tetap "tahu" TANPA kode notifikasi baru: catatStatus() di bawah menembak
     * event CapaianStatusDiubah (listener KirimPengingatStatusCapaian mengirim email ke
     * penanggung jawab IKU/Ketua Tim) dan, karena Notula ikut ditarik ke "dikembalikan",
     * Notula::kembalikan() menembak NotulaStatusDiubah (listener KirimPengingatStatusNotula
     * mengirim email ke Tim SAKIP) — keduanya listener yang sudah ada sebelumnya.
     *
     * Kegiatan yang sudah "diverifikasi" di bawah Capaian ini ikut ditarik ke "dikembalikan"
     * (bukan per-berkas seperti VerifikasiCapaian::kembalikanKeKetuaTim(), karena Kepala
     * meninjau di level IKU, bukan sampai ke rincian berkas/kendala-solusi). Kegiatan yang
     * sudah "disetujui" (notula versi sebelumnya sudah final) SENGAJA dibiarkan terkunci —
     * sama persis dengan VerifikasiCapaian::bukaKembali(): yang dibuka cuma Capaian-nya,
     * supaya Ketua Tim bisa memperbaiki/menambah isian baru tanpa mengubah catatan kegiatan
     * yang sudah ikut ditandatangani di versi lama.
     *
     * Dampaknya ke notula triwulan yang memuat isian ini:
     * - menunggu persetujuan -> notula ikut "dikembalikan" ke Tim SAKIP (alur normal).
     * - SUDAH disetujui       -> notula dibuka lagi dari awal sebagai VERSI BARU (draft,
     *   lihat Notula::bukaVersiBaru()), karena isi dokumen ber-TTD itu sekarang berubah;
     *   Tim SAKIP menyusun & mengirim ulang, lalu Kepala membubuhkan persetujuan lagi.
     */
    public function kembalikanIsian(Capaian $capaian, User $kepala, string $catatan): void
    {
        if (! $capaian->bisaDikembalikanOlehKepala()) {
            throw new InvalidStatusTransitionException($capaian->status, Capaian::STATUS_DIKEMBALIKAN);
        }

        $capaian->loadMissing(['periode', 'masterIku']);

        DB::transaction(function () use ($capaian, $kepala, $catatan) {
            foreach ($capaian->kegiatanList()->where('status_dokumen', Kegiatan::STATUS_DIVERIFIKASI)->get() as $kegiatan) {
                $kegiatan->kembalikan();
            }

            $capaian->catatStatus(Capaian::STATUS_DIKEMBALIKAN, $kepala, $catatan);

            $notula = $this->untukTriwulan($capaian->periode->tahun, $capaian->periode->triwulan);
            $indikator = $capaian->masterIku->indikator ?? "IKU #{$capaian->iku_id}";
            $pesan = "Isian IKU \"{$indikator}\" dikembalikan langsung ke Ketua Tim oleh Kepala. Catatan: {$catatan}";

            if ($notula->status === Notula::STATUS_MENUNGGU_PERSETUJUAN) {
                $notula->kembalikan($pesan, $kepala);
            } else {
                $notula->bukaVersiBaru($kepala, $pesan.' Notula dibuka kembali sebagai versi '.($notula->versiSaatIni() + 1).' dan perlu digabung serta disetujui ulang.');
            }
        });
    }

    /**
     * Tim SAKIP merevisi isian IKU yang SUDAH termuat di notula (Capaian "diverifikasi"/
     * "disetujui", lihat kumpulkanDataBagianSatu()) langsung dari halaman Verifikasi —
     * tanpa harus mengembalikannya dulu ke Ketua Tim. Supaya revisi itu ikut masuk ke
     * notula: Bagian I yang sudah pernah disusun dibangun ulang dari data terkini (isi
     * lamanya dicadangkan ke bagian1_html_cadangan, sama seperti "Susun Ulang Otomatis"
     * di Kompilasi Notula), lalu notula ditandai perlu digabung ulang — notula yang sudah
     * disetujui dibuka sebagai versi baru (lihat Notula::tandaiPerluDigabungUlang()).
     *
     * Bila penyusunan ulang gagal (mis. LibreOffice tidak tersedia), notula tetap
     * ditandai perlu digabung ulang dan Tim SAKIP diminta menekan "Susun Ulang Otomatis"
     * sendiri. Mengembalikan pesan ringkas untuk ditampilkan, atau null bila IKU ini
     * belum termuat di notula mana pun (tidak ada yang perlu diperbarui).
     */
    public function perbaruiSetelahRevisiIku(Capaian $capaian, ?User $user = null): ?string
    {
        if (! in_array($capaian->status, [Capaian::STATUS_DIVERIFIKASI, Capaian::STATUS_DISETUJUI], true)) {
            return null;
        }

        $capaian->loadMissing(['periode', 'masterIku']);
        $notula = $this->untukTriwulan($capaian->periode->tahun, $capaian->periode->triwulan);

        // Bagian I belum pernah disusun -> akan tersusun otomatis dari data terkini
        // begitu Kompilasi Notula dibuka (KompilasiNotula::muatBagian1EditText()).
        if (blank($notula->bagian1_html)) {
            return null;
        }

        $indikator = $capaian->masterIku->indikator ?? "IKU #{$capaian->iku_id}";
        $disusunUlang = true;

        try {
            $notula->update(['bagian1_html_cadangan' => $notula->bagian1_html]);
            $this->susunBagianSatu($notula);
        } catch (\Throwable $e) {
            Log::warning('Gagal menyusun ulang Bagian I notula setelah revisi IKU: '.$e->getMessage());
            $disusunUlang = false;
        }

        $notula->refresh();
        $sudahDisetujui = $notula->status === Notula::STATUS_DISETUJUI;
        $notula->tandaiPerluDigabungUlang($user, "Isian IKU \"{$indikator}\" direvisi Tim SAKIP setelah notula disetujui — notula dibuka kembali sebagai versi baru dan perlu digabung serta disetujui ulang.");

        $pesan = $disusunUlang
            ? 'Bagian I notula ikut diperbarui dari revisi ini (suntingan sebelumnya dicadangkan).'
            : 'Bagian I notula BELUM berhasil diperbarui otomatis — buka Kompilasi Notula lalu klik "Susun Ulang Otomatis".';

        return $pesan.($sudahDisetujui
            ? ' Notula yang sudah disetujui dibuka kembali sebagai versi '.$notula->versiSaatIni().' — gabungkan lalu kirim ulang ke Kepala.'
            : ' Gabungkan ulang notula sebelum dikirim ke Kepala.');
    }

    /**
     * Ratakan hasil CapaianCalculatorService::rataRataCapaianTriwulanan()/
     * rataRataCapaianSetahun() (float|string) ke float|null untuk paragraf pembuka --
     * TIDAK_DINILAI ("-", seluruh IKU belum ternilai triwulan ini) jadi null, konsisten
     * dengan makna null di tempat lain (lihat Capaian::hitungPersentase()).
     */
    private function bulatkanAtauNull(float|string $hasil): ?float
    {
        return $hasil === CapaianCalculatorService::TIDAK_DINILAI ? null : round((float) $hasil, 2);
    }

    protected function pastikanFolder(string $dir): void
    {
        $this->berkas->pastikanFolder($dir);
    }
}
