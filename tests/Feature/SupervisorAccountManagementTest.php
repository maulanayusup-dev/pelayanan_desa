<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\UserProfile;
use App\Models\Application;
use App\Models\DocumentType;
use App\Models\AuditLog;
use App\Livewire\AccountManagement;
use App\Services\VillageDataIntelligenceService;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class SupervisorAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $supervisor;
    protected User $otherSupervisor;
    protected User $warga;
    protected User $staf;
    protected User $kades;
    protected Role $roleSupervisor;
    protected Role $roleWarga;
    protected Role $roleStaf;
    protected Role $roleKades;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleWarga = Role::firstOrCreate(['name' => 'masyarakat']);
        $this->roleStaf = Role::firstOrCreate(['name' => 'staf']);
        $this->roleSupervisor = Role::firstOrCreate(['name' => 'supervisor']);
        $this->roleKades = Role::firstOrCreate(['name' => 'kades']);

        $this->supervisor = User::factory()->create([
            'name' => 'Pak Budi Supervisor',
            'email' => 'supervisor@desa.id',
            'role_id' => $this->roleSupervisor->id,
            'is_active' => true,
        ]);

        $this->otherSupervisor = User::factory()->create([
            'name' => 'Ibu Maya Supervisor',
            'email' => 'maya.supervisor@desa.id',
            'role_id' => $this->roleSupervisor->id,
            'is_active' => true,
        ]);

        $this->warga = User::factory()->create([
            'name' => 'Warga Santoso',
            'email' => 'santoso@gmail.com',
            'role_id' => $this->roleWarga->id,
            'is_active' => true,
        ]);

        $this->staf = User::factory()->create([
            'name' => 'Staf Pelayanan',
            'email' => 'staf@desa.id',
            'role_id' => $this->roleStaf->id,
            'is_active' => true,
        ]);

        $this->kades = User::factory()->create([
            'name' => 'Kepala Desa Sukamaju',
            'email' => 'kades@desa.id',
            'role_id' => $this->roleKades->id,
            'is_active' => true,
        ]);
    }

    /**
     * Test 1: Supervisor yang berwenang dapat membuka daftar akun dan melihat akun warga, staf, kades.
     */
    public function test_supervisor_dapat_membuka_daftar_akun()
    {
        $response = $this->actingAs($this->supervisor)->get(route('admin.users'));

        $response->assertStatus(200);
        $response->assertSee('Manajemen Akun Pengguna');
        $response->assertSee('Total Akun Terdaftar');
        $response->assertSee($this->warga->name);
        $response->assertSee($this->staf->name);
        $response->assertSee($this->kades->name);
    }

    /**
     * Test 2: Supervisor berhasil membuat akun Warga dan Staff Desa dengan password terenkripsi.
     */
    /**
     * Test 2: Supervisor berhasil membuat seluruh jenis akun (Warga, Staf, Kades, dan Supervisor baru).
     */
    public function test_supervisor_berhasil_membuat_keempat_jenis_akun_termasuk_supervisor_baru()
    {
        // 1. Buat akun Warga
        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->set('newName', 'Budi Warga')
            ->set('newEmail', 'budi.warga@desa.id')
            ->set('newPassword', 'passwordAman123')
            ->set('newRoleId', $this->roleWarga->id)
            ->set('newIsActive', true)
            ->call('saveNewUser')
            ->assertHasNoErrors();

        $wargaUser = User::where('email', 'budi.warga@desa.id')->first();
        $this->assertNotNull($wargaUser);
        $this->assertEquals($this->roleWarga->id, $wargaUser->role_id);
        $this->assertNotNull($wargaUser->profile); // Profil otomatis terbuat

        // 2. Buat akun Staff Desa
        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->set('newName', 'Dewi Staff')
            ->set('newEmail', 'dewi.staff@desa.id')
            ->set('newPassword', 'staffAman2026')
            ->set('newRoleId', $this->roleStaf->id)
            ->set('newIsActive', true)
            ->call('saveNewUser')
            ->assertHasNoErrors();

        $staffUser = User::where('email', 'dewi.staff@desa.id')->first();
        $this->assertNotNull($staffUser);
        $this->assertEquals($this->roleStaf->id, $staffUser->role_id);

        // 3. Buat akun Kepala Desa baru
        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->set('newName', 'Kades Baru')
            ->set('newEmail', 'kades.baru@desa.id')
            ->set('newPassword', 'kadesAman2026')
            ->set('newRoleId', $this->roleKades->id)
            ->set('newIsActive', true)
            ->call('saveNewUser')
            ->assertHasNoErrors();

        $kadesUser = User::where('email', 'kades.baru@desa.id')->first();
        $this->assertNotNull($kadesUser);
        $this->assertEquals($this->roleKades->id, $kadesUser->role_id);

        // 4. Buat akun Supervisor baru
        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->set('newName', 'Supervisor Baru')
            ->set('newEmail', 'supervisor.baru@desa.id')
            ->set('newPassword', 'spvAman2026')
            ->set('newRoleId', $this->roleSupervisor->id)
            ->set('newIsActive', true)
            ->call('saveNewUser')
            ->assertHasNoErrors();

        $newSpvUser = User::where('email', 'supervisor.baru@desa.id')->first();
        $this->assertNotNull($newSpvUser);
        $this->assertEquals($this->roleSupervisor->id, $newSpvUser->role_id);

        // Password di-hash
        $this->assertTrue(Hash::check('passwordAman123', $wargaUser->password));
        $this->assertTrue(Hash::check('staffAman2026', $staffUser->password));
        $this->assertTrue(Hash::check('kadesAman2026', $kadesUser->password));
        $this->assertTrue(Hash::check('spvAman2026', $newSpvUser->password));

        // Audit log tercatat
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->supervisor->id,
            'action' => 'CREATE_USER',
        ]);
    }

    /**
     * Test 3: Akun baru dapat login menggunakan autentikasi yang benar.
     */
    public function test_akun_baru_dapat_login_menggunakan_autentikasi_yang_benar()
    {
        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->set('newName', 'Siti Rahma')
            ->set('newEmail', 'siti.rahma@desa.id')
            ->set('newPassword', 'sitiSecret2026')
            ->set('newRoleId', $this->roleWarga->id)
            ->set('newIsActive', true)
            ->call('saveNewUser');

        auth()->logout();
        $this->assertGuest();

        $response = $this->post(route('login.post'), [
            'email' => 'siti.rahma@desa.id',
            'password' => 'sitiSecret2026',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertEquals('siti.rahma@desa.id', auth()->user()->email);
    }

    /**
     * Test 4: Supervisor berhasil mengedit akun Warga dan perubahannya tersimpan.
     */
    public function test_supervisor_mengedit_akun_dan_perubahan_tersimpan()
    {
        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->call('openEditModal', $this->warga->id)
            ->set('editName', 'Warga Santoso Terupdate')
            ->set('editEmail', 'santoso.baru@desa.id')
            ->set('editRoleId', $this->roleStaf->id) // Ubah role ke Staff Desa
            ->set('editIsActive', false)
            ->call('updateUser')
            ->assertHasNoErrors();

        $this->warga->refresh();
        $this->assertEquals('Warga Santoso Terupdate', $this->warga->name);
        $this->assertEquals('santoso.baru@desa.id', $this->warga->email);
        $this->assertEquals($this->roleStaf->id, $this->warga->role_id);
        $this->assertFalse($this->warga->is_active);
    }

    /**
     * Test 5: Supervisor dapat mengedit akun Kepala Desa dan mengubah rolenya.
     */
    public function test_supervisor_dapat_mengedit_akun_kepala_desa()
    {
        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->call('openEditModal', $this->kades->id)
            ->assertSet('showEditModal', true)
            ->set('editName', 'Kades Sukamaju Terupdate')
            ->set('editEmail', 'kades.update@desa.id')
            ->set('editRoleId', $this->roleStaf->id)
            ->call('updateUser')
            ->assertHasNoErrors();

        $this->kades->refresh();
        $this->assertEquals('Kades Sukamaju Terupdate', $this->kades->name);
        $this->assertEquals('kades.update@desa.id', $this->kades->email);
        $this->assertEquals($this->roleStaf->id, $this->kades->role_id);
    }

    /**
     * Test 6: Supervisor dapat menonaktifkan dan menghapus akun Kepala Desa.
     */
    public function test_supervisor_dapat_menonaktifkan_dan_menghapus_kades()
    {
        // Toggle status kades
        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->call('toggleStatus', $this->kades->id);

        $this->kades->refresh();
        $this->assertFalse($this->kades->is_active);

        // Delete kades (soft delete)
        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->call('confirmDelete', $this->kades->id)
            ->call('deleteUser');

        $this->assertSoftDeleted('users', ['id' => $this->kades->id]);
    }

    /**
     * Test 7: Sistem menolak penonaktifan atau penghapusan akun Supervisor terakhir.
     */
    public function test_sistem_menolak_penonaktifan_atau_penghapusan_akun_supervisor_terakhir()
    {
        // Nonaktifkan supervisor kedua (Maya), sehingga Budi menjadi satu-satunya supervisor aktif
        $this->otherSupervisor->is_active = false;
        $this->otherSupervisor->save();

        // 1. Coba nonaktifkan akun supervisor terakhir via updateUser
        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->call('openEditModal', $this->supervisor->id)
            ->set('editIsActive', false)
            ->call('updateUser')
            ->assertSet('feedbackType', 'error');

        $this->supervisor->refresh();
        $this->assertTrue($this->supervisor->is_active);

        // 2. Coba ubah role akun supervisor terakhir ke non-supervisor
        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->call('openEditModal', $this->supervisor->id)
            ->set('editRoleId', $this->roleWarga->id)
            ->call('updateUser')
            ->assertHasErrors(['editRoleId']);

        $this->supervisor->refresh();
        $this->assertEquals($this->roleSupervisor->id, $this->supervisor->role_id);

        // 3. Coba hapus akun supervisor terakhir
        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->call('confirmDelete', $this->supervisor->id)
            ->call('deleteUser');

        $this->assertNotSoftDeleted('users', ['id' => $this->supervisor->id]);
    }

    /**
     * Test 8: Supervisor tidak dapat mengubah role, menonaktifkan, atau menghapus akun miliknya sendiri.
     */
    public function test_supervisor_tidak_dapat_mengubah_atau_menghapus_akun_miliknya_sendiri()
    {
        // Pastikan ada supervisor lain aktif
        $this->otherSupervisor->is_active = true;
        $this->otherSupervisor->save();

        // Coba ganti role sendiri
        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->set('editUserId', $this->supervisor->id)
            ->set('editName', $this->supervisor->name)
            ->set('editEmail', $this->supervisor->email)
            ->set('editRoleId', $this->roleWarga->id)
            ->call('updateUser')
            ->assertHasErrors(['editRoleId']);

        // Coba nonaktifkan diri sendiri
        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->call('toggleStatus', $this->supervisor->id);

        $this->supervisor->refresh();
        $this->assertTrue($this->supervisor->is_active);

        // Coba hapus akun sendiri
        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->call('confirmDelete', $this->supervisor->id)
            ->call('deleteUser');

        $this->assertNotSoftDeleted('users', ['id' => $this->supervisor->id]);
    }

    /**
     * Test 9: Validasi menolak email duplikat dan role tidak valid.
     */
    public function test_validasi_menolak_email_duplikat_dan_role_tidak_valid()
    {
        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->set('newName', 'User Duplikat')
            ->set('newEmail', $this->warga->email)
            ->set('newPassword', 'password123')
            ->set('newRoleId', $this->roleWarga->id)
            ->call('saveNewUser')
            ->assertHasErrors(['newEmail']);

        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->set('newName', 'Role Invalid')
            ->set('newEmail', 'invalid.role@desa.id')
            ->set('newPassword', 'password123')
            ->set('newRoleId', '00000000-0000-0000-0000-000000000000')
            ->call('saveNewUser')
            ->assertHasErrors(['newRoleId']);
    }

    /**
     * Test 10: Pengguna tanpa izin (Warga, Staf, Kepala Desa) tidak dapat mengakses manajemen akun (403 Forbidden).
     */
    public function test_pengguna_tanpa_izin_tidak_dapat_mengakses_manajemen_akun()
    {
        // 1. Warga
        $responseWarga = $this->actingAs($this->warga)->get(route('admin.users'));
        $responseWarga->assertStatus(403);
        Livewire::actingAs($this->warga)
            ->test(AccountManagement::class)
            ->assertForbidden();

        // 2. Staf Desa
        $responseStaf = $this->actingAs($this->staf)->get(route('admin.users'));
        $responseStaf->assertStatus(403);
        Livewire::actingAs($this->staf)
            ->test(AccountManagement::class)
            ->assertForbidden();

        // 3. Kepala Desa
        $responseKades = $this->actingAs($this->kades)->get(route('admin.users'));
        $responseKades->assertStatus(403);
        Livewire::actingAs($this->kades)
            ->test(AccountManagement::class)
            ->assertForbidden();
    }

    /**
     * Test 12: Penghapusan menggunakan Soft Delete dan tidak merusak relasi pengajuan/audit log.
     */
    public function test_penghapusan_tidak_merusak_relasi_pengajuan_dan_audit_log()
    {
        $docType = DocumentType::create([
            'name' => 'Surat Keterangan Tidak Mampu',
            'code' => 'SKTM',
            'is_active' => true,
        ]);

        $application = Application::create([
            'user_id' => $this->warga->id,
            'document_type_id' => $docType->id,
            'application_number' => 'SKTM-2026-0099',
            'status' => 'SUBMITTED',
            'purpose' => 'Bantuan Pendidikan Anak',
        ]);

        $auditLog = AuditLog::create([
            'user_id' => $this->warga->id,
            'action' => 'SUBMIT_APPLICATION',
            'details' => 'Application submitted',
        ]);

        // Hapus akun warga
        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->call('confirmDelete', $this->warga->id)
            ->call('deleteUser');

        $this->assertSoftDeleted('users', ['id' => $this->warga->id]);

        // Relasi dan data pengajuan tetap utuh
        $application->refresh();
        $this->assertNotNull($application);
        $this->assertEquals($this->warga->id, $application->user_id);

        $auditLog->refresh();
        $this->assertNotNull($auditLog);
        $this->assertEquals($this->warga->id, $auditLog->user_id);
    }

    /**
     * Test 13: Statistik akun pada dashboard cocok dengan database riil.
     */
    public function test_statistik_akun_pada_dashboard_cocok_dengan_database()
    {
        $totalExpected = User::count();
        $activeExpected = User::where('is_active', true)->count();
        $inactiveExpected = User::where('is_active', false)->count();

        Livewire::actingAs($this->supervisor)
            ->test(AccountManagement::class)
            ->assertViewHas('totalAccounts', $totalExpected)
            ->assertViewHas('activeAccounts', $activeExpected)
            ->assertViewHas('inactiveAccounts', $inactiveExpected);
    }

    /**
     * Test 14: AI menjawab statistik sesuai hasil query database aktual dan menolak akun unauthorized.
     */
    public function test_ai_menjawab_statistik_sesuai_hasil_query_dan_permission()
    {
        $intelligenceService = app(VillageDataIntelligenceService::class);

        // 1. Supervisor bertanya total akun terdaftar
        $resSupervisor = $intelligenceService->getAccountOverviewStatistics($this->supervisor);
        $this->assertTrue($resSupervisor['authorized']);
        $this->assertEquals(User::count(), $resSupervisor['total']);

        // 2. Supervisor bertanya role yang tersedia
        $resRoles = $intelligenceService->getAvailableRolesList($this->supervisor);
        $this->assertTrue($resRoles['authorized']);
        $this->assertEquals(4, $resRoles['total_role_types']);

        // 3. Warga ditolak sopan tanpa membocorkan data internal
        $resWarga = $intelligenceService->getAccountOverviewStatistics($this->warga);
        $this->assertFalse($resWarga['authorized']);
        $this->assertNull($resWarga['data']);
        $this->assertStringContainsString('Maaf', $resWarga['refusal_message']);
    }

    /**
     * Test 15: Login, logout, dan penolakan akun dinonaktifkan tetap berfungsi.
     */
    public function test_login_dan_logout_tetap_berfungsi_serta_akun_nonaktif_ditolak()
    {
        // Login normal
        $response = $this->post(route('login.post'), [
            'email' => $this->supervisor->email,
            'password' => 'password',
        ]);
        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();

        // Logout
        $responseLogout = $this->post(route('logout'));
        $responseLogout->assertRedirect(route('login'));
        $this->assertGuest();

        // Akun nonaktif ditolak login
        $this->warga->is_active = false;
        $this->warga->save();

        $responseInactive = $this->post(route('login.post'), [
            'email' => $this->warga->email,
            'password' => 'password',
        ]);
        $responseInactive->assertSessionHasErrors(['email']);
        $this->assertGuest();
    }

    /**
     * Test 16: AI Chatbot menolak permintaan aksi manajemen akun dari non-supervisor (Warga, Staf, Kades).
     */
    public function test_ai_chatbot_menolak_aksi_manajemen_akun_dari_non_supervisor()
    {
        $intelligenceService = app(VillageDataIntelligenceService::class);

        // 1. Warga meminta buatkan akun
        $resWarga = $intelligenceService->handleAccountManagementInquiry($this->warga, 'Tolong buatkan akun warga baru');
        $this->assertFalse($resWarga['authorized']);
        $this->assertStringContainsString('Maaf', $resWarga['message']);
        $this->assertStringContainsString('Supervisor', $resWarga['message']);

        // 2. Staf Desa meminta ubah role
        $resStaf = $intelligenceService->handleAccountManagementInquiry($this->staf, 'Ubah role akun staf ini');
        $this->assertFalse($resStaf['authorized']);
        $this->assertStringContainsString('Maaf', $resStaf['message']);

        // 3. Kepala Desa meminta hapus akun melalui percakapan
        $resKades = $intelligenceService->handleAccountManagementInquiry($this->kades, 'Hapus akun user santoso');
        $this->assertFalse($resKades['authorized']);
        $this->assertStringContainsString('Maaf', $resKades['message']);
    }

    /**
     * Test 17: AI Chatbot menampilkan konfirmasi jelas dan prosedur resmi saat Supervisor meminta manajemen akun.
     */
    public function test_ai_chatbot_memberikan_prosedur_konfirmasi_dan_validasi_manajemen_akun_kepada_supervisor()
    {
        $aiService = app(\App\Services\AiService::class);
        $conv = \App\Models\Conversation::create([
            'user_id' => $this->supervisor->id,
            'session_id' => 'spv-test-crud-ai',
            'title' => 'Test AI Manage Account'
        ]);

        $reply = $aiService->processConversation($conv, 'Tolong buatkan akun warga baru untuk Budi', $this->supervisor);

        $this->assertStringContainsString('Supervisor', $reply);
        $this->assertStringContainsString('Konfirmasi Prosedur Keamanan', $reply);
        $this->assertStringContainsString('/admin/users', $reply);
        $this->assertStringContainsString('Audit Log', $reply);
    }
}
