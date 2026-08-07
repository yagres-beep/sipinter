<?php

namespace App\Models;

use App\Models\Concerns\PolaFolderHierarki;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pola folder KHUSUS untuk satu IKU tertentu — override opsional di atas pola GLOBAL
 * (FolderConfig, satu untuk semua IKU). IKU yang tidak punya baris di tabel ini tetap
 * memakai pola global seperti biasa.
 *
 * Bentuk pola_json SAMA PERSIS dengan FolderConfig::polaDefault() ({hierarki, kategori})
 * — lihat FolderConfig untuk penjelasan lengkap strukturnya, dan
 * FolderStructureService::configUntukIku() untuk cara pola ini dipilih saat resolve folder.
 */
class IkuFolderConfig extends Model
{
    use HasFactory, PolaFolderHierarki;

    protected $table = 'iku_folder_config';

    protected $fillable = [
        'iku_id',
        'pola_json',
    ];

    protected function casts(): array
    {
        return [
            'pola_json' => 'array',
        ];
    }

    public function masterIku(): BelongsTo
    {
        return $this->belongsTo(MasterIku::class, 'iku_id');
    }
}
