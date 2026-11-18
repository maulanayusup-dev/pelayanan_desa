# Hapus file kanal notifikasi yang sudah tidak digunakan (Telegram, WebPush/Desktop, WhatsApp Cloud API Meta).
# Jalankan dari folder proyek:  powershell -ExecutionPolicy Bypass -File .\hapus-kanal-notifikasi-lama.ps1
# Tabel database & riwayat notifikasi TIDAK disentuh oleh skrip ini.
$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

$files = @(
    'app/Services/TelegramNotificationService.php',
    'app/Console/Commands/TelegramPollCommand.php',
    'app/Http/Controllers/TelegramController.php',
    'app/Livewire/AdminTelegramSettings.php',
    'resources/views/livewire/admin-telegram-settings.blade.php',
    'app/Models/TelegramSetting.php',
    'app/Models/TelegramNotificationLog.php',
    'tests/Feature/TelegramNotificationTest.php',
    'app/Http/Controllers/WebPushController.php',
    'app/Notifications/WebPushTestNotification.php',
    'app/Models/PushSubscription.php',
    'config/webpush.php',
    'resources/js/webpush.js',
    'tests/Feature/WebPushNotificationTest.php',
    'app/Services/WhatsAppCloudApiService.php',
    'app/Jobs/SendWhatsAppNotificationJob.php',
    'app/Console/Commands/WhatsAppTestCommand.php',
    'app/Models/WhatsAppNotificationLog.php',
    'tests/Feature/WhatsAppNotificationTest.php'
)

foreach ($f in $files) {
    $path = Join-Path $PSScriptRoot ($f -replace '/', '\')
    if (Test-Path $path) {
        Remove-Item $path -Force
        Write-Host "Dihapus : $f"
    } else {
        Write-Host "Tidak ada: $f"
    }
}

# Cache paket/service lama (masih mendaftarkan paket webpush) akan dibuat ulang otomatis
foreach ($c in @('bootstrap\cache\packages.php', 'bootstrap\cache\services.php')) {
    if (Test-Path $c) { Remove-Item $c -Force; Write-Host "Cache dihapus: $c" }
}

Write-Host ''
Write-Host 'Selesai. Lanjutkan: composer install ; npm run build ; php artisan optimize:clear ; php artisan queue:restart ; php artisan test'
