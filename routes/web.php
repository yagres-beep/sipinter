<?php

use App\Http\Controllers\Admin\UserVerificationController;
use App\Http\Controllers\BerkasDownloadController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoogleOAuthController;
use App\Http\Controllers\NotulaDownloadController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\VerifikasiController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

// Halaman publik untuk layar persetujuan OAuth Google (lihat resources/views/legal).
Route::view('/kebijakan-privasi', 'legal.kebijakan-privasi')->name('kebijakan-privasi');
Route::view('/ketentuan-layanan', 'legal.ketentuan-layanan')->name('ketentuan-layanan');

Route::middleware(['auth', 'profile.complete'])->group(function () {
    // 🏠 DASBOR (semua peran) — Tim SAKIP & Kepala memiliki sub-tab Pemantauan Pengisian & Capaian Kinerja
    Route::get('/dasbor/{tab?}', [DashboardController::class, 'index'])
        ->whereIn('tab', ['pemantauan', 'kinerja', 'capaian-kinerja'])
        ->name('dashboard');
    Route::redirect('/dashboard', '/dasbor');
    Route::redirect('/dasbor-kinerja', '/dasbor/capaian-kinerja');

    // ⚙️ PENGATURAN AKUN (semua peran)
    Route::get('/pengaturan-akun', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/pengaturan-akun', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/pengaturan-akun/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::redirect('/profile', '/pengaturan-akun');

    // 👥 PENGGUNA & TIM (Tim SAKIP) — Verifikasi Pendaftaran & Penugasan IKU
    Route::middleware('role:Tim SAKIP')->prefix('kelola-pengguna')->name('verifikasi-akun.')->group(function () {
        Route::get('/{tab?}', [UserVerificationController::class, 'index'])
            ->whereIn('tab', ['verifikasi', 'penugasan'])
            ->name('index');
        Route::post('/{user}/approve', [UserVerificationController::class, 'approve'])->name('approve');
        Route::post('/{user}/reject', [UserVerificationController::class, 'reject'])->name('reject');
    });
    Route::redirect('/verifikasi-akun', '/kelola-pengguna');

    // ⚙️ SISTEM & INTEGRASI (Tim SAKIP) — Storage Google Drive, Pengingat, Backup, dan Log Aktivitas
    Route::middleware('role:Tim SAKIP')->prefix('pengaturan-sistem')->name('pengaturan-sistem.')->group(function () {
        Route::get('/{tab?}', function (?string $tab = 'storage') {
            $tab = match ($tab) {
                'storage', 'akun-storage' => 'storage',
                'pengingat', 'whatsapp' => 'pengingat',
                'backup', 'cadangan' => 'backup',
                'log', 'log-aktivitas' => 'log',
                default => 'storage',
            };
            return view('pengaturan-sistem.index', ['tabAktif' => $tab]);
        })->whereIn('tab', ['storage', 'akun-storage', 'pengingat', 'whatsapp', 'backup', 'cadangan', 'log', 'log-aktivitas'])
          ->name('index');
    });
    Route::redirect('/akun-storage', '/pengaturan-sistem/storage');
    Route::middleware('role:Tim SAKIP')->group(function () {
        Route::get('/akun-storage-redirect', fn () => redirect()->route('pengaturan-sistem.index', ['tab' => 'storage']))->name('storage-accounts.index');
        Route::get('/akun-storage/{storageAccount}/google/redirect', [GoogleOAuthController::class, 'redirect'])->name('storage-accounts.google-redirect');
        Route::get('/akun-storage/google/callback', [GoogleOAuthController::class, 'callback'])->name('storage-accounts.google-callback');
    });

    // 📝 ISIAN KEGIATAN (Ketua Tim)
    Route::middleware('role:Ketua Tim')->group(function () {
        Route::view('/isian-kegiatan', 'pengisian.index')->name('pengisian.index');
        Route::redirect('/pengisian', '/isian-kegiatan');
        Route::view('/rtl-evaluasi', 'rtl-evaluasi.index')->name('rtl-evaluasi.index');
    });

    // Pratinjau/unduh berkas bukti
    Route::middleware('role:Tim SAKIP,Ketua Tim,Kepala')
        ->get('/berkas/{berkas}/buka', [BerkasDownloadController::class, 'show'])
        ->name('berkas.show');

    // ✅ VERIFIKASI & NOTULA (Tim SAKIP) — Verifikasi Capaian, Kompilasi Notula, Template Notula
    Route::middleware('role:Tim SAKIP')->group(function () {
        Route::get('/verifikasi-notula/{tab?}', function (?string $tab = 'verifikasi') {
            $tab = match ($tab) {
                'verifikasi' => 'verifikasi',
                'kompilasi', 'notula' => 'kompilasi',
                'template', 'template-notula' => 'template',
                default => 'verifikasi',
            };
            return view('verifikasi.index', ['tabAktif' => $tab]);
        })->whereIn('tab', ['verifikasi', 'kompilasi', 'notula', 'template', 'template-notula'])
          ->name('verifikasi.index');
        Route::redirect('/verifikasi', '/verifikasi-notula');
        Route::redirect('/notula', '/verifikasi-notula/kompilasi');
        Route::redirect('/template-notula', '/verifikasi-notula/template');

        Route::get('/verifikasi-notula/verifikasi/{capaian}', [VerifikasiController::class, 'show'])->name('verifikasi.show');
        Route::get('/verifikasi/{capaian}', fn ($capaian) => redirect()->route('verifikasi.show', $capaian));

        // 📊 DATA MASTER & FORM (Tim SAKIP)
        Route::get('/master-data/{tab?}', function (?string $tab = 'iku') {
            $tab = match ($tab) {
                'iku' => 'iku',
                'target', 'target-tahunan' => 'target',
                'rumus', 'rumus-capaian' => 'rumus',
                'folder', 'struktur-folder' => 'folder',
                'bagian', 'pengelolaan-bagian' => 'bagian',
                'variabel', 'katalog-variabel' => 'variabel',
                default => 'iku',
            };
            return view('master-iku.index', ['tabAktif' => $tab]);
        })->whereIn('tab', ['iku', 'target', 'target-tahunan', 'rumus', 'rumus-capaian', 'folder', 'struktur-folder', 'bagian', 'pengelolaan-bagian', 'variabel', 'katalog-variabel'])
          ->name('master-iku.index');
        Route::redirect('/master-iku', '/master-data');
        Route::redirect('/konfigurasi', '/master-data/struktur-folder');

        // Unduh template notula
        Route::get('/notula/template-bagian1', [NotulaDownloadController::class, 'templateBagian1'])->name('notula.template-bagian1');
        Route::get('/notula/template-bagian2', [NotulaDownloadController::class, 'templateBagian2'])->name('notula.template-bagian2');
        Route::get('/notula/template-bagian3', [NotulaDownloadController::class, 'templateBagian3'])->name('notula.template-bagian3');
    });

    // ✍️ PERSETUJUAN (Kepala)
    Route::middleware('role:Kepala')->group(function () {
        Route::view('/persetujuan', 'persetujuan.index')->name('persetujuan.index');
    });

    // Unduhan & Pratinjau Notula
    Route::middleware('role:Tim SAKIP,Kepala')->prefix('notula/{notula}')->name('notula.')->group(function () {
        Route::get('/draf', [NotulaDownloadController::class, 'draf'])->name('unduh-draf');
        Route::get('/pratinjau', [NotulaDownloadController::class, 'pratinjau'])->name('pratinjau');
        Route::get('/pratinjau-bagian2', [NotulaDownloadController::class, 'pratinjauBagian2'])->name('pratinjau-bagian2');
        Route::get('/pratinjau-bagian3', [NotulaDownloadController::class, 'pratinjauBagian3'])->name('pratinjau-bagian3');
        Route::get('/unduh-bagian1-docx', [NotulaDownloadController::class, 'unduhBagian1Docx'])->name('unduh-bagian1-docx');
        Route::get('/pratinjau-cepat-pdf', [NotulaDownloadController::class, 'pratinjauCepatPdf'])->name('pratinjau-cepat-pdf');
    });

    Route::middleware('role:Tim SAKIP,Kepala,Ketua Tim')
        ->get('/notula/{notula}/final', [NotulaDownloadController::class, 'final'])
        ->name('notula.unduh-final');

    // 📄 RIWAYAT NOTULA (Ketua Tim & Kepala)
    Route::middleware('role:Ketua Tim,Kepala')->group(function () {
        Route::view('/riwayat-notula', 'notula-riwayat.index')->name('notula-riwayat.index');
        Route::redirect('/notula-riwayat', '/riwayat-notula');
    });

    // 🧩 ISIAN BAGIAN KUSTOM (Tim SAKIP & Kepala)
    Route::middleware('role:Tim SAKIP,Kepala')->group(function () {
        Route::view('/isian-bagian-kustom', 'bagian-kustom.index')->name('bagian-kustom.index');
    });

    // 📈 REKAP KINERJA TAHUNAN (LAKIN — semua peran)
    Route::middleware('role:Ketua Tim,Tim SAKIP,Kepala')->prefix('rekap-kinerja-tahunan')->name('lakin.')->group(function () {
        Route::view('/', 'lakin.index')->name('index');
        Route::get('/{lakin}', function (\App\Models\Lakin $lakin) {
            return view('lakin.show', ['lakin' => $lakin]);
        })->name('show');
    });
});

require __DIR__.'/auth.php';
