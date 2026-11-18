<?php

namespace Tests\Feature;

use App\Jobs\SendFonnteReportNotificationJob;
use App\Livewire\Admin\ReportCenter;
use App\Livewire\Admin\ReportDetail as AdminReportDetail;
use App\Livewire\AdminDashboard;
use App\Livewire\NotificationDropdown;
use App\Livewire\Reports\ReportDetail as ReporterReportDetail;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\SystemReport;
use App\Models\SystemReportHistory;
use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\ReportNotification;
use App\Services\ReportNotificationService;
use App\Services\SystemReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SupervisorDashboardAndReportStep3Test extends TestCase
{
    use RefreshDatabase;

    private Role $masyarakatRole;
    private Role $stafRole;
    private Role $supervisorRole;
    private Role $kadesRole;
    private DocumentType $sktmType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->masyarakatRole = Role::firstOrCreate(['name' => 'masyarakat']);
        $this->stafRole = Role::firstOrCreate(['name' => 'staf']);
        $this->supervisorRole = Role::firstOrCreate(['name' => 'supervisor']);
        $this->kadesRole = Role::firstOrCreate(['name' => 'kades']);
        $this->sktmType = DocumentType::firstOrCreate(
            ['code' => 'SKTM'],
            ['name' => 'Surat Keterangan Tidak Mampu', 'is_active' => true]
        );

        Cache::flush();
        Storage::fake('local');
    }

    private function createUser(Role $role, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => $role->id,
            'is_active' => true,
            'email_verified_at' => now(),
            'phone_number' => '0812' . random_int(10000000, 99999999),
        ], $overrides));
    }

    private function makeSktmApplication(User $owner, string $status, string $number = null): Application
    {
        return Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => $number ?? 'SKTM-SPV-' . strtoupper(Str::random(5)),
            'user_id' => $owner->id,
            'document_type_id' => $this->sktmType->id,
            'status' => $status,
            'purpose' => 'Bantuan Pendidikan',
            'submitted_at' => now(),
        ]);
    }

    private function validPngUpload(string $filename = 'bukti_step3.png'): UploadedFile
    {
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent($filename, $pngBytes);
    }

    public function test_supervisor_dashboard_shows_sktm_and_report_statistics_without_active_supervisor_review_stage(): void
    {
        $warga = $this->createUser($this->masyarakatRole, ['name' => 'Warga Pantauan']);
        $staf = $this->createUser($this->stafRole, ['name' => 'Staf Monitor']);
        $kades = $this->createUser($this->kadesRole, ['name' => 'Kades Monitor']);
        $supervisor = $this->createUser($this->supervisorRole, ['name' => 'Supervisor Pusat']);

        // SKTM across direct Masyarakat -> Staf -> Kepala Desa workflow
        $appSubmitted = $this->makeSktmApplication($warga, 'SUBMITTED', 'SKTM-STEP3-001');
        $this->makeSktmApplication($warga, 'UNDER_REVIEW', 'SKTM-STEP3-002');
        $this->makeSktmApplication($warga, 'NEEDS_REVISION', 'SKTM-STEP3-003');
        $this->makeSktmApplication($warga, 'HEAD_APPROVAL', 'SKTM-STEP3-004');
        $this->makeSktmApplication($warga, 'COMPLETED', 'SKTM-STEP3-005');
        $this->makeSktmApplication($warga, 'REJECTED', 'SKTM-STEP3-006');

        // Reports across different lifecycle states
        $reportService = app(SystemReportService::class);
        $reportOpen = $reportService->createReport($warga, [
            'category' => SystemReport::CATEGORY_BUG,
            'priority' => SystemReport::PRIORITY_CRITICAL,
            'title' => 'Tombol unduh PDF macet',
            'description' => 'Warga tidak bisa membuka tombol unduh di ponsel Android.',
        ]);

        $reportInProgress = $reportService->createReport($staf, [
            'category' => SystemReport::CATEGORY_NOTIFIKASI,
            'priority' => SystemReport::PRIORITY_HIGH,
            'title' => 'Delay WhatsApp staf',
            'description' => 'Notifikasi pengajuan baru lambat masuk pada jam sibuk.',
        ]);
        $reportService->updateStatus($reportInProgress, $supervisor, SystemReport::STATUS_IN_PROGRESS, null, 'Sedang diperiksa');

        $reportResolved = $reportService->createReport($kades, [
            'category' => SystemReport::CATEGORY_DOKUMEN,
            'priority' => SystemReport::PRIORITY_LOW,
            'title' => 'Spasi kop surat SKTM',
            'description' => 'Mohon dirapikan jarak margin atas surat keterangan resmi.',
        ]);
        $reportService->updateStatus($reportResolved, $supervisor, SystemReport::STATUS_RESOLVED, 'Margin kop surat telah diperbarui.');

        $this->actingAs($supervisor);

        $component = Livewire::test(AdminDashboard::class);
        $component->assertOk()
            ->assertViewHas('reportStats', [
                'total' => 3,
                'open' => 1,
                'in_progress' => 1,
                'resolved' => 1,
                'rejected' => 0,
                'critical_high' => 2,
            ])
            ->assertViewHas('sktmOverview', function (array $overview): bool {
                return $overview['total'] === 6
                    && $overview['staf_queue'] === 2
                    && $overview['needs_revision'] === 1
                    && $overview['head_approval'] === 1
                    && $overview['completed'] === 1
                    && $overview['rejected'] === 1
                    && $overview['historical_supervisor_review'] === 0;
            })
            ->assertSee('Ringkasan Sistem Laporan & Keluhan', false)
            ->assertSee('Monitoring Pengajuan SKTM Desa (Read-Only)')
            ->assertSee('Laporan & Keluhan Terbaru', false)
            ->assertSee('Aktivitas Terbaru')
            ->assertSee($reportOpen->ticket_number)
            ->assertSee('Tombol unduh PDF macet')
            ->assertSee($appSubmitted->application_number)
            ->assertDontSee('Antrean Review Dokumen')
            ->assertDontSee('Arsip Historis:');

        // If historical SUPERVISOR_REVIEW rows exist in legacy data, show historical archive note ONLY (not as active workflow stage)
        $this->makeSktmApplication($warga, 'SUPERVISOR_REVIEW', 'SKTM-LEGACY-999');
        Livewire::test(AdminDashboard::class)
            ->assertSee('Arsip Historis')
            ->assertSee('SUPERVISOR_REVIEW');
    }

    public function test_report_center_supports_search_by_reporter_name_status_filter_and_pagination(): void
    {
        $wargaAlpha = $this->createUser($this->masyarakatRole, ['name' => 'Budi Santoso Unik']);
        $stafBeta = $this->createUser($this->stafRole, ['name' => 'Rina Kartika Staf']);
        $supervisor = $this->createUser($this->supervisorRole, ['name' => 'Supervisor Center']);

        $service = app(SystemReportService::class);
        for ($i = 1; $i <= 11; $i++) {
            $service->createReport($stafBeta, [
                'category' => SystemReport::CATEGORY_BUG,
                'priority' => SystemReport::PRIORITY_LOW,
                'title' => "Tiket Reguler Nomor {$i}",
                'description' => "Deskripsi kendala teknis nomor urut {$i} untuk menguji pagination.",
            ]);
        }

        $targetReport = $service->createReport($wargaAlpha, [
            'category' => SystemReport::CATEGORY_KENDALA_AKUN,
            'priority' => SystemReport::PRIORITY_HIGH,
            'title' => 'Kendala aktivasi akun baru',
            'description' => 'Email aktivasi tidak diterima saat mendaftar.',
        ]);
        // Pastikan $targetReport berurutan paling atas (lebih baru dari batch loop)
        $targetReport->forceFill(['created_at' => now()->addSeconds(5)])->save();

        $this->actingAs($supervisor);

        // Search by reporter name + priority/status filter + pagination (12 items -> page 2 exists)
        Livewire::test(ReportCenter::class)
            ->assertSee('BARU')
            ->assertSee($targetReport->ticket_number)
            ->set('search', 'Budi Santoso Unik')
            ->assertSee($targetReport->ticket_number)
            ->assertDontSee('Tiket Reguler Nomor 1')
            ->call('resetFilters')
            ->set('statusFilter', SystemReport::STATUS_OPEN)
            ->assertSee($targetReport->ticket_number)
            ->call('gotoPage', 2)
            ->assertSee('Kelola');
    }

    public function test_supervisor_can_inspect_reporter_reply_add_internal_note_and_change_status_with_audit_trail(): void
    {
        Queue::fake();
        Config::set('services.fonnte.enabled', true);
        Config::set('services.fonnte.token', 'step3-test-fonnte');
        Config::set('services.fonnte.use_queue', true);

        $warga = $this->createUser($this->masyarakatRole, [
            'name' => 'Dewi Pelapor',
            'email' => 'dewi.pelapor@desa.id',
            'phone_number' => '081277770001',
        ]);
        $otherCitizen = $this->createUser($this->masyarakatRole, ['name' => 'Warga Asing']);
        $supervisor = $this->createUser($this->supervisorRole, ['name' => 'Supervisor Penangan']);

        $service = app(SystemReportService::class);
        $report = $service->createReport($warga, [
            'category' => SystemReport::CATEGORY_KENDALA_PENGAJUAN,
            'priority' => SystemReport::PRIORITY_MEDIUM,
            'title' => 'Form unggah KK bermasalah',
            'description' => 'Upload file KK PNG selalu memuat ulang halaman.',
        ], [$this->validPngUpload('kk_error_warga.png')]);

        $publicAttachment = $report->attachments()->firstOrFail();

        $this->actingAs($supervisor);
        Livewire::test(AdminReportDetail::class, ['report' => $report])
            ->assertSee('dewi.pelapor@desa.id')
            ->assertSee('081277770001')
            ->call('markInProgress')
            ->assertHasNoErrors()
            ->set('messageMode', 'internal')
            ->set('messageBody', 'INTERNAL ONLY: Error berasal dari ukuran temporary upload.')
            ->set('messageAttachments', [$this->validPngUpload('debug_internal.png')])
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->set('messageMode', 'public')
            ->set('messageBody', 'Kami sedang memperbaiki batas upload dan akan segera mengabarkan.')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->set('newStatus', SystemReport::STATUS_RESOLVED)
            ->set('resolutionSummary', 'Batas unggah & konfigurasi Livewire sudah diperbaiki.')
            ->call('changeStatus')
            ->assertHasNoErrors();

        $report->refresh();
        $this->assertSame(SystemReport::STATUS_RESOLVED, $report->status);
        $this->assertNotNull($report->resolved_at);

        // AuditLog & History consistency
        $this->assertDatabaseHas('audit_logs', ['action' => 'REPORT_CREATED', 'user_id' => $warga->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'REPORT_INTERNAL_NOTE_ADDED', 'user_id' => $supervisor->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'REPORT_PUBLIC_REPLIED', 'user_id' => $supervisor->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'REPORT_RESOLVED', 'user_id' => $supervisor->id]);

        $this->assertDatabaseHas('system_report_histories', [
            'report_id' => $report->id,
            'action' => SystemReportHistory::ACTION_INTERNAL_NOTE_ADDED,
        ]);

        // Reporter sees public reply & resolution summary, NEVER internal note/attachment/history
        $internalAttachment = $report->attachments()->whereNotNull('message_id')->firstOrFail();
        $this->actingAs($warga);
        Livewire::test(ReporterReportDetail::class, ['report' => $report])
            ->assertSee('Kami sedang memperbaiki batas upload dan akan segera mengabarkan.')
            ->assertSee('Batas unggah & konfigurasi Livewire sudah diperbaiki.')
            ->assertDontSee('INTERNAL ONLY: Error berasal dari ukuran temporary upload.')
            ->assertDontSee('debug_internal.png');

        // Attachment access security
        $this->get(route('reports.attachments.download', $publicAttachment))->assertOk();
        $this->get(route('reports.attachments.download', $internalAttachment))->assertForbidden();

        $this->actingAs($otherCitizen);
        $this->get(route('reports.attachments.download', $publicAttachment))->assertForbidden();
        $this->get(route('reports.attachments.download', $internalAttachment))->assertForbidden();
    }

    public function test_role_restrictions_and_report_mutation_deletion_are_blocked_across_roles(): void
    {
        $warga = $this->createUser($this->masyarakatRole);
        $staf = $this->createUser($this->stafRole);
        $kades = $this->createUser($this->kadesRole);
        $supervisor = $this->createUser($this->supervisorRole);

        $report = app(SystemReportService::class)->createReport($warga, [
            'category' => SystemReport::CATEGORY_BUG,
            'priority' => SystemReport::PRIORITY_MEDIUM,
            'title' => 'Pengujian pembatasan role',
            'description' => 'Memastikan non-supervisor tidak bisa mengakses manajemen laporan.',
        ]);

        foreach ([$warga, $staf, $kades] as $nonSupervisor) {
            $this->actingAs($nonSupervisor)->get('/admin/laporan')->assertForbidden();
            $this->actingAs($nonSupervisor)->get("/admin/laporan/{$report->id}")->assertForbidden();

            $this->assertFalse(Gate::forUser($nonSupervisor)->allows('assign', $report));
            $this->assertFalse(Gate::forUser($nonSupervisor)->allows('updateStatus', $report));
            $this->assertFalse(Gate::forUser($nonSupervisor)->allows('createInternalNote', $report));
            $this->assertFalse(Gate::forUser($nonSupervisor)->allows('update', $report));
            $this->assertFalse(Gate::forUser($nonSupervisor)->allows('delete', $report));
        }

        // Neither Supervisor nor reporter can directly update/delete raw report record via Policy
        $this->assertFalse(Gate::forUser($supervisor)->allows('update', $report));
        $this->assertFalse(Gate::forUser($supervisor)->allows('delete', $report));

        // Staf & Kades cannot read or reply to warga's report
        $this->actingAs($staf)->get("/laporan/{$report->id}")->assertForbidden();
        $this->actingAs($kades)->get("/laporan/{$report->id}")->assertForbidden();
        $this->actingAs($staf)->postJson("/api/reports/{$report->id}/messages", ['message' => 'Ilegal'])->assertForbidden();
    }

    public function test_supervisor_profile_phone_fallback_and_notifications_are_deduplicated_and_fault_tolerant(): void
    {
        Queue::fake();
        Config::set('services.fonnte.enabled', true);
        Config::set('services.fonnte.token', 'fake-fonnte-key');
        Config::set('services.fonnte.use_queue', true);

        $warga = $this->createUser($this->masyarakatRole, ['phone_number' => '081200001111']);
        // Supervisor has empty string phone on users table, valid WA number in user_profiles (matches live aseps case!)
        $supervisor = $this->createUser($this->supervisorRole, ['name' => 'asep', 'phone_number' => '']);
        UserProfile::create([
            'user_id' => $supervisor->id,
            'phone_number' => '081234567890',
        ]);

        $report = app(SystemReportService::class)->createReport($warga, [
            'category' => SystemReport::CATEGORY_BUG,
            'priority' => SystemReport::PRIORITY_HIGH,
            'title' => 'Uji notifikasi lonceng & WA profil',
            'description' => 'Memastikan notifikasi masuk ke lonceng dan fallback nomor profil WA.',
        ]);

        // 1. Web notification stored in database channel ONLY (no Telegram/WebPush)
        $notif = $supervisor->notifications()->firstOrFail();
        $this->assertSame(['database'], (new ReportNotification($report, 'uji'))->via($supervisor));
        $this->assertSame('/admin/laporan/' . $report->id, $notif->data['action_url']);

        // 2. NotificationDropdown shows notification & redirects to Admin ReportDetail
        $this->actingAs($supervisor);
        Livewire::test(NotificationDropdown::class)
            ->assertSet('unreadCount', 1)
            ->call('markAsReadAndRedirect', $notif->id)
            ->assertRedirect('/admin/laporan/' . $report->id);

        // 3. Fonnte Job dispatched using profile phone fallback formatted to 62...
        Queue::assertPushed(SendFonnteReportNotificationJob::class, 1);
        Queue::assertPushed(SendFonnteReportNotificationJob::class, fn ($job) => $job->targetPhone === '6281234567890');

        // 4. Re-invoking notifyReportCreated for same report does NOT send duplicate WhatsApp
        app(ReportNotificationService::class)->notifyReportCreated($report);
        Queue::assertPushed(SendFonnteReportNotificationJob::class, 1);

        // 5. Connection exception on synchronous Fonnte still preserves report saving
        Config::set('services.fonnte.use_queue', false);
        Http::fake([
            'https://api.fonnte.com/*' => fn () => throw new ConnectionException('Timeout simulasi'),
        ]);

        $reportFault = app(SystemReportService::class)->createReport($warga, [
            'category' => SystemReport::CATEGORY_LAINNYA,
            'priority' => SystemReport::PRIORITY_LOW,
            'title' => 'Uji toleransi kegagalan WA',
            'description' => 'Laporan tetap tersimpan meskipun Fonnte tidak dapat dihubungi.',
        ]);

        $this->assertDatabaseHas('system_reports', ['id' => $reportFault->id]);
        $this->assertSame(2, $supervisor->notifications()->count());
    }
}
