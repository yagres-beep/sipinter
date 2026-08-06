<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'nama',
        'email',
        'password',
        'role_id',
        'status_verifikasi',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Nama peran pengguna ini, lewat peta Role::semuaNama() yang di-cache — pakai ini
     * (bukan ->role->nama) di tempat yang dipanggil tiap request (gerbang peran,
     * layout) supaya tidak menambah satu query database remote per halaman.
     */
    public function namaRole(): ?string
    {
        return Role::semuaNama()[$this->role_id] ?? null;
    }

    /**
     * Keanggotaan tim (khusus Ketua Tim) — satu pengguna bisa merangkap lebih dari satu tim.
     */
    public function timList(): HasMany
    {
        return $this->hasMany(UserTim::class);
    }

    /**
     * @return list<string>
     */
    public function namaTimList(): array
    {
        return $this->timList()->pluck('tim')->all();
    }
}
