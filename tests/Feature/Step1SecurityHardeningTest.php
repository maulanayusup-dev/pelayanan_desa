<?php

namespace Tests\Feature;

use App\Livewire\AccountManagement;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\SystemReport;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class Step1SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected Role $roleMasyarakat;
    protected Role $roleStaf;
    protected Role $roleSupervisor;
    protected Role $roleKades;
    protected DocumentType $sktmType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleMasyarakat = Role::create(['id' => (string) Str::uuid(), 'name' => 'masyarakat']);
        $this->roleStaf = Role::create(['id' => (string) Str::uuid(), 'name' => 'staf']);
        $this->roleSupervisor = Role::create(['id' => (string) Str::uuid(), 'name' => 'supervisor']);
        $this->roleKades = Role::create(['id' => (string) Str::uuid(), 'name' => 'kades']);

        $this->sktmType = DocumentType::create([
            'id' => (string) Str::uuid(),
            'code' => 'SKTM',
            'name' => 'Surat Keterangan Tidak Mampu',
            'description' => 'SKTM',
            'is_active' => true,
        ]);

        Storage::fake('local');
    }

    private function makeUser(Role $role, string $name, string $email, bool $active = true, bool $verified = true): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password123'),
            'role_id' => $role->id,
            'is_active' => $active,
            'email_verified_at' => $verified ? now() : null,
            'phone_number' => '081200000001',
        ]);
    }

    public function test_inactive_user_cannot_access_protected_api_chatbot_or_web_with_old_credentials(): void
    {
        $warga = $this->makeUser($this->roleMasyarakat, 'Warga Nonaktif', 'nonaktif@desa.id', true);
        $tokenApi = $warga->createToken('api-client')->plainTextToken;
        $tokenChat = $warga->createToken('chat-client')->plainTextToken;

        // Deactivate directly in DB (simulating legacy/out-of-band deactivation without token delete)
        $warga->update(['is_active' => false]);
        $warga->refresh();

        // 1. Old token calling chatbot is rejected 403 and must NOT fall back to guest
        $this->withHeader('Authorization', 'Bearer ' . $tokenChat)
            ->postJson('/api/chat', ['message' => 'Siapa saya?'])
            ->assertForbidden();

        // Token was revoked upon interception
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $warga->id,
        ]);

        // 2. Fresh token issued before deactivation also fails on /api/user
        $tokenExtra = $warga->createToken('stale-extra')->plainTextToken;
        $this->app->make('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $tokenExtra)
            ->getJson('/api/user')
            ->assertForbidden();

        // 3. Direct Sanctum guard actingAs inactive user fails on /api/applications
        $this->actingAs($warga, 'sanctum')
            ->getJson('/api/applications')
            ->assertForbidden();

        // 4. Policy checks block inactive users across both ApplicationPolicy and ReportPolicy
        $this->assertFalse(Gate::forUser($warga)->allows('viewAny', Application::class));
        $this->assertFalse(Gate::forUser($warga)->allows('viewAny', SystemReport::class));

        // 5. Web guard session for inactive user is logged out and redirected to login
        $this->app->make('auth')->forgetGuards();
        $this->actingAs($warga, 'web')
            ->get('/dashboard')
            ->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_account_management_revokes_sanctum_tokens_on_toggle_update_and_soft_delete(): void
    {
        $supervisor = $this->makeUser($this->roleSupervisor, 'Supervisor Utama', 'spv@desa.id');
        $wargaToggle = $this->makeUser($this->roleMasyarakat, 'Warga Toggle', 'toggle@desa.id');
        $wargaUpdate = $this->makeUser($this->roleMasyarakat, 'Warga Update', 'update@desa.id');
        $wargaDelete = $this->makeUser($this->roleMasyarakat, 'Warga Delete', 'delete@desa.id');

        $tokenToggle = $wargaToggle->createToken('mobile-toggle')->plainTextToken;
        $tokenUpdate = $wargaUpdate->createToken('mobile-update')->plainTextToken;
        $tokenDelete = $wargaDelete->createToken('mobile-delete')->plainTextToken;

        // 1. Deactivate via toggleStatus()
        Livewire::actingAs($supervisor)
            ->test(AccountManagement::class)
            ->call('toggleStatus', $wargaToggle->id);

        $this->assertFalse($wargaToggle->fresh()->is_active);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $wargaToggle->id]);

        // Revoked token cannot access /api/chat as guest OR /api/user
        $this->app->make('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $tokenToggle)
            ->postJson('/api/chat', ['message' => 'Siapa saya?'])
            ->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer ' . $tokenToggle)
            ->getJson('/api/user')
            ->assertUnauthorized();

        // 2. Deactivate via updateUser()
        Livewire::actingAs($supervisor)
            ->test(AccountManagement::class)
            ->call('openEditModal', $wargaUpdate->id)
            ->set('editIsActive', false)
            ->call('updateUser');

        $this->assertFalse($wargaUpdate->fresh()->is_active);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $wargaUpdate->id]);

        $this->app->make('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $tokenUpdate)
            ->getJson('/api/applications')
            ->assertUnauthorized();

        // 3. Soft delete via deleteUser()
        Livewire::actingAs($supervisor)
            ->test(AccountManagement::class)
            ->call('confirmDelete', $wargaDelete->id)
            ->call('deleteUser');

        $this->assertSoftDeleted('users', ['id' => $wargaDelete->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $wargaDelete->id]);

        $this->app->make('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $tokenDelete)
            ->postJson('/api/chat', ['message' => 'Halo'])
            ->assertUnauthorized();
    }

    public function test_soft_deleted_user_with_unrevoked_token_or_session_is_blocked(): void
    {
        $staf = $this->makeUser($this->roleStaf, 'Staf Dihapus', 'staf.deleted@desa.id');
        $token = $staf->createToken('staf-api')->plainTextToken;

        // Direct model soft-delete without manually deleting tokens
        $staf->delete();
        $deletedUser = User::withTrashed()->findOrFail($staf->id);

        $this->assertFalse($deletedUser->isActiveAccount());
        $this->assertFalse(Gate::forUser($deletedUser)->allows('viewAny', Application::class));
        $this->assertFalse(Gate::forUser($deletedUser)->allows('viewAny', SystemReport::class));

        // API calls with Bearer token or actingAs must be denied
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user')
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/chat', ['message' => 'Ada berapa pengajuan?'])
            ->assertUnauthorized();

        $this->actingAs($deletedUser, 'sanctum')
            ->getJson('/api/applications')
            ->assertForbidden();
    }

    public function test_profile_update_syncs_phone_number_and_validates_unique_nik(): void
    {
        $wargaA = $this->makeUser($this->roleMasyarakat, 'Warga Satu', 'warga1@desa.id');
        $wargaB = $this->makeUser($this->roleMasyarakat, 'Warga Dua', 'warga2@desa.id');

        // 1. Warga A updates profile -> syncs BOTH users.phone_number and user_profiles.phone_number
        $this->actingAs($wargaA)
            ->put('/profile', [
                'name' => 'Warga Satu Resmi',
                'phone_number' => '081234567890',
                'nik' => '3201123456789001',
                'no_kk' => '3201123456789002',
                'address' => 'Jl. Kenanga No. 1',
                'rt' => '001',
                'rw' => '002',
                'desa' => 'Desa Pelayanan',
                'kecamatan' => 'Kecamatan Uji',
                'kabupaten' => 'Kabupaten Uji',
                'occupation' => 'Buruh Tani',
                'penghasilan' => 1200000,
                'place_of_birth' => 'Garut',
                'date_of_birth' => '1992-04-12',
                'gender' => 'L',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'id' => $wargaA->id,
            'name' => 'Warga Satu Resmi',
            'phone_number' => '081234567890',
        ]);
        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $wargaA->id,
            'nik' => '3201123456789001',
            'phone_number' => '081234567890',
        ]);

        // 2. Warga A updates profile keeping own NIK -> allowed & syncs new phone_number
        $this->actingAs($wargaA)
            ->put('/profile', [
                'name' => 'Warga Satu Resmi',
                'phone_number' => '089876543210',
                'nik' => '3201123456789001',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('089876543210', $wargaA->fresh()->phone_number);
        $this->assertSame('089876543210', UserProfile::where('user_id', $wargaA->id)->value('phone_number'));

        // 3. Warga B uses Warga A's NIK -> returns clean validation error (422 JSON / 302 session error), no DB crash
        $originalNameB = $wargaB->name;
        $this->actingAs($wargaB)
            ->putJson('/profile', [
                'name' => 'Warga Dua Hack',
                'phone_number' => '081111111111',
                'nik' => '3201123456789001',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nik']);

        $this->assertSame($originalNameB, $wargaB->fresh()->name);
        $this->assertNull(UserProfile::where('user_id', $wargaB->id)->first());

        // 4. Web PUT duplicate NIK redirects back with session validation error on 'nik'
        $this->actingAs($wargaB)
            ->from('/profile')
            ->put('/profile', [
                'name' => 'Warga Dua Web',
                'phone_number' => '081111111111',
                'nik' => '3201123456789001',
            ])
            ->assertRedirect('/profile')
            ->assertSessionHasErrors(['nik']);

        $this->assertSame($originalNameB, $wargaB->fresh()->name);
    }

    public function test_pengajuan_dokumen_enforces_headers_and_role_ownership_security(): void
    {
        $owner = $this->makeUser($this->roleMasyarakat, 'Pemilik Berkas', 'owner@desa.id');
        $otherCitizen = $this->makeUser($this->roleMasyarakat, 'Warga Lain', 'other@desa.id');
        $staf = $this->makeUser($this->roleStaf, 'Staf Desa', 'staf@desa.id');
        $supervisor = $this->makeUser($this->roleSupervisor, 'Supervisor Desa', 'supervisor@desa.id');
        $kades = $this->makeUser($this->roleKades, 'Kepala Desa', 'kades@desa.id');

        $unknownRole = Role::create(['id' => (string) Str::uuid(), 'name' => 'auditor_asing']);
        $strangerRoleUser = $this->makeUser($unknownRole, 'Role Tidak Dikenal', 'stranger@desa.id');

        $app = Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-SEC-001',
            'user_id' => $owner->id,
            'document_type_id' => $this->sktmType->id,
            'status' => 'SUBMITTED',
            'purpose' => 'Beasiswa',
        ]);

        Storage::disk('local')->put('documents/sktm/ktp_owner.png', "\x89PNG\r\n\x1a\nfake-binary");

        $doc = ApplicationDocument::create([
            'id' => (string) Str::uuid(),
            'application_id' => $app->id,
            'document_name' => 'KTP',
            'file_path' => 'documents/sktm/ktp_owner.png',
            'mime_type' => 'image/png',
            'status' => 'PENDING',
        ]);

        $traversalDoc = ApplicationDocument::create([
            'id' => (string) Str::uuid(),
            'application_id' => $app->id,
            'document_name' => 'Traversal',
            'file_path' => '../.env',
            'mime_type' => 'text/plain',
            'status' => 'PENDING',
        ]);

        // 1. Owner + authorized officers succeed with hardening headers
        foreach ([$owner, $staf, $supervisor, $kades] as $allowedUser) {
            $resp = $this->actingAs($allowedUser)->get('/pengajuan/dokumen/' . $doc->id);
            $resp->assertOk();
            $this->assertSame('nosniff', strtolower((string) $resp->headers->get('X-Content-Type-Options')));
            $cacheControl = strtolower((string) $resp->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $cacheControl);
            $this->assertStringContainsString('no-store', $cacheControl);
            $this->assertStringContainsString('noindex', strtolower((string) $resp->headers->get('X-Robots-Tag')));
        }

        // 2. Other citizen & unknown role fail-closed 403
        $this->actingAs($otherCitizen)->get('/pengajuan/dokumen/' . $doc->id)->assertForbidden();
        $this->actingAs($strangerRoleUser)->get('/pengajuan/dokumen/' . $doc->id)->assertForbidden();

        // 3. Path traversal rejected 403
        $this->actingAs($staf)->get('/pengajuan/dokumen/' . $traversalDoc->id)->assertForbidden();

        // 4. Inactive owner blocked on both JSON and Web
        $owner->update(['is_active' => false]);
        $this->actingAs($owner->fresh())->getJson('/pengajuan/dokumen/' . $doc->id)->assertForbidden();
        $this->actingAs($owner->fresh())->get('/pengajuan/dokumen/' . $doc->id)->assertRedirect(route('login'));
    }

    public function test_citizen_registration_and_email_verification_remain_functional(): void
    {
        Mail::fake();

        $registerResponse = $this->post('/register', [
            'name' => 'Warga Pendaftar Baru',
            'email' => 'pendaftar.baru@desa.id',
            'phone_number' => '081277778888',
            'gender' => 'L',
            'password' => 'PasswordKuat123!',
            'password_confirmation' => 'PasswordKuat123!',
        ]);

        $registerResponse->assertRedirect(route('verification.notice'));

        $citizen = User::where('email', 'pendaftar.baru@desa.id')->firstOrFail();
        $this->assertTrue($citizen->is_active);
        $this->assertNull($citizen->email_verified_at);

        // Verification notice & resend route still accessible for active unverified citizen
        $this->actingAs($citizen)->get(route('verification.notice'))->assertOk();
        $this->actingAs($citizen)->post(route('verification.send'))->assertRedirect();

        // Signed verification link marks user verified and allows /dashboard
        $verifyUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(30),
            ['id' => $citizen->id, 'hash' => sha1($citizen->email)]
        );

        $this->actingAs($citizen)->get($verifyUrl)->assertRedirect(route('dashboard'));
        $this->assertNotNull($citizen->fresh()->email_verified_at);

        $this->actingAs($citizen->fresh())->get('/dashboard')->assertOk();
    }
}
