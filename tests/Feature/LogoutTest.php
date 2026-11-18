<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Str;

class LogoutTest extends TestCase
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

    private function createMasyarakatUser()
    {
        $role = Role::where('name', 'masyarakat')->first();
        return User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Warga Test Logout',
            'email' => 'warga.logout@test.id',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    /** @test */
    public function test_web_logout_invalidates_session_and_redirects_to_login()
    {
        // 1. Create valid user & login
        $user = $this->createMasyarakatUser();
        $this->actingAs($user, 'web');

        // 2. Open dashboard
        $response = $this->get('/dashboard');
        $response->assertStatus(200);

        // 3. Post to /logout with CSRF
        $logoutResponse = $this->post('/logout');

        // 4. Assert redirected to login page
        $logoutResponse->assertRedirect('/login');

        // 5. Assert user is unauthenticated
        $this->assertGuest('web');

        // 6. Direct access to /dashboard after logout must redirect to /login
        $dashboardResponse = $this->get('/dashboard');
        $dashboardResponse->assertRedirect('/login');
    }

    /** @test */
    public function test_get_logout_redirects_to_login_gracefully()
    {
        // Opening http://127.0.0.1:8000/logout via GET must redirect to /login without 405 error or JSON message
        $response = $this->get('/logout');
        $response->assertRedirect('/login');
    }

    /** @test */
    public function test_api_logout_returns_json_contract()
    {
        $user = $this->createMasyarakatUser();
        $token = $user->createToken('test_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                         ->postJson('/api/logout');

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Logout berhasil']);
    }

    /** @test */
    public function test_relogin_after_logout_succeeds()
    {
        $user = $this->createMasyarakatUser();

        // 1. Initial Login
        $this->post('/login', [
            'email' => 'warga.logout@test.id',
            'password' => 'password'
        ]);
        $this->assertAuthenticatedAs($user, 'web');

        // 2. Logout
        $this->post('/logout');
        $this->assertGuest('web');

        // 3. Re-login
        $loginResponse = $this->post('/login', [
            'email' => 'warga.logout@test.id',
            'password' => 'password'
        ]);

        $this->assertAuthenticatedAs($user, 'web');
    }
}
