<?php

namespace Tests\Feature;

use App\Jobs\SendFonnteNotificationJob;
use App\Models\Application;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\FonnteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class FonnteNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected Role $roleMasyarakat;
    protected Role $roleStaf;
    protected Role $roleSupervisor;
    protected Role $roleKades;
    protected DocumentType $docTypeSktm;
    protected User $wargaUser;
    protected User $stafUser;
    protected User $supervisorUser;
    protected User $kadesUser;

    protected string $fakeFonnteToken = 'fonnte_device_token_xyz123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleMasyarakat = Role::firstOrCreate(['name' => 'masyarakat'], ['description' => 'Masyarakat']);
        $this->roleStaf = Role::firstOrCreate(['name' => 'staf'], ['description' => 'Staf Desa']);
        $this->roleSupervisor = Role::firstOrCreate(['name' => 'supervisor'], ['description' => 'Supervisor Kasi']);
        $this->roleKades = Role::firstOrCreate(['name' => 'kades'], ['description' => 'Kepala Desa']);

        $this->docTypeSktm = DocumentType::firstOrCreate(
            ['code' => 'SKTM'],
            [
                'name' => 'Surat Keterangan Tidak Mampu',
                'description' => 'Surat bukti prasejahtera',
                'is_active' => true,
            ]
        );

        // Configure default mock Fonnte settings
        Config::set('services.fonnte.enabled', true);
        Config::set('services.fonnte.token', $this->fakeFonnteToken);
        Config::set('services.fonnte.country_code', '62');
        Config::set('services.fonnte.timeout', 10);
        Config::set('services.fonnte.use_queue', false); // synchronous for tests

        // Create Users with phone numbers
        $this->wargaUser = $this->createTestUser($this->roleMasyarakat, 'Warga Siti', 'siti.fonnte@desa.id', '081211112222');
        $this->stafUser = $this->createTestUser($this->roleStaf, 'Staf Doni', 'doni.fonnte@desa.id', '081233334444');
        $this->supervisorUser = $this->createTestUser($this->roleSupervisor, 'Supervisor Rahmat', 'rahmat.fonnte@desa.id', '081255556666');
        $this->kadesUser = $this->createTestUser($this->roleKades, 'Kades Subagyo', 'subagyo.fonnte@desa.id', '081277778888');

        Cache::flush();
        Storage::fake('local');
    }

    private function createTestUser(Role $role, string $name, string $email, ?string $phone): User
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

    private function createTestApplication(User $applicant, string $status = 'SUBMITTED'): Application
    {
        return Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-FNT-' . strtoupper(Str::random(6)),
            'user_id' => $applicant->id,
            'document_type_id' => $this->docTypeSktm->id,
            'status' => $status,
            'form_data' => [
                'keperluan' => 'Keringanan Biaya Rumah Sakit',
                'tujuan' => 'RSUD',
            ],
            'submitted_at' => now(),
        ]);
    }

    /**
     * TEST 1: Format nomor telepon Indonesia diubah secara konsisten dan nomor internasional tidak rusak.
     */
    public function test_phone_number_formatting_normalizes_indonesian_numbers(): void
    {
        $service = new FonnteService();

        // 08xx -> 628xx
        $this->assertEquals('6281234567890', $service->formatPhoneNumber('081234567890'));
        // +628xx -> 628xx
        $this->assertEquals('6281234567890', $service->formatPhoneNumber('+6281234567890'));
        // 628xx tetap 628xx
        $this->assertEquals('6281234567890', $service->formatPhoneNumber('6281234567890'));
        // Strip tanda baca dan spasi
        $this->assertEquals('6281234567890', $service->formatPhoneNumber('(0812) 3456-7890'));
        $this->assertEquals('6281234567890', $service->formatPhoneNumber('81234567890'));

        // Input tidak valid
        $this->assertNull($service->formatPhoneNumber(null));
        $this->assertNull($service->formatPhoneNumber(''));
        $this->assertNull($service->formatPhoneNumber('123')); // terlalu pendek
        $this->assertNull($service->formatPhoneNumber('abcdefghij'));
    }

    /**
     * TEST 2: Fonnte aman dan tidak mengirim HTTP request saat token belum dikonfigurasi atau dinonaktifkan.
     */
    public function test_fonnte_handles_unconfigured_and_disabled_states_safely(): void
    {
        Config::set('services.fonnte.enabled', false);
        Config::set('services.fonnte.token', null);

        Http::fake();

        $service = new FonnteService();

        $this->assertFalse($service->isConfigured());
        $this->assertFalse($service->isEnabled());
        $this->assertEquals('(Belum dikonfigurasi)', $service->getMaskedToken());

        $result = $service->sendMessage('081234567890', 'Pesan uji coba');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertStringContainsString('belum dikonfigurasi', $result['error']);

        Http::assertNothingSent();
    }

    /**
     * TEST 3: Pengiriman pesan berhasil menggunakan HTTP fake dengan format header dan payload Fonnte.
     */
    public function test_send_message_successful_using_http_fake(): void
    {
        $fakeMsgId = 'fnt_msg_987654';

        Http::fake([
            'https://api.fonnte.com/send' => Http::response([
                'status' => true,
                'message' => 'message processed',
                'id' => [$fakeMsgId],
                'target' => ['6281233334444'],
                'process' => 'sent',
            ], 200),
        ]);

        $service = new FonnteService($this->fakeFonnteToken);
        $app = $this->createTestApplication($this->wargaUser, 'SUBMITTED');

        $result = $service->sendMessage(
            '081233334444',
            'Halo Staf Desa, ada berkas baru!',
            $app,
            $this->stafUser,
            'FONNTE_NEW_SUBMISSION'
        );

        $this->assertTrue($result['success']);
        $this->assertEquals($fakeMsgId, $result['message_id']);

        // Verifikasi HTTP Request ke Fonnte
        Http::assertSent(function ($request) {
            $data = $request->data();
            return $request->url() === 'https://api.fonnte.com/send'
                && $request->hasHeader('Authorization', $this->fakeFonnteToken)
                && $data['target'] === '6281233334444'
                && str_contains($data['message'], 'Halo Staf Desa')
                && $data['countryCode'] === '62';
        });
    }

    /**
     * TEST 4: Penanganan respons API gagal dari Fonnte (misal: token invalid atau device disconnect).
     */
    public function test_fonnte_handles_api_failure_response(): void
    {
        Http::fake([
            'https://api.fonnte.com/send' => Http::response([
                'status' => false,
                'reason' => 'device disconnect',
            ], 200), // Fonnte sering mengembalikan HTTP 200 dengan status: false
        ]);

        $service = new FonnteService($this->fakeFonnteToken);
        $app = $this->createTestApplication($this->wargaUser, 'SUBMITTED');

        $result = $service->sendMessage('081233334444', 'Tes Gagal', $app, $this->stafUser, 'TEST_FAIL');

        $this->assertFalse($result['success']);
        $this->assertEquals('device disconnect', $result['error']);

        // Verifikasi token tidak bocor di pesan error
        $this->assertStringNotContainsString($this->fakeFonnteToken, $result['error']);
    }

    /**
     * TEST 5: Penanganan kegagalan timeout koneksi Fonnte secara aman.
     */
    public function test_fonnte_handles_connection_timeout(): void
    {
        Http::fake([
            'https://api.fonnte.com/send' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out after 15000 milliseconds');
            },
        ]);

        $service = new FonnteService($this->fakeFonnteToken);
        $app = $this->createTestApplication($this->wargaUser, 'SUBMITTED');

        $result = $service->sendMessage('081233334444', 'Tes Timeout', $app, $this->stafUser, 'TEST_TIMEOUT');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Koneksi ke gateway Fonnte gagal', $result['error']);
    }

    /**
     * TEST 6: Pencegahan notifikasi ganda (Idempotency) tidak mengirim ulang pesan pada event yang sama.
     */
    public function test_duplicate_notification_prevention(): void
    {
        Http::fake([
            'https://api.fonnte.com/send' => Http::response([
                'status' => true,
                'id' => ['fnt_first_call'],
            ], 200),
        ]);

        $service = new FonnteService($this->fakeFonnteToken);
        $app = $this->createTestApplication($this->wargaUser, 'SUBMITTED');

        // Panggilan pertama: berhasil terkirim
        $res1 = $service->sendMessage('081233334444', 'Pesan 1', $app, $this->stafUser, 'FONNTE_NEW_SUBMISSION');
        $this->assertTrue($res1['success']);
        $this->assertEquals('fnt_first_call', $res1['message_id']);

        // Panggilan kedua untuk aplikasi & status yang sama: dicegah oleh deduplication cache
        $res2 = $service->sendMessage('081233334444', 'Pesan 1', $app, $this->stafUser, 'FONNTE_NEW_SUBMISSION');
        $this->assertTrue($res2['success']);
        $this->assertTrue($res2['skipped'] ?? false);

        // Hanya 1 request HTTP yang dikirim ke Fonnte
        Http::assertSentCount(1);
    }

    /**
     * TEST 7: KETENTUAN UTAMA — Pengajuan SKTM baru (SUBMITTED) HANYA dikirim kepada STAF.
     * Supervisor, Kepala Desa, dan Warga TIDAK menerima notifikasi ini.
     */
    public function test_new_submission_notifies_only_staff_and_excludes_supervisor_kades_and_citizen(): void
    {
        Http::fake([
            'https://api.fonnte.com/send' => Http::response([
                'status' => true,
                'id' => ['fnt_staff_only'],
            ], 200),
        ]);

        $service = new FonnteService($this->fakeFonnteToken);
        $app = $this->createTestApplication($this->wargaUser, 'SUBMITTED');

        $res = $service->notifyOfficersForApplication($app);

        $this->assertTrue($res['dispatched']);
        $this->assertEquals(1, $res['count'], 'Hanya boleh 1 notifikasi yang dikirim ke Staf');

        // 1. Verifikasi terkirim ke Staf (081233334444 -> 6281233334444)
        Http::assertSent(function ($req) {
            return $req['target'] === '6281233334444'
                && str_contains($req['message'], 'PEMBERITAHUAN PENGAJUAN SKTM BARU')
                && str_contains($req['message'], 'Halo Staf Pelayanan Desa')
                && str_contains($req['message'], 'Menunggu Pemeriksaan Staf');
        });

        // 2. Verifikasi TIDAK terkirim ke Supervisor (6281255556666)
        Http::assertNotSent(fn ($req) => $req['target'] === '6281255556666');

        // 3. Verifikasi TIDAK terkirim ke Kepala Desa (6281277778888)
        Http::assertNotSent(fn ($req) => $req['target'] === '6281277778888');

        // 4. Verifikasi TIDAK terkirim ke Warga Pemohon (6281211112222)
        Http::assertNotSent(fn ($req) => $req['target'] === '6281211112222');

        // 5. Verifikasi isi pesan tidak mengandung data sensitif (NIK, KK)
        Http::assertSent(function ($req) {
            $msg = $req['message'];
            return !str_contains(strtolower($msg), 'nik')
                && !str_contains(strtolower($msg), 'kartu keluarga');
        });
    }

    /**
     * TEST 8: Warga berhasil membuat pengajuan via Livewire Form dan memicu notifikasi hanya ke Staf.
     */
    public function test_citizen_submits_sktm_via_livewire_form_triggers_fonnte_only_to_staff(): void
    {
        Http::fake([
            'https://api.fonnte.com/send' => Http::response([
                'status' => true,
                'id' => ['fnt_livewire_submission'],
            ], 200),
            'https://graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp'], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->actingAs($this->wargaUser);

        $fileKtp = UploadedFile::fake()->create('ktp.jpg', 500, 'image/jpeg');
        $fileKk = UploadedFile::fake()->create('kk.jpg', 500, 'image/jpeg');

        Livewire::test(\App\Livewire\SktmForm::class)
            ->set('currentStep', 1)
            ->set('nik', '3201123456789012')
            ->set('no_kk', '3201123456789013')
            ->set('name', 'Siti Pemohon')
            ->set('place_of_birth', 'Bandung')
            ->set('date_of_birth', '1995-05-15')
            ->set('gender', 'P')
            ->set('address', 'Jl. Sukamaju RT 01 RW 02')
            ->set('rt', '01')
            ->set('rw', '02')
            ->set('desa', 'Sukamaju')
            ->set('kecamatan', 'Sukarame')
            ->set('kabupaten', 'Bandung')
            ->set('occupation', 'Wiraswasta')
            ->set('penghasilan', '1500000')
            ->call('nextStep')
            ->set('purpose', 'Bantuan Pengobatan')
            ->call('nextStep')
            ->set('ktp', $fileKtp)
            ->set('kk', $fileKk)
            ->call('submit')
            ->assertRedirect('/pengajuan');

        // Verifikasi pengajuan tersimpan di database
        $this->assertDatabaseHas('applications', [
            'user_id' => $this->wargaUser->id,
            'status' => 'SUBMITTED',
            'purpose' => 'Bantuan Pengobatan',
        ]);

        // Verifikasi Fonnte HANYA mengirim ke nomor Staf
        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'api.fonnte.com/send')
                && $req['target'] === '6281233334444';
        });

        // Verifikasi Fonnte TIDAK mengirim ke Supervisor, Kades, atau Warga
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.fonnte.com/send') && $req['target'] === '6281255556666');
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.fonnte.com/send') && $req['target'] === '6281277778888');
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.fonnte.com/send') && $req['target'] === '6281211112222');
    }

    /**
     * TEST 9: Pengajuan yang gagal divalidasi TIDAK memicu notifikasi WhatsApp.
     */
    public function test_validation_failure_does_not_trigger_fonnte_notification(): void
    {
        Http::fake();

        $this->actingAs($this->wargaUser);

        // Submit form kosong (akan gagal validasi pada step 1)
        Livewire::test(\App\Livewire\SktmForm::class)
            ->set('currentStep', 1)
            ->set('nik', '') // Kosong, melanggar validasi
            ->call('nextStep')
            ->assertHasErrors(['nik']);

        Http::assertNothingSent();
    }

    /**
     * TEST 10: Pengajuan SKTM tetap berhasil disimpan meskipun Fonnte mengalami kegagalan API.
     */
    public function test_application_workflow_succeeds_even_when_fonnte_fails(): void
    {
        Http::fake([
            'https://api.fonnte.com/send' => Http::response([
                'status' => false,
                'reason' => 'server maintenance',
            ], 500),
        ]);

        $app = $this->createTestApplication($this->wargaUser, 'SUBMITTED');

        // Eksekusi gateway notification
        NotificationService::notifyStatusChange($app);

        // Verifikasi aplikasi tetap tersimpan di database
        $this->assertDatabaseHas('applications', [
            'id' => $app->id,
            'status' => 'SUBMITTED',
        ]);
    }

    /**
     * TEST 11: Penanganan aman ketika staf tidak memiliki nomor telepon atau nomor tidak valid.
     */
    public function test_handles_empty_or_invalid_staff_phone_safely(): void
    {
        // Ubah staf agar nomor HP kosong
        $this->stafUser->update(['phone_number' => null]);
        Config::set('services.fonnte.admin_phones.staf', null);
        Config::set('services.fonnte.default_recipient', null);

        Http::fake();

        $service = new FonnteService($this->fakeFonnteToken);
        $app = $this->createTestApplication($this->wargaUser, 'SUBMITTED');

        $res = $service->notifyOfficersForApplication($app);

        $this->assertTrue($res['dispatched']);
        $this->assertEquals(0, $res['count'], 'Pesan tidak dikirim jika tidak ada nomor valid');

        // Tidak ada HTTP call yang dikirim
        Http::assertNothingSent();
    }

    /**
     * TEST 12: Saluran email Gmail warga tetap terkirim saat Fonnte mengalami kegagalan.
     */
    public function test_email_continues_independently_when_fonnte_fails(): void
    {
        Http::fake([
            'https://api.fonnte.com/send' => Http::response([
                'status' => false,
                'reason' => 'token invalid',
            ], 401),
        ]);

        \Illuminate\Support\Facades\Mail::fake();

        $app = $this->createTestApplication($this->wargaUser, 'SUBMITTED');

        NotificationService::notifyStatusChange($app);

        // Verifikasi email konfirmasi untuk warga tetap terkirim
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\ApplicationConfirmationMailable::class, function ($mail) {
            return $mail->hasTo($this->wargaUser->email);
        });
    }

    /**
     * TEST 13: Tahapan HEAD_APPROVAL mengirim WA ke Kepala Desa, sedangkan SUPERVISOR_REVIEW tidak lagi mengirim notifikasi aktif.
     */
    public function test_subsequent_workflow_stages_still_notify_their_respective_roles(): void
    {
        Http::fake([
            'https://api.fonnte.com/send' => Http::response([
                'status' => true,
                'id' => ['fnt_stage_ok'],
            ], 200),
        ]);

        $service = new FonnteService($this->fakeFonnteToken);

        // 1. SUPERVISOR_REVIEW (status historis): tidak lagi mengirim WA ke Supervisor
        $app1 = $this->createTestApplication($this->wargaUser, 'SUPERVISOR_REVIEW');
        $res1 = $service->notifyOfficersForApplication($app1);
        $this->assertFalse($res1['dispatched']);
        Http::assertNotSent(fn ($req) => $req['target'] === '6281255556666');
        Http::assertNotSent(fn ($req) => $req['target'] === '6281233334444');

        // 2. HEAD_APPROVAL: ditujukan khusus ke Kepala Desa
        $app2 = $this->createTestApplication($this->wargaUser, 'HEAD_APPROVAL');
        $res2 = $service->notifyOfficersForApplication($app2);
        $this->assertTrue($res2['dispatched']);
        $this->assertEquals(1, $res2['count']);
        Http::assertSent(fn ($req) => $req['target'] === '6281277778888' && str_contains($req['message'], 'KEPALA DESA'));
        Http::assertNotSent(fn ($req) => $req['target'] === '6281233334444'); // Staf tidak menerima ini
    }

    /**
     * TEST 14: Pengiriman melalui queue job SendFonnteNotificationJob berjalan sukses.
     */
    public function test_queue_job_executes_successfully(): void
    {
        Http::fake([
            'https://api.fonnte.com/send' => Http::response([
                'status' => true,
                'id' => ['fnt_job_success'],
            ], 200),
        ]);

        $app = $this->createTestApplication($this->wargaUser, 'SUBMITTED');

        $job = new SendFonnteNotificationJob(
            '081233334444',
            'Pesan dari Queue Job Fonnte',
            $app->id,
            $this->stafUser->id,
            'JOB_TEST'
        );

        $service = new FonnteService($this->fakeFonnteToken);
        $job->handle($service);

        Http::assertSent(function ($req) {
            return $req['target'] === '6281233334444'
                && str_contains($req['message'], 'Pesan dari Queue Job Fonnte');
        });
    }

    /**
     * TEST 15: Pengecekan profil perangkat WhatsApp via Fonnte /device endpoint.
     */
    public function test_check_device_status_endpoint(): void
    {
        Http::fake([
            'https://api.fonnte.com/device' => Http::response([
                'status' => true,
                'device' => '6281234567890',
                'device_status' => 'connect',
                'name' => 'Server Desa Sukamaju',
                'quota' => '100',
                'expired' => '31 Desember 2026',
            ], 200),
        ]);

        $service = new FonnteService($this->fakeFonnteToken);
        $status = $service->checkDeviceStatus();

        $this->assertTrue($status['success']);
        $this->assertTrue($status['connected']);
        $this->assertEquals('6281234567890', $status['device']);
        $this->assertEquals('100', $status['quota']);
    }

    // ---------------------------------------------------------------------
    // Perbaikan target notifikasi pengajuan SKTM baru (hanya STAF)
    // ---------------------------------------------------------------------

    private function fakeFonnteSuccess(): void
    {
        Http::fake([
            'https://api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['fnt_ok']], 200),
        ]);
    }

    private function fonnteTargets(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.fonnte.com/send'))
            ->map(fn ($pair) => $pair[0]['target'])
            ->values()
            ->all();
    }

    private function fillValidSktmForm(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(\App\Livewire\SktmForm::class)
            ->set('nik', '3201123456789012')
            ->set('no_kk', '3201123456789013')
            ->set('name', 'Siti Pemohon')
            ->set('place_of_birth', 'Bandung')
            ->set('date_of_birth', '1995-05-15')
            ->set('gender', 'P')
            ->set('address', 'Jl. Sukamaju')
            ->set('rt', '01')
            ->set('rw', '02')
            ->set('desa', 'Sukamaju')
            ->set('kecamatan', 'Sukarame')
            ->set('kabupaten', 'Bandung')
            ->set('occupation', 'Wiraswasta')
            ->set('penghasilan', '1500000')
            ->call('nextStep')
            ->set('purpose', 'Bantuan Pengobatan')
            ->call('nextStep');
    }

    /**
     * TEST 16: Nomor fallback .env (FONNTE_STAFF_PHONE / KADES / DEFAULT) TIDAK dipakai untuk pengajuan baru.
     * Ini penyebab utama bug: FONNTE_STAFF_PHONE berisi nomor yang sama dengan FONNTE_KADES_PHONE.
     */
    public function test_new_submission_never_uses_env_fallback_numbers(): void
    {
        $this->stafUser->update(['phone_number' => null]);
        Config::set('services.fonnte.admin_phones.staf', '081277778888');      // = nomor Kades
        Config::set('services.fonnte.admin_phones.kades', '081277778888');
        Config::set('services.fonnte.admin_phones.supervisor', '081255556666');
        Config::set('services.fonnte.default_recipient', '081277778888');
        $this->fakeFonnteSuccess();

        $res = (new FonnteService())->notifyOfficersForApplication($this->createTestApplication($this->wargaUser));

        $this->assertSame(0, $res['count']);
        Http::assertNothingSent();
    }

    /**
     * TEST 17: SUPERVISOR dan KEPALA_DESA (termasuk nama role 'kepala_desa') tidak menerima,
     * walaupun ada beberapa staf; semua staf dengan nomor valid menerima.
     */
    public function test_all_staff_receive_and_supervisor_kades_kepala_desa_do_not(): void
    {
        $roleKepalaDesa = Role::firstOrCreate(['name' => 'kepala_desa']);
        $this->createTestUser($roleKepalaDesa, 'Kepala Desa Lama', 'kepdes.fonnte@desa.id', '081299990000');
        $this->createTestUser($this->roleStaf, 'Staf Kedua', 'staf2.fonnte@desa.id', '+62 812-4444-5555');
        $this->fakeFonnteSuccess();

        $res = (new FonnteService())->notifyOfficersForApplication($this->createTestApplication($this->wargaUser));

        $this->assertSame(2, $res['count']);
        $targets = $this->fonnteTargets();
        sort($targets);
        $this->assertSame(['6281233334444', '6281244445555'], $targets);
    }

    /**
     * TEST 18: Nomor staf yang juga terdaftar pada akun Kades/Supervisor atau milik warga pemohon dilewati.
     */
    public function test_staff_number_shared_with_kades_supervisor_or_applicant_is_skipped(): void
    {
        $this->createTestUser($this->roleStaf, 'Staf Pakai HP Kades', 'stafkades@desa.id', '0812-7777-8888');
        $this->createTestUser($this->roleStaf, 'Staf Pakai HP Supervisor', 'stafspv@desa.id', '6281255556666');
        $this->createTestUser($this->roleStaf, 'Staf Pakai HP Warga', 'stafwarga@desa.id', '081211112222');
        $this->fakeFonnteSuccess();

        $res = (new FonnteService())->notifyOfficersForApplication($this->createTestApplication($this->wargaUser));

        $this->assertSame(1, $res['count']);
        $this->assertSame(['6281233334444'], $this->fonnteTargets());
    }

    /**
     * TEST 19: Nomor staf tidak valid / kosong ditangani aman, staf lain tetap menerima.
     */
    public function test_invalid_staff_numbers_are_skipped_safely(): void
    {
        $this->createTestUser($this->roleStaf, 'Staf Nomor Pendek', 'pendek@desa.id', '12345');
        $this->createTestUser($this->roleStaf, 'Staf Nomor Huruf', 'huruf@desa.id', 'tidak-ada');
        $this->createTestUser($this->roleStaf, 'Staf Tanpa Nomor', 'kosong@desa.id', null);
        $this->fakeFonnteSuccess();

        $res = (new FonnteService())->notifyOfficersForApplication($this->createTestApplication($this->wargaUser));

        $this->assertSame(1, $res['count']);
        $this->assertSame(['6281233334444'], $this->fonnteTargets());
    }

    /**
     * TEST 20: Pemanggilan ganda untuk pengajuan yang sama tidak mengirim pesan ganda.
     */
    public function test_repeated_trigger_for_same_submission_sends_only_once(): void
    {
        $this->fakeFonnteSuccess();
        $app = $this->createTestApplication($this->wargaUser);

        NotificationService::notifyStatusChange($app);
        NotificationService::notifyStatusChange($app);
        (new FonnteService())->notifyOfficersForApplication($app);

        $this->assertSame(['6281233334444'], $this->fonnteTargets());
    }

    /**
     * TEST 21: Isi pesan sesuai format dan tidak memuat data sensitif warga.
     */
    public function test_new_submission_message_has_required_format_without_sensitive_data(): void
    {
        $app = $this->createTestApplication($this->wargaUser);
        $app->update([
            'submitted_at' => \Illuminate\Support\Carbon::parse('2026-11-05 01:30:00', 'UTC'),
            'form_data' => ['nik' => '3201123456789012', 'no_kk' => '3201123456789013', 'name' => 'Warga Siti'],
            'purpose' => 'Bantuan Pengobatan',
        ]);

        $msg = (new FonnteService())->buildNewSubmissionMessage($app->fresh());

        $this->assertStringContainsString('PEMBERITAHUAN PENGAJUAN SKTM BARU', $msg);
        $this->assertStringContainsString('Halo Staf Pelayanan Desa', $msg);
        $this->assertStringContainsString('Nomor Pengajuan: ' . $app->application_number, $msg);
        $this->assertStringContainsString('Tanggal Pengajuan: 05/11/2026 08:30 WIB', $msg);
        $this->assertStringContainsString('Status: Menunggu Pemeriksaan Staf', $msg);
        $this->assertStringContainsString('/admin/pengajuan/' . $app->id, $msg);
        $this->assertStringContainsString('Pesan otomatis dari Sistem Pelayanan Desa.', $msg);

        foreach (['3201123456789012', '3201123456789013', 'Warga Siti', 'Bantuan Pengobatan', 'Keringanan Biaya', 'NIK', 'KK'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $msg);
        }
    }

    /**
     * TEST 22: Submit lewat Livewire yang gagal validasi di langkah akhir tidak membuat pengajuan dan tidak mengirim WA.
     */
    public function test_livewire_submit_with_failed_validation_sends_nothing(): void
    {
        $this->fakeFonnteSuccess();
        $this->actingAs($this->wargaUser);

        $this->fillValidSktmForm()
            ->call('submit') // tanpa KTP & KK
            ->assertHasErrors(['ktp', 'kk']);

        $this->assertDatabaseCount('applications', 0);
        Http::assertNothingSent();
    }

    /**
     * TEST 23: Kegagalan API Fonnte saat warga submit tidak membatalkan pengajuan.
     */
    public function test_livewire_submission_is_saved_when_fonnte_api_fails(): void
    {
        Http::fake([
            'https://api.fonnte.com/send' => Http::response(['status' => false, 'reason' => 'device disconnect'], 500),
        ]);
        $this->actingAs($this->wargaUser);

        $this->fillValidSktmForm()
            ->set('ktp', UploadedFile::fake()->create('ktp.jpg', 100, 'image/jpeg'))
            ->set('kk', UploadedFile::fake()->create('kk.jpg', 100, 'image/jpeg'))
            ->call('submit')
            ->assertRedirect('/pengajuan');

        $this->assertDatabaseHas('applications', ['user_id' => $this->wargaUser->id, 'status' => 'SUBMITTED']);
        $this->assertSame(['6281233334444'], $this->fonnteTargets());
    }

    /**
     * TEST 24: Submit ganda pada komponen Livewire yang sama hanya membuat 1 pengajuan & 1 pesan.
     */
    public function test_livewire_double_submit_creates_single_application_and_single_message(): void
    {
        $this->fakeFonnteSuccess();
        $this->actingAs($this->wargaUser);

        $this->fillValidSktmForm()
            ->set('ktp', UploadedFile::fake()->create('ktp.jpg', 100, 'image/jpeg'))
            ->set('kk', UploadedFile::fake()->create('kk.jpg', 100, 'image/jpeg'))
            ->call('submit')
            ->call('submit');

        $this->assertDatabaseCount('applications', 1);
        $this->assertSame(['6281233334444'], $this->fonnteTargets());
    }

    /**
     * TEST 25: Mode queue — job hanya didorong setelah transaksi commit, hanya untuk staf, dan tidak didorong saat rollback.
     */
    public function test_queue_job_dispatched_only_after_commit_and_only_for_staff(): void
    {
        Config::set('services.fonnte.use_queue', true);
        \Illuminate\Support\Facades\Queue::fake();
        Http::fake();

        // Rollback: tidak ada job
        try {
            \Illuminate\Support\Facades\DB::transaction(function () {
                $app = $this->createTestApplication($this->wargaUser);
                NotificationService::notifyStatusChange($app);
                throw new \RuntimeException('simulasi gagal simpan');
            });
        } catch (\RuntimeException) {
        }
        \Illuminate\Support\Facades\Queue::assertNotPushed(SendFonnteNotificationJob::class);

        // Commit: tepat 1 job untuk staf
        \Illuminate\Support\Facades\DB::transaction(function () {
            $app = $this->createTestApplication($this->wargaUser);
            NotificationService::notifyStatusChange($app);
            \Illuminate\Support\Facades\Queue::assertNotPushed(SendFonnteNotificationJob::class);
        });

        \Illuminate\Support\Facades\Queue::assertPushed(SendFonnteNotificationJob::class, 1);
        \Illuminate\Support\Facades\Queue::assertPushed(SendFonnteNotificationJob::class, function ($job) {
            return $job->targetPhone === '6281233334444'
                && $job->officerId === $this->stafUser->id
                && $job->type === FonnteService::TYPE_NEW_SUBMISSION;
        });
        Http::assertNothingSent();
    }

    /**
     * TEST 26: Notifikasi tahapan lain ke staf (COMPLETED/REJECTED) tidak memakai template "pengajuan baru", dan APPROVED tidak mengirim FONNTE_COMPLETED.
     */
    public function test_other_staff_events_do_not_use_new_submission_template(): void
    {
        $this->fakeFonnteSuccess();
        $service = new FonnteService();

        $approvedRes = $service->notifyOfficersForApplication($this->createTestApplication($this->wargaUser, 'APPROVED'));
        $this->assertFalse($approvedRes['dispatched']);
        Http::assertNothingSent();

        $service->notifyOfficersForApplication($this->createTestApplication($this->wargaUser, 'COMPLETED'));
        $service->notifyOfficersForApplication($this->createTestApplication($this->wargaUser, 'REJECTED'));

        Http::assertSent(fn ($req) => $req['target'] === '6281233334444' && str_contains($req['message'], 'SKTM SELESAI'));
        Http::assertSent(fn ($req) => $req['target'] === '6281233334444' && str_contains($req['message'], 'PENOLAKAN SKTM'));
        Http::assertNotSent(fn ($req) => str_contains($req['message'], 'PENGAJUAN SKTM BARU'));
    }

    /**
     * TEST 27: Konfigurasi pengujian tidak memakai token/nomor asli dari .env.
     */
    public function test_phpunit_environment_disables_real_fonnte_credentials(): void
    {
        $this->assertFalse((bool) env('FONNTE_ENABLED'));
        $this->assertEmpty(env('FONNTE_API_TOKEN'));
        $this->assertEmpty(env('FONNTE_STAFF_PHONE'));
        $this->assertEmpty(env('FONNTE_KADES_PHONE'));
        $this->assertEmpty(env('FONNTE_SUPERVISOR_PHONE'));
        $this->assertEmpty(env('FONNTE_DEFAULT_RECIPIENT_PHONE'));
    }
}
