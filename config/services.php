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
    | Google Drive (Service Account) — RF-10, RF-10a, SRS §5.3
    |--------------------------------------------------------------------------
    |
    | SIPINTER tidak meminta pengguna login akun Google pribadi. Sebagai gantinya,
    | satu Service Account (akun mesin Google) memakai kredensial JSON berikut
    | untuk membaca/menulis berkas ke Drive akun Gmail institusi yang telah
    | membagikan (share) sebuah folder ke alamat email Service Account tersebut.
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
    |
    */
    'google_drive' => [
        'service_account_path' => storage_path('app/'.env('GOOGLE_SERVICE_ACCOUNT_PATH', 'google/service-account.json')),
        'default_folder_id' => env('GOOGLE_DRIVE_FOLDER_ID'),
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

];
