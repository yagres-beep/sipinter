<?php

namespace App\Http\Middleware;

use App\Models\LogAktivitas;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $userRole = $request->user()?->namaRole();

        if (! $userRole || ! in_array($userRole, $roles, true)) {
            // Aksi Livewire pada halaman yang sudah terbuka (mis. peran dicabut saat
            // halaman masih terbuka, atau request dimanipulasi) dicatat sebagai
            // percobaan yang ditolak. Membuka halaman biasa tidak dicatat.
            if ($request->user() && $request->hasHeader('X-Livewire')) {
                LogAktivitas::aksesDitolak('aksi pada halaman '.($request->route()?->getName() ?? 'aplikasi'));
            }

            abort(403);
        }

        return $next($request);
    }
}
