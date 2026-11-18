<?php

namespace App\Services;

use App\Models\AiProviderCredential;
use App\Models\AiSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AiConfigService
{
    public const CACHE_KEY = 'ai:global-config-meta:v1';

    /**
     * Optional test hook to override DNS resolution when testing SSRF / DNS rebinding.
     *
     * @var (callable(string): array<int, string>)|null
     */
    protected static $dnsResolver = null;

    /**
     * Registry of supported provider presets and their protocol metadata.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function providerPresets(bool $includeOptInLocal = false): array
    {
        $presets = [
            'google_ai_studio' => [
                'name' => 'Google AI Studio (Gemini)',
                'protocol' => 'gemini',
                'protocol_note' => 'Menggunakan Gemini REST API resmi Google AI Studio (/v1beta/models/{model}:generateContent & /v1beta/models) dengan header x-goog-api-key. Daftar model chat aktif diambil hanya dari model yang mendukung metode generateContent.',
                'default_base_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
                'default_model' => 'gemini-2.5-flash',
                'key_placeholder' => 'AIza...',
                'suggested_models' => [
                    'gemini-2.5-flash',
                    'gemini-2.5-flash-lite',
                    'gemini-2.5-pro',
                    'gemini-2.0-flash',
                    'gemini-2.0-flash-lite',
                ],
            ],
            'deepseek' => [
                'name' => 'DeepSeek Direct API',
                'protocol' => 'openai_compatible',
                'protocol_note' => 'Mendukung endpoint OpenAI-compatible resmi DeepSeek (/v1/chat/completions & /v1/models). Model generasi V4 menggunakan deepseek-flash / deepseek-v4-pro; alias deepseek-chat / deepseek-reasoner tetap disediakan untuk kompatibilitas.',
                'default_base_url' => 'https://api.deepseek.com/v1',
                'default_model' => 'deepseek-chat',
                'key_placeholder' => 'sk-...',
                'suggested_models' => [
                    'deepseek-chat',
                    'deepseek-reasoner',
                    'deepseek-flash',
                    'deepseek-v4-pro',
                ],
            ],
            'openai' => [
                'name' => 'OpenAI',
                'protocol' => 'openai_compatible',
                'protocol_note' => 'Menggunakan endpoint resmi OpenAI Chat Completions (/v1/chat/completions & /v1/models).',
                'default_base_url' => 'https://api.openai.com/v1',
                'default_model' => 'gpt-4o-mini',
                'key_placeholder' => 'sk-proj-...',
                'suggested_models' => [
                    'gpt-4o-mini',
                    'gpt-4o',
                    'gpt-4.1-mini',
                    'gpt-4.1',
                    'o4-mini',
                ],
            ],
            'openrouter' => [
                'name' => 'OpenRouter',
                'protocol' => 'openai_compatible',
                'protocol_note' => 'Gateway OpenAI-compatible lintas model (/api/v1/chat/completions & /api/v1/models).',
                'default_base_url' => 'https://openrouter.ai/api/v1',
                'default_model' => 'deepseek/deepseek-chat',
                'key_placeholder' => 'sk-or-...',
                'suggested_models' => [
                    'deepseek/deepseek-chat',
                    'openai/gpt-4o-mini',
                    'openai/gpt-4o',
                    'google/gemini-2.0-flash-001',
                    'anthropic/claude-3.5-sonnet',
                ],
            ],
            'groq' => [
                'name' => 'Groq',
                'protocol' => 'openai_compatible',
                'protocol_note' => 'Menggunakan endpoint OpenAI-compatible Groq (/openai/v1/chat/completions & /openai/v1/models). Untuk model reasoning seperti openai/gpt-oss-20b atau openai/gpt-oss-120b, disarankan Max Tokens >= 300.',
                'default_base_url' => 'https://api.groq.com/openai/v1',
                'default_model' => 'openai/gpt-oss-20b',
                'key_placeholder' => 'gsk_...',
                'suggested_models' => [
                    'openai/gpt-oss-20b',
                    'openai/gpt-oss-120b',
                    'llama-3.3-70b-versatile',
                    'llama-3.1-8b-instant',
                    'qwen/qwen3-32b',
                ],
            ],
            'custom' => [
                'name' => 'Custom OpenAI-Compatible',
                'protocol' => 'openai_compatible',
                'protocol_note' => 'Hanya untuk gateway/provider yang kompatibel dengan format OpenAI Chat Completions (Authorization: Bearer & JSON choices[0].message.content). Provider protokol native berbeda (mis. Gemini native atau Anthropic native) memerlukan adapter terpisah.',
                'default_base_url' => '',
                'default_model' => '',
                'key_placeholder' => 'Masukkan API Key provider...',
                'suggested_models' => [],
            ],
        ];

        if ($includeOptInLocal && (bool) config('services.ai.allow_local_ollama', false)) {
            $presets['ollama_local'] = [
                'name' => 'Ollama Lokal (Opt-In Khusus)',
                'protocol' => 'openai_compatible',
                'protocol_note' => 'Diaktifkan khusus via AI_ALLOW_LOCAL_OLLAMA=true. Hanya mengizinkan http://127.0.0.1:11434/v1 atau http://localhost:11434/v1.',
                'default_base_url' => 'http://127.0.0.1:11434/v1',
                'default_model' => 'llama3.2',
                'key_placeholder' => 'ollama',
                'suggested_models' => ['llama3.2', 'qwen2.5'],
            ];
        }

        return $presets;
    }

    public static function presetLabel(string $provider): string
    {
        $presets = self::providerPresets(true);

        return $presets[$provider]['name'] ?? strtoupper($provider);
    }

    public static function inferProviderFromUrl(?string $baseUrl): string
    {
        $url = strtolower((string) $baseUrl);

        return match (true) {
            str_contains($url, 'generativelanguage.googleapis.com') => 'google_ai_studio',
            str_contains($url, 'api.deepseek.com') => 'deepseek',
            str_contains($url, 'api.openai.com') => 'openai',
            str_contains($url, 'openrouter.ai') => 'openrouter',
            str_contains($url, 'api.groq.com') => 'groq',
            str_contains($url, ':11434') && (bool) config('services.ai.allow_local_ollama', false) => 'ollama_local',
            default => 'custom',
        };
    }

    public static function setDnsResolver(?callable $resolver): void
    {
        static::$dnsResolver = $resolver;
    }

    public function clearCache(): void
    {
        self::forgetCache();
    }

    public static function forgetCache(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // Abaikan kegagalan cache driver agar aplikasi tetap tangguh
        }
    }

    /**
     * Resolve global AI configuration (Database singleton first, config('services.ai.*') fallback).
     * Plaintext API Key is NEVER stored in Cache; only encrypted ciphertext is cached when DB record exists.
     *
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $cached = null;
        try {
            $cached = Cache::get(self::CACHE_KEY);
        } catch (\Throwable) {
            $cached = null;
        }

        if (is_array($cached) && ! empty($cached['exists']) && array_key_exists('credentials', $cached)) {
            return $this->hydrateFromDatabaseMeta($cached);
        }

        $record = $this->fetchDatabaseRecord();
        if ($record !== null) {
            $meta = [
                'exists' => true,
                'id' => $record->id,
                'provider' => $record->provider ?: 'custom',
                'label' => $record->label ?: self::presetLabel($record->provider ?: 'custom'),
                'base_url' => rtrim((string) $record->base_url, '/'),
                'model' => trim((string) $record->model),
                'legacy_api_key_ciphertext' => $record->getRawOriginal('api_key_encrypted'),
                'credentials' => $this->fetchProviderCredentialsMeta(),
                'timeout' => max(1, (int) ($record->timeout ?: 15)),
                'temperature' => max(0.0, min(2.0, (float) ($record->temperature ?? config('services.ai.temperature', 0.2)))),
                'max_tokens' => max(1, (int) ($record->max_tokens ?: 300)),
                'is_enabled' => (bool) $record->is_enabled,
                'last_tested_at' => $record->last_tested_at?->toIso8601String(),
                'last_test_status' => $record->last_test_status,
                'last_test_message' => $record->last_test_message,
                'updated_by_id' => $record->updated_by,
                'updated_by_name' => $record->updatedBy?->name,
                'updated_at' => $record->updated_at?->toIso8601String(),
            ];

            try {
                Cache::put(self::CACHE_KEY, $meta, now()->addMinutes(10));
            } catch (\Throwable) {
                // Jangan menggagalkan request bila cache store sedang tidak tersedia
            }

            return $this->hydrateFromDatabaseMeta($meta);
        }

        return $this->resolveFromDefaultConfig();
    }

    /**
     * Status publik aman untuk widget chatbot tanpa membocorkan provider/model/key/error internal.
     *
     * @return array{external_active: bool, label: string, tone: string}
     */
    public function getPublicStatus(): array
    {
        $resolved = $this->resolve();

        if (! $resolved['is_enabled'] || $resolved['status_code'] !== 'ready') {
            return [
                'external_active' => false,
                'label' => 'Mode Data Lokal',
                'tone' => 'amber',
            ];
        }

        return [
            'external_active' => true,
            'label' => 'Asisten Layanan Aktif',
            'tone' => 'emerald',
        ];
    }

    /**
     * Uji koneksi menggunakan konfigurasi kandidat (termasuk form sementara yang belum disimpan).
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public function testConnection(array $candidate): array
    {
        $provider = trim((string) ($candidate['provider'] ?? 'custom'));
        $baseUrl = trim((string) ($candidate['base_url'] ?? ''));
        $model = trim((string) ($candidate['model'] ?? ''));
        $timeout = max(5, min(120, (int) ($candidate['timeout'] ?? 15)));
        $requestedMaxTokens = max(1, (int) ($candidate['max_tokens'] ?? 300));
        // Model reasoning (seperti openai/gpt-oss-20b, R1, QwQ, o1/o3) menghabiskan token untuk reasoning sebelum menulis content.
        $maxTokens = $this->isReasoningModel($model)
            ? max(256, min(512, $requestedMaxTokens))
            : min(16, $requestedMaxTokens);
        $candidateKey = isset($candidate['api_key']) ? trim((string) $candidate['api_key']) : null;
        $resolvedKey = $this->resolveProviderKey($provider, $candidateKey);
        $apiKey = $resolvedKey['api_key'];
        $providerLabel = self::presetLabel($provider);

        if ($apiKey === null || $apiKey === '') {
            return [
                'ok' => false,
                'reason' => 'not_configured',
                'http_status' => null,
                'provider' => $provider,
                'provider_label' => $providerLabel,
                'model' => $model,
                'message' => "API Key untuk {$providerLabel} belum tersedia di database. Masukkan API Key terlebih dahulu untuk menguji koneksi.",
            ];
        }

        $result = $this->executeChatRequest(
            config: [
                'provider' => $provider,
                'base_url' => $baseUrl,
                'model' => $model,
                'api_key' => $apiKey,
                'timeout' => $timeout,
                'max_tokens' => $maxTokens,
            ],
            messages: [
                ['role' => 'user', 'content' => 'Jawab dengan satu kata: OK.'],
            ],
            temperature: 0.0,
            maxTokensOverride: $maxTokens,
            isConnectionTest: true
        );

        $providerLabel = self::presetLabel($provider);

        if ($result['ok']) {
            return [
                'ok' => true,
                'reason' => 'ok',
                'http_status' => $result['http_status'],
                'provider' => $provider,
                'provider_label' => $providerLabel,
                'model' => $model,
                'message' => "Koneksi berhasil ke {$providerLabel} menggunakan model {$model}.",
            ];
        }

        $friendlyMessage = match ($result['reason']) {
            'invalid_url' => $result['error_message'] ?? 'Base URL tidak valid atau ditolak oleh kebijakan keamanan.',
            'not_configured' => 'Konfigurasi provider, model, Base URL, atau API Key belum lengkap.',
            'bad_request' => ! empty($result['error_message'])
                ? "Permintaan ditolak penyedia (HTTP 400): {$result['error_message']}"
                : "Permintaan tidak kompatibel dengan endpoint/model '{$model}' (HTTP 400).",
            'invalid_key' => 'Autentikasi ditolak (HTTP 401): API Key tidak valid.',
            'forbidden' => 'Akses ditolak penyedia (HTTP 403): API Key ditolak atau tidak memiliki izin untuk model ini.',
            'model_not_found' => "Endpoint atau model '{$model}' tidak ditemukan (HTTP 404).",
            'rate_limited' => 'Batas permintaan terlampaui (HTTP 429): terlalu banyak permintaan atau kuota habis.',
            'server_error' => 'Server penyedia AI sedang mengalami gangguan (HTTP 5xx).',
            'redirect_blocked' => 'Permintaan ditolak karena endpoint melakukan redirect yang tidak diizinkan demi keamanan.',
            'timeout' => $result['error_message'] ?? "Koneksi melebihi batas waktu timeout ({$timeout} detik).",
            'invalid_json',
            'provider_error_body',
            'refusal',
            'tool_calls_only',
            'reasoning_token_limit',
            'reasoning_only_no_content',
            'token_limit_empty',
            'missing_choices',
            'empty_response' => $result['error_message']
                ?? 'Endpoint merespons HTTP 200 tetapi tidak mengembalikan konten jawaban format OpenAI-compatible (choices.0.message.content kosong).',
            default => ! empty($result['http_status'])
                ? "Gagal terhubung ke {$providerLabel} (HTTP {$result['http_status']})."
                : ($result['error_message'] ?? "Terjadi kendala teknis saat menghubungkan ke {$providerLabel}."),
        };

        return [
            'ok' => false,
            'reason' => $result['reason'],
            'timeout_phase' => $result['timeout_phase'] ?? null,
            'http_status' => $result['http_status'],
            'provider' => $provider,
            'provider_label' => $providerLabel,
            'model' => $model,
            'message' => self::sanitizeText($friendlyMessage, $apiKey),
        ];
    }

    /**
     * Eksekusi chat completion OpenAI-compatible dengan SSRF guard, redirect protection, dan sanitasi log.
     *
     * @param  array<string, mixed>  $config
     * @param  array<int, array<string, string>>  $messages
     * @return array{ok: bool, reason: string, http_status: ?int, content: ?string, error_message: ?string}
     */
    public function executeChatRequest(
        array $config,
        array $messages,
        float $temperature = 0.2,
        ?int $maxTokensOverride = null,
        bool $isConnectionTest = false
    ): array {
        $apiKey = isset($config['api_key']) ? trim((string) $config['api_key']) : '';
        $baseUrl = trim((string) ($config['base_url'] ?? ''));
        $model = trim((string) ($config['model'] ?? ''));
        $provider = trim((string) ($config['provider'] ?? 'custom'));
        $timeout = max(1, (int) ($config['timeout'] ?? 15));
        $connectTimeout = $this->resolveConnectTimeout($timeout);
        $maxTokens = max(1, (int) ($maxTokensOverride ?? $config['max_tokens'] ?? 300));

        if ($apiKey === '' || $baseUrl === '' || $model === '') {
            return [
                'ok' => false,
                'reason' => 'not_configured',
                'http_status' => null,
                'content' => null,
                'error_message' => 'API Key, Base URL, atau model belum terisi.',
            ];
        }

        $validation = $this->validateEndpointUrl($baseUrl, $provider);
        if (! $validation['ok']) {
            Log::warning('AI Endpoint Validation Rejected [' . $provider . ']: ' . self::sanitizeText($validation['error'], $apiKey));

            return [
                'ok' => false,
                'reason' => 'invalid_url',
                'http_status' => null,
                'content' => null,
                'error_message' => $validation['error'],
            ];
        }

        $endpoint = $this->isGeminiEndpoint($provider, (string) $validation['normalized_url'])
            ? $this->buildGeminiGenerateContentEndpoint((string) $validation['normalized_url'], $model)
            : $this->buildChatCompletionsEndpoint((string) $validation['normalized_url']);

        $isGemini = $this->isGeminiEndpoint($provider, (string) $validation['normalized_url']);
        $handlerStats = null;
        $httpOptions = $this->buildHttpOptions($validation, $timeout, function (array $stats) use (&$handlerStats): void {
            $handlerStats = $stats;
        });
        $payload = $isGemini
            ? $this->buildGeminiPayload($messages, $temperature, $maxTokens)
            : $this->buildChatPayload(
                provider: $provider,
                baseUrl: (string) $validation['normalized_url'],
                model: $model,
                messages: $messages,
                temperature: $temperature,
                maxTokens: $maxTokens,
                isConnectionTest: $isConnectionTest
            );

        try {
            $pendingRequest = Http::withOptions($httpOptions)
                ->connectTimeout($connectTimeout)
                ->timeout($timeout);

            $pendingRequest = $isGemini
                ? $pendingRequest->withHeaders(['x-goog-api-key' => $apiKey])
                : $pendingRequest->withToken($apiKey);

            $response = $pendingRequest->post($endpoint, $payload);

            $status = $response->status();

            if ($status >= 300 && $status < 400) {
                Log::warning("AI Service Blocked Redirect [HTTP {$status}] on provider [{$provider}] model [{$model}].");

                return [
                    'ok' => false,
                    'reason' => 'redirect_blocked',
                    'http_status' => $status,
                    'content' => null,
                    'error_message' => 'Redirect endpoint ditolak demi keamanan.',
                ];
            }

            if ($response->successful()) {
                return $isGemini
                    ? $this->parseGeminiSuccessfulResponse(
                        response: $response,
                        provider: $provider,
                        model: $model,
                        apiKey: $apiKey,
                        sentMaxTokens: $maxTokens
                    )
                    : $this->parseSuccessfulResponse(
                        response: $response,
                        provider: $provider,
                        model: $model,
                        apiKey: $apiKey,
                        sentMaxTokens: $maxTokens
                    );
            }

            $reason = match (true) {
                $status === 400 => 'bad_request',
                $status === 401 => 'invalid_key',
                $status === 403 => 'forbidden',
                $status === 404 => 'model_not_found',
                $status === 429 => 'rate_limited',
                $status >= 500 => 'server_error',
                default => 'unexpected_http',
            };

            if ($isGemini && $status === 400 && $this->isGeminiInvalidApiKeyResponse($response)) {
                $reason = 'invalid_key';
            }

            $safeBody = substr(self::sanitizeText($response->body(), $apiKey), 0, 300);
            Log::error("AI Service Error [HTTP {$status}] [{$reason}] on provider [{$provider}] model [{$model}]: {$safeBody}");

            $safeDiagnostic = $reason === 'bad_request'
                ? $this->extractSafeProviderError($response, $apiKey)
                : null;

            return [
                'ok' => false,
                'reason' => $reason,
                'http_status' => $status,
                'content' => null,
                'error_message' => $safeDiagnostic,
            ];
        } catch (ConnectionException $e) {
            $safeMessage = self::sanitizeText($e->getMessage(), $apiKey);
            $timeoutDiag = $this->classifyTimeoutPhase(
                exceptionMessage: $safeMessage,
                handlerStats: $handlerStats,
                providerLabel: self::presetLabel($provider),
                timeout: $timeout,
                connectTimeout: $connectTimeout
            );

            Log::error("AI Service Connection Timeout [timeout] [{$timeoutDiag['phase']}] on provider [{$provider}] model [{$model}]: {$safeMessage}");

            return [
                'ok' => false,
                'reason' => 'timeout',
                'timeout_phase' => $timeoutDiag['phase'],
                'http_status' => null,
                'content' => null,
                'error_message' => $timeoutDiag['message'],
            ];
        } catch (\Throwable $e) {
            $safeMessage = self::sanitizeText($e->getMessage(), $apiKey);
            Log::error('AI Service Technical Exception [' . get_class($e) . "]: {$safeMessage}");

            return [
                'ok' => false,
                'reason' => 'unexpected',
                'http_status' => null,
                'content' => null,
                'error_message' => null,
            ];
        }
    }

    /**
     * Validasi URL endpoint AI untuk mencegah SSRF, DNS rebinding, kredensial tertanam, dan skema tidak aman.
     *
     * @return array{ok: bool, normalized_url: ?string, host: ?string, port: int, resolved_ips: array<int, string>, is_literal_ip: bool, error: ?string}
     */
    public function validateEndpointUrl(string $url, string $provider = 'custom'): array
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return $this->urlFailure('Base URL wajib diisi.');
        }

        if (preg_match('/[\x00-\x1F\x7F\s]/', $trimmed) === 1) {
            return $this->urlFailure('Base URL tidak boleh mengandung spasi atau karakter kontrol.');
        }

        // Izin khusus yang eksplisit dan terbatas hanya jika Ollama lokal diaktifkan secara sadar lewat env
        if ($provider === 'ollama_local' && (bool) config('services.ai.allow_local_ollama', false)) {
            $normalizedLocal = rtrim($trimmed, '/');
            if (in_array($normalizedLocal, [
                'http://127.0.0.1:11434',
                'http://127.0.0.1:11434/v1',
                'http://localhost:11434',
                'http://localhost:11434/v1',
            ], true)) {
                $parts = parse_url($normalizedLocal);
                return [
                    'ok' => true,
                    'normalized_url' => $normalizedLocal,
                    'host' => strtolower((string) ($parts['host'] ?? '127.0.0.1')),
                    'port' => 11434,
                    'resolved_ips' => ['127.0.0.1'],
                    'is_literal_ip' => true,
                    'error' => null,
                ];
            }

            return $this->urlFailure('Mode Ollama lokal hanya mengizinkan http://127.0.0.1:11434/v1 atau http://localhost:11434/v1.');
        }

        $parts = parse_url($trimmed);
        if (! is_array($parts)) {
            return $this->urlFailure('Format Base URL tidak valid.');
        }

        if (array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            return $this->urlFailure('Base URL tidak boleh menyertakan username atau password.');
        }

        if (array_key_exists('query', $parts) || array_key_exists('fragment', $parts)) {
            return $this->urlFailure('Base URL tidak boleh menyertakan query string atau fragment.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            return $this->urlFailure('Endpoint AI eksternal wajib menggunakan protokol HTTPS.');
        }

        $rawHost = trim((string) ($parts['host'] ?? ''));
        $host = strtolower(rtrim($rawHost, '.'));
        if ($host === '') {
            return $this->urlFailure('Host pada Base URL tidak valid.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($port !== 443) {
            return $this->urlFailure('Port selain 443 tidak diizinkan untuk endpoint AI eksternal.');
        }

        if ($this->isForbiddenHostname($host)) {
            return $this->urlFailure('Host internal, localhost, atau layanan metadata tidak diizinkan.');
        }

        if ($this->isObfuscatedNumericHost($host)) {
            return $this->urlFailure('Format host numerik/oktal/heksadesimal tidak diizinkan.');
        }

        $path = isset($parts['path']) ? '/' . ltrim(rtrim((string) $parts['path'], '/'), '/') : '';
        if ($path === '/') {
            $path = '';
        }
        $normalizedUrl = "https://{$rawHost}{$path}";

        $literalCandidate = trim($host, '[]');
        if (filter_var($literalCandidate, FILTER_VALIDATE_IP) !== false) {
            if (! self::isPublicIp($literalCandidate)) {
                return $this->urlFailure('Alamat IP privat, loopback, link-local, atau metadata tidak diizinkan.');
            }

            return [
                'ok' => true,
                'normalized_url' => rtrim($normalizedUrl, '/'),
                'host' => $host,
                'port' => $port,
                'resolved_ips' => [$literalCandidate],
                'is_literal_ip' => true,
                'error' => null,
            ];
        }

        $resolvedIps = $this->resolveHostIps($host);

        foreach ($resolvedIps as $ip) {
            if (! self::isPublicIp($ip)) {
                return $this->urlFailure('Host Base URL mengarah ke alamat IP internal, loopback, link-local, atau metadata yang dilarang.');
            }
        }

        if ($resolvedIps === []) {
            $allowOfflineFakedTests = static::$dnsResolver === null
                && app()->runningUnitTests()
                && $this->isHttpFaking();

            if (! $allowOfflineFakedTests) {
                return $this->urlFailure('Host Base URL tidak dapat di-resolve ke alamat IP publik yang valid.');
            }
        }

        return [
            'ok' => true,
            'normalized_url' => rtrim($normalizedUrl, '/'),
            'host' => $host,
            'port' => $port,
            'resolved_ips' => $resolvedIps,
            'is_literal_ip' => false,
            'error' => null,
        ];
    }

    public function buildChatCompletionsEndpoint(string $baseUrl): string
    {
        $trimmed = rtrim(trim($baseUrl), '/');
        if (str_ends_with(strtolower($trimmed), '/models')) {
            $trimmed = rtrim(substr($trimmed, 0, -strlen('/models')), '/');
        }
        if (str_ends_with(strtolower($trimmed), '/chat/completions')) {
            return $trimmed;
        }

        $path = (string) (parse_url($trimmed, PHP_URL_PATH) ?? '');
        if ($path === '' || $path === '/') {
            return "{$trimmed}/v1/chat/completions";
        }

        return "{$trimmed}/chat/completions";
    }

    public function isGeminiEndpoint(string $provider, string $baseUrl = ''): bool
    {
        return strtolower(trim($provider)) === 'google_ai_studio'
            || str_contains(strtolower($baseUrl), 'generativelanguage.googleapis.com');
    }

    protected function normalizeGeminiApiRoot(string $baseUrl): string
    {
        $trimmed = rtrim(trim($baseUrl), '/');
        $trimmed = preg_replace('/\/models(?:\/[^\/]+)?(?::generateContent)?$/i', '', $trimmed) ?? $trimmed;
        $trimmed = rtrim($trimmed, '/');

        $path = (string) (parse_url($trimmed, PHP_URL_PATH) ?? '');
        if ($path === '' || $path === '/') {
            return "{$trimmed}/v1beta";
        }

        return $trimmed;
    }

    public function buildGeminiGenerateContentEndpoint(string $baseUrl, string $model): string
    {
        $root = $this->normalizeGeminiApiRoot($baseUrl);
        $cleanModel = preg_replace('/^models\//i', '', trim($model)) ?? trim($model);

        return "{$root}/models/" . rawurlencode($cleanModel) . ':generateContent';
    }

    public function buildModelsEndpoint(string $baseUrl, string $provider = 'custom'): string
    {
        if ($this->isGeminiEndpoint($provider, $baseUrl)) {
            return $this->normalizeGeminiApiRoot($baseUrl) . '/models';
        }

        $trimmed = rtrim(trim($baseUrl), '/');
        if (str_ends_with(strtolower($trimmed), '/chat/completions')) {
            $trimmed = substr($trimmed, 0, -strlen('/chat/completions'));
        }
        if (str_ends_with(strtolower($trimmed), '/models')) {
            return $trimmed;
        }

        $path = (string) (parse_url($trimmed, PHP_URL_PATH) ?? '');
        if ($path === '' || $path === '/') {
            return "{$trimmed}/v1/models";
        }

        return "{$trimmed}/models";
    }

    /**
     * Daftar model terstruktur untuk dropdown Supervisor (menggabungkan hasil API live bila tersedia + preset lokal).
     *
     * @param  array<int, array<string, mixed>>  $liveModels
     * @return array<int, array{id: string, label: string, source: string, is_reasoning: bool}>
     */
    public function modelsForProvider(string $provider, array $liveModels = []): array
    {
        $presets = self::providerPresets(true);
        $presetIds = $presets[$provider]['suggested_models'] ?? [];

        $indexed = [];

        // Jika hasil live API ada, tampilkan sebagai daftar utama terurut, lalu tambahkan flag reasoning
        if ($liveModels !== []) {
            foreach ($liveModels as $item) {
                $id = trim((string) ($item['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $indexed[$id] = [
                    'id' => $id,
                    'label' => (string) ($item['label'] ?? $id),
                    'source' => (string) ($item['source'] ?? 'api'),
                    'is_reasoning' => $this->isReasoningModel($id),
                ];
            }
        } else {
            foreach ($presetIds as $id) {
                $id = trim((string) $id);
                if ($id === '') {
                    continue;
                }
                $indexed[$id] = [
                    'id' => $id,
                    'label' => $id,
                    'source' => 'preset',
                    'is_reasoning' => $this->isReasoningModel($id),
                ];
            }
        }

        return array_values($indexed);
    }

    /**
     * Ambil daftar model chat yang tersedia dari endpoint /models provider secara aman (anti-SSRF & tanpa bocor API Key).
     * Bila endpoint /models tidak tersedia/gagal, fallback aman ke daftar preset lokal.
     *
     * @param  array<string, mixed>  $candidate
     * @return array{ok: bool, source: string, models: array<int, array{id: string, label: string, source: string, is_reasoning: bool}>, message: string}
     */
    public function fetchAvailableModels(array $candidate): array
    {
        $provider = trim((string) ($candidate['provider'] ?? 'custom'));
        $baseUrl = trim((string) ($candidate['base_url'] ?? ''));
        $timeout = max(5, min(20, (int) ($candidate['timeout'] ?? 15)));
        $presetFallback = $this->modelsForProvider($provider);

        $validation = $this->validateEndpointUrl($baseUrl, $provider);
        if (! $validation['ok']) {
            return [
                'ok' => false,
                'source' => 'preset',
                'models' => $presetFallback,
                'message' => (string) ($validation['error'] ?? 'Base URL tidak valid.'),
            ];
        }

        $candidateKey = isset($candidate['api_key']) ? trim((string) $candidate['api_key']) : null;
        $apiKey = $this->resolveProviderKey($provider, $candidateKey)['api_key'];

        if (($apiKey === null || $apiKey === '') && $provider !== 'openrouter' && $provider !== 'ollama_local') {
            return [
                'ok' => false,
                'source' => 'preset',
                'models' => $presetFallback,
                'message' => 'Menggunakan daftar preset lokal. Masukkan atau simpan API Key untuk memuat daftar model langsung dari API provider.',
            ];
        }

        $baseUrlNormalized = (string) $validation['normalized_url'];
        $isGemini = $this->isGeminiEndpoint($provider, $baseUrlNormalized);
        $endpoint = $this->buildModelsEndpoint($baseUrlNormalized, $provider);
        $httpOptions = $this->buildHttpOptions($validation);

        try {
            $request = Http::withOptions($httpOptions)->timeout($timeout);
            if ($apiKey !== null && $apiKey !== '') {
                $request = $isGemini
                    ? $request->withHeaders(['x-goog-api-key' => $apiKey])
                    : $request->withToken($apiKey);
            }

            $response = $request->get($endpoint);
            $status = $response->status();

            if ($status >= 300 && $status < 400) {
                return [
                    'ok' => false,
                    'source' => 'preset',
                    'models' => $presetFallback,
                    'message' => 'Endpoint daftar model melakukan redirect yang diblokir demi keamanan. Menampilkan daftar preset lokal.',
                ];
            }

            if (! $response->successful()) {
                $providerLabel = self::presetLabel($provider);
                Log::warning("AI Models Fetch Failed [HTTP {$status}] on provider [{$provider}].");

                return [
                    'ok' => false,
                    'source' => 'preset',
                    'models' => $presetFallback,
                    'message' => "Endpoint daftar model {$providerLabel} merespons HTTP {$status}; menampilkan daftar preset lokal.",
                ];
            }

            $rawList = $isGemini ? $response->json('models') : $response->json('data');
            if (! is_array($rawList)) {
                return [
                    'ok' => false,
                    'source' => 'preset',
                    'models' => $presetFallback,
                    'message' => $isGemini
                        ? 'Respons endpoint Gemini /models tidak memiliki format daftar models yang valid; menampilkan daftar preset lokal.'
                        : 'Respons endpoint /models tidak memiliki format daftar OpenAI-compatible; menampilkan daftar preset lokal.',
                ];
            }

            $filtered = [];
            foreach ($rawList as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                if ($isGemini) {
                    $rawName = trim((string) ($entry['name'] ?? $entry['id'] ?? ''));
                    $id = preg_replace('/^models\//i', '', $rawName);
                    $id = is_string($id) ? trim($id) : '';
                    if ($id === '' || ! $this->isGeminiGenerateContentCapableModel($id, $entry)) {
                        continue;
                    }
                    $displayName = trim((string) ($entry['displayName'] ?? ''));
                    $label = ($displayName !== '' && $displayName !== $id) ? "{$id} — {$displayName}" : $id;
                } else {
                    $id = trim((string) ($entry['id'] ?? ''));
                    if ($id === '' || ! $this->isChatCompletionCapableModel($id, $entry, $provider)) {
                        continue;
                    }
                    $displayName = trim((string) ($entry['name'] ?? ''));
                    $label = ($displayName !== '' && $displayName !== $id) ? "{$id} — {$displayName}" : $id;
                }

                $filtered[$id] = [
                    'id' => $id,
                    'label' => $label,
                    'source' => 'api',
                    'is_reasoning' => $this->isReasoningModel($id),
                ];
            }

            if ($filtered === []) {
                return [
                    'ok' => false,
                    'source' => 'preset',
                    'models' => $presetFallback,
                    'message' => 'API provider tidak mengembalikan model chat completion yang cocok; menampilkan daftar preset lokal.',
                ];
            }

            // Urutkan agar model preset yang tersedia tetap mudah ditemukan di atas, diikuti model API lain secara alfabetis
            $presets = self::providerPresets(true);
            $priorityIds = $presets[$provider]['suggested_models'] ?? [];
            $ordered = [];
            foreach ($priorityIds as $pid) {
                if (isset($filtered[$pid])) {
                    $ordered[$pid] = $filtered[$pid];
                    unset($filtered[$pid]);
                }
            }
            ksort($filtered);
            $finalModels = array_values($ordered + $filtered);

            return [
                'ok' => true,
                'source' => 'api',
                'models' => $finalModels,
                'message' => 'Berhasil memuat ' . count($finalModels) . ' model chat completion dari API ' . self::presetLabel($provider) . '.',
            ];
        } catch (ConnectionException $e) {
            Log::warning('AI Models Fetch Timeout [' . $provider . ']: ' . self::sanitizeText($e->getMessage(), $apiKey));

            return [
                'ok' => false,
                'source' => 'preset',
                'models' => $presetFallback,
                'message' => 'Koneksi saat memuat daftar model melebihi batas waktu; menampilkan daftar preset lokal.',
            ];
        } catch (\Throwable $e) {
            Log::warning('AI Models Fetch Exception [' . $provider . ']: ' . self::sanitizeText($e->getMessage(), $apiKey));

            return [
                'ok' => false,
                'source' => 'preset',
                'models' => $presetFallback,
                'message' => 'Gagal memuat daftar model dari API provider; menampilkan daftar preset lokal.',
            ];
        }
    }

    /**
     * Saring model dari /models agar hanya menyertakan model yang dapat digunakan untuk chat completion.
     *
     * @param  array<string, mixed>  $entry
     */
    public function isChatCompletionCapableModel(string $id, array $entry = [], string $provider = 'custom'): bool
    {
        $lower = strtolower(trim($id));
        if ($lower === '') {
            return false;
        }

        // Groq menandai model nonaktif dengan active = false
        if (array_key_exists('active', $entry) && $entry['active'] === false) {
            return false;
        }

        // OpenRouter menyertakan output_modalities; pastikan bisa menghasilkan teks
        $outputModalities = data_get($entry, 'architecture.output_modalities');
        if (is_array($outputModalities) && $outputModalities !== [] && ! in_array('text', $outputModalities, true)) {
            return false;
        }

        $nonChatPatterns = [
            'whisper', 'distil-whisper', 'tts', 'dall-e', 'gpt-image', 'chatgpt-image',
            'embedding', 'embed-', '-embed', 'rerank', 'moderation', 'omni-moderation',
            'guard', 'safeguard', 'playai', 'orpheus', 'sora', 'realtime', 'transcribe',
            'babbage', 'davinci', 'clip', 'stable-diffusion', 'flux', 'sdxl',
            'deplot', 'paligemma', 'paddleocr', 'nv-grounding', '-ocr', '-parse',
            '-instruct-0914', 'computer-use', 'codex-mini',
        ];

        foreach ($nonChatPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return false;
            }
        }

        if ($provider === 'openai') {
            if (str_contains($lower, 'instruct') || str_contains($lower, '-audio') || str_contains($lower, '-search')) {
                return false;
            }

            return preg_match('/^(?:gpt-|o[134](?:-|$)|chatgpt-)/i', $lower) === 1;
        }

        return true;
    }

    /**
     * Cek apakah IP adalah alamat publik yang aman (blokir privat, loopback, link-local/metadata, CGNAT, multicast, IPv4-mapped).
     */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            $long = unpack('N', $packed)[1] & 0xFFFFFFFF;
            $blockedCidrs = [
                [0x00000000, 0x00FFFFFF], // 0.0.0.0/8
                [0x0A000000, 0x0AFFFFFF], // 10.0.0.0/8
                [0x64400000, 0x647FFFFF], // 100.64.0.0/10 (CGNAT)
                [0x7F000000, 0x7FFFFFFF], // 127.0.0.0/8 (loopback)
                [0xA9FE0000, 0xA9FEFFFF], // 169.254.0.0/16 (link-local & cloud metadata)
                [0xAC100000, 0xAC1FFFFF], // 172.16.0.0/12
                [0xC0000000, 0xC00000FF], // 192.0.0.0/24
                [0xC0000200, 0xC00002FF], // 192.0.2.0/24
                [0xC0A80000, 0xC0A8FFFF], // 192.168.0.0/16
                [0xC6120000, 0xC613FFFF], // 198.18.0.0/15
                [0xC6336400, 0xC63364FF], // 198.51.100.0/24
                [0xCB007100, 0xCB0071FF], // 203.0.113.0/24
                [0xE0000000, 0xFFFFFFFF], // 224.0.0.0/4+ multicast & reserved
            ];

            foreach ($blockedCidrs as [$start, $end]) {
                if ($long >= $start && $long <= $end) {
                    return false;
                }
            }

            return true;
        }

        if (strlen($packed) === 16) {
            // IPv4-mapped IPv6 (::ffff:0:0/96) atau IPv4-compatible (::/96)
            $prefix12 = substr($packed, 0, 12);
            if (
                $prefix12 === str_repeat("\x00", 10) . "\xff\xff"
                || $prefix12 === str_repeat("\x00", 12)
            ) {
                $mappedV4 = @inet_ntop(substr($packed, 12, 4));
                if ($mappedV4 === false || $mappedV4 === '0.0.0.0') {
                    return false;
                }

                return self::isPublicIp($mappedV4);
            }

            $firstByte = ord($packed[0]);
            $secondByte = ord($packed[1]);

            // fc00::/7 ULA, fe80::/10 link-local, ff00::/8 multicast
            if (($firstByte & 0xFE) === 0xFC) {
                return false;
            }
            if ($firstByte === 0xFE && ($secondByte & 0xC0) === 0x80) {
                return false;
            }
            if ($firstByte === 0xFF) {
                return false;
            }

            // ::/128 dan ::1/128
            if ($packed === str_repeat("\x00", 16) || $packed === str_repeat("\x00", 15) . "\x01") {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Sanitasi teks log/error agar API Key aktif, Bearer token, maupun pola token provider lain tidak pernah tercatat.
     */
    public static function sanitizeText(?string $text, ?string $activeKey = null): string
    {
        $clean = strip_tags((string) $text);
        if ($clean === '') {
            return '';
        }

        if (is_string($activeKey) && trim($activeKey) !== '') {
            $trimmedKey = trim($activeKey);
            $clean = str_replace(
                [$trimmedKey, urlencode($trimmedKey), rawurlencode($trimmedKey)],
                '[PROTECTED_KEY]',
                $clean
            );
        }

        $patterns = [
            '/sk-or-[A-Za-z0-9_-]+/i' => '[PROTECTED_TOKEN]',
            '/sk-ant-[A-Za-z0-9_-]+/i' => '[PROTECTED_TOKEN]',
            '/sk-proj-[A-Za-z0-9_-]+/i' => '[PROTECTED_TOKEN]',
            '/sk-[A-Za-z0-9_-]{8,}/i' => '[PROTECTED_TOKEN]',
            '/gsk_[A-Za-z0-9_-]+/i' => '[PROTECTED_TOKEN]',
            '/AIza[A-Za-z0-9_-]{10,}/' => '[PROTECTED_TOKEN]',
            '/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i' => '$1[PROTECTED_TOKEN]',
            '/((?:authorization|x-goog-api-key|x-api-key|api[-_]?key)\s*[:=]\s*["\']?)[A-Za-z0-9._~+\/=-]+/i' => '$1[PROTECTED_TOKEN]',
            '/([?&](?:api_key|key|token)=)[^&\s]+/i' => '$1[PROTECTED_TOKEN]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $clean = (string) preg_replace($pattern, $replacement, $clean);
        }

        return trim($clean);
    }

    /**
     * Resolve API key untuk provider tertentu secara Database-Only (tanpa fallback .env):
     * 1. $candidateKey (input form sementara yang belum disimpan)
     * 2. Kredensial database terenkripsi milik provider terpilih (`ai_provider_credentials`)
     * 3. Jika key_cleared_explicitly = true, hentikan dan kembalikan null
     * 4. Tidak tersedia -> kembalikan null dengan source = 'none'
     *
     * @param  array<string, array{api_key_ciphertext?: ?string, key_cleared_explicitly?: bool}>|null  $cachedCredentials
     * @return array{api_key: ?string, source: string, has_db_key: bool, key_cleared_explicitly: bool, masked_api_key: ?string}
     */
    public function resolveProviderKey(string $provider, ?string $candidateKey = null, ?array $cachedCredentials = null): array
    {
        $normalizedProvider = strtolower(trim($provider)) ?: 'custom';

        $credMeta = null;
        if ($cachedCredentials !== null) {
            $credMeta = $cachedCredentials[$normalizedProvider] ?? null;
        } else {
            $credMeta = $this->fetchSingleProviderCredentialMeta($normalizedProvider);
        }

        $dbCiphertext = is_array($credMeta) ? ($credMeta['api_key_ciphertext'] ?? null) : null;
        $isClearedExplicitly = is_array($credMeta) && ! empty($credMeta['key_cleared_explicitly']);
        $dbApiKey = $this->decryptCiphertext(is_string($dbCiphertext) ? $dbCiphertext : null);
        $hasDbKey = $dbApiKey !== null;

        $trimmedCandidate = is_string($candidateKey) ? trim($candidateKey) : '';
        if ($trimmedCandidate !== '') {
            return [
                'api_key' => $trimmedCandidate,
                'source' => 'candidate',
                'has_db_key' => $hasDbKey,
                'key_cleared_explicitly' => $isClearedExplicitly,
                'masked_api_key' => AiSetting::maskSecret($trimmedCandidate),
            ];
        }

        if ($hasDbKey) {
            return [
                'api_key' => $dbApiKey,
                'source' => 'database',
                'has_db_key' => true,
                'key_cleared_explicitly' => false,
                'masked_api_key' => AiSetting::maskSecret($dbApiKey),
            ];
        }

        if ($isClearedExplicitly) {
            return [
                'api_key' => null,
                'source' => 'none',
                'has_db_key' => false,
                'key_cleared_explicitly' => true,
                'masked_api_key' => null,
            ];
        }

        return [
            'api_key' => null,
            'source' => 'none',
            'has_db_key' => false,
            'key_cleared_explicitly' => false,
            'masked_api_key' => null,
        ];
    }

    /**
     * @return array<string, array{api_key_ciphertext: ?string, key_cleared_explicitly: bool}>
     */
    protected function fetchProviderCredentialsMeta(): array
    {
        try {
            if (! Schema::hasTable('ai_provider_credentials')) {
                return [];
            }

            $rows = AiProviderCredential::query()->get(['id', 'provider', 'api_key_encrypted', 'key_cleared_explicitly']);
            $map = [];
            foreach ($rows as $row) {
                $provider = strtolower(trim((string) $row->provider));
                if ($provider === '') {
                    continue;
                }
                $map[$provider] = [
                    'api_key_ciphertext' => $row->getRawOriginal('api_key_encrypted'),
                    'key_cleared_explicitly' => (bool) $row->key_cleared_explicitly,
                ];
            }

            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{api_key_ciphertext: ?string, key_cleared_explicitly: bool}|null
     */
    protected function fetchSingleProviderCredentialMeta(string $provider): ?array
    {
        try {
            if (! Schema::hasTable('ai_provider_credentials')) {
                return null;
            }

            $row = AiProviderCredential::query()
                ->where('provider', $provider)
                ->first(['id', 'provider', 'api_key_encrypted', 'key_cleared_explicitly']);

            if ($row === null) {
                return null;
            }

            return [
                'api_key_ciphertext' => $row->getRawOriginal('api_key_encrypted'),
                'key_cleared_explicitly' => (bool) $row->key_cleared_explicitly,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function hydrateFromDatabaseMeta(array $meta): array
    {
        $provider = strtolower(trim((string) ($meta['provider'] ?? 'custom'))) ?: 'custom';
        $cachedCredentials = is_array($meta['credentials'] ?? null) ? $meta['credentials'] : [];
        $resolvedKey = $this->resolveProviderKey($provider, null, $cachedCredentials);

        $apiKey = $resolvedKey['api_key'];
        $keySource = $resolvedKey['source'];
        $hasDbKey = $resolvedKey['has_db_key'];
        $keyClearedExplicitly = $resolvedKey['key_cleared_explicitly'];

        // Kompatibilitas mundur sementara bila kolom legacy ai_settings.api_key_encrypted terisi tanpa event model
        if (
            $apiKey === null
            && ! $keyClearedExplicitly
            && ! array_key_exists($provider, $cachedCredentials)
            && ! empty($meta['legacy_api_key_ciphertext'])
        ) {
            $legacyKey = $this->decryptCiphertext((string) $meta['legacy_api_key_ciphertext']);
            if ($legacyKey !== null) {
                $apiKey = $legacyKey;
                $keySource = 'database';
                $hasDbKey = true;
            }
        }

        $isEnabled = (bool) ($meta['is_enabled'] ?? true);
        $baseUrl = rtrim((string) ($meta['base_url'] ?? ''), '/');
        $model = trim((string) ($meta['model'] ?? ''));

        $statusCode = match (true) {
            ! $isEnabled => 'disabled',
            empty($apiKey) || $baseUrl === '' || $model === '' => 'not_configured',
            default => 'ready',
        };

        return [
            'source' => 'database',
            'key_source' => $keySource,
            'has_db_key' => $hasDbKey,
            'key_cleared_explicitly' => $keyClearedExplicitly,
            'provider' => $provider,
            'label' => (string) ($meta['label'] ?: self::presetLabel($provider)),
            'base_url' => $baseUrl,
            'model' => $model,
            'api_key' => $apiKey,
            'has_api_key' => $apiKey !== null,
            'masked_api_key' => AiSetting::maskSecret($apiKey),
            'temperature' => max(0.0, min(2.0, (float) ($meta['temperature'] ?? 0.2))),
            'timeout' => max(1, (int) ($meta['timeout'] ?? 15)),
            'max_tokens' => max(1, (int) ($meta['max_tokens'] ?? 300)),
            'is_enabled' => $isEnabled,
            'is_configured' => $statusCode === 'ready',
            'status_code' => $statusCode,
            'last_tested_at' => $meta['last_tested_at'] ?? null,
            'last_test_status' => $meta['last_test_status'] ?? null,
            'last_test_message' => $meta['last_test_message'] ?? null,
            'updated_by_id' => $meta['updated_by_id'] ?? null,
            'updated_by_name' => $meta['updated_by_name'] ?? null,
            'updated_at' => $meta['updated_at'] ?? null,
        ];
    }

    /**
     * Fallback metadata non-kredensial (URL/model/timeout default) bila tabel ai_settings belum memiliki baris.
     * API Key tetap bersumber eksklusif dari database ai_provider_credentials (tanpa membaca .env).
     *
     * @return array<string, mixed>
     */
    protected function resolveFromDefaultConfig(): array
    {
        $baseUrl = rtrim((string) config('services.ai.base_url', 'https://generativelanguage.googleapis.com/v1beta/models'), '/');
        $model = trim((string) config('services.ai.model', 'gemini-2.5-flash'));
        $temperature = max(0.0, min(2.0, (float) config('services.ai.temperature', 0.2)));
        $timeout = max(1, (int) config('services.ai.timeout', 15));
        $maxTokens = max(1, (int) config('services.ai.max_tokens', 300));
        $provider = self::inferProviderFromUrl($baseUrl);

        $resolvedKey = $this->resolveProviderKey($provider);
        $apiKey = $resolvedKey['api_key'];

        $statusCode = ($apiKey === null || $baseUrl === '' || $model === '')
            ? 'not_configured'
            : 'ready';

        return [
            'source' => 'default',
            'key_source' => $resolvedKey['source'],
            'has_db_key' => $resolvedKey['has_db_key'],
            'key_cleared_explicitly' => $resolvedKey['key_cleared_explicitly'],
            'provider' => $provider,
            'label' => self::presetLabel($provider),
            'base_url' => $baseUrl,
            'model' => $model,
            'api_key' => $apiKey,
            'has_api_key' => $apiKey !== null,
            'masked_api_key' => AiSetting::maskSecret($apiKey),
            'temperature' => $temperature,
            'timeout' => $timeout,
            'max_tokens' => $maxTokens,
            'is_enabled' => true,
            'is_configured' => $statusCode === 'ready',
            'status_code' => $statusCode,
            'last_tested_at' => null,
            'last_test_status' => null,
            'last_test_message' => null,
            'updated_by_id' => null,
            'updated_by_name' => null,
            'updated_at' => null,
        ];
    }

    protected function fetchDatabaseRecord(): ?AiSetting
    {
        try {
            return AiSetting::query()
                ->with('updatedBy:id,name')
                ->where('singleton_key', AiSetting::SINGLETON_KEY)
                ->first()
                ?? AiSetting::query()->with('updatedBy:id,name')->latest('updated_at')->first();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function decryptCiphertext(?string $ciphertext): ?string
    {
        if (! is_string($ciphertext) || trim($ciphertext) === '') {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($ciphertext);

            return trim($decrypted) !== '' ? trim($decrypted) : null;
        } catch (DecryptException $e) {
            Log::warning('AI Config gagal mendekripsi API Key dari cache/database: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    protected function resolveHostIps(string $host): array
    {
        if (static::$dnsResolver !== null) {
            $custom = (static::$dnsResolver)($host);

            return array_values(array_filter(array_unique(array_map('strval', $custom)), static fn ($ip) => $ip !== ''));
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (! empty($record['ip'])) {
                    $ips[] = (string) $record['ip'];
                }
                if (! empty($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                $ips[] = (string) $ip;
            }
        }

        return array_values(array_unique(array_filter($ips, static fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false)));
    }

    protected function isForbiddenHostname(string $host): bool
    {
        $exact = [
            'localhost',
            'localhost.localdomain',
            'ip6-localhost',
            'ip6-loopback',
            'metadata',
            'metadata.google.internal',
            'metadata.goog',
            'instance-data',
        ];

        if (in_array($host, $exact, true)) {
            return true;
        }

        foreach (['.local', '.localhost', '.localdomain', '.internal', '.arpa', '.intranet', '.lan'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    protected function isObfuscatedNumericHost(string $host): bool
    {
        if (preg_match('/^(0x[0-9a-f]+|\d+)$/i', $host) === 1) {
            return true;
        }

        // Contoh IP oktal/heksadesimal per segmen seperti 0177.0.0.1 atau 0x7f.0.0.1
        if (preg_match('/^(?:0[0-7]+|0x[0-9a-f]+)(?:\.(?:0[0-7]+|0x[0-9a-f]+|\d+)){1,3}$/i', $host) === 1) {
            return true;
        }

        return false;
    }

    public function resolveConnectTimeout(int $totalTimeout): int
    {
        return max(1, min(10, max(1, $totalTimeout)));
    }

    /**
     * @param  array<string, mixed>  $validation
     * @param  (callable(array<string, mixed>): void)|null  $statsCollector
     * @return array<string, mixed>
     */
    protected function buildHttpOptions(array $validation, int $timeout = 15, ?callable $statsCollector = null): array
    {
        $totalTimeout = max(1, $timeout);
        $connectTimeout = $this->resolveConnectTimeout($totalTimeout);

        $options = [
            'allow_redirects' => false,
            'connect_timeout' => $connectTimeout,
            'timeout' => $totalTimeout,
        ];

        $curlOptions = [];
        if (defined('CURLOPT_CONNECTTIMEOUT')) {
            $curlOptions[CURLOPT_CONNECTTIMEOUT] = $connectTimeout;
        }
        if (defined('CURLOPT_TIMEOUT')) {
            $curlOptions[CURLOPT_TIMEOUT] = $totalTimeout;
        }

        if (
            ! empty($validation['resolved_ips'])
            && empty($validation['is_literal_ip'])
            && ! $this->isHttpFaking()
            && defined('CURLOPT_RESOLVE')
        ) {
            $host = (string) $validation['host'];
            $port = (int) $validation['port'];
            $formattedIps = implode(',', $validation['resolved_ips']);
            $curlOptions[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$formattedIps}"];
        }

        if ($curlOptions !== []) {
            $options['curl'] = $curlOptions;
        }

        if ($statsCollector !== null) {
            $options['on_stats'] = static function (\GuzzleHttp\TransferStats $stats) use ($statsCollector): void {
                $statsCollector($stats->getHandlerStats());
            };
        }

        return $options;
    }

    /**
     * Klasifikasikan fase timeout menjadi connect_timeout vs upstream_ttfb_timeout vs upstream_body_timeout
     * tanpa pernah membocorkan kredensial atau payload pengguna.
     *
     * @param  array<string, mixed>|null  $handlerStats
     * @return array{phase: string, message: string}
     */
    protected function classifyTimeoutPhase(
        string $exceptionMessage,
        ?array $handlerStats,
        string $providerLabel,
        int $timeout,
        int $connectTimeout
    ): array {
        if (is_array($handlerStats) && $handlerStats !== []) {
            $connectTime = (float) ($handlerStats['connect_time'] ?? 0.0);
            $appConnectTime = (float) ($handlerStats['appconnect_time'] ?? 0.0);
            $pretransferTime = (float) ($handlerStats['pretransfer_time'] ?? 0.0);
            $startTransferTime = (float) ($handlerStats['starttransfer_time'] ?? 0.0);
            $bytesReceived = (int) ($handlerStats['size_download'] ?? 0);

            if ($connectTime <= 0.0) {
                return [
                    'phase' => 'connect_timeout',
                    'message' => "Gagal membuka koneksi jaringan ke {$providerLabel} dalam batas connect timeout ({$connectTimeout} detik).",
                ];
            }

            if (($appConnectTime > 0.0 || $pretransferTime > 0.0) && $pretransferTime > 0.0 && $startTransferTime <= 0.0) {
                return [
                    'phase' => 'upstream_ttfb_timeout',
                    'message' => "Koneksi TCP/TLS dan permintaan berhasil terkirim ke {$providerLabel}, tetapi server AI upstream tidak merespons hingga batas timeout ({$timeout} detik; {$bytesReceived} byte diterima).",
                ];
            }

            if ($startTransferTime > 0.0) {
                return [
                    'phase' => 'upstream_body_timeout',
                    'message' => "Server {$providerLabel} mulai merespons tetapi tidak menyelesaikan pengiriman jawaban dalam batas timeout ({$timeout} detik).",
                ];
            }
        }

        if (preg_match('/(?:Failed to connect|Connection timed out|Resolving timed out|Could not resolve|SSL connection timeout|cURL error (?:6|7|35)\b)/i', $exceptionMessage) === 1) {
            return [
                'phase' => 'connect_timeout',
                'message' => "Gagal membuka koneksi jaringan ke {$providerLabel} dalam batas connect timeout ({$connectTimeout} detik).",
            ];
        }

        if (preg_match('/Operation timed out after \d+ milliseconds with 0 bytes received/i', $exceptionMessage) === 1) {
            return [
                'phase' => 'upstream_ttfb_timeout',
                'message' => "Permintaan terkirim ke {$providerLabel}, tetapi server AI upstream tidak merespons (0 byte diterima) hingga batas timeout ({$timeout} detik).",
            ];
        }

        if (preg_match('/Operation timed out after \d+ milliseconds with [1-9]\d* (?:out of \d+ )?bytes received/i', $exceptionMessage) === 1) {
            return [
                'phase' => 'upstream_body_timeout',
                'message' => "Server {$providerLabel} mulai merespons tetapi tidak menyelesaikan pengiriman jawaban dalam batas timeout ({$timeout} detik).",
            ];
        }

        return [
            'phase' => 'timeout',
            'message' => "Koneksi melebihi batas waktu timeout ({$timeout} detik).",
        ];
    }

    public function isReasoningModel(string $model): bool
    {
        $lower = strtolower(trim($model));
        if ($lower === '') {
            return false;
        }

        if ($this->isOpenAiReasoningModel($lower)) {
            return true;
        }

        // Model Qwen3 umumnya mampu menghasilkan blok thinking, kecuali varian eksplisit -instruct / -coder
        if (str_contains($lower, 'qwen3') && ! str_contains($lower, '-instruct') && ! str_contains($lower, '-coder')) {
            return true;
        }

        return str_contains($lower, 'gpt-oss')
            || str_contains($lower, 'gemini-2.5')
            || str_contains($lower, 'deepseek-reasoner')
            || str_contains($lower, 'deepseek-r1')
            || str_contains($lower, 'qwq');
    }

    /**
     * Saring model dari Gemini /v1beta/models agar hanya menyertakan model yang mendukung generateContent.
     *
     * @param  array<string, mixed>  $entry
     */
    public function isGeminiGenerateContentCapableModel(string $id, array $entry = []): bool
    {
        $lower = strtolower(trim($id));
        if ($lower === '') {
            return false;
        }

        $methods = $entry['supportedGenerationMethods'] ?? null;
        if (is_array($methods) && $methods !== [] && ! in_array('generateContent', $methods, true)) {
            return false;
        }

        $nonChatPatterns = [
            'embedding', 'embed-', '-embed', 'aqa', 'imagen', 'veo',
            'tts', 'robotics', 'lyria', 'audio', 'native-audio', 'computer-use',
        ];

        foreach ($nonChatPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return false;
            }
        }

        return str_contains($lower, 'gemini');
    }

    public function isOpenAiReasoningModel(string $model): bool
    {
        $lower = strtolower(trim($model));
        if ($lower === '') {
            return false;
        }

        return preg_match('/(?:^|\/)o[134](?:-|$)/i', $lower) === 1
            || preg_match('/(?:^|\/)gpt-5(?!-chat)(?:-|$)/i', $lower) === 1;
    }

    protected function isGroqEndpoint(string $provider, string $baseUrl): bool
    {
        return strtolower(trim($provider)) === 'groq'
            || str_contains(strtolower($baseUrl), 'api.groq.com');
    }

    protected function isOpenAiDirectEndpoint(string $provider, string $baseUrl): bool
    {
        return strtolower(trim($provider)) === 'openai'
            || str_contains(strtolower($baseUrl), 'api.openai.com');
    }

    protected function isDeepSeekDirectEndpoint(string $provider, string $baseUrl): bool
    {
        return strtolower(trim($provider)) === 'deepseek'
            || str_contains(strtolower($baseUrl), 'api.deepseek.com');
    }

    /**
     * Susun payload OpenAI-compatible sesuai kapabilitas aktual provider dan model:
     * - Groq + gpt-oss: memakai max_completion_tokens dan reasoning_effort='low' saat token terbatas.
     * - OpenAI direct + o1/o3/o4/gpt-5 (non-chat): memakai max_completion_tokens, menanggalkan temperature non-default, dan mengirim reasoning_effort='low' bila model mendukungnya.
     * - DeepSeek direct + deepseek-reasoner: memakai max_tokens standar dan menanggalkan temperature.
     * - OpenRouter, Custom gateway, dan model non-reasoning standar: memakai payload OpenAI-compatible standar (model, messages, temperature, max_tokens).
     *
     * @param  array<int, array<string, string>>  $messages
     * @return array<string, mixed>
     */
    protected function buildChatPayload(
        string $provider,
        string $baseUrl,
        string $model,
        array $messages,
        float $temperature,
        int $maxTokens,
        bool $isConnectionTest = false
    ): array {
        $payload = [
            'model' => $model,
            'messages' => $messages,
        ];

        $lowerModel = strtolower(trim($model));

        if ($this->isGroqEndpoint($provider, $baseUrl) && str_contains($lowerModel, 'gpt-oss')) {
            // Dokumentasi resmi Groq untuk gpt-oss merekomendasikan max_completion_tokens & reasoning_effort.
            $payload['temperature'] = $temperature;
            $payload['max_completion_tokens'] = $maxTokens;
            if ($isConnectionTest || $maxTokens <= 400) {
                $payload['reasoning_effort'] = 'low';
            }

            return $payload;
        }

        if ($this->isOpenAiDirectEndpoint($provider, $baseUrl) && $this->isOpenAiReasoningModel($lowerModel)) {
            $payload['max_completion_tokens'] = $maxTokens;

            // Model reasoning OpenAI menolak temperature selain default (1).
            if (abs($temperature - 1.0) < 0.0001) {
                $payload['temperature'] = 1.0;
            }

            // Hanya kirim reasoning_effort pada model OpenAI yang mendukungnya (o3, o4, o1 penuh non-mini/preview, gpt-5 non-chat).
            if (
                ($isConnectionTest || $maxTokens <= 400)
                && preg_match('/^(?:o[34](?:-|$)|o1(?!-(?:mini|preview))(?:-|$)|gpt-5(?!-chat)(?:-|$))/i', $lowerModel) === 1
            ) {
                $payload['reasoning_effort'] = 'low';
            }

            return $payload;
        }

        if ($this->isDeepSeekDirectEndpoint($provider, $baseUrl) && str_contains($lowerModel, 'deepseek-reasoner')) {
            // deepseek-reasoner menggunakan max_tokens dan mengabaikan/tidak membutuhkan temperature.
            $payload['max_tokens'] = $maxTokens;

            return $payload;
        }

        $payload['temperature'] = $temperature;
        $payload['max_tokens'] = $maxTokens;

        return $payload;
    }

    /**
     * Susun payload resmi Google AI Studio Gemini REST API (generateContent).
     * System prompt dipisah ke `systemInstruction`, sementara turn percakapan dipetakan ke `contents` (user/model).
     *
     * @param  array<int, array<string, string>>  $messages
     * @return array<string, mixed>
     */
    protected function buildGeminiPayload(array $messages, float $temperature, int $maxTokens): array
    {
        $systemTexts = [];
        $contents = [];

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $role = strtolower(trim((string) ($message['role'] ?? 'user')));
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            if ($role === 'system' || $role === 'developer') {
                $systemTexts[] = $content;
                continue;
            }

            $geminiRole = $role === 'assistant' ? 'model' : 'user';
            $lastIndex = count($contents) - 1;

            // Gabungkan turn berurutan dengan role sama agar struktur role alternating Gemini tetap valid
            if ($lastIndex >= 0 && $contents[$lastIndex]['role'] === $geminiRole) {
                $contents[$lastIndex]['parts'][] = ['text' => $content];
                continue;
            }

            $contents[] = [
                'role' => $geminiRole,
                'parts' => [
                    ['text' => $content],
                ],
            ];
        }

        if ($contents === []) {
            $contents[] = [
                'role' => 'user',
                'parts' => [
                    ['text' => 'Halo'],
                ],
            ];
        }

        $payload = [];

        if ($systemTexts !== []) {
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => implode("\n\n", $systemTexts)],
                ],
            ];
        }

        $payload['contents'] = $contents;
        $payload['generationConfig'] = [
            'temperature' => round(max(0.0, min(2.0, $temperature)), 2),
            'maxOutputTokens' => max(1, $maxTokens),
        ];

        return $payload;
    }

    protected function isGeminiInvalidApiKeyResponse(\Illuminate\Http\Client\Response $response): bool
    {
        $decoded = $response->json();
        if (! is_array($decoded)) {
            return false;
        }

        $reason = strtoupper((string) data_get($decoded, 'error.details.0.reason', ''));
        if (in_array($reason, ['API_KEY_INVALID', 'API_KEY_EXPIRED', 'API_KEY_SERVICE_BLOCKED'], true)) {
            return true;
        }

        $message = strtolower((string) data_get($decoded, 'error.message', ''));

        return str_contains($message, 'api key not valid')
            || str_contains($message, 'api_key_invalid')
            || str_contains($message, 'api key expired');
    }

    /**
     * Parse respons HTTP 200 Google AI Studio Gemini API secara terstruktur dan aman.
     * Part internal dengan `thought === true` TIDAK pernah dijadikan jawaban pengguna.
     *
     * @return array{ok: bool, reason: string, http_status: int, content: ?string, error_message: ?string}
     */
    protected function parseGeminiSuccessfulResponse(
        \Illuminate\Http\Client\Response $response,
        string $provider,
        string $model,
        string $apiKey,
        int $sentMaxTokens
    ): array {
        $status = $response->status();
        $decoded = $response->json();

        if (! is_array($decoded)) {
            Log::warning("AI Gemini Invalid JSON [HTTP {$status}] on provider [{$provider}] model [{$model}].");

            return [
                'ok' => false,
                'reason' => 'invalid_json',
                'http_status' => $status,
                'content' => null,
                'error_message' => 'Endpoint Google AI Studio merespons HTTP 200 tetapi tidak mengembalikan JSON Gemini yang valid.',
            ];
        }

        if (! empty($decoded['error'])) {
            $rawError = is_array($decoded['error'])
                ? (string) ($decoded['error']['message'] ?? json_encode($decoded['error']))
                : (string) $decoded['error'];
            $safeError = substr(self::sanitizeText($rawError, $apiKey), 0, 220);

            Log::error("AI Gemini Error Body in HTTP 200 [provider={$provider}] [model={$model}]: {$safeError}");

            return [
                'ok' => false,
                'reason' => 'provider_error_body',
                'http_status' => $status,
                'content' => null,
                'error_message' => $safeError !== ''
                    ? "Google AI Studio mengembalikan error di dalam respons: {$safeError}"
                    : 'Google AI Studio mengembalikan objek error di dalam respons HTTP 200.',
            ];
        }

        $blockReason = (string) data_get($decoded, 'promptFeedback.blockReason', '');
        if ($blockReason !== '') {
            return [
                'ok' => false,
                'reason' => 'refusal',
                'http_status' => $status,
                'content' => null,
                'error_message' => "Permintaan diblokir oleh kebijakan keamanan Gemini (blockReason: {$blockReason}).",
            ];
        }

        $candidate = data_get($decoded, 'candidates.0');
        $parts = is_array($candidate) ? data_get($candidate, 'content.parts', []) : [];

        $textSegments = [];
        $hasThoughtParts = false;
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (! is_array($part)) {
                    continue;
                }
                if (! empty($part['thought'])) {
                    $hasThoughtParts = true;
                    continue;
                }
                $text = $part['text'] ?? null;
                if (is_string($text) && trim($text) !== '') {
                    $textSegments[] = trim($text);
                }
            }
        }

        $answer = trim(implode("\n", $textSegments));
        if ($answer !== '') {
            $answer = preg_replace('/<(think|thought|reasoning)>[\s\S]*?(<\/\1>|$)/i', '', $answer);
            $answer = preg_replace('/^[\s\S]*?<\/(?:think|thought|reasoning)>\s*/i', '', (string) $answer);
            $answer = trim((string) $answer);

            if ($answer !== '') {
                return [
                    'ok' => true,
                    'reason' => 'ok',
                    'http_status' => $status,
                    'content' => $answer,
                    'error_message' => null,
                ];
            }
        }

        $finishReason = strtoupper(is_array($candidate) ? (string) ($candidate['finishReason'] ?? 'UNKNOWN') : 'UNKNOWN');
        $thoughtsTokenCount = (int) data_get($decoded, 'usageMetadata.thoughtsTokenCount', 0);

        Log::warning(
            "AI Gemini Empty Content [HTTP {$status}] [provider={$provider}] [model={$model}] "
            . "[finishReason={$finishReason}] [thoughtsTokenCount={$thoughtsTokenCount}]"
        );

        if (in_array($finishReason, ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'], true)) {
            return [
                'ok' => false,
                'reason' => 'refusal',
                'http_status' => $status,
                'content' => null,
                'error_message' => "Gemini menolak menghasilkan jawaban (finishReason: {$finishReason}).",
            ];
        }

        if ($finishReason === 'MAX_TOKENS' && ($hasThoughtParts || $thoughtsTokenCount > 0)) {
            return [
                'ok' => false,
                'reason' => 'reasoning_token_limit',
                'http_status' => $status,
                'content' => null,
                'error_message' => "Endpoint Gemini merespons HTTP 200, tetapi batas {$sentMaxTokens} token habis untuk proses thinking sebelum jawaban akhir tertulis (finishReason: MAX_TOKENS). Naikkan Max Tokens (disarankan >= 512).",
            ];
        }

        if ($finishReason === 'MAX_TOKENS') {
            return [
                'ok' => false,
                'reason' => 'token_limit_empty',
                'http_status' => $status,
                'content' => null,
                'error_message' => "Endpoint Gemini merespons HTTP 200 tetapi mencapai batas {$sentMaxTokens} token (finishReason: MAX_TOKENS) dengan konten kosong.",
            ];
        }

        if (! is_array($candidate)) {
            return [
                'ok' => false,
                'reason' => 'missing_choices',
                'http_status' => $status,
                'content' => null,
                'error_message' => 'Endpoint Google AI Studio merespons HTTP 200 tetapi tidak menyertakan candidates.0.',
            ];
        }

        return [
            'ok' => false,
            'reason' => 'empty_response',
            'http_status' => $status,
            'content' => null,
            'error_message' => 'Endpoint Google AI Studio merespons HTTP 200 tetapi tidak mengembalikan teks jawaban pada candidates.0.content.parts.',
        ];
    }

    /**
     * Parse respons HTTP 200 OpenAI-compatible secara terstruktur dan aman.
     * Field reasoning internal (reasoning / reasoning_content) TIDAK pernah dijadikan jawaban pengguna.
     *
     * @return array{ok: bool, reason: string, http_status: int, content: ?string, error_message: ?string}
     */
    protected function parseSuccessfulResponse(
        \Illuminate\Http\Client\Response $response,
        string $provider,
        string $model,
        string $apiKey,
        int $sentMaxTokens
    ): array {
        $status = $response->status();
        $decoded = $response->json();

        if (! is_array($decoded)) {
            Log::warning("AI Service Invalid JSON [HTTP {$status}] on provider [{$provider}] model [{$model}].");

            return [
                'ok' => false,
                'reason' => 'invalid_json',
                'http_status' => $status,
                'content' => null,
                'error_message' => 'Endpoint merespons HTTP 200 tetapi tidak mengembalikan JSON OpenAI-compatible yang valid.',
            ];
        }

        if (! empty($decoded['error'])) {
            $rawError = is_array($decoded['error'])
                ? (string) ($decoded['error']['message'] ?? json_encode($decoded['error']))
                : (string) $decoded['error'];
            $safeError = substr(self::sanitizeText($rawError, $apiKey), 0, 220);

            Log::error("AI Service Error Body in HTTP 200 [provider={$provider}] [model={$model}]: {$safeError}");

            return [
                'ok' => false,
                'reason' => 'provider_error_body',
                'http_status' => $status,
                'content' => null,
                'error_message' => $safeError !== ''
                    ? "Penyedia AI mengembalikan error di dalam respons: {$safeError}"
                    : 'Penyedia AI mengembalikan objek error di dalam respons HTTP 200.',
            ];
        }

        $choice = data_get($decoded, 'choices.0');
        $message = is_array($choice) && is_array($choice['message'] ?? null) ? $choice['message'] : [];

        $answer = $this->extractAnswerContent($decoded, $choice, $message);
        if ($answer !== '') {
            return [
                'ok' => true,
                'reason' => 'ok',
                'http_status' => $status,
                'content' => $answer,
                'error_message' => null,
            ];
        }

        // Diagnosa terstruktur ketika jawaban akhir kosong (tanpa membocorkan reasoning atau prompt sensitif ke log/UI)
        $finishReason = is_array($choice) && isset($choice['finish_reason']) ? (string) $choice['finish_reason'] : 'unknown';
        $hasReasoning = $this->extractPlainTextField($message['reasoning'] ?? $message['reasoning_content'] ?? null) !== '';
        $reasoningTokens = (int) data_get($decoded, 'usage.completion_tokens_details.reasoning_tokens', 0);
        $hasRefusal = $this->extractPlainTextField($message['refusal'] ?? null) !== '' || $finishReason === 'content_filter';
        $hasToolCalls = ! empty($message['tool_calls']) || ! empty($message['executed_tools']) || $finishReason === 'tool_calls';

        Log::warning(
            "AI Service Empty Content [HTTP {$status}] [provider={$provider}] [model={$model}] "
            . "[finish_reason={$finishReason}] [has_reasoning=" . ($hasReasoning || $reasoningTokens > 0 ? '1' : '0') . '] '
            . '[has_tool_calls=' . ($hasToolCalls ? '1' : '0') . '] [has_refusal=' . ($hasRefusal ? '1' : '0') . ']'
        );

        if ($hasRefusal) {
            return [
                'ok' => false,
                'reason' => 'refusal',
                'http_status' => $status,
                'content' => null,
                'error_message' => 'Model menolak menghasilkan jawaban (refusal / content_filter) dan tidak mengembalikan konten.',
            ];
        }

        if ($hasToolCalls) {
            return [
                'ok' => false,
                'reason' => 'tool_calls_only',
                'http_status' => $status,
                'content' => null,
                'error_message' => "Model mengembalikan pemanggilan tool (finish_reason: {$finishReason}) tanpa teks jawaban pada choices.0.message.content.",
            ];
        }

        if ($finishReason === 'length' && ($hasReasoning || $reasoningTokens > 0)) {
            return [
                'ok' => false,
                'reason' => 'reasoning_token_limit',
                'http_status' => $status,
                'content' => null,
                'error_message' => "Endpoint merespons HTTP 200, tetapi batas {$sentMaxTokens} token habis untuk reasoning internal sebelum jawaban akhir tertulis (finish_reason: length). Naikkan Max Tokens (disarankan >= 512).",
            ];
        }

        if ($hasReasoning) {
            return [
                'ok' => false,
                'reason' => 'reasoning_only_no_content',
                'http_status' => $status,
                'content' => null,
                'error_message' => 'Endpoint merespons HTTP 200 dengan blok reasoning internal, tetapi choices.0.message.content tetap kosong.',
            ];
        }

        if ($finishReason === 'length') {
            return [
                'ok' => false,
                'reason' => 'token_limit_empty',
                'http_status' => $status,
                'content' => null,
                'error_message' => "Endpoint merespons HTTP 200 tetapi mencapai batas {$sentMaxTokens} token (finish_reason: length) dengan konten kosong.",
            ];
        }

        if (! is_array($choice)) {
            return [
                'ok' => false,
                'reason' => 'missing_choices',
                'http_status' => $status,
                'content' => null,
                'error_message' => 'Endpoint merespons HTTP 200 tetapi tidak menyertakan choices.0 dalam format OpenAI-compatible.',
            ];
        }

        return [
            'ok' => false,
            'reason' => 'empty_response',
            'http_status' => $status,
            'content' => null,
            'error_message' => 'Endpoint merespons HTTP 200 tetapi tidak mengembalikan konten jawaban format OpenAI-compatible (choices.0.message.content kosong).',
        ];
    }

    /**
     * Ekstrak konten jawaban pengguna yang sah (tidak pernah mengambil field reasoning/thinking).
     *
     * @param  array<string, mixed>  $decoded
     * @param  array<string, mixed>  $message
     */
    protected function extractAnswerContent(array $decoded, mixed $choice, array $message): string
    {
        $candidates = [];

        if (array_key_exists('content', $message)) {
            $candidates[] = $this->extractPlainTextField($message['content']);
        }

        if (is_array($choice) && isset($choice['text'])) {
            $candidates[] = $this->extractPlainTextField($choice['text']);
        }

        if (isset($decoded['output_text'])) {
            $candidates[] = $this->extractPlainTextField($decoded['output_text']);
        }

        if (isset($decoded['output']) && is_array($decoded['output'])) {
            $parts = [];
            foreach ($decoded['output'] as $item) {
                if (! is_array($item) || ($item['type'] ?? '') !== 'message') {
                    continue;
                }
                $partText = $this->extractPlainTextField($item['content'] ?? null);
                if ($partText !== '') {
                    $parts[] = $partText;
                }
            }
            if ($parts !== []) {
                $candidates[] = implode("\n", $parts);
            }
        }

        foreach ($candidates as $candidate) {
            $cleaned = preg_replace('/<(think|thought|reasoning)>[\s\S]*?(<\/\1>|$)/i', '', $candidate);
            // Tangani tag penutup yatim (mis. output dimulai langsung oleh thinking lalu ditutup </think> tanpa tag pembuka).
            $cleaned = preg_replace('/^[\s\S]*?<\/(?:think|thought|reasoning)>\s*/i', '', (string) $cleaned);
            $cleaned = trim((string) $cleaned);
            if ($cleaned !== '') {
                return $cleaned;
            }
        }

        return '';
    }

    /**
     * Ekstrak pesan error diagnostik yang aman & ter-sanitasi dari respons HTTP gagal.
     */
    protected function extractSafeProviderError(\Illuminate\Http\Client\Response $response, string $apiKey): ?string
    {
        $decoded = $response->json();
        $raw = '';
        if (is_array($decoded)) {
            $raw = (string) ($decoded['error']['message'] ?? $decoded['message'] ?? $decoded['detail'] ?? '');
        }
        if ($raw === '') {
            $raw = $response->body();
        }

        $safe = substr(self::sanitizeText($raw, $apiKey), 0, 200);

        return $safe !== '' ? $safe : null;
    }

    protected function extractPlainTextField(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (! is_array($value)) {
            return '';
        }

        $segments = [];
        foreach ($value as $part) {
            if (is_string($part)) {
                $trimmed = trim($part);
                if ($trimmed !== '') {
                    $segments[] = $trimmed;
                }
                continue;
            }

            if (! is_array($part)) {
                continue;
            }

            $type = strtolower((string) ($part['type'] ?? 'text'));
            if (in_array($type, ['reasoning', 'thinking', 'reasoning_content'], true)) {
                continue;
            }

            if (in_array($type, ['text', 'output_text'], true)) {
                $text = $part['text'] ?? $part['output_text'] ?? $part['content'] ?? '';
                if (is_string($text) && trim($text) !== '') {
                    $segments[] = trim($text);
                }
            }
        }

        return trim(implode("\n", $segments));
    }

    protected function isHttpFaking(): bool
    {
        try {
            $root = Http::getFacadeRoot();
            if (! $root || ! property_exists($root, 'stubCallbacks')) {
                return false;
            }

            $ref = new \ReflectionProperty($root, 'stubCallbacks');
            $ref->setAccessible(true);
            $callbacks = $ref->getValue($root);

            return $callbacks instanceof \Illuminate\Support\Collection
                ? $callbacks->isNotEmpty()
                : ! empty($callbacks);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{ok: false, normalized_url: null, host: null, port: int, resolved_ips: array<int, string>, is_literal_ip: false, error: string}
     */
    protected function urlFailure(string $error): array
    {
        return [
            'ok' => false,
            'normalized_url' => null,
            'host' => null,
            'port' => 443,
            'resolved_ips' => [],
            'is_literal_ip' => false,
            'error' => $error,
        ];
    }
}
