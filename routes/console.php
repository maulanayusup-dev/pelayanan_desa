<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Kirim ulang email penerbitan SKTM yang gagal terkirim (perlu scheduler: `php artisan schedule:work` atau cron `schedule:run`)
\Illuminate\Support\Facades\Schedule::command('sktm:retry-issued-emails')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
