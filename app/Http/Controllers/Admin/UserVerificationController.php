<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserVerificationController extends Controller
{
    public function index(): View
    {
        return view('admin.verifikasi.index', [
            'pending' => User::with('role')->where('status_verifikasi', 'pending')->latest()->get(),
            'users' => User::with('role')->where('status_verifikasi', '!=', 'pending')->latest()->get(),
            'roles' => Role::orderBy('nama')->get(),
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

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        $user->update($validated);

        return back()->with('status', "Peran {$user->nama} diperbarui menjadi {$user->role->nama}.");
    }
}
