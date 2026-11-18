<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AuthenticationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'masyarakat', 'id' => (string) Str::uuid()]);
        Role::create(['name' => 'staf', 'id' => (string) Str::uuid()]);
        Role::create(['name' => 'supervisor', 'id' => (string) Str::uuid()]);
        Role::create(['name' => 'kades', 'id' => (string) Str::uuid()]);
    }

    private function createUser($roleName, $password = 'password123')
    {
        $role = Role::where('name', $roleName)->first();
        return User::factory()->create([
            'role_id' => $role->id,
            'password' => Hash::make($password),
        ]);
    }

    public function test_1_login_page_can_be_opened(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
        $response->assertSee('Selamat Datang');
        $response->assertSee('Login ke Sistem Pelayanan Desa');
    }

    public function test_2_login_with_valid_credentials_succeeds_for_masyarakat(): void
    {
        $user = $this->createUser('masyarakat', 'secret123');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_3_login_with_valid_credentials_succeeds_for_staff_and_redirects_to_admin(): void
    {
        $staf = $this->createUser('staf', 'secret123');

        $response = $this->post('/login', [
            'email' => $staf->email,
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($staf);
    }

    public function test_4_login_with_invalid_credentials_fails_with_errors(): void
    {
        $user = $this->createUser('masyarakat', 'secret123');

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_5_unauthenticated_user_cannot_access_protected_pages(): void
    {
        $response1 = $this->get('/dashboard');
        $response1->assertRedirect(route('login'));

        $response2 = $this->get('/admin/dashboard');
        $response2->assertRedirect(route('login'));
    }

    public function test_6_logout_clears_session_and_redirects_to_login(): void
    {
        $user = $this->createUser('masyarakat');
        $this->actingAs($user);

        $response = $this->post('/logout');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_7_already_logged_in_user_visiting_login_redirects_to_appropriate_dashboard(): void
    {
        $masyarakat = $this->createUser('masyarakat');
        $this->actingAs($masyarakat);

        $response1 = $this->get('/login');
        $response1->assertRedirect(route('dashboard'));

        $staf = $this->createUser('staf');
        $this->actingAs($staf);

        $response2 = $this->get('/login');
        $response2->assertRedirect(route('admin.dashboard'));
    }

    public function test_8_api_login_returns_json_and_token(): void
    {
        $user = $this->createUser('masyarakat', 'secret123');

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['message', 'access_token', 'token_type']);
    }

    public function test_9_api_login_with_invalid_credentials_returns_422(): void
    {
        $user = $this->createUser('masyarakat', 'secret123');

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_10_redirect_urls_do_not_leak_default_localhost_without_port(): void
    {
        $user = $this->createUser('masyarakat', 'secret123');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $targetUrl = $response->headers->get('Location');
        // Ensure it doesn't redirect to bare http://localhost/dashboard (port 80)
        $this->assertStringNotContainsString('http://localhost/', $targetUrl);
    }
}
