<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\DocumentType;
use App\Models\Application;
use App\Models\ApplicationDocument;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ApplicationDetailFixTest extends TestCase
{
    use RefreshDatabase;
    /** @test */
    public function application_detail_renders_without_type_error_when_form_data_is_array()
    {
        $roleStaf = Role::firstOrCreate(['name' => 'staf']);
        $roleWarga = Role::firstOrCreate(['name' => 'masyarakat']);

        $staff = User::factory()->create([
            'role_id' => $roleStaf->id,
            'is_active' => true,
        ]);

        $warga = User::factory()->create([
            'role_id' => $roleWarga->id,
            'is_active' => true,
        ]);

        $docType = DocumentType::firstOrCreate(
            ['code' => 'SKTM'],
            ['name' => 'Surat Keterangan Tidak Mampu']
        );

        // Application with form_data as array (Eloquent cast)
        $application = Application::create([
            'application_number' => 'SKTM-20261016-TEST',
            'user_id' => $warga->id,
            'document_type_id' => $docType->id,
            'status' => 'SUBMITTED',
            'purpose' => 'Keringanan Biaya Rumah Sakit',
            'form_data' => [
                'nik' => '1234567891012341',
                'no_kk' => '1234567891012341',
                'name' => 'Agus Test',
                'address' => 'Jalan Jendral Sudirman',
                'rt' => '001',
                'rw' => '002',
                'desa' => 'Desa Ciperna',
                'kecamatan' => 'Talun',
                'kabupaten' => 'Kabupaten Cirebon',
                'occupation' => 'Wiraswasta',
                'penghasilan' => '1000000',
            ],
            'applicant_notes' => 'Catatan pemohon untuk pengujian.',
            'submitted_at' => now(),
        ]);

        $this->actingAs($staff);

        // Test Livewire component directly
        Livewire::test('admin.application-detail', ['id' => $application->id])
            ->assertStatus(200)
            ->assertSee('Agus Test')
            ->assertSee('1234567891012341')
            ->assertSee('Keringanan Biaya Rumah Sakit')
            ->assertSee('Mulai Pemeriksaan Dokumen');

        // Test full web route
        $response = $this->get('/admin/pengajuan/' . $application->id);
        $response->assertStatus(200);
        $response->assertSee('Agus Test');
    }

    /** @test */
    public function application_detail_renders_safely_when_form_data_is_json_string()
    {
        $roleStaf = Role::firstOrCreate(['name' => 'staf']);
        $roleWarga = Role::firstOrCreate(['name' => 'masyarakat']);

        $staff = User::factory()->create([
            'role_id' => $roleStaf->id,
            'is_active' => true,
        ]);

        $warga = User::factory()->create([
            'role_id' => $roleWarga->id,
            'is_active' => true,
        ]);

        $docType = DocumentType::firstOrCreate(
            ['code' => 'SKTM'],
            ['name' => 'Surat Keterangan Tidak Mampu']
        );

        // Application created with raw JSON string in DB
        $appId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('applications')->insert([
            'id' => $appId,
            'application_number' => 'SKTM-20261016-RAW',
            'user_id' => $warga->id,
            'document_type_id' => $docType->id,
            'status' => 'SUBMITTED',
            'purpose' => 'Bantuan Beasiswa',
            'form_data' => json_encode([
                'nik' => '9876543210123456',
                'name' => 'Budi Santoso',
                'address' => 'Dusun Manis',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($staff);

        Livewire::test('admin.application-detail', ['id' => $appId])
            ->assertStatus(200)
            ->assertSee('Budi Santoso')
            ->assertSee('9876543210123456');
    }

    /** @test */
    public function kades_approve_generates_pdf_and_creates_completed_history()
    {
        $roleKades = Role::firstOrCreate(['name' => 'kades']);
        $roleWarga = Role::firstOrCreate(['name' => 'masyarakat']);

        $kades = User::factory()->create([
            'role_id' => $roleKades->id,
            'is_active' => true,
        ]);

        $warga = User::factory()->create([
            'role_id' => $roleWarga->id,
            'is_active' => true,
        ]);

        $docType = DocumentType::firstOrCreate(
            ['code' => 'SKTM'],
            ['name' => 'Surat Keterangan Tidak Mampu']
        );

        $app = Application::create([
            'application_number' => 'SKTM-20261016-APPROVE',
            'user_id' => $warga->id,
            'document_type_id' => $docType->id,
            'status' => 'HEAD_APPROVAL',
            'purpose' => 'Bantuan Kuliah',
            'form_data' => [
                'name' => 'Agus Mahasiswa',
                'nik' => '1234567891012341',
            ],
            'submitted_at' => now(),
        ]);

        $this->actingAs($kades);

        Livewire::test('admin.application-detail', ['id' => $app->id])
            ->set('generalNote', 'Disetujui. Surat resmi diterbitkan.')
            ->call('kadesApprove');

        $freshApp = $app->fresh();
        $this->assertEquals('COMPLETED', $freshApp->status);

        // Verify history transitions
        $this->assertTrue($freshApp->histories()->where('status_to', 'APPROVED')->exists());
        $completedHistory = $freshApp->histories()->where('status_to', 'COMPLETED')->first();
        $this->assertNotNull($completedHistory);
        $this->assertEquals('APPROVED', $completedHistory->status_from);
        $this->assertStringContainsString('diterbitkan', $completedHistory->description);

        // Verify citizen dashboard counts it in $approved
        $this->actingAs($warga);
        $dashResponse = $this->get('/dashboard');
        $dashResponse->assertStatus(200);
        $dashResponse->assertViewHas('approved', 1);
    }

    /** @test */
    public function application_controller_api_includes_under_review_and_completed()
    {
        $roleStaf = Role::firstOrCreate(['name' => 'staf']);
        $roleWarga = Role::firstOrCreate(['name' => 'masyarakat']);

        $staff = User::factory()->create([
            'role_id' => $roleStaf->id,
            'is_active' => true,
        ]);

        $warga = User::factory()->create([
            'role_id' => $roleWarga->id,
            'is_active' => true,
        ]);

        $docType = DocumentType::firstOrCreate(
            ['code' => 'SKTM'],
            ['name' => 'Surat Keterangan Tidak Mampu']
        );

        $appUnderReview = Application::create([
            'application_number' => 'SKTM-UNDER-REVIEW',
            'user_id' => $warga->id,
            'document_type_id' => $docType->id,
            'status' => 'UNDER_REVIEW',
            'purpose' => 'Pengujian',
            'form_data' => ['name' => 'Test'],
            'submitted_at' => now(),
        ]);

        $appCompleted = Application::create([
            'application_number' => 'SKTM-COMPLETED',
            'user_id' => $warga->id,
            'document_type_id' => $docType->id,
            'status' => 'COMPLETED',
            'purpose' => 'Pengujian Selesai',
            'form_data' => ['name' => 'Test'],
            'submitted_at' => now(),
        ]);

        $this->actingAs($staff, 'sanctum');

        $response = $this->getJson('/api/applications');
        $response->assertStatus(200);
        $numbers = collect($response->json())->pluck('application_number')->toArray();
        $this->assertContains('SKTM-UNDER-REVIEW', $numbers);
        $this->assertContains('SKTM-COMPLETED', $numbers);
    }
}
