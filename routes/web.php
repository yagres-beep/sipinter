<?php

use App\Http\Controllers\Admin\UserVerificationController;
use App\Http\Controllers\BerkasDownloadController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotulaDownloadController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\VerifikasiController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::middleware('role:Tim SAKIP')->prefix('verifikasi-akun')->name('verifikasi-akun.')->group(function () {
        Route::get('/', [UserVerificationController::class, 'index'])->name('index');
        Route::post('/{user}/approve', [UserVerificationController::class, 'approve'])->name('approve');
        Route::post('/{user}/reject', [UserVerificationController::class, 'reject'])->name('reject');
        Route::put('/{user}/role', [UserVerificationController::class, 'updateRole'])->name('role');
    });

    Route::middleware('role:Ketua Tim')->group(function () {
        Route::view('/pengisian', 'pengisian.index')->name('pengisian.index');
        Route::view('/kendala-solusi', 'kendala-solusi.index')->name('kendala-solusi.index');
        Route::view('/rtl-evaluasi', 'rtl-evaluasi.index')->name('rtl-evaluasi.index');
    });

    Route::middleware('role:Tim SAKIP')->group(function () {
        Route::view('/verifikasi', 'verifikasi.index')->name('verifikasi.index');
        Route::get('/verifikasi/{capaian}', [VerifikasiController::class, 'show'])->name('verifikasi.show');
        Route::get('/berkas/{berkas}/buka', [BerkasDownloadController::class, 'show'])->name('berkas.show');
        Route::view('/master-iku', 'master-iku.index')->name('master-iku.index');
        Route::view('/akun-storage', 'storage-accounts.index')->name('storage-accounts.index');
        Route::view('/konfigurasi', 'folder-config.index')->name('folder-config.index');
        Route::view('/notula', 'notula.index')->name('notula.index');
        Route::view('/template-notula', 'template-notula.index')->name('template-notula.index');
    });

    Route::middleware('role:Kepala')->group(function () {
        Route::view('/persetujuan', 'persetujuan.index')->name('persetujuan.index');
    });

    // Diakses Tim SAKIP (kompilasi) maupun Kepala (tinjau/unduh) — lihat NotulaDownloadController.
    Route::middleware('role:Tim SAKIP,Kepala')->prefix('notula/{notula}')->name('notula.')->group(function () {
        Route::get('/draf', [NotulaDownloadController::class, 'draf'])->name('unduh-draf');
        Route::get('/pratinjau', [NotulaDownloadController::class, 'pratinjau'])->name('pratinjau');
        Route::get('/pratinjau-bagian2', [NotulaDownloadController::class, 'pratinjauBagian2'])->name('pratinjau-bagian2');
        Route::get('/pratinjau-bagian3', [NotulaDownloadController::class, 'pratinjauBagian3'])->name('pratinjau-bagian3');
    });

    // Unduh final (ber-TTD) juga dibuka untuk Ketua Tim — supaya bisa melihat hasil
    // akhir notula yang sudah disetujui Kepala (NotulaDownloadController::final()
    // tetap menggerbang internal: hanya tersedia bila status sudah "disetujui").
    Route::middleware('role:Tim SAKIP,Kepala,Ketua Tim')
        ->get('/notula/{notula}/final', [NotulaDownloadController::class, 'final'])
        ->name('notula.unduh-final');

    // RF-44a: riwayat notula per triwulan (baca saja) untuk Ketua Tim & Kepala — Tim
    // SAKIP memakai halaman Kompilasi Notula (/notula) yang lebih lengkap.
    Route::middleware('role:Ketua Tim,Kepala')->group(function () {
        Route::view('/notula-riwayat', 'notula-riwayat.index')->name('notula-riwayat.index');
    });

    // RF-48 s.d. RF-50: filter periode, rekapitulasi, dan dasbor — dipakai Tim SAKIP & Kepala.
    Route::middleware('role:Tim SAKIP,Kepala')->group(function () {
        Route::view('/rekapitulasi', 'rekapitulasi.index')->name('rekapitulasi.index');
        Route::view('/dasbor-kinerja', 'dasbor-kinerja.index')->name('dasbor-kinerja.index');
    });
});

require __DIR__.'/auth.php';
