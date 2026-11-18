<?php

namespace Tests\Feature;

use App\Jobs\SendFonnteReportNotificationJob;
use App\Livewire\NotificationDropdown;
use App\Livewire\Reports\CreateReport;
use App\Mail\ReportNotificationMailable;
use App\Models\EmailNotificationLog;
use App\Models\Role;
use App\Models\SystemReport;
use App\Models\User;
use App\Models\WhatsAppNotificationLog;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class ReportUiEndToEndNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Role $roleWarga;
    private Role $roleStaf;
    private Role $roleSupervisor;
    private User $warga;
    private User $staf;
    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleWarga = Role::firstOrCreate(['name' => 'masyarakat']);
        $this->roleStaf = Role::firstOrCreate(['name' => 'staf']);
        $this->roleSupervisor = Role::firstOrCreate(['name' => 'supervisor']);

        $this->warga = User::create([
            'name' => 'Warga E2E UI',
            'email' => 'warga.e2e@gmail.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role_id' => $this->roleWarga->id,
            'phone_number' => '081210001001',
            'is_active' => true,
        ]);

        $this->staf = User::create([
            'name' => 'Staf E2E UI',
            'email' => 'staf.e2e@gmail.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role_id' => $this->roleStaf->id,
            'phone_number' => '081210001002',
            'is_active' => true,
        ]);

        $this->supervisor = User::create([
            'name' => 'Supervisor E2E UI',
            'email' => 'supervisor.e2e@gmail.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role_id' => $this->roleSupervisor->id,
            'phone_number' => '083899990778',
            'is_active' => true,
        ]);

        Config::set('app.url', 'http://127.0.0.1:8000');
        Config::set('queue.default', 'database');
        Config::set('services.fonnte.enabled', true);
        Config::set('services.fonnte.token', 'e2e_secret_fonnte_tok_123');
        Config::set('services.fonnte.use_queue', true);
        Config::set('mail.enforce_dummy_block', true);
        Cache::flush();
    }

    public function test_masyarakat_and_staf_submit_report_via_ui_delivering_bell_whatsapp_job_and_gmail_with_trace_and_no_duplicates(): void
    {
        Mail::fake();

        $capturedLogs = [];
        Log::listen(function ($entry) use (&$capturedLogs) {
            $capturedLogs[] = ['level' => $entry->level, 'message' => $entry->message, 'context' => $entry->context];
        });

        // 1. Masyarakat membuka route UI /laporan/buat & mengirim laporan melalui komponen Livewire CreateReport
        $this->actingAs($this->warga)->get(route('reports.create'))->assertOk();

        $sensitiveDescWarga = 'RAHASIA_KRONOLOGI_WARGA_TIDAK_BOLEH_KE_LOG_ERROR';
        $resWarga = Livewire::actingAs($this->warga)
            ->test(CreateReport::class)
            ->set('category', SystemReport::CATEGORY_BUG)
            ->set('priority', SystemReport::PRIORITY_HIGH)
            ->set('title', 'Uji E2E laporan masyarakat dari UI')
            ->set('description', $sensitiveDescWarga)
            ->call('submit');

        $reportWarga = SystemReport::where('reporter_id', $this->warga->id)->latest('created_at')->firstOrFail();
        $resWarga->assertRedirect(route('reports.show', $reportWarga));

        // 2. Staf membuka route UI /laporan/buat & mengirim laporan melalui komponen Livewire CreateReport
        $this->actingAs($this->staf)->get(route('reports.create'))->assertOk();

        $resStaf = Livewire::actingAs($this->staf)
            ->test(CreateReport::class)
            ->set('category', SystemReport::CATEGORY_NOTIFIKASI)
            ->set('priority', SystemReport::PRIORITY_MEDIUM)
            ->set('title', 'Uji E2E laporan staf dari UI')
            ->set('description', 'Deskripsi lengkap kendala operasional oleh staf pelayanan desa.')
            ->call('submit');

        $reportStaf = SystemReport::where('reporter_id', $this->staf->id)->latest('created_at')->firstOrFail();
        $resStaf->assertRedirect(route('reports.show', $reportStaf));

        // 3. Supervisor menerima notifikasi lonceng aplikasi (2 notif) & dapat melihatnya di NotificationDropdown
        $this->assertSame(2, $this->supervisor->notifications()->count());
        $this->assertSame(0, $this->staf->notifications()->count());
        $this->assertSame(0, $this->warga->notifications()->count());

        $this->actingAs($this->supervisor);
        Livewire::test(NotificationDropdown::class)->assertSet('unreadCount', 2);

        // 4. Job WhatsApp benar-benar masuk database queue ('laporan-prioritas' & 'laporan') + status log awal 'QUEUED'
        $this->assertSame(1, DB::table('jobs')->where('queue', 'laporan-prioritas')->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'laporan')->count());
        $this->assertDatabaseHas('whatsapp_notification_logs', [
            'user_id' => $this->supervisor->id,
            'message_type' => 'report:' . $reportWarga->id,
            'status' => 'QUEUED',
            'message_id' => null,
        ]);
        $this->assertDatabaseHas('whatsapp_notification_logs', [
            'user_id' => $this->supervisor->id,
            'message_type' => 'report:' . $reportStaf->id,
            'status' => 'QUEUED',
            'message_id' => null,
        ]);

        // 5. Gmail benar-benar dikirim & tercatat SMTP_ACCEPTED untuk kedua laporan UI
        Mail::assertSent(ReportNotificationMailable::class, 2);
        Mail::assertSent(ReportNotificationMailable::class, fn (ReportNotificationMailable $m) => $m->hasTo('supervisor.e2e@gmail.com') && $m->report->id === $reportWarga->id);
        Mail::assertSent(ReportNotificationMailable::class, fn (ReportNotificationMailable $m) => $m->hasTo('supervisor.e2e@gmail.com') && $m->report->id === $reportStaf->id);
        $this->assertDatabaseHas('email_notification_logs', [
            'user_id' => $this->supervisor->id,
            'type' => 'REPORT_CREATED:' . $reportWarga->ticket_number,
            'status' => 'SMTP_ACCEPTED',
        ]);
        $this->assertDatabaseHas('email_notification_logs', [
            'user_id' => $this->supervisor->id,
            'type' => 'REPORT_CREATED:' . $reportStaf->ticket_number,
            'status' => 'SMTP_ACCEPTED',
        ]);

        // 6. Proses job antrean melalui worker nyata (--queue=sktm,laporan-prioritas,laporan,default)
        Http::fake([
            'https://api.fonnte.com/send' => Http::sequence()
                ->push(['status' => true, 'id' => ['fnt-e2e-warga-1'], 'process' => 'pending'], 200)
                ->push(['status' => true, 'id' => ['fnt-e2e-staf-2'], 'process' => 'sent'], 200),
        ]);

        for ($i = 0; $i < 2; $i++) {
            Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => 'sktm,laporan-prioritas,laporan,default',
                '--once' => true,
            ]);
        }

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        Http::assertSentCount(2);

        $this->assertDatabaseHas('whatsapp_notification_logs', [
            'message_type' => 'report:' . $reportWarga->id,
            'status' => 'PENDING',
            'message_id' => 'fnt-e2e-warga-1',
        ]);
        $this->assertDatabaseHas('whatsapp_notification_logs', [
            'message_type' => 'report:' . $reportStaf->id,
            'status' => 'SENT',
            'message_id' => 'fnt-e2e-staf-2',
        ]);

        // 7. Pastikan seluruh 8 tahapan ReportNotificationTrace tercatat rapi dengan correlation_id yang sama dan tanpa kebocoran rahasia
        $traceForWarga = collect($capturedLogs)->filter(
            fn ($item) => str_starts_with($item['message'], 'ReportNotificationTrace:')
                && (($item['context']['report_id'] ?? null) === $reportWarga->id
                    || ($item['context']['context'] ?? null) === 'report:' . $reportWarga->id)
        );

        $traceMessages = $traceForWarga->pluck('message')->all();
        foreach ([
            'ReportNotificationTrace: Report created.',
            'ReportNotificationTrace: Notification service invoked.',
            'ReportNotificationTrace: Supervisor recipients resolved.',
            'ReportNotificationTrace: WhatsApp job dispatched.',
            'ReportNotificationTrace: WhatsApp job processed.',
            'ReportNotificationTrace: Fonnte response received.',
            'ReportNotificationTrace: Gmail send attempted.',
            'ReportNotificationTrace: SMTP response received.',
        ] as $expectedStage) {
            $this->assertContains($expectedStage, $traceMessages, "Stage hilang: {$expectedStage}");
        }

        $correlationIds = $traceForWarga->pluck('context.correlation_id')->filter()->unique();
        $this->assertCount(1, $correlationIds, 'Seluruh proses dari UI sampai worker harus berbagi correlation_id yang konsisten.');

        $serializedAllLogs = json_encode($capturedLogs);
        $this->assertStringNotContainsString('e2e_secret_fonnte_tok_123', (string) $serializedAllLogs);
        $this->assertStringNotContainsString($sensitiveDescWarga, (string) $serializedAllLogs);

        // 8. Tidak ada pengiriman ganda ketika event dipicu ulang untuk laporan yang sama
        app(\App\Services\ReportNotificationService::class)->notifyReportCreated($reportWarga);
        $this->assertSame(2, $this->supervisor->notifications()->count());
        $this->assertSame(0, DB::table('jobs')->count());
        Mail::assertSent(ReportNotificationMailable::class, 2);
    }

    public function test_ui_channel_failure_isolation_bell_whatsapp_and_gmail_remain_independent(): void
    {
        // Skenario A: WhatsApp gagal (Fonnte HTTP 500) saat submit dari UI -> Lonceng dan Gmail TETAP berhasil
        Mail::fake();
        Http::fake([
            'https://api.fonnte.com/*' => Http::response(['status' => false, 'reason' => 'Device disconnected'], 500),
        ]);

        Livewire::actingAs($this->warga)
            ->test(CreateReport::class)
            ->set('category', SystemReport::CATEGORY_BUG)
            ->set('priority', SystemReport::PRIORITY_HIGH)
            ->set('title', 'Uji isolasi ketika Fonnte error dari UI')
            ->set('description', 'Memastikan lonceng dan Gmail tetap berjalan meski Fonnte error.')
            ->call('submit')
            ->assertRedirect();

        $reportA = SystemReport::where('title', 'Uji isolasi ketika Fonnte error dari UI')->firstOrFail();
        $this->assertSame(1, $this->supervisor->notifications()->count());
        Mail::assertSent(ReportNotificationMailable::class, 1);

        // Jalankan job WA -> gagal masuk log FAILED namun tidak mengganggu lonceng & email sebelumnya
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'laporan-prioritas',
            '--once' => true,
        ]);
        $this->assertDatabaseHas('whatsapp_notification_logs', [
            'message_type' => 'report:' . $reportA->id,
            'status' => 'FAILED',
        ]);
        DB::table('jobs')->delete();

        // Skenario B: Gmail SMTP gagal (530 auth) saat submit dari UI -> Lonceng dan Job WhatsApp TETAP berhasil
        $secretPass = 'smtp-secret-e2e-ui-pass';
        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', 'smtp.gmail.com');
        Config::set('mail.mailers.smtp.port', 587);
        Config::set('mail.mailers.smtp.username', 'desa@gmail.com');
        Config::set('mail.mailers.smtp.password', $secretPass);
        Config::set('mail.from.address', 'desa@gmail.com');

        $originalMailer = Mail::getFacadeRoot();
        Mail::shouldReceive('getFacadeRoot')->andReturn(new \stdClass());
        Mail::shouldReceive('to')->andThrow(new TransportException("530 Authentication Required {$secretPass}"));

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => ['fnt-iso-2'], 'process' => 'sent'], 200),
        ]);

        Livewire::actingAs($this->staf)
            ->test(CreateReport::class)
            ->set('category', SystemReport::CATEGORY_NOTIFIKASI)
            ->set('priority', SystemReport::PRIORITY_MEDIUM)
            ->set('title', 'Uji isolasi ketika SMTP error dari UI')
            ->set('description', 'Memastikan lonceng dan WhatsApp tetap jalan saat SMTP gagal.')
            ->call('submit')
            ->assertRedirect();

        $reportB = SystemReport::where('title', 'Uji isolasi ketika SMTP error dari UI')->firstOrFail();
        $this->assertSame(2, $this->supervisor->notifications()->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'laporan')->count());
        $emailFail = EmailNotificationLog::where('type', 'REPORT_CREATED:' . $reportB->ticket_number)->firstOrFail();
        $this->assertSame('SMTP_FAILED', $emailFail->status);
        $this->assertStringNotContainsString($secretPass, (string) $emailFail->error_message);

        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'laporan',
            '--once' => true,
        ]);
        $this->assertDatabaseHas('whatsapp_notification_logs', [
            'message_type' => 'report:' . $reportB->id,
            'status' => 'SENT',
            'message_id' => 'fnt-iso-2',
        ]);

        // Skenario C: Notifikasi lonceng melempar exception saat submit dari UI -> WhatsApp job dan Gmail TETAP berhasil
        Mail::swap($originalMailer);
        Mail::fake();
        Config::set('mail.default', 'array');

        $originalDispatcher = app(NotificationDispatcher::class);
        $failingDispatcher = \Mockery::mock(NotificationDispatcher::class);
        $failingDispatcher->shouldReceive('send')->andThrow(new \RuntimeException('Simulasi kegagalan tabel notifications'));
        app()->instance(NotificationDispatcher::class, $failingDispatcher);

        try {
            Livewire::actingAs($this->warga)
                ->test(CreateReport::class)
                ->set('category', SystemReport::CATEGORY_LAINNYA)
                ->set('priority', SystemReport::PRIORITY_HIGH)
                ->set('title', 'Uji isolasi ketika lonceng gagal dari UI')
                ->set('description', 'Memastikan WhatsApp dan Gmail tetap jalan meski lonceng gagal.')
                ->call('submit')
                ->assertRedirect();
        } finally {
            app()->instance(NotificationDispatcher::class, $originalDispatcher);
        }

        $reportC = SystemReport::where('title', 'Uji isolasi ketika lonceng gagal dari UI')->firstOrFail();
        $this->assertSame(2, $this->supervisor->notifications()->count()); // tidak bertambah karena gagal
        $this->assertSame(1, DB::table('jobs')->where('queue', 'laporan-prioritas')->count());
        Mail::assertSent(ReportNotificationMailable::class, fn (ReportNotificationMailable $m) => $m->report->id === $reportC->id);
        $this->assertDatabaseHas('email_notification_logs', [
            'type' => 'REPORT_CREATED:' . $reportC->ticket_number,
            'status' => 'SMTP_ACCEPTED',
        ]);
    }
}

