<?php

namespace App\Services;

use App\Models\Notula;
use App\Models\PengaturanCapaian;
use App\Support\RumusMarkup;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Isi template Bagian I bervariabel {{...}} (template_notula/SIPINTER_Template_Bagian_I_Mesin.docx)
 * dari data yang sama dipakai jalur HTML/PDF (lihat NotulaService::kumpulkanDataBagianSatu()) --
 * supaya Tim SAKIP bisa mengunduh Bagian I sebagai .docx asli (bisa disunting/digabung manual
 * dengan Bagian II/III yang disusun di luar sistem), persis struktur dokumen resmi.
 *
 * RO (Rincian Output) sengaja TIDAK diisi otomatis -- katalog kode RO resmi (dibakukan sebagai
 * teks tetap langsung di dalam template) tidak terhubung ke data Kegiatan bebas-teks yang dipakai
 * sistem, dan dokumen resmi sendiri menandai bagian ini opsional ("Hanya terisi jika belum ada
 * realisasi IKU ... dapat dihapuskan") -- jadi sel Realisasi Volume RO/Progres selalu dikosongkan,
 * siap diisi atau dihapus manual oleh Tim SAKIP di Word.
 */
class NotulaBagian1DocxService
{
    private const TEMPLATE_PATH = 'template_notula/SIPINTER_Template_Bagian_I_Mesin.docx';

    /**
     * Kode RO per IKU -- HARUS tetap sinkron dengan baris literal yang dibakukan di dalam
     * template .docx (lihat scratchpad buat_bagian1_variabel.py saat template dibuat) -- dipakai
     * hanya untuk tahu token variabel mana yang perlu dikosongkan, bukan untuk menyusun teksnya
     * (teks RO sudah tetap/fixed di dalam template).
     *
     * @var array<string, list<string>>
     */
    private const RO_PER_IKU = [
        '1111' => ['2905 BMA 004', '2905 BMA 005'],
        '1131' => ['2906 BMA 005', '2906 BMA 006'],
        '1151' => ['2907 BMA 006', '2907 BMA 008', '2907 BMA 009'],
        '1211' => ['2909 BMA 005', '2909 BMA 006', '2910 BMA 007', '2910 BMA 008', '8130 BMA 005', '8130 BMA 007', '8130 BMA 008'],
        '1221' => ['8131 BMA 005'],
        '1231' => ['2904 BMA 006'],
        '1311' => ['2902 BMA 004', '2902 BMA 006', '2900 BMA 005', '2901 CAN 004', '2902 FAN ZZ1'],
        '1331' => ['2903 BMA 009', '2903 QMA 006', '2903 BMA 007', '2903 BMA 008'],
        '1351' => ['2908 BMA 004', '2900 QMA 006', '2896 QMA 006'],
        '1411' => ['2899 BMA 006'],
        '1412' => ['2898 BMA 007'],
        '1413' => ['2896 BMA 004'],
        '2141' => ['2907 UBB 006'],
        '2511' => ['2897 QDB 003', '2897 BMA 006'],
        '2711' => ['2897 BMA 004'],
    ];

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

        $this->isiHeader($processor, $notula, $data);
        $this->isiPerIku($processor, $data);
        $this->kosongkanRo($processor);
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
        // seluruh IKU pada triwulan yang sama) -- lihat row_lampiran_basis_data() di
        // scratchpad buat_bagian1_variabel.py.
        $this->set($p, 'lampiran_basis_data', $notula->link_lampiran_basis_data);
    }

    private function isiPerIku(TemplateProcessor $p, array $data): void
    {
        foreach ($data['sasaranPerIku']->flatten() as $iku) {
            $kode = $iku->kode;
            $rekap = $data['rekapPerIku']->get($iku->id, []);
            $capaian = $data['capaianPerIku']->get($iku->id);
            $kegiatanIku = $data['kegiatanPerIku']->get($iku->id, collect());
            $kendalaSolusiTriwulan = $data['kendalaSolusiPerIku']->get($iku->id, collect());
            $rtlIku = $data['rtlPerIku']->get($iku->id, collect());
            $rtlSebelumnyaIku = $data['rtlSebelumnyaPerIku']->get($iku->id, collect());
            $linkFolder = $data['linkFolderPerIku'][$iku->id] ?? null;
            $linkFolderTwSebelumnya = $data['linkFolderTwSebelumnyaPerIku'][$iku->id] ?? null;

            $this->set($p, "sasaran_{$kode}", $iku->sasaran);
            $this->set($p, "target_pk_{$kode}", PengaturanCapaian::formatAngka($rekap['target_pk'] ?? null));
            $this->set($p, "target_tw_{$kode}", PengaturanCapaian::formatAngka($rekap['target_tw'] ?? null));
            $this->set($p, "realisasi_tw_{$kode}", PengaturanCapaian::formatAngka($rekap['realisasi'] ?? null));
            $this->set($p, "capaian_tw_{$kode}", PengaturanCapaian::formatPersen($rekap['capaian_tw'] ?? null));
            $this->set($p, "capaian_pk_{$kode}", PengaturanCapaian::formatPersen($rekap['capaian_pk'] ?? null));

            // Analisis Capaian Kinerja: narasi bebas dari Tim SAKIP, DIIKUTI daftar
            // bernomor kegiatan pendukung IKU ini pada triwulan berjalan (RF-37) --
            // sama seperti pola "...disamping itu, terdapat beberapa kegiatan..." yang
            // sudah dipakai Tim SAKIP di jalur PDF.
            $analisis = trim((string) $capaian?->analisis_capaian);
            $daftarKegiatan = $this->daftarBernomor($kegiatanIku->pluck('uraian_kegiatan')->filter());
            $analisisLengkap = $analisis !== '' && $daftarKegiatan !== null
                ? $analisis."\n\n".$daftarKegiatan
                : ($analisis !== '' ? $analisis : $daftarKegiatan);
            $this->set($p, "analisis_capaian_{$kode}", $analisisLengkap);

            if ($kode === '3241') {
                $this->set($p, 'target_proksi_3241', null);
                $this->set($p, 'realisasi_proksi_3241', null);
            }

            // Kendala & Solusi sebagai DUA variabel terpisah (mengikuti dua baris terpisah
            // pada dokumen resmi), KUMULATIF TW1..TW berjalan (RF-28), disusun sebagai
            // daftar bernomor per indikator -- sama seperti jalur PDF.
            $semuaKendalaSolusi = $kendalaSolusiTriwulan->flatten(1);
            $this->set($p, "kendala_{$kode}", $this->daftarBernomor($semuaKendalaSolusi->pluck('kendala')->filter()));
            $this->set($p, "solusi_{$kode}", $this->daftarBernomor($semuaKendalaSolusi->pluck('solusi')->filter()));

            $rtlTeks = $this->daftarBernomor($rtlIku->pluck('rtl_teks')->filter());
            $picRtl = $rtlIku->pluck('pic')->filter()->unique()->implode(', ');
            $batasWaktuRtl = $rtlIku->pluck('batas_waktu')->filter()->sort()->last();
            $this->set($p, "rtl_{$kode}", $rtlTeks);
            $this->set($p, "pic_rtl_{$kode}", $picRtl !== '' ? $picRtl : null);
            $this->set($p, "batas_waktu_rtl_{$kode}", $batasWaktuRtl?->translatedFormat('F Y'));

            // [[a|b]] (pecahan bersusun di PDF, lihat App\Support\RumusMarkup) diratakan
            // jadi notasi biasa "a/b" -- .docx tidak mendukung pecahan bersusun lewat
            // penggantian teks biasa (TemplateProcessor::setValue).
            $dasarHitungTeks = RumusMarkup::keTeksPolos($iku->dasar_hitung);
            $dasarHitung = trim(($dasarHitungTeks ?: '').($iku->basis_data ? ' Basis Data: '.$iku->basis_data : ''));
            $this->set($p, "dasar_hitung_{$kode}", $dasarHitung !== '' ? $dasarHitung : null);
            $this->set($p, "bukti_realisasi_{$kode}", $linkFolder);
            $this->set($p, "bukti_rtl_sebelumnya_{$kode}", $rtlSebelumnyaIku->isNotEmpty() ? $linkFolderTwSebelumnya : null);
            $this->set($p, "penjelasan_lainnya_{$kode}", $capaian?->catatan);
        }
    }

    private function kosongkanRo(TemplateProcessor $p): void
    {
        foreach (self::RO_PER_IKU as $roList) {
            foreach ($roList as $kodeRo) {
                $token = strtolower(str_replace(' ', '', $kodeRo));
                $p->setValue('ro_vol_'.$token, '', -1);
                $p->setValue('ro_progres_'.$token, '', -1);
            }
        }
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
}
