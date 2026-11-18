<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileAndDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'masyarakat', 'id' => (string) Str::uuid()]);
        Role::create(['name' => 'staf', 'id' => (string) Str::uuid()]);
        Role::create(['name' => 'supervisor', 'id' => (string) Str::uuid()]);
        Role::create(['name' => 'kades', 'id' => (string) Str::uuid()]);

        DocumentType::create([
            'id' => (string) Str::uuid(),
            'code' => 'SKTM',
            'name' => 'Surat Keterangan Tidak Mampu'
        ]);

        Storage::fake('local');
    }

    private function createMasyarakat($email = 'warga@desa.id')
    {
        $role = Role::where('name', 'masyarakat')->first();
        return User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Warga Asli',
            'email' => $email,
            'password' => Hash::make('password123'),
            'role_id' => $role->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    public function test_user_can_view_profile_page()
    {
        $user = $this->createMasyarakat();
        $this->actingAs($user);

        $response = $this->get('/profile');
        $response->assertStatus(200);
        $response->assertSee('Profil Saya');
        $response->assertSee($user->name);
    }

    public function test_user_can_update_profile_and_user_profiles_table_is_saved()
    {
        $user = $this->createMasyarakat();
        $this->actingAs($user);

        $response = $this->put('/profile', [
            'name' => 'Warga Baru Update',
            'phone_number' => '081299998888',
            'nik' => '3201123456789012',
            'no_kk' => '3201123456789099',
            'address' => 'Jl. Mawar No. 10',
            'rt' => '001',
            'rw' => '002',
            'desa' => 'Desa Pelayanan',
            'kecamatan' => 'Kecamatan Contoh',
            'kabupaten' => 'Kabupaten Maju',
            'occupation' => 'Wiraswasta',
            'penghasilan' => 2000000,
            'place_of_birth' => 'Bandung',
            'date_of_birth' => '1995-05-20',
            'gender' => 'L',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Warga Baru Update',
        ]);

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'nik' => '3201123456789012',
            'phone_number' => '081299998888',
            'desa' => 'Desa Pelayanan',
        ]);
    }

    public function test_user_can_update_password()
    {
        $user = $this->createMasyarakat();
        $this->actingAs($user);

        $response = $this->put('/profile/password', [
            'current_password' => 'password123',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success_password');

        $user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $user->password));
    }

    public function test_user_cannot_update_password_with_wrong_current_password()
    {
        $user = $this->createMasyarakat();
        $this->actingAs($user);

        $response = $this->put('/profile/password', [
            'current_password' => 'wrongpassword',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertSessionHasErrors('current_password');
    }

    public function test_user_cannot_download_unapproved_sktm()
    {
        $user = $this->createMasyarakat();
        $this->actingAs($user);

        $docType = DocumentType::where('code', 'SKTM')->first();
        $app = Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-20261015-TEST',
            'user_id' => $user->id,
            'document_type_id' => $docType->id,
            'status' => 'SUBMITTED',
            'purpose' => 'Beasiswa Pendidikan',
        ]);

        $response = $this->get('/pengajuan/' . $app->id . '/download');
        $response->assertSessionHas('error');
    }

    public function test_user_can_download_approved_sktm()
    {
        $user = $this->createMasyarakat();
        $this->actingAs($user);

        $docType = DocumentType::where('code', 'SKTM')->first();
        $app = Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-20261015-APPR',
            'user_id' => $user->id,
            'document_type_id' => $docType->id,
            'status' => 'APPROVED',
            'purpose' => 'Keringanan Biaya Rumah Sakit',
            'form_data' => [
                'nik' => '3201123456789012',
                'name' => 'Warga Asli',
                'place_of_birth' => 'Bandung',
                'date_of_birth' => '1995-05-20',
                'gender' => 'L',
                'occupation' => 'Petani',
                'address' => 'Jl. Desa',
                'rt' => '01',
                'rw' => '02',
                'desa' => 'Desa Pelayanan',
            ],
            'resolved_at' => now(),
        ]);

        $response = $this->get('/pengajuan/' . $app->id . '/download');
        $response->assertStatus(200);
        $this->assertTrue(str_contains($response->headers->get('content-type') ?? '', 'application/pdf'));
    }

    public function test_user_cannot_access_other_users_application_or_download()
    {
        $userA = $this->createMasyarakat('userA@desa.id');
        $userB = $this->createMasyarakat('userB@desa.id');

        $docType = DocumentType::where('code', 'SKTM')->first();
        $appB = Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-USER-B',
            'user_id' => $userB->id,
            'document_type_id' => $docType->id,
            'status' => 'APPROVED',
            'purpose' => 'Keperluan B',
        ]);

        // User A tries to view User B's detail -> must return 404/403
        $this->actingAs($userA);
        $responseView = $this->get('/pengajuan/' . $appB->id);
        $responseView->assertStatus(404);

        // User A tries to download User B's SKTM -> must abort 403
        $responseDownload = $this->get('/pengajuan/' . $appB->id . '/download');
        $responseDownload->assertStatus(403);
    }

    public function test_user_can_submit_revision_when_needs_revision()
    {
        $user = $this->createMasyarakat();
        $this->actingAs($user);

        $docType = DocumentType::where('code', 'SKTM')->first();
        $app = Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-REV-01',
            'user_id' => $user->id,
            'document_type_id' => $docType->id,
            'status' => 'NEEDS_REVISION',
            'purpose' => 'Bantuan Sosial',
        ]);

        $app->documents()->create([
            'id' => (string) Str::uuid(),
            'document_name' => 'KTP',
            'file_path' => 'documents/sktm/old_ktp.jpg',
            'status' => 'REJECTED',
            'notes' => 'KTP buram'
        ]);

        $newKtp = UploadedFile::fake()->create('ktp_baru.jpg', 500, 'image/jpeg');

        $response = $this->post('/pengajuan/' . $app->id . '/revisi', [
            'ktp' => $newKtp,
            'notes' => 'Sudah saya upload ulang foto KTP yang lebih jelas.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('message');

        $app->refresh();
        $this->assertEquals('SUBMITTED', $app->status);

        $this->assertDatabaseHas('application_histories', [
            'application_id' => $app->id,
            'status_from' => 'NEEDS_REVISION',
            'status_to' => 'SUBMITTED',
        ]);
    }
}
