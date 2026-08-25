<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sebagian akun lama dibuat sebelum email wajib diisi (lihat migrasi
 * add_username_to_users_table), sehingga tidak bisa memakai "lupa kata sandi"
 * (Password::sendResetLink() mencari berdasarkan email). Middleware ini
 * memaksa pengguna dengan email kosong melengkapi profilnya dulu.
 */
class EnsureEmailIsComplete
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && blank($user->email) && ! $request->routeIs('profile.*')) {
            return redirect()->route('profile.edit')
                ->with('status', 'Lengkapi email Anda terlebih dahulu agar bisa memakai fitur lupa kata sandi.');
        }

        return $next($request);
    }
}
