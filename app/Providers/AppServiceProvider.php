<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use Illuminate\Support\Facades\Gate;
use App\Models\SystemReport;
use App\Models\SystemReportMessage;
use App\Models\User;
use App\Policies\ReportPolicy;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(SystemReport::class, ReportPolicy::class);
        Gate::policy(SystemReportMessage::class, ReportPolicy::class);

        \Illuminate\Auth\Notifications\VerifyEmail::createUrlUsing(function ($notifiable) {
            return app(\App\Services\PublicUrlService::class)->emailVerificationUrl($notifiable);
        });

        \Illuminate\Auth\Notifications\VerifyEmail::toMailUsing(function ($notifiable, $url) {
            return new \App\Mail\VerifyEmailMailable($notifiable, $url);
        });

        Gate::define('view-warga-account-stats', function (?User $user) {
            return $user && in_array($user->role?->name, ['supervisor', 'kades', 'kepala_desa']);
        });

        Gate::define('view-internal-pending-stats', function (?User $user) {
            return $user && in_array($user->role?->name, ['staf', 'supervisor', 'kades', 'kepala_desa']);
        });

        Gate::define('view-head-approval-stats', function (?User $user) {
            return $user && in_array($user->role?->name, ['kades', 'kepala_desa', 'supervisor']);
        });

        Gate::define('view-service-summary-stats', function (?User $user) {
            return $user && in_array($user->role?->name, ['supervisor', 'kades', 'kepala_desa']);
        });

        Gate::define('view-staff-queue-data', function (?User $user) {
            return $user && in_array($user->role?->name, ['staf', 'supervisor', 'kades', 'kepala_desa']);
        });
    }
}
