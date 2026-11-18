<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class CitizenRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $roles = ['masyarakat', 'staf', 'supervisor', 'kades'];
        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }

    /** @test */
    public function test_citizen_can_register_successfully_with_valid_data()
    {
        Event::fake([Registered::class]);

        $payload = [
            'name' => 'Ahmad Warga Contoh',
            'email' => 'ahmad.warga@desa.id',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'L',
            'phone_number' => '0812-3456-7890',
        ];

        $response = $this->postJson('/register', $payload);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'message',
                     'access_token',
                     'token_type',
                 ]);

        // Verifikasi akun user
        $user = User::where('email', 'ahmad.warga@desa.id')->first();
        $this->assertNotNull($user);
        $this->assertEquals('Ahmad Warga Contoh', $user->name);
        $this->assertEquals('L', $user->gender);
        $this->assertEquals('081234567890', $user->phone_number);
        $this->assertEquals('masyarakat', $user->role->name);

        // Verifikasi user profile
        $this->assertNotNull($user->profile);
        $this->assertEquals('L', $user->profile->gender);
        $this->assertEquals('081234567890', $user->profile->phone_number);

        // Verifikasi event Registered di-dispatch untuk mekanisme verifikasi email
        Event::assertDispatched(Registered::class, function ($event) use ($user) {
            return $event->user->id === $user->id;
        });
    }

    /** @test */
    public function test_citizen_can_register_via_web_form_and_redirects_to_dashboard()
    {
        $payload = [
            'name' => 'Siti Warga Perempuan',
            'email' => 'siti.warga@desa.id',
            'password' => 'rahasia12345',
            'password_confirmation' => 'rahasia12345',
            'gender' => 'P',
            'phone_number' => '+62 813-9876-5432',
        ];

        $response = $this->post('/register', $payload);

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticated();

        $user = User::where('email', 'siti.warga@desa.id')->first();
        $this->assertNotNull($user);
        $this->assertEquals('P', $user->gender);
        $this->assertEquals('+6281398765432', $user->phone_number);
        $this->assertEquals('masyarakat', $user->role->name);
    }

    /** @test */
    public function test_registration_fails_when_email_is_duplicate()
    {
        $roleMasyarakat = Role::where('name', 'masyarakat')->first();
        User::create([
            'name' => 'Warga Lama',
            'email' => 'warga.duplikat@desa.id',
            'password' => Hash::make('secret123'),
            'role_id' => $roleMasyarakat->id,
            'gender' => 'L',
            'phone_number' => '081234567890',
        ]);

        $payload = [
            'name' => 'Warga Baru Peniru',
            'email' => 'warga.duplikat@desa.id',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'P',
            'phone_number' => '081299998888',
        ];

        $response = $this->postJson('/register', $payload);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email']);
        $this->assertStringContainsString('sudah terdaftar', $response->json('errors.email.0'));
    }

    /** @test */
    public function test_registration_fails_when_email_is_invalid()
    {
        $payload = [
            'name' => 'Format Email Salah',
            'email' => 'bukan-sebuah-email',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'L',
            'phone_number' => '081234567890',
        ];

        $response = $this->postJson('/register', $payload);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email']);
        $this->assertStringContainsString('Format alamat email tidak valid', $response->json('errors.email.0'));
    }

    /** @test */
    public function test_registration_fails_when_password_confirmation_does_not_match()
    {
        $payload = [
            'name' => 'Sandi Beda',
            'email' => 'sandi.beda@desa.id',
            'password' => 'password123',
            'password_confirmation' => 'passwordBerbeda',
            'gender' => 'L',
            'phone_number' => '081234567890',
        ];

        $response = $this->postJson('/register', $payload);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['password']);
        $this->assertStringContainsString('Konfirmasi kata sandi tidak cocok', $response->json('errors.password.0'));
    }

    /** @test */
    public function test_registration_fails_when_password_is_under_8_characters()
    {
        $payload = [
            'name' => 'Sandi Pendek',
            'email' => 'sandi.pendek@desa.id',
            'password' => '1234567',
            'password_confirmation' => '1234567',
            'gender' => 'L',
            'phone_number' => '081234567890',
        ];

        $response = $this->postJson('/register', $payload);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['password']);
        $this->assertStringContainsString('minimal 8 karakter', $response->json('errors.password.0'));
    }

    /** @test */
    public function test_registration_fails_when_gender_is_missing_or_invalid()
    {
        // Gender kosong
        $respEmpty = $this->postJson('/register', [
            'name' => 'Gender Kosong',
            'email' => 'gender.kosong@desa.id',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => '',
            'phone_number' => '081234567890',
        ]);
        $respEmpty->assertStatus(422)->assertJsonValidationErrors(['gender']);
        $this->assertStringContainsString('Jenis kelamin wajib dipilih', $respEmpty->json('errors.gender.0'));

        // Gender di luar opsi L atau P
        $respInvalid = $this->postJson('/register', [
            'name' => 'Gender Salah',
            'email' => 'gender.salah@desa.id',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'X',
            'phone_number' => '081234567890',
        ]);
        $respInvalid->assertStatus(422)->assertJsonValidationErrors(['gender']);
        $this->assertStringContainsString('Pilihan jenis kelamin tidak valid', $respInvalid->json('errors.gender.0'));
    }

    /** @test */
    public function test_registration_fails_when_phone_number_is_missing_or_invalid()
    {
        // Nomor HP kosong
        $respEmpty = $this->postJson('/register', [
            'name' => 'HP Kosong',
            'email' => 'hp.kosong@desa.id',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'L',
            'phone_number' => '',
        ]);
        $respEmpty->assertStatus(422)->assertJsonValidationErrors(['phone_number']);
        $this->assertStringContainsString('Nomor HP wajib diisi', $respEmpty->json('errors.phone_number.0'));

        // Nomor HP mengandung huruf
        $respLetters = $this->postJson('/register', [
            'name' => 'HP Huruf',
            'email' => 'hp.huruf@desa.id',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'L',
            'phone_number' => '0812ABCDE3456',
        ]);
        $respLetters->assertStatus(422)->assertJsonValidationErrors(['phone_number']);
        $this->assertStringContainsString('Format nomor HP tidak valid', $respLetters->json('errors.phone_number.0'));

        // Nomor HP terlalu pendek (< 10 digit)
        $respShort = $this->postJson('/register', [
            'name' => 'HP Pendek',
            'email' => 'hp.pendek@desa.id',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'L',
            'phone_number' => '0812345',
        ]);
        $respShort->assertStatus(422)->assertJsonValidationErrors(['phone_number']);
        $this->assertStringContainsString('minimal 10 karakter', $respShort->json('errors.phone_number.0'));

        // Nomor HP tanpa awalan yang wajar (bukan 08 atau +62)
        $respPrefix = $this->postJson('/register', [
            'name' => 'HP Awalan Salah',
            'email' => 'hp.awalan@desa.id',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'L',
            'phone_number' => '123456789012',
        ]);
        $respPrefix->assertStatus(422)->assertJsonValidationErrors(['phone_number']);
    }

    /** @test */
    public function test_role_manipulation_attempt_is_prevented()
    {
        $supervisorRole = Role::where('name', 'supervisor')->first();
        $kadesRole = Role::where('name', 'kades')->first();

        $payload = [
            'name' => 'Hacker Wannabe',
            'email' => 'hacker@desa.id',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'L',
            'phone_number' => '081234567890',
            // Upaya injeksi role berbahaya
            'role' => 'supervisor',
            'role_id' => $supervisorRole->id,
            'is_admin' => 1,
            'is_active' => 1,
        ];

        $response = $this->postJson('/register', $payload);
        $response->assertStatus(201);

        $user = User::where('email', 'hacker@desa.id')->first();
        $this->assertNotNull($user);

        // Pastikan role TETAP 'masyarakat', tidak pernah menjadi 'supervisor' atau 'kades'
        $this->assertEquals('masyarakat', $user->role->name);
        $this->assertNotEquals($supervisorRole->id, $user->role_id);
        $this->assertNotEquals($kadesRole->id, $user->role_id);
    }

    /** @test */
    public function test_registration_form_view_renders_correctly_without_unauthorized_fields()
    {
        $response = $this->get('/register');

        $response->assertStatus(200);

        // Memuat field yang diizinkan
        $response->assertSee('name="name"', false);
        $response->assertSee('name="email"', false);
        $response->assertSee('name="password"', false);
        $response->assertSee('name="password_confirmation"', false);
        $response->assertSee('name="gender"', false);
        $response->assertSee('name="phone_number"', false);

        // Jangan menambahkan NIK, KK, tempat lahir, tanggal lahir, domisili pada form registrasi
        $response->assertDontSee('name="nik"', false);
        $response->assertDontSee('name="no_kk"', false);
        $response->assertDontSee('name="kk"', false);
        $response->assertDontSee('name="birth_date"', false);
        $response->assertDontSee('name="birth_place"', false);
        $response->assertDontSee('name="address"', false);
        $response->assertDontSee('name="domisili"', false);
        $response->assertDontSee('name="rt"', false);
        $response->assertDontSee('name="rw"', false);
    }

    /** @test */
    public function test_failed_web_registration_preserves_safe_inputs_and_never_retains_passwords()
    {
        $response = $this->from('/register')->post('/register', [
            'name' => 'Warga Gagal',
            'email' => 'warga.gagal@desa.id',
            'password' => 'secret12345',
            'password_confirmation' => 'salah12345',
            'gender' => 'L',
            'phone_number' => '081234567890',
        ]);

        $response->assertRedirect('/register');

        // Pastikan input aman disimpan di session
        $this->assertEquals('Warga Gagal', session('_old_input.name'));
        $this->assertEquals('warga.gagal@desa.id', session('_old_input.email'));
        $this->assertEquals('L', session('_old_input.gender'));
        $this->assertEquals('081234567890', session('_old_input.phone_number'));

        // Kata sandi TIDAK PERNAH disimpan di _old_input
        $this->assertArrayNotHasKey('password', session('_old_input') ?? []);
        $this->assertArrayNotHasKey('password_confirmation', session('_old_input') ?? []);
    }

    /** @test */
    public function test_application_form_sktm_still_requires_domisili()
    {
        $masyarakatRole = Role::where('name', 'masyarakat')->first();
        $citizen = User::create([
            'name' => 'Warga Pemohon',
            'email' => 'pemohon@desa.id',
            'password' => Hash::make('secret123'),
            'role_id' => $masyarakatRole->id,
            'gender' => 'L',
            'phone_number' => '081234567890',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($citizen);

        // Formulir SKTM (pengajuan layanan) tetap meminta alamat domisili
        $component = Livewire::test('sktm-form');
        $component->assertSee('Alamat Domisili KTP');

        // Validasi step 1 pada pengajuan SKTM mewajibkan data domisili (address, rt, rw)
        $component->set('currentStep', 1)
                  ->set('nik', '3201123456780001')
                  ->set('no_kk', '3201123456780002')
                  ->set('place_of_birth', 'Bandung')
                  ->set('date_of_birth', '1995-05-15')
                  ->set('gender', 'L')
                  ->set('address', '') // Domisili dikosongkan
                  ->set('rt', '')
                  ->set('rw', '')
                  ->set('penghasilan', '1500000')
                  ->call('nextStep')
                  ->assertHasErrors(['address', 'rt', 'rw']);
    }
}
