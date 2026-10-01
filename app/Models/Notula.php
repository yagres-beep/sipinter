<?php

namespace App\Models;

use App\Events\NotulaStatusDiubah;
use App\Exceptions\InvalidStatusTransitionException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Notula extends Model
{
    use HasFactory;

    protected $table = 'notula';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_MENUNGGU_PERSETUJUAN = 'menunggu_persetujuan';

    public const STATUS_DISETUJUI = 'disetujui';

    public const STATUS_DIKEMBALIKAN = 'dikembalikan';

    /**
     * Alur status notula (RF-42d, RF-44) — TERPISAH dari alur status kegiatan
     * (lihat Kegiatan::TRANSITIONS). Tim SAKIP menggabungkan 3 bagian -> menunggu
     * persetujuan Kepala -> disetujui / dikembalikan. Bila dikembalikan, Tim SAKIP
     * memperbaiki lalu menggabungkan ulang (kirim() bisa dipanggil lagi dari draft
     * ATAU dikembalikan).
     *
     * @var array<string, list<string>>
     */
    protected const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_MENUNGGU_PERSETUJUAN],
        // Termasuk kembali ke STATUS_DRAFT: RF-42e mengizinkan Tim SAKIP mengganti
        // berkas Bagian II/III kapan pun, termasuk saat notula sudah menunggu
        // persetujuan Kepala — begitu diganti, notula ditarik kembali ke draft
        // untuk digabung ulang (lihat Notula::tandaiPerluDigabungUlang()).
        self::STATUS_MENUNGGU_PERSETUJUAN => [self::STATUS_DISETUJUI, self::STATUS_DIKEMBALIKAN, self::STATUS_DRAFT],
        self::STATUS_DIKEMBALIKAN => [self::STATUS_MENUNGGU_PERSETUJUAN],
        // "Disetujui" BUKAN lagi jalan buntu: bila isian yang termuat di dalamnya
        // berubah setelah ditandatangani (Kepala mengembalikan satu isian IKU, atau
        // Tim SAKIP mengganti Bagian I/II/III), notula ditarik balik ke draft sebagai
        // VERSI BARU — lihat bukaVersiBaru(). Dari draft alurnya kembali normal:
        // digabung ulang -> menunggu persetujuan -> Kepala membubuhkan TTD lagi.
        self::STATUS_DISETUJUI => [self::STATUS_DRAFT],
    ];

    protected $fillable = [
        'periode_id',
        'hari_tanggal',
        'waktu',
        'tempat',
        'pimpinan_rapat',
        'notulis',
        'kepala_satker',
        'kota_ttd',
        'link_lampiran_basis_data',
        'bagian1_html',
        'bagian1_html_cadangan',
        'bagian2_pdf',
        'bagian3_pdf',
        'bagian2_html',
        'bagian3_html',
        'pdf_gabungan',
        'pdf_final',
        'status',
        'versi',
        'disetujui_oleh_user_id',
        'disetujui_pada',
        'catatan_pengembalian',
    ];

    protected function casts(): array
    {
        return [
            'disetujui_pada' => 'datetime',
            'versi' => 'integer',
        ];
    }

    /**
     * Notula dibuat lewat firstOrCreate() tanpa menyebut 'versi' (lihat
     * NotulaService::untukTriwulan()) — tanpa default di sisi MODEL, instance yang baru
     * saja dibuat punya versi null sampai di-refresh, padahal nilainya langsung dipakai
     * menyusun nama berkas. Default di sini membuatnya SELALU terisi 1, sama dengan
     * default kolomnya di basis data.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'versi' => 1,
    ];

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class);
    }

    public function disetujuiOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh_user_id');
    }

    /**
     * Riwayat tindakan (kirim ke Kepala / disetujui / dikembalikan), ditampilkan
     * sebagai "Riwayat Tindakan" di halaman Persetujuan Notula.
     */
    public function riwayatStatus(): HasMany
    {
        return $this->hasMany(RiwayatStatusNotula::class)->latest('id');
    }

    protected function catatRiwayat(?User $user, ?string $catatan = null): void
    {
        $this->riwayatStatus()->create([
            'status' => $this->status,
            'user_id' => $user?->id,
            'catatan' => $catatan,
        ]);
    }

    /**
     * Arsip PDF final di Drive institusi (RF-44a), lewat pola Berkas polimorfik yang
     * sama dipakai bukti dukung lain — kategori "notula".
     */
    public function berkas(): MorphMany
    {
        return $this->morphMany(Berkas::class, 'ref', 'ref_type', 'ref_id');
    }

    /**
     * "Lengkap" ditentukan dari konten INLINE (bagian2_html/bagian3_html), bukan
     * bagian2_pdf/bagian3_pdf — kolom PDF itu sekarang cuma dipakai pratinjau iframe,
     * sedangkan render notula menyatu (lihat NotulaService::gabungkan()/setujui())
     * memakai versi HTML/inline.
     */
    public function bagianLengkap(): bool
    {
        return filled($this->bagian1_html) && filled($this->bagian2_html) && filled($this->bagian3_html);
    }

    /**
     * Tanggal TTD "Mengetahui" SELALU tanggal saja, TANPA nama hari -- dipetik dari
     * hari_tanggal rapat (field bebas yang tampil di kepala dokumen, mis. "Selasa, 1
     * September 2026" atau "Jumat/17 Juli 2026") dengan membuang nama hari & pemisahnya
     * di depan bila ada, supaya baris TTD tercetak "Kulisusu, 1 September 2026" --
     * BUKAN ikut mencetak "Selasa,". hari_tanggal sendiri (dipakai di kepala dokumen)
     * TIDAK diubah -- ini cuma dipakai untuk blok TTD.
     *
     * Dipakai baik oleh blok TTD dokumen gabungan (NotulaService::dataNotulaUtuh())
     * maupun tabel TTD bawaan template .docx (NotulaBagian1DocxService::isiPenutup()),
     * supaya keduanya SELALU sinkron -- taruh logikanya di sini (bukan diduplikasi di
     * kedua kelas itu) satu-satunya tempat yang keduanya boleh sama-sama bergantung.
     */
    public function tanggalTtd(): ?string
    {
        $teks = trim((string) $this->hari_tanggal);
        if ($teks === '') {
            return null;
        }

        return preg_replace('/^(Senin|Selasa|Rabu|Kamis|Jumat|Sabtu|Minggu)\s*[\/,:]\s*/iu', '', $teks);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    protected function transitionTo(string $status): void
    {
        if (! $this->canTransitionTo($status)) {
            throw new InvalidStatusTransitionException($this->status, $status);
        }

        $this->update(['status' => $status]);
    }

    /**
     * Tim SAKIP mengirim notula gabungan ke Kepala (draft/dikembalikan -> menunggu_persetujuan).
     */
    public function kirimKePersetujuan(?User $user = null): void
    {
        $this->transitionTo(self::STATUS_MENUNGGU_PERSETUJUAN);

        $this->catatRiwayat($user);

        event(new NotulaStatusDiubah($this));
    }

    /**
     * Kepala menyetujui notula (menunggu_persetujuan -> disetujui). Hanya mengubah
     * status & mencatat siapa/kapan — pembuatan pdf_final dengan blok TTD dilakukan
     * NotulaService::setujui(), bukan di sini, karena butuh akses ke dompdf dll.
     */
    public function setujui(User $kepala): void
    {
        $this->transitionTo(self::STATUS_DISETUJUI);

        $this->update([
            'disetujui_oleh_user_id' => $kepala->id,
            'disetujui_pada' => now(),
        ]);

        $this->catatRiwayat($kepala);
    }

    /**
     * Kepala mengembalikan notula ke Tim SAKIP (menunggu_persetujuan -> dikembalikan).
     */
    public function kembalikan(string $catatan, ?User $user = null): void
    {
        $this->transitionTo(self::STATUS_DIKEMBALIKAN);

        $this->update(['catatan_pengembalian' => $catatan]);

        $this->catatRiwayat($user, $catatan);

        event(new NotulaStatusDiubah($this));
    }

    /**
     * RF-42e: dipanggil saat isi notula berubah (Bagian I disunting, Bagian II/III
     * diganti) — hasil gabungan lama tidak lagi valid:
     *
     * - menunggu persetujuan -> ditarik kembali ke draft, supaya Tim SAKIP wajib
     *   menggabungkan ulang sebelum dikirim lagi (versi TIDAK naik: dokumen itu
     *   belum pernah ditandatangani siapa pun).
     * - sudah DISETUJUI -> dibuka sebagai VERSI BARU (lihat bukaVersiBaru()), karena
     *   dokumen yang sudah ber-TTD tidak boleh diam-diam berubah isinya; yang beredar
     *   harus dokumen baru yang disetujui ulang.
     */
    public function tandaiPerluDigabungUlang(?User $user = null, ?string $catatan = null): void
    {
        if ($this->status === self::STATUS_DISETUJUI) {
            $this->bukaVersiBaru($user, $catatan ?? 'Isi notula diubah setelah disetujui — notula dibuka kembali sebagai versi baru dan perlu digabung serta disetujui ulang.');

            return;
        }

        if ($this->status === self::STATUS_MENUNGGU_PERSETUJUAN) {
            $this->transitionTo(self::STATUS_DRAFT);
        }

        $this->update(['pdf_gabungan' => null, 'pdf_final' => null]);
    }

    /**
     * Tarik notula yang SUDAH disetujui kembali ke awal (draft) sebagai versi
     * berikutnya — dipakai saat ada data/isian yang berubah setelah notula
     * ditandatangani: Kepala mengembalikan satu isian IKU dari halaman Persetujuan
     * (lihat NotulaService::kembalikanIsian()), atau Tim SAKIP menyunting/mengganti
     * salah satu bagian (lihat tandaiPerluDigabungUlang() di atas).
     *
     * Kolom hasil & persetujuan DIKOSONGKAN karena seluruhnya menggambarkan versi
     * SEBELUMNYA, bukan versi yang sedang disusun ini: alur wajib diulang dari awal
     * (gabungkan -> kirim ke Kepala -> Kepala membubuhkan TTD lagi). Jejak versi lama
     * TIDAK hilang — berkas PDF tiap versi tersimpan dengan nama berbeda (lihat
     * namaUnduhan(), dipakai NotulaService::gabungkan()/setujui() termasuk untuk nama
     * arsip Drive yang dicatat sebagai App\Models\Berkas tersendiri per versi), dan
     * siapa/kapan menyetujuinya tetap tercatat di riwayatStatus().
     *
     * Aman dipanggil pada status apa pun: hanya berlaku untuk notula yang benar-benar
     * sudah disetujui, selain itu tidak melakukan apa-apa (pemanggilnya tidak perlu
     * mengecek ulang statusnya sendiri).
     */
    public function bukaVersiBaru(?User $user = null, ?string $catatan = null): void
    {
        if ($this->status !== self::STATUS_DISETUJUI) {
            return;
        }

        $this->transitionTo(self::STATUS_DRAFT);

        $this->update([
            'versi' => $this->versiSaatIni() + 1,
            'pdf_gabungan' => null,
            'pdf_final' => null,
            'disetujui_oleh_user_id' => null,
            'disetujui_pada' => null,
            'catatan_pengembalian' => $catatan,
        ]);

        $this->catatRiwayat($user, $catatan);

        event(new NotulaStatusDiubah($this));
    }

    /**
     * Nomor versi yang sedang disusun — selalu minimal 1, termasuk untuk baris notula
     * lama yang dibuat sebelum kolom `versi` ada.
     */
    public function versiSaatIni(): int
    {
        return max(1, (int) ($this->versi ?? 1));
    }

    /**
     * Catatan yang menyertai pembukaan versi terakhir (alasan notula yang sudah
     * disetujui ditarik lagi ke draft) — ditampilkan sebagai banner di layar
     * Kompilasi Notula supaya Tim SAKIP tahu kenapa harus menyusun ulang. Null bila
     * notula ini memang belum pernah disetujui sama sekali (versi masih 1) atau sudah
     * berjalan lagi melewati draft.
     */
    public function catatanVersiBaru(): ?string
    {
        if ($this->status !== self::STATUS_DRAFT || $this->versiSaatIni() <= 1) {
            return null;
        }

        return $this->riwayatStatus()->where('status', self::STATUS_DRAFT)->value('catatan');
    }

    /**
     * Nama berkas unduhan notula, SELALU bernomor versi di belakang ("-v2") supaya
     * berkas tiap versi tidak saling menimpa dan pembacanya langsung tahu ini versi
     * ke berapa — mis. "notula-final-tw3-2026-v2.pdf".
     *
     * Satu-satunya tempat pola penamaan ini didefinisikan: dipakai jalur unduhan
     * (App\Http\Controllers\NotulaDownloadController), nama berkas fisik di disk
     * (NotulaService::gabungkan()/setujui()), maupun nama arsip di Google Drive.
     */
    public function namaUnduhan(string $jenis, string $ekstensi = 'pdf'): string
    {
        $tw = $this->periode?->triwulan ?? '-';
        $tahun = $this->periode?->tahun ?? '-';

        return "notula-{$jenis}-tw{$tw}-{$tahun}-v{$this->versiSaatIni()}.{$ekstensi}";
    }
}
