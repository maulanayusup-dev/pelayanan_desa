<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Conversation;
use App\Models\ChatMessage;
use App\Services\AiService;
use Illuminate\Support\Str;

class AiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $roles = ['masyarakat', 'staf', 'supervisor', 'kades'];
        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response([
                'choices' => [
                    ['message' => ['content' => 'Halo! Saya Asisten Virtual Pelayanan Desa. Ada yang bisa dibantu?']]
                ]
            ], 200)
        ]);
    }

    /** @test */
    public function test_uuid_user_compatibility_and_no_type_error()
    {
        $role = Role::firstOrCreate(['name' => 'masyarakat']);
        
        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test User UUID',
            'email' => 'testuuid@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
        ]);

        $this->assertNotNull($user);
        $this->assertIsString($user->id);

        $ai = new AiService();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'session_id' => 'test-uuid-session',
            'title' => 'Test UUID Compatibility',
            'current_service' => 'Surat Keterangan Tidak Mampu'
        ]);

        $reply = $ai->processConversation($conversation, 'siapa anda');
        $this->assertNotEmpty($reply);
        
        $latestMsg = ChatMessage::where('conversation_id', $conversation->id)
            ->latest('created_at')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($latestMsg);
        $this->assertEquals('assistant', $latestMsg->role);
    }

    /** @test */
    public function test_coding_and_programming_refusal_intent_boundary()
    {
        $ai = new AiService();
        $conversation = Conversation::create([
            'session_id' => 'test-coding-refusal',
            'title' => 'Test Coding Refusal'
        ]);

        // Scenario 1: Coding request
        $r1 = $ai->processConversation($conversation, 'Buatkan coding untuk menuliskan nama Anda.');
        $this->assertNotEmpty($r1);
        $this->assertStringNotContainsString('```', $r1);
        $this->assertStringNotContainsString('<html>', $r1);

        // Scenario 2: HTML request
        $r2 = $ai->processConversation($conversation, 'Buatkan HTML untuk website pelayanan desa.');
        $this->assertNotEmpty($r2);
        $this->assertStringNotContainsString('```', $r2);
        $this->assertStringNotContainsString('<html>', $r2);
    }

    /** @test */
    public function test_village_service_questions_and_context_preservation()
    {
        $ai = new AiService();
        $conversation = Conversation::create([
            'session_id' => 'test-village-context',
            'title' => 'Test Village Context'
        ]);

        // Scenario 3: Definition of SKTM
        $r3 = $ai->processConversation($conversation, 'Apa itu SKTM?');
        $this->assertNotEmpty($r3);
        $this->assertEquals('Surat Keterangan Tidak Mampu', $conversation->fresh()->current_service);

        // Scenario 4: Context follow up
        $r4 = $ai->processConversation($conversation, 'Syaratnya apa?');
        $this->assertNotEmpty($r4);

        // Scenario 5: Out of scope polite refusal
        $r5 = $ai->processConversation($conversation, 'Rekomendasi anime dong.');
        $this->assertNotEmpty($r5);

        // Scenario 6: Resume SKTM context
        $r6 = $ai->processConversation($conversation, 'Oke, kembali ke SKTM. Dokumennya apa?');
        $this->assertNotEmpty($r6);
        $this->assertEquals('Surat Keterangan Tidak Mampu', $conversation->fresh()->current_service);
    }
}
