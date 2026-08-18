<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class UserVerificationController extends Controller
{
    public function index(): View
    {
        return view('admin.verifikasi.index', [
            // timList: akun yang mendaftar sebagai Ketua Tim bisa sekalian mengajukan
            // tim (lihat RegisteredUserController) — ditampilkan di tabel "Menunggu
            // Persetujuan" supaya Tim SAKIP langsung tahu tanpa buka halaman lain.
            'pending' => User::with(['role', 'timList'])->where('status_verifikasi', 'pending')->latest()->get(),
        ]);
    }

    public function approve(User $user): RedirectResponse
    {
        $user->update(['status_verifikasi' => 'terverifikasi']);

        return back()->with('status', "Akun {$user->nama} disetujui.");
    }

    public function reject(User $user): RedirectResponse
    {
        $user->update(['status_verifikasi' => 'ditolak']);

        return back()->with('status', "Akun {$user->nama} ditolak.");
    }
}
