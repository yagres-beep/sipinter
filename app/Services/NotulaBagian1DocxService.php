<?php

namespace App\Services;

use App\Models\Notula;
use App\Models\PengaturanCapaian;
use App\Support\RumusMarkup;
use Illuminate\Support\Collection;
use PhpOffice\PhpWord\TemplateProcessor;
use ReflectionProperty;
use RuntimeException;

/**
 * Isi template Bagian I bervariabel {{...}} (template_notula/SIPINTER_Template_Bagian_I_Mesin.docx)
 * dari data yang sama dipakai jalur HTML/PDF (lihat NotulaService::kumpulkanDataBagianSatu()) --
 * supaya Tim SAKIP bisa mengunduh Bagian I sebagai .docx asli (bisa disunting/digabung manual
 * dengan Bagian II/III yang disusun di luar sistem), persis struktur dokumen resmi.
 *
 * Template-nya berisi SATU blok {{iku_blok}}...{{/iku_blok}} yang DIGANDAKAN di sini sekali per
 * Master IKU (RF baru) -- BUKAN satu macro bernama per kode IKU seperti versi lama. Ini supaya
 * penomoran/kode IKU boleh berubah kapan pun (mis. lewat impor Master IKU dari Excel) tanpa harus
 * menulis ulang template-nya; satu-satunya alasan template perlu disunting lagi adalah bila
 * STRUKTUR dokumen resmi sendiri berubah dari pusat. Blok RO ({{ro_row}}...{{/ro_row}}) di dalamnya
 * ikut digandakan per Kegiatan pada IKU tsb (Kegiatan::rincian_output/volume_ro/progres_persen --
 * sumber yang sama dipakai jalur PDF, lihat notula-bagian1-konten.blade.php), dan salah satu dari
 * tiga varian {{blok_sakip}}/{{blok_berakhlak}}/{{blok_ro}} dipilih sesuai teks indikator, sama
 * seperti deteksi SAKIP/BerAKHLAK pada jalur PDF.
 */
class NotulaBagian1DocxService
{
    private const TEMPLATE_PATH = 'template_notula/SIPINTER_Template_Bagian_I_Mesin.docx';

    public function __construct(private NotulaService $notulaService) {}

    public function generate(Notula $notula, string $outputPath): string
    {
        $templatePath = base_path(self::TEMPLATE_PATH);
        abort_unless(file_exists($templatePath), 404, 'Template Bagian I (mesin) belum tersedia.');

        $data = $this->notulaService->kumpulkanDataBagianSatu($notula);

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
     * Satu nilai teks bebas -- newline TETAP newline asli (TemplateProcessor::setValue
     * otomatis mengubahnya jadi <w:br/> lewat replaceCarriageReturns(), sudah diverifikasi
     * lewat smoke test manual), null jadi placeholder "…" sesuai konvensi yang sama
     * dipakai jalur HTML (notula-bagian1-konten.blade.php).
     */
    private function set(TemplateProcessor $p, string $nama, ?string $nilai): void
    {
        $bersih = $nilai !== null ? trim($nilai) : '';
        $p->setValue($nama, $bersih !== '' ? $bersih : '…', -1);
    }

    /**
     * Susun daftar bernomor "1. ...\n2. ..." dari kumpulan teks -- dipakai untuk daftar
     * kegiatan/kendala/solusi/RTL supaya formatnya sama seperti jalur PDF (<ol> bernomor).
     */
    private function daftarBernomor(iterable $items): ?string
    {
        $daftar = collect($items)->values();
        if ($daftar->isEmpty()) {
            return null;
        }

        return $daftar->map(fn ($teks, $i) => ($i + 1).'. '.$teks)->implode("\n");
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

        $isiBaru = $potongan->implode('<w:p/>');

        $this->setMainPart($processor, $before.$isiBaru.$after);
    }

    /**
     * Isi SATU salinan blok per-IKU: pilih varian SAKIP/BerAKHLAK/RO yang sesuai (persis
     * deteksi teks indikator yang dipakai notula-bagian1-konten.blade.php), gandakan baris
     * RO per Kegiatan, lalu isi seluruh macro sisa lewat TemplateProcessor sekali pakai.
     */
    private function isiSatuIku(TemplateProcessor $sub, string $blokTemplate, $iku, array $data): string
    {
        $kode = $iku->kode;
        $rekap = $data['rekapPerIku']->get($iku->id, []);
        $capaian = $data['capaianPerIku']->get($iku->id);
        $kegiatanIku = $data['kegiatanPerIku']->get($iku->id, collect());
        $kendalaSolusiTriwulan = $data['kendalaSolusiPerIku']->get($iku->id, collect());
        $rtlIku = $data['rtlPerIku']->get($iku->id, collect());
        $rtlSebelumnyaIku = $data['rtlSebelumnyaPerIku']->get($iku->id, collect());
        $linkFolder = $data['linkFolderPerIku'][$iku->id] ?? null;
        $linkFolderTwSebelumnya = $data['linkFolderTwSebelumnyaPerIku'][$iku->id] ?? null;

        // Deteksi SAKIP/BerAKHLAK dari TEKS indikator (bukan kode -- penomoran kode IKU
        // beda tiap instansi & bisa berubah), sama persis dengan notula-bagian1-konten.blade.php.
        $indikatorLower = mb_strtolower($iku->indikator);
        $isSakip = str_contains($indikatorLower, 'sakip');
        $isBerakhlak = str_contains($indikatorLower, 'berakhlak');
        $tampilkanRo = ! $isSakip && ! $isBerakhlak && empty($rekap['realisasi'] ?? null);

        $xml = $blokTemplate;
        $xml = $this->resolveBlokKondisional($xml, 'blok_sakip', $isSakip);
        $xml = $this->resolveBlokKondisional($xml, 'blok_berakhlak', $isBerakhlak);
        $xml = $this->resolveBlokKondisional($xml, 'blok_ro', $tampilkanRo);
        if ($tampilkanRo) {
            $xml = $this->gandakanBarisRo($xml, $kegiatanIku);
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
        $analisisLengkap = $analisis !== '' && $daftarKegiatan !== null
            ? $analisis."\n\n".$daftarKegiatan
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
        $picRtl = $rtlIku->pluck('pic')->filter()->unique()->implode(', ');
        $batasWaktuRtl = $rtlIku->pluck('batas_waktu')->filter()->sort()->last();
        $this->set($sub, 'rtl', $rtlTeks);
        $this->set($sub, 'pic_rtl', $picRtl !== '' ? $picRtl : null);
        $this->set($sub, 'batas_waktu_rtl', $batasWaktuRtl?->translatedFormat('F Y'));

        // [[a|b]] (pecahan bersusun di PDF, lihat App\Support\RumusMarkup) diratakan
        // jadi notasi biasa "a/b" -- .docx tidak mendukung pecahan bersusun lewat
        // penggantian teks biasa (TemplateProcessor::setValue).
        $dasarHitungTeks = RumusMarkup::keTeksPolos($iku->dasar_hitung);
        $dasarHitung = trim(($dasarHitungTeks ?: '').($iku->basis_data ? ' Basis Data: '.$iku->basis_data : ''));
        $this->set($sub, 'dasar_hitung', $dasarHitung !== '' ? $dasarHitung : null);
        $this->set($sub, 'bukti_realisasi', $linkFolder);
        $this->set($sub, 'bukti_rtl_sebelumnya', $rtlSebelumnyaIku->isNotEmpty() ? $linkFolderTwSebelumnya : null);
        $this->set($sub, 'penjelasan_lainnya', $capaian?->catatan);

        return $this->getMainPart($sub);
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
     * Volume RO/Progres) sekali per Kegiatan pada IKU ini -- sumber data yang SAMA dipakai jalur
     * PDF (lihat notula-bagian1-konten.blade.php), bukan katalog kode RO tetap seperti versi lama.
     * Kosong (belum ada Kegiatan) -> satu baris placeholder "…", sama seperti pola @empty Blade.
     */
    private function gandakanBarisRo(string $xml, Collection $kegiatanIku): string
    {
        [$before, $rowTemplate, $after] = $this->splitOnMarkers($xml, '{{ro_row}}', '{{/ro_row}}', 'tr');

        if ($kegiatanIku->isEmpty()) {
            $baris = $this->isiBarisRo($rowTemplate, '…', '…', '…');
        } else {
            $baris = $kegiatanIku->map(function ($kegiatan) use ($rowTemplate) {
                $progres = $kegiatan->progres_persen !== null
                    ? number_format((float) $kegiatan->progres_persen, 2, ',', '.').'%'
                    : '…';

                return $this->isiBarisRo(
                    $rowTemplate,
                    $kegiatan->rincian_output ?: $kegiatan->uraian_kegiatan ?: '…',
                    $kegiatan->volume_ro ?: '…',
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
