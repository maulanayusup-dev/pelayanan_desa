<?php

namespace App\Livewire\Admin;

use App\Models\AiProviderCredential;
use App\Models\AiSetting;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AiConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Component;

class AiSettings extends Component
{
    // Form state (newApiKey selalu kosong saat load; TIDAK pernah diisi API key tersimpan)
    public string $provider = 'google_ai_studio';
    public string $label = '';
    public string $baseUrl = '';
    public string $model = '';
    public string $selectedModelOption = '';
    public string $newApiKey = '';
    public float $temperature = 0.2;
    public int $timeout = 15;
    public int $maxTokens = 300;
    public bool $isEnabled = true;
    public bool $confirmUnlistedModel = false;

    // Dynamic model catalog state (hanya ID/nama model publik, TANPA API Key)
    public array $availableModels = [];
    public array $cachedApiModelsByProvider = [];
    public string $modelCatalogSource = 'preset';
    public string $modelSelectionNotice = '';
    public ?array $modelFetchStatus = null;

    // Active runtime status metadata (hanya metadata aman & preview masking)
    public string $configSource = 'default';
    public string $activeProvider = 'google_ai_studio';
    public string $activeProviderLabel = 'Google AI Studio (Gemini)';
    public string $activeModel = '';
    public string $activeBaseUrl = '';
    public bool $activeIsEnabled = true;
    public bool $activeIsConfigured = false;
    public string $activeStatusCode = 'not_configured';
    public string $activeKeySource = 'none';
    public bool $hasStoredDbKey = false;
    public bool $hasEffectiveKey = false;
    public ?string $maskedKeyPreview = null;

    // Credential metadata khusus provider yang sedang dipilih di form
    public string $selectedProviderKeySource = 'none';
    public bool $selectedProviderHasDbKey = false;
    public bool $selectedProviderIsCleared = false;
    public ?string $selectedProviderMaskedKey = null;

    public ?string $lastTestedAt = null;
    public ?string $lastTestStatus = null;
    public ?string $lastTestMessage = null;
    public ?string $updatedByName = null;
    public ?string $updatedAtLabel = null;

    // Ephemeral UI state
    public ?array $liveTestResult = null;
    public string $feedbackMessage = '';
    public string $feedbackType = 'success';
    public bool $showClearKeyModal = false;

    public function mount(AiConfigService $configService): void
    {
        $this->authorizeSupervisor();
        $this->loadState($configService, true);
    }

    public function updatedProvider(string $value): void
    {
        $this->authorizeSupervisor();

        $presets = AiConfigService::providerPresets(true);
        if (! isset($presets[$value])) {
            return;
        }

        if ($value !== 'custom') {
            $this->baseUrl = (string) ($presets[$value]['default_base_url'] ?? '');
            $this->label = (string) ($presets[$value]['name'] ?? '');
        } else {
            // Jangan mewarisi Base URL provider resmi sebelumnya ketika beralih ke Custom Provider.
            $this->baseUrl = '';
            if (trim($this->label) === '' || in_array($this->label, array_column($presets, 'name'), true)) {
                $this->label = (string) ($presets['custom']['name'] ?? 'Custom OpenAI-Compatible');
            }
        }

        // Selalu kosongkan draft API key ketika berganti provider agar key lama tidak pernah terbawa.
        $this->newApiKey = '';
        $configService = app(AiConfigService::class);
        $this->syncSelectedProviderKeyMeta($configService);

        $previousModel = trim($this->model);
        $this->loadModelsForProvider($value, $configService);

        $availableIds = array_column($this->availableModels, 'id');

        if ($previousModel !== '' && in_array($previousModel, $availableIds, true)) {
            // Model masih kompatibel dengan provider baru -> pertahankan tanpa mengubah secara diam-diam
            $this->model = $previousModel;
            $this->selectedModelOption = $previousModel;
            $this->modelSelectionNotice = '';
            $this->confirmUnlistedModel = false;
        } elseif ($value === 'custom') {
            $this->selectedModelOption = '__custom__';
            $this->model = $previousModel;
            $this->modelSelectionNotice = '';
            $this->confirmUnlistedModel = false;
        } else {
            // Model sebelumnya tidak tersedia pada provider baru -> wajib minta Supervisor memilih model baru
            $this->selectedModelOption = '';
            $this->model = '';
            $this->confirmUnlistedModel = false;
            $providerName = (string) ($presets[$value]['name'] ?? strtoupper($value));
            $this->modelSelectionNotice = $previousModel !== ''
                ? "Model sebelumnya ({$previousModel}) tidak tersedia pada {$providerName}. Silakan pilih model yang tersedia atau pilih 'Model Khusus / Custom'."
                : "Silakan pilih model yang tersedia untuk {$providerName}.";
        }

        $this->liveTestResult = null;
        $this->modelFetchStatus = null;
    }

    public function updatedSelectedModelOption(string $value): void
    {
        $this->authorizeSupervisor();
        $this->modelSelectionNotice = '';
        $this->liveTestResult = null;
        $this->resetValidation('model');

        if ($value === '__custom__') {
            $availableIds = array_column($this->availableModels, 'id');
            if (in_array(trim($this->model), $availableIds, true)) {
                $this->model = '';
            }
            $this->confirmUnlistedModel = false;
            return;
        }

        if ($value === '') {
            $this->model = '';
            $this->confirmUnlistedModel = false;
            return;
        }

        $this->model = trim($value);
        $this->confirmUnlistedModel = false;
    }

    public function updatedModel(string $value): void
    {
        $this->authorizeSupervisor();
        $trimmed = trim($value);
        $availableIds = array_column($this->availableModels, 'id');

        if ($trimmed !== '' && in_array($trimmed, $availableIds, true)) {
            $this->selectedModelOption = $trimmed;
            $this->confirmUnlistedModel = false;
            $this->modelSelectionNotice = '';
        } elseif ($trimmed !== '') {
            $this->selectedModelOption = '__custom__';
        }

        $this->liveTestResult = null;
    }

    public function applySuggestedModel(string $modelName): void
    {
        $this->authorizeSupervisor();
        $trimmed = trim($modelName);
        $this->model = $trimmed;
        $this->updatedModel($trimmed);
    }

    public function refreshModels(AiConfigService $configService): void
    {
        $this->authorizeSupervisor();
        $this->resetValidation('baseUrl');

        $validation = $configService->validateEndpointUrl($this->baseUrl, $this->provider);
        if (! $validation['ok']) {
            $this->addError('baseUrl', (string) ($validation['error'] ?? 'Base URL tidak valid.'));
            return;
        }

        $res = $configService->fetchAvailableModels([
            'provider' => $this->provider,
            'base_url' => $validation['normalized_url'],
            'api_key' => trim($this->newApiKey) !== '' ? trim($this->newApiKey) : null,
            'timeout' => (int) $this->timeout,
        ]);

        $this->availableModels = $res['models'];
        $this->modelCatalogSource = $res['source'];

        if ($res['source'] === 'api') {
            $this->cachedApiModelsByProvider[$this->provider] = $res['models'];
        }

        $this->modelFetchStatus = [
            'ok' => $res['ok'],
            'source' => $res['source'],
            'message' => $res['message'],
            'count' => count($res['models']),
        ];

        $currentModel = trim($this->model);
        $availableIds = array_column($this->availableModels, 'id');
        if ($currentModel !== '' && in_array($currentModel, $availableIds, true)) {
            $this->selectedModelOption = $currentModel;
            $this->modelSelectionNotice = '';
            $this->confirmUnlistedModel = false;
        } elseif ($currentModel !== '' && $this->provider !== 'custom' && $res['source'] === 'api') {
            $this->selectedModelOption = '__custom__';
            $this->confirmUnlistedModel = false;
            $this->modelSelectionNotice = "Model '{$currentModel}' tidak ditemukan dalam respons daftar model API provider ini. Pilih model yang tersedia atau centang konfirmasi Model Khusus / Custom.";
        }
    }

    public function save(AiConfigService $configService): void
    {
        $actor = $this->authorizeSupervisor();

        $presets = AiConfigService::providerPresets(true);
        $validatedEndpoint = null;

        $this->validate([
            'provider' => ['required', 'string', Rule::in(array_keys($presets))],
            'label' => ['nullable', 'string', 'max:120'],
            'baseUrl' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($configService, &$validatedEndpoint): void {
                    $result = $configService->validateEndpointUrl((string) $value, $this->provider);
                    if (! $result['ok']) {
                        $fail((string) ($result['error'] ?? 'Base URL tidak valid.'));
                        return;
                    }
                    $validatedEndpoint = $result;
                },
            ],
            'model' => ['required', 'string', 'max:150'],
            'newApiKey' => ['nullable', 'string', 'max:500'],
            'temperature' => ['required', 'numeric', 'min:0', 'max:2'],
            'timeout' => ['required', 'integer', 'min:5', 'max:120'],
            'maxTokens' => ['required', 'integer', 'min:50', 'max:4096'],
            'isEnabled' => ['boolean'],
        ], [
            'provider.in' => 'Provider AI yang dipilih tidak valid.',
            'baseUrl.required' => 'Base URL wajib diisi.',
            'model.required' => 'Nama model AI wajib diisi.',
            'temperature.min' => 'Temperature minimal 0.0.',
            'temperature.max' => 'Temperature maksimal 2.0.',
            'timeout.min' => 'Timeout minimal 5 detik.',
            'timeout.max' => 'Timeout maksimal 120 detik.',
            'maxTokens.min' => 'Max tokens minimal 50.',
            'maxTokens.max' => 'Max tokens maksimal 4096.',
        ]);

        $normalizedUrl = (string) ($validatedEndpoint['normalized_url'] ?? rtrim(trim($this->baseUrl), '/'));
        $trimmedModel = trim($this->model);

        if ($this->isUnlistedPresetModel($trimmedModel) && ! $this->confirmUnlistedModel) {
            $providerLabel = AiConfigService::presetLabel($this->provider);
            $this->addError(
                'model',
                "Model '{$trimmedModel}' tidak tercantum pada daftar model {$providerLabel}. Pilih model yang tersedia atau centang konfirmasi penggunaan Model Khusus / Custom."
            );
            return;
        }

        $trimmedLabel = trim($this->label) !== ''
            ? trim($this->label)
            : AiConfigService::presetLabel($this->provider);
        $trimmedNewKey = trim($this->newApiKey);

        try {
            $setting = DB::transaction(function () use ($actor, $normalizedUrl, $trimmedModel, $trimmedLabel, $trimmedNewKey): AiSetting {
                $record = AiSetting::singletonOrNew();

                $endpointChanged = ! $record->exists
                    || $record->provider !== $this->provider
                    || rtrim((string) $record->base_url, '/') !== $normalizedUrl
                    || trim((string) $record->model) !== $trimmedModel
                    || $trimmedNewKey !== '';

                $record->singleton_key = AiSetting::SINGLETON_KEY;
                $record->provider = $this->provider;
                $record->label = $trimmedLabel;
                $record->base_url = $normalizedUrl;
                $record->model = $trimmedModel;
                $record->temperature = round((float) $this->temperature, 2);
                $record->timeout = (int) $this->timeout;
                $record->max_tokens = (int) $this->maxTokens;
                $record->is_enabled = (bool) $this->isEnabled;
                $record->api_key_encrypted = null;
                $record->updated_by = $actor->id;

                if ($endpointChanged) {
                    $record->last_tested_at = null;
                    $record->last_test_status = null;
                    $record->last_test_message = null;
                }

                $record->save();

                // Simpan atau perbarui credential khusus untuk provider yang dipilih saja.
                // Input kosong tidak mengubah key provider target maupun provider lain.
                if ($trimmedNewKey !== '') {
                    AiProviderCredential::query()->updateOrCreate(
                        ['provider' => $this->provider],
                        [
                            'api_key_encrypted' => $trimmedNewKey,
                            'key_cleared_explicitly' => false,
                            'updated_by' => $actor->id,
                        ]
                    );
                }

                return $record;
            });

            $configService->clearCache();
            $resolvedKey = $configService->resolveProviderKey($setting->provider);

            $this->recordAuditLog($actor, 'UPDATE_AI_SETTINGS', [
                'action_type' => 'UPDATE_AI_SETTINGS',
                'provider' => $setting->provider,
                'model' => $setting->model,
                'base_url' => $setting->base_url,
                'temperature' => (float) $setting->temperature,
                'timeout' => $setting->timeout,
                'max_tokens' => $setting->max_tokens,
                'is_enabled' => $setting->is_enabled,
                'api_key_updated' => $trimmedNewKey !== '',
                'has_api_key' => $resolvedKey['api_key'] !== null,
                'key_source' => $resolvedKey['source'],
                'timestamp' => now()->toIso8601String(),
            ]);

            $this->newApiKey = '';
            $this->liveTestResult = null;
            $this->loadState($configService, true);

            $this->feedbackType = 'success';
            $this->feedbackMessage = 'Konfigurasi AI global berhasil disimpan dan langsung berlaku untuk seluruh pengguna.';
        } catch (\Throwable $e) {
            Log::error('Gagal menyimpan AiSetting: ' . AiConfigService::sanitizeText($e->getMessage(), $trimmedNewKey));
            $this->feedbackType = 'error';
            $this->feedbackMessage = 'Terjadi kesalahan saat menyimpan konfigurasi AI. Silakan coba lagi.';
        }
    }

    public function testConnection(AiConfigService $configService): void
    {
        $actor = $this->authorizeSupervisor();
        $this->liveTestResult = null;

        $presets = AiConfigService::providerPresets(true);
        $validatedEndpoint = null;

        $this->validate([
            'provider' => ['required', 'string', Rule::in(array_keys($presets))],
            'baseUrl' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($configService, &$validatedEndpoint): void {
                    $result = $configService->validateEndpointUrl((string) $value, $this->provider);
                    if (! $result['ok']) {
                        $fail((string) ($result['error'] ?? 'Base URL tidak valid.'));
                        return;
                    }
                    $validatedEndpoint = $result;
                },
            ],
            'model' => ['required', 'string', 'max:150'],
            'newApiKey' => ['nullable', 'string', 'max:500'],
            'temperature' => ['required', 'numeric', 'min:0', 'max:2'],
            'timeout' => ['required', 'integer', 'min:5', 'max:120'],
            'maxTokens' => ['required', 'integer', 'min:50', 'max:4096'],
        ]);

        $normalizedUrl = (string) ($validatedEndpoint['normalized_url'] ?? rtrim(trim($this->baseUrl), '/'));
        $trimmedModel = trim($this->model);
        $trimmedNewKey = trim($this->newApiKey);

        $dbRecord = AiSetting::singleton();
        $matchesSavedConfig = $dbRecord !== null
            && $trimmedNewKey === ''
            && $dbRecord->provider === $this->provider
            && rtrim((string) $dbRecord->base_url, '/') === $normalizedUrl
            && trim((string) $dbRecord->model) === $trimmedModel
            && abs((float) ($dbRecord->temperature ?? 0.2) - (float) $this->temperature) < 0.001
            && (int) $dbRecord->timeout === (int) $this->timeout
            && (int) $dbRecord->max_tokens === (int) $this->maxTokens
            && (bool) $dbRecord->is_enabled === (bool) $this->isEnabled;

        $result = $configService->testConnection([
            'provider' => $this->provider,
            'base_url' => $normalizedUrl,
            'model' => $trimmedModel,
            'api_key' => $trimmedNewKey !== '' ? $trimmedNewKey : null,
            'temperature' => (float) $this->temperature,
            'timeout' => (int) $this->timeout,
            'max_tokens' => (int) $this->maxTokens,
        ]);

        $this->liveTestResult = [
            'ok' => (bool) $result['ok'],
            'provider_label' => (string) $result['provider_label'],
            'model' => (string) $result['model'],
            'message' => (string) $result['message'],
            'is_temporary' => ! $matchesSavedConfig,
            'tested_at' => now()->translatedFormat('d M Y, H:i:s'),
        ];

        // Status tersimpan hanya diperbarui bila yang dites identik dengan konfigurasi database tersimpan.
        if ($matchesSavedConfig && $dbRecord !== null) {
            $dbRecord->last_tested_at = now();
            $dbRecord->last_test_status = $result['ok'] ? 'success' : 'failed';
            $dbRecord->last_test_message = (string) $result['message'];
            $dbRecord->save();
            $configService->clearCache();
            $this->loadState($configService, false);
        }

        $this->recordAuditLog($actor, 'TEST_AI_CONNECTION', [
            'action_type' => 'TEST_AI_CONNECTION',
            'provider' => $this->provider,
            'model' => $trimmedModel,
            'base_url' => $normalizedUrl,
            'is_enabled' => (bool) $this->isEnabled,
            'is_temporary_candidate' => ! $matchesSavedConfig,
            'test_status' => $result['ok'] ? 'success' : 'failed',
            'test_reason' => $result['reason'],
            'http_status' => $result['http_status'],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function openClearKeyModal(): void
    {
        $this->authorizeSupervisor();
        $this->showClearKeyModal = true;
    }

    public function cancelClearKeyModal(): void
    {
        $this->authorizeSupervisor();
        $this->showClearKeyModal = false;
    }

    public function clearApiKey(AiConfigService $configService): void
    {
        $actor = $this->authorizeSupervisor();
        $targetProvider = strtolower(trim($this->provider)) ?: 'custom';

        AiProviderCredential::query()->updateOrCreate(
            ['provider' => $targetProvider],
            [
                'api_key_encrypted' => null,
                'key_cleared_explicitly' => true,
                'updated_by' => $actor->id,
            ]
        );

        $record = AiSetting::singleton();
        if ($record !== null && strtolower((string) $record->provider) === $targetProvider) {
            $record->api_key_encrypted = null;
            $record->last_tested_at = null;
            $record->last_test_status = null;
            $record->last_test_message = null;
            $record->updated_by = $actor->id;
            $record->save();
        }

        $configService->clearCache();

        $this->recordAuditLog($actor, 'CLEAR_AI_API_KEY', [
            'action_type' => 'CLEAR_AI_API_KEY',
            'provider' => $targetProvider,
            'model' => $this->model ?: ($record?->model),
            'base_url' => $this->baseUrl ?: ($record?->base_url),
            'is_enabled' => $record?->is_enabled ?? $this->isEnabled,
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->newApiKey = '';
        $this->showClearKeyModal = false;
        $this->liveTestResult = null;
        $this->loadState($configService, false);

        $providerName = AiConfigService::presetLabel($targetProvider);
        $this->feedbackType = 'success';
        $this->feedbackMessage = "API Key tersimpan untuk provider {$providerName} telah dihapus.";
    }

    public function dismissFeedback(): void
    {
        $this->authorizeSupervisor();
        $this->feedbackMessage = '';
    }

    public function render()
    {
        $this->authorizeSupervisor();

        return view('livewire.admin.ai-settings', [
            'presets' => AiConfigService::providerPresets(true),
        ])->layout('components.layouts.app', ['title' => 'Pengaturan Konfigurasi AI']);
    }

    protected function loadState(AiConfigService $configService, bool $resetForm): void
    {
        $resolved = $configService->resolve();

        $this->configSource = (string) $resolved['source'];
        $this->activeProvider = (string) $resolved['provider'];
        $this->activeProviderLabel = (string) $resolved['label'];
        $this->activeModel = (string) $resolved['model'];
        $this->activeBaseUrl = (string) $resolved['base_url'];
        $this->activeIsEnabled = (bool) $resolved['is_enabled'];
        $this->activeIsConfigured = (bool) $resolved['is_configured'];
        $this->activeStatusCode = (string) $resolved['status_code'];
        $this->activeKeySource = (string) ($resolved['key_source'] ?? 'none');
        $this->hasStoredDbKey = (bool) ($resolved['has_db_key'] ?? ($this->activeKeySource === 'database'));
        $this->hasEffectiveKey = (bool) $resolved['has_api_key'];
        $this->maskedKeyPreview = $resolved['masked_api_key'];

        $this->lastTestedAt = ! empty($resolved['last_tested_at'])
            ? \Illuminate\Support\Carbon::parse($resolved['last_tested_at'])->translatedFormat('d M Y, H:i')
            : null;
        $this->lastTestStatus = $resolved['last_test_status'];
        $this->lastTestMessage = $resolved['last_test_message'];
        $this->updatedByName = $resolved['updated_by_name'];
        $this->updatedAtLabel = ! empty($resolved['updated_at'])
            ? \Illuminate\Support\Carbon::parse($resolved['updated_at'])->translatedFormat('d M Y, H:i')
            : null;

        if ($resetForm) {
            $this->provider = (string) $resolved['provider'];
            $this->label = (string) $resolved['label'];
            $this->baseUrl = (string) $resolved['base_url'];
            $this->model = (string) $resolved['model'];
            $this->temperature = (float) ($resolved['temperature'] ?? 0.2);
            $this->timeout = (int) $resolved['timeout'];
            $this->maxTokens = (int) $resolved['max_tokens'];
            $this->isEnabled = (bool) $resolved['is_enabled'];
            $this->newApiKey = '';
            $this->modelSelectionNotice = '';

            $this->loadModelsForProvider($this->provider, $configService);
            $availableIds = array_column($this->availableModels, 'id');

            if ($this->model !== '' && in_array($this->model, $availableIds, true)) {
                $this->selectedModelOption = $this->model;
                $this->confirmUnlistedModel = false;
            } elseif ($this->model !== '') {
                $this->selectedModelOption = '__custom__';
                $this->confirmUnlistedModel = true;
            } else {
                $this->selectedModelOption = $this->provider === 'custom' ? '__custom__' : '';
                $this->confirmUnlistedModel = false;
            }
        }

        $this->syncSelectedProviderKeyMeta($configService);
    }

    protected function syncSelectedProviderKeyMeta(AiConfigService $configService): void
    {
        $keyInfo = $configService->resolveProviderKey($this->provider);
        $this->selectedProviderKeySource = (string) $keyInfo['source'];
        $this->selectedProviderHasDbKey = (bool) $keyInfo['has_db_key'];
        $this->selectedProviderIsCleared = (bool) $keyInfo['key_cleared_explicitly'];
        $this->selectedProviderMaskedKey = $keyInfo['masked_api_key'];
    }

    protected function loadModelsForProvider(string $provider, AiConfigService $configService): void
    {
        $cached = $this->cachedApiModelsByProvider[$provider] ?? [];
        if ($cached !== []) {
            $this->availableModels = $cached;
            $this->modelCatalogSource = 'api';
            return;
        }

        $this->availableModels = $configService->modelsForProvider($provider);
        $this->modelCatalogSource = 'preset';
    }

    public function isUnlistedPresetModel(?string $modelName = null): bool
    {
        if (in_array($this->provider, ['custom', 'ollama_local'], true)) {
            return false;
        }

        $candidate = trim((string) ($modelName ?? $this->model));
        if ($candidate === '') {
            return false;
        }

        $availableIds = array_column($this->availableModels, 'id');

        return ! in_array($candidate, $availableIds, true);
    }

    protected function authorizeSupervisor(): User
    {
        $user = auth()->user();

        if (
            ! $user instanceof User
            || ! $user->isActiveAccount()
            || strtolower((string) ($user->role?->name ?? '')) !== 'supervisor'
        ) {
            abort(403, 'Hanya Supervisor aktif yang berhak mengakses Pengaturan AI.');
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected function recordAuditLog(User $actor, string $action, array $details): void
    {
        try {
            AuditLog::create([
                'user_id' => $actor->id,
                'action' => $action,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'details' => json_encode($details),
            ]);
        } catch (\Throwable $e) {
            Log::warning("AuditLog {$action} failed: " . $e->getMessage());
        }
    }
}

