<?php

namespace Tests\Feature;

use App\Mail\ApplicationStatusChangedMailable;
use App\Mail\OfficerNotificationMailable;
use App\Mail\SktmIssuedMailable;
use App\Models\Application;
use App\Models\ApplicationHistory;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ApplicationStatusChanged;
use App\Services\EmailNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SktmDirectStaffToKadesWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected Role $roleWarga;
    protected Role $roleStaf;
    protected Role $roleSupervisor;
    protected Role $roleKades;
    protected DocumentType $docTypeSktm;

    protected User $warga;
    protected User $staf;
    protected User $supervisor;
    protected User $kades;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleWarga = Role::firstOrCreate(['name' => 'masyarakat']);
        $this->roleStaf = Role::firstOrCreate(['name' => 'staf']);
        $this->roleSupervisor = Role::firstOrCreate(['name' => 'supervisor']);
        $this->roleKades = Role::firstOrCreate(['name' => 'kades']);

        $this->docTypeSktm = DocumentType::firstOrCreate(
            ['code' => 'SKTM'],
            ['name' => 'Surat Keterangan Tidak Mampu', 'is_active' => true]
        );

        $this->warga = $this->createActor($this->roleWarga, 'Warga Pemohon', 'warga.sktm@desa.id', '081211110001');
        $this->staf = $this->createActor($this->roleStaf, 'Staf Pelayanan', 'staf.sktm@desa.id', '081211110002');
        $this->supervisor = $this->createActor($this->roleSupervisor, 'Supervisor Pengawas', 'spv.sktm@desa.id', '081211110003');
        $this->kades = $this->createActor($this->roleKades, 'Kepala Desa', 'kades.sktm@desa.id', '081211110004');

        Storage::fake('local');
        EmailNotificationService::resetDeduplication();
    }

    private function createActor(Role $role, string $name, string $email, string $phone): User
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

    private function createSubmittedApp(string $status = 'SUBMITTED'): Application
    {
        $app = Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-FLOW-' . strtoupper(Str::random(5)),
            'user_id' => $this->warga->id,
            'document_type_id' => $this->docTypeSktm->id,
            'status' => $status,
            'purpose' => 'Beasiswa Pendidikan',
            'form_data' => [
                'nik' => '3201010101010001',
                'no_kk' => '3201010101010002',
                'name' => $this->warga->name,
                'address' => 'Jl. Melati No. 1',
                'rt' => '01',
                'rw' => '02',
                'desa' => 'Sukamaju',
                'kecamatan' => 'Sukarame',
                'kabupaten' => 'Bandung',
            ],
            'submitted_at' => now(),
        ]);

        $app->documents()->create([
            'document_name' => 'KTP',
            'file_path' => 'documents/sktm/ktp_test.jpg',
            'mime_type' => 'image/jpeg',
            'status' => 'PENDING',
        ]);

        return $app;
    }

    public function test_staf_forwards_directly_to_head_approval_and_notifies_kades_and_citizen(): void
    {
        Notification::fake();
        Mail::fake();
        config(['services.fonnte.enabled' => true, 'services.fonnte.token' => 'fake-fonnte-token', 'services.fonnte.use_queue' => false]);
        Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['fnt_kades_1']], 200)]);

        $app = $this->createSubmittedApp('SUBMITTED');

        // Staf mulai memeriksa dokumen
        $this->actingAs($this->staf);
        Volt::test('admin.application-detail', ['id' => $app->id])
            ->call('markAsUnderReview');
        $this->assertEquals('UNDER_REVIEW', $app->fresh()->status);

        // Staf memverifikasi dan langsung meneruskan ke Kepala Desa
        EmailNotificationService::resetDeduplication();
        Volt::test('admin.application-detail', ['id' => $app->id])
            ->set('generalNote', 'Berkas KTP dan KK telah lengkap.')
            ->call('forwardToHead')
            ->assertRedirect(route('admin.pengajuan'));

        $app->refresh();
        $this->assertEquals('HEAD_APPROVAL', $app->status);

        $this->assertDatabaseHas('application_histories', [
            'application_id' => $app->id,
            'actor_id' => $this->staf->id,
            'status_from' => 'UNDER_REVIEW',
            'status_to' => 'HEAD_APPROVAL',
            'description' => 'Dokumen diverifikasi oleh Staf. Diteruskan ke Kepala Desa.',
        ]);

        // Kepala Desa & Warga menerima notifikasi; Supervisor TIDAK menerima notifikasi review baru
        Notification::assertSentTo($this->kades, ApplicationStatusChanged::class, fn ($n) => $n->application->id === $app->id);
        Notification::assertSentTo($this->warga, ApplicationStatusChanged::class, fn ($n) => $n->application->id === $app->id);
        Notification::assertNotSentTo($this->supervisor, ApplicationStatusChanged::class);

        Mail::assertSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo($this->kades->email));
        Mail::assertSent(ApplicationStatusChangedMailable::class, fn ($m) => $m->hasTo($this->warga->email));
        Mail::assertNotSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo($this->supervisor->email));

        Http::assertSent(fn ($req) => $req['target'] === '6281211110004' && str_contains($req['message'], 'KEPALA DESA'));
        Http::assertNotSent(fn ($req) => $req['target'] === '6281211110003');
    }

    public function test_staf_compatibility_alias_also_forwards_to_head_approval(): void
    {
        $app = $this->createSubmittedApp('UNDER_REVIEW');

        $this->actingAs($this->staf);
        Volt::test('admin.application-detail', ['id' => $app->id])
            ->call('forwardToSupervisor');

        $this->assertEquals('HEAD_APPROVAL', $app->fresh()->status);
    }

    public function test_kades_can_approve_and_issue_pdf_and_also_reject_directly_from_staf(): void
    {
        Mail::fake();
        Notification::fake();

        // 1. Kades approve & terbitkan PDF
        $appApprove = $this->createSubmittedApp('UNDER_REVIEW');
        $this->actingAs($this->staf);
        Volt::test('admin.application-detail', ['id' => $appApprove->id])->call('forwardToHead');
        $this->assertEquals('HEAD_APPROVAL', $appApprove->fresh()->status);

        $this->actingAs($this->kades);
        EmailNotificationService::resetDeduplication();
        Volt::test('admin.application-detail', ['id' => $appApprove->id])
            ->set('generalNote', 'Disetujui resmi oleh Kepala Desa.')
            ->call('kadesApprove')
            ->assertRedirect(route('admin.pengajuan'));

        $appApprove->refresh();
        $this->assertEquals('COMPLETED', $appApprove->status);
        $this->assertNotNull($appApprove->resolved_at);

        $pdfDoc = $appApprove->documents()->where('mime_type', 'application/pdf')->first();
        $this->assertNotNull($pdfDoc);
        $this->assertTrue(Storage::disk('local')->exists($pdfDoc->file_path));

        Mail::assertSent(SktmIssuedMailable::class, fn ($m) => $m->hasTo($this->warga->email));

        // 2. Kades reject
        $appReject = $this->createSubmittedApp('UNDER_REVIEW');
        $this->actingAs($this->staf);
        Volt::test('admin.application-detail', ['id' => $appReject->id])->call('forwardToHead');

        $this->actingAs($this->kades);
        Volt::test('admin.application-detail', ['id' => $appReject->id])
            ->set('generalNote', 'Data penghasilan tidak memenuhi kriteria SKTM.')
            ->call('kadesReject')
            ->assertRedirect(route('admin.pengajuan'));

        $appReject->refresh();
        $this->assertEquals('REJECTED', $appReject->status);
        $this->assertDatabaseHas('application_histories', [
            'application_id' => $appReject->id,
            'status_to' => 'REJECTED',
        ]);
    }

    public function test_supervisor_cannot_mutate_sktm_status_on_any_endpoint_and_retains_read_only_access(): void
    {
        $appSubmitted = $this->createSubmittedApp('SUBMITTED');
        $appReview = $this->createSubmittedApp('UNDER_REVIEW');
        $appHead = $this->createSubmittedApp('HEAD_APPROVAL');
        $appLegacy = $this->createSubmittedApp('SUPERVISOR_REVIEW');

        // Policy checks
        $this->assertTrue($this->supervisor->can('view', $appHead));
        $this->assertFalse($this->supervisor->can('reviewSupervisor', $appLegacy));
        $this->assertFalse($this->supervisor->can('reviewStaff', $appReview));
        $this->assertFalse($this->supervisor->can('approve', $appHead));

        // API supervisor-review returns 403
        $this->actingAs($this->supervisor, 'sanctum');
        $this->postJson("/api/applications/{$appLegacy->id}/supervisor-review")->assertStatus(403);
        $this->postJson("/api/applications/{$appReview->id}/staff-review")->assertStatus(403);
        $this->postJson("/api/applications/{$appHead->id}/kades-approve")->assertStatus(403);

        // API read-only index & detail succeed
        $listRes = $this->getJson('/api/applications')->assertStatus(200);
        $numbers = collect($listRes->json())->pluck('application_number')->all();
        $this->assertContains($appSubmitted->application_number, $numbers);
        $this->assertContains($appHead->application_number, $numbers);
        $this->assertContains($appLegacy->application_number, $numbers);
        $this->getJson("/api/applications/{$appHead->id}")->assertStatus(200);

        // Web Livewire read-only access succeeds without workflow action buttons
        $this->actingAs($this->supervisor);
        $this->get('/admin/pengajuan/' . $appHead->id)
            ->assertStatus(200)
            ->assertSee('Akses Pengawasan (Read-Only)')
            ->assertDontSee('Setujui & Terbitkan SKTM')
            ->assertDontSee('Setujui & Teruskan ke Kades');

        // All Livewire status-mutating methods abort with 403 for Supervisor
        foreach ([
            [$appSubmitted, 'markAsUnderReview', null],
            [$appReview, 'markAsNeedsRevision', 'Catatan paksa'],
            [$appReview, 'forwardToHead', null],
            [$appLegacy, 'supervisorReturn', 'Catatan paksa'],
            [$appLegacy, 'supervisorForward', null],
            [$appHead, 'kadesApprove', null],
            [$appHead, 'kadesReject', 'Tolak paksa'],
        ] as [$targetApp, $method, $note]) {
            $test = Volt::test('admin.application-detail', ['id' => $targetApp->id]);
            if ($note !== null) {
                $test->set('generalNote', $note);
            }
            $test->call($method)->assertForbidden();
        }

        // setDocumentStatus also forbidden for Supervisor
        $doc = $appReview->documents()->first();
        Volt::test('admin.application-detail', ['id' => $appReview->id])
            ->call('setDocumentStatus', $doc->id, 'VERIFIED')
            ->assertForbidden();

        $this->assertEquals('SUBMITTED', $appSubmitted->fresh()->status);
        $this->assertEquals('UNDER_REVIEW', $appReview->fresh()->status);
        $this->assertEquals('HEAD_APPROVAL', $appHead->fresh()->status);
        $this->assertEquals('SUPERVISOR_REVIEW', $appLegacy->fresh()->status);
    }

    public function test_historical_supervisor_review_records_and_filter_remain_accessible(): void
    {
        $appHistorical = $this->createSubmittedApp('COMPLETED');
        ApplicationHistory::create([
            'application_id' => $appHistorical->id,
            'actor_id' => $this->staf->id,
            'status_from' => 'UNDER_REVIEW',
            'status_to' => 'SUPERVISOR_REVIEW',
            'description' => 'Dokumen diverifikasi oleh Staf. Diteruskan ke Kasi.',
        ]);
        ApplicationHistory::create([
            'application_id' => $appHistorical->id,
            'actor_id' => $this->supervisor->id,
            'status_from' => 'SUPERVISOR_REVIEW',
            'status_to' => 'HEAD_APPROVAL',
            'description' => 'Disetujui oleh Kasi. Diteruskan ke Kades.',
        ]);

        $appArchivedInSpv = $this->createSubmittedApp('SUPERVISOR_REVIEW');

        // Supervisor can filter by historical SUPERVISOR_REVIEW status and inspect timeline
        $this->actingAs($this->supervisor);
        Volt::test('admin.application-list')
            ->set('statusFilter', 'SUPERVISOR_REVIEW')
            ->assertSee($appArchivedInSpv->application_number);

        $this->get('/admin/pengajuan/' . $appHistorical->id)
            ->assertStatus(200)
            ->assertSee('Review Kasi / Supervisor')
            ->assertSee('Dokumen diverifikasi oleh Staf. Diteruskan ke Kasi.');
    }

    public function test_citizen_revision_cycle_works_and_staf_sees_head_approval_archive(): void
    {
        $app = $this->createSubmittedApp('UNDER_REVIEW');

        // Staf mengembalikan berkas untuk revisi
        $this->actingAs($this->staf);
        Volt::test('admin.application-detail', ['id' => $app->id])
            ->set('generalNote', 'Foto KTP terpotong.')
            ->call('markAsNeedsRevision');
        $this->assertEquals('NEEDS_REVISION', $app->fresh()->status);

        // Masyarakat mengirim revisi dokumen
        $this->actingAs($this->warga);
        $this->post(route('pengajuan.revisi', $app->id), [
            'ktp' => UploadedFile::fake()->create('ktp_baru.jpg', 500, 'image/jpeg'),
            'notes' => 'Sudah saya unggah KTP yang lebih jelas.',
        ])->assertSessionHas('message');

        $this->assertEquals('SUBMITTED', $app->fresh()->status);

        // Staf menerima kembali, meneruskan langsung ke HEAD_APPROVAL, dan melihatnya di daftar & API
        $this->actingAs($this->staf);
        Volt::test('admin.application-detail', ['id' => $app->id])
            ->call('markAsUnderReview')
            ->call('forwardToHead');
        $this->assertEquals('HEAD_APPROVAL', $app->fresh()->status);

        Volt::test('admin.application-list')
            ->assertSee($app->application_number);

        $this->actingAs($this->staf, 'sanctum');
        $res = $this->getJson('/api/applications')->assertStatus(200);
        $this->assertContains($app->application_number, collect($res->json())->pluck('application_number')->all());
    }
}

