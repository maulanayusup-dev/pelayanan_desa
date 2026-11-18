<?php

namespace App\Services;

use App\Jobs\SendFonnteNotificationJob;
use App\Models\Application;
use App\Models\User;
use App\Models\WhatsAppNotificationLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FonnteService
{
    /**
     * Role yang BOLEH menerima notifikasi WhatsApp pengajuan SKTM baru.
     */
    public const NEW_SUBMISSION_RECIPIENT_ROLES = NewSubmissionRecipients::ROLES;

    /**
     * Role yang TIDAK BOLEH menerima notifikasi WhatsApp pengajuan SKTM baru.
     * Nomor HP milik role ini dikecualikan walaupun terdaftar juga pada akun staf.
     */
    public const NEW_SUBMISSION_EXCLUDED_ROLES = NewSubmissionRecipients::EXCLUDED_ROLES;

    public const TYPE_NEW_SUBMISSION = 'FONNTE_NEW_SUBMISSION';

    protected ?string $token;
    protected bool $enabled;
    protected string $countryCode;
    protected int $timeout;
    protected string $apiUrl;
    protected string $deviceUrl;
    protected bool $useQueue;
    protected ?string $defaultRecipient;
    protected array $adminPhones;

    public function __construct(
        ?string $token = null,
        ?bool $enabled = null,
        ?string $countryCode = null
    ) {
        $this->token = $token !== null ? $token : config('services.fonnte.token');
        $this->enabled = $enabled !== null ? $enabled : (bool) config('services.fonnte.enabled', false);
        $this->countryCode = $countryCode !== null ? $countryCode : (config('services.fonnte.country_code') ?? '62');
        $this->timeout = (int) (config('services.fonnte.timeout') ?? 15);
        $this->apiUrl = config('services.fonnte.url') ?? 'https://api.fonnte.com/send';
        $this->deviceUrl = config('services.fonnte.device_url') ?? 'https://api.fonnte.com/device';
        $this->useQueue = (bool) config('services.fonnte.use_queue', true);
        $this->defaultRecipient = config('services.fonnte.default_recipient');
        $this->adminPhones = (array) config('services.fonnte.admin_phones', []);
    }

    /**
     * Check if Fonnte API token is configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->token);
    }

    /**
     * Check if Fonnte WhatsApp integration is active and configured.
     */
    public function isEnabled(): bool
    {
        return $this->enabled && $this->isConfigured();
    }

    /**
     * Get masked token for safe display in logs and UI without leaking secrets.
     */
    public function getMaskedToken(): string
    {
        if (empty($this->token)) {
            return '(Belum dikonfigurasi)';
        }

        $length = strlen($this->token);
        if ($length <= 8) {
            return '********';
        }

        return substr($this->token, 0, 4) . '...' . substr($this->token, -4);
    }

    /**
     * Get masked phone number for safe privacy-respecting logging.
     */
    public function getMaskedPhone(?string $phone): string
    {
        if (empty($phone)) {
            return '(Tidak ada nomor)';
        }

        $clean = preg_replace('/[^0-9]/', '', $phone);
        $len = strlen($clean);
        if ($len <= 6) {
            return '***' . substr($clean, -2);
        }

        return substr($clean, 0, 5) . '****' . substr($clean, -3);
    }

    /**
     * Format domestic Indonesian and international numbers into clean E.164 without leading plus.
     * Examples:
     * - 081234567890   -> 6281234567890
     * - +6281234567890 -> 6281234567890
     * - 62812-3456-789 -> 628123456789
     * - 81234567890    -> 6281234567890
     */
    public function formatPhoneNumber(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $phone);

        if (empty($digits)) {
            return null;
        }

        // Convert domestic / international typos to clean 62xxx
        if (str_starts_with($digits, '0062')) {
            $digits = '62' . substr($digits, 4);
        } elseif (str_starts_with($digits, '620')) {
            $digits = '62' . substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '62' . substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62' . $digits;
        }

        // Validate length: Indonesian numbers with 62 prefix are typically 10 to 15 digits
        if (strlen($digits) < 10 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }

    /**
     * Deteksi nomor contoh/placeholder (.env default atau angka berulang) agar tidak dipakai untuk pengiriman nyata.
     */
    public function isPlaceholderPhone(?string $phone): bool
    {
        $digits = $this->formatPhoneNumber($phone) ?? preg_replace('/[^0-9]/', '', (string) $phone);
        if ($digits === '') {
            return false;
        }

        if (in_array($digits, ['6281234567890', '6281200000000', '6280000000000', '6281111111111', '6289999999999'], true)) {
            return true;
        }

        return preg_match('/^628(.)\1{7,}$/', $digits) === 1;
    }

    /**
     * Send WhatsApp message via official Fonnte API endpoint and persist gateway status log.
     */
    public function sendMessage(
        string $target,
        string $message,
        ?Application $application = null,
        ?User $officer = null,
        ?string $type = 'NOTIFICATION',
        ?string $contextKey = null,
        ?string $recipientRole = null,
        ?string $existingLogId = null,
        ?string $correlationId = null
    ): array {
        $formattedPhone = $this->formatPhoneNumber($target);
        $roleLabel = $recipientRole ?? $officer?->role?->name;
        $dedupeId = $application?->id ?? $contextKey;
        $messageType = $contextKey ?? 'text';

        if (!$formattedPhone) {
            $maskedRaw = $this->getMaskedPhone($target);
            Log::info("FonnteService: Format nomor tujuan tidak valid atau kosong [{$maskedRaw}], pengiriman eksternal dilewati aman (SKIPPED_NO_VALID_RECIPIENT).");
            $this->saveOrUpdateWhatsAppLog($existingLogId, $application?->id, $officer?->id, (string) $target, $roleLabel, (string) $type, $messageType, 'SKIPPED_NO_VALID_RECIPIENT', null, 'Format nomor WhatsApp tidak valid atau kosong.');

            return [
                'success' => false,
                'permanent' => true,
                'delivery_status' => 'SKIPPED_NO_VALID_RECIPIENT',
                'error' => 'Format nomor WhatsApp tidak valid atau kosong.',
                'skipped' => true,
            ];
        }

        $maskedPhone = $this->getMaskedPhone($formattedPhone);

        // Deduplication check: prevent duplicate sending within 1 hour
        if ($this->isDuplicate($dedupeId, $type, $formattedPhone)) {
            Log::info("FonnteService: Pesan duplikat dicegah untuk {$maskedPhone} [Type: {$type}].");
            $this->saveOrUpdateWhatsAppLog($existingLogId, $application?->id, $officer?->id, $formattedPhone, $roleLabel, (string) $type, $messageType, 'SKIPPED', null, 'Duplikasi dicegah (sudah pernah diterima gateway sebelumnya).');

            return [
                'success' => true,
                'skipped' => true,
                'delivery_status' => 'DUPLICATE_SKIPPED',
                'message' => 'Pesan duplikat dicegah (sudah terkirim sebelumnya).',
            ];
        }

        if (!$this->isConfigured()) {
            Log::info("FonnteService: Pengiriman dibatalkan, token API Fonnte belum dikonfigurasi.");
            $this->saveOrUpdateWhatsAppLog($existingLogId, $application?->id, $officer?->id, $formattedPhone, $roleLabel, (string) $type, $messageType, 'SKIPPED', null, 'Token API Fonnte belum dikonfigurasi.');

            return [
                'success' => false,
                'permanent' => true,
                'delivery_status' => 'SKIPPED',
                'error' => 'Token API Fonnte belum dikonfigurasi di file .env.',
                'skipped' => true,
            ];
        }

        if ($this->shouldBlockPlaceholderTarget($formattedPhone)) {
            Log::info("FonnteService: Pengiriman dilewati aman karena nomor {$maskedPhone} adalah nomor placeholder (SKIPPED_NO_VALID_RECIPIENT).");
            $this->saveOrUpdateWhatsAppLog($existingLogId, $application?->id, $officer?->id, $formattedPhone, $roleLabel, (string) $type, $messageType, 'SKIPPED_NO_VALID_RECIPIENT', null, 'Nomor tujuan terdeteksi sebagai placeholder.');

            return [
                'success' => false,
                'permanent' => true,
                'delivery_status' => 'SKIPPED_NO_VALID_RECIPIENT',
                'skipped' => true,
                'error' => 'Nomor tujuan merupakan nomor placeholder dan dibatalkan.',
            ];
        }

        $payload = [
            'target' => $formattedPhone,
            'message' => $message,
            'countryCode' => $this->countryCode,
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->token,
            ])
            ->timeout($this->timeout)
            ->post($this->apiUrl, $payload);

            $responseData = $response->json();

            // Fonnte returns JSON status: true when gateway accepts/queues request (not necessarily user WhatsApp delivered yet).
            if ($response->successful() && is_array($responseData) && ($responseData['status'] ?? false) === true) {
                $rawId = $responseData['id'][0] ?? ($responseData['id'] ?? null);
                $messageId = is_scalar($rawId) && (string) $rawId !== '' ? (string) $rawId : 'accepted';
                $gatewayStatus = $this->resolveGatewayStatus($responseData);
                $sentAt = in_array($gatewayStatus, ['SENT', 'DELIVERED'], true) ? now() : null;

                Log::info("FonnteService: Pesan WhatsApp {$gatewayStatus} oleh gateway Fonnte untuk {$maskedPhone}. ID: {$messageId}");
                Log::info('ReportNotificationTrace: Fonnte response received.', [
                    'correlation_id' => $correlationId,
                    'context' => $contextKey,
                    'log_id' => $existingLogId,
                    'target_masked' => $maskedPhone,
                    'http_status' => $response->status(),
                    'delivery_status' => $gatewayStatus,
                    'message_id' => $messageId,
                ]);

                $this->saveOrUpdateWhatsAppLog($existingLogId, $application?->id, $officer?->id, $formattedPhone, $roleLabel, (string) $type, $messageType, $gatewayStatus, $messageId, null, $sentAt);
                $this->markAsSent($dedupeId, $type, $formattedPhone);

                return [
                    'success' => true,
                    'delivery_status' => $gatewayStatus,
                    'accepted' => true,
                    'delivered' => $gatewayStatus === 'DELIVERED',
                    'message_id' => $messageId,
                    'target' => $formattedPhone,
                    'response' => $responseData,
                ];
            }

            $reason = is_array($responseData)
                ? ($responseData['reason'] ?? ($responseData['message'] ?? 'Respons API Fonnte gagal (HTTP ' . $response->status() . ')'))
                : 'Respons API Fonnte tidak valid (HTTP ' . $response->status() . ')';
            $safeReason = $this->sanitizeErrorMessage((string) $reason);
            $isPermanent = $this->isPermanentApiError($response->status(), $safeReason);

            Log::warning("FonnteService error saat mengirim ke {$maskedPhone}: {$safeReason}");
            Log::warning('ReportNotificationTrace: Fonnte response received.', [
                'correlation_id' => $correlationId,
                'context' => $contextKey,
                'log_id' => $existingLogId,
                'target_masked' => $maskedPhone,
                'http_status' => $response->status(),
                'delivery_status' => 'FAILED',
                'reason' => $safeReason,
            ]);
            $this->saveOrUpdateWhatsAppLog($existingLogId, $application?->id, $officer?->id, $formattedPhone, $roleLabel, (string) $type, $messageType, 'FAILED', null, $safeReason);

            return [
                'success' => false,
                'permanent' => $isPermanent,
                'delivery_status' => 'FAILED',
                'error' => $safeReason,
                'response' => $responseData,
            ];
        } catch (\Throwable $e) {
            $safeExceptionMsg = $this->sanitizeErrorMessage($e->getMessage());
            Log::warning("FonnteService exception koneksi ke {$maskedPhone}: {$safeExceptionMsg}");
            Log::warning('ReportNotificationTrace: Fonnte response received.', [
                'correlation_id' => $correlationId,
                'context' => $contextKey,
                'log_id' => $existingLogId,
                'target_masked' => $maskedPhone,
                'delivery_status' => 'FAILED',
                'reason' => $safeExceptionMsg,
            ]);
            $this->saveOrUpdateWhatsAppLog($existingLogId, $application?->id, $officer?->id, $formattedPhone, $roleLabel, (string) $type, $messageType, 'FAILED', null, 'Koneksi: ' . $safeExceptionMsg);

            return [
                'success' => false,
                'permanent' => false,
                'delivery_status' => 'FAILED',
                'error' => 'Koneksi ke gateway Fonnte gagal: ' . $safeExceptionMsg,
            ];
        }
    }

    /**
     * Dispatch WhatsApp notifications to relevant administrative officers based on application lifecycle stage.
     */
    public function notifyOfficersForApplication(Application $application): array
    {
        if (!$this->isEnabled()) {
            return [
                'dispatched' => false,
                'reason' => 'Fonnte WhatsApp integration is disabled in configuration.',
            ];
        }

        // KETENTUAN UTAMA: pengajuan SKTM baru (SUBMITTED) ditangani jalur khusus yang
        // HANYA mengirim ke pengguna role 'staf' dari database (tanpa fallback nomor .env).
        if ($application->status === 'SUBMITTED') {
            return $this->notifyStaffOfNewSubmission($application);
        }

        // Map application status to relevant officer roles (workflow: Masyarakat -> Staf -> Kepala Desa)
        $officerRoles = match ($application->status) {
            'HEAD_APPROVAL' => ['kades', 'kepala_desa'],
            'COMPLETED' => ['staf'],
            'REJECTED' => ['staf'],
            default => [],
        };

        if (empty($officerRoles)) {
            return [
                'dispatched' => false,
                'reason' => "Status '{$application->status}' tidak memerlukan notifikasi WhatsApp petugas.",
            ];
        }

        $type = match ($application->status) {
            'HEAD_APPROVAL' => 'FONNTE_HEAD_APPROVAL',
            'COMPLETED' => 'FONNTE_COMPLETED',
            'REJECTED' => 'FONNTE_REJECTED',
            default => 'FONNTE_STATUS_CHANGE',
        };

        // Query active officers strictly matching the target roles
        $officers = User::with(['role', 'profile'])
            ->whereHas('role', function ($q) use ($officerRoles) {
                $q->whereIn('name', $officerRoles);
            })
            ->where('is_active', true)
            ->get()
            ->filter(function ($u) use ($application) {
                // Ensure applicant themselves never receives internal staff/officer notification
                return $u->id !== $application->user_id;
            });

        $dispatchedCount = 0;
        $sentRecipients = [];

        // Collect target recipients from database users
        $recipients = [];
        $skippedEmptyCount = 0;
        foreach ($officers as $officer) {
            $rawPhone = $this->resolveUserPhone($officer);
            $formatted = $this->formatPhoneNumber($rawPhone);
            if (!empty($formatted) && !$this->isPlaceholderPhone($formatted) && !in_array($formatted, $sentRecipients)) {
                $recipients[] = [
                    'phone' => $formatted,
                    'officer' => $officer,
                    'role' => $officer->role?->name ?? 'staf',
                ];
                $sentRecipients[] = $formatted;
            } else {
                $skippedEmptyCount++;
                $isPlaceholder = !empty($formatted) && $this->isPlaceholderPhone($formatted);
                $reason = $isPlaceholder
                    ? 'Nomor WhatsApp petugas terdeteksi sebagai nomor placeholder.'
                    : 'Nomor WhatsApp petugas kosong atau sengaja tidak dikonfigurasi.';
                Log::info("FonnteService: Petugas {$officer->name} (role: {$officer->role?->name}) tidak memiliki kontak WhatsApp valid, pengiriman eksternal dilewati aman (SKIPPED_NO_VALID_RECIPIENT).");
                $this->persistWhatsAppLog(
                    $application->id,
                    $officer->id,
                    $isPlaceholder ? '(placeholder diabaikan)' : '(tidak tersedia)',
                    $officer->role?->name,
                    $type,
                    'text',
                    'SKIPPED_NO_VALID_RECIPIENT',
                    null,
                    $reason
                );
            }
        }

        // If no officer in database has a valid phone number, check role-specific fallback in .env (tolak nomor placeholder)
        if (empty($recipients)) {
            $primaryRole = $officerRoles[0];
            $rawFallback = $this->adminPhones[$primaryRole] ?? null;
            $fallbackPhone = is_string($rawFallback) ? trim($rawFallback) : $rawFallback;
            if (empty($fallbackPhone) && $primaryRole !== 'staf') {
                $fallbackPhone = is_string($this->defaultRecipient) ? trim($this->defaultRecipient) : $this->defaultRecipient;
            }

            if (!empty($fallbackPhone)) {
                if ($this->isPlaceholderPhone((string) $fallbackPhone)) {
                    Log::info("FonnteService: Nomor fallback .env untuk role '{$primaryRole}' terdeteksi sebagai placeholder dan dilewati aman (SKIPPED_NO_VALID_RECIPIENT).");
                    $this->persistWhatsAppLog($application->id, $officers->first()?->id, '(placeholder diabaikan)', $primaryRole, $type, 'text', 'SKIPPED_NO_VALID_RECIPIENT', null, 'Nomor fallback .env terdeteksi sebagai nomor contoh/placeholder.');
                } else {
                    $formattedFallback = $this->formatPhoneNumber($fallbackPhone);
                    if ($formattedFallback && !in_array($formattedFallback, $sentRecipients)) {
                        $recipients[] = [
                            'phone' => $formattedFallback,
                            'officer' => $officers->first(),
                            'role' => $primaryRole,
                        ];
                        $sentRecipients[] = $formattedFallback;
                    }
                }
            }
        }

        // If still empty, log safely and exit without failing submission
        if (empty($recipients)) {
            $roleLabel = implode('/', $officerRoles);
            Log::info("FonnteService: Tidak ada pengguna role '{$roleLabel}' yang memiliki nomor HP valid untuk pengajuan {$application->application_number}. Notifikasi WhatsApp dilewati dengan aman.");
            return [
                'dispatched' => true,
                'count' => 0,
                'status' => $application->status,
                'note' => 'No valid officer phone numbers available',
            ];
        }

        // Dispatch messages to qualified recipients
        foreach ($recipients as $recipient) {
            $formattedPhone = $recipient['phone'];
            $officer = $recipient['officer'];
            $roleName = $recipient['role'];
            $messageText = $this->buildOfficerMessage($application, $roleName);

            if ($this->deliver($formattedPhone, $messageText, $application, $officer, $type)) {
                $dispatchedCount++;
            }
        }

        return [
            'dispatched' => true,
            'count' => $dispatchedCount,
            'status' => $application->status,
        ];
    }

    /**
     * Kirim notifikasi WhatsApp pengajuan SKTM baru HANYA kepada pengguna role STAF.
     *
     * - Penerima diambil dari tabel users (role 'staf', aktif), nomor dari users.phone_number
     *   atau user_profiles.phone_number. Tidak ada fallback ke nomor .env.
     * - Nomor yang juga terdaftar pada akun SUPERVISOR / KEPALA_DESA, atau milik warga pemohon,
     *   dikecualikan agar pesan tidak pernah sampai ke mereka.
     * - Satu pengiriman per (pengajuan, waktu submit, nomor) dijamin dengan cache lock atomik
     *   sehingga render ulang Livewire / pemanggilan ganda tidak menghasilkan pesan ganda.
     */
    public function notifyStaffOfNewSubmission(Application $application): array
    {
        if (!$this->isEnabled()) {
            return [
                'dispatched' => false,
                'reason' => 'Fonnte WhatsApp integration is disabled in configuration.',
            ];
        }

        $staffUsers = NewSubmissionRecipients::staff($application);

        $blockedPhones = $this->phonesNotAllowedForNewSubmission($application);

        $recipients = [];
        $skippedInvalid = 0;
        $skippedConflict = 0;

        foreach ($staffUsers as $staff) {
            $formatted = $this->formatPhoneNumber($this->resolveUserPhone($staff));

            if (!$formatted || $this->isPlaceholderPhone($formatted)) {
                $skippedInvalid++;
                $isPlaceholder = !empty($formatted) && $this->isPlaceholderPhone($formatted);
                $reason = $isPlaceholder
                    ? 'Nomor WhatsApp staf terdeteksi sebagai placeholder.'
                    : 'Nomor WhatsApp staf kosong atau sengaja dikosongkan.';
                Log::info("FonnteService: Staf {$staff->name} tidak memiliki nomor WhatsApp valid, pengiriman eksternal dilewati aman (SKIPPED_NO_VALID_RECIPIENT).");
                $this->persistWhatsAppLog($application->id, $staff->id, $isPlaceholder ? '(placeholder diabaikan)' : '(tidak tersedia)', $staff->role?->name ?? 'staf', self::TYPE_NEW_SUBMISSION, 'text', 'SKIPPED_NO_VALID_RECIPIENT', null, $reason);
                continue;
            }

            if (isset($blockedPhones[$formatted])) {
                $skippedConflict++;
                Log::warning("FonnteService: Nomor staf {$this->getMaskedPhone($formatted)} juga terdaftar pada akun {$blockedPhones[$formatted]}. Notifikasi pengajuan baru {$application->application_number} tidak dikirim ke nomor ini.");
                $this->persistWhatsAppLog($application->id, $staff->id, $formatted, $staff->role?->name ?? 'staf', self::TYPE_NEW_SUBMISSION, 'text', 'SKIPPED', null, "Nomor bentrok dengan akun {$blockedPhones[$formatted]}.");
                continue;
            }

            $recipients[$formatted] ??= $staff; // satu pesan per nomor
        }

        if (empty($recipients)) {
            Log::info("FonnteService: Tidak ada staf dengan nomor HP valid untuk pengajuan {$application->application_number} (staf aktif: {$staffUsers->count()}, nomor kosong/tidak valid: {$skippedInvalid}, nomor bentrok dengan role lain: {$skippedConflict}). Notifikasi WhatsApp dilewati dengan aman.");

            return [
                'dispatched' => true,
                'count' => 0,
                'status' => $application->status,
                'note' => 'No valid staff phone numbers available',
            ];
        }

        $messageText = $this->buildNewSubmissionMessage($application);
        $dispatchedCount = 0;

        foreach ($recipients as $phone => $staff) {
            // Atomic lock: hanya proses pertama yang boleh mengirim untuk kombinasi ini
            if (!NewSubmissionRecipients::claim('fonnte', $application, $phone)) {
                Log::info("FonnteService: Notifikasi pengajuan baru {$application->application_number} ke {$this->getMaskedPhone($phone)} sudah diproses sebelumnya, dilewati.");
                continue;
            }

            if ($this->deliver($phone, $messageText, $application, $staff, self::TYPE_NEW_SUBMISSION)) {
                $dispatchedCount++;
            } else {
                NewSubmissionRecipients::release('fonnte', $application, $phone);
            }
        }

        return [
            'dispatched' => true,
            'count' => $dispatchedCount,
            'status' => $application->status,
        ];
    }

    /**
     * Pesan WhatsApp pengajuan SKTM baru untuk staf. Tidak memuat NIK, No. KK,
     * nama pemohon, keperluan, maupun isi dokumen.
     */
    public function buildNewSubmissionMessage(Application $application): string
    {
        $submittedAt = ($application->submitted_at ?? $application->created_at ?? now())
            ->copy()
            ->timezone('Asia/Jakarta')
            ->format('d/m/Y H:i');
        $actionUrl = app(PublicUrlService::class)->route('admin.pengajuan.show', $application->id);

        return "*PEMBERITAHUAN PENGAJUAN SKTM BARU*\n\n" .
            "Halo Staf Pelayanan Desa,\n\n" .
            "Terdapat permohonan Surat Keterangan Tidak Mampu (SKTM) baru yang masuk ke sistem dan memerlukan pemeriksaan kelengkapan berkas.\n\n" .
            "• Nomor Pengajuan: {$application->application_number}\n" .
            "• Tanggal Pengajuan: {$submittedAt} WIB\n" .
            "• Status: Menunggu Pemeriksaan Staf\n\n" .
            "Mohon berkenan untuk meninjau dan memproses pengajuan tersebut melalui tautan berikut:\n" .
            "{$actionUrl}\n\n" .
            "Terima kasih.\n" .
            "_Pesan otomatis dari Sistem Pelayanan Desa._";
    }

    /**
     * Nomor (format 62xxx) yang tidak boleh menerima notifikasi pengajuan baru:
     * milik SUPERVISOR / KEPALA_DESA dan milik warga pemohon. Value = label pemilik untuk log.
     */
    protected function phonesNotAllowedForNewSubmission(Application $application): array
    {
        $blocked = [];

        foreach (NewSubmissionRecipients::excludedContacts($application) as $owner) {
            $label = $owner->id === $application->user_id ? 'warga pemohon' : 'role ' . ($owner->role?->name ?? '-');
            foreach ([$owner->phone_number, $owner->profile?->phone_number] as $raw) {
                $formatted = $this->formatPhoneNumber($raw);
                if ($formatted) {
                    $blocked[$formatted] = $label;
                }
            }
        }

        return $blocked;
    }

    /**
     * Kirim lewat queue (setelah commit) atau langsung. Mengembalikan true bila diantrekan/terkirim.
     */
    protected function deliver(string $phone, string $message, Application $application, ?User $officer, string $type): bool
    {
        if ($this->useQueue) {
            $logId = $this->createPendingQueueLog(
                applicationId: $application->id,
                userId: $officer?->id,
                recipientPhone: $phone,
                recipientRole: $officer?->role?->name,
                type: $type,
                messageType: 'text'
            );

            SendFonnteNotificationJob::dispatch(
                $phone,
                $message,
                $application->id,
                $officer?->id,
                $type,
                $logId
            )
                ->onQueue((string) config('services.fonnte.queues.sktm', 'sktm'))
                ->afterCommit();

            return true;
        }

        $res = $this->sendMessage($phone, $message, $application, $officer, $type, null, $officer?->role?->name);

        return (bool) ($res['success'] ?? false);
    }

    /**
     * Send alert to system administrator / supervisor for critical technical events.
     */
    public function notifySystemIncident(string $title, string $details): array
    {
        if (!$this->isEnabled()) {
            return ['dispatched' => false, 'reason' => 'Fonnte disabled'];
        }

        $adminPhone = $this->adminPhones['supervisor'] ?? ($this->defaultRecipient ?? null);
        if (empty($adminPhone) || $this->isPlaceholderPhone((string) $adminPhone)) {
            return ['dispatched' => false, 'reason' => 'No valid non-placeholder supervisor/admin phone configured'];
        }

        $timeStr = now()->timezone('Asia/Jakarta')->format('d/m/Y H:i:s');
        $message = "⚠️ *PERINGATAN TEKNIS SISTEM PELAYANAN DESA*\n\n" .
            "Yth. Supervisor / Administrator Sistem,\n\n" .
            "Sistem mendeteksi kondisi teknis yang memerlukan perhatian Anda:\n\n" .
            "• *Insiden:* {$title}\n" .
            "• *Waktu:* {$timeStr} WIB\n" .
            "• *Detail:* {$details}\n\n" .
            "Mohon segera ditindaklanjuti sesuai prosedur.\n" .
            "_Pesan otomatis dari Sistem Pelayanan Desa Digital._";

        return $this->sendMessage($adminPhone, $message, null, null, 'FONNTE_SYSTEM_INCIDENT');
    }

    /**
     * Check Fonnte device profile and connection status.
     */
    public function checkDeviceStatus(): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Token API Fonnte belum dikonfigurasi.',
            ];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->token,
            ])
            ->timeout($this->timeout)
            ->post($this->deviceUrl);

            $data = $response->json();

            if ($response->successful() && is_array($data) && ($data['status'] ?? false) === true) {
                return [
                    'success' => true,
                    'connected' => ($data['device_status'] ?? '') === 'connect',
                    'device' => $data['device'] ?? null,
                    'device_status' => $data['device_status'] ?? 'unknown',
                    'name' => $data['name'] ?? null,
                    'quota' => $data['quota'] ?? null,
                    'expired' => $data['expired'] ?? null,
                    'raw' => $data,
                ];
            }

            return [
                'success' => false,
                'connected' => false,
                'message' => $data['reason'] ?? 'Gagal mengambil status perangkat dari Fonnte.',
                'raw' => $data,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'connected' => false,
                'message' => 'Gagal koneksi ke Fonnte: ' . $this->sanitizeErrorMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Build informative, safe officer notification message without exposing citizen's sensitive documents/NIK.
     */
    public function buildOfficerMessage(Application $application, string $targetRole): string
    {
        $applicantName = $application->user?->name ?? 'Warga Pemohon';
        $appNumber = $application->application_number;
        $keperluan = $application->form_data['keperluan'] ?? ($application->purpose ?? 'Pengajuan SKTM');
        $timeStr = now()->timezone('Asia/Jakarta')->format('d/m/Y H:i');
        $actionUrl = app(PublicUrlService::class)->route('admin.pengajuan.show', $application->id);

        // Template "pengajuan baru" hanya untuk status SUBMITTED.
        if ($application->status === 'SUBMITTED') {
            return $this->buildNewSubmissionMessage($application);
        }

        if (in_array($targetRole, ['kades', 'kepala_desa']) || $application->status === 'HEAD_APPROVAL') {
            return "✍️ *MOHON PERSETUJUAN KEPALA DESA*\n\n" .
                "Yth. Bapak/Ibu Kepala Desa,\n\n" .
                "Berkas pengajuan SKTM berikut telah selesai diverifikasi kelengkapannya oleh Staf Pelayanan Desa dan menunggu persetujuan akhir Anda:\n\n" .
                "• Nomor Pengajuan: `{$appNumber}`\n" .
                "• Nama Pemohon: {$applicantName}\n" .
                "• Keperluan: {$keperluan}\n" .
                "• Waktu Verifikasi: {$timeStr} WIB\n" .
                "• Status: Menunggu Persetujuan Kepala Desa\n\n" .
                "Mohon berkenan meninjau dan memberikan persetujuan penerbitan surat melalui tautan berikut:\n" .
                "{$actionUrl}\n\n" .
                "Terima kasih.\n" .
                "_Pesan otomatis dari Sistem Pelayanan Desa Digital._";
        }

        if ($application->status === 'COMPLETED') {
            return "✅ *PEMBERITAHUAN SKTM SELESAI DITERBITKAN*\n\n" .
                "Halo Staf Pelayanan Desa,\n\n" .
                "Pengajuan SKTM berikut telah resmi disetujui oleh Kepala Desa dan dokumen surat resmi telah diterbitkan:\n\n" .
                "• Nomor Pengajuan: `{$appNumber}`\n" .
                "• Nama Pemohon: {$applicantName}\n" .
                "• Waktu: {$timeStr} WIB\n" .
                "• Status: Surat Resmi Selesai Diterbitkan\n\n" .
                "Berkas dan arsip pengajuan dapat dipantau melalui panel administrasi:\n" .
                "{$actionUrl}\n\n" .
                "Terima kasih.\n" .
                "_Pesan otomatis dari Sistem Pelayanan Desa Digital._";
        }

        if ($application->status === 'REJECTED') {
            return "❌ *PEMBERITAHUAN PENOLAKAN SKTM*\n\n" .
                "Halo Staf Pelayanan Desa,\n\n" .
                "Pengajuan SKTM berikut telah ditolak oleh Kepala Desa:\n\n" .
                "• Nomor Pengajuan: `{$appNumber}`\n" .
                "• Nama Pemohon: {$applicantName}\n" .
                "• Waktu: {$timeStr} WIB\n" .
                "• Status: Permohonan Ditolak\n\n" .
                "Silakan periksa catatan alasan penolakan melalui panel administrasi:\n" .
                "{$actionUrl}\n\n" .
                "Terima kasih.\n" .
                "_Pesan otomatis dari Sistem Pelayanan Desa Digital._";
        }

        return "🔔 *PEMBARUAN STATUS LAYANAN SKTM*\n\n" .
            "Halo Petugas Pelayanan Desa,\n\n" .
            "Terdapat pembaruan status pada permohonan SKTM berikut:\n\n" .
            "• Nomor Pengajuan: `{$appNumber}`\n" .
            "• Waktu: {$timeStr} WIB\n" .
            "• Status: {$application->status}\n\n" .
            "Silakan lihat detail selengkapnya melalui tautan berikut:\n" .
            "{$actionUrl}\n\n" .
            "_Pesan otomatis dari Sistem Pelayanan Desa Digital._";
    }

    /**
     * Ambil nomor telepon pengguna dari kolom users.phone_number atau relasi user_profiles.phone_number
     * dengan menangani nilai NULL maupun string kosong/spasi secara konsisten.
     */
    protected function resolveUserPhone(User $user): ?string
    {
        $user->loadMissing('profile');

        foreach ([$user->phone_number, $user->profile?->phone_number] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * Check if a notification for this application, type, and recipient was already sent (deduplication).
     */
    protected function isDuplicate(?string $applicationId, ?string $type, string $phone): bool
    {
        if (empty($applicationId) || empty($type)) {
            return false;
        }

        $cacheKey = "fonnte_sent_{$applicationId}_{$type}_{$phone}";

        return Cache::has($cacheKey);
    }

    /**
     * Mark notification as sent in cache to prevent duplicate dispatches.
     */
    protected function markAsSent(?string $applicationId, ?string $type, string $phone): void
    {
        if (empty($applicationId) || empty($type)) {
            return;
        }

        $cacheKey = "fonnte_sent_{$applicationId}_{$type}_{$phone}";
        Cache::put($cacheKey, true, now()->addHours(1));
    }

    /**
     * Sanitize sensitive credentials from error messages.
     */
    protected function sanitizeErrorMessage(?string $message): string
    {
        if (empty($message)) {
            return 'Unknown error';
        }

        if (!empty($this->token)) {
            $message = str_replace($this->token, '[REDACTED_FONNTE_TOKEN]', $message);
        }

        return preg_replace('/Authorization:\s*[A-Za-z0-9_\-\.]+/i', 'Authorization: [REDACTED]', $message);
    }

    /**
     * Bedakan status diterima gateway vs pending vs sent/delivered tanpa mengklaim delivered sepihak.
     *
     * @param  array<string, mixed>  $responseData
     */
    protected function resolveGatewayStatus(array $responseData): string
    {
        $process = strtolower(trim((string) ($responseData['process'] ?? '')));
        $detail = strtolower(trim((string) ($responseData['detail'] ?? '')));

        if ($process === 'delivered') {
            return 'DELIVERED';
        }

        if ($process === 'sent') {
            return 'SENT';
        }

        if (in_array($process, ['pending', 'queue', 'processing'], true) || str_contains($detail, 'queue')) {
            return 'PENDING';
        }

        return 'ACCEPTED';
    }

    protected function isPermanentApiError(int $httpStatus, string $reason): bool
    {
        $lower = strtolower($reason);

        foreach (['token invalid', 'invalid token', 'unauthorized', 'invalid target', 'empty message', 'not registered'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return $httpStatus >= 400 && $httpStatus < 500 && $httpStatus !== 429;
    }

    protected function shouldBlockPlaceholderTarget(string $formattedPhone): bool
    {
        if (! $this->isPlaceholderPhone($formattedPhone)) {
            return false;
        }

        if ((bool) config('services.fonnte.enforce_placeholder_block', false)) {
            return true;
        }

        return ! app()->runningUnitTests();
    }

    public function createQueuedLog(
        ?string $applicationId,
        ?string $userId,
        string $recipientPhone,
        ?string $recipientRole,
        string $type,
        string $messageType
    ): ?string {
        return $this->persistWhatsAppLog($applicationId, $userId, $recipientPhone, $recipientRole, $type, $messageType, 'QUEUED', null, null, null);
    }

    public function createPendingQueueLog(
        ?string $applicationId,
        ?string $userId,
        string $recipientPhone,
        ?string $recipientRole,
        string $type,
        string $messageType
    ): ?string {
        return $this->createQueuedLog($applicationId, $userId, $recipientPhone, $recipientRole, $type, $messageType);
    }

    public function persistSkippedLog(
        ?string $applicationId,
        ?string $userId,
        string $recipientPhone,
        ?string $recipientRole,
        string $type,
        string $messageType,
        string $reason
    ): ?string {
        return $this->persistWhatsAppLog($applicationId, $userId, $recipientPhone, $recipientRole, $type, $messageType, 'SKIPPED', null, $reason, null);
    }

    public function updateQueueLogStatus(
        ?string $logId,
        string $status,
        ?string $errorMessage = null,
        ?string $messageId = null,
        mixed $sentAt = null
    ): void {
        if (! $logId) {
            return;
        }

        try {
            if (! Schema::hasTable('whatsapp_notification_logs')) {
                return;
            }

            $log = WhatsAppNotificationLog::find($logId);
            if (! $log) {
                return;
            }

            $payload = ['status' => $status];
            if ($errorMessage !== null) {
                $payload['error_message'] = $this->sanitizeErrorMessage($errorMessage);
            } elseif (in_array($status, ['QUEUED', 'ACCEPTED', 'SENT', 'DELIVERED', 'PENDING', 'PROCESSING'], true)) {
                $payload['error_message'] = null;
            }
            if ($messageId !== null) {
                $payload['message_id'] = $messageId;
            }
            if ($sentAt !== null) {
                $payload['sent_at'] = $sentAt;
            }

            $log->update($payload);
        } catch (\Throwable $e) {
            Log::warning('FonnteService: Gagal memperbarui WhatsAppNotificationLog: ' . $e->getMessage());
        }
    }

    protected function saveOrUpdateWhatsAppLog(
        ?string $existingLogId,
        ?string $applicationId,
        ?string $userId,
        string $recipientPhone,
        ?string $recipientRole,
        string $type,
        string $messageType,
        string $status,
        ?string $messageId = null,
        ?string $errorMessage = null,
        mixed $sentAt = null
    ): ?string {
        if ($existingLogId) {
            $this->updateQueueLogStatus($existingLogId, $status, $errorMessage, $messageId, $sentAt);

            return $existingLogId;
        }

        return $this->persistWhatsAppLog($applicationId, $userId, $recipientPhone, $recipientRole, $type, $messageType, $status, $messageId, $errorMessage, $sentAt);
    }

    protected function persistWhatsAppLog(
        ?string $applicationId,
        ?string $userId,
        string $recipientPhone,
        ?string $recipientRole,
        string $type,
        string $messageType,
        string $status,
        ?string $messageId = null,
        ?string $errorMessage = null,
        mixed $sentAt = null
    ): ?string {
        try {
            if (! Schema::hasTable('whatsapp_notification_logs')) {
                return null;
            }

            $row = WhatsAppNotificationLog::create([
                'application_id' => $applicationId,
                'user_id' => $userId,
                'recipient_phone' => $recipientPhone,
                'recipient_role' => $recipientRole,
                'type' => $type,
                'message_type' => $messageType,
                'status' => $status,
                'message_id' => $messageId,
                'error_message' => $errorMessage,
                'sent_at' => $sentAt,
            ]);

            return (string) $row->id;
        } catch (\Throwable $e) {
            Log::warning('FonnteService: Gagal menyimpan WhatsAppNotificationLog: ' . $e->getMessage());

            return null;
        }
    }
}

