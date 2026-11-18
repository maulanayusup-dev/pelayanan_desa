<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Laravel\Sanctum\PersonalAccessToken;

class SanctumUuidTokenTest extends TestCase
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
    public function user_can_register_via_json_and_tokenable_id_matches_uuid()
    {
        $payload = [
            'name' => 'Budi UUID Test',
            'email' => 'budi.uuid@desa.id',
            'password' => 'secret12345',
            'password_confirmation' => 'secret12345',
            'gender' => 'L',
            'phone_number' => '081234567890',
        ];

        $response = $this->postJson('/register', $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'message',
            'access_token',
            'token_type'
        ]);

        $user = User::where('email', 'budi.uuid@desa.id')->first();
        $this->assertNotNull($user);
        $this->assertIsString($user->id);
        $this->assertEquals(36, strlen($user->id)); // Valid UUID string

        // Assert profile was created
        $this->assertNotNull($user->profile);

        // Assert personal access token was created with UUID tokenable_id
        $tokenRecord = PersonalAccessToken::where('tokenable_id', $user->id)->first();
        $this->assertNotNull($tokenRecord);
        $this->assertEquals($user->id, $tokenRecord->tokenable_id);
        $this->assertEquals(User::class, $tokenRecord->tokenable_type);

        // Assert polymorphic relationship tokenable works
        $this->assertInstanceOf(User::class, $tokenRecord->tokenable);
        $this->assertEquals($user->id, $tokenRecord->tokenable->id);
    }

    /** @test */
    public function user_can_authenticate_via_bearer_token_on_protected_api_route()
    {
        $role = Role::where('name', 'masyarakat')->first();
        $user = User::create([
            'name' => 'Warga API Test',
            'email' => 'warga.api@desa.id',
            'password' => bcrypt('password123'),
            'role_id' => $role->id,
        ]);

        $plainToken = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $plainToken)
                         ->getJson('/api/user');

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $user->id,
            'email' => $user->email,
        ]);
    }

    /** @test */
    public function api_login_creates_token_and_returns_valid_response()
    {
        $role = Role::where('name', 'masyarakat')->first();
        $user = User::create([
            'name' => 'Warga Login Test',
            'email' => 'warga.login@desa.id',
            'password' => bcrypt('password123'),
            'role_id' => $role->id,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'warga.login@desa.id',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'access_token',
            'token_type',
            'role'
        ]);

        $this->assertEquals(1, $user->tokens()->count());
    }

    /** @test */
    public function web_registration_redirects_and_creates_profile()
    {
        $payload = [
            'name' => 'Warga Web Form',
            'email' => 'warga.web@desa.id',
            'password' => 'secret12345',
            'password_confirmation' => 'secret12345',
            'gender' => 'P',
            'phone_number' => '089876543210',
        ];

        $response = $this->post('/register', $payload);

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticated();

        $user = User::where('email', 'warga.web@desa.id')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->profile);
    }
}
