<?php

namespace Tests\Feature;

use App\Models\AiProviderCredential;
use App\Models\Application;
use App\Models\Conversation;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class RoleAccountIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private Role $roleWarga;
    private Role $roleStaf;
    private Role $roleSupervisor;
    private Role $roleKades;

    private User $supervisor;
    private User $kades;
    private User $stafA;
    private User $stafB;

    /** @var list<User> */
    private array $wargaUsers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleWarga = Role::firstOrCreate(['name' => 'masyarakat']);
        $this->roleStaf = Role::firstOrCreate(['name' => 'staf']);
        $this->roleSupervisor = Role::firstOrCreate(['name' => 'supervisor']);
        $this->roleKades = Role::firstOrCreate(['name' => 'kades']);

        $this->supervisor = $this->makeUser($this->roleSupervisor, 'Supervisor Asep', 'spv.asep@gmail.com', '083811110001');
        $this->kades = $this->makeUser($this->roleKades, 'Kepala Desa Budi', 'kades.budi@gmail.com', '083811110002');
        $this->stafA = $this->makeUser($this->roleStaf, 'Staf Dewi', 'staf.dewi@gmail.com', '083811110003');
        $this->stafB = $this->makeUser($this->roleStaf, 'Staf Raka', 'staf.raka@gmail.com', '083811110004');

        for ($i = 1; $i <= 4; $i++) {
            $this->wargaUsers[] = $this->makeUser(
                $this->roleWarga,
                "Warga Uji {$i}",
                "warga{$i}@gmail.com",
                '0812000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)
            );
        }

        // Buat 1 pengajuan SKTM untuk membuktikan "Siapa user-nya?" setelah query role TIDAK tercampur daftar pemohon SKTM
        $doc = DocumentType::create([
            'code' => 'SKTM',
            'name' => 'Surat Keterangan Tidak Mampu',
            'description' => 'SKTM',
            'is_active' => true,
        ]);

        Application::create([
            'application_number' => 'SKTM-2026-ACC-1',
            'user_id' => $this->wargaUsers[0]->id,
            'document_type_id' => $doc->id,
            'status' => 'SUBMITTED',
            'submitted_at' => now(),
            'form_data' => ['name' => 'Pemohon Khusus SKTM', 'nik' => '3201112233445566'],
        ]);

        // Simulasikan provider AI API error 403 agar terbukti seluruh query database bekerja lokal 100%
        AiProviderCredential::query()->updateOrCreate(
            ['provider' => 'groq'],
            ['api_key_encrypted' => 'gsk_test_role_key', 'key_cleared_explicitly' => false]
        );
        Http::fake(['*' => Http::response(['status' => 403, 'detail' => 'Authorization failed'], 403)]);
    }

    private function makeUser(Role $role, string $name, string $email, string $phone): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => bcrypt('SecretPass123!'),
            'role_id' => $role->id,
            'phone_number' => $phone,
            'is_active' => true,
        ]);
    }

    private function ask(?User $user, string $message, ?Conversation $conversation = null): string
    {
        $conv = $conversation ?? Conversation::create([
            'user_id' => $user?->id,
            'session_id' => Str::random(16),
            'title' => 'Uji Role Intelligence',
        ]);

        return app(AiService::class)->processConversation($conv, $message, $user);
    }

    public function test_role_account_counts_and_available_roles_from_actual_database_without_external_ai(): void
    {
        foreach ([$this->supervisor, $this->kades] as $actor) {
            // 1. Jumlah akun warga
            $rWarga1 = $this->ask($actor, 'Ada berapa akun dengan role warga?');
            $this->assertStringContainsString('4 akun warga', $rWarga1);

            $rWarga2 = $this->ask($actor, 'Berapa jumlah akun masyarakat?');
            $this->assertStringContainsString('4 akun warga', $rWarga2);

            // 2. Jumlah akun staf
            $rStaf = $this->ask($actor, 'Ada berapa staf?');
            $this->assertStringContainsString('2 akun', $rStaf);
            $this->assertStringContainsString('Staf Desa', $rStaf);

            // 3. Jumlah akun Kades
            $rKades = $this->ask($actor, 'Ada berapa akun Kades?');
            $this->assertStringContainsString('1 akun', $rKades);
            $this->assertStringContainsString('Kepala Desa', $rKades);

            // 4. Jumlah akun Supervisor
            $rSpv = $this->ask($actor, 'Berapa akun Supervisor?');
            $this->assertStringContainsString('1 akun', $rSpv);
            $this->assertStringContainsString('Supervisor', $rSpv);

            // 5. Jumlah seluruh akun (1 spv + 1 kades + 2 staf + 4 warga = 8)
            $rAll = $this->ask($actor, 'Total seluruh akun ada berapa?');
            $this->assertStringContainsString('8 akun', $rAll);
            $this->assertStringContainsString('Warga (Masyarakat): 4 akun', $rAll);
            $this->assertStringContainsString('Staf Desa: 2 akun', $rAll);
            $this->assertStringContainsString('Kepala Desa: 1 akun', $rAll);
            $this->assertStringContainsString('Supervisor: 1 akun', $rAll);

            // 6. Role apa saja yang tersedia
            $rRoles = $this->ask($actor, 'Role apa saja yang tersedia?');
            $this->assertStringContainsString('4 jenis role', $rRoles);
            $this->assertStringContainsString('masyarakat', strtolower($rRoles));
            $this->assertStringContainsString('staf', strtolower($rRoles));
            $this->assertStringContainsString('supervisor', strtolower($rRoles));
            $this->assertStringContainsString('kades', strtolower($rRoles));
        }

        Http::assertNothingSent();
    }

    public function test_role_account_lists_return_actual_names_without_leaking_pii_or_secrets(): void
    {
        $sup = $this->supervisor;

        // Daftar staf
        $stafList = $this->ask($sup, 'Siapa nama staf yang terdaftar?');
        $this->assertStringContainsString('2 akun dengan role Staf Desa', $stafList);
        $this->assertStringContainsString('Staf Dewi', $stafList);
        $this->assertStringContainsString('Staf Raka', $stafList);
        $this->assertStringNotContainsString('staf.dewi@gmail.com', $stafList);
        $this->assertStringNotContainsString('083811110003', $stafList);
        $this->assertStringNotContainsString('SecretPass123!', $stafList);
        $this->assertStringNotContainsString('Pemohon Khusus SKTM', $stafList);

        // Daftar Kades
        $kadesList = $this->ask($sup, 'Tampilkan daftar akun Kades.');
        $this->assertStringContainsString('1 akun dengan role Kepala Desa', $kadesList);
        $this->assertStringContainsString('Kepala Desa Budi', $kadesList);
        $this->assertStringNotContainsString('kades.budi@gmail.com', $kadesList);

        // Daftar Warga
        $wargaList = $this->ask($sup, 'Siapa saja akun warga?');
        $this->assertStringContainsString('4 akun dengan role Warga (Masyarakat)', $wargaList);
        $this->assertStringContainsString('Warga Uji 1', $wargaList);
        $this->assertStringContainsString('Warga Uji 4', $wargaList);
        $this->assertStringNotContainsString('warga1@gmail.com', $wargaList);

        // Daftar Supervisor
        $spvList = $this->ask($sup, 'Siapa saja Supervisor?');
        $this->assertStringContainsString('1 akun dengan role Supervisor', $spvList);
        $this->assertStringContainsString('Supervisor Asep', $spvList);
        $this->assertStringNotContainsString('spv.asep@gmail.com', $spvList);

        Http::assertNothingSent();
    }

    public function test_exact_multi_turn_follow_ups_kalau_kades_and_siapa_user_nya_preserve_context(): void
    {
        $sup = $this->supervisor;
        $conv = Conversation::create([
            'user_id' => $sup->id,
            'session_id' => 'follow-up-role-test',
            'title' => 'Follow-up Role',
        ]);

        // Reproduksi persis percakapan gagal pada laporan bug
        $r1 = $this->ask($sup, 'Ada berapa akun dengan role warga?', $conv);
        $this->assertStringContainsString('4 akun warga', $r1);

        // Follow-up 1: "Kalau Kades?" -> HARUS dijawab lokal dari database (TIDAK ke API eksternal 403)
        $r2 = $this->ask($sup, 'Kalau Kades?', $conv);
        $this->assertStringContainsString('Kepala Desa', $r2);
        $this->assertStringContainsString('1 akun', $r2);
        $this->assertStringNotContainsString('403', $r2);
        $this->assertStringNotContainsString('kunci API', $r2);

        // Follow-up "Siapa saja?" setelah Kades -> tampilkan daftar akun Kades
        $r2List = $this->ask($sup, 'Siapa saja?', $conv);
        $this->assertStringContainsString('Kepala Desa Budi', $r2List);
        $this->assertStringNotContainsString('Pemohon Khusus SKTM', $r2List);

        // Follow-up ganti role lagi: "Kalau staf?" -> kembali ke hitungan atau daftar staf sesuai aksi sebelumnya
        $r3 = $this->ask($sup, 'Ada berapa akun dengan role staff?', $conv);
        $this->assertStringContainsString('Staf Desa', $r3);
        $this->assertStringContainsString('2 akun', $r3);

        // Follow-up 2: "Siapa user nya?" -> HARUS menampilkan nama akun staf, BUKAN pemohon SKTM!
        $r4 = $this->ask($sup, 'Siapa user nya?', $conv);
        $this->assertStringContainsString('Staf Dewi', $r4);
        $this->assertStringContainsString('Staf Raka', $r4);
        $this->assertStringNotContainsString('Pemohon Khusus SKTM', $r4);
        $this->assertStringNotContainsString('Daftar pemohon yang mana', $r4);

        // Follow-up "Tampilkan semuanya" -> tetap dalam konteks staf (menampilkan akun staf)
        $r5 = $this->ask($sup, 'Tampilkan semuanya', $conv);
        $this->assertStringContainsString('Staf Dewi', $r5);
        $this->assertStringContainsString('Staf Raka', $r5);

        // Variasi Livewire widget dengan chat window terbuka
        $this->actingAs($sup);
        $widget = Livewire::test('chatbot-widget')
            ->call('openChat')
            ->set('userInput', 'Berapa akun Supervisor?')
            ->call('sendMessage')
            ->set('userInput', 'Kalau staf?')
            ->call('sendMessage')
            ->set('userInput', 'Siapa user-nya?')
            ->call('sendMessage')
            ->assertSee('Staf Dewi')
            ->assertSee('Staf Raka')
            ->assertDontSee('Pemohon Khusus SKTM');
        $lastWidgetMsg = collect($widget->get('messages'))->last()['content'] ?? '';
        $this->assertStringContainsString('Staf Dewi', $lastWidgetMsg);
        $this->assertStringContainsString('Staf Raka', $lastWidgetMsg);

        Http::assertNothingSent();
    }

    public function test_role_access_control_prevents_masyarakat_staf_and_guest_from_viewing_account_counts_or_lists(): void
    {
        $warga = $this->wargaUsers[0];

        foreach ([$warga, $this->stafA, null] as $unauthorized) {
            $conv = Conversation::create([
                'user_id' => $unauthorized?->id,
                'session_id' => 'unauth-' . Str::random(8),
                'title' => 'Unauth Test',
            ]);

            $r1 = $this->ask($unauthorized, 'Ada berapa akun dengan role warga?', $conv);
            $r2 = $this->ask($unauthorized, 'Kalau Kades?', $conv);
            $r3 = $this->ask($unauthorized, 'Siapa nama staf yang terdaftar?', $conv);
            $r4 = $this->ask($unauthorized, 'Tampilkan daftar akun Kades.', $conv);
            $r5 = $this->ask($unauthorized, 'Ada berapa akun dengan role staff?', $conv);
            $r6 = $this->ask($unauthorized, 'Siapa user nya?', $conv);

            foreach ([$r1, $r2, $r3, $r4, $r5, $r6] as $reply) {
                $this->assertStringNotContainsString('Staf Dewi', $reply);
                $this->assertStringNotContainsString('Kepala Desa Budi', $reply);
                $this->assertStringNotContainsString('Warga Uji 2', $reply);
                $this->assertStringNotContainsString('Supervisor Asep', $reply);
                $this->assertStringNotContainsString('4 akun', $reply);
                $this->assertStringNotContainsString('2 akun', $reply);
                $this->assertStringNotContainsString('kunci API', $reply);
            }
        }

        Http::assertNothingSent();
    }
}
