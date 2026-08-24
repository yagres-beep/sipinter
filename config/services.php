<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Drive — OAuth (akun Gmail biasa) + Service Account (fallback lama)
    |--------------------------------------------------------------------------
    |
    | Google TIDAK MENGIZINKAN Service Account menulis berkas baru ke folder yang
    | di-*share* dari akun Gmail BIASA (bukan Shared Drive Google Workspace) — akun
    | robot itu tidak pernah punya kuota penyimpanan sendiri ("Service Accounts do
    | not have storage quota"). Karena akun institusi SIPINTER adalah Gmail biasa,
    | jalur utama unggah berkas WAJIB lewat OAuth: Tim SAKIP login sekali ke akun
    | tsb (menu Akun & Storage → "Hubungkan ke Google Drive"), tokennya disimpan di
    | storage_account.google_refresh_token, lalu GoogleDriveService mengunggah
    | SEBAGAI akun itu sendiri (kuota 15GB pribadinya) — lihat GoogleOAuthController.
    |
    | - oauth_client_id / oauth_client_secret: dari Google Cloud Console > APIs &
    |   Services > Credentials > OAuth client ID (tipe "Web application"). Redirect
    |   URI yang didaftarkan di sana harus persis {APP_URL}/akun-storage/google/callback.
    |
    | Kredensial Service Account (service_account_path) TETAP dipertahankan sebagai
    | fallback — dipakai GoogleDriveService HANYA bila storage aktif belum pernah
    | dihubungkan lewat OAuth, dan tetap relevan bila suatu saat institusi memakai
    | akun Google Workspace/Shared Drive (yang memang boleh diakses Service Account).
    |
    | - service_account_path: lokasi file kredensial JSON (unduh dari Google Cloud
    |   Console > IAM & Admin > Service Accounts > Keys). GOOGLE_SERVICE_ACCOUNT_PATH
    |   di .env diisi path RELATIF terhadap storage/app/ (mis. "google/service-account.json"),
    |   lalu di sini digabung dengan storage_path('app/...') menjadi path absolut.
    |   Simpan berkas ini di luar folder `public` dan JANGAN commit ke git (lihat .gitignore).
    | - default_folder_id: ID folder induk default di Drive — dipakai sebagai
    |   nilai awal saat storage account pertama didaftarkan (lihat RF-10a).
    |   Setiap storage_account punya kolom drive_folder_id sendiri di database,
    |   sehingga akun Gmail institusi berikutnya bisa memakai folder induk yang
    |   berbeda tanpa mengubah nilai .env ini.
    | - master_account_email: bila diisi, SEMUA akun storage lain (selain email ini)
    |   yang terhubung lewat OAuth TIDAK membuat folder root sendiri — GoogleOAuthController
    |   otomatis membagikan (share, peran writer) default_folder_id milik akun ini ke
    |   akun yang baru terhubung, lalu drive_folder_id-nya diarahkan ke folder yang sama.
    |   Hasilnya satu struktur folder yang konsisten di Drive walau uploadnya datang dari
    |   akun institusi yang berbeda-beda (kuota 15GB tiap akun tetap dihitung terpisah —
    |   Drive membebankan kuota ke akun PENGUNGGAH, bukan ke pemilik folder). Kosongkan
    |   untuk kembali ke perilaku lama (tiap akun punya folder root "SIPINTER" sendiri).
    |
    */
    'google_drive' => [
        'oauth_client_id' => env('GOOGLE_OAUTH_CLIENT_ID'),
        'oauth_client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET'),
        'service_account_path' => storage_path('app/'.env('GOOGLE_SERVICE_ACCOUNT_PATH', 'google/service-account.json')),
        'default_folder_id' => env('GOOGLE_DRIVE_FOLDER_ID'),
        'master_account_email' => env('GOOGLE_DRIVE_MASTER_ACCOUNT_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | LibreOffice headless (RF-42a, RF-42b, SRS §5.1)
    |--------------------------------------------------------------------------
    |
    | Dipakai LibreOfficeConversionService untuk mengonversi berkas Bagian II & III
    | notula (.docx/.xlsx/gambar) menjadi PDF sebelum digabungkan. Ini BUKAN paket
    | Composer — LibreOffice harus dipasang terpisah di server (lihat
    | app/Services/LibreOfficeConversionService.php untuk instruksi instalasi Windows).
    |
    */
    'libreoffice' => [
        'binary_path' => env('LIBREOFFICE_PATH', 'C:\\Program Files\\LibreOffice\\program\\soffice.exe'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Poppler pdftoppm (notula menyatu — rasterisasi Bagian II/III berformat PDF)
    |--------------------------------------------------------------------------
    |
    | Dipakai App\Services\PdfRasterService untuk merasterisasi berkas PDF (mis.
    | hasil pindai/tanda tangan basah) menjadi gambar per halaman yang ditempel
    | inline ke dokumen notula menyatu. BUKAN paket Composer — Poppler harus
    | dipasang terpisah (lihat app/Services/PdfRasterService.php untuk instruksi
    | instalasi Windows).
    |
    */
    'poppler' => [
        'pdftoppm_path' => env('POPPLER_PDFTOPPM_PATH', 'C:\\Program Files\\poppler\\Library\\bin\\pdftoppm.exe'),
    ],

];
