<?php

namespace App\Services;

use App\Models\FolderConfig;
use App\Models\Kegiatan;
use App\Models\MasterIku;
use App\Models\Notula;
use App\Models\PengaturanCapaian;
use App\Models\Periode;
use App\Models\RincianN;
use App\Support\RumusMarkup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\TemplateProcessor;
use ReflectionProperty;
use RuntimeException;
use Throwable;

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
 * {{blok_ro}} ditampilkan sesuai realisasi ({{blok_berakhlak}} usang, hanya diproses bila template
 * lama masih memuatnya), dan {{blok_proksi}}
 * (baris tabel proksi digandakan per App\Models\IndikatorProksi) untuk IKU mana pun yang punya
 * proksi isian Tim SAKIP (lihat isiSatuIku() di bawah).
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
     * Rumus (baris $formulaDicetak, lihat isiSatuIku()) yang disisipkan sebagai
     * OOXML Math di generate() TERAKHIR, urut PERSIS seperti kemunculannya di
     * dokumen -- dipakai NotulaService::susunBagianSatu() untuk mengganti penanda
     * rumus (LibreOffice membuang OOXML Math saat ekspor HTML, lihat
     * NotulaService::gantiPenandaRumusDenganHtml()) dengan HTML bersusun yang SAMA
     * persis dipakai jalur PDF langsung (RumusMarkup::keHtml()), tanpa
     * bergantung ke LibreOffice sama sekali untuk bagian ini. Di-reset di awal
     * tiap generate() supaya pemanggilan berikutnya tidak membawa sisa data lama.
     *
     * @var list<string>
     */
    private array $formulaUrutan = [];

    /**
     * true: docx dibuat untuk dikonversi jadi HTML pratinjau -- rumus ditulis sebagai
     * teks penanda (penandaRumus(), lihat setFormula()) dan tiap bagian dokumen diawali
     * paragraf penanda blok (penandaBlok(), lihat isiPerIkuDinamis()). Lihat parameter
     * $untukPratinjauHtml di generate().
     */
    private bool $untukPratinjauHtml = false;

    /**
     * Teks penanda rumus ke-$i -- TANPA spasi supaya tidak dipatahkan LibreOffice
     * saat membungkus baris HTML hasil ekspornya.
     */
    public static function penandaRumus(int $i): string
    {
        return "SIPINTERRUMUS{$i}X";
    }

    /**
     * Teks penanda awal blok $kunci (mis. "iku-12", "kustom", "ekor") -- diubah jadi
     * <div data-blok="..."> oleh App\Support\BlokBagianSatu::bungkus() supaya
     * "Susun Ulang Otomatis" bisa memperbarui blok yang belum disunting saja.
     */
    public static function penandaBlok(string $kunci): string
    {
        return 'SIPINTERBLOK_'.$kunci;
    }

    private function paragrafPenandaBlok(string $kunci): string
    {
        return $this->untukPratinjauHtml
            ? '<w:p><w:r><w:t>'.self::penandaBlok($kunci).'</w:t></w:r></w:p>'
            : '';
    }

    /**
     * $data WAJIB hasil NotulaService::kumpulkanDataBagianSatu($notula) milik
     * pemanggil sendiri -- diterima sebagai parameter (bukan resolve NotulaService
     * lewat constructor) supaya kelas ini TIDAK bergantung balik ke NotulaService,
     * yang sejak susunBagianSatu() memakai kelas ini untuk menghasilkan HTML Bagian I
     * (lihat NotulaService) sudah jadi dependensi searah lainnya -- dependensi dua
     * arah di antara keduanya akan bikin container Laravel gagal resolve (circular).
     *
     * $sertakanBlokTtdMandiri mengatur tabel "Mengetahui/Kepala.../Notulis" bawaan
     * template (setelah {{bagian_3}}, lihat hapusBlokTtdMandiri()) -- TETAP boleh
     * disertakan (default true) untuk unduhan Bagian I .docx tersendiri
     * (NotulaDownloadController::unduhBagian1Docx()) karena dokumen itu berdiri
     * sendiri dan butuh tempat TTD-nya sendiri. DIBUANG (false) khusus saat dipanggil
     * dari NotulaService::susunBagianSatu() -- hasil HTML-nya disisipkan ke tengah
     * dokumen gabungan Bagian I+II+III, yang SUDAH punya blok TTD akhirnya sendiri di
     * paling bawah (lihat resources/views/pdf/notula-utuh.blade.php, .ttd-blok) --
     * tanpa ini, blok "Mengetahui/Kepala.../Notulis" muncul DUA KALI di dokumen
     * gabungan/final (sekali dari sini di tengah, sekali lagi di akhir).
     *
     * TERLEPAS dari $sertakanBlokTtdMandiri, tabelnya JUGA selalu dibuang bila notula
     * belum berstatus disetujui (RF-44) -- lihat catatan hapusBlokTtdMandiri().
     *
     * $untukPratinjauHtml HANYA untuk jalur pratinjau HTML (susunBagianSatu()) --
     * lihat $this->untukPratinjauHtml. Unduhan .docx tetap memakai OOXML Math
     * sungguhan dan tidak memuat penanda apa pun.
     */
    public function generate(Notula $notula, array $data, string $outputPath, bool $sertakanBlokTtdMandiri = true, bool $untukPratinjauHtml = false): string
    {
        $this->formulaUrutan = [];
        $this->untukPratinjauHtml = $untukPratinjauHtml;

        $templatePath = $this->resolveTemplatePath();

        $processor = $this->newTemplateProcessor($templatePath);
        // Template ini pakai delimiter {{...}} (bukan ${...} bawaan PhpWord) supaya
        // sesuai konvensi yang diminta -- lihat TemplateProcessor::setMacroChars().
        // Dikembalikan ke default di akhir generate() karena properti ini STATIC
        // (berlaku global selama request), supaya tidak memengaruhi pemakaian
        // TemplateProcessor lain di kode yang sama.
        $processor->setMacroChars('{{', '}}');

        $this->isiPerIkuDinamis($processor, $templatePath, $data);
        $this->isiHeader($processor, $notula, $data);
        $this->isiPenutup($processor, $notula);
        if (! $sertakanBlokTtdMandiri || $notula->status !== Notula::STATUS_DISETUJUI) {
            $this->hapusBlokTtdMandiri($processor);
        }
        $this->cegahBarisTerpotongAntarHalaman($processor);

        $dir = dirname($outputPath);

        try {
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new RuntimeException("Folder untuk berkas Bagian I tidak bisa dibuat: {$dir}. Periksa izin tulis folder storage di server.");
            }
            if (! is_writable($dir)) {
                throw new RuntimeException("Folder untuk berkas Bagian I tidak bisa ditulisi: {$dir}. Periksa izin tulis folder storage di server.");
            }

            // TemplateProcessor::saveAs() menghapus tujuan lalu copy() TANPA mengecek
            // hasilnya -- dicek sendiri di sini: berkas harus ada, tidak kosong, dan
            // berupa arsip ZIP (.docx) yang utuh.
            try {
                $processor->saveAs($outputPath);
            } catch (Throwable $e) {
                throw new RuntimeException('Berkas Bagian I (.docx) gagal ditulis: '.$e->getMessage(), 0, $e);
            }

            clearstatcache(true, $outputPath);
            $kepala = is_file($outputPath) ? (string) @file_get_contents($outputPath, false, null, 0, 2) : '';
            if ($kepala !== 'PK' || filesize($outputPath) < 100) {
                if (file_exists($outputPath)) {
                    @unlink($outputPath);
                }

                throw new RuntimeException('Berkas Bagian I (.docx) hasil penyusunan rusak atau kosong.');
            }
        } finally {
            $processor->setMacroChars('${', '}');
        }

        return $outputPath;
    }

    /**
     * @return list<string> lihat catatan $formulaUrutan di atas.
     */
    public function formulaUrutanTerakhir(): array
    {
        return $this->formulaUrutan;
    }

    /**
     * Ukuran kertas & margin (pt) dari <w:sectPr> TERAKHIR template Bagian I yang
     * aktif -- dipakai dokumen notula gabungan (pdf.notula-utuh) supaya PDF mengikuti
     * kertas/orientasi/margin template yang diunggah Tim SAKIP, bukan angka tetap di
     * kode. Orientasi sudah tercermin di w:w/w:h (lanskap = w:w > w:h).
     *
     * Jatuh ke tata halaman template bawaan (A4 tegak, margin
     * 851/1440/1135/1440 twips) bila template tidak bisa dibaca -- PDF tetap bisa
     * dibuat walau template sedang bermasalah.
     *
     * @return array{lebar: float, tinggi: float, atas: float, kanan: float, bawah: float, kiri: float}
     */
    public function tataHalamanTemplate(): array
    {
        $bawaan = ['lebar' => 595.3, 'tinggi' => 841.9, 'atas' => 42.55, 'kanan' => 72.0, 'bawah' => 56.75, 'kiri' => 72.0];

        try {
            $zip = new \ZipArchive;
            if ($zip->open($this->resolveTemplatePath()) !== true) {
                return $bawaan;
            }
            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();
        } catch (Throwable $e) {
            Log::warning("Tata halaman template notula tidak terbaca, memakai bawaan: {$e->getMessage()}");

            return $bawaan;
        }

        if (! preg_match_all('/<w:sectPr\b.*?<\/w:sectPr>/s', $xml, $m)) {
            return $bawaan;
        }
        $sect = end($m[0]);

        $atribut = function (string $tag, string $nama) use ($sect): ?float {
            if (preg_match('/<w:'.$tag.'\b[^>]*\bw:'.$nama.'="(-?\d+)"/', $sect, $a)) {
                return abs((int) $a[1]) / 20;
            }

            return null;
        };

        $hasil = [
            'lebar' => $atribut('pgSz', 'w') ?? $bawaan['lebar'],
            'tinggi' => $atribut('pgSz', 'h') ?? $bawaan['tinggi'],
            'atas' => $atribut('pgMar', 'top') ?? $bawaan['atas'],
            'kanan' => $atribut('pgMar', 'right') ?? $bawaan['kanan'],
            'bawah' => $atribut('pgMar', 'bottom') ?? $bawaan['bawah'],
            'kiri' => $atribut('pgMar', 'left') ?? $bawaan['kiri'],
        ];

        // Nilai tidak masuk akal (mis. margin lebih besar dari kertas) -> bawaan.
        if ($hasil['lebar'] - $hasil['kiri'] - $hasil['kanan'] < 100 || $hasil['tinggi'] - $hasil['atas'] - $hasil['bawah'] < 100) {
            return $bawaan;
        }

        return $hasil;
    }

    /**
     * Berkas Template Notula yang sedang diunggah (Pengaturan > Template Notula) diutamakan
     * bila ada dan masih tersimpan di disk -- jatuh ke berkas bawaan proyek kalau belum
     * pernah diunggah sama sekali (instalasi baru).
     *
     * Salinan lokal (storage/app/private) TIDAK persisten di Render free plan -- terhapus
     * tiap kali container di-deploy ulang, walau unggahan ke Drive sudah sukses sebelumnya
     * (lihat TemplateNotula::unggah()). Sebelum diam-diam jatuh ke template BAWAAN (yang
     * BEDA dari template resmi yang diunggah Tim SAKIP -- notula hasilnya akan meleset dari
     * template tanpa peringatan apa pun), coba dulu ambil ulang dari Drive lewat
     * drive_file_id, sama seperti pola BerkasDownloadController::show(). Hasilnya disimpan
     * balik ke disk lokal supaya panggilan generate() berikutnya dalam deploy yang sama
     * tidak perlu memanggil Drive API lagi setiap kali.
     */
    private function resolveTemplatePath(): string
    {
        $config = FolderConfig::current();

        if ($config->template_notula_path && Storage::disk('local')->exists($config->template_notula_path)) {
            return Storage::disk('local')->path($config->template_notula_path);
        }

        if ($config->template_notula_path && $config->template_notula_drive_file_id) {
            try {
                $konten = app(GoogleDriveService::class)->downloadFileContent($config->template_notula_drive_file_id);
                Storage::disk('local')->put($config->template_notula_path, $konten);

                return Storage::disk('local')->path($config->template_notula_path);
            } catch (Throwable $e) {
                Log::warning("Gagal mengambil template notula dari Drive, jatuh ke template bawaan: {$e->getMessage()}");
            }
        }

        $bawaan = base_path(self::DEFAULT_TEMPLATE_PATH);
        abort_unless(file_exists($bawaan), 404, 'Template Bagian I (mesin) belum tersedia.');

        return $bawaan;
    }

    /**
     * Satu nilai teks bebas -- newline TETAP newline asli (TemplateProcessor::setValue
     * otomatis mengubahnya jadi <w:br/> lewat replaceCarriageReturns(), sudah diverifikasi
     * lewat smoke test manual), null jadi placeholder "…".
     *
     * rtrim() (BUKAN trim()) SENGAJA -- daftarBernomor() menaruh "\n" PEMBUKA supaya
     * poin 1 pindah baris baru dari label di depannya (mis. "Kendala : {{kendala}}");
     * trim() akan membuang newline pembuka itu lagi dan poin 1 menempel lagi ke baris
     * label seperti bug sebelumnya. Whitespace/newline di AKHIR tetap dibuang (rtrim)
     * supaya isian bebas dari textarea tidak menyisakan baris kosong nganggur.
     */
    private function set(TemplateProcessor $p, string $nama, ?string $nilai): void
    {
        $bersih = $nilai !== null ? rtrim($nilai) : '';
        $p->setValue($nama, $bersih !== '' ? $bersih : '…', -1);
    }

    /**
     * Isi macro dengan TAUTAN SUNGGUHAN (bisa diklik, biru bergaris bawah seperti
     * hyperlink Word biasa), bukan teks URL polos -- lewat field HYPERLINK, yang
     * tidak butuh relasi (document.xml.rels) sehingga aman juga di dalam blok IKU
     * yang digandakan lewat TemplateProcessor sementara. Sama seperti setFormula(),
     * nilainya "memutus" run tempat macro berada: menutup <w:t>/<w:r>, menyisipkan
     * run-run field, lalu membuka <w:r>/<w:t> kosong baru supaya XML tetap sah.
     *
     * Nilai yang bukan URL http(s) (mis. diketik bebas) jatuh ke set() biasa.
     */
    private function setTautan(TemplateProcessor $p, string $nama, ?string $url): void
    {
        $url = trim((string) $url);
        if (! preg_match('#^https?://\S+$#i', $url)) {
            $this->set($p, $nama, $url !== '' ? $url : null);

            return;
        }

        $teks = htmlspecialchars($url, ENT_QUOTES | ENT_XML1);
        // Tanda kutip di dalam instrText HYPERLINK "..." harus di-encode supaya
        // argumen field tidak terpotong.
        $target = htmlspecialchars(str_replace('"', '%22', $url), ENT_QUOTES | ENT_XML1);
        $gaya = '<w:rPr><w:color w:val="0563C1"/><w:u w:val="single"/></w:rPr>';

        $p->setValue($nama, '</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r><w:instrText xml:space="preserve"> HYPERLINK "'.$target.'" </w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            .'<w:r>'.$gaya.'<w:t xml:space="preserve">'.$teks.'</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r>'
            .'<w:r><w:t xml:space="preserve">', -1);
    }

    /**
     * Isi {{formula_capaian}} dengan pecahan bersusun SUNGGUHAN (OOXML Math, lihat
     * App\Support\RumusMarkup::keOmml()) -- BEDA dari set() biasa karena <m:oMath>
     * TIDAK sah sebagai isi <w:t> (macro-nya ada di dalam satu <w:t> milik satu
     * <w:r>). Nilainya "memutus" run tsb: menutup <w:t>/<w:r> yang sedang berjalan,
     * menyisipkan <m:oMath> sebagai run SEJAJAR (sibling) di dalam <w:p> yang sama,
     * lalu membuka <w:r>/<w:t> kosong baru supaya XML tetap sah -- aman disisipkan
     * mentah lewat setValue() karena Settings::isOutputEscapingEnabled() default
     * false (replace TIDAK di-escape, lihat vendor TemplateProcessor::setValue()).
     */
    private function setFormula(TemplateProcessor $p, string $formulaBaris): void
    {
        // Jalur pratinjau HTML: LibreOffice MEMBUANG <m:oMath> saat ekspor ke HTML
        // (paragraf rumusnya jadi kosong), jadi yang disisipkan cukup teks penanda
        // tanpa spasi -- diganti HTML bersusun setelah konversi, lihat
        // NotulaService::gantiPenandaRumusDenganHtml().
        if ($this->untukPratinjauHtml) {
            $p->setValue('formula_capaian', self::penandaRumus(count($this->formulaUrutan)), -1);

            return;
        }

        $omml = RumusMarkup::keOmml($formulaBaris);
        $p->setValue('formula_capaian', '</w:t></w:r>'.$omml.'<w:r><w:t xml:space="preserve">', -1);
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

    /**
     * Gabungkan daftar nama dengan "dan" -- TANPA koma bila cuma DUA nama ("A dan
     * B"), koma di antara SISANYA baru "dan" sebelum nama terakhir bila TIGA atau
     * lebih ("A, B, dan C"). Collection::join() bawaan Laravel selalu memakai
     * $finalGlue yang sama utuh berapa pun jumlah itemnya -- tidak bisa membedakan
     * kasus dua item dari tiga+ seperti aturan penulisan Indonesia yang diminta di
     * sini, jadi dipisah manual per jumlah item.
     */
    private function gabungDenganDan(iterable $items): string
    {
        $daftar = collect($items)->filter()->values();

        return match ($daftar->count()) {
            0 => '',
            1 => $daftar->first(),
            2 => $daftar->implode(' dan '),
            default => $daftar->join(', ', ', dan '),
        };
    }

    private function isiHeader(TemplateProcessor $p, Notula $notula, array $data): void
    {
        $periode = $data['periode'];

        $this->set($p, 'tahun', (string) $periode->tahun);
        $this->set($p, 'triwulan_angka', (string) $data['labelTriwulan']);
        // Romawi triwulan sebelumnya (mis. "II" untuk notula TW III) -- template tidak
        // bisa menghitung "{{triwulan_angka-1}}" sendiri. TW I tidak punya triwulan
        // sebelumnya di tahun yang sama, jadi "…".
        $this->set($p, 'triwulan_sebelumnya', $periode->triwulan > 1 ? ['I', 'II', 'III'][$periode->triwulan - 2] : null);
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
        $this->setTautan($p, 'lampiran_basis_data', $notula->linkLampiranBasisDataEfektif());
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

        $sub = $this->newTemplateProcessor($templatePath);
        $sub->setMacroChars('{{', '}}');

        $potongan = $daftarIku->map(function ($iku) use ($data, $ikuBlokTemplate, $sub) {
            return $this->paragrafPenandaBlok('iku-'.$iku->id).$this->isiSatuIku($sub, $ikuBlokTemplate, $iku, $data);
        });

        $isiBaru = $potongan->implode('<w:p/>')
            .$this->paragrafPenandaBlok('kustom').$this->bagianKustomXml($data['bagianKustomPerBagian'])
            .$this->paragrafPenandaBlok('ekor');

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
            // Judul & format baris diatur Tim SAKIP di Data Master (lihat BagianKustom::
            // judulNotulaEfektif() & formatNotulaEfektif()), bukan ditulis di sini.
            $xml .= '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">'
                .htmlspecialchars($bagian->judulNotulaEfektif(), ENT_QUOTES | ENT_XML1)
                .'</w:t></w:r></w:p>';

            foreach ($bagian->poin as $poin) {
                $konteks = \App\Support\TemplateBagianKustom::konteks(
                    $poin->masterIku->kode ?? null,
                    $poin->masterIku->indikator ?? null,
                    $poin->teks,
                    $bagian->nama,
                    $poin->periode?->tahun,
                    $poin->periode?->triwulan,
                    $poin->periode?->bulan,
                    $bagian->nilaiKolomLengkap($poin->nilai_kolom),
                );

                $teks = \App\Support\TemplateBagianKustom::render($bagian->formatNotulaEfektif(), $konteks);
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

        // Deteksi BerAKHLAK dari TEKS indikator (bukan kode -- penomoran kode IKU
        // beda tiap instansi & bisa berubah). Proksi TIDAK lagi dideteksi dari teks
        // "SAKIP": blok_proksi tampil untuk IKU mana pun yang punya Indikator Proksi
        // isian Tim SAKIP (App\Models\IndikatorProksi).
        $isBerakhlak = str_contains(mb_strtolower($iku->indikator), 'berakhlak');
        $proksiIku = ($data['proksiPerIku'] ?? collect())->get($iku->id, collect());
        $tampilkanProksi = $proksiIku->isNotEmpty();
        // Tabel RO hanya tampil bila BENAR-BENAR ada RO yang SUDAH direalisasikan
        // (volume_ro atau progres_persen terisi) -- sebelumnya cukup RO-nya ADA
        // (baris Kegiatan/RincianOutput sudah dibuat) walau belum satu pun diisi
        // realisasinya, sehingga tabel tetap tercetak tapi isinya "…" semua (tampak
        // kosong). Baris yang belum diisi realisasinya ikut disaring keluar (lihat
        // pemakaian $roIkuTerisi di gandakanBarisRo() di bawah), bukan cuma dicek
        // ada/tidaknya -- supaya tabel betul-betul dilewati bila TIDAK ADA satu pun
        // RO yang punya realisasi, langsung lanjut ke bagian berikutnya.
        $roIkuTerisi = $roIku->filter(fn ($ro) => filled($ro->volume_ro) || $ro->progres_persen !== null);
        $tampilkanRo = ! $isBerakhlak && empty($rekap['realisasi'] ?? null) && $roIkuTerisi->isNotEmpty();
        // Baris rumus "y = n/N x 100%" (paragraf TERSENDIRI rata tengah, lihat
        // formulaBaris()) hanya tampil untuk IKU % yang datanya lengkap -- sama seperti
        // blok_proksi/blok_ro, dibuang total (bukan dikosongkan jadi "…") bila tidak berlaku
        // supaya tidak menyisakan paragraf rata-tengah kosong. IKU bersatuan Poin (rumus
        // unik per indikator, mis. IPP/TPSS) TIDAK punya rumus otomatis -- kalau Tim SAKIP
        // menuliskan sintaks RumusMarkup di baris pertama "Dasar Hitung"-nya sendiri, itu
        // yang dipakai sebagai gantinya (lihat formulaKustomDariDasarHitung()). Rumus
        // otomatis SELALU diutamakan bila keduanya kebetulan ada, supaya tidak dobel.
        $formulaOtomatis = $this->formulaBaris($iku, $rekap);
        $formulaKustom = $formulaOtomatis === null ? $this->formulaKustomDariDasarHitung($iku->dasar_hitung) : null;
        $formulaDicetak = $formulaOtomatis ?? $formulaKustom['formula'] ?? null;
        $tampilkanFormula = $formulaDicetak !== null;

        $xml = $blokTemplate;
        // Template lama masih bisa memuat blok_sakip (tabel proksi tetap khusus SAKIP) --
        // selalu dibuang, digantikan blok_proksi.
        if (str_contains($xml, '{{blok_sakip}}')) {
            $xml = $this->resolveBlokKondisional($xml, 'blok_sakip', false, $this->unitPenanda($xml, '{{blok_sakip}}'));
        }
        if (str_contains($xml, '{{blok_proksi}}')) {
            $xml = $this->resolveBlokKondisional($xml, 'blok_proksi', $tampilkanProksi, $this->unitPenanda($xml, '{{blok_proksi}}'));
            if ($tampilkanProksi) {
                $xml = $this->gandakanBarisProksi($xml, $proksiIku);
            }
        }
        // blok_berakhlak OPSIONAL: sejak Indikator Proksi berlaku untuk SEMUA IKU (termasuk
        // BerAKHLAK), template baru cukup memakai blok_proksi -- penanda ini hanya diproses
        // bila template lama masih memuatnya.
        if (str_contains($xml, '{{blok_berakhlak}}')) {
            $xml = $this->resolveBlokKondisional($xml, 'blok_berakhlak', $isBerakhlak);
        }
        $xml = $this->resolveBlokKondisional($xml, 'blok_ro', $tampilkanRo, $this->unitPenanda($xml, '{{blok_ro}}'));
        $xml = $this->resolveBlokKondisional($xml, 'blok_formula', $tampilkanFormula, 'p');
        if ($tampilkanRo) {
            $xml = $this->gandakanBarisRo($xml, $roIkuTerisi);
        }
        $xml = $this->rapikanSelTabel($xml);

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
        // sudah dipakai Tim SAKIP di jalur PDF. Ditampilkan untuk SEMUA IKU
        // (termasuk BerAKHLAK & yang punya proksi) -- kotak "Analisis Capaian Kinerja"
        // tidak lagi dirangkap jadi pertanyaan proksi seperti versi template lama.
        $analisis = trim((string) $capaian?->analisis_capaian);
        // Nama kegiatan utama, satu kali per kegiatan walau dipisah ke beberapa tahapan
        // (lihat Kegiatan::daftarNamaCapaian()) -- bukan nama folder tahapannya.
        $daftarKegiatan = $this->daftarBernomor(Kegiatan::daftarNamaCapaian($kegiatanIku));
        // $daftarKegiatan SUDAH diawali "\n" sendiri (lihat daftarBernomor()) -- pemisahnya
        // cukup SATU "\n" lagi di sini supaya total ada baris kosong di antara narasi dan
        // daftar (bukan dua "\n\n" + "\n" bawaan yang bikin tiga baris kosong).
        $analisisLengkap = $analisis !== '' && $daftarKegiatan !== null
            ? $analisis."\n".$daftarKegiatan
            : ($analisis !== '' ? $analisis : $daftarKegiatan);
        $this->set($sub, 'analisis_capaian', $analisisLengkap);

        // Kendala & Solusi sebagai DUA variabel terpisah (mengikuti dua baris terpisah
        // pada dokumen resmi), KUMULATIF TW1..TW berjalan (RF-28), disusun sebagai
        // daftar bernomor per indikator -- sama seperti jalur PDF.
        $semuaKendalaSolusi = $kendalaSolusiTriwulan->flatten(1);
        $this->set($sub, 'kendala', $this->daftarBernomor($semuaKendalaSolusi->pluck('kendala')->filter()));
        $this->set($sub, 'solusi', $this->daftarBernomor($semuaKendalaSolusi->pluck('solusi')->filter()));

        $rtlTeks = $this->daftarBernomor($rtlIku->pluck('rtl_teks')->filter());
        // PIC Tindak Lanjut = tim (boleh lebih dari satu, digabung dengan "dan" --
        // lihat gabungDenganDan()) yang ditugaskan pada IKU ini di Master IKU
        // (App\Models\IkuTim), BUKAN nama orang yang diketik bebas per poin RTL.
        $this->set($sub, 'rtl', $rtlTeks);
        $this->set($sub, 'pic_rtl', $this->gabungDenganDan($iku->namaTimList()));
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
        // dirakit otomatis di formulaBaris() supaya Tim SAKIP tidak perlu mengetik
        // ulang rumus & memperbarui angkanya tiap triwulan, dicetak sebagai paragraf
        // TERSENDIRI rata tengah (blok_formula, lihat $tampilkanFormula di atas) --
        // beda dari "dimana:.../rincian n-N" & kolom dasar_hitung manual di bawahnya
        // yang tetap rata kiri. [[a|b]] dirakit jadi pecahan bersusun SUNGGUHAN lewat
        // OOXML Math (lihat setFormula() & App\Support\RumusMarkup::keOmml()) -- BUKAN
        // notasi "a/b" biasa, supaya .docx tercetak persis seperti dokumen resmi.
        if ($tampilkanFormula) {
            $this->setFormula($sub, $formulaDicetak);
            $this->formulaUrutan[] = $formulaDicetak;
        }

        // Kolom dasar_hitung sendiri (kalau diisi) TETAP ditampilkan sebagai keterangan
        // tambahan setelah "dimana:.../rincian" (mis. rincian "Target 2026: N = 2
        // mencakup: ..."), bukan lagi wajib memuat rumus lengkap. Bila baris pertamanya
        // sudah "diambil" jadi rumus kustom di atas ($formulaKustom), sisanya (baris
        // KEDUA dst.) yang jadi keterangan di sini -- bukan dasar_hitung utuh lagi,
        // supaya rumusnya tidak tercetak dobel (sekali bersusun di blok_formula, sekali
        // lagi mendatar di sini).
        // Untuk IKU % (rumus otomatis), baris rumus ketikan lama di Dasar Hitung manual
        // (mis. "y = n/N x 100%") ikut dibuang -- rumusnya sudah tercetak bersusun di
        // blok_formula & "y = ..." sudah dijelaskan di keterangan "dimana:".
        $dasarHitungSumber = match (true) {
            $formulaKustom !== null => $formulaKustom['sisa'],
            $formulaOtomatis !== null => $this->buangBarisRumusManual($iku->dasar_hitung),
            default => $iku->dasar_hitung,
        };
        $dasarHitungGabungan = collect([$this->formulaKeterangan($iku, $rekap, $data['periode']), $dasarHitungSumber])
            ->filter(fn ($t) => filled($t))
            ->implode("\n\n");
        $dasarHitungTeks = RumusMarkup::keTeksPolos($dasarHitungGabungan);
        $dasarHitung = trim(($dasarHitungTeks ?: '').($iku->basis_data ? ' Basis Data: '.$iku->basis_data : ''));
        $this->set($sub, 'dasar_hitung', $dasarHitung !== '' ? $dasarHitung : null);
        $this->setTautan($sub, 'bukti_realisasi', $linkFolder);
        // Bagian 4 Isian Kegiatan (Evaluasi RTL): poin RTL yang DIRENCANAKAN untuk
        // triwulan notula ini -- isinya sama dengan {{rtl}}, nama terpisah supaya jelas
        // di template. Bagian 5 (RTL triwulan berikutnya, TW IV -> TW I tahun depan):
        // {{rtl_berikutnya}} + batas waktunya (akhir triwulan berikutnya).
        $rtlBerikutnyaIku = ($data['rtlBerikutnyaPerIku'] ?? collect())->get($iku->id, collect());
        $this->set($sub, 'rtl_evaluasi', $rtlTeks);
        $this->setTautan($sub, 'bukti_rtl_evaluasi', ($data['linkFolderEvaluasiRtlPerIku'] ?? collect())->get($iku->id));
        $this->set($sub, 'rtl_berikutnya', $this->daftarBernomor($rtlBerikutnyaIku->pluck('rtl_teks')->filter()));
        $this->set($sub, 'batas_waktu_rtl_berikutnya', $rtlBerikutnyaIku->isNotEmpty()
            ? $this->akhirTriwulan($data['tahunTwBerikutnya'], $data['twBerikutnya'])->translatedFormat('F Y')
            : null);
        $this->setTautan($sub, 'bukti_rtl_sebelumnya',$rtlSebelumnyaIku->isNotEmpty() ? $linkFolderTwSebelumnya : null);
        $this->set($sub, 'penjelasan_lainnya', $capaian?->catatan);

        return $this->getMainPart($sub);
    }

    /**
     * Akhir bulan triwulan $triwulan pada tahun $tahun (TW I->Maret, II->Juni,
     * III->September, IV->Desember) -- dipakai untuk Batas Waktu Tindak Lanjut,
     * lihat pemanggilnya di isiSatuIku().
     */
    private function akhirTriwulan(int $tahun, int $triwulan): Carbon
    {
        // locale('id') eksplisit: APP_LOCALE production 'en', jadi translatedFormat()
        // tanpa ini mencetak "December 2026", bukan "Desember 2026".
        return Carbon::create($tahun, $triwulan * 3, 1)->endOfMonth()->locale('id');
    }

    /**
     * Baris rumus baku untuk IKU bersatuan Persen: "y = n/N x 100%" -- SEMUA IKU %
     * memakai pola yang SAMA (beda dari IKU bersatuan Poin, mis. IPP/TPSS, yang
     * rumusnya unik per indikator dan tetap diketik manual). n & N di sini BUKAN
     * pasangan yang dipakai capaian_tw/capaian_pk (itu memakai kumulatif TW I s.d.
     * TW berjalan, lihat CapaianTahunan::realisasiKumulatif() -- TIDAK disentuh):
     * - n = KUMULATIF TW I s.d. TW berjalan (rekap['x_realisasi_kumulatif']), sama
     *   seperti "item kumulatif s.d. TW ini" di Verifikasi Capaian & Realisasi
     *   Kumulatif (%) -- bukan nilai mentah triwulan berjalan saja (yang bisa 0
     *   walau item sudah terealisasi di triwulan sebelumnya).
     * - N = Alokasi Y TW IV (rekap['y_target'], sumber App\Models\CapaianTahunan::
     *   y_alokasi_tw4 -- konstan sepanjang tahun, dibaca langsung TANPA dijumlah,
     *   sama seperti App\Models\CapaianTahunan::targetTahunan()).
     *
     * Null (bukan dirakit) bila datanya belum lengkap (satuan bukan Persen, atau
     * Deskripsi X/Y / target Y / realisasi triwulan berjalan belum diisi) -- supaya
     * jatuh ke isian manual kolom `dasar_hitung` apa adanya, bukan menampilkan
     * rumus dengan bagian kosong. Dicetak sebagai paragraf TERSENDIRI rata tengah
     * ({{formula_capaian}}, blok_formula) TERPISAH dari formulaKeterangan() di
     * bawah -- lihat isiSatuIku().
     */
    private function formulaBaris(MasterIku $iku, array $rekap): ?string
    {
        if ($iku->satuan !== 'Persen' || ! $iku->deskripsi_x || ! $iku->deskripsi_y) {
            return null;
        }

        $n = $rekap['x_realisasi_kumulatif'] ?? null;
        $besarN = $rekap['y_target'] ?? null;
        if ($n === null || $besarN === null) {
            return null;
        }

        // n & N di rumus selalu bilangan bulat (jumlah item), bukan "3.00" dari
        // kolom desimal database.
        $nTeks = PengaturanCapaian::formatAngka((int) round((float) $n));
        $besarNTeks = PengaturanCapaian::formatAngka((int) round((float) $besarN));

        return "y = [[n|N]] × 100% = [[{$nTeks}|{$besarNTeks}]] × 100%";
    }

    /**
     * Ambil rumus KUSTOM IKU bersatuan Poin (mis. IPP: "IPP = w1 x [[SUM:i=1,n|xi]] +
     * w2 x [[SUM:i=1,m|yi]]") dari BARIS PERTAMA kolom "Dasar Hitung" Master IKU --
     * konvensinya: baris pertama berisi rumusnya (dicetak bersusun rata tengah lewat
     * blok_formula, PERSIS seperti rumus otomatis formulaBaris()), baris-baris
     * SETELAHNYA (kalau ada) tetap keterangan biasa rata kiri seperti dasar_hitung
     * lain -- dikembalikan terpisah lewat kunci 'sisa' supaya pemanggil (isiSatuIku())
     * tidak mencetak rumusnya dua kali (sekali bersusun, sekali lagi mendatar).
     *
     * Null bila baris pertama TIDAK memuat sintaks RumusMarkup "[[" sama sekali --
     * berarti seluruh isian memang teks keterangan biasa (perilaku lama, tidak
     * berubah). Dipanggil HANYA bila formulaBaris() sendiri null (lihat isiSatuIku())
     * -- rumus otomatis IKU % SELALU diutamakan, supaya tidak dobel dengan isian
     * manual yang kebetulan juga diawali "[[".
     *
     * @return array{formula: string, sisa: ?string}|null
     */
    private function formulaKustomDariDasarHitung(?string $dasarHitung): ?array
    {
        $dasarHitung = trim((string) $dasarHitung);
        if ($dasarHitung === '') {
            return null;
        }

        [$barisPertama, $sisa] = array_pad(explode("\n", $dasarHitung, 2), 2, null);
        $barisPertama = trim($barisPertama);

        if (! str_contains($barisPertama, '[[')) {
            return null;
        }

        return ['formula' => $barisPertama, 'sisa' => $sisa !== null && trim($sisa) !== '' ? trim($sisa) : null];
    }

    /**
     * Dasar Hitung manual TANPA baris berawalan "y =" (rumus ketikan lama yang
     * kini dobel dengan rumus otomatis formulaBaris()). Null bila tidak bersisa.
     */
    private function buangBarisRumusManual(?string $dasarHitung): ?string
    {
        $sisa = collect(preg_split('/\R/u', (string) $dasarHitung))
            ->reject(fn (string $baris) => preg_match('/^\s*y\s*=/iu', $baris))
            ->implode("\n");

        return trim($sisa) !== '' ? trim($sisa) : null;
    }

    /**
     * Keterangan "dimana: y=.../n=.../N=..." + rincian nyata item n/N (lihat
     * rincianNMencakupTeks()) yang MENYERTAI formulaBaris() -- dipisah supaya
     * formulaBaris() bisa dicetak rata tengah sementara keterangan ini (mis. "N = 4
     * mencakup: ...") tetap rata kiri seperti isian dasar_hitung manual lainnya.
     * Null bila formulaBaris() sendiri null (rumus tidak berlaku untuk IKU ini) --
     * keterangan tanpa rumusnya sendiri tidak bermakna.
     *
     * SEMUA IKU % (satuan Persen hanya terjadi untuk MasterIku::pakaiRasio(), lihat
     * MasterIku::booted()) mendapat TAMBAHAN rincian nyata item-item n/N -- Tim
     * SAKIP tidak perlu lagi mengetik ulang daftarnya secara manual tiap triwulan
     * ke kolom dasar_hitung (mis. "N = 4 mencakup: ..."), karena sumbernya sudah
     * App\Models\RincianN yang diisi sekali di awal tahun (App\Livewire\TargetTahunan)
     * & dipilih per triwulan (App\Livewire\VerifikasiCapaian). Null (belum ada satu
     * pun item RincianN) berarti bagian rincian ini dilewatkan.
     */
    private function formulaKeterangan(MasterIku $iku, array $rekap, Periode $periode): ?string
    {
        if ($this->formulaBaris($iku, $rekap) === null) {
            return null;
        }

        $keterangan = "dimana:\ny = {$iku->indikator}\nn = {$iku->deskripsi_x}\nN = {$iku->deskripsi_y}";

        $rincian = $this->rincianNMencakupTeks($iku, $periode);

        return $rincian !== null ? "{$keterangan}\n\n{$rincian}" : $keterangan;
    }

    /**
     * Rincian nyata isi n (item yang triwulan_realisasi-nya <= triwulan Notula ini,
     * KUMULATIF -- sama seperti n di formulaBaris() & daftar di Verifikasi Capaian) dan N
     * (SELURUH item tahun ini) untuk IKU bermetode Rasio, format
     * "n = 1 mencakup:\n• uraian\n\nN = 4 mencakup:\n• uraian...", persis contoh
     * manual yang sebelumnya diketik Tim SAKIP sendiri ke kolom dasar_hitung.
     * Null bila IKU ini belum punya satu pun baris RincianN untuk tahun tsb.
     *
     * Baris "n = ..." DIHILANGKAN SAMA SEKALI (bukan dicetak "n = 0") bila belum ada
     * satu pun item yang direalisasikan s.d. triwulan ini -- langsung lompat ke rincian N,
     * sesuai contoh dokumen resmi.
     */
    private function rincianNMencakupTeks(MasterIku $iku, Periode $periode): ?string
    {
        $semua = RincianN::where('iku_id', $iku->id)->where('tahun', $periode->tahun)->orderBy('id')->get();

        if ($semua->isEmpty()) {
            return null;
        }

        $terealisasi = $semua->filter(fn (RincianN $r) => $r->triwulan_realisasi !== null && $r->triwulan_realisasi <= $periode->triwulan);

        $daftar = fn (Collection $baris) => $baris->map(fn (RincianN $r) => '• '.$r->uraian)->implode("\n");

        $blokN = $terealisasi->isNotEmpty()
            ? "n = {$terealisasi->count()} mencakup:\n".$daftar($terealisasi)."\n\n"
            : '';

        return "{$blokN}N = {$semua->count()} mencakup:\n".$daftar($semua);
    }

    /**
     * Tandai SELURUH baris tabel (<w:tr>) di dokumen supaya TIDAK BOLEH terpotong Word di
     * tengah saat menyeberangi batas halaman (<w:cantSplit/>) -- bawaan Word MENGIZINKAN baris
     * terpotong, dan tabel Bagian I ini satu baris bisa memuat cukup banyak teks (mis. baris RO
     * dengan uraian panjang, atau baris Analisis Capaian Kinerja + daftar kegiatan) sehingga kerap
     * jatuh tepat di batas halaman: isinya lalu tampak "terpotong" ke baris tabel BERIKUTNYA
     * (kolom lain di baris yang sama seolah kosong di potongan pertama, lalu muncul sendirian di
     * potongan kedua) -- persis kerancuan Realisasi Volume RO vs Kegiatan/Kendala-Solusi yang
     * dilaporkan Tim SAKIP. Dipasang GLOBAL (bukan cuma baris RO) karena baris Kegiatan dan
     * Kendala/Solusi pada tabel per-IKU yang sama berisiko sama.
     *
     * Dijalankan di generate() SETELAH seluruh manipulasi XML lain (blok IKU digandakan, baris RO
     * digandakan, dst) supaya menyisir HASIL AKHIR dokumen, bukan cuma template mentahnya.
     *
     * Baris HARUS diperiksa dulu apakah sudah punya <w:trPr> sendiri (mis. baris header tabel
     * Sasaran/Triwulan yang butuh <w:trPr><w:jc w:val="center"/></w:trPr> supaya sel vMerge/
     * gridSpan-nya rata tengah) -- kalau ADA, <w:cantSplit/> harus disisipkan sebagai ANAK di
     * dalam <w:trPr> yang sudah ada itu, BUKAN menambah <w:trPr> KEDUA yang terpisah. Skema OOXML
     * hanya mengizinkan SATU <w:trPr> per <w:tr>; dua elemen trPr bersebelahan membuat baris itu
     * tidak valid, dan Word/LibreOffice lalu merusak susunan sel gabungan (vMerge/gridSpan) baris
     * tsb saat memulihkannya -- persis tabel header Sasaran/Triwulan yang tampak berantakan di
     * pratinjau Kompilasi Notula walau template aslinya benar.
     */
    private function cegahBarisTerpotongAntarHalaman(TemplateProcessor $processor): void
    {
        $xml = $this->getMainPart($processor);
        $xml = preg_replace_callback(
            '/<w:tr\b([^>]*)>(\s*<w:trPr\s*\/>|\s*<w:trPr>)?/',
            function (array $m): string {
                $trOpen = '<w:tr'.$m[1].'>';
                $trPr = $m[2] ?? '';

                if ($trPr === '') {
                    return $trOpen.'<w:trPr><w:cantSplit/></w:trPr>';
                }

                if (str_contains($trPr, '/>')) {
                    // <w:trPr/> kosong (self-closed) -- ganti jadi <w:trPr><w:cantSplit/></w:trPr>.
                    return $trOpen.'<w:trPr><w:cantSplit/></w:trPr>';
                }

                // <w:trPr> sudah terbuka dengan anak-anaknya sendiri menyusul di XML setelah ini
                // (mis. <w:jc/>) -- cukup selipkan <w:cantSplit/> sebagai anak PERTAMA di dalamnya.
                return $trOpen.$trPr.'<w:cantSplit/>';
            },
            $xml
        );
        $this->setMainPart($processor, $xml);
    }

    /**
     * {{blok_x}}...{{/blok_x}} -- kalau $tampilkan true, baris/paragraf marker-nya dibuang tapi
     * ISINYA dipertahankan; kalau false, marker DAN isinya sekaligus dibuang (tidak ada blok lain
     * yang dipilih untuk IKU ini, mis. sudah ada realisasi & bukan BerAKHLAK). $unit 'tr'
     * untuk penanda berupa BARIS TABEL tersendiri (blok_berakhlak), 'p' untuk penanda
     * berupa PARAGRAF di dalam sel (blok_formula); blok_proksi & blok_ro dideteksi otomatis
     * lewat unitPenanda().
     */
    private function resolveBlokKondisional(string $xml, string $namaBlok, bool $tampilkan, string $unit = 'tr'): string
    {
        [$before, $inner, $after] = $this->splitOnMarkers($xml, "{{{$namaBlok}}}", "{{/{$namaBlok}}}", $unit);

        return $before.($tampilkan ? $inner : '').$after;
    }

    /**
     * Gandakan baris template RO ({{ro_row}}...{{/ro_row}}, 3 kolom: Rincian Output/Realisasi
     * Volume RO/Progres) sekali per RincianOutput yang SUDAH direalisasikan pada IKU ini (RF
     * baru: satu Kegiatan boleh punya banyak RO), bukan katalog kode RO tetap seperti versi
     * lama. $roIku di sini SELALU sudah tersaring notEmpty() -- pemanggilnya (isiSatuIku())
     * hanya memanggil method ini bila $tampilkanRo true, lihat $roIkuTerisi di sana.
     */
    private function gandakanBarisRo(string $xml, Collection $roIku): string
    {
        [$before, $rowTemplate, $after] = $this->splitOnMarkers($xml, '{{ro_row}}', '{{/ro_row}}', 'tr');

        $baris = $roIku->map(function ($ro) use ($rowTemplate) {
            $progres = $ro->progres_persen !== null
                ? number_format((float) $ro->progres_persen, 2, ',', '.').'%'
                : '…';

            return $this->isiBarisRo(
                $rowTemplate,
                $ro->uraian ?: $ro->kegiatan?->namaCapaian() ?: '…',
                $ro->volume_ro ?: '…',
                $progres
            );
        })->implode('');

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

    /** Macro kolom pada baris isi tabel proksi -- baris (<w:tr>) yang memuatnya digandakan. */
    private const MACRO_BARIS_PROKSI = ['{{proksi_uraian}}', '{{proksi_target}}', '{{proksi_realisasi}}'];

    /**
     * Gandakan baris isi tabel proksi (baris <w:tr> yang memuat {{proksi_uraian}}/
     * {{proksi_target}}/{{proksi_realisasi}}) sekali per IndikatorProksi -- TANPA baris
     * penanda tersendiri seperti {{ro_row}}, supaya cukup menulis ketiga macro di satu
     * baris tabel biasa. Tidak melakukan apa-apa bila template tidak memuat macro tsb.
     */
    private function gandakanBarisProksi(string $xml, Collection $proksiIku): string
    {
        $posisi = collect(self::MACRO_BARIS_PROKSI)->map(fn ($m) => strpos($xml, $m))->filter(fn ($p) => $p !== false);
        if ($posisi->isEmpty()) {
            return $xml;
        }

        [$awal, $akhir] = $this->elementBounds($xml, $posisi->min(), 'tr');
        $rowTemplate = substr($xml, $awal, $akhir - $awal);

        $baris = $proksiIku->map(fn ($proksi) => str_replace(
            self::MACRO_BARIS_PROKSI,
            array_map(fn ($v) => htmlspecialchars(filled($v) ? $v : '…', ENT_QUOTES | ENT_XML1), [$proksi->uraian, $proksi->target, $proksi->realisasi]),
            $rowTemplate
        ))->implode('');

        return substr($xml, 0, $awal).$baris.substr($xml, $akhir);
    }

    /**
     * Unit penanda blok: 'tr' bila paragraf penanda berdiri sendiri sebagai SATU-SATUNYA
     * isi baris tabel (gaya template bawaan: satu baris = satu penanda), 'p' bila
     * penanda hanyalah paragraf di dalam sel yang juga berisi konten lain (mis. tulisan
     * {{blok_proksi}} di tengah sel besar, di atas tabel proksi bersarang).
     */
    private function unitPenanda(string $xml, string $needle): string
    {
        $pos = strpos($xml, $needle);
        if ($pos === false) {
            return 'tr';
        }

        [$pAwal, $pAkhir] = $this->elementBounds($xml, $pos, 'p');
        $sebelum = substr($xml, 0, $pAwal);

        $tcAwal = $this->posisiTagTerakhir($sebelum, 'w:tc');
        $trAwal = $this->posisiTagTerakhir($sebelum, 'w:tr');
        if ($tcAwal === null || $trAwal === null || $trAwal > $tcAwal) {
            return 'p';
        }

        // Tidak ada paragraf/tabel lain sebelum penanda di sel ini, dan sel ini sel pertama barisnya.
        $isiSelSebelum = substr($sebelum, $tcAwal);
        $isiBarisSebelum = substr($sebelum, $trAwal, $tcAwal - $trAwal);
        $selKosongSebelum = $this->posisiTagTerakhir($isiSelSebelum, 'w:p') === null && ! str_contains($isiSelSebelum, '<w:tbl');
        $selPertama = $this->posisiTagTerakhir($isiBarisSebelum, 'w:tc') === null;

        // Sesudah paragraf penanda langsung tutup sel lalu tutup baris (satu sel saja).
        $sesudahLangsungTutup = (bool) preg_match('#^\s*</w:tc>\s*</w:tr>#', substr($xml, $pAkhir, 64));

        return $selKosongSebelum && $selPertama && $sesudahLangsungTutup ? 'tr' : 'p';
    }

    /**
     * Posisi kemunculan TERAKHIR tag pembuka <$tag> / <$tag ...> (bukan <w:pPr>, <w:trPr>,
     * <w:tcPr>, <w:proofErr>, dst. yang kebetulan berawalan sama).
     */
    private function posisiTagTerakhir(string $xml, string $tag): ?int
    {
        $kandidat = array_filter([strrpos($xml, "<{$tag}>"), strrpos($xml, "<{$tag} ")], fn ($p) => $p !== false);

        return $kandidat === [] ? null : max($kandidat);
    }

    /**
     * Sel tabel WAJIB diakhiri paragraf -- setelah blok bergaya 'p' dibuang/disisakan,
     * sel bisa berakhir dengan tabel bersarang atau kosong sama sekali, yang membuat
     * Word menolak/memulihkan dokumen. Sisipkan <w:p/> kosong di kasus tsb.
     */
    private function rapikanSelTabel(string $xml): string
    {
        return preg_replace(['#</w:tbl>(\s*)</w:tc>#', '#</w:tcPr>(\s*)</w:tc>#', '#<w:tc>(\s*)</w:tc>#'], ['</w:tbl><w:p/></w:tc>', '</w:tcPr><w:p/></w:tc>', '<w:tc><w:p/></w:tc>'], $xml) ?? $xml;
    }

    /**
     * {{bagian_2}} dan {{bagian_3}} SENGAJA dibiarkan sebagai kode mentah (tidak
     * disubstitusi) -- keduanya jadi penanda tempat Tim SAKIP menempelkan/menggabungkan
     * dokumen Bagian II dan III yang disusun terpisah di luar sistem.
     *
     * Isi tabel "Mengetahui/Kepala.../Notulis" bawaan template diisi TANPA SYARAT di
     * sini -- tabelnya sendiri (label + keempat macro ini) baru tercetak SAMA SEKALI
     * bila notula sudah disetujui Kepala (RF-44), dibuang TOTAL sebelum itu lewat
     * hapusBlokTtdMandiri() yang dipanggil dari generate(). Jadi tidak perlu lagi
     * kosongkan nilai per-macro seperti sebelumnya (bandingkan versi lama isiTtdKepala())
     * -- baik terisi atau tidak, "…" placeholder tidak akan pernah tercetak karena
     * seluruh tabelnya sudah tidak ada.
     *
     * 'tanggal' cuma TANGGAL, tanpa nama hari (lihat Notula::tanggalTtd()) -- mengikuti
     * hari_tanggal RAPAT yang sudah diisi Tim SAKIP di Detail Rapat, BUKAN tanggal klik
     * "Setuju" di sistem (Notula::disetujui_pada), yang bisa berbeda dari tanggal
     * rapatnya sendiri. Konsisten dengan $tanggal di blok TTD dokumen gabungan, lihat
     * NotulaService::dataNotulaUtuh().
     */
    private function isiPenutup(TemplateProcessor $p, Notula $notula): void
    {
        $this->set($p, 'kotaTtd', $notula->kota_ttd);
        $this->set($p, 'tanggal', $notula->tanggalTtd());
        $this->set($p, 'namaKepala', $notula->kepala_satker);
        $this->set($p, 'namaNotulis', $notula->notulis);
    }

    /**
     * Buang tabel "Mengetahui/Kepala.../Notulis" bawaan template -- satu-satunya
     * <w:tbl> di dokumen yang memuat label "Kepala Badan Pusat Statistik Kabupaten
     * Buton Utara" (dicari lewat teks label itu, BUKAN posisi relatif ke {{bagian_3}},
     * supaya tetap kena walau template disunting ulang Tim SAKIP selama labelnya
     * sendiri tidak diganti), berikut satu <w:p/> kosong tepat sebelumnya (pemisah
     * antar-paragraf {{bagian_3}} dan tabel ini di template asli).
     *
     * Dipanggil dari generate() bila $sertakanBlokTtdMandiri false (dokumen gabungan,
     * lihat catatan parameter itu di generate()) ATAU notula BELUM disetujui Kepala
     * (RF-44) -- tabel TTD ini (baik untuk unduhan Bagian I sendirian maupun dokumen
     * gabungan) baru tercetak SAMA SEKALI setelah disetujui, supaya draf tidak
     * menampilkan tempat TTD seolah tinggal ditandatangani padahal baru isian form
     * Detail Rapat.
     *
     * Tidak melakukan apa-apa (bukan melempar exception) bila label tsb tidak
     * ditemukan -- template kustom Tim SAKIP boleh saja tidak/sudah tidak punya blok
     * ini sama sekali.
     */
    private function hapusBlokTtdMandiri(TemplateProcessor $p): void
    {
        $xml = $this->getMainPart($p);

        // Label dicocokkan dari TEKS polos tabel (tag & pindah baris diabaikan), bukan string
        // XML persis -- di template unggahan, Word bisa memecah label jadi beberapa run atau
        // menaruh <w:br/> di antara "Statistik" dan "Kabupaten", mis.
        // "...Statistik</w:t></w:r><w:r><w:br/><w:t>Kab</w:t></w:r><w:r><w:t>upaten...".
        // Diambil tabel TERAKHIR yang cocok -- tabel TTD selalu di ujung dokumen.
        $tblStart = null;
        $tblEnd = null;
        foreach ($this->tabelTingkatAtas($xml) as [$awal, $akhir]) {
            $teks = preg_replace('/\s+/', ' ', strip_tags(str_replace(['<w:br/>', '</w:p>'], ' ', substr($xml, $awal, $akhir - $awal))));
            if (str_contains($teks, 'Kepala Badan Pusat Statistik') && str_contains($teks, 'Kabupaten Buton Utara')) {
                [$tblStart, $tblEnd] = [$awal, $akhir];
            }
        }
        if ($tblStart === null) {
            return;
        }

        $before = substr($xml, 0, $tblStart);
        if (str_ends_with($before, '<w:p/>')) {
            $before = substr($before, 0, -strlen('<w:p/>'));
        }

        $this->setMainPart($p, $before.substr($xml, $tblEnd));
    }

    /**
     * Posisi [awal, akhir] setiap <w:tbl> TINGKAT ATAS (bukan tabel bersarang di dalam sel).
     *
     * @return list<array{int, int}>
     */
    private function tabelTingkatAtas(string $xml): array
    {
        preg_match_all('/<w:tbl>|<\/w:tbl>/', $xml, $cocok, PREG_OFFSET_CAPTURE);

        $hasil = [];
        $kedalaman = 0;
        $awal = 0;
        foreach ($cocok[0] as [$tag, $pos]) {
            if ($tag === '<w:tbl>') {
                if ($kedalaman++ === 0) {
                    $awal = $pos;
                }
            } elseif (--$kedalaman === 0) {
                $hasil[] = [$awal, $pos + strlen('</w:tbl>')];
            }
        }

        return $hasil;
    }

    // ------------------------------------------------------------------
    // Utilitas manipulasi XML mentah -- dipakai untuk hal yang TIDAK didukung
    // TemplateProcessor bawaan: menggandakan satu blok/baris tabel dengan isi yang
    // berbeda tiap salinan sebelum digabung kembali ke dokumen utama (lihat catatan
    // kelas di atas). tempDocumentMainPart bersifat protected di PhpWord, jadi diakses
    // lewat Reflection -- dipakai murni untuk baca/tulis mentah, tidak mengubah
    // perilaku TemplateProcessor itu sendiri.
    // ------------------------------------------------------------------

    /**
     * Bangun TemplateProcessor dengan delimiter {{...}} SUDAH aktif SEBELUM konstruktornya
     * jalan -- konstruktor PhpWord (lewat readPartWithRels()) memanggil fixBrokenMacros()
     * SAAT ITU JUGA untuk menyatukan run XML yang terpecah (Word kerap memecah satu macro
     * jadi beberapa <w:r> begitu dokumennya disunting ulang & disimpan, mis. commit "Rumus
     * pecahan bersusun asli di docx, rapikan tabel Realisasi RO"), memakai delimiter yang
     * SEDANG AKTIF saat konstruksi. Kode lama memanggil setMacroChars('{{','}}') SETELAH
     * `new TemplateProcessor(...)`, jadi fixBrokenMacros() konstruktor itu masih memakai
     * default ${...} bawaan PhpWord -- macro {{...}} yang kebetulan terpecah runnya TIDAK
     * ikut diperbaiki, dan pencarian string literal di splitOnMarkers() (mis. {{iku_blok}})
     * gagal walau macro itu masih ADA di dokumen, cuma terpecah. Delimiter diset lewat
     * Reflection ke properti STATIC TemplateProcessor (bukan lewat instance) karena kita
     * belum punya instance-nya di titik ini -- justru itu masalahnya.
     */
    private function newTemplateProcessor(string $templatePath): TemplateProcessor
    {
        foreach (['macroOpeningChars' => '{{', 'macroClosingChars' => '}}'] as $properti => $nilai) {
            $ref = new ReflectionProperty(TemplateProcessor::class, $properti);
            $ref->setAccessible(true);
            $ref->setValue(null, $nilai);
        }

        $processor = new TemplateProcessor($templatePath);

        // Word memecah macro yang ditandai salah eja (mis. "{{" | "tahun" | "}} ")
        // jadi beberapa run; saat PhpWord menyatukannya, spasi di ekor run terakhir
        // pindah ke <w:t> run PERTAMA yang tidak bertanda xml:space="preserve" --
        // Word/LibreOffice lalu membuang spasinya ("IKU 2026dapat dilihat").
        // Semua <w:t> polos ditandai preserve; tampilan teks lain tidak berubah.
        $this->setMainPart($processor, str_replace('<w:t>', '<w:t xml:space="preserve">', $this->getMainPart($processor)));

        return $processor;
    }

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
     * Validasi STRUKTUR template (bukan isi datanya) -- dipanggil App\Livewire\TemplateNotula::unggah()
     * SEBELUM berkas yang diunggah Tim SAKIP diaktifkan sebagai template Bagian I. Mencegah berkas yang
     * belum lengkap penanda bloknya (mis. lupa menyisipkan {{/iku_blok}} saat menyunting ulang di Word)
     * baru ketahuan rusak saat generate() dipanggil (splitOnMarkers() melempar RuntimeException, lihat
     * catatan kelas di atas) -- yang berarti SELURUH halaman Kompilasi Notula ambruk 500 bagi SEMUA
     * pemakai berikutnya. Dengan validasi ini, kesalahannya ketahuan & bisa diperbaiki SAAT unggah.
     *
     * Mengecek SEMUA pasangan penanda blok sekaligus lewat cariBlokAman() (bukan berhenti di kesalahan
     * pertama seperti splitOnMarkers()) supaya Tim SAKIP bisa memperbaiki semuanya dalam satu kali coba.
     *
     * @throws RuntimeException berisi daftar SEMUA masalah yang ditemukan, satu per baris
     */
    public function validasiStrukturTemplate(string $templatePath): void
    {
        try {
            $processor = $this->newTemplateProcessor($templatePath);
            $processor->setMacroChars('{{', '}}');
            $xml = $this->getMainPart($processor);
        } catch (Throwable $e) {
            throw new RuntimeException("Berkas bukan .docx yang valid atau rusak: {$e->getMessage()}");
        }

        $masalah = [];

        $ikuBlok = $this->cariBlokAman($xml, '{{iku_blok}}', '{{/iku_blok}}', 'p', $masalah);

        // Blok kondisional & ro_row ada DI DALAM iku_blok -- hanya diperiksa kalau iku_blok sendiri
        // berhasil ditemukan (kalau tidak, isinya tidak diketahui, laporan di atas sudah cukup).
        if ($ikuBlok !== null) {
            // blok_proksi OPSIONAL (template tanpa bagian proksi tetap sah), tapi bila ada
            // pasangan penandanya harus lengkap & baris isinya ada di dalamnya.
            if (str_contains($ikuBlok, '{{blok_proksi}}') || str_contains($ikuBlok, '{{/blok_proksi}}')) {
                $blokProksi = $this->cariBlokAman($ikuBlok, '{{blok_proksi}}', '{{/blok_proksi}}', $this->unitPenanda($ikuBlok, '{{blok_proksi}}'), $masalah);
                if ($blokProksi !== null && ! str_contains($blokProksi, '{{proksi_uraian}}')) {
                    $masalah[] = 'Baris isi tabel proksi ({{proksi_uraian}} | {{proksi_target}} | {{proksi_realisasi}}) tidak ditemukan di antara {{blok_proksi}} dan {{/blok_proksi}}.';
                }
            }
            // blok_berakhlak OPSIONAL (digantikan blok_proksi yang berlaku untuk semua IKU),
            // tapi bila masih ada pasangan penandanya harus lengkap.
            if (str_contains($ikuBlok, '{{blok_berakhlak}}') || str_contains($ikuBlok, '{{/blok_berakhlak}}')) {
                $this->cariBlokAman($ikuBlok, '{{blok_berakhlak}}', '{{/blok_berakhlak}}', 'tr', $masalah);
            }
            $blokRo = $this->cariBlokAman($ikuBlok, '{{blok_ro}}', '{{/blok_ro}}', $this->unitPenanda($ikuBlok, '{{blok_ro}}'), $masalah);
            $this->cariBlokAman($ikuBlok, '{{blok_formula}}', '{{/blok_formula}}', 'p', $masalah);

            if ($blokRo !== null) {
                $this->cariBlokAman($blokRo, '{{ro_row}}', '{{/ro_row}}', 'tr', $masalah);
            }
        }

        if ($masalah !== []) {
            throw new RuntimeException("Template tidak sesuai struktur yang dibutuhkan:\n- ".implode("\n- ", $masalah));
        }
    }

    /**
     * Macro yang DIISI generate() (lihat isiHeader()/isiSatuIku()/isiPenutup()/penanda
     * blok) -- di luar daftar ini, macro di template tidak akan pernah diganti dan
     * tercetak mentah "{{...}}" di notula.
     */
    private const MACRO_DIKENAL = [
        'tahun', 'triwulan_angka', 'triwulan_sebelumnya', 'nama_satker', 'agenda_pembahasan', 'hari_tanggal', 'waktu', 'tempat',
        'pimpinan_rapat', 'capaian_triwulanan_persen', 'capaian_pk_persen', 'lampiran_basis_data',
        'kotaTtd', 'tanggal', 'namaKepala', 'namaNotulis', 'bagian_2', 'bagian_3',
        'iku_blok', '/iku_blok', 'blok_proksi', '/blok_proksi', 'blok_berakhlak', '/blok_berakhlak',
        'blok_ro', '/blok_ro', 'blok_formula', '/blok_formula', 'ro_row', '/ro_row',
        'proksi_uraian', 'proksi_target', 'proksi_realisasi',
        'kode', 'indikator', 'sasaran', 'target_pk', 'target_tw', 'realisasi_tw', 'capaian_tw', 'capaian_pk',
        'analisis_capaian', 'kendala', 'solusi', 'rtl', 'pic_rtl',
        'batas_waktu_rtl', 'formula_capaian', 'dasar_hitung', 'bukti_realisasi', 'bukti_rtl_sebelumnya',
        'rtl_evaluasi', 'bukti_rtl_evaluasi', 'rtl_berikutnya', 'batas_waktu_rtl_berikutnya', 'penjelasan_lainnya', 'ro_uraian', 'ro_vol', 'ro_progres',
    ];

    /**
     * Macro {{...}} di template (isi utama, header & footer) yang TIDAK dikenali
     * generate() -- mis. salah ketik "{{tempat_rapat}}" atau bagian baru yang belum
     * ada datanya di sistem. Bukan galat (template tetap boleh dipakai), cukup jadi
     * PERINGATAN saat unggah supaya Tim SAKIP tahu bagian mana yang tidak akan terisi,
     * alih-alih baru ketahuan dari teks "{{...}}" mentah di notula.
     *
     * @return list<string>
     */
    public function macroTidakDikenal(string $templatePath): array
    {
        try {
            $processor = $this->newTemplateProcessor($templatePath);
            $variabel = $processor->getVariables();
            // Delimiter STATIC dikembalikan ke bawaan PhpWord, sama seperti generate().
            $processor->setMacroChars('${', '}');
        } catch (Throwable) {
            return [];
        }

        return array_values(array_diff(array_unique(array_map('trim', $variabel)), self::MACRO_DIKENAL));
    }

    /**
     * Versi splitOnMarkers() yang TIDAK melempar exception -- mengembalikan null & menambahkan
     * pesannya ke $masalah bila penanda tidak ditemukan/rusak, dipakai KHUSUS oleh
     * validasiStrukturTemplate() supaya semua masalah bisa dikumpulkan sekaligus.
     */
    private function cariBlokAman(string $xml, string $openNeedle, string $closeNeedle, string $unit, array &$masalah): ?string
    {
        try {
            [, $inner] = $this->splitOnMarkers($xml, $openNeedle, $closeNeedle, $unit);

            return $inner;
        } catch (RuntimeException $e) {
            $masalah[] = $e->getMessage();

            return null;
        }
    }

    /**
     * Cari elemen ('p' = <w:p>, 'tr' = <w:tr>) yang MEMUAT posisi $pos, kembalikan
     * [awal, akhir] byte offset elemen tsb (termasuk tag pembuka & penutupnya).
     */
    private function elementBounds(string $xml, int $pos, string $unit): array
    {
        $tag = $unit === 'p' ? 'w:p' : 'w:tr';
        $closeTag = "</{$tag}>";
        // Tag pembuka dicari persis (<w:p> / <w:p ...>), BUKAN awalan "<w:p" yang juga
        // cocok dengan <w:pPr>/<w:proofErr>/<w:trPr> milik elemen yang sama.
        $start = $this->posisiTagTerakhir(substr($xml, 0, $pos), $tag);
        $endTagPos = strpos($xml, $closeTag, $pos);

        if ($start === null || $endTagPos === false) {
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
        $inner = substr($xml, $openEnd, $closeStart - $openEnd);

        // Penanda pembuka & penutup harus SETINGKAT (sama-sama di tabel luar, atau sama-sama
        // di sel/tabel bersarang yang sama). Kalau mis. {{/blok_ro}} diletakkan di baris TABEL
        // BERSARANG sementara {{blok_ro}} di baris tabel luar, membuang/menyalin isi di
        // antaranya memotong tag tabel di tengah -- document.xml jadi rusak (tidak bisa dibuka).
        foreach (['w:tbl', 'w:tr', 'w:tc'] as $tag) {
            if (preg_match_all('/<'.$tag.'[ >]/', $inner) !== substr_count($inner, "</{$tag}>")) {
                throw new RuntimeException("Penanda {$openNeedle} dan {$closeNeedle} tidak setingkat (salah satunya ada di dalam tabel/sel bersarang) -- letakkan keduanya pada tingkat tabel yang sama.");
            }
        }

        return [substr($xml, 0, $openStart), $inner, substr($xml, $closeEnd)];
    }
}
