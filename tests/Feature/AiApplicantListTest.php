<?php

namespace Tests\Feature;

use App\Models\AiProviderCredential;
use App\Models\Application;
use App\Models\Conversation;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Services\AiService;
use App\Services\ApplicationStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pertanyaan daftar pemohon ("siapa saja?") harus dijawab dari database — termasuk sebagai
 * pertanyaan lanjutan — tanpa memanggil LLM eksternal, dengan hak akses role yang sama seperti statistik.
 *
 * Dataset (pemohon = nama pada formulir; Budi tanpa nama formulir → nama akun):
 *   Siti  SUPERVISOR_REVIEW | Budi  NEEDS_REVISION | Rina NEEDS_REVISION | Dodi HEAD_APPROVAL
 *   Eka   APPROVED          | Fajar COMPLETED      | Gita REJECTED       | Hana SUBMITTED (bulan lalu)
 */
class AiApplicantListTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,User> */
    private array $u = [];

    /** @var array<string,string> nama pemohon => status */
    private array $applicants = [];

    private DocumentType $doc;

    protected function setUp(): void
    {
        parent::setUp();

        AiProviderCredential::query()->updateOrCreate(
            ['provider' => 'groq'],
            ['api_key_encrypted' => 'gsk_test_key_not_real', 'key_cleared_explicitly' => false]
        );
        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00:00', 'Asia/Jakarta')->setTimezone('UTC'));

        $roles = [];
        foreach (['masyarakat', 'staf', 'supervisor', 'kades'] as $name) {
            $roles[$name] = Role::firstOrCreate(['name' => $name]);
        }
        foreach (['staf', 'supervisor', 'kades'] as $role) {
            $this->u[$role] = $this->makeUser($roles[$role], ucfirst($role) . ' Desa', "{$role}@uji.desa.id");
        }
        $this->doc = DocumentType::create(['code' => 'SKTM', 'name' => 'Surat Keterangan Tidak Mampu', 'description' => 'SKTM', 'is_active' => true]);

        $data = [
            'Siti Aminah' => ['SUPERVISOR_REVIEW', '2026-11-18 09:00:00', true],
            'Budi Santoso' => ['NEEDS_REVISION', '2026-11-17 09:00:00', false], // tanpa nama di formulir
            'Rina Wati' => ['NEEDS_REVISION', '2026-11-16 09:00:00', true],
            'Dodi Pratama' => ['HEAD_APPROVAL', '2026-11-15 09:00:00', true],
            'Eka Lestari' => ['APPROVED', '2026-11-14 09:00:00', true],
            'Fajar Nugroho' => ['COMPLETED', '2026-11-13 09:00:00', true],
            'Gita Permata' => ['REJECTED', '2026-11-12 09:00:00', true],
            'Hana Putri' => ['SUBMITTED', '2026-10-20 09:00:00', true],
        ];
        foreach ($data as $name => [$status, $local, $withFormName]) {
            $key = Str::slug($name);
            $this->u[$key] = $this->makeUser($roles['masyarakat'], $name, "{$key}@uji.desa.id");
            $this->makeApplication($this->u[$key], $status, $local, $withFormName ? $name : null);
            $this->applicants[$name] = $status;
        }

        // Kondisi nyata Laragon: penyedia AI menolak (HTTP 403)
        Http::fake(['*' => Http::response(['status' => 403, 'detail' => 'Authorization failed'], 403)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeUser(Role $role, string $name, string $email): User
    {
        return User::create([
            'name' => $name, 'email' => $email, 'password' => bcrypt('password'),
            'role_id' => $role->id, 'email_verified_at' => now(), 'is_active' => true,
        ]);
    }

    private function makeApplication(User $owner, string $status, string $local, ?string $formName): Application
    {
        $at = Carbon::parse($local, 'Asia/Jakarta')->setTimezone('UTC');
        $app = Application::create([
            'application_number' => 'SKTM-LIST-' . Str::upper(Str::random(6)),
            'user_id' => $owner->id,
            'document_type_id' => $this->doc->id,
            'status' => $status,
            'submitted_at' => $at,
            'form_data' => $formName ? ['name' => $formName, 'nik' => '3201000000000001'] : ['nik' => '3201000000000002'],
        ]);
        $app->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();

        return $app;
    }

    private function conv(?User $user): Conversation
    {
        return Conversation::create(['user_id' => $user?->id, 'session_id' => Str::random(12), 'title' => 'uji']);
    }

    private function ask(?User $user, string $question, ?Conversation $c = null): string
    {
        return app(AiService::class)->processConversation($c ?? $this->conv($user), $question, $user);
    }

    /** @return string[] nama pemohon yang tampil sebagai butir daftar */
    private function listedNames(string $reply): array
    {
        preg_match_all('/^• (.+?) — /mu', $reply, $m);

        return $m[1];
    }

    private function namesWithStatus(array $statuses): array
    {
        $names = array_keys(array_filter($this->applicants, fn ($s) => in_array($s, $statuses, true)));
        sort($names);

        return $names;
    }

    private function assertListEquals(array $expectedNames, string $reply, string $context): void
    {
        $listed = $this->listedNames($reply);
        sort($listed);
        $this->assertSame($expectedNames, $listed, "{$context}\n{$reply}");
        $this->assertStringNotContainsString('**', $reply, "{$context}: markdown mentah");
        $this->assertStringNotContainsString('kunci API', $reply, "{$context}: pesan provider bocor");
        $this->assertStringNotContainsString('3201', $reply, "{$context}: NIK tidak boleh tampil");
    }

    // ------------------------------------------------------------------ 1. jumlah pengajuan

    public function test_count_question_still_answers_from_database(): void
    {
        $stats = app(ApplicationStatisticsService::class);
        $cards = $stats->dashboardCards($this->u['supervisor']);

        $reply = $this->ask($this->u['supervisor'], 'Ada berapa pengajuan?');
        $this->assertStringContainsString("Total SKTM {$cards['total']} pengajuan", $reply);
        $this->assertStringContainsString("Tahap Staf {$cards['staf_queue']} pengajuan", $reply);
        $this->assertStringContainsString("Dikembalikan {$cards['needs_revision']} pengajuan", $reply);
        $this->assertStringContainsString("Menunggu Kades {$cards['head_approval']} pengajuan", $reply);
        $this->assertStringContainsString("Disetujui / Terbit {$cards['completed']} pengajuan", $reply);
        $this->assertStringContainsString("Ditolak {$cards['rejected']} pengajuan", $reply);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 2. lanjutan "siapa saja?"

    public function test_follow_up_siapa_saja_lists_names_status_and_date_from_previous_context(): void
    {
        $sup = $this->u['supervisor'];
        $c = $this->conv($sup);

        $this->ask($sup, 'Ada berapa pengajuan?', $c);
        $reply = $this->ask($sup, 'Siapa saja?', $c);

        $expectedDashboard = $this->namesWithStatus(app(ApplicationStatisticsService::class)->dashboardStatuses($sup));
        $this->assertListEquals($expectedDashboard, $reply, 'siapa saja setelah ringkasan');
        $this->assertStringStartsWith('Ada ' . count($expectedDashboard) . ' pengajuan:', $reply);
        $this->assertStringContainsString('• Siti Aminah — Arsip historis (Review Supervisor terdahulu) (18 Nov 2026)', $reply);
        $this->assertStringContainsString('• Budi Santoso — Dikembalikan (perlu revisi) (17 Nov 2026)', $reply, 'nama akun bila formulir tanpa nama');

        // Lanjutan setelah pertanyaan jumlah per kategori
        $this->ask($sup, 'Berapa pengajuan yang dikembalikan?', $c);
        $this->assertListEquals(['Budi Santoso', 'Rina Wati'], $this->ask($sup, 'siapa saja?', $c), 'siapa saja setelah dikembalikan');
        $this->assertListEquals(['Budi Santoso', 'Rina Wati'], $this->ask($sup, 'sebutkan namanya', $c), 'konteks tetap tersimpan');

        $this->ask($sup, 'Berapa pengajuan yang diteruskan ke Kepala Desa?', $c);
        $this->assertListEquals(['Dodi Pratama'], $this->ask($sup, 'siapa?', $c), 'siapa setelah diteruskan');

        Http::assertNothingSent();
    }

    public function test_registered_applications_follow_up_with_user_phrases(): void
    {
        $sup = $this->u['supervisor'];
        $c = $this->conv($sup);

        // 1. Pertanyaan awal jumlah pengajuan terdaftar
        $ans1 = $this->ask($sup, 'ada berapa pengajuan yang terdaftar', $c);
        $this->assertStringContainsString('Ringkasan monitoring SKTM Supervisor:', $ans1);
        $this->assertStringContainsString('Total SKTM 8 pengajuan', $ans1);
        $this->assertStringContainsString('Dikembalikan 2 pengajuan', $ans1);

        $expectedDashboard = $this->namesWithStatus(app(ApplicationStatisticsService::class)->dashboardStatuses($sup));

        // 2. Pertanyaan persis yang memicu bug: "siapa saja user nya?"
        $ans2 = $this->ask($sup, 'siapa saja user nya?', $c);
        $this->assertListEquals($expectedDashboard, $ans2, 'siapa saja user nya');
        $this->assertStringStartsWith('Ada ' . count($expectedDashboard) . ' pengajuan:', $ans2);

        // 3. Variasi frasa lain: user-nya, penggunanya, pemohonnya, usernya
        foreach ([
            'siapa saja user-nya?',
            'siapa saja penggunanya?',
            'siapa saja pemohonnya?',
            'siapa saja usernya?',
        ] as $variant) {
            $reply = $this->ask($sup, $variant, $c);
            $this->assertListEquals($expectedDashboard, $reply, $variant);
        }

        Http::assertNothingSent();
    }

    public function test_category_follow_up_with_user_phrases(): void
    {
        $sup = $this->u['supervisor'];
        $c = $this->conv($sup);

        // Kategori dikembalikan
        $this->ask($sup, 'Berapa pengajuan yang dikembalikan?', $c);
        $this->assertListEquals(['Budi Santoso', 'Rina Wati'], $this->ask($sup, 'siapa saja user nya?', $c), 'siapa saja user nya setelah dikembalikan');
        $this->assertListEquals(['Budi Santoso', 'Rina Wati'], $this->ask($sup, 'siapa saja user-nya?', $c), 'siapa saja user-nya setelah dikembalikan');
        $this->assertListEquals(['Budi Santoso', 'Rina Wati'], $this->ask($sup, 'siapa saja penggunanya?', $c), 'siapa saja penggunanya setelah dikembalikan');

        // Kategori menunggu review
        $this->ask($sup, 'Berapa pengajuan yang menunggu review?', $c);
        $this->assertListEquals(['Siti Aminah'], $this->ask($sup, 'siapa saja user nya?', $c), 'siapa saja user nya setelah review');

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 3–5. daftar per kategori

    public function test_category_list_questions(): void
    {
        $sup = $this->u['supervisor'];

        $cases = [
            'yang menunggu review siapa?' => ['SUPERVISOR_REVIEW'],
            'yang dikembalikan siapa saja?' => ['NEEDS_REVISION'],
            'siapa saja yang diteruskan?' => ['HEAD_APPROVAL'],
            'siapa saja yang disetujui?' => ['APPROVED', 'COMPLETED'],
            'yang selesai siapa saja?' => ['APPROVED', 'COMPLETED'],
            'siapa saja yang ditolak?' => ['REJECTED'],
            'sebutkan semua pemohon' => array_keys(array_flip(array_values($this->applicants))),
        ];

        foreach ($cases as $question => $statuses) {
            $reply = $this->ask($sup, $question);
            $this->assertListEquals($this->namesWithStatus($statuses), $reply, $question);
            $expectedCount = Application::whereIn('status', $statuses)->count();
            $this->assertMatchesRegularExpression('/^Ada ' . $expectedCount . ' pengajuan/u', $reply, $question);
        }

        // Daftar per status selalu sama dengan angka statistik untuk kategori yang sama
        $count = $this->ask($sup, 'Berapa pengajuan yang dikembalikan?');
        $this->assertStringContainsString('2 pengajuan', $count);

        Http::assertNothingSent();
    }

    public function test_list_supports_period_and_never_invents_names(): void
    {
        $sup = $this->u['supervisor'];

        // Hana (Oktober) tidak termasuk "bulan ini"
        $reply = $this->ask($sup, 'Siapa saja yang mengajukan bulan ini?');
        $this->assertNotContains('Hana Putri', $this->listedNames($reply));
        $this->assertCount(7, $this->listedNames($reply));
        $this->assertListEquals(['Hana Putri'], $this->ask($sup, 'siapa saja yang mengajukan bulan lalu?'), 'bulan lalu');

        // Kategori kosong → jawaban jujur tanpa nama karangan
        Application::where('status', 'SUPERVISOR_REVIEW')->delete();
        $empty = $this->ask($sup, 'yang menunggu review siapa?');
        $this->assertSame('Saat ini tidak ada pengajuan arsip historis review Supervisor.', $empty);
        $this->assertSame([], $this->listedNames($empty));

        Http::assertNothingSent();
    }

    public function test_long_lists_are_truncated_with_remaining_count(): void
    {
        $role = Role::where('name', 'masyarakat')->first();
        for ($i = 1; $i <= 12; $i++) {
            $w = $this->makeUser($role, "Pemohon Tambahan {$i}", "tambahan{$i}@uji.desa.id");
            $this->makeApplication($w, 'NEEDS_REVISION', '2026-11-19 0' . ($i % 9) . ':00:00', "Pemohon Tambahan {$i}");
        }

        $reply = $this->ask($this->u['supervisor'], 'yang dikembalikan siapa saja?');
        $this->assertStringStartsWith('Ada 14 pengajuan yang dikembalikan untuk revisi:', $reply);
        $this->assertCount(10, $this->listedNames($reply));
        $this->assertStringContainsString('…dan 4 lainnya', $reply);
    }

    public function test_applicant_account_follow_up_lists_accounts(): void
    {
        $kades = $this->u['kades'];
        $c = $this->conv($kades);

        $this->assertStringContainsString('8 akun warga', $this->ask($kades, 'Berapa akun yang melakukan pengajuan?', $c));
        $reply = $this->ask($kades, 'siapa saja?', $c);
        $this->assertStringStartsWith('Ada 8 akun warga yang sudah pernah mengajukan surat:', $reply);
        $this->assertCount(8, $this->listedNames($reply));
        $this->assertStringContainsString('• Siti Aminah — 1 pengajuan, terakhir 18 Nov 2026', $reply);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 6. isolasi konteks

    public function test_context_is_isolated_between_conversations_users_and_after_reset(): void
    {
        $sup = $this->u['supervisor'];
        $c1 = $this->conv($sup);
        $this->ask($sup, 'Berapa pengajuan yang dikembalikan?', $c1);

        // Percakapan lain milik user yang sama tidak mewarisi konteks
        $other = $this->ask($sup, 'siapa saja?', $this->conv($sup));
        $this->assertStringStartsWith('Daftar pemohon yang mana?', $other);
        $this->assertSame([], $this->listedNames($other));

        // User lain tidak mewarisi konteks
        $kades = $this->ask($this->u['kades'], 'siapa saja?', $this->conv($this->u['kades']));
        $this->assertSame([], $this->listedNames($kades));

        // Percakapan asal tetap memakai konteksnya
        $this->assertListEquals(['Budi Santoso', 'Rina Wati'], $this->ask($sup, 'siapa saja?', $c1), 'percakapan asal');

        // Pertanyaan non-statistik di antaranya menghapus konteks
        $this->ask($sup, 'Siapa kamu?', $c1);
        $this->assertSame([], $this->listedNames($this->ask($sup, 'siapa saja?', $c1)));

        // Reset percakapan lewat widget → konteks tidak terbawa
        $this->actingAs($sup);
        $widget = Livewire::test('chatbot-widget');
        $widget->set('userInput', 'Berapa pengajuan yang dikembalikan?')->call('sendMessage');
        Carbon::setTestNow(now()->addSeconds(5));
        $widget->set('userInput', 'siapa saja?')->call('sendMessage');
        $this->assertStringContainsString('Budi Santoso', collect($widget->get('messages'))->last()['content']);
        $widget->call('resetChat');
        Carbon::setTestNow(now()->addSeconds(5));
        $widget->set('userInput', 'siapa saja?')->call('sendMessage');
        $this->assertStringNotContainsString('Budi Santoso', collect($widget->get('messages'))->last()['content']);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 7. hak akses

    public function test_role_based_access_and_data_ownership(): void
    {
        // Warga: hanya pengajuan miliknya, tidak pernah nama warga lain
        $siti = $this->u['siti-aminah'];
        $own = $this->ask($siti, 'sebutkan semua pemohon');
        $this->assertListEquals(['Siti Aminah'], $own, 'warga hanya miliknya');
        $this->assertStringContainsString('pengajuan milik Anda', $own);
        $this->assertListEquals([], $this->ask($siti, 'yang dikembalikan siapa saja?'), 'warga tidak melihat revisi warga lain');
        $accounts = $this->ask($siti, 'siapa saja warga yang sudah mengajukan?');
        $this->assertStringContainsString('tidak dapat ditampilkan untuk akun warga', $accounts);
        $this->assertSame([], $this->listedNames($accounts));

        // Staf: rekap penolakan tetap data internal; daftar seluruh pemohon tanpa yang ditolak
        $this->assertStringContainsString('data internal', $this->ask($this->u['staf'], 'siapa saja yang ditolak?'));
        $stafAll = $this->listedNames($this->ask($this->u['staf'], 'sebutkan semua pemohon'));
        $this->assertNotContains('Gita Permata', $stafAll);
        $this->assertCount(7, $stafAll);
        $this->assertStringContainsString('hanya tersedia untuk Supervisor dan Kepala Desa', $this->ask($this->u['staf'], 'siapa yang belum pernah mengajukan?'));

        // Supervisor & Kepala Desa: boleh melihat yang ditolak
        foreach (['supervisor', 'kades'] as $role) {
            $this->assertListEquals(['Gita Permata'], $this->ask($this->u[$role], 'siapa saja yang ditolak?'), $role);
        }

        // Tamu: wajib login
        $guest = $this->ask(null, 'sebutkan semua pemohon');
        $this->assertStringContainsString('login', strtolower($guest));
        $this->assertSame([], $this->listedNames($guest));

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 8. Provider AI 403 / timeout

    public function test_database_list_questions_work_while_external_ai_is_down(): void
    {
        $sup = $this->u['supervisor'];

        foreach ([403, 401, 429, 500] as $status) {
            Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
            Http::fake(['*' => Http::response(['detail' => 'down'], $status)]);
            $c = $this->conv($sup);
            $this->ask($sup, 'Ada berapa pengajuan?', $c);
            $this->assertCount(8, $this->listedNames($this->ask($sup, 'Siapa saja?', $c)), "HTTP {$status}");
            $this->assertListEquals(['Siti Aminah'], $this->ask($sup, 'yang menunggu review siapa?'), "HTTP {$status}");
            Http::assertNothingSent();
        }

        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->assertListEquals(['Budi Santoso', 'Rina Wati'], $this->ask($sup, 'yang dikembalikan siapa saja?'), 'timeout');
        Http::assertNothingSent();
    }

    public function test_non_data_siapa_questions_are_not_hijacked_by_list_intent(): void
    {
        $sup = $this->u['supervisor'];

        $this->assertStringContainsString('Asisten Virtual', $this->ask($sup, 'Siapa kamu?'));
        $this->assertStringContainsString('Supervisor Desa', $this->ask($sup, 'Siapa saya?'));
        $this->assertStringContainsString('Kartu Keluarga', $this->ask($sup, 'Siapa yang bisa mengajukan SKTM?'));
        foreach (['Siapa kamu?', 'Siapa saya?', 'Siapa yang bisa mengajukan SKTM?', 'Siapa yang menyetujui SKTM?'] as $q) {
            $this->assertSame([], $this->listedNames($this->ask($sup, $q)), $q);
        }
    }
}
