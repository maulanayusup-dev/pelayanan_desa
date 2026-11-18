<?php

namespace Tests\Feature;

use App\Jobs\SendFonnteNotificationJob;
use App\Livewire\NotificationDropdown;
use App\Livewire\SktmForm;
use App\Mail\ApplicationConfirmationMailable;
use App\Mail\ApplicationStatusChangedMailable;
use App\Mail\OfficerNotificationMailable;
use App\Mail\VerifyEmailMailable;
use App\Models\Application;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ApplicationStatusChanged;
use App\Services\EmailNotificationService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Penyederhanaan kanal notifikasi.
 * Aktif: WhatsApp Fonnte, Gmail SMTP, notifikasi in-app (lonceng).
 * Dihapus: Telegram, Desktop Notification / WebPush, WhatsApp Cloud API Meta.
 */
class NotificationChannelsSimplificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $warga;
    protected User $staf;
    protected User $supervisor;
    protected User $kades;
    protected DocumentType $docType;

    private const P_STAF = '6281233334444';

    protected function setUp(): void
    {
        parent::setUp();

        $roles = [];
        foreach (['masyarakat', 'staf', 'supervisor', 'kades'] as $name) {
            $roles[$name] = Role::firstOrCreate(['name' => $name]);
        }
        $this->docType = DocumentType::firstOrCreate(['code' => 'SKTM'], ['name' => 'Surat Keterangan Tidak Mampu']);

        $this->warga = $this->user($roles['masyarakat'], 'Warga Siti', 'warga@desa.id', '081211112222');
        $this->staf = $this->user($roles['staf'], 'Staf Doni', 'staf@desa.id', '081233334444');
        $this->supervisor = $this->user($roles['supervisor'], 'Supervisor Rahmat', 'supervisor@desa.id', '081255556666');
        $this->kades = $this->user($roles['kades'], 'Kades Subagyo', 'kades@desa.id', '081277778888');

        Config::set('services.fonnte.enabled', true);
        Config::set('services.fonnte.token', 'fake_fonnte_token');
        Config::set('services.fonnte.use_queue', false);

        Http::fake([
            'https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => ['fnt_ok']], 200),
            '*' => Http::response([], 200),
        ]);
        Storage::fake('local');
        Cache::flush();
        EmailNotificationService::resetDeduplication();
    }

    private function user(Role $role, string $name, string $email, string $phone): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'phone_number' => $phone,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function application(string $status = 'SUBMITTED'): Application
    {
        return Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-CH-' . strtoupper(Str::random(6)),
            'user_id' => $this->warga->id,
            'document_type_id' => $this->docType->id,
            'status' => $status,
            'submitted_at' => now(),
        ]);
    }

    private function submitSktmAsCitizen(): Application
    {
        $this->actingAs($this->warga);

        Livewire::test(SktmForm::class)
            ->set('nik', '3201123456789012')->set('no_kk', '3201123456789013')
            ->set('name', 'Warga Siti')->set('place_of_birth', 'Cirebon')->set('date_of_birth', '1995-05-15')
            ->set('gender', 'P')->set('address', 'Jl. Melati')->set('rt', '01')->set('rw', '02')
            ->set('desa', 'Sukamaju')->set('kecamatan', 'Sukarame')->set('kabupaten', 'Cirebon')
            ->set('occupation', 'Buruh')->set('penghasilan', '1500000')
            ->call('nextStep')
            ->set('purpose', 'Beasiswa')
            ->call('nextStep')
            ->set('ktp', UploadedFile::fake()->create('ktp.jpg', 100, 'image/jpeg'))
            ->set('kk', UploadedFile::fake()->create('kk.jpg', 100, 'image/jpeg'))
            ->call('submit')
            ->assertRedirect('/pengajuan');

        return Application::where('user_id', $this->warga->id)->latest()->firstOrFail();
    }

    private function fonnteTargets(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.fonnte.com/send'))
            ->map(fn ($pair) => $pair[0]['target'])
            ->values()->all();
    }

    private function assertNoRequestTo(string ...$hosts): void
    {
        foreach ($hosts as $host) {
            Http::assertNotSent(fn ($req) => str_contains($req->url(), $host));
        }
    }

    /** 1, 2, 7, 8: pengajuan warga berhasil; Fonnte hanya ke STAF; Gmail tetap jalan; lonceng staf terisi. */
    public function test_citizen_submission_uses_only_fonnte_gmail_and_in_app_bell(): void
    {
        Mail::fake();

        $app = $this->submitSktmAsCitizen();
        $this->assertSame('SUBMITTED', $app->status);

        // 1 & 8. WhatsApp Fonnte: hanya STAF
        $this->assertSame([self::P_STAF], $this->fonnteTargets());

        // 2. Gmail SMTP: konfirmasi ke warga + pemberitahuan tugas ke staf
        Mail::assertSent(ApplicationConfirmationMailable::class, fn ($m) => $m->hasTo('warga@desa.id'));
        Mail::assertSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo('staf@desa.id'));
        Mail::assertNotSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo('supervisor@desa.id') || $m->hasTo('kades@desa.id'));

        // Lonceng in-app: tersimpan di tabel notifications (bukan fake) untuk staf & warga
        $this->assertSame(1, $this->staf->fresh()->unreadNotifications()->count());
        $this->assertSame(1, $this->warga->fresh()->unreadNotifications()->count());
        $this->assertSame(0, $this->supervisor->fresh()->notifications()->count());
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->staf->id,
            'type' => ApplicationStatusChanged::class,
        ]);

        // 3, 4, 5. Tidak ada request ke Telegram, layanan push browser, atau WhatsApp Cloud API
        $this->assertNoRequestTo('api.telegram.org', 'fcm.googleapis.com', 'push.services.mozilla.com', 'graph.facebook.com');
    }

    /** 2 & status lain: email perubahan status warga tetap terkirim; SUPERVISOR_REVIEW nonaktif dan COMPLETED -> staf. */
    public function test_status_change_email_and_other_rules_unchanged(): void
    {
        Mail::fake();

        NotificationService::notifyStatusChange($this->application('REJECTED'));
        NotificationService::notifyStatusChange($this->application('HEAD_APPROVAL'));
        NotificationService::notifyStatusChange($this->application('SUPERVISOR_REVIEW'));
        NotificationService::notifyStatusChange($this->application('COMPLETED'));

        Mail::assertSent(ApplicationStatusChangedMailable::class, fn ($m) => $m->hasTo('warga@desa.id'));
        Mail::assertSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo('kades@desa.id'));
        Mail::assertNotSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo('supervisor@desa.id'));
        $this->assertContains(self::P_STAF, $this->fonnteTargets());           // COMPLETED/REJECTED -> staf
        $this->assertContains('6281277778888', $this->fonnteTargets());        // HEAD_APPROVAL -> kades
        $this->assertNotContains('6281255556666', $this->fonnteTargets());     // SUPERVISOR_REVIEW tidak lagi dikirim ke supervisor
        $this->assertNoRequestTo('api.telegram.org', 'graph.facebook.com');
    }

    /** 3. Telegram benar-benar hilang: kelas, route, halaman admin, dan konfigurasi. */
    public function test_telegram_is_fully_removed(): void
    {
        foreach (['App\Services\TelegramNotificationService', 'App\Http\Controllers\TelegramController',
                     'App\Livewire\AdminTelegramSettings', 'App\Console\Commands\TelegramPollCommand'] as $class) {
            $this->assertFalse(class_exists($class), "{$class} masih ada");
        }
        foreach (['telegram.token', 'telegram.unlink', 'telegram.test', 'telegram.webhook', 'admin.telegram'] as $name) {
            $this->assertFalse(Route::has($name), "Route {$name} masih terdaftar");
        }
        $this->assertNull(config('services.telegram'));

        $this->actingAs($this->supervisor)->get('/admin/telegram')->assertNotFound();
        $this->post('/telegram/webhook', [])->assertNotFound();
        $this->post('/api/telegram/webhook', [])->assertNotFound();
    }

    /** 4 & 5. WebPush / desktop notification dihapus, tetapi notifikasi in-app tetap dikirim lewat channel database. */
    public function test_webpush_and_desktop_notifications_are_removed_while_in_app_stays(): void
    {
        $notification = new ApplicationStatusChanged($this->application(), 'Uji', 'info');
        $this->assertSame(['database'], $notification->via($this->staf));
        $this->assertFalse(method_exists($notification, 'toWebPush'));

        $this->assertFalse(class_exists('NotificationChannels\WebPush\WebPushChannel'), 'Paket webpush masih terpasang');
        $this->assertFalse(class_exists('App\Http\Controllers\WebPushController'));
        $this->assertFalse(class_exists('App\Models\PushSubscription'));
        $this->assertFalse(method_exists($this->staf, 'updatePushSubscription'));
        foreach (['webpush.key', 'webpush.subscribe', 'webpush.unsubscribe', 'webpush.status', 'webpush.destroy', 'webpush.test'] as $name) {
            $this->assertFalse(Route::has($name), "Route {$name} masih terdaftar");
        }
        $this->assertNull(config('webpush'));

        // Service worker pembersih: tidak menampilkan notifikasi, mencabut langganan & dirinya sendiri
        $sw = file_get_contents(public_path('sw.js'));
        $this->assertStringNotContainsString("addEventListener('push'", $sw);
        $this->assertStringNotContainsString('showNotification', $sw);
        $this->assertStringContainsString('unsubscribe()', $sw);
        $this->assertStringContainsString('registration.unregister()', $sw);

        // Tidak ada kode front-end yang meminta izin notifikasi browser
        $this->assertFileDoesNotExist(resource_path('js/webpush.js'));
        foreach (glob(resource_path('js/*.js')) as $js) {
            $source = file_get_contents($js);
            $this->assertStringNotContainsString('Notification.requestPermission', $source, $js);
            $this->assertStringNotContainsString('new Notification(', $source, $js);
            $this->assertStringNotContainsString('pushManager.subscribe', $source, $js);
        }
    }

    /** UI: halaman profil hanya menampilkan kanal aktif. */
    public function test_profile_page_shows_only_active_channels(): void
    {
        $response = $this->actingAs($this->staf)->get('/profile')->assertOk();

        $response->assertSee('Kanal Notifikasi');
        $response->assertSee('WhatsApp');
        $response->assertSee('Email');
        $response->assertSee('Notifikasi Aplikasi');
        $response->assertDontSee('Telegram');
        $response->assertDontSee('Notifikasi Desktop');
        $response->assertDontSee('window.WebPush', false);

        $this->actingAs($this->supervisor)->get('/admin/dashboard')->assertOk()->assertDontSee('Pengaturan Telegram');
    }

    /** 6. Email verifikasi akun tetap berjalan. */
    public function test_email_verification_still_sent(): void
    {
        Mail::fake();

        $user = User::create([
            'name' => 'Warga Baru', 'email' => 'baru@desa.id', 'password' => bcrypt('password'),
            'role_id' => Role::where('name', 'masyarakat')->first()->id, 'is_active' => true,
        ]);

        $this->assertTrue($user->sendEmailVerificationNotification());
        Mail::assertSent(VerifyEmailMailable::class, fn ($m) => $m->hasTo('baru@desa.id'));
        $this->assertNoRequestTo('api.telegram.org', 'graph.facebook.com');
    }

    /** 9. Kegagalan WhatsApp tidak memengaruhi email (dan pengajuan tetap tersimpan). */
    public function test_whatsapp_failure_does_not_affect_email(): void
    {
        Http::fake(['https://api.fonnte.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Fonnte down')]);
        Mail::fake();

        $app = $this->submitSktmAsCitizen();

        $this->assertDatabaseHas('applications', ['id' => $app->id, 'status' => 'SUBMITTED']);
        Mail::assertSent(ApplicationConfirmationMailable::class, fn ($m) => $m->hasTo('warga@desa.id'));
        Mail::assertSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo('staf@desa.id'));
        $this->assertSame(1, $this->staf->fresh()->unreadNotifications()->count());
    }

    /** 10. Kegagalan email tidak memengaruhi WhatsApp (dan pengajuan tetap tersimpan). */
    public function test_email_failure_does_not_affect_whatsapp(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP Gmail down'));

        $app = $this->submitSktmAsCitizen();

        $this->assertDatabaseHas('applications', ['id' => $app->id, 'status' => 'SUBMITTED']);
        $this->assertSame([self::P_STAF], $this->fonnteTargets());
        $this->assertSame(1, $this->staf->fresh()->unreadNotifications()->count());
    }

    /** 11. Tidak ada pemanggilan ganda pada ketiga kanal aktif. */
    public function test_repeated_trigger_does_not_duplicate_any_active_channel(): void
    {
        Mail::fake();
        $app = $this->application();

        NotificationService::notifyStatusChange($app);
        EmailNotificationService::resetDeduplication(); // simulasi request lain
        NotificationService::notifyStatusChange($app);

        $this->assertSame([self::P_STAF], $this->fonnteTargets());
        Mail::assertSent(OfficerNotificationMailable::class, 1);
        $this->assertSame(1, $this->staf->fresh()->notifications()->count());
    }

    /** Queue Fonnte tetap dipakai: job didorong setelah commit, hanya untuk staf. */
    public function test_fonnte_queue_still_used_after_commit(): void
    {
        Config::set('services.fonnte.use_queue', true);
        Queue::fake();

        \Illuminate\Support\Facades\DB::transaction(function () {
            NotificationService::notifyStatusChange($this->application());
            Queue::assertNotPushed(SendFonnteNotificationJob::class);
        });

        Queue::assertPushed(SendFonnteNotificationJob::class, 1);
        Queue::assertPushed(SendFonnteNotificationJob::class, fn ($job) => $job->targetPhone === self::P_STAF);
    }

    /** Lonceng in-app: baca, tandai dibaca, tandai semua; riwayat & tabel lama tetap ada. */
    public function test_in_app_bell_history_and_old_tables_are_preserved(): void
    {
        NotificationService::notifyStatusChange($this->application());
        NotificationService::notifyStatusChange($this->application());

        $this->actingAs($this->staf);
        $first = $this->staf->notifications()->first();

        Livewire::test(NotificationDropdown::class)
            ->assertSet('unreadCount', 2)
            ->call('markAsRead', $first->id)
            ->assertSet('unreadCount', 1)
            ->call('markAllAsRead')
            ->assertSet('unreadCount', 0);

        $this->assertSame(2, $this->staf->notifications()->count(), 'Riwayat lonceng tidak boleh hilang');

        // Tidak ada migrasi penghapusan: tabel riwayat lama tetap tersedia
        foreach (['notifications', 'push_subscriptions', 'telegram_notification_logs', 'telegram_settings', 'whatsapp_notification_logs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Tabel {$table} harus tetap ada");
        }

        // Konfigurasi kanal aktif tetap utuh
        $this->assertNotNull(config('services.fonnte'));
        $this->assertNotNull(config('mail.mailers.smtp'));
    }

    // ------------------------------------------------------------------
    // Dipindahkan dari WebPushNotificationTest (file dihapus): perilaku lonceng in-app yang masih aktif
    // ------------------------------------------------------------------

    /** Lonceng: pengajuan baru → warga (URL portal warga) & HANYA staf (URL admin); supervisor/kades tidak. */
    public function test_bell_new_submission_notifies_applicant_and_only_staff(): void
    {
        Notification::fake();
        $application = $this->application('SUBMITTED');

        NotificationService::notifyStatusChange($application);

        Notification::assertSentTo($this->warga, ApplicationStatusChanged::class, function ($n) use ($application) {
            return $n->application->id === $application->id
                && $n->actionUrl === '/pengajuan/' . $application->id
                && $n->via($this->warga) === ['database'];
        });
        Notification::assertSentTo($this->staf, ApplicationStatusChanged::class, function ($n) use ($application) {
            return $n->application->id === $application->id
                && $n->resolveActionUrlFor($this->staf) === '/admin/pengajuan/' . $application->id
                && $n->via($this->staf) === ['database'];
        });
        Notification::assertNotSentTo($this->supervisor, ApplicationStatusChanged::class);
        Notification::assertNotSentTo($this->kades, ApplicationStatusChanged::class);
    }

    /** Lonceng: status disetujui → warga menerima notifikasi bertipe success berisi "disetujui". */
    public function test_bell_status_change_to_approved_notifies_applicant(): void
    {
        Notification::fake();

        NotificationService::notifyStatusChange($this->application('APPROVED'));

        Notification::assertSentTo($this->warga, ApplicationStatusChanged::class, function ($n) {
            return $n->type === 'success' && str_contains($n->message, 'disetujui');
        });
    }

    // ------------------------------------------------------------------
    // WhatsApp Cloud API Meta: tidak ada kode, konfigurasi, job, maupun request yang tersisa
    // ------------------------------------------------------------------

    public function test_whatsapp_cloud_api_meta_is_removed_and_never_called_in_any_workflow_stage(): void
    {
        Mail::fake();
        Queue::fake();
        Config::set('services.fonnte.use_queue', false);

        // Kode & konfigurasi Meta sudah tidak ada
        foreach (['App\Services\WhatsAppCloudApiService', 'App\Jobs\SendWhatsAppNotificationJob',
                     'App\Console\Commands\WhatsAppTestCommand'] as $class) {
            $this->assertFalse(class_exists($class), "{$class} masih ada");
        }
        $this->assertNull(config('services.whatsapp'));
        $this->assertNull(config('services.whatsapp_cloud'));
        $this->assertArrayNotHasKey('whatsapp:test', \Illuminate\Support\Facades\Artisan::all());

        // Pengajuan baru lewat form warga + seluruh tahap workflow
        $this->submitSktmAsCitizen();
        foreach (['UNDER_REVIEW', 'NEEDS_REVISION', 'SUPERVISOR_REVIEW', 'HEAD_APPROVAL', 'APPROVED', 'REJECTED', 'COMPLETED'] as $status) {
            NotificationService::notifyStatusChange($this->application($status));
        }

        // WhatsApp hanya lewat Fonnte; tidak ada satu pun request ke Graph API Meta
        $this->assertNoRequestTo('graph.facebook.com', 'facebook.com', 'whatsapp.net');
        $hosts = collect(Http::recorded())->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_HOST))->unique()->values()->all();
        $this->assertSame(['api.fonnte.com'], $hosts, 'Semua request HTTP notifikasi hanya ke Fonnte');
        $this->assertContains(self::P_STAF, $this->fonnteTargets(), 'Pengajuan baru tetap dikirim ke STAF via Fonnte');

        // Tidak ada job WhatsApp lain selain Fonnte yang didorong ke antrean
        Queue::assertNotPushed('App\Jobs\SendWhatsAppNotificationJob');
    }
}
