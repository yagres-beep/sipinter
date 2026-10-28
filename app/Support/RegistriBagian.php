<?php

namespace App\Support;

use App\Models\BagianKustom;
use App\Models\PengaturanBagianBawaan;

/**
 * Registri SELURUH bagian SIPINTER untuk pusat pengelolaan (Data Master > Pengelolaan
 * Bagian). Bagian bawaan didaftarkan di kode dengan kode tetap; Bagian Kustom dibaca
 * dari database (BagianKustom).
 *
 * Jenis:
 * - A: dinamis penuh (struktur, hak aksi, bukti, notula diatur dari layar) — Bagian Kustom.
 * - B: konfigurasi terbatas (label menu, judul, petunjuk) — bagian bawaan Isian Kegiatan.
 * - C: logika khusus, tidak bisa dikonfigurasi dari layar — hanya didokumentasikan.
 *
 * Hak akses dan alur bagian bawaan TIDAK dibaca dari sini: tetap ditegakkan oleh gerbang
 * rute (middleware role:) dan pemeriksaan di komponennya. Teks di sini hanya uraian untuk
 * Tim SAKIP, diselaraskan dengan kode (lihat _docs/audit/AUDIT_EDITOR_BAGIAN_HAK_AKSES_*.md).
 */
final class RegistriBagian
{
    public const JENIS = [
        'A' => 'Dinamis penuh',
        'B' => 'Konfigurasi terbatas',
        'C' => 'Logika khusus',
    ];

    /** Alur capaian Isian Kegiatan (Capaian::catatStatus()), dipakai bagian 1–5 & Bagian Kustom di Isian Kegiatan. */
    public const ALUR_ISIAN_KEGIATAN = 'Draft → Diajukan (Ketua Tim) → Sedang ditangani (Tim SAKIP) → Diverifikasi atau Dikembalikan (Tim SAKIP) → Disetujui (notula final). Isian terkunci selama sedang ditangani, diverifikasi, dan disetujui; dikembalikan = bisa diperbaiki lalu diajukan ulang.';

    /**
     * Bagian bawaan. Kunci = kode tetap (tidak pernah berubah walau label diganti).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function bawaan(): array
    {
        $hakIsian = [
            BagianKustom::PERAN_KETUA_TIM => 'Lihat, buat, ubah selama belum terkunci, hapus butir yang belum terverifikasi, unggah/ganti bukti, ajukan',
            BagianKustom::PERAN_TIM_SAKIP => 'Lihat lewat menu Verifikasi; verifikasi (Sesuai/Tidak Sesuai), kembalikan, isi analisis',
            BagianKustom::PERAN_KEPALA => 'Lihat ringkasan di Dasbor dan Notula; tidak mengubah',
        ];

        return [
            'iku' => [
                'n' => '1', 'label' => 'IKU', 'judul' => 'IKU', 'jenis' => 'B', 'menu' => 'Isian Kegiatan',
                'butuh_iku' => false, 'periode' => 'Tahun & bulan', 'bukti' => 'Tidak', 'notula' => 'Bagian I (kode & indikator)',
                'tabel' => 'master_iku, iku_penugasan, capaian_tahunan',
                'hak' => [
                    BagianKustom::PERAN_KETUA_TIM => 'Pilih IKU untuk diisi',
                    BagianKustom::PERAN_TIM_SAKIP => 'Kelola daftar IKU, penugasan, dan target di Data Master',
                    BagianKustom::PERAN_KEPALA => 'Lihat di Dasbor Kinerja',
                ],
                'alur' => self::ALUR_ISIAN_KEGIATAN,
                'batasan' => 'Daftar IKU, kode, dan relasinya ke kegiatan tetap dikelola di tab Master IKU dan Target Tahunan. Yang bisa diganti di sini hanya label tampilan.',
            ],
            'kegiatan' => [
                'n' => '2', 'label' => 'Kegiatan', 'judul' => 'Kegiatan (bisa lebih dari satu)', 'jenis' => 'B', 'menu' => 'Isian Kegiatan',
                'butuh_iku' => true, 'periode' => 'Bulan', 'bukti' => 'Ya, folder Drive per kegiatan/tahapan', 'notula' => 'Bagian I',
                'tabel' => 'kegiatan, berkas, rincian_output',
                'hak' => $hakIsian,
                'alur' => self::ALUR_ISIAN_KEGIATAN,
                'batasan' => 'Kolom, tahapan survei/sensus, rincian n, dan struktur folder Drive diatur kode (struktur folder di tab Struktur Folder). Label tidak mengubah nama folder Drive maupun teks notula.',
            ],
            'kendala_solusi' => [
                'n' => '3', 'label' => 'Kendala & Solusi', 'judul' => 'Kendala & Solusi', 'jenis' => 'B', 'menu' => 'Isian Kegiatan',
                'butuh_iku' => true, 'periode' => 'Bulan', 'bukti' => 'Tidak', 'notula' => 'Bagian I',
                'tabel' => 'kendala_solusi',
                'hak' => $hakIsian,
                'alur' => self::ALUR_ISIAN_KEGIATAN.' Tiap pasang kendala ↔ solusi diverifikasi Sesuai/Tidak Sesuai.',
                'batasan' => 'Pasangan kendala ↔ solusi dan teks notula/DOCX tetap dari kode.',
            ],
            'evaluasi_rtl' => [
                'n' => '4', 'label' => 'Evaluasi RTL', 'judul' => 'Evaluasi RTL Triwulan Sebelumnya', 'jenis' => 'B', 'menu' => 'Isian Kegiatan',
                'butuh_iku' => true, 'periode' => 'Triwulan sebelumnya', 'bukti' => 'Ya, ditaut ke bukti kegiatan', 'notula' => 'Bagian I/II',
                'tabel' => 'rtl_evaluasi, salinan_bukti_rtl',
                'hak' => $hakIsian,
                'alur' => self::ALUR_ISIAN_KEGIATAN.' Poin RTL diverifikasi per butir.',
                'batasan' => 'Relasi poin RTL ↔ kegiatan ↔ bukti memakai ID dan tetap dari kode.',
            ],
            'rtl_triwulan' => [
                'n' => '5', 'label' => 'RTL', 'judul' => 'Rencana Tindak Lanjut', 'jenis' => 'B', 'menu' => 'Isian Kegiatan',
                'butuh_iku' => true, 'periode' => 'Hanya bulan ke-3 triwulan', 'bukti' => 'Tidak', 'notula' => 'Bagian I',
                'tabel' => 'rtl_evaluasi',
                'hak' => $hakIsian,
                'alur' => self::ALUR_ISIAN_KEGIATAN.' PIC otomatis dari tim IKU.',
                'batasan' => 'Nama triwulan berikutnya (mis. "TW II 2026") selalu ditambahkan otomatis di belakang label. Aturan triwulan tetap dari kode.',
            ],
            'verifikasi' => [
                'n' => null, 'label' => 'Verifikasi & Notula', 'judul' => 'Verifikasi Capaian', 'jenis' => 'C', 'menu' => 'Verifikasi & Notula',
                'butuh_iku' => true, 'periode' => 'Bulan', 'bukti' => 'Menandai bukti Sesuai/Tidak Sesuai', 'notula' => '—',
                'tabel' => 'capaian, riwayat_status_capaian, riwayat_verifikasi_berkas',
                'hak' => [
                    BagianKustom::PERAN_KETUA_TIM => '— (403)',
                    BagianKustom::PERAN_TIM_SAKIP => 'Lihat, verifikasi, kembalikan, isi analisis',
                    BagianKustom::PERAN_KEPALA => '— (403)',
                ],
                'alur' => self::ALUR_ISIAN_KEGIATAN,
                'batasan' => 'Seluruh aturan verifikasi ada di kode. Tidak ada yang bisa diubah dari layar.',
            ],
            'notula' => [
                'n' => null, 'label' => 'Notula & Persetujuan', 'judul' => 'Notula', 'jenis' => 'C', 'menu' => 'Notula, Persetujuan',
                'butuh_iku' => false, 'periode' => 'Triwulan', 'bukti' => 'Tidak', 'notula' => 'Inti',
                'tabel' => 'notula, riwayat_status_notula',
                'hak' => [
                    BagianKustom::PERAN_KETUA_TIM => 'Lihat notula final (riwayat)',
                    BagianKustom::PERAN_TIM_SAKIP => 'Kompilasi, ubah draft, ajukan ke Kepala',
                    BagianKustom::PERAN_KEPALA => 'Lihat, setujui, kembalikan',
                ],
                'alur' => 'Draft → Menunggu persetujuan (Tim SAKIP) → Disetujui atau Dikembalikan (Kepala).',
                'batasan' => 'Hanya template notula yang bisa diatur (menu Template Notula).',
            ],
            'lakin' => [
                'n' => null, 'label' => 'Rekap Kinerja Tahunan', 'judul' => 'Rekap Kinerja Tahunan (LAKIN)', 'jenis' => 'C', 'menu' => 'Rekap Kinerja Tahunan',
                'butuh_iku' => false, 'periode' => 'Tahun', 'bukti' => 'Tidak', 'notula' => '—',
                'tabel' => 'lakin, lakin_baris',
                'hak' => [
                    BagianKustom::PERAN_KETUA_TIM => 'Lihat',
                    BagianKustom::PERAN_TIM_SAKIP => 'Lihat, bentuk, ubah, hapus',
                    BagianKustom::PERAN_KEPALA => 'Lihat',
                ],
                'alur' => 'Tanpa alur pengajuan.',
                'batasan' => 'Struktur dan perhitungan tetap dari kode.',
            ],
        ];
    }

    /** Kode bagian bawaan yang labelnya boleh diganti (jenis B). */
    public static function bisaDiberiLabel(string $kode): bool
    {
        return (self::bawaan()[$kode]['jenis'] ?? null) === 'B';
    }

    /**
     * Label efektif bagian bawaan jenis B untuk layar Isian Kegiatan: pengaturan Tim
     * SAKIP bila ada, selain itu label dari kode.
     *
     * @return array<string, array{label: string, judul: string, petunjuk: ?string, diubah: bool}>
     */
    public static function tampilanBawaan(): array
    {
        $atur = PengaturanBagianBawaan::peta();
        $hasil = [];

        foreach (self::bawaan() as $kode => $b) {
            if ($b['jenis'] !== 'B') {
                continue;
            }

            $p = $atur[$kode] ?? [];

            $hasil[$kode] = [
                'label' => filled($p['label_menu'] ?? null) ? (string) $p['label_menu'] : $b['label'],
                'judul' => filled($p['judul'] ?? null) ? (string) $p['judul'] : $b['judul'],
                'petunjuk' => filled($p['petunjuk'] ?? null) ? (string) $p['petunjuk'] : null,
                'diubah' => filled($p['label_menu'] ?? null) || filled($p['judul'] ?? null) || filled($p['petunjuk'] ?? null),
            ];
        }

        return $hasil;
    }
}
