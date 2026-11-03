<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(?string $tab = null): View
    {
        $tab = match ($tab) {
            'kinerja', 'capaian-kinerja' => 'kinerja',
            default => 'pemantauan',
        };

        return view('dashboard', ['tabAktif' => $tab]);
    }
}
