<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'username' => 'Username atau kata sandi tidak sesuai.',
            ]);
        }

        $user = Auth::user();

        if ($user->status_verifikasi !== 'terverifikasi') {
            Auth::logout();

            $message = $user->status_verifikasi === 'ditolak'
                ? 'Pendaftaran akun Anda ditolak oleh Tim SAKIP.'
                : 'Akun Anda masih menunggu verifikasi Tim SAKIP.';

            throw ValidationException::withMessages(['username' => $message]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
