<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\DocumentType;
use App\Models\Application;

class SktmFormStepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'masyarakat', 'description' => 'Masyarakat']);
        Role::create(['name' => 'staf', 'description' => 'Staf Desa']);
        Role::create(['name' => 'supervisor', 'description' => 'Supervisor']);
        Role::create(['name' => 'kades', 'description' => 'Kepala Desa']);

        DocumentType::create([
            'name' => 'Surat Keterangan Tidak Mampu (SKTM)',
            'code' => 'SKTM',
            'description' => 'SKTM'
        ]);

        Storage::fake('local');
    }

    private function createMasyarakatUser()
    {
        $role = Role::where('name', 'masyarakat')->first();
        return User::create([
            'name' => 'Ahmad Warga',
            'email' => 'ahmad@desa.id',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    public function test_step_1_validation_fails_when_required_fields_including_kabupaten_are_empty()
    {
        $user = $this->createMasyarakatUser();
        $this->actingAs($user);

        Livewire::test('sktm-form')
            ->set('currentStep', 1)
            ->call('nextStep')
            ->assertHasErrors([
                'nik',
                'no_kk',
                'place_of_birth',
                'date_of_birth',
                'gender',
                'address',
                'rt',
                'rw',
                'desa',
                'kecamatan',
                'kabupaten',
                'occupation',
                'penghasilan',
            ])
            ->assertSet('currentStep', 1);
    }

    public function test_step_1_validation_fails_when_format_is_invalid()
    {
        $user = $this->createMasyarakatUser();
        $this->actingAs($user);

        Livewire::test('sktm-form')
            ->set('nik', '12345') // not 16 digits
            ->set('no_kk', 'abcdef') // not numeric
            ->set('gender', 'X') // not in L,P
            ->set('penghasilan', -50000) // negative
            ->call('nextStep')
            ->assertHasErrors(['nik', 'no_kk', 'gender', 'penghasilan'])
            ->assertSet('currentStep', 1);
    }

    public function test_step_1_proceeds_to_step_2_when_all_fields_including_kabupaten_are_valid()
    {
        $user = $this->createMasyarakatUser();
        $this->actingAs($user);

        Livewire::test('sktm-form')
            ->set('nik', '3201234567890001')
            ->set('no_kk', '3201234567890002')
            ->set('name', 'Ahmad Warga')
            ->set('place_of_birth', 'Bogor')
            ->set('date_of_birth', '1995-05-15')
            ->set('gender', 'L')
            ->set('occupation', 'Buruh Harian Lepas')
            ->set('penghasilan', 1200000)
            ->set('address', 'Jl. Sukajadi No. 12')
            ->set('rt', '003')
            ->set('rw', '002')
            ->set('desa', 'Sukamaju')
            ->set('kecamatan', 'Cibungbulang')
            ->set('kabupaten', 'Bogor')
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('currentStep', 2);
    }

    public function test_can_navigate_back_and_forth_preserving_filled_data()
    {
        $user = $this->createMasyarakatUser();
        $this->actingAs($user);

        $component = Livewire::test('sktm-form')
            ->set('nik', '3201234567890001')
            ->set('no_kk', '3201234567890002')
            ->set('name', 'Ahmad Warga')
            ->set('place_of_birth', 'Bogor')
            ->set('date_of_birth', '1995-05-15')
            ->set('gender', 'L')
            ->set('occupation', 'Pedagang')
            ->set('penghasilan', 800000)
            ->set('address', 'Kp. Melati')
            ->set('rt', '01')
            ->set('rw', '05')
            ->set('desa', 'Sukamaju')
            ->set('kecamatan', 'Cibungbulang')
            ->set('kabupaten', 'Bogor')
            ->call('nextStep')
            ->assertSet('currentStep', 2)
            ->set('purpose', 'Beasiswa Pendidikan (KIP/Bidikmisi)')
            ->set('applicant_notes', 'Untuk keperluan daftar kuliah anak')
            // Go back to Step 1
            ->call('prevStep')
            ->assertSet('currentStep', 1)
            ->assertSet('nik', '3201234567890001')
            ->assertSet('kabupaten', 'Bogor')
            ->assertSet('purpose', 'Beasiswa Pendidikan (KIP/Bidikmisi)')
            // Go forward to Step 2 again
            ->call('nextStep')
            ->assertSet('currentStep', 2)
            ->assertSet('purpose', 'Beasiswa Pendidikan (KIP/Bidikmisi)')
            ->call('nextStep')
            ->assertSet('currentStep', 3);
    }

    public function test_step_2_validation_requires_purpose()
    {
        $user = $this->createMasyarakatUser();
        $this->actingAs($user);

        Livewire::test('sktm-form')
            ->set('currentStep', 2)
            ->set('purpose', '')
            ->call('nextStep')
            ->assertHasErrors(['purpose'])
            ->assertSet('currentStep', 2);
    }

    public function test_full_flow_submission_saves_application_and_profile_in_database()
    {
        $user = $this->createMasyarakatUser();
        $this->actingAs($user);

        $fileKtp = UploadedFile::fake()->create('ktp_asli.jpg', 500, 'image/jpeg');
        $fileKk = UploadedFile::fake()->create('kk_asli.pdf', 800, 'application/pdf');

        Livewire::test('sktm-form')
            ->set('nik', '3201234567890001')
            ->set('no_kk', '3201234567890002')
            ->set('name', 'Ahmad Warga')
            ->set('place_of_birth', 'Bogor')
            ->set('date_of_birth', '1995-05-15')
            ->set('gender', 'L')
            ->set('occupation', 'Buruh Tani')
            ->set('penghasilan', 750000)
            ->set('address', 'Kp. Cijambe RT 02 RW 01')
            ->set('rt', '2')
            ->set('rw', '1')
            ->set('desa', 'Sukaresmi')
            ->set('kecamatan', 'Tamansari')
            ->set('kabupaten', 'Bogor')
            ->call('nextStep')
            ->set('purpose', 'Keringanan Biaya Rumah Sakit')
            ->set('applicant_notes', 'Biaya rawat inap puskesmas')
            ->call('nextStep')
            ->set('ktp', $fileKtp)
            ->set('kk', $fileKk)
            ->call('submit')
            ->assertRedirect('/pengajuan')
            ->assertSessionHas('message');

        // Check database application
        $this->assertDatabaseHas('applications', [
            'user_id' => $user->id,
            'status' => 'SUBMITTED',
            'purpose' => 'Keringanan Biaya Rumah Sakit',
        ]);

        // Check user profile updated with kabupaten
        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'nik' => '3201234567890001',
            'kabupaten' => 'Bogor',
            'desa' => 'Sukaresmi',
            'kecamatan' => 'Tamansari',
        ]);

        $app = Application::first();
        $this->assertNotNull($app);
        $this->assertEquals(2, $app->documents()->count());
        $this->assertDatabaseHas('application_histories', [
            'application_id' => $app->id,
            'status_to' => 'SUBMITTED',
        ]);
    }

    public function test_step_1_fails_when_nik_already_registered_by_another_user()
    {
        $user1 = $this->createMasyarakatUser();
        $user1->profile()->create([
            'nik' => '3201234567890001',
            'no_kk' => '3201234567890002',
            'gender' => 'L',
        ]);

        $role = Role::where('name', 'masyarakat')->first();
        $user2 = User::create([
            'name' => 'Budi Warga',
            'email' => 'budi@desa.id',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->actingAs($user2);

        Livewire::test('sktm-form')
            ->set('nik', '3201234567890001') // duplicate NIK of user1
            ->set('no_kk', '3201234567899999')
            ->set('name', 'Budi Warga')
            ->set('place_of_birth', 'Bandung')
            ->set('date_of_birth', '1998-08-17')
            ->set('gender', 'L')
            ->set('occupation', 'Wiraswasta')
            ->set('penghasilan', 1500000)
            ->set('address', 'Jl. Merdeka No. 45')
            ->set('rt', '01')
            ->set('rw', '02')
            ->set('desa', 'Sukamaju')
            ->set('kecamatan', 'Cibungbulang')
            ->set('kabupaten', 'Bogor')
            ->call('nextStep')
            ->assertHasErrors(['nik'])
            ->assertSet('currentStep', 1);
    }

    public function test_user_can_submit_with_own_existing_profile_nik()
    {
        $user = $this->createMasyarakatUser();
        $user->profile()->create([
            'nik' => '3201234567890001',
            'no_kk' => '3201234567890002',
            'gender' => 'L',
            'place_of_birth' => 'Bogor',
            'date_of_birth' => '1995-05-15',
            'occupation' => 'Buruh',
            'penghasilan' => 1000000,
            'address' => 'Jl. Desa',
            'rt' => '01',
            'rw' => '02',
            'desa' => 'Sukaresmi',
            'kecamatan' => 'Tamansari',
            'kabupaten' => 'Bogor',
        ]);
        $this->actingAs($user);

        $fileKtp = UploadedFile::fake()->create('ktp_asli.jpg', 500, 'image/jpeg');
        $fileKk = UploadedFile::fake()->create('kk_asli.pdf', 800, 'application/pdf');

        Livewire::test('sktm-form')
            ->assertSet('nik', '3201234567890001') // auto-populated from own profile
            ->call('nextStep')
            ->assertSet('currentStep', 2)
            ->set('purpose', 'Beasiswa Pendidikan (KIP/Bidikmisi)')
            ->call('nextStep')
            ->assertSet('currentStep', 3)
            ->set('ktp', $fileKtp)
            ->set('kk', $fileKk)
            ->call('submit')
            ->assertRedirect('/pengajuan')
            ->assertSessionHas('message');

        $this->assertDatabaseHas('applications', [
            'user_id' => $user->id,
            'status' => 'SUBMITTED',
        ]);
    }

    public function test_submit_switches_to_step_1_if_nik_fails_during_final_submit()
    {
        $user1 = $this->createMasyarakatUser();
        $user1->profile()->create([
            'nik' => '3201234567890001',
            'gender' => 'L',
        ]);

        $role = Role::where('name', 'masyarakat')->first();
        $user2 = User::create([
            'name' => 'Budi Warga',
            'email' => 'budi2@desa.id',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->actingAs($user2);

        $fileKtp = UploadedFile::fake()->create('ktp_asli.jpg', 500, 'image/jpeg');
        $fileKk = UploadedFile::fake()->create('kk_asli.pdf', 800, 'application/pdf');

        // Simulate reaching step 3 with a duplicate NIK
        Livewire::test('sktm-form')
            ->set('nik', '3201234567890001') // duplicate NIK
            ->set('no_kk', '3201234567890002')
            ->set('name', 'Budi Warga')
            ->set('place_of_birth', 'Bogor')
            ->set('date_of_birth', '1995-05-15')
            ->set('gender', 'L')
            ->set('occupation', 'Buruh')
            ->set('penghasilan', 1000000)
            ->set('address', 'Jl. Desa')
            ->set('rt', '01')
            ->set('rw', '02')
            ->set('desa', 'Sukaresmi')
            ->set('kecamatan', 'Tamansari')
            ->set('kabupaten', 'Bogor')
            ->set('purpose', 'Beasiswa Pendidikan (KIP/Bidikmisi)')
            ->set('currentStep', 3)
            ->set('ktp', $fileKtp)
            ->set('kk', $fileKk)
            ->call('submit')
            ->assertHasErrors(['nik'])
            ->assertSet('currentStep', 1); // Automatically navigates back to Step 1

        $this->assertDatabaseMissing('applications', [
            'user_id' => $user2->id,
        ]);
    }

    public function test_unauthorized_role_cannot_submit_application()
    {
        $role = Role::where('name', 'staf')->first();
        $staf = User::create([
            'name' => 'Staf Desa',
            'email' => 'staf@desa.id',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
        ]);
        $this->actingAs($staf);

        // Visiting pengajuan/create as non-masyarakat is forbidden
        $response = $this->get('/pengajuan/create');
        $response->assertStatus(403);

        $this->assertDatabaseMissing('applications', [
            'user_id' => $staf->id,
        ]);
    }
}
