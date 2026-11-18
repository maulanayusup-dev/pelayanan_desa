<?php

namespace Tests\Feature;

use App\Livewire\AdminDashboard;
use App\Models\AiProviderCredential;
use App\Models\AiSetting;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationHistory;
use App\Models\Conversation;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Services\AiConfigService;
use App\Services\AiService;
use App\Services\ApplicationStatisticsService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sinkronisasi statistik: ApplicationStatisticsService adalah satu sumber angka untuk dashboard dan Asisten AI.
 *
 * Dataset (brief + 1 SUPERVISOR_REVIEW agar kartu Supervisor "Menunggu Review" ikut teruji):
 * 2 NEEDS_REVISION, 1 SUBMITTED, 1 UNDER_REVIEW, 1 SUPERVISOR_REVIEW, 2 HEAD_APPROVAL, 2 APPROVED, 1 COMPLETED, 1 REJECTED = 11.
 */
class ApplicationStatisticsSyncTest extends TestCase
{
    use RefreshDatabase;

    private const DATASET = [
        'NEEDS_REVISION' => 2, 'SUBMITTED' => 1, 'UNDER_REVIEW' => 1, 'SUPERVISOR_REVIEW' => 1,
        'HEAD_APPROVAL' => 2, 'APPROVED' => 2, 'COMPLETED' => 1, 'REJECTED' => 1,
    ];

    /** Sentinel jawaban LLM: bila muncul di jawaban statistik berarti angka dikarang LLM. */
    private const LLM_SENTINEL = 'Menurut perkiraan saya ada 99 pengajuan.';

    protected array $users = [];
    protected User $wargaA;
    protected User $wargaB;
    protected DocumentType $docType;
    protected ApplicationStatisticsService $stats;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => self::LLM_SENTINEL]]]], 200)]);
        AiConfigService::forgetCache();
        AiSetting::query()->updateOrCreate(
            ['singleton_key' => AiSetting::SINGLETON_KEY],
            [
                'provider' => 'groq',
                'label' => 'Groq',
                'base_url' => 'https://api.groq.com/openai/v1',
                'model' => 'llama-3.3-70b-versatile',
                'temperature' => 0.2,
                'timeout' => 15,
                'max_tokens' => 300,
                'is_enabled' => true,
            ]
        );
        AiProviderCredential::query()->updateOrCreate(
            ['provider' => 'groq'],
            ['api_key_encrypted' => 'gsk_test_sync_key', 'key_cleared_explicitly' => false]
        );
        AiConfigService::forgetCache();

        $roles = [];
        foreach (['masyarakat', 'staf', 'supervisor', 'kades', 'kepala_desa'] as $name) {
            $roles[$name] = Role::firstOrCreate(['name' => $name]);
        }

        $make = fn (string $role, string $name) => User::create([
            'name' => $name,
            'email' => Str::slug($name) . '@desa.id',
            'password' => bcrypt('password'),
            'role_id' => $roles[$role]->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->users = [
            'staf' => $make('staf', 'Staf Doni'),
            'supervisor' => $make('supervisor', 'Supervisor Rahmat'),
            'kades' => $make('kades', 'Kades Subagyo'),
            'kepala_desa' => $make('kepala_desa', 'Kepala Desa Lama'),
        ];
        $this->wargaA = $make('masyarakat', 'Warga Siti');
        $this->wargaB = $make('masyarakat', 'Warga Budi');

        $this->docType = DocumentType::firstOrCreate(['code' => 'SKTM'], ['name' => 'Surat Keterangan Tidak Mampu']);

        // Warga A memiliki: 1 NEEDS_REVISION, 1 HEAD_APPROVAL, 1 APPROVED, 1 REJECTED. Sisanya milik Warga B.
        $ownedByA = ['NEEDS_REVISION' => 1, 'HEAD_APPROVAL' => 1, 'APPROVED' => 1, 'REJECTED' => 1];
        foreach (self::DATASET as $status => $n) {
            for ($i = 0; $i < $n; $i++) {
                $owner = ($ownedByA[$status] ?? 0) > $i ? $this->wargaA : $this->wargaB;
                $this->application($owner, $status);
            }
        }

        $this->stats = app(ApplicationStatisticsService::class);
    }

    private function application(User $owner, string $status): Application
    {
        $app = Application::create([
            'application_number' => 'SKTM-STAT-' . strtoupper(Str::random(6)),
            'user_id' => $owner->id,
            'document_type_id' => $this->docType->id,
            'status' => $status,
            'submitted_at' => now(),
        ]);

        // Relasi berganda per pengajuan (3 dokumen, 2 riwayat) — tidak boleh menggandakan hitungan
        foreach (['KTP', 'KK', 'Pendukung'] as $doc) {
            ApplicationDocument::create(['application_id' => $app->id, 'document_name' => $doc, 'file_path' => "x/{$doc}.jpg", 'mime_type' => 'image/jpeg', 'status' => 'PENDING']);
        }
        foreach (['DRAFT', 'SUBMITTED'] as $from) {
            ApplicationHistory::create(['application_id' => $app->id, 'actor_id' => $owner->id, 'status_from' => $from, 'status_to' => $status, 'description' => 'uji']);
        }

        return $app;
    }

    private function ask(User $user, string $question, ?Conversation $conversation = null): string
    {
        $conversation ??= Conversation::create(['user_id' => $user->id, 'session_id' => Str::random(10), 'title' => 'uji']);

        return app(AiService::class)->processConversation($conversation, $question, $user);
    }

    private function assertCount_(int $expected, string $reply, string $context): void
    {
        $this->assertMatchesRegularExpression('/(?<![\d.])' . $expected . '\s+(pengajuan|berkas)/u', $reply, "{$context}\nJawaban: {$reply}");
        $this->assertStringNotContainsString('99', $reply, "{$context}: angka karangan LLM muncul");
    }

    private function assertLlmNotCalled(): void
    {
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'chat/completions'));
    }

    // ------------------------------------------------------------------ 1 & 4

    public function test_each_status_is_counted_exactly_once_despite_related_rows(): void
    {
        $s = $this->stats->summary($this->users['kades']);

        foreach (self::DATASET as $status => $n) {
            $this->assertSame($n, $s['by_status'][$status], "Status {$status}");
        }
        $this->assertSame(11, $s['total']);
        $this->assertSame(Application::count(), $s['total']);
        $this->assertSame(33, ApplicationDocument::count(), 'Dataset memang punya relasi berganda');

        $this->assertSame(2, $s['categories']['diteruskan']);
        $this->assertSame(3, $s['categories']['disetujui_selesai']);
        $this->assertSame(5, $s['categories']['diteruskan_disetujui']);
        $this->assertSame(2, $s['categories']['dikembalikan']);
        $this->assertSame(1, $s['categories']['ditolak']);
    }

    // ------------------------------------------------------------------ 3. dashboard == service

    public function test_dashboard_cards_equal_statistics_service_for_every_officer_role(): void
    {
        $expected = [
            'staf' => ['baru' => 1, 'diperiksa' => 1, 'perbaikan' => 2, 'selesai' => 3],
            'supervisor' => ['total' => 11, 'staf_queue' => 2, 'needs_revision' => 2, 'head_approval' => 2, 'completed' => 3, 'rejected' => 1],
            'kades' => ['menunggu' => 2, 'diterbitkan' => 3, 'ditolak' => 1, 'total' => 11],
            'kepala_desa' => ['menunggu' => 2, 'diterbitkan' => 3, 'ditolak' => 1, 'total' => 11],
        ];

        foreach ($expected as $role => $cards) {
            $user = $this->users[$role];
            $this->actingAs($user);

            $dashboard = Livewire::test(AdminDashboard::class)->viewData('stats');

            $this->assertSame($cards, $dashboard, "Dashboard {$role}");
            $this->assertSame($this->stats->dashboardCards($user), $dashboard, "Dashboard {$role} == service");
        }
    }

    // ------------------------------------------------------------------ 2. AI == service

    public function test_ai_answers_equal_statistics_service_for_every_officer_role(): void
    {
        foreach (['staf', 'supervisor', 'kades', 'kepala_desa'] as $role) {
            $user = $this->users[$role];
            $c = $this->stats->summary($user)['categories'];

            $this->assertCount_($c['dikembalikan'], $this->ask($user, 'Berapa pengajuan yang dikembalikan?'), "{$role} dikembalikan");
            $this->assertCount_($c['diteruskan'], $this->ask($user, 'Berapa pengajuan yang diteruskan?'), "{$role} diteruskan (HEAD_APPROVAL)");
            $this->assertCount_($c['disetujui_selesai'], $this->ask($user, 'Berapa pengajuan yang disetujui/selesai?'), "{$role} disetujui/selesai");
            $this->assertCount_($c['disetujui_selesai'], $this->ask($user, 'Berapa pengajuan yang selesai?'), "{$role} selesai");
            $this->assertCount_($c['diteruskan_disetujui'], $this->ask($user, 'Berapa pengajuan yang diteruskan/disetujui?'), "{$role} diteruskan/disetujui");
            $this->assertCount_($c['diteruskan_disetujui'], $this->ask($user, 'Berapa pengajuan yang diteruskan atau disetujui?'), "{$role} diteruskan atau disetujui");

            // Ringkasan AI memuat setiap nilai kartu dashboard role tersebut
            $summary = $this->ask($user, 'Ringkasan pengajuan saat ini');
            foreach ($this->stats->dashboardCards($user) as $key => $value) {
                // Jawaban kini teks biasa tanpa penanda markdown (**), karena widget chat menampilkan teks mentah
                $this->assertMatchesRegularExpression('/(?<![\d.])' . $value . '\s+(pengajuan|berkas)/u', $summary, "{$role} ringkasan kartu {$key}");
            }
        }

        // Nilai absolut sesuai brief
        $this->assertCount_(5, $this->ask($this->users['supervisor'], 'Berapa pengajuan yang diteruskan/disetujui?'), 'kartu Diteruskan/Disetujui');
        $this->assertCount_(2, $this->ask($this->users['staf'], 'Berapa pengajuan yang diteruskan?'), 'staf diteruskan');

        $this->assertLlmNotCalled();
    }

    // ------------------------------------------------------------------ follow-up & unrecognised

    public function test_follow_up_questions_read_the_database_not_chat_history(): void
    {
        $supervisor = $this->users['supervisor'];
        $conversation = Conversation::create(['user_id' => $supervisor->id, 'session_id' => 'follow-up', 'title' => 'uji']);

        $this->ask($supervisor, 'Berapa pengajuan yang dikembalikan?', $conversation);
        $this->assertCount_(3, $this->ask($supervisor, 'kalau yang sudah selesai berapa?', $conversation), 'lanjutan selesai');
        $this->assertCount_(1, $this->ask($supervisor, 'kalau yang ditolak?', $conversation), 'lanjutan ditolak');

        // Data berubah di database: 1 HEAD_APPROVAL disetujui. Pertanyaan lanjutan yang sama harus membaca angka baru.
        Application::where('status', 'HEAD_APPROVAL')->first()->update(['status' => 'APPROVED']);

        $this->assertCount_(4, $this->ask($supervisor, 'kalau yang sudah selesai berapa?', $conversation), 'lanjutan setelah data berubah');
        $this->assertCount_(1, $this->ask($supervisor, 'terus yang diteruskan berapa?', $conversation), 'lanjutan diteruskan');

        $this->assertLlmNotCalled();
    }

    public function test_unrecognised_statistics_questions_are_answered_from_database_never_by_llm(): void
    {
        $kades = $this->users['kades'];

        $questions = [
            'Ada berapa pengajuan sekarang?' => null,                  // ringkasan kartu role
            'Total semua pengajuan ada berapa?' => 11,
            'jumlah seluruh permohonan berapa ya' => 11,
            'Berapa banyak berkas yang menunggu review supervisor?' => 1,
            'yang ditolak ada berapa?' => 1,
            'berkas yang lagi diperiksa jumlahnya berapa' => 2,       // SUBMITTED + UNDER_REVIEW (menunggu pemeriksaan staf)
        ];

        foreach ($questions as $question => $expected) {
            $reply = $this->ask($kades, $question);
            $this->assertStringNotContainsString('99', $reply, "LLM menjawab: {$question}");
            if ($expected !== null) {
                $this->assertCount_($expected, $reply, $question);
            }
        }

        $this->assertLlmNotCalled();
    }

    // ------------------------------------------------------------------ 5. role scope

    public function test_citizen_sees_only_own_statistics_and_no_officer_dashboard(): void
    {
        $own = $this->stats->summary($this->wargaA);
        $this->assertSame('own', $own['scope']);
        $this->assertSame(4, $own['total']);
        $this->assertSame(Application::where('user_id', $this->wargaA->id)->count(), $own['total']);
        $this->assertNull($this->stats->dashboardCards($this->wargaA));

        $this->assertCount_(1, $this->ask($this->wargaA, 'Berapa pengajuan yang dikembalikan?'), 'warga dikembalikan');
        $this->assertCount_(1, $this->ask($this->wargaA, 'Berapa pengajuan yang diteruskan?'), 'warga diteruskan');
        $this->assertCount_(1, $this->ask($this->wargaA, 'Berapa pengajuan yang disetujui/selesai?'), 'warga disetujui');
        $this->assertCount_(2, $this->ask($this->wargaA, 'Berapa pengajuan yang diteruskan/disetujui?'), 'warga diteruskan/disetujui');

        $total = $this->ask($this->wargaA, 'Total semua pengajuan ada berapa?');
        $this->assertStringContainsString('total 4 pengajuan', $total);
        $this->assertStringNotContainsString('11', $total, 'Total desa tidak boleh bocor ke warga');

        $rejected = $this->ask($this->wargaA, 'Berapa pengajuan yang ditolak?');
        $this->assertStringContainsString('milik Anda', $rejected);
        $this->assertStringContainsString('1 pengajuan milik Anda yang ditolak', $rejected);

        // Warga tidak dapat membuka dashboard petugas
        $this->actingAs($this->wargaA);
        Livewire::test(AdminDashboard::class)->assertStatus(403);

        $this->assertLlmNotCalled();
    }

    public function test_guest_is_refused_and_staff_restriction_on_rejection_recap_is_kept(): void
    {
        $guestConversation = Conversation::create(['user_id' => null, 'session_id' => 'guest', 'title' => 'tamu']);
        $guest = app(AiService::class)->processConversation($guestConversation, 'Total semua pengajuan ada berapa?', null);
        $this->assertStringContainsString('login', strtolower($guest));
        $this->assertDoesNotMatchRegularExpression('/\d+\s+pengajuan/', $guest);

        $staf = $this->users['staf'];
        $conversation = Conversation::create(['user_id' => $staf->id, 'session_id' => 'staf', 'title' => 'uji']);

        $this->assertStringContainsString('data internal', $this->ask($staf, 'Berapa pengajuan yang ditolak?', $conversation));
        $this->assertStringContainsString('data internal', $this->ask($staf, 'kalau yang ditolak?', $conversation));

        // Total untuk staf tidak membuka jumlah penolakan lewat selisih: 11 - 1 ditolak = 10
        $total = $this->ask($staf, 'Total semua pengajuan ada berapa?', $conversation);
        $this->assertStringContainsString('10 pengajuan', $total);
        $this->assertStringNotContainsString('Ditolak', $total);

        $this->assertLlmNotCalled();
    }

    // ------------------------------------------------------------------ 6 & 7. live data, no stale cache

    public function test_status_change_is_reflected_immediately_in_service_dashboard_and_ai(): void
    {
        $staf = $this->users['staf'];
        $this->actingAs($staf);

        $this->assertSame(3, Livewire::test(AdminDashboard::class)->viewData('stats')['selesai']);
        $this->assertCount_(3, $this->ask($staf, 'Berapa pengajuan yang selesai?'), 'sebelum');

        Application::where('status', 'UNDER_REVIEW')->first()->update(['status' => 'APPROVED']);
        Application::where('status', 'SUBMITTED')->first()->update(['status' => 'COMPLETED']);

        $this->assertSame(5, $this->stats->count($staf, 'disetujui_selesai'));
        $this->assertSame(5, Livewire::test(AdminDashboard::class)->viewData('stats')['selesai']);
        $this->assertCount_(5, $this->ask($staf, 'Berapa pengajuan yang selesai?'), 'sesudah');
        $this->assertSame(0, Livewire::test(AdminDashboard::class)->viewData('stats')['baru']);
    }

    // ------------------------------------------------------------------ proof of single source

    public function test_dashboard_and_ai_both_read_from_application_statistics_service(): void
    {
        // Ganti service dengan versi tiruan yang mengembalikan angka unik. Jika dashboard & AI menghitung sendiri,
        // mereka tidak akan menampilkan angka tiruan ini.
        $this->app->instance(ApplicationStatisticsService::class, new class extends ApplicationStatisticsService {
            public function countsByStatus(User $user, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
            {
                return array_merge(array_fill_keys(self::STATUSES, 0), [
                    'SUBMITTED' => 41, 'UNDER_REVIEW' => 42, 'NEEDS_REVISION' => 43, 'SUPERVISOR_REVIEW' => 44,
                    'HEAD_APPROVAL' => 45, 'APPROVED' => 46, 'COMPLETED' => 47, 'REJECTED' => 48,
                ]);
            }
        });

        $supervisor = $this->users['supervisor'];
        $this->actingAs($supervisor);

        $this->assertSame([
            'total' => 41 + 42 + 43 + 44 + 45 + 46 + 47 + 48,
            'staf_queue' => 41 + 42,
            'needs_revision' => 43,
            'head_approval' => 45,
            'completed' => 46 + 47,
            'rejected' => 48,
        ], Livewire::test(AdminDashboard::class)->viewData('stats'));

        $this->assertCount_(41 + 42, $this->ask($supervisor, 'Berapa pengajuan menunggu pemeriksaan?'), 'AI tahap staf dari service');
        $this->assertCount_(43, $this->ask($supervisor, 'Berapa pengajuan yang dikembalikan?'), 'AI dikembalikan dari service');
        $this->assertCount_(45, $this->ask($supervisor, 'Berapa yang menunggu Kepala Desa?'), 'AI menunggu kades dari service');
        $this->assertCount_(46 + 47, $this->ask($supervisor, 'Berapa yang sudah disetujui?'), 'AI disetujui dari service');
    }

    public function test_no_statistics_count_query_remains_outside_the_service(): void
    {
        foreach (['app/Livewire/AdminDashboard.php', 'app/Services/VillageDataIntelligenceService.php'] as $file) {
            $source = file_get_contents(base_path($file));
            $this->assertDoesNotMatchRegularExpression('/Application::(where|whereIn)\(\'status\'[^;]*->count\(\)/s', $source, "{$file} masih menghitung status sendiri");
            $this->assertStringNotContainsString('Application::count()', $source, $file);
            $this->assertStringContainsString('ApplicationStatisticsService', $source, $file);
        }
    }

    public function test_supervisor_stage1_sktm_questions_match_active_dashboard_without_historical_noise(): void
    {
        // Ubah 1 SUPERVISOR_REVIEW menjadi COMPLETED dan 1 REJECTED menjadi COMPLETED agar sesuai skenario 11/2/2/2/5/0
        Application::where('status', 'SUPERVISOR_REVIEW')->first()->update(['status' => 'COMPLETED']);
        Application::where('status', 'REJECTED')->first()->update(['status' => 'COMPLETED']);

        $sup = $this->users['supervisor'];
        $this->actingAs($sup);
        $overview = Livewire::test(AdminDashboard::class)->viewData('sktmOverview');
        $this->assertSame(11, $overview['total']);
        $this->assertSame(2, $overview['staf_queue']);
        $this->assertSame(2, $overview['needs_revision']);
        $this->assertSame(2, $overview['head_approval']);
        $this->assertSame(5, $overview['completed']);
        $this->assertSame(0, $overview['rejected']);
        $this->assertSame(0, $overview['historical_supervisor_review']);

        $qSktm = $this->ask($sup, 'Ada berapa pengajuan SKTM?');
        $this->assertStringContainsString('Total SKTM 11 pengajuan', $qSktm);
        $this->assertStringContainsString('Tahap Staf 2 pengajuan', $qSktm);
        $this->assertStringContainsString('Dikembalikan 2 pengajuan', $qSktm);
        $this->assertStringContainsString('Menunggu Kades 2 pengajuan', $qSktm);
        $this->assertStringContainsString('Disetujui / Terbit 5 pengajuan', $qSktm);
        $this->assertStringContainsString('Ditolak 0 pengajuan', $qSktm);
        $this->assertStringNotContainsString('SUPERVISOR_REVIEW', $qSktm);

        $qTotal = $this->ask($sup, 'Berapa total SKTM?');
        $this->assertStringContainsString('Total ada 11 pengajuan', $qTotal);
        $this->assertStringNotContainsString('review Supervisor', $qTotal);

        $qWaiting = $this->ask($sup, 'Ada berapa pengajuan yang menunggu?');
        $this->assertCount_(4, $qWaiting, 'menunggu aktif = 2 Tahap Staf + 2 Menunggu Kades');
        $this->assertStringContainsString('2 di Tahap Staf', $qWaiting);
        $this->assertStringContainsString('2 menunggu Kepala Desa', $qWaiting);

        $this->assertCount_(2, $this->ask($sup, 'Berapa yang menunggu Kepala Desa?'), 'menunggu kades');
        $this->assertCount_(5, $this->ask($sup, 'Berapa yang sudah disetujui?'), 'disetujui');

        $listAll = $this->ask($sup, 'Tampilkan semua pengajuan.');
        $this->assertStringStartsWith('Ada 11 pengajuan:', $listAll);

        $this->assertLlmNotCalled();
    }
}
