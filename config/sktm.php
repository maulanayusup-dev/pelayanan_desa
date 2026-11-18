<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Link download SKTM di email penerbitan
    |--------------------------------------------------------------------------
    | Masa berlaku signed URL (hari). Setelah kedaluwarsa, warga tetap dapat
    | mengunduh dokumen melalui halaman pengajuan setelah login.
    | Link dibentuk dari APP_URL, jadi APP_URL wajib berupa alamat yang dapat
    | diakses warga (bukan localhost / 127.0.0.1).
    */
    'download_link_ttl_days' => (int) env('SKTM_DOWNLOAD_LINK_TTL_DAYS', 7),

    // Batas permintaan download per menit per IP
    'download_throttle_per_minute' => (int) env('SKTM_DOWNLOAD_THROTTLE_PER_MINUTE', 20),
];
