<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'nama',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Peta id => nama seluruh role, di-cache permanen. Role dicek di HAMPIR SETIAP
     * request (gerbang peran di middleware, sidebar/topbar layout) — database remote
     * (Supabase, Seoul) makan ~400ms per query, jadi tanpa cache ini SETIAP halaman
     * bayar biaya jaringan itu 1-2x lagi hanya untuk tahu peran pengguna. Daftar role
     * (3 peran tetap) nyaris tidak pernah berubah; kalau memang diubah, jalankan
     * `php artisan cache:clear` sekali untuk menyegarkan.
     *
     * @return array<int, string>
     */
    public static function semuaNama(): array
    {
        return Cache::rememberForever('roles-nama-by-id', fn () => static::pluck('nama', 'id')->all());
    }
}
