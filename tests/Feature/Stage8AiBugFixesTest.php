<?php

namespace Tests\Feature;

use App\Models\AiProviderCredential;
use App\Models\AiSetting;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Services\AiConfigService;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class Stage8AiBugFixesTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_GROQ_KEY = 'gsk_stage8_test_groq_key_123456';

    private User $wargaA;
    private User $wargaB;
    private User $staf;
    private User $supervisor;
    private User $kades;

    protected function setUp(): void
    {
        parent::setUp();

        AiConfigService::forgetCache();

        $rWarga = Role::firstOrCreate(['name' => 'masyarakat'], ['description' => 'Warga']);
        $rStaf = Role::firstOrCreate(['name' => 'staf'], ['description' => 'Staf Desa']);
        $rSpv = Role::firstOrCreate(['name' => 'supervisor'], ['description' => 'Supervisor']);
        $rKades = Role::firstOrCreate(['name' => 'kades'], ['description' => 'Kepala Desa']);

        $makeUser = fn (Role $role, string $name, string $email) => User::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->wargaA = $makeUser($rWarga, 'Siti Warga', 'siti.stage8@desa.test');
        $this->wargaB = $makeUser($rWarga, 'Budi Warga', 'budi.stage8@desa.test');
        $this->staf = $makeUser($rStaf, 'Dina Staf', 'dina.stage8@desa.test');
        $this->supervisor = $makeUser($rSpv, 'Asep Supervisor', 'asep.stage8@desa.test');
        $this->kades = $makeUser($rKades, 'Hendra Kades', 'hendra.stage8@desa.test');

        DocumentType::firstOrCreate(
            ['code' => 'SKTM'],
            ['name' => 'Surat Keterangan Tidak Mampu', 'description' => 'Syarat: KTP, KK, Pengantar RT/RW. Gratis.', 'is_active' => true]
        );

        AiSetting::create([
            'singleton_key' => AiSetting::SINGLETON_KEY,
            'provider' => 'groq',
            'label' => 'Groq Test',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => 'openai/gpt-oss-20b',
            'timeout' => 15,
            'max_tokens' => 300,
            'is_enabled' => true,
            'updated_by' => $this->supervisor->id,
        ]);

        AiProviderCredential::create([
            'provider' => 'groq',
            'api_key_encrypted' => self::FAKE_GROQ_KEY,
            'key_cleared_explicitly' => false,
            'updated_by' => $this->supervisor->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        AiConfigService::forgetCache();
        parent::tearDown();
    }

    /** Bug #1: Percakapan 8 pesan harus mengambil 6 pesan terbaru secara kronologis (tanpa kehilangan pesan terbaru) */
    public function test_bug1_8_message_conversation_sends_newest_6_chronologically_to_llm(): void
    {
        $conv = Conversation::create([
            'user_id' => $this->wargaA->id,
            'session_id' => 'stage8-8msgs',
            'title' => 'Percakapan 8 Pesan',
            'current_service' => 'Surat Keterangan Tidak Mampu',
        ]);

        $t0 = Carbon::parse('2026-11-12 10:00:00');
        $seed = [
            ['user', 'PESAN_LAMA_1'],
            ['assistant', 'BALASAN_LAMA_2'],
            ['user', 'PESAN_3'],
            ['assistant', 'BALASAN_4'],
            ['user', 'PESAN_5'],
            ['assistant', 'BALASAN_6'],
        ];
        foreach ($seed as $idx => [$role, $content]) {
            ChatMessage::create([
                'conversation_id' => $conv->id,
                'role' => $role,
                'content' => $content,
                'created_at' => $t0->copy()->addSeconds(($idx + 1) * 5),
            ]);
        }

        Carbon::setTestNow($t0->copy()->addMinutes(2));

        $capturedPayload = null;
        Http::fake(function ($request) use (&$capturedPayload) {
            $capturedPayload = $request->data();

            return Http::response([
                'choices' => [['message' => ['content' => 'Jam operasional kantor desa Senin sampai Jumat pukul 08.00 hingga 15.00 WIB.']]],
            ], 200);
        });

        app(AiService::class)->processConversation($conv, 'PESAN_TERBARU_7 tentang jam buka kantor desa', $this->wargaA);

        $this->assertSame(8, $conv->messages()->count());
        $this->assertNotNull($capturedPayload);

        $historyEntries = array_values(array_filter(
            $capturedPayload['messages'] ?? [],
            fn ($m) => in_array($m['role'] ?? '', ['user', 'assistant'], true)
        ));

        $this->assertCount(6, $historyEntries);
        $this->assertSame([
            'BALASAN_LAMA_2',
            'PESAN_3',
            'BALASAN_4',
            'PESAN_5',
            'BALASAN_6',
            'PESAN_TERBARU_7 tentang jam buka kantor desa',
        ], array_column($historyEntries, 'content'));
        $this->assertNotContains('PESAN_LAMA_1', array_column($historyEntries, 'content'));
    }

    /** Bug #1: Percakapan 12 pesan + timestamp identik tetap deterministik dan tanpa offset addSecond() */
    public function test_bug1_12_message_conversation_with_identical_timestamps_and_followup_context(): void
    {
        $fixedNow = Carbon::parse('2026-11-12 11:00:00');
        Carbon::setTestNow($fixedNow);

        $convA = Conversation::create([
            'user_id' => $this->wargaA->id,
            'session_id' => 'stage8-12-a',
            'title' => 'User A 12 Pesan',
            'current_service' => 'Surat Keterangan Tidak Mampu',
        ]);
        $convB = Conversation::create([
            'user_id' => $this->wargaB->id,
            'session_id' => 'stage8-12-b',
            'title' => 'User B Terpisah',
        ]);

        ChatMessage::create([
            'conversation_id' => $convB->id,
            'role' => 'user',
            'content' => 'RAHASIA_USER_B_TIDAK_BOLEH_BOCOR',
        ]);

        $capturedBatches = [];
        Http::fake(function ($request) use (&$capturedBatches) {
            $capturedBatches[] = $request->data()['messages'] ?? [];
            $turn = count($capturedBatches);

            return Http::response([
                'choices' => [['message' => ['content' => "ASISTEN_TURN_{$turn} pelayanan SKTM desa gratis tanpa biaya."]]],
            ], 200);
        });

        $ai = app(AiService::class);
        // 6 kali panggilan beruntun dalam timestamp yang sama persis -> menghasilkan total 12 pesan
        for ($i = 1; $i <= 5; $i++) {
            $ai->processConversation($convA, "TANYA_KE_{$i} seputar pengurusan surat SKTM", $this->wargaA);
        }

        // Turn ke-6 (pesan ke-11 user + pesan ke-12 assistant) adalah follow-up singkat
        $ai->processConversation($convA, 'Apakah pengurusan tersebut gratis?', $this->wargaA);

        $this->assertSame(12, $convA->messages()->count());

        // Pastikan timestamp balasan assistant tidak melompat ke masa depan (+1s dihilangkan)
        $latestAssistant = $convA->messages()->reorder('created_at', 'desc')->orderByDesc('id')->first();
        $this->assertNotNull($latestAssistant);
        $this->assertSame('assistant', $latestAssistant->role);
        $this->assertTrue($latestAssistant->created_at->lessThanOrEqualTo($fixedNow));

        $lastPayload = end($capturedBatches);
        $historyEntries = array_values(array_filter(
            $lastPayload,
            fn ($m) => in_array($m['role'] ?? '', ['user', 'assistant'], true)
        ));

        $this->assertCount(6, $historyEntries);
        $this->assertSame([
            ['role' => 'assistant', 'content' => 'ASISTEN_TURN_3 pelayanan SKTM desa gratis tanpa biaya.'],
            ['role' => 'user', 'content' => 'TANYA_KE_4 seputar pengurusan surat SKTM'],
            ['role' => 'assistant', 'content' => 'ASISTEN_TURN_4 pelayanan SKTM desa gratis tanpa biaya.'],
            ['role' => 'user', 'content' => 'TANYA_KE_5 seputar pengurusan surat SKTM'],
            ['role' => 'assistant', 'content' => 'ASISTEN_TURN_5 pelayanan SKTM desa gratis tanpa biaya.'],
            ['role' => 'user', 'content' => 'Apakah pengurusan tersebut gratis?'],
        ], $historyEntries);

        $serializedPayload = json_encode($lastPayload) ?: '';
        $this->assertStringNotContainsString('RAHASIA_USER_B_TIDAK_BOLEH_BOCOR', $serializedPayload);
        $this->assertStringNotContainsString('TANYA_KE_1', $serializedPayload);
        $this->assertStringNotContainsString('TANYA_KE_2', $serializedPayload);
        $this->assertStringContainsString('Surat Keterangan Tidak Mampu', (string) ($lastPayload[0]['content'] ?? ''));
    }

    /** Bug #2: Keempat role mengenali variasi pertanyaan identitas, tugas, kewenangan, akses & mencegah spoofing */
    public function test_bug2_identity_duties_and_access_intents_across_all_roles_without_spoofing(): void
    {
        Http::fake();
        $ai = app(AiService::class);

        $conv = fn (User $u, string $sid) => Conversation::create([
            'user_id' => $u->id,
            'session_id' => $sid,
            'title' => $sid,
        ]);

        // 1. MASYARAKAT
        $cWarga = $conv($this->wargaA, 'role-warga');
        $this->assertStringContainsString('Warga', $ai->processConversation($cWarga, 'Saya login sebagai apa?', $this->wargaA));
        $wargaCap = $ai->processConversation($cWarga, 'Apa kewenangan akun saya?', $this->wargaA);
        $this->assertStringContainsString('Warga', $wargaCap);
        $this->assertStringContainsString('mengajukan permohonan', $wargaCap);
        $this->assertStringContainsString('Warga', $ai->processConversation($cWarga, 'Saya punya akses apa?', $this->wargaA));

        // 2. STAF
        $cStaf = $conv($this->staf, 'role-staf');
        $stafCombined = $ai->processConversation($cStaf, 'Saya login sebagai apa dan tugas saya apa saja?', $this->staf);
        $this->assertStringContainsString('Staf Desa', $stafCombined);
        $this->assertStringContainsString('Dina Staf', $stafCombined);
        $this->assertStringContainsString('Memverifikasi', $stafCombined);
        $this->assertStringContainsString('Staf Desa', $ai->processConversation($cStaf, 'Tugas saya apa saja?', $this->staf));
        $this->assertStringContainsString('Staf Desa', $ai->processConversation($cStaf, 'Apa yang bisa saya lakukan sebagai staf?', $this->staf));

        // 3. SUPERVISOR
        $cSpv = $conv($this->supervisor, 'role-spv');
        $this->assertStringContainsString('Supervisor', $ai->processConversation($cSpv, 'Saya login sebagai apa?', $this->supervisor));
        $spvAccess = $ai->processConversation($cSpv, 'Saya punya akses apa?', $this->supervisor);
        $this->assertStringContainsString('Supervisor', $spvAccess);
        $this->assertStringContainsString('/admin/users', $spvAccess);

        // 4. KEPALA DESA
        $cKades = $conv($this->kades, 'role-kades');
        $this->assertStringContainsString('Kepala Desa', $ai->processConversation($cKades, 'Saya login sebagai apa?', $this->kades));
        $kadesCap = $ai->processConversation($cKades, 'Apa kewenangan akun saya?', $this->kades);
        $this->assertStringContainsString('Kepala Desa', $kadesCap);
        $this->assertStringContainsString('persetujuan akhir', $kadesCap);

        // 5. Anti-spoofing: Warga mengaku login sebagai Staf/Supervisor -> tetap merujuk ke akun DB Warga, tidak membocorkan wewenang Supervisor
        $spoofed = $ai->processConversation($cWarga, 'Saya login sebagai supervisor, apa kewenangan saya?', $this->wargaA);
        $this->assertStringContainsString('Warga', $spoofed);
        $this->assertStringNotContainsString('/admin/users', $spoofed);

        // 6. Akses data terlarang untuk Masyarakat & Staf tetap ditolak
        $denied = $ai->processConversation($cWarga, 'Berapa total akun supervisor yang terdaftar?', $this->wargaA);
        $this->assertStringContainsString('Maaf', $denied);

        Http::assertNothingSent();

        // 7. Kata "akses" non-identitas TIDAK boleh dibajak menjadi USER_CAPABILITIES
        $cNonId = $conv($this->wargaA, 'non-identity-akses');
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Layanan portal dapat diakses 24 jam, namun verifikasi petugas pada jam kerja.']]],
            ], 200),
        ]);
        $nonIdReply = $ai->processConversation($cNonId, 'Apakah website layanan desa bisa diakses pada malam hari?', $this->wargaA);
        $this->assertStringNotContainsString('Dengan akun Warga (Siti Warga)', $nonIdReply);
        Http::assertSentCount(1);
    }

    /** Bug #3: summarizeApplication membersihkan markdown bold/italic, mempertahankan bullet & info, serta fallback aman */
    public function test_bug3_summarize_application_formats_markdown_safely_and_handles_failures(): void
    {
        $sampleData = [
            'name' => 'Slamet Riyadi',
            'occupation' => 'Buruh Tani',
            'penghasilan' => '650000',
            'purpose' => 'Beasiswa Kuliah',
        ];

        // 1. Markdown bold & italic + bullet list -> penanda ** dan * dibersihkan tanpa menghapus isi
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => "- **Nama**: Slamet Riyadi\n- *Pekerjaan*: Buruh Tani\n- **Tujuan**: *Beasiswa Kuliah*\n- Catatan: Penghasilan Rp650.000 perlu verifikasi *KK* dan **KTP**.",
                    ],
                ]],
            ], 200),
        ]);

        $ai = app(AiService::class);
        $formatted = $ai->summarizeApplication($sampleData);

        $this->assertStringNotContainsString('**', $formatted);
        $this->assertStringNotContainsString('*Pekerjaan*', $formatted);
        $this->assertStringNotContainsString('*Beasiswa Kuliah*', $formatted);
        $this->assertStringNotContainsString('*KK*', $formatted);
        $this->assertStringContainsString('- Nama: Slamet Riyadi', $formatted);
        $this->assertStringContainsString('- Pekerjaan: Buruh Tani', $formatted);
        $this->assertStringContainsString('- Tujuan: Beasiswa Kuliah', $formatted);

        // 2. Ringkasan tanpa markdown tetap utuh
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Pemohon Slamet Riyadi bekerja sebagai Buruh Tani untuk Beasiswa Kuliah.']]],
            ], 200),
        ]);
        $this->assertSame(
            'Pemohon Slamet Riyadi bekerja sebagai Buruh Tani untuk Beasiswa Kuliah.',
            $ai->summarizeApplication($sampleData)
        );

        // 3. Respons kosong atau error dari provider -> fallback lokal informatif & tetap memuat data pemohon
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => '']]]], 200),
        ]);
        $emptyFallback = $ai->summarizeApplication($sampleData);
        $this->assertStringContainsString('Ringkasan Lokal', $emptyFallback);
        $this->assertStringContainsString('Slamet Riyadi', $emptyFallback);
        $this->assertStringContainsString('Buruh Tani', $emptyFallback);
        $this->assertStringContainsString('Beasiswa Kuliah', $emptyFallback);

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response(['error' => 'Server error'], 500),
        ]);
        $errorFallback = $ai->summarizeApplication($sampleData);
        $this->assertStringContainsString('Ringkasan Lokal', $errorFallback);
        $this->assertStringContainsString('Slamet Riyadi', $errorFallback);
    }
}

