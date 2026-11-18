<?php

namespace Tests\Feature;

use App\Services\AiConfigService;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AiConfigTimeoutDiagnosticsTest extends TestCase
{
    private const FAKE_SECRET = 'AIzaSyTimeoutDiagSecretToken9988';

    private function baseCandidate(int $timeout = 30): array
    {
        return [
            'provider' => 'google_ai_studio',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'model' => 'gemini-2.5-flash',
            'api_key' => self::FAKE_SECRET,
            'timeout' => $timeout,
            'max_tokens' => 300,
        ];
    }

    public function test_1_connect_timeout_classified_without_secret_leak(): void
    {
        Log::spy();
        Http::swap(new HttpFactory());
        Http::fake([
            '*' => function () {
                throw new ConnectionException('cURL error 28: Connection timed out after 10002 milliseconds for Authorization: Bearer ' . self::FAKE_SECRET);
            },
        ]);

        $res = app(AiConfigService::class)->testConnection($this->baseCandidate(30));

        $this->assertFalse($res['ok']);
        $this->assertSame('timeout', $res['reason']);
        $this->assertSame('connect_timeout', $res['timeout_phase']);
        $this->assertStringContainsStringIgnoringCase('membuka koneksi jaringan', $res['message']);
        $this->assertStringContainsString('10 detik', $res['message']);
        $this->assertStringNotContainsString(self::FAKE_SECRET, $res['message']);

        Log::shouldHaveReceived('error')->withArgs(
            fn ($msg) => str_contains($msg, '[timeout]')
                && str_contains($msg, '[connect_timeout]')
                && ! str_contains($msg, self::FAKE_SECRET)
        );
    }

    public function test_2a_upstream_ttfb_timeout_classified_from_curl_message(): void
    {
        Log::spy();
        Http::swap(new HttpFactory());
        Http::fake([
            '*' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out after 30016 milliseconds with 0 bytes received for https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent (' . self::FAKE_SECRET . ')');
            },
        ]);

        $res = app(AiConfigService::class)->testConnection($this->baseCandidate(30));

        $this->assertFalse($res['ok']);
        $this->assertSame('timeout', $res['reason']);
        $this->assertSame('upstream_ttfb_timeout', $res['timeout_phase']);
        $this->assertStringContainsStringIgnoringCase('server ai upstream tidak merespons', $res['message']);
        $this->assertStringContainsString('30 detik', $res['message']);
        $this->assertStringNotContainsString(self::FAKE_SECRET, $res['message']);

        Log::shouldHaveReceived('error')->withArgs(
            fn ($msg) => str_contains($msg, '[timeout]')
                && str_contains($msg, '[upstream_ttfb_timeout]')
                && ! str_contains($msg, self::FAKE_SECRET)
        );
    }

    public function test_2b_upstream_ttfb_timeout_classified_from_transfer_stats_when_tcp_tls_succeeds_with_zero_starttransfer(): void
    {
        $svc = new class extends AiConfigService {
            public function probeClassify(string $msg, array $stats, int $timeout): array
            {
                return $this->classifyTimeoutPhase(
                    exceptionMessage: $msg,
                    handlerStats: $stats,
                    providerLabel: 'Google AI Studio (Gemini)',
                    timeout: $timeout,
                    connectTimeout: $this->resolveConnectTimeout($timeout)
                );
            }
        };

        // TCP (0.023s), TLS (0.324s), request terkirim (0.324s), starttransfer_time tetap 0
        $upstream = $svc->probeClassify('cURL error 28', [
            'connect_time' => 0.0231,
            'appconnect_time' => 0.3240,
            'pretransfer_time' => 0.3241,
            'starttransfer_time' => 0.0,
            'size_download' => 0,
        ], 30);

        $this->assertSame('upstream_ttfb_timeout', $upstream['phase']);
        $this->assertStringContainsString('TCP/TLS dan permintaan berhasil terkirim', $upstream['message']);
        $this->assertStringContainsString('server AI upstream tidak merespons', $upstream['message']);
        $this->assertStringContainsString('30 detik', $upstream['message']);

        // TCP tidak pernah tersambung (connect_time = 0)
        $connect = $svc->probeClassify('cURL error 28', [
            'connect_time' => 0.0,
            'appconnect_time' => 0.0,
            'pretransfer_time' => 0.0,
            'starttransfer_time' => 0.0,
            'size_download' => 0,
        ], 30);

        $this->assertSame('connect_timeout', $connect['phase']);
        $this->assertStringContainsString('10 detik', $connect['message']);
    }

    public function test_3_and_4_http_403_404_and_200_remain_unaffected(): void
    {
        $svc = app(AiConfigService::class);

        // HTTP 403
        Http::swap(new HttpFactory());
        Http::fake(['*' => Http::response(['status' => 403, 'title' => 'Forbidden', 'detail' => 'Authorization failed ' . self::FAKE_SECRET], 403)]);
        $r403 = $svc->testConnection($this->baseCandidate(15));
        $this->assertFalse($r403['ok']);
        $this->assertSame('forbidden', $r403['reason']);
        $this->assertSame(403, $r403['http_status']);
        $this->assertNull($r403['timeout_phase']);
        $this->assertStringNotContainsString(self::FAKE_SECRET, $r403['message']);

        // HTTP 404
        Http::swap(new HttpFactory());
        Http::fake(['*' => Http::response(['status' => 404, 'title' => 'Not Found'], 404)]);
        $r404 = $svc->testConnection($this->baseCandidate(15));
        $this->assertFalse($r404['ok']);
        $this->assertSame('model_not_found', $r404['reason']);
        $this->assertSame(404, $r404['http_status']);
        $this->assertNull($r404['timeout_phase']);

        // HTTP 200
        Http::swap(new HttpFactory());
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'OK']]], 'finishReason' => 'STOP']]], 200)]);
        $r200 = $svc->testConnection($this->baseCandidate(15));
        $this->assertTrue($r200['ok']);
        $this->assertSame('ok', $r200['reason']);
        $this->assertSame(200, $r200['http_status']);
    }

    public function test_5_connect_timeout_capped_at_10s_and_never_exceeds_total_timeout_without_retries(): void
    {
        $svc = new class extends AiConfigService {
            public function exposeHttpOptions(int $timeout): array
            {
                return $this->buildHttpOptions([
                    'host' => 'generativelanguage.googleapis.com',
                    'port' => 443,
                    'resolved_ips' => ['75.2.113.119'],
                    'is_literal_ip' => false,
                ], $timeout, function (): void {});
            }
        };

        $opts30 = $svc->exposeHttpOptions(30);
        $this->assertSame(10, $opts30['connect_timeout']);
        $this->assertSame(30, $opts30['timeout']);
        $this->assertSame(10, $opts30['curl'][CURLOPT_CONNECTTIMEOUT]);
        $this->assertSame(30, $opts30['curl'][CURLOPT_TIMEOUT]);
        $this->assertIsCallable($opts30['on_stats']);

        // Saat timeout total 5 detik (< 10), connect_timeout tidak boleh melebihi 5 detik
        $opts5 = $svc->exposeHttpOptions(5);
        $this->assertSame(5, $opts5['connect_timeout']);
        $this->assertSame(5, $opts5['timeout']);
        $this->assertSame(5, $opts5['curl'][CURLOPT_CONNECTTIMEOUT]);
        $this->assertSame(5, $opts5['curl'][CURLOPT_TIMEOUT]);

        // Pastikan tidak ada retry: 1 panggilan testConnection() hanya mengirim 1 request HTTP
        Http::swap(new HttpFactory());
        $attempts = 0;
        Http::fake([
            '*' => function () use (&$attempts) {
                $attempts++;
                throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received');
            },
        ]);

        $res = app(AiConfigService::class)->testConnection($this->baseCandidate(5));
        $this->assertSame(1, $attempts);
        $this->assertSame('upstream_ttfb_timeout', $res['timeout_phase']);
        $this->assertInstanceOf(\Closure::class, function () {
            $req = new Psr7Request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent');
            return fn () => new TransferStats($req);
        });
    }
}

