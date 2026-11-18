<?php

namespace Tests\Feature;

use App\Livewire\AdminDashboard;
use App\Models\AiProviderCredential;
use App\Models\AiSetting;
use App\Models\Application;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Services\AiConfigService;
use App\Services\AiService;
use App\Services\ApplicationStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pengujian perbaikan audit AI Asisten Pelayanan Desa.
 *
 * Dataset (waktu uji: Jumat 20 Nov 2026 10:00 WIB):
 *  - Warga A: SUBMITTED (hari ini 08:00 WIB), COMPLETED (5 Nov), REJECTED (15 Okt)
 *  - Warga B: HEAD_APPROVAL (20 Okt), APPROVED (hari ini 01:00 WIB = 19 Nov 18:00 UTC)
 *  - Warga C: tanpa pengajuan
 *  - Warga E: hanya DRAFT (Juni 2025) — belum pernah mengirim pengajuan
 *
 * Seluruh angka yang diharapkan juga dihitung ulang langsung dari database pada setiap test.
 */
class AiAssistantAuditFixTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_KEY = 'gsk_SECRET-test-key-should-never-leak';

    /** @var array<string,User> */
    private array $u = [];

    private ApplicationStatisticsService $stats;

    protected function setUp(): void
    {
        parent::setUp();

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
            ['api_key_encrypted' => self::FAKE_KEY, 'key_cleared_explicitly' => false]
        );
        AiConfigService::forgetCache();
        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00:00', 'Asia/Jakarta')->setTimezone('UTC'));

        $roles = [];
        foreach (['masyarakat', 'staf', 'supervisor', 'kades', 'kepala_desa'] as $name) {
            $roles[$name] = Role::firstOrCreate(['name' => $name]);
        }
        $make = fn (string $role, string $key) => $this->u[$key] = User::create([
            'name' => 'User ' . $key,
            'email' => $key . '@uji.desa.id',
            'password' => bcrypt('password'),
            'role_id' => $roles[$role]->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $make('staf', 'staf');
        $make('supervisor', 'supervisor');
        $make('kades', 'kades');
        $make('kepala_desa', 'kepala_desa');
        foreach (['wargaA', 'wargaB', 'wargaC', 'wargaE'] as $w) {
            $make('masyarakat', $w);
        }

        $doc = DocumentType::create(['code' => 'SKTM', 'name' => 'Surat Keterangan Tidak Mampu', 'description' => 'SKTM', 'is_active' => true]);
        $app = function (string $owner, string $status, string $localTime) use ($doc) {
            $created = Carbon::parse($localTime, 'Asia/Jakarta')->setTimezone('UTC');
            $a = Application::create([
                'application_number' => 'SKTM-UJI-' . Str::upper(Str::random(6)),
                'user_id' => $this->u[$owner]->id,
                'document_type_id' => $doc->id,
                'status' => $status,
            ]);
            $a->forceFill(['created_at' => $created, 'updated_at' => $created])->saveQuietly();
        };

        $app('wargaA', 'SUBMITTED', '2026-11-20 08:00:00');
        $app('wargaA', 'COMPLETED', '2026-11-05 09:00:00');
        $app('wargaA', 'REJECTED', '2026-10-15 09:00:00');
        $app('wargaB', 'HEAD_APPROVAL', '2026-10-20 09:00:00');
        $app('wargaB', 'APPROVED', '2026-11-20 01:00:00');
        $app('wargaE', 'DRAFT', '2025-06-01 09:00:00');

        $this->stats = app(ApplicationStatisticsService::class);

        // Default: penyedia LLM menolak (HTTP 403) — kondisi nyata di Laragon saat audit
        $this->fakeProvider(403);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    /** Ganti penyedia palsu sepenuhnya (Http::fake bertumpuk; stub pertama yang cocok selalu menang). */
    private function freshHttp(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
    }

    private function assertCountIn(int $expected, string $reply, string $context): void
    {
        $pattern = $expected === 0
            ? '/(tidak ada|(?<![\d.])0\s+(pengajuan|berkas))/u'
            : '/(?<![\d.])' . $expected . '\s+(pengajuan|berkas)/u';
        $this->assertMatchesRegularExpression($pattern, $reply, "{$context} → {$reply}");
    }

    private function fakeProvider(int $status): void
    {
        $this->freshHttp();
        Http::fake(['*' => Http::response(['status' => $status, 'title' => 'Forbidden', 'detail' => 'Authorization failed'], $status)]);
    }

    private function conv(User $user): Conversation
    {
        return Conversation::create(['user_id' => $user->id, 'session_id' => Str::random(12), 'title' => 'uji']);
    }

    private function ask(?User $user, string $q, ?Conversation $c = null): string
    {
        $c ??= $user ? $this->conv($user) : Conversation::create(['user_id' => null, 'session_id' => 'tamu', 'title' => 'tamu']);

        return app(AiService::class)->processConversation($c, $q, $user);
    }

    private function sentences(string $text): int
    {
        return count(array_filter(preg_split('/(?<=[.!?])\s+/u', trim($text)), fn ($s) => trim($s) !== ''));
    }

    private function assertShortStatistic(string $reply, string $context): void
    {
        $this->assertLessThanOrEqual(3, $this->sentences($reply), "{$context}: terlalu panjang — {$reply}");
        $this->assertStringNotContainsString('**', $reply, "{$context}: markdown mentah");
        $this->assertDoesNotMatchRegularExpression('/^(tentu|baik|dengan senang hati)/i', $reply, "{$context}: pembukaan basa-basi");
        $this->assertStringNotContainsString('dibatasi oleh penyedia', strtolower($reply), "{$context}: error provider bocor");
    }

    private function distinctApplicantsFromDb(?string $fromUtc = null, ?string $toUtc = null): int
    {
        $q = DB::table('applications')
            ->join('users', 'users.id', '=', 'applications.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'masyarakat')
            ->whereNull('users.deleted_at')
            ->where('applications.status', '!=', 'DRAFT');
        if ($fromUtc) {
            $q->whereBetween('applications.created_at', [$fromUtc, $toUtc]);
        }

        return (int) $q->distinct()->count('applications.user_id');
    }

    // ------------------------------------------------------------------ 1. akun yang melakukan pengajuan

    public function test_1_counts_unique_accounts_that_submitted_not_total_applications(): void
    {
        $expected = $this->distinctApplicantsFromDb();
        $this->assertSame(2, $expected, 'Warga A & B (E hanya draft, C tidak pernah)');
        $this->assertSame(5, Application::where('status', '!=', 'DRAFT')->count(), 'jumlah pengajuan berbeda dari jumlah akun');
        $this->assertSame($expected, $this->stats->applicantAccountCount());

        foreach (['staf', 'supervisor', 'kades', 'kepala_desa'] as $role) {
            foreach ([
                'Berapa akun yang melakukan pengajuan?',
                'Berapa warga yang pernah mengajukan SKTM?',
                'Ada berapa akun warga yang sudah mengajukan?',
                'brp akun yg udh mengajukn?',          // singkatan + salah ketik ringan
            ] as $q) {
                $reply = $this->ask($this->u[$role], $q);
                $this->assertStringContainsString("{$expected} akun warga", $reply, "{$role}: {$q}");
                $this->assertShortStatistic($reply, "{$role}: {$q}");
            }
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 2. warga yang belum pernah mengajukan

    public function test_2_counts_citizens_who_never_submitted(): void
    {
        $totalWarga = User::whereHas('role', fn ($q) => $q->where('name', 'masyarakat'))->count();
        $expected = $totalWarga - $this->distinctApplicantsFromDb();
        $this->assertSame(2, $expected, 'Warga C & E');
        $this->assertSame($expected, $this->stats->citizensWithoutApplicationCount());

        foreach (['supervisor', 'kades'] as $role) {
            $reply = $this->ask($this->u[$role], 'Berapa warga yang belum pernah mengajukan?');
            $this->assertStringContainsString("{$expected} akun warga yang belum pernah mengajukan", $reply);
            $this->assertStringContainsString("dari {$totalWarga} akun warga", $reply);
            $this->assertShortStatistic($reply, $role);
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 3. total pengajuan

    public function test_3_total_applications_matches_database_and_dashboard_card(): void
    {
        $dbTotal = Application::count();
        $this->actingAs($this->u['kades']);
        $card = Livewire::test(AdminDashboard::class)->viewData('stats')['total'];
        $this->assertSame($dbTotal, $card);

        foreach (['Berapa total pengajuan?', 'Berapa jumlah pengajuan yang masuk?', 'Total semua pengajuan ada berapa?'] as $q) {
            $reply = $this->ask($this->u['kades'], $q);
            $this->assertStringContainsString("{$dbTotal} pengajuan", $reply, $q);
            $this->assertShortStatistic($reply, $q);
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 4. statistik status

    public function test_4_status_statistics_follow_agreed_category_rules(): void
    {
        $byStatus = fn (array $statuses) => Application::whereIn('status', $statuses)->count();

        $cases = [
            'Berapa pengajuan menunggu pemeriksaan?' => $byStatus(['SUBMITTED', 'UNDER_REVIEW']),
            'Berapa pengajuan yang sedang diproses?' => $byStatus(['SUBMITTED', 'UNDER_REVIEW', 'SUPERVISOR_REVIEW', 'HEAD_APPROVAL']),
            'Berapa pengajuan yang diteruskan ke Kepala Desa?' => $byStatus(['HEAD_APPROVAL']),
            'Berapa pengajuan disetujui?' => $byStatus(['APPROVED', 'COMPLETED']),
            'Berapa pengajuan ditolak?' => $byStatus(['REJECTED']),
            'Berapa pengajuan selesai?' => $byStatus(['APPROVED', 'COMPLETED']),
            'Berapa pengajuan yang diteruskan/disetujui?' => $byStatus(['HEAD_APPROVAL', 'APPROVED', 'COMPLETED']),
        ];

        foreach ($cases as $q => $expected) {
            $reply = $this->ask($this->u['supervisor'], $q);
            $this->assertCountIn($expected, $reply, $q);
            $this->assertShortStatistic($reply, $q);
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 5. statistik periode

    public function test_5_period_statistics_use_village_local_time(): void
    {
        [$mFrom, $mTo] = $this->stats->periodRange('this_month');
        [$dFrom, $dTo] = $this->stats->periodRange('today');
        [$lFrom, $lTo] = $this->stats->periodRange('last_month');

        $thisMonth = Application::whereBetween('created_at', [$mFrom, $mTo])->count();
        $today = Application::whereBetween('created_at', [$dFrom, $dTo])->count();
        $lastMonth = Application::whereBetween('created_at', [$lFrom, $lTo])->count();

        $this->assertSame([3, 2, 2], [$thisMonth, $today, $lastMonth]);
        // Pengajuan 01:00 WIB tercatat 19 Nov (UTC) tetapi tetap "hari ini" bagi desa
        $this->assertSame('2026-11-19 17:00:00', $dFrom->format('Y-m-d H:i:s'));

        $sup = $this->u['supervisor'];
        $this->assertStringContainsString("Bulan ini ada {$thisMonth} pengajuan masuk", $this->ask($sup, 'Berapa pengajuan bulan ini?'));
        $this->assertStringContainsString("Hari ini ada {$today} pengajuan masuk", $this->ask($sup, 'Berapa pengajuan hari ini?'));
        $this->assertStringContainsString("{$lastMonth} pengajuan masuk", $this->ask($sup, 'Berapa pengajuan bulan lalu?'));

        // Status dalam periode
        $approvedThisMonth = Application::whereBetween('created_at', [$mFrom, $mTo])->whereIn('status', ['APPROVED', 'COMPLETED'])->count();
        $reply = $this->ask($sup, 'Berapa pengajuan disetujui bulan ini?');
        $this->assertStringContainsString("{$approvedThisMonth} pengajuan yang sudah disetujui/selesai", $reply);
        $this->assertShortStatistic($reply, 'disetujui bulan ini');

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 6. pertanyaan lanjutan

    public function test_6_follow_up_questions_keep_conversation_context(): void
    {
        $staf = $this->u['staf'];
        $c = $this->conv($staf);

        $this->assertStringContainsString('Bulan ini ada 3 pengajuan masuk', $this->ask($staf, 'Berapa pengajuan bulan ini?', $c));

        // Staf tidak melihat pengajuan ditolak (pembatasan lama dipertahankan): bulan lalu terlihat 1 (HEAD_APPROVAL)
        $cmp = $this->ask($staf, 'Bagaimana jika dibandingkan bulan lalu?', $c);
        $this->assertStringContainsString('naik 2 dibandingkan bulan lalu (1 pengajuan)', $cmp);
        $supCmp = $this->ask($this->u['supervisor'], 'Berapa pengajuan bulan ini dibandingkan bulan lalu?');
        $this->assertStringContainsString('Bulan ini ada 3 pengajuan masuk, naik 1 dibandingkan bulan lalu (2 pengajuan)', $supCmp);

        $done = $this->ask($staf, 'Dari jumlah tersebut, berapa yang sudah selesai?', $c);
        $this->assertStringContainsString('bulan ini, ada 2 pengajuan yang sudah disetujui/selesai', $done, 'periode bulan ini dipertahankan');

        $waiting = $this->ask($staf, 'Berapa yang masih menunggu?', $c);
        $this->assertStringContainsString('1 pengajuan yang menunggu pemeriksaan Staf', $waiting, '"menunggu" untuk staf = kartu Baru + Diperiksa');

        // Lanjutan pada subjek akun
        $sup = $this->u['supervisor'];
        $c2 = $this->conv($sup);
        $this->assertStringContainsString('2 akun warga', $this->ask($sup, 'Berapa akun yang melakukan pengajuan?', $c2));
        [$lFrom, $lTo] = $this->stats->periodRange('last_month');
        $expectedLast = $this->distinctApplicantsFromDb($lFrom->format('Y-m-d H:i:s'), $lTo->format('Y-m-d H:i:s'));
        $this->assertStringContainsString("Bulan lalu ada {$expectedLast} akun warga", $this->ask($sup, 'kalau bulan lalu?', $c2));
        $this->assertStringContainsString('2 akun warga yang belum pernah', $this->ask($sup, 'kalau yang belum pernah?', $c2));

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 7. AI == dashboard

    public function test_7_ai_statistics_equal_dashboard_cards(): void
    {
        $questions = [
            'staf' => ['baru' => 'Berapa pengajuan baru?', 'perbaikan' => 'Berapa pengajuan yang dikembalikan?', 'selesai' => 'Berapa pengajuan selesai?'],
            'supervisor' => ['total' => 'Berapa total pengajuan?', 'staf_queue' => 'Berapa pengajuan menunggu pemeriksaan?', 'needs_revision' => 'Berapa pengajuan yang dikembalikan?', 'head_approval' => 'Berapa yang menunggu Kepala Desa?', 'completed' => 'Berapa pengajuan disetujui?', 'rejected' => 'Berapa pengajuan ditolak?'],
            'kades' => ['menunggu' => 'Berapa pengajuan yang diteruskan ke Kepala Desa?', 'diterbitkan' => 'Berapa pengajuan disetujui?', 'ditolak' => 'Berapa pengajuan ditolak?', 'total' => 'Berapa total pengajuan?'],
        ];

        foreach ($questions as $role => $map) {
            $this->actingAs($this->u[$role]);
            $cards = Livewire::test(AdminDashboard::class)->viewData('stats');
            foreach ($map as $card => $q) {
                $reply = $this->ask($this->u[$role], $q);
                $this->assertCountIn($cards[$card], $reply, "{$role}/{$card}");
            }
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 8. privasi warga

    public function test_8_citizen_cannot_read_other_citizens_data(): void
    {
        $a = $this->u['wargaA'];

        $accounts = $this->ask($a, 'Berapa akun yang melakukan pengajuan?');
        $this->assertStringContainsString('tidak dapat ditampilkan untuk akun warga', $accounts);
        $this->assertDoesNotMatchRegularExpression('/\d+\s+akun/', $accounts);

        $never = $this->ask($a, 'Berapa warga yang belum pernah mengajukan?');
        $this->assertDoesNotMatchRegularExpression('/\d+\s+akun/', $never);

        [$mFrom, $mTo] = $this->stats->periodRange('this_month');
        $own = Application::where('user_id', $a->id)->whereBetween('created_at', [$mFrom, $mTo])->count();
        $reply = $this->ask($a, 'Berapa pengajuan bulan ini?');
        $this->assertStringContainsString("{$own} pengajuan milik Anda", $reply);
        $this->assertStringNotContainsString('3 pengajuan', $reply, 'angka seluruh desa tidak boleh bocor');

        $this->assertStringContainsString('0 pengajuan milik Anda', $this->ask($this->u['wargaC'], 'Berapa pengajuan bulan ini?'));

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 9. izin role

    public function test_9_role_permissions_are_enforced_in_backend(): void
    {
        // Staf: boleh jumlah akun pemohon, tidak boleh rekap penolakan & akun yang belum mengajukan
        $this->assertStringContainsString('2 akun warga', $this->ask($this->u['staf'], 'Berapa akun yang melakukan pengajuan?'));
        $this->assertStringContainsString('data internal', $this->ask($this->u['staf'], 'Berapa pengajuan ditolak?'));
        $this->assertStringContainsString('hanya tersedia untuk Supervisor dan Kepala Desa', $this->ask($this->u['staf'], 'Berapa warga yang belum pernah mengajukan?'));
        $this->assertStringContainsString('data internal', $this->ask($this->u['staf'], 'Berapa pengajuan ditolak bulan lalu?'));

        // Supervisor & Kepala Desa: boleh
        foreach (['supervisor', 'kades', 'kepala_desa'] as $role) {
            $this->assertMatchesRegularExpression('/1\s+pengajuan yang ditolak/', $this->ask($this->u[$role], 'Berapa pengajuan ditolak?'), $role);
            $this->assertStringContainsString('2 akun warga yang belum pernah', $this->ask($this->u[$role], 'Berapa warga yang belum pernah mengajukan?'), $role);
        }

        // Tamu: wajib login
        $guest = $this->ask(null, 'Berapa akun yang melakukan pengajuan?');
        $this->assertStringContainsString('login', strtolower($guest));
        $this->assertDoesNotMatchRegularExpression('/\d+\s+(akun|pengajuan)/', $guest);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 10–13. kegagalan provider

    public function test_10_to_13_provider_failures_are_reported_by_cause_not_as_access_denial(): void
    {
        $ai = app(AiService::class);
        $question = 'Tolong jelaskan manfaat SKTM untuk beasiswa secara umum';
        $cases = [
            403 => ['forbidden', 'kunci API ditolak'],           // 10. provider menolak (akar masalah Laragon)
            401 => ['invalid_key', 'kunci API tidak valid'],     // 11. API key tidak valid
            429 => ['rate_limited', 'lonjakan antrean'],         // 13. rate limit
            500 => ['server_error', 'kendala teknis'],
            503 => ['server_error', 'kendala teknis'],
            404 => ['model_not_found', 'model AI tidak ditemukan'],
        ];

        foreach ($cases as $status => [$reason, $needle]) {
            $this->fakeProvider($status);
            $reply = $this->ask($this->u['wargaA'], $question);
            $this->assertSame(AiService::PROVIDER_MESSAGES[$reason], $reply, "HTTP {$status}");
            $this->assertStringContainsString($needle, $reply);
            $this->assertStringNotContainsString('dibatasi oleh penyedia sistem', $reply);
            $this->assertStringNotContainsString(self::FAKE_KEY, $reply);
            $this->assertLessThanOrEqual(2, $this->sentences($reply));
            $this->assertDoesNotMatchRegularExpression('/\d+\s+(akun|pengajuan)/', $reply, 'tidak boleh ada angka tebakan');
        }

        // 12. Timeout — pesan koneksi, kunci API tidak bocor ke log
        Log::spy();
        $this->freshHttp();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out; Bearer ' . self::FAKE_KEY));
        $reply = $this->ask($this->u['wargaA'], $question);
        $this->assertSame(AiService::PROVIDER_MESSAGES['timeout'], $reply);
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, '[timeout]') && !str_contains($m, self::FAKE_KEY));

        // API key kosong
        AiProviderCredential::query()->where('provider', 'groq')->delete();
        \App\Services\AiConfigService::forgetCache();
        $this->assertSame(AiService::PROVIDER_MESSAGES['not_configured'], (new AiService())->chat([['role' => 'user', 'content' => 'halo']]));
    }

    public function test_403_body_with_key_is_sanitised_in_log(): void
    {
        Log::spy();
        $this->freshHttp();
        Http::fake(['*' => Http::response('denied for Bearer ' . self::FAKE_KEY, 403)]);
        $ai = app(AiService::class);
        $ai->chat([['role' => 'user', 'content' => 'halo']]);

        $this->assertSame('forbidden', $ai->getLastProviderError());
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, '[HTTP 403] [forbidden]') && !str_contains($m, self::FAKE_KEY));
    }

    // ------------------------------------------------------------------ 14. statistik tetap jalan saat LLM mati

    public function test_14_database_statistics_work_while_llm_is_unavailable(): void
    {
        foreach ([403, 401, 429, 500] as $status) {
            $this->fakeProvider($status);
            $reply = $this->ask($this->u['supervisor'], 'Berapa akun yang melakukan pengajuan?');
            $this->assertStringContainsString('2 akun warga', $reply, "HTTP {$status}");
            $reply = $this->ask($this->u['supervisor'], 'Berapa pengajuan hari ini?');
            $this->assertStringContainsString('Hari ini ada 2 pengajuan masuk', $reply, "HTTP {$status}");
        }

        $this->freshHttp();
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->assertStringContainsString('2 akun warga', $this->ask($this->u['kades'], 'Berapa warga yang pernah mengajukan SKTM?'));

        Http::assertNothingSent();
    }

    public function test_unsupported_count_question_is_answered_honestly_without_llm_guessing(): void
    {
        $this->freshHttp();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Ada 99 pengguna yang online.']]]], 200)]);
        $reply = $this->ask($this->u['supervisor'], 'Berapa pengguna yang sedang online sekarang?');

        $this->assertStringNotContainsString('99', $reply);
        $this->assertStringContainsString('belum bisa saya hitung', $reply);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 15–16. gaya jawaban

    public function test_15_simple_answers_are_concise_and_style_rules_reach_the_llm(): void
    {
        $this->freshHttp();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '**Syarat SKTM**: KTP, KK, dan surat pengantar RT/RW.']]]], 200)]);

        $reply = $this->ask($this->u['wargaA'], 'Apa saja persyaratan SKTM?');
        $this->assertSame('Syarat SKTM: KTP, KK, dan surat pengantar RT/RW.', $reply, 'markdown mentah dibuang untuk tampilan teks');

        Http::assertSent(function ($request) {
            $system = $request['messages'][0]['content'] ?? '';
            return str_contains($system, '1–3 kalimat')
                && str_contains($system, 'DILARANG menebak angka')
                && str_contains($system, 'maksimal 5 langkah');
        });

        // Fallback lokal saat LLM mati juga ringkas
        $this->fakeProvider(403);
        $fallback = $this->ask($this->u['wargaA'], 'Apa saja persyaratan SKTM?');
        $this->assertStringContainsString('KTP', $fallback);
        $this->assertStringContainsString('Kartu Keluarga', $fallback);
        $this->assertStringContainsString('Pengantar RT/RW', $fallback);
        $this->assertLessThanOrEqual(3, $this->sentences($fallback));

        $steps = $this->ask($this->u['wargaA'], 'Bagaimana caranya mengajukan?');
        $this->assertLessThanOrEqual(5, preg_match_all('/^\d+\./m', $steps), 'prosedur maksimal 5 langkah');

        $this->assertLessThanOrEqual(2, $this->sentences($this->ask($this->u['wargaA'], 'Siapa kamu?')));

        // "Berapa lama" dijawab estimasi waktu, bukan daftar alur panjang
        $duration = $this->ask($this->u['wargaA'], 'Berapa lama proses SKTM?');
        $this->assertStringContainsString('1 hingga 2 hari kerja', $duration);
        $this->assertStringNotContainsString('Draft Permohonan', $duration);

        $flow = $this->ask($this->u['wargaA'], 'Bagaimana alur pengajuan SKTM?');
        $this->assertSame(5, preg_match_all('/^\d+\./m', $flow), 'alur diringkas menjadi 5 langkah');
    }

    public function test_16_statistic_answers_show_actual_numbers_first_and_briefly(): void
    {
        $sup = $this->u['supervisor'];
        $before = $this->ask($sup, 'Berapa pengajuan bulan ini?');
        $this->assertStringContainsString('3 pengajuan', $before);

        // Data berubah → jawaban langsung berubah (tidak ada cache/angka lama)
        Application::create([
            'application_number' => 'SKTM-UJI-BARU',
            'user_id' => $this->u['wargaC']->id,
            'document_type_id' => DocumentType::first()->id,
            'status' => 'SUBMITTED',
        ]);
        $after = $this->ask($sup, 'Berapa pengajuan bulan ini?');
        $this->assertStringContainsString('4 pengajuan', $after);
        $this->assertStringContainsString('3 akun warga', $this->ask($sup, 'Berapa akun yang melakukan pengajuan?'));

        foreach (['Berapa pengajuan yang sedang diproses?', 'Berapa total pengajuan?', 'Berapa akun yang melakukan pengajuan?'] as $q) {
            $reply = $this->ask($sup, $q);
            $this->assertMatchesRegularExpression('/^\D{0,25}\d/u', $reply, "angka utama muncul di awal: {$reply}");
            $this->assertShortStatistic($reply, $q);
        }
        $this->assertShortStatistic($this->ask($sup, 'Ringkasan pengajuan saat ini'), 'ringkasan');
    }

    // ------------------------------------------------------------------ 17. riwayat & reset

    public function test_17_history_and_reset_still_work_through_livewire_widget(): void
    {
        $this->actingAs($this->u['supervisor']);

        $widget = Livewire::test('chatbot-widget');
        $firstConversation = $widget->get('conversationId');
        $this->assertNotNull($firstConversation);

        $widget->set('userInput', 'Berapa akun yang melakukan pengajuan?')->call('sendMessage');
        Carbon::setTestNow(now()->addSeconds(5)); // waktu nyata berjalan di antara dua pesan
        $widget->set('userInput', 'kalau bulan lalu?')->call('sendMessage');

        $messages = collect($widget->get('messages'));
        $this->assertSame(['assistant', 'user', 'assistant', 'user', 'assistant'], $messages->pluck('role')->all());
        $this->assertStringContainsString('2 akun warga', $messages[2]['content']);
        $this->assertStringContainsString('Bulan lalu ada', $messages[4]['content']);
        $this->assertSame(5, ChatMessage::where('conversation_id', $firstConversation)->count());

        // Reset: percakapan baru, riwayat lama tetap tersimpan, konteks lanjutan tidak terbawa
        $widget->call('resetChat');
        $this->assertNotSame($firstConversation, $widget->get('conversationId'));
        $this->assertCount(1, $widget->get('messages'));
        $this->assertSame(5, ChatMessage::where('conversation_id', $firstConversation)->count());

        Carbon::setTestNow(now()->addSeconds(5));
        $widget->set('userInput', 'kalau bulan lalu?')->call('sendMessage');
        $last = collect($widget->get('messages'))->last()['content'];
        $this->assertStringNotContainsString('akun warga', $last, 'konteks percakapan lama tidak boleh terbawa setelah reset');
        $this->assertStringNotContainsString('dibatasi oleh penyedia sistem', $last);
    }
}
