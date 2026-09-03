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
            // Saran tim yang sudah ada (Master IKU + keanggotaan tim pengguna lain,
            // lihat MasterIku::daftarTimGabungan()) — dipilih lewat checkbox atau
            // diketik (dengan datalist) bila peran yang diajukan Ketua Tim, supaya
            // nama tim yang diketik ulang tetap konsisten dengan yang sudah ada.
            'daftarTim' => MasterIku::daftarTimGabungan(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'nomor_telepon' => ['required', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role_id' => ['required', 'exists:roles,id'],
            'tim' => ['nullable', 'array'],
            'tim.*' => ['string', 'max:255'],
            'tim_baru' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::create([
            'nama' => $validated['nama'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'nomor_telepon' => $validated['nomor_telepon'],
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
            // Samakan ejaan tim yang diketik manual (tim_baru) dengan tim yang
            // sudah ada di database bila hanya beda huruf besar/kecil atau spasi,
            // supaya tidak lahir tim "kembar" akibat salah ketik (mis. "Tim IT"
            // vs "tim it") — lihat MasterIku::daftarTimGabungan().
            $timTerdaftar = collect(MasterIku::daftarTimGabungan());

            $timDiajukan = collect($validated['tim'] ?? [])
                ->merge(explode(',', $validated['tim_baru'] ?? ''))
                ->map(fn ($tim) => trim($tim))
                ->filter()
                ->map(fn ($tim) => $timTerdaftar->first(
                    fn ($ada) => mb_strtolower($ada) === mb_strtolower($tim)
                ) ?? $tim)
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
