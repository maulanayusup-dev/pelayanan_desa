<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Setup default roles
        Role::create(['name' => 'masyarakat', 'id' => (string) \Illuminate\Support\Str::uuid()]);
        Role::create(['name' => 'staf', 'id' => (string) \Illuminate\Support\Str::uuid()]);
        Role::create(['name' => 'supervisor', 'id' => (string) \Illuminate\Support\Str::uuid()]);
        Role::create(['name' => 'kades', 'id' => (string) \Illuminate\Support\Str::uuid()]);
        
        // Setup default DocumentType
        \App\Models\DocumentType::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'code' => 'SKTM',
            'name' => 'SKTM'
        ]);
    }

    private function createUserWithRole($roleName)
    {
        $role = Role::where('name', $roleName)->first();
        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_masyarakat_can_create_application()
    {
        $user = $this->createUserWithRole('masyarakat');
        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/applications');

        $response->assertStatus(201);
    }

    public function test_staf_cannot_create_application()
    {
        $user = $this->createUserWithRole('staf');
        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/applications');

        $response->assertStatus(403);
    }

    public function test_staf_can_access_staff_routes()
    {
        $user = $this->createUserWithRole('staf');
        $this->actingAs($user, 'sanctum');

        // Application created by someone else in SUBMITTED state
        $app = Application::forceCreate([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'application_number' => 'APP-123',
            'user_id' => $this->createUserWithRole('masyarakat')->id,
            'document_type_id' => \App\Models\DocumentType::first()->id,
            'status' => 'SUBMITTED'
        ]);

        $response = $this->postJson("/api/applications/{$app->id}/staff-review");
        $response->assertStatus(200);
    }

    public function test_masyarakat_cannot_access_staff_routes()
    {
        $user = $this->createUserWithRole('masyarakat');
        $this->actingAs($user, 'sanctum');

        $app = Application::forceCreate([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'application_number' => 'APP-124',
            'user_id' => $user->id,
            'document_type_id' => \App\Models\DocumentType::first()->id,
            'status' => 'SUBMITTED'
        ]);

        $response = $this->postJson("/api/applications/{$app->id}/staff-review");
        $response->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_protected_routes()
    {
        $response = $this->getJson('/api/applications');
        $response->assertStatus(401);
    }

    public function test_kades_can_view_admin_dashboard_with_kpi_cards()
    {
        $kades = $this->createUserWithRole('kades');
        $this->actingAs($kades);

        $response = $this->get(route('admin.dashboard'));
        $response->assertStatus(200);
        $response->assertSee('Panel Kepala Desa');
        $response->assertSee('Menunggu Persetujuan');
        $response->assertSee('SKTM Diterbitkan');
        $response->assertSee('Grafik Penerbitan SKTM');
    }
}
