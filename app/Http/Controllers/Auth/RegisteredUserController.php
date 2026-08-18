<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\MasterIku;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTim;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register', [
            'roles' => Role::orderBy('nama')->get(),
            // Saran tim yang sudah ada (dari Master IKU) — dipilih lewat checkbox bila
            // peran yang diajukan Ketua Tim; boleh juga mengetik tim baru (lihat blade).
            'daftarTim' => MasterIku::whereNotNull('tim')->distinct()->orderBy('tim')->pluck('tim'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:users,username'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role_id' => ['required', 'exists:roles,id'],
            'tim' => ['nullable', 'array'],
            'tim.*' => ['string', 'max:255'],
            'tim_baru' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::create([
            'nama' => $validated['nama'],
            'username' => $validated['username'],
            'password' => $validated['password'],
            'role_id' => $validated['role_id'],
            'status_verifikasi' => 'pending',
        ]);

        // Tim hanya berlaku untuk peran Ketua Tim (lihat User::timList()) — boleh
        // pilih beberapa lewat checkbox sekaligus mengetik tim baru yang dipisah
        // koma; diterapkan langsung meski akun masih menunggu verifikasi, supaya
        // begitu Tim SAKIP menyetujui, keanggotaan timnya sudah siap tanpa langkah
        // tambahan (lihat AkunAktif — hanya menampilkan akun terverifikasi).
        if ($user->namaRole() === 'Ketua Tim') {
            $timDiajukan = collect($validated['tim'] ?? [])
                ->merge(explode(',', $validated['tim_baru'] ?? ''))
                ->map(fn ($tim) => trim($tim))
                ->filter()
                ->unique();

            foreach ($timDiajukan as $tim) {
                UserTim::firstOrCreate(['user_id' => $user->id, 'tim' => $tim]);
            }
        }

        return redirect()->route('login')->with(
            'status',
            'Pendaftaran berhasil. Akun Anda menunggu verifikasi Tim SAKIP sebelum dapat digunakan.'
        );
    }
}
