<?php

namespace App\Models;

use App\Exceptions\InvalidStatusTransitionException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        self::STATUS_DISETUJUI => [],
    ];

    protected $fillable = [
        'periode_id',
        'hari_tanggal',
        'waktu',
        'tempat',
        'pimpinan_rapat',
        'notulis',
        'bagian1_html',
        'bagian2_pdf',
        'bagian3_pdf',
        'bagian2_html',
        'bagian3_html',
        'pdf_gabungan',
        'pdf_final',
        'status',
        'disetujui_oleh_user_id',
        'disetujui_pada',
        'catatan_pengembalian',
    ];

    protected function casts(): array
    {
        return [
            'disetujui_pada' => 'datetime',
        ];
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class);
    }

    public function disetujuiOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh_user_id');
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
    public function kirimKePersetujuan(): void
    {
        $this->transitionTo(self::STATUS_MENUNGGU_PERSETUJUAN);
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
    }

    /**
     * Kepala mengembalikan notula ke Tim SAKIP (menunggu_persetujuan -> dikembalikan).
     */
    public function kembalikan(string $catatan): void
    {
        $this->transitionTo(self::STATUS_DIKEMBALIKAN);

        $this->update(['catatan_pengembalian' => $catatan]);
    }

    /**
     * RF-42e: dipanggil saat Bagian II/III diganti — hasil gabungan lama tidak lagi
     * valid, dan bila notula sudah terlanjur menunggu persetujuan Kepala, ditarik
     * kembali ke draft supaya Tim SAKIP wajib menggabungkan ulang sebelum dikirim lagi.
     */
    public function tandaiPerluDigabungUlang(): void
    {
        if ($this->status === self::STATUS_MENUNGGU_PERSETUJUAN) {
            $this->transitionTo(self::STATUS_DRAFT);
        }

        $this->update(['pdf_gabungan' => null, 'pdf_final' => null]);
    }
}
