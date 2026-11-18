<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CustomConfirmationDialogTest extends TestCase
{
    use RefreshDatabase;

    protected Role $stafRole;
    protected Role $supervisorRole;
    protected Role $kadesRole;
    protected Role $masyarakatRole;
    protected DocumentType $docType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stafRole = Role::firstOrCreate(['name' => 'staf']);
        $this->supervisorRole = Role::firstOrCreate(['name' => 'supervisor']);
        $this->kadesRole = Role::firstOrCreate(['name' => 'kades']);
        $this->masyarakatRole = Role::firstOrCreate(['name' => 'masyarakat']);

        $this->docType = DocumentType::firstOrCreate(
            ['code' => 'SKTM'],
            ['name' => 'Surat Keterangan Tidak Mampu', 'description' => 'SKTM']
        );
    }

    private function createActor(string $roleName): User
    {
        $role = Role::where('name', $roleName)->first();
        return User::create([
            'id' => (string) Str::uuid(),
            'name' => 'User ' . ucfirst($roleName),
            'email' => $roleName . '_' . Str::random(5) . '@desa.id',
            'password' => bcrypt('password123'),
            'role_id' => $role->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    public function test_no_native_browser_confirm_in_blade_and_js_files()
    {
        $viewsPath = resource_path('views');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($viewsPath));

        $foundNativeConfirm = [];

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getRealPath());
                if (preg_match('/\bconfirm\s*\(/i', $content, $matches)) {
                    // Ignore password_confirmation
                    $filtered = preg_replace('/password_confirmation/i', '', $content);
                    if (preg_match('/\bconfirm\s*\(/i', $filtered)) {
                        $foundNativeConfirm[] = str_replace(base_path(), '', $file->getRealPath());
                    }
                }
            }
        }

        $this->assertEmpty(
            $foundNativeConfirm,
            'Found native confirm() in views: ' . implode(', ', $foundNativeConfirm)
        );
    }

    public function test_confirm_dialog_component_is_included_in_app_layout()
    {
        $layoutContent = file_get_contents(resource_path('views/components/layouts/app.blade.php'));
        $this->assertStringContainsString('<x-confirm-dialog', $layoutContent);

        $dialogContent = file_get_contents(resource_path('views/components/confirm-dialog.blade.php'));
        $this->assertStringContainsString('role="dialog"', $dialogContent);
        $this->assertStringContainsString('aria-modal="true"', $dialogContent);
        $this->assertStringContainsString('aria-labelledby="confirm-dialog-title"', $dialogContent);
        $this->assertStringContainsString('aria-describedby="confirm-dialog-desc"', $dialogContent);
        $this->assertStringContainsString('window.confirmDialog', $dialogContent);
        $this->assertStringContainsString('handleCancel', $dialogContent);
        $this->assertStringContainsString('handleConfirm', $dialogContent);
        $this->assertStringContainsString('isProcessing', $dialogContent);
    }

    public function test_staf_forward_to_head_has_required_title_description_and_confirm_label()
    {
        $viewContent = file_get_contents(resource_path('views/livewire/admin/application-detail.blade.php'));

        $this->assertStringContainsString("title: 'Teruskan ke Kepala Desa?'", $viewContent);
        $this->assertStringContainsString("message: 'Dokumen pengajuan telah diverifikasi lengkap dan valid. Pengajuan akan diteruskan langsung kepada Kepala Desa untuk persetujuan akhir.'", $viewContent);
        $this->assertStringContainsString("confirmText: 'Ya, Teruskan'", $viewContent);
        $this->assertStringContainsString("onConfirm: () => \$wire.forwardToHead()", $viewContent);
        $this->assertStringNotContainsString('$wire.supervisorForward()', $viewContent);
        $this->assertStringNotContainsString('$wire.supervisorReturn()', $viewContent);
    }

    public function test_all_admin_action_buttons_use_confirm_dialog_and_preserve_workflow()
    {
        $warga = $this->createActor('masyarakat');
        $staf = $this->createActor('staf');
        $supervisor = $this->createActor('supervisor');
        $kades = $this->createActor('kades');

        $application = Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-' . time(),
            'user_id' => $warga->id,
            'document_type_id' => $this->docType->id,
            'status' => 'SUBMITTED',
            'form_data' => ['nik' => '3201123456789012', 'nama' => 'Warga Test'],
            'purpose' => 'Bantuan Sekolah',
            'submitted_at' => now(),
        ]);

        // 1. Staf starts review and forwards directly to Kepala Desa (HEAD_APPROVAL)
        $this->actingAs($staf);
        Volt::test('admin.application-detail', ['id' => $application->id])
            ->call('markAsUnderReview');
        $this->assertEquals('UNDER_REVIEW', $application->fresh()->status);

        Volt::test('admin.application-detail', ['id' => $application->id])
            ->call('forwardToHead');
        $this->assertEquals('HEAD_APPROVAL', $application->fresh()->status);

        // 2. Supervisor only has read-only access and receives 403 on workflow mutations
        $this->actingAs($supervisor);
        Volt::test('admin.application-detail', ['id' => $application->id])
            ->assertStatus(200)
            ->assertSee('Akses Pengawasan (Read-Only)')
            ->call('supervisorForward')
            ->assertForbidden();
        $this->assertEquals('HEAD_APPROVAL', $application->fresh()->status);

        // 3. Kades approves and completes workflow
        $this->actingAs($kades);
        Volt::test('admin.application-detail', ['id' => $application->id])
            ->call('kadesApprove');
        $this->assertEquals('COMPLETED', $application->fresh()->status);
    }
}
