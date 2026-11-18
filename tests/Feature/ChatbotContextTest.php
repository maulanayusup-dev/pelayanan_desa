<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Conversation;
use App\Models\ChatMessage;
use App\Services\AiService;

class ChatbotContextTest extends TestCase
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
                    ['message' => ['content' => 'Halo! Saya Asisten Virtual Pelayanan Desa. Ada yang bisa dibantu mengenai pengajuan SKTM atau surat desa?']]
                ]
            ], 200)
        ]);
    }

    /** @test */
    public function test_multi_turn_chain_questions()
    {
        $ai = new AiService();
        $conversation = Conversation::create([
            'session_id' => 'test-chain-1',
            'title' => 'Multi turn chain'
        ]);

        $q1 = $ai->processConversation($conversation, 'Apa itu SKTM?');
        $this->assertNotEmpty($q1);
        $this->assertEquals('Surat Keterangan Tidak Mampu', $conversation->fresh()->current_service);

        $q2 = $ai->processConversation($conversation, 'Siapa yang bisa mengajukan?');
        $this->assertNotEmpty($q2);

        $q3 = $ai->processConversation($conversation, 'Syaratnya apa?');
        $this->assertNotEmpty($q3);

        $q4 = $ai->processConversation($conversation, 'Dokumennya apa saja?');
        $this->assertNotEmpty($q4);

        $q5 = $ai->processConversation($conversation, 'Prosesnya bagaimana?');
        $this->assertNotEmpty($q5);

        $q6 = $ai->processConversation($conversation, 'Berapa lama?');
        $this->assertNotEmpty($q6);

        $q7 = $ai->processConversation($conversation, 'Ada biayanya?');
        $this->assertNotEmpty($q7);

        $q8 = $ai->processConversation($conversation, 'Kalau KK saya belum ada?');
        $this->assertNotEmpty($q8);
        $this->assertStringNotContainsString('Maaf, informasi tersebut belum tersedia', $q8);
    }

    /** @test */
    public function test_informal_language_resolution()
    {
        $ai = new AiService();
        $conversation = Conversation::create([
            'session_id' => 'test-informal-1',
            'title' => 'Informal phrasing'
        ]);

        $res1 = $ai->processConversation($conversation, 'Mau bikin surat nggak mampu, gimana?');
        $this->assertNotEmpty($res1);
        $this->assertEquals('Surat Keterangan Tidak Mampu', $conversation->fresh()->current_service);

        $res2 = $ai->processConversation($conversation, 'Kalau surat itu dokumennya apa saja?');
        $this->assertNotEmpty($res2);
        $this->assertStringNotContainsString('Maaf, informasi tersebut belum tersedia', $res2);
    }

    /** @test */
    public function test_topic_switch_precision()
    {
        $ai = new AiService();
        $conversation = Conversation::create([
            'session_id' => 'test-switch-1',
            'title' => 'Topic switch'
        ]);

        $ai->processConversation($conversation, 'Apa syarat SKTM?');
        $this->assertEquals('Surat Keterangan Tidak Mampu', $conversation->fresh()->current_service);

        $ai->processConversation($conversation, 'Sekarang saya mau tanya surat domisili.');
        $this->assertEquals('Surat Keterangan Domisili', $conversation->fresh()->current_service);

        $res3 = $ai->processConversation($conversation, 'Syaratnya apa?');
        $this->assertNotEmpty($res3);
        $this->assertEquals('Surat Keterangan Domisili', $conversation->fresh()->current_service);
    }

    /** @test */
    public function test_ambiguous_query_clarification()
    {
        $ai = new AiService();
        $conversation = Conversation::create([
            'session_id' => 'test-ambiguous-1',
            'title' => 'Ambiguous query'
        ]);

        $res = $ai->processConversation($conversation, 'Saya mau bikin surat.');
        $this->assertNotEmpty($res);
        $this->assertNull($conversation->fresh()->current_service);
    }

    /** @test */
    public function test_soft_boundary_handling()
    {
        $ai = new AiService();
        $conversation = Conversation::create([
            'session_id' => 'test-soft-boundary-1',
            'title' => 'Soft boundary'
        ]);

        // Off topic query
        $res1 = $ai->processConversation($conversation, 'Hari ini capek banget.');
        $this->assertNotEmpty($res1);
        $this->assertStringNotContainsString('Pertanyaan Anda berada di luar cakupan', $res1);

        // Mixed query
        $res2 = $ai->processConversation($conversation, 'Apa syarat SKTM dan rekomendasi anime yang bagus apa?');
        $this->assertNotEmpty($res2);

        // Resume SKTM context
        $res3 = $ai->processConversation($conversation, 'Oke balik lagi. Berapa biayanya?');
        $this->assertNotEmpty($res3);
        $this->assertEquals('Surat Keterangan Tidak Mampu', $conversation->fresh()->current_service);
    }
}
