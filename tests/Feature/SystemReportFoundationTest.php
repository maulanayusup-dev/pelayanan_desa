<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\SystemReport;
use App\Models\SystemReportAttachment;
use App\Models\SystemReportHistory;
use App\Models\SystemReportMessage;
use App\Models\User;
use App\Services\SystemReportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SystemReportFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Role $masyarakatRole;
    private Role $stafRole;
    private Role $supervisorRole;
    private Role $kadesRole;
    private Role $kepalaDesaRole;
    private DocumentType $sktmType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->masyarakatRole = Role::create(['name' => 'masyarakat']);
        $this->stafRole = Role::create(['name' => 'staf']);
        $this->supervisorRole = Role::create(['name' => 'supervisor']);
        $this->kadesRole = Role::create(['name' => 'kades']);
        $this->kepalaDesaRole = Role::create(['name' => 'kepala_desa']);

        $this->sktmType = DocumentType::create([
            'code' => 'SKTM',
            'name' => 'Surat Keterangan Tidak Mampu',
        ]);
    }

    private function createUser(Role $role, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => $role->id,
            'is_active' => true,
        ], $overrides));
    }

    private function createApplicationFor(User $owner): Application
    {
        return Application::create([
            'application_number' => 'SKTM-' . strtoupper(Str::random(6)),
            'user_id' => $owner->id,
            'document_type_id' => $this->sktmType->id,
            'status' => 'SUBMITTED',
        ]);
    }

    private function validPngUpload(string $filename = 'bukti.png'): UploadedFile
    {
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent($filename, $pngBytes);
    }

    private function validJpegUpload(string $filename = 'bukti.jpg'): UploadedFile
    {
        $jpgBytes = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

        return UploadedFile::fake()->createWithContent($filename, $jpgBytes);
    }

    private function validPdfUpload(string $filename = 'dokumen.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $filename,
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF"
        );
    }

    public function test_1_all_new_report_tables_and_columns_are_migrated(): void
    {
        $this->assertTrue(Schema::hasTable('system_reports'));
        $this->assertTrue(Schema::hasColumns('system_reports', [
            'id', 'ticket_number', 'reporter_id', 'reporter_role', 'assigned_to',
            'application_id', 'category', 'priority', 'title', 'description',
            'page_url', 'status', 'resolution_summary', 'resolved_at', 'closed_at',
            'last_replied_at', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('system_report_messages'));
        $this->assertTrue(Schema::hasColumns('system_report_messages', [
            'id', 'report_id', 'sender_id', 'message', 'is_internal', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('system_report_attachments'));
        $this->assertTrue(Schema::hasColumns('system_report_attachments', [
            'id', 'report_id', 'message_id', 'uploaded_by', 'original_name',
            'file_path', 'mime_type', 'file_size', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('system_report_histories'));
        $this->assertTrue(Schema::hasColumns('system_report_histories', [
            'id', 'report_id', 'actor_id', 'action', 'status_from',
            'status_to', 'description', 'created_at', 'updated_at',
        ]));
    }

    public function test_2_and_3_and_17_uuids_eloquent_relations_and_initial_history_work(): void
    {
        Storage::fake('local');

        $warga = $this->createUser($this->masyarakatRole, ['name' => 'Warga Pelapor']);
        $supervisor = $this->createUser($this->supervisorRole, ['name' => 'Supervisor Desa']);
        $application = $this->createApplicationFor($warga);

        $service = app(SystemReportService::class);
        $report = $service->createReport(
            actor: $warga,
            data: [
                'category' => SystemReport::CATEGORY_BUG,
                'priority' => SystemReport::PRIORITY_HIGH,
                'title' => 'Tombol unduh SKTM tidak muncul',
                'description' => 'Saat membuka halaman detail pengajuan, tombol unduh tidak tampil.',
                'page_url' => '/pengajuan/' . $application->id,
                'application_id' => $application->id,
            ],
            attachments: [$this->validPngUpload('screenshot_awal.png')]
        );

        $service->assignSupervisor($report, $supervisor, $supervisor->id);
        $message = $service->addMessage($report, $supervisor, 'Kami sedang memeriksa kendala ini.', false);
        $report->refresh();

        $attachment = $report->attachments->first();
        $history = $report->histories->first();

        $this->assertTrue(Str::isUuid((string) $report->id));
        $this->assertTrue(Str::isUuid((string) $message->id));
        $this->assertTrue(Str::isUuid((string) $attachment->id));
        $this->assertTrue(Str::isUuid((string) $history->id));

        $this->assertSame($warga->id, $report->reporter->id);
        $this->assertSame('masyarakat', $report->reporter_role);
        $this->assertSame($supervisor->id, $report->assignedSupervisor->id);
        $this->assertSame($application->id, $report->application->id);
        $this->assertCount(1, $report->messages);
        $this->assertCount(1, $report->attachments);
        $this->assertGreaterThanOrEqual(3, $report->histories->count());

        $this->assertSame($report->id, $message->report->id);
        $this->assertSame($supervisor->id, $message->sender->id);
        $this->assertSame($warga->id, $attachment->uploader->id);
        $this->assertSame($warga->id, $history->actor->id);

        $this->assertSame(SystemReportHistory::ACTION_CREATED, $history->action);
        $this->assertNull($history->status_from);
        $this->assertSame(SystemReport::STATUS_OPEN, $history->status_to);

        // Reverse relationships on User and Application
        $this->assertTrue($warga->systemReports->contains('id', $report->id));
        $this->assertTrue($supervisor->assignedSystemReports->contains('id', $report->id));
        $this->assertTrue($application->systemReports->contains('id', $report->id));
    }

    public function test_4_5_6_7_role_access_boundaries_for_masyarakat_staf_kades_and_supervisor(): void
    {
        $wargaA = $this->createUser($this->masyarakatRole);
        $wargaB = $this->createUser($this->masyarakatRole);
        $staf = $this->createUser($this->stafRole);
        $kades = $this->createUser($this->kadesRole);
        $kepalaDesa = $this->createUser($this->kepalaDesaRole);
        $supervisor = $this->createUser($this->supervisorRole);

        $service = app(SystemReportService::class);
        $reportWargaA = $service->createReport($wargaA, [
            'category' => SystemReport::CATEGORY_COMPLAINT,
            'title' => 'Kendala Warga A',
            'description' => 'Deskripsi keluhan dari warga A untuk pengujian otorisasi.',
        ]);
        $reportStaf = $service->createReport($staf, [
            'category' => SystemReport::CATEGORY_BUG,
            'title' => 'Kendala Staf',
            'description' => 'Deskripsi bug yang ditemukan oleh akun staf desa.',
        ]);
        $reportKades = $service->createReport($kades, [
            'category' => SystemReport::CATEGORY_ACCESS,
            'title' => 'Kendala Kades',
            'description' => 'Deskripsi laporan yang dikirim oleh akun kepala desa.',
        ]);
        $reportKepalaDesa = $service->createReport($kepalaDesa, [
            'category' => SystemReport::CATEGORY_OTHER,
            'title' => 'Kendala Kepala Desa Alias',
            'description' => 'Deskripsi laporan menggunakan role kepala_desa.',
        ]);

        // Masyarakat hanya mengakses miliknya sendiri
        $this->assertSame([$reportWargaA->id], SystemReport::accessibleBy($wargaA)->pluck('id')->all());
        $this->assertTrue(Gate::forUser($wargaA)->allows('view', $reportWargaA));
        $this->assertFalse(Gate::forUser($wargaA)->allows('view', $reportStaf));
        $this->assertFalse(Gate::forUser($wargaB)->allows('view', $reportWargaA));

        // Staf hanya mengakses miliknya sendiri
        $this->assertSame([$reportStaf->id], SystemReport::accessibleBy($staf)->pluck('id')->all());
        $this->assertTrue(Gate::forUser($staf)->allows('view', $reportStaf));
        $this->assertFalse(Gate::forUser($staf)->allows('view', $reportWargaA));
        $this->assertFalse(Gate::forUser($staf)->allows('view', $reportKades));

        // Kepala Desa (kades & kepala_desa) hanya mengakses miliknya sendiri
        $this->assertSame([$reportKades->id], SystemReport::accessibleBy($kades)->pluck('id')->all());
        $this->assertTrue(Gate::forUser($kades)->allows('view', $reportKades));
        $this->assertFalse(Gate::forUser($kades)->allows('view', $reportWargaA));
        $this->assertSame([$reportKepalaDesa->id], SystemReport::accessibleBy($kepalaDesa)->pluck('id')->all());

        // Supervisor mengakses semua laporan
        $this->assertCount(4, SystemReport::accessibleBy($supervisor)->get());
        $this->assertTrue(Gate::forUser($supervisor)->allows('view', $reportWargaA));
        $this->assertTrue(Gate::forUser($supervisor)->allows('view', $reportStaf));
        $this->assertTrue(Gate::forUser($supervisor)->allows('view', $reportKades));
        $this->assertTrue(Gate::forUser($supervisor)->allows('view', $reportKepalaDesa));

        // Verifikasi HTTP API isolation + 403 backend enforcement untuk non-supervisor
        $this->actingAs($wargaB, 'sanctum');
        $this->getJson('/api/reports')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/reports/{$reportWargaA->id}")->assertForbidden();

        $this->actingAs($staf, 'sanctum');
        $this->getJson('/api/reports')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/reports/{$reportWargaA->id}")->assertForbidden();
        $this->patchJson("/api/reports/{$reportStaf->id}/status", ['status' => SystemReport::STATUS_RESOLVED, 'resolution_summary' => 'Bypass'])->assertForbidden();
        $this->patchJson("/api/reports/{$reportStaf->id}/priority", ['priority' => SystemReport::PRIORITY_CRITICAL])->assertForbidden();
        $this->patchJson("/api/reports/{$reportStaf->id}/assign", ['assigned_to' => $supervisor->id])->assertForbidden();

        $this->actingAs($kades, 'sanctum');
        $this->getJson("/api/reports/{$reportWargaA->id}")->assertForbidden();
        $this->patchJson("/api/reports/{$reportKades->id}/status", ['status' => SystemReport::STATUS_CLOSED])->assertForbidden();

        $this->actingAs($supervisor, 'sanctum');
        $this->getJson('/api/reports')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson("/api/reports/{$reportWargaA->id}")->assertOk();
    }

    public function test_8_and_9_internal_notes_are_visible_only_to_supervisor_and_hidden_from_reporters(): void
    {
        Storage::fake('local');

        $warga = $this->createUser($this->masyarakatRole);
        $staf = $this->createUser($this->stafRole);
        $supervisor = $this->createUser($this->supervisorRole);

        $service = app(SystemReportService::class);
        $report = $service->createReport($warga, [
            'category' => SystemReport::CATEGORY_BUG,
            'title' => 'Error validasi NIK',
            'description' => 'Terjadi error saat validasi NIK di formulir pengajuan.',
        ]);

        $publicReply = $service->addMessage($report, $supervisor, 'Balasan publik: silakan kirim ulang foto KK.', false);
        $internalNote = $service->addMessage(
            report: $report,
            actor: $supervisor,
            messageBody: 'Catatan internal: cek log Redis & worker queue malam ini.',
            isInternal: true,
            attachments: [$this->validPngUpload('debug_internal.png')]
        );

        // Policy level
        $this->assertFalse(Gate::forUser($warga)->allows('viewInternalNotes', $report));
        $this->assertFalse(Gate::forUser($warga)->allows('createInternalNote', $report));
        $this->assertFalse(Gate::forUser($warga)->allows('viewMessage', $internalNote));
        $this->assertTrue(Gate::forUser($warga)->allows('viewMessage', $publicReply));
        $this->assertFalse(Gate::forUser($staf)->allows('viewInternalNotes', $report));

        $this->assertTrue(Gate::forUser($supervisor)->allows('viewInternalNotes', $report));
        $this->assertTrue(Gate::forUser($supervisor)->allows('createInternalNote', $report));
        $this->assertTrue(Gate::forUser($supervisor)->allows('viewMessage', $internalNote));

        // Scope & helper level
        $this->assertCount(1, $report->visibleMessagesFor($warga)->get());
        $this->assertCount(2, $report->visibleMessagesFor($supervisor)->get());
        $this->assertSame([$publicReply->id], SystemReportMessage::visibleTo($warga)->pluck('id')->all());
        $this->assertCount(2, SystemReportMessage::visibleTo($supervisor)->get());

        // HTTP API level: pelapor tidak menerima catatan internal maupun lampiran catatan internal
        $this->actingAs($warga, 'sanctum');
        $response = $this->getJson("/api/reports/{$report->id}")->assertOk();
        $response->assertJsonMissing(['message' => $internalNote->message]);
        $response->assertJsonFragment(['message' => $publicReply->message]);
        $response->assertJsonCount(0, 'data.attachments');

        // Pelapor tidak dapat memanipulasi is_internal = true saat mengirim pesan
        $this->postJson("/api/reports/{$report->id}/messages", [
            'message' => 'Mencoba menyusupkan catatan internal',
            'is_internal' => true,
        ])->assertForbidden();

        // Pelapor tidak bisa mengunduh lampiran milik catatan internal Supervisor
        $internalAttachment = $internalNote->attachments->first();
        $this->getJson("/api/reports/attachments/{$internalAttachment->id}")->assertForbidden();

        // Supervisor bisa membaca catatan internal dan mengunduh lampirannya
        $this->actingAs($supervisor, 'sanctum');
        $supResponse = $this->getJson("/api/reports/{$report->id}")->assertOk();
        $supResponse->assertJsonFragment(['message' => $internalNote->message]);
        $supResponse->assertJsonCount(1, 'data.attachments');
        $this->get("/api/reports/attachments/{$internalAttachment->id}")->assertOk();
    }

    public function test_10_and_11_reporter_id_and_reporter_role_manipulation_are_rejected(): void
    {
        $warga = $this->createUser($this->masyarakatRole);
        $korban = $this->createUser($this->stafRole);
        $otherApp = $this->createApplicationFor($korban);

        $this->actingAs($warga, 'sanctum');

        // Manipulasi reporter_id via HTTP ditolak
        $this->postJson('/api/reports', [
            'reporter_id' => $korban->id,
            'category' => SystemReport::CATEGORY_COMPLAINT,
            'title' => 'Percobaan manipulasi reporter_id',
            'description' => 'Mencoba mengatasnamakan user lain sebagai pelapor.',
        ])->assertStatus(422)->assertJsonValidationErrors(['reporter_id']);

        // Manipulasi reporter_role via HTTP ditolak
        $this->postJson('/api/reports', [
            'reporter_role' => 'supervisor',
            'category' => SystemReport::CATEGORY_COMPLAINT,
            'title' => 'Percobaan manipulasi reporter_role',
            'description' => 'Mencoba menyamar sebagai role supervisor.',
        ])->assertStatus(422)->assertJsonValidationErrors(['reporter_role']);

        // Manipulasi status / assigned_to pada pembuatan laporan juga ditolak
        $this->postJson('/api/reports', [
            'status' => SystemReport::STATUS_RESOLVED,
            'assigned_to' => $korban->id,
            'category' => SystemReport::CATEGORY_BUG,
            'title' => 'Percobaan bypass status awal',
            'description' => 'Mencoba langsung menandai laporan sebagai RESOLVED saat dibuat.',
        ])->assertStatus(422)->assertJsonValidationErrors(['status', 'assigned_to']);

        $this->assertSame(0, SystemReport::count());

        // Manipulasi application_id milik pengguna lain juga ditolak (403)
        $this->postJson('/api/reports', [
            'application_id' => $otherApp->id,
            'category' => SystemReport::CATEGORY_APPLICATION_ISSUE,
            'title' => 'Menautkan pengajuan orang lain',
            'description' => 'Mencoba menautkan ID pengajuan warga/staf lain ke laporan.',
        ])->assertForbidden();

        // Pembuatan sah selalu mengikat reporter_id = auth()->id() dan reporter_role = role auth
        $created = $this->postJson('/api/reports', [
            'category' => SystemReport::CATEGORY_BUG,
            'title' => 'Laporan sah dari warga',
            'description' => 'Deskripsi keluhan valid dari akun warga terautentikasi.',
        ])->assertCreated();

        $report = SystemReport::findOrFail($created->json('data.id'));
        $this->assertSame($warga->id, $report->reporter_id);
        $this->assertSame('masyarakat', $report->reporter_role);
        $this->assertSame(SystemReport::STATUS_OPEN, $report->status);

        // Direct service call juga menolak manipulasi reporter_id / reporter_role
        $service = app(SystemReportService::class);
        $this->expectException(ValidationException::class);
        $service->createReport($warga, [
            'reporter_id' => $korban->id,
            'reporter_role' => 'supervisor',
            'category' => SystemReport::CATEGORY_BUG,
            'title' => 'Direct service tamper',
            'description' => 'Mencoba override melalui array data service secara langsung.',
        ]);
    }

    public function test_12_and_13_and_14_attachment_private_storage_mime_size_and_idor_protection(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $wargaA = $this->createUser($this->masyarakatRole);
        $wargaB = $this->createUser($this->masyarakatRole);
        $staf = $this->createUser($this->stafRole);
        $supervisor = $this->createUser($this->supervisorRole);

        // 1. Validasi MIME isi berkas: file PHP disamarkan sebagai .jpg ditolak
        $this->actingAs($wargaA, 'sanctum');
        $disguisedPhp = UploadedFile::fake()->createWithContent('exploit.jpg', '<?php system("whoami"); ?>');
        $this->post('/api/reports', [
            'category' => SystemReport::CATEGORY_BUG,
            'title' => 'Upload script menyamar',
            'description' => 'Percobaan mengunggah file PHP dengan ekstensi JPG.',
            'attachments' => [$disguisedPhp],
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['attachments.0']);

        // 2. Validasi ukuran > 5 MB ditolak
        $oversizedPdf = UploadedFile::fake()->create('terlalu_besar.pdf', 5121, 'application/pdf');
        $this->post('/api/reports', [
            'category' => SystemReport::CATEGORY_BUG,
            'title' => 'Upload ukuran berlebih',
            'description' => 'Percobaan mengunggah lampiran lebih dari batas maksimal 5 MB.',
            'attachments' => [$oversizedPdf],
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['attachments.0']);

        // 3. Upload valid JPG, PNG, dan PDF tersimpan di storage privat `local`
        $resp = $this->post('/api/reports', [
            'category' => SystemReport::CATEGORY_BUG,
            'title' => 'Lampiran valid multi tipe',
            'description' => 'Melampirkan bukti JPG, PNG, dan PDF yang valid secara isi berkas.',
            'attachments' => [
                $this->validJpegUpload('foto_asli.jpg'),
                $this->validPngUpload('tangkapan_layar.png'),
                $this->validPdfUpload('berita_acara.pdf'),
            ],
        ], ['Accept' => 'application/json'])->assertCreated();

        $report = SystemReport::with('attachments')->findOrFail($resp->json('data.id'));
        $this->assertCount(3, $report->attachments);

        foreach ($report->attachments as $att) {
            $this->assertStringStartsWith('reports/attachments/', $att->file_path);
            $this->assertNotSame('foto_asli.jpg', basename($att->file_path));
            $this->assertNotSame('tangkapan_layar.png', basename($att->file_path));
            $this->assertNotSame('berita_acara.pdf', basename($att->file_path));
            Storage::disk('local')->assertExists($att->file_path);
            Storage::disk('public')->assertMissing($att->file_path);
        }

        // Pastikan file_path tidak bocor pada response JSON
        $resp->assertJsonMissing(['file_path' => $report->attachments->first()->file_path]);

        $attachment = $report->attachments->first();

        // 4. IDOR: pengguna lain (wargaB maupun staf) ditolak mengakses lampiran wargaA
        $this->actingAs($wargaB, 'sanctum');
        $this->getJson("/api/reports/attachments/{$attachment->id}")->assertForbidden();
        $this->actingAs($wargaB)->get("/laporan/lampiran/{$attachment->id}")->assertForbidden();

        $this->actingAs($staf)->get("/laporan/lampiran/{$attachment->id}")->assertForbidden();

        // 5. Cross-report attachment IDOR pada policy ditolak
        $reportB = app(SystemReportService::class)->createReport($wargaA, [
            'category' => SystemReport::CATEGORY_OTHER,
            'title' => 'Laporan kedua warga A',
            'description' => 'Laporan terpisah untuk memverifikasi pencocokan report_id dan attachment.',
        ]);
        $this->assertFalse(Gate::forUser($wargaA)->allows('downloadAttachment', [$reportB, $attachment]));

        // 6. Pemilik dan Supervisor dapat mengunduh melalui controller privat
        $downloadOwner = $this->actingAs($wargaA)->get("/laporan/lampiran/{$attachment->id}");
        $downloadOwner->assertOk();
        $downloadOwner->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($supervisor)->get("/laporan/lampiran/{$attachment->id}")->assertOk();
    }

    public function test_15_foreign_keys_and_safe_deletion_rules(): void
    {
        $warga = $this->createUser($this->masyarakatRole);
        $supervisor = $this->createUser($this->supervisorRole);
        $service = app(SystemReportService::class);

        $report = $service->createReport($warga, [
            'category' => SystemReport::CATEGORY_BUG,
            'title' => 'Uji integritas foreign key',
            'description' => 'Memastikan laporan tidak ikut terhapus otomatis ketika akun pelapor dihapus.',
        ]);
        $service->assignSupervisor($report, $supervisor, $supervisor->id);

        // Soft delete akun pelapor TIDAK boleh menghapus laporan secara otomatis
        $warga->delete();
        $this->assertSoftDeleted('users', ['id' => $warga->id]);
        $this->assertDatabaseHas('system_reports', [
            'id' => $report->id,
            'reporter_id' => $warga->id,
            'reporter_role' => 'masyarakat',
        ]);
        $this->assertSame($warga->id, $report->fresh()->reporter->id);

        // Hard delete di tingkat DB ditolak oleh FK RESTRICT (bukan CASCADE yang menghapus laporan)
        $restrictTriggered = false;
        try {
            $warga->forceDelete();
        } catch (QueryException $e) {
            $restrictTriggered = true;
        }
        $this->assertTrue($restrictTriggered, 'FK restrict seharusnya menolak hard-delete akun yang masih memiliki laporan.');
        $this->assertDatabaseHas('system_reports', ['id' => $report->id]);

        // Memasukkan reporter_id yang tidak ada ditolak oleh FK
        $invalidFkRejected = false;
        try {
            SystemReport::create([
                'ticket_number' => 'RPT-INVALID-001',
                'reporter_id' => (string) Str::uuid(),
                'reporter_role' => 'masyarakat',
                'category' => SystemReport::CATEGORY_BUG,
                'priority' => SystemReport::PRIORITY_MEDIUM,
                'title' => 'FK tidak valid',
                'description' => 'Mencoba membuat laporan dengan reporter_id fiktif.',
                'status' => SystemReport::STATUS_OPEN,
            ]);
        } catch (QueryException $e) {
            $invalidFkRejected = true;
        }
        $this->assertTrue($invalidFkRejected);
    }

    public function test_16_ticket_numbers_are_unique_and_duplicates_are_rejected(): void
    {
        $warga = $this->createUser($this->masyarakatRole);
        $service = app(SystemReportService::class);

        $r1 = $service->createReport($warga, [
            'category' => SystemReport::CATEGORY_BUG,
            'title' => 'Tiket paralel pertama',
            'description' => 'Membuat tiket pertama untuk uji keunikan nomor tiket.',
        ]);
        $r2 = $service->createReport($warga, [
            'category' => SystemReport::CATEGORY_BUG,
            'title' => 'Tiket paralel kedua',
            'description' => 'Membuat tiket kedua segera setelah tiket pertama.',
        ]);

        $this->assertNotSame($r1->ticket_number, $r2->ticket_number);
        $this->assertMatchesRegularExpression('/^RPT-\d{8}-[A-Z0-9]+$/', $r1->ticket_number);
        $this->assertMatchesRegularExpression('/^RPT-\d{8}-[A-Z0-9]+$/', $r2->ticket_number);

        $this->expectException(QueryException::class);
        SystemReport::create([
            'ticket_number' => $r1->ticket_number,
            'reporter_id' => $warga->id,
            'reporter_role' => 'masyarakat',
            'category' => SystemReport::CATEGORY_BUG,
            'priority' => SystemReport::PRIORITY_MEDIUM,
            'title' => 'Duplikat nomor tiket',
            'description' => 'Mencoba memasukkan nomor tiket yang sama persis ke database.',
            'status' => SystemReport::STATUS_OPEN,
        ]);
    }

    public function test_18_audit_log_records_all_report_lifecycle_events_without_secrets(): void
    {
        $warga = $this->createUser($this->masyarakatRole);
        $staf = $this->createUser($this->stafRole);
        $supervisor = $this->createUser($this->supervisorRole);
        $otherSupervisor = $this->createUser($this->supervisorRole);

        $this->actingAs($warga, 'sanctum');
        $createResp = $this->postJson('/api/reports', [
            'category' => SystemReport::CATEGORY_APPLICATION_ISSUE,
            'title' => 'Status surat tertahan',
            'description' => 'Status pengajuan SKTM tidak berubah setelah revisi dokumen.',
        ])->assertCreated();

        $report = SystemReport::findOrFail($createResp->json('data.id'));

        $this->actingAs($supervisor, 'sanctum');

        // Menunjuk non-supervisor sebagai assignee ditolak (422)
        $this->patchJson("/api/reports/{$report->id}/assign", [
            'assigned_to' => $staf->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['assigned_to']);

        // 1. Assign ke Supervisor valid
        $this->patchJson("/api/reports/{$report->id}/assign", [
            'assigned_to' => $otherSupervisor->id,
        ])->assertOk();

        // 2. Ubah prioritas
        $this->patchJson("/api/reports/{$report->id}/priority", [
            'priority' => SystemReport::PRIORITY_CRITICAL,
            'reason' => 'Memengaruhi penerbitan surat warga',
        ])->assertOk();

        // 3. Ubah status ke IN_PROGRESS
        $this->patchJson("/api/reports/{$report->id}/status", [
            'status' => SystemReport::STATUS_IN_PROGRESS,
            'note' => 'Sedang ditelusuri',
        ])->assertOk();

        // 4. Balasan publik
        $this->postJson("/api/reports/{$report->id}/messages", [
            'message' => 'Sedang kami periksa datanya.',
            'is_internal' => false,
        ])->assertCreated();

        // 5. Catatan internal
        $this->postJson("/api/reports/{$report->id}/messages", [
            'message' => 'Internal: ditemukan delay antrean.',
            'is_internal' => true,
        ])->assertCreated();

        // 6. Penyelesaian laporan (RESOLVED) wajib ringkasan penyelesaian
        $this->patchJson("/api/reports/{$report->id}/status", [
            'status' => SystemReport::STATUS_RESOLVED,
            'resolution_summary' => 'Sinkronisasi status diperbaiki dan surat telah terbit.',
        ])->assertOk();

        $report->refresh();
        $this->assertSame(SystemReport::STATUS_RESOLVED, $report->status);
        $this->assertSame(SystemReport::PRIORITY_CRITICAL, $report->priority);
        $this->assertSame($otherSupervisor->id, $report->assigned_to);
        $this->assertNotNull($report->resolved_at);
        $this->assertNotNull($report->last_replied_at);

        $expectedActions = [
            'REPORT_CREATED',
            'REPORT_ASSIGNED',
            'REPORT_PRIORITY_CHANGED',
            'REPORT_STATUS_CHANGED',
            'REPORT_PUBLIC_REPLIED',
            'REPORT_INTERNAL_NOTE_ADDED',
            'REPORT_RESOLVED',
        ];

        $logs = AuditLog::whereIn('action', $expectedActions)->get();
        foreach ($expectedActions as $action) {
            $this->assertTrue(
                $logs->contains('action', $action),
                "AuditLog untuk aksi {$action} harus tercatat."
            );
        }

        foreach ($logs as $log) {
            $details = (string) $log->details;
            $this->assertStringNotContainsString($warga->password, $details);
            $this->assertStringNotContainsString($supervisor->password, $details);
            $this->assertStringNotContainsString('FONNTE_TOKEN', $details);
        }
    }

    public function test_additional_input_edge_cases_closed_status_and_unverified_citizen_download(): void
    {
        Storage::fake('local');

        $unverifiedWarga = $this->createUser($this->masyarakatRole, ['email_verified_at' => null]);
        $staf = $this->createUser($this->stafRole);
        $supervisor = $this->createUser($this->supervisorRole);

        $this->actingAs($staf, 'sanctum');

        // 1. Batas panjang judul > 180 karakter dan URL skema berbahaya ditolak
        $this->postJson('/api/reports', [
            'category' => SystemReport::CATEGORY_BUG,
            'title' => str_repeat('A', 181),
            'description' => 'Menguji batas maksimal judul 180 karakter.',
            'page_url' => 'javascript:alert(1)',
        ])->assertStatus(422)->assertJsonValidationErrors(['title', 'page_url']);

        // 2. File valid PNG yang diganti ekstensinya menjadi .pdf ditolak (mismatch isi vs ekstensi)
        $pngAsPdf = $this->validPngUpload('menyamar.pdf');
        $this->post('/api/reports', [
            'category' => SystemReport::CATEGORY_BUG,
            'title' => 'Uji ekstensi tidak cocok dengan isi file',
            'description' => 'Mengirim isi PNG dengan ekstensi PDF harus ditolak.',
            'attachments' => [$pngAsPdf],
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['attachments.0']);

        // 3. Staf mencoba menyisipkan is_internal=true pada pesan publik ditolak (403)
        $service = app(SystemReportService::class);
        $report = $service->createReport($unverifiedWarga, [
            'category' => SystemReport::CATEGORY_OTHER,
            'title' => 'Laporan warga sebelum verifikasi email',
            'description' => 'Laporan dibuat untuk menguji unduhan warga yang belum terverifikasi.',
        ], [$this->validPdfUpload('lampiran.pdf')]);

        $attachment = $report->attachments->first();

        $reportStaf = $service->createReport($staf, [
            'category' => SystemReport::CATEGORY_BUG,
            'title' => 'Laporan Staf',
            'description' => 'Menguji penolakan manipulasi is_internal oleh akun staf.',
        ]);
        $this->postJson("/api/reports/{$reportStaf->id}/messages", [
            'message' => 'Coba catatan internal',
            'is_internal' => 1,
        ])->assertForbidden();

        // 4. Warga yang belum terverifikasi email ditolak di route web unduhan lampiran
        $this->actingAs($unverifiedWarga)->get("/laporan/lampiran/{$attachment->id}")->assertRedirect(route('verification.notice'));

        // 5. Status CLOSED mencatat closed_at dengan benar oleh Supervisor
        $this->actingAs($supervisor, 'sanctum');
        $this->patchJson("/api/reports/{$report->id}/status", [
            'status' => SystemReport::STATUS_CLOSED,
            'note' => 'Laporan ditutup setelah verifikasi.',
        ])->assertOk();

        $this->assertNotNull($report->fresh()->closed_at);
    }

    public function test_stage1_audit_sanctum_bearer_policy_registration_and_internal_privacy(): void
    {
        Storage::fake('local');

        $this->assertInstanceOf(\App\Policies\ReportPolicy::class, Gate::getPolicyFor(SystemReport::class));
        $this->assertInstanceOf(\App\Policies\ReportPolicy::class, Gate::getPolicyFor(SystemReportMessage::class));

        $warga = $this->createUser($this->masyarakatRole);
        $staf = $this->createUser($this->stafRole, ['email_verified_at' => null]);
        $kades = $this->createUser($this->kadesRole, ['email_verified_at' => null]);
        $supervisor = $this->createUser($this->supervisorRole, ['email_verified_at' => null]);

        // 1. Sanctum Bearer token autentikasi nyata + kategori primary Fase 3
        $token = $warga->createToken('auth_token')->plainTextToken;
        $resp = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post('/api/reports', [
                'category' => SystemReport::CATEGORY_KENDALA_PENGAJUAN,
                'priority' => SystemReport::PRIORITY_HIGH,
                'title' => 'Laporan dengan Bearer Token Sanctum',
                'description' => 'Menguji pembuatan laporan memakai token Sanctum dan gambar biner tanpa GD.',
                'attachments' => [$this->validJpegUpload('tanpa_gd.jpg'), $this->validPngUpload('tanpa_gd.png')],
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $report = SystemReport::findOrFail($resp->json('data.id'));

        // Supervisor menambahkan catatan internal beserta lampiran internal & histori internal
        $service = app(SystemReportService::class);
        $internalNote = $service->addMessage(
            report: $report,
            actor: $supervisor,
            messageBody: 'RAHASIA SUPERVISOR: temuan log error internal.',
            isInternal: true,
            attachments: [$this->validPdfUpload('audit_rahasia_internal.pdf')]
        );

        // Pastikan pelapor tidak menerima pesan internal, nama file lampiran internal, atau histori internal via API
        $showWarga = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/reports/{$report->id}")
            ->assertOk();

        $showWarga->assertJsonMissing(['message' => 'RAHASIA SUPERVISOR: temuan log error internal.']);
        $showWarga->assertJsonMissing(['original_name' => 'audit_rahasia_internal.pdf']);
        $showWarga->assertJsonMissing(['action' => SystemReportHistory::ACTION_INTERNAL_NOTE_ADDED]);
        $this->assertFalse($warga->can('viewMessage', $internalNote));
        $this->assertCount(1, $report->visibleHistoriesFor($warga)->get());
        $this->assertCount(2, $report->visibleHistoriesFor($supervisor)->get());

        // 2. Pastikan Staf & Kades (walaupun email_verified_at null) tetap dapat mengunduh lampiran laporannya sendiri via verified.citizen
        $stafReport = $service->createReport($staf, [
            'category' => SystemReport::CATEGORY_KENDALA_AKUN,
            'title' => 'Kendala Staf Unduh Lampiran',
            'description' => 'Staf melampirkan berkas untuk verifikasi middleware verified.citizen.',
        ], [$this->validPngUpload('staf_bukti.png')]);
        $stafAttachment = $stafReport->attachments->first();

        $kadesReport = $service->createReport($kades, [
            'category' => SystemReport::CATEGORY_NOTIFIKASI,
            'title' => 'Kendala Kades Unduh Lampiran',
            'description' => 'Kepala Desa melampirkan berkas untuk verifikasi middleware verified.citizen.',
        ], [$this->validPdfUpload('kades_bukti.pdf')]);
        $kadesAttachment = $kadesReport->attachments->first();

        $this->actingAs($staf)->get("/laporan/lampiran/{$stafAttachment->id}")->assertOk();
        $this->actingAs($kades)->get("/laporan/lampiran/{$kadesAttachment->id}")->assertOk();
        $this->actingAs($supervisor)->get("/laporan/lampiran/{$stafAttachment->id}")->assertOk();

        // 3. Akun nonaktif tidak dapat mengakses report atau API meskipun memegang token lama
        $warga->update(['is_active' => false]);
        $warga->refresh();
        $this->assertFalse(Gate::forUser($warga)->allows('view', $report));
        $this->app->make('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/reports/{$report->id}")
            ->assertForbidden();
    }
}
