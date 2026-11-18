<?php

namespace App\Services;

use App\Mail\ApplicationConfirmationMailable;
use App\Mail\ApplicationStatusChangedMailable;
use App\Mail\OfficerNotificationMailable;
use App\Mail\TestSmtpMailable;
use App\Models\Application;
use App\Models\EmailNotificationLog;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Testing\Fakes\MailFake;

class EmailNotificationService
{
    /** @var list<string> */
    private const DUMMY_DOMAINS = ['@desa.id', '@example.com', '@example.org', '@test.local'];

    /**
     * Memory cache per penerima untuk mencegah duplikasi dalam siklus request yang sama
     * HANYA setelah pengiriman ke server SMTP berhasil diterima.
     */
    protected static array $dispatchedEvents = [];

    /**
     * Notify applicant and relevant officers regarding status changes or new submissions.
     * Pengiriman email warga dan petugas dipisah agar email petugas tetap terkirim meski email warga kosong.
     */
    public function notifyStatusChange(Application $application): void
    {
        $application->loadMissing('user');
        $applicant = $application->user;

        // 1. Notify Applicant (Citizen) — skip only citizen email if email kosong/tidak valid
        $this->notifyCitizen($application, $applicant);

        // 2. Notify Authorized Internal Officers based on workflow stage (tetap jalan walau email warga kosong)
        $this->notifyOfficersForWorkflow($application, $applicant);
    }

    /**
     * Send email notification strictly to the citizen applicant if email is available.
     */
    protected function notifyCitizen(Application $application, ?User $applicant): void
    {
        if (!$applicant || empty($applicant->email) || ($this->isDummyEmail($applicant->email) && $this->shouldBlockDummyTransmission())) {
            if ($applicant) {
                $isDummy = !empty($applicant->email) && $this->isDummyEmail($applicant->email);
                $reason = $isDummy
                    ? 'Alamat email warga menggunakan domain dummy (@desa.id).'
                    : 'Alamat email warga kosong/tidak tersedia.';
                Log::info("EmailNotificationService: Email warga {$applicant->name} tidak valid atau kosong untuk pengajuan {$application->application_number}, pengiriman eksternal dilewati aman (SKIPPED_NO_VALID_RECIPIENT).");
                $this->persistEmailLog($applicant->email ?? '', ApplicationStatusChangedMailable::class, 'STATUS_CHANGED_' . $application->status, 'SKIPPED_NO_VALID_RECIPIENT', $isDummy, $reason, [
                    'application_id' => $application->id,
                    'user_id' => $applicant->id,
                    'recipient_role' => 'masyarakat',
                ]);
            }

            return;
        }

        $submittedTs = $application->submitted_at?->timestamp ?? 'na';
        $citizenKey = $application->id . ':' . $application->status . ':citizen:' . $applicant->id . ':' . $submittedTs;
        $cacheKey = 'email_sent_' . sha1($citizenKey);
        if (isset(self::$dispatchedEvents[$citizenKey]) || \Illuminate\Support\Facades\Cache::has($cacheKey)) {
            return;
        }

        if ($application->status === 'SUBMITTED') {
            $sent = $this->sendSafe(
                $applicant->email,
                new ApplicationConfirmationMailable($application),
                [
                    'application_id' => $application->id,
                    'user_id' => $applicant->id,
                    'recipient_role' => 'masyarakat',
                    'type' => 'CONFIRMATION',
                ]
            );
            if ($sent) {
                self::$dispatchedEvents[$citizenKey] = true;
                \Illuminate\Support\Facades\Cache::put($cacheKey, true, now()->addMinutes(30));
            }
        } elseif ($application->status === 'APPROVED') {
            // Alur persetujuan Kepala Desa: email "disetujui" tidak dikirim terpisah.
            // Warga menerima SATU email akhir (tombol download) setelah PDF SKTM berhasil diterbitkan (status COMPLETED).
        } elseif ($application->status === 'COMPLETED') {
            // Email penerbitan SKTM dengan tombol download; hanya terkirim bila PDF sudah tersimpan,
            // satu kali per dokumen, dan tidak ditandai terkirim bila SMTP gagal.
            $result = app(SktmIssuanceService::class)->sendIssuedEmail($application);
            if (in_array($result, ['sent', 'already_sent'], true)) {
                self::$dispatchedEvents[$citizenKey] = true;
                \Illuminate\Support\Facades\Cache::put($cacheKey, true, now()->addMinutes(30));
            }
        } else {
            $statusMeta = $this->resolveStatusMeta($application);
            if ($statusMeta) {
                $sent = $this->sendSafe(
                    $applicant->email,
                    new ApplicationStatusChangedMailable(
                        $application,
                        $statusMeta['label'],
                        $statusMeta['description'],
                        $statusMeta['note']
                    ),
                    [
                        'application_id' => $application->id,
                        'user_id' => $applicant->id,
                        'recipient_role' => 'masyarakat',
                        'type' => 'STATUS_CHANGED_' . $application->status,
                    ]
                );
                if ($sent) {
                    self::$dispatchedEvents[$citizenKey] = true;
                    \Illuminate\Support\Facades\Cache::put($cacheKey, true, now()->addMinutes(30));
                }
            }
        }
    }

    /**
     * Notify relevant administrative staff / head based on workflow stage (Masyarakat -> Staf -> Kepala Desa).
     */
    protected function notifyOfficersForWorkflow(Application $application, ?User $applicant): void
    {
        $applicantName = $applicant?->name ?? 'Warga Pemohon';

        $stageConfig = match ($application->status) {
            // Pengajuan baru: HANYA STAF (aturan bersama di NewSubmissionRecipients)
            'SUBMITTED' => [
                'roles' => NewSubmissionRecipients::ROLES,
                'type' => 'OFFICER_NEW_SUBMISSION',
                'title' => 'Pengajuan SKTM Baru Masuk Antrean',
                'description' => "Pengajuan SKTM baru dari warga ({$applicantName}) telah masuk ke antrean verifikasi. Silakan periksa kelengkapan berkas KTP dan Kartu Keluarga pemohon.",
            ],
            'HEAD_APPROVAL' => [
                'roles' => ['kades', 'kepala_desa'],
                'type' => 'OFFICER_HEAD_APPROVAL',
                'title' => 'Pengajuan Menunggu Persetujuan Kepala Desa',
                'description' => "Pengajuan SKTM ({$application->application_number}) telah diverifikasi oleh Staf dan saat ini menunggu persetujuan/tanda tangan akhir Anda.",
            ],
            default => null,
        };

        if (!$stageConfig) {
            return;
        }

        $isNewSubmission = NewSubmissionRecipients::isNewSubmission($application);

        $officers = $isNewSubmission
            ? NewSubmissionRecipients::staff($application)
            : User::with('role')
                ->whereHas('role', function ($query) use ($stageConfig) {
                    $query->whereIn('name', $stageConfig['roles']);
                })
                ->where('is_active', true)
                ->get();

        foreach ($officers as $officer) {
            if ($applicant && $officer->id === $applicant->id) {
                continue;
            }

            $roleLabel = $officer->role?->name ?? ($isNewSubmission ? 'staf' : 'kades');
            if (empty($officer->email) || ($this->isDummyEmail($officer->email) && $this->shouldBlockDummyTransmission())) {
                $isDummy = !empty($officer->email) && $this->isDummyEmail($officer->email);
                $reason = $isDummy
                    ? 'Alamat email petugas menggunakan domain dummy seeder (@desa.id), kontak sengaja dikosongkan.'
                    : 'Alamat email petugas kosong atau sengaja dikosongkan.';
                Log::info("EmailNotificationService: Email petugas {$officer->name} (role: {$roleLabel}) tidak valid atau sengaja dikosongkan untuk pengajuan {$application->application_number}, pengiriman eksternal dilewati aman (SKIPPED_NO_VALID_RECIPIENT).");
                $this->persistEmailLog($officer->email ?? '', OfficerNotificationMailable::class, $stageConfig['type'], 'SKIPPED_NO_VALID_RECIPIENT', $isDummy, $reason, [
                    'application_id' => $application->id,
                    'user_id' => $officer->id,
                    'recipient_role' => $roleLabel,
                ]);
                continue;
            }

            $submittedTs = $application->submitted_at?->timestamp ?? 'na';
            $officerKey = $application->id . ':' . $application->status . ':officer:' . $officer->id . ':' . $submittedTs;
            $officerCacheKey = 'email_sent_' . sha1($officerKey);
            if (isset(self::$dispatchedEvents[$officerKey]) || \Illuminate\Support\Facades\Cache::has($officerCacheKey)) {
                continue;
            }

            // Pengajuan baru: kunci lintas-request agar email staf tidak terkirim ganda
            if ($isNewSubmission && !NewSubmissionRecipients::claim('email', $application, (string) $officer->id)) {
                continue;
            }

            $sent = $this->sendSafe(
                $officer->email,
                new OfficerNotificationMailable(
                    $officer,
                    $application,
                    $stageConfig['title'],
                    $stageConfig['description']
                ),
                [
                    'application_id' => $application->id,
                    'user_id' => $officer->id,
                    'recipient_role' => $roleLabel,
                    'type' => $stageConfig['type'],
                ]
            );

            if ($sent) {
                self::$dispatchedEvents[$officerKey] = true;
                \Illuminate\Support\Facades\Cache::put($officerCacheKey, true, now()->addMinutes(30));
            } elseif ($isNewSubmission) {
                // Lepas klaim agar kegagalan SMTP sementara tetap bisa dicoba ulang
                NewSubmissionRecipients::release('email', $application, (string) $officer->id);
            }
        }
    }

    /**
     * Resolve human-readable status metadata for citizen notification.
     */
    protected function resolveStatusMeta(Application $application): ?array
    {
        $latestReview = $application->reviews()->latest()->first();
        $latestHistory = $application->histories()->latest()->first();
        $note = $latestReview?->notes ?: ($latestHistory?->description ?: null);

        return match ($application->status) {
            'UNDER_REVIEW' => [
                'label' => 'Sedang Diperiksa Petugas',
                'description' => 'Berkas Anda sedang dalam proses pemeriksaan verifikasi administrasi oleh Staf Pelayanan Desa.',
                'note' => $note,
            ],
            'NEEDS_REVISION' => [
                'label' => 'Membutuhkan Perbaikan Dokumen',
                'description' => 'Terdapat dokumen yang perlu diperbaiki atau diunggah ulang agar permohonan dapat diproses.',
                'note' => $note,
            ],
            'HEAD_APPROVAL' => [
                'label' => 'Menunggu Persetujuan Kepala Desa',
                'description' => 'Pengajuan Anda telah diverifikasi oleh Staf dan sedang menunggu persetujuan serta tanda tangan Kepala Desa.',
                'note' => $note,
            ],
            'APPROVED' => [
                'label' => 'Pengajuan Disetujui',
                'description' => 'Selamat! Permohonan Surat Keterangan Tidak Mampu Anda telah disetujui oleh Kepala Desa.',
                'note' => $note,
            ],
            'REJECTED' => [
                'label' => 'Pengajuan Ditolak',
                'description' => 'Mohon maaf, pengajuan surat Anda belum dapat disetujui setelah dilakukan peninjauan.',
                'note' => $note,
            ],
            'COMPLETED' => [
                'label' => 'Dokumen Diterbitkan',
                'description' => 'Dokumen resmi Surat Keterangan Tidak Mampu Anda telah diterbitkan dan siap diunduh atau dicetak.',
                'note' => $note,
            ],
            default => null,
        };
    }

    /**
     * Send test email to verify SMTP configuration.
     */
    public function sendTestEmail(string $recipientEmail): bool
    {
        return $this->sendSafe($recipientEmail, new TestSmtpMailable(), ['type' => 'SMTP_TEST']);
    }

    /**
     * Deteksi alamat email dummy/seeder (seperti @desa.id) yang tidak terhubung ke kotak masuk nyata.
     */
    public function isDummyEmail(string $email): bool
    {
        $lower = strtolower(trim($email));
        foreach (self::DUMMY_DOMAINS as $domain) {
            if (str_ends_with($lower, $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Safe sending wrapper that logs SMTP outcome without conflating SMTP acceptance with Inbox delivery.
     *
     * @param  array<string, mixed>  $context
     */
    public function sendSafe(string $email, Mailable $mailable, array $context = []): bool
    {
        $cleanEmail = trim($email);
        $mailableClass = get_class($mailable);
        $type = (string) ($context['type'] ?? 'NOTIFICATION');
        $correlationId = $context['correlation_id'] ?? null;
        $reportId = $context['report_id'] ?? null;

        if ($cleanEmail === '' || !filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            Log::warning("EmailNotificationService: Alamat email tidak valid: {$email}");
            Log::warning('ReportNotificationTrace: SMTP response received.', [
                'correlation_id' => $correlationId,
                'report_id' => $reportId,
                'application_id' => $context['application_id'] ?? null,
                'type' => $type,
                'status' => 'SKIPPED_INVALID_EMAIL',
            ]);
            $this->persistEmailLog($cleanEmail, $mailableClass, $type, 'SKIPPED_INVALID_EMAIL', false, 'Format alamat email tidak valid.', $context);

            return false;
        }

        $isDummy = $this->isDummyEmail($cleanEmail);
        $masked = $this->maskEmail($cleanEmail);

        if ($isDummy) {
            Log::warning("EmailNotificationService: Terdeteksi alamat email dummy/seeder ({$masked}). Pastikan kontak email nyata diisi.");
            if ($this->shouldBlockDummyTransmission()) {
                $reason = 'Alamat email menggunakan domain dummy (@desa.id / seeder), pengiriman SMTP nyata dibatalkan.';
                Log::warning('ReportNotificationTrace: SMTP response received.', [
                    'correlation_id' => $correlationId,
                    'report_id' => $reportId,
                    'application_id' => $context['application_id'] ?? null,
                    'type' => $type,
                    'email_masked' => $masked,
                    'status' => 'SKIPPED_DUMMY_DOMAIN',
                ]);
                $this->persistEmailLog($cleanEmail, $mailableClass, $type, 'SKIPPED_DUMMY_DOMAIN', true, $reason, $context);

                return false;
            }
        }

        if (! (Mail::getFacadeRoot() instanceof MailFake) && config('mail.default') === 'smtp') {
            $smtpError = $this->validateSmtpConfiguration();
            if ($smtpError !== null) {
                Log::error("EmailNotificationService: Konfigurasi SMTP tidak valid sebelum mengirim ke {$masked} [Type: {$type}]: {$smtpError}");
                Log::warning('ReportNotificationTrace: SMTP response received.', [
                    'correlation_id' => $correlationId,
                    'report_id' => $reportId,
                    'application_id' => $context['application_id'] ?? null,
                    'type' => $type,
                    'email_masked' => $masked,
                    'status' => 'SMTP_FAILED',
                    'error' => $smtpError,
                ]);
                $this->persistEmailLog($cleanEmail, $mailableClass, $type, 'SMTP_FAILED', $isDummy, $smtpError, $context);

                return false;
            }
        }

        try {
            Mail::to($cleanEmail)->send($mailable);
            Log::info("EmailNotificationService: Email diterima server SMTP untuk {$masked} [Type: {$type}] (catatan: diterimanya oleh server SMTP belum menjamin masuk Inbox / tidak bounce).");
            Log::info('ReportNotificationTrace: SMTP response received.', [
                'correlation_id' => $correlationId,
                'report_id' => $reportId,
                'application_id' => $context['application_id'] ?? null,
                'type' => $type,
                'email_masked' => $masked,
                'status' => 'SMTP_ACCEPTED',
            ]);
            $this->persistEmailLog($cleanEmail, $mailableClass, $type, 'SMTP_ACCEPTED', $isDummy, null, $context, now());

            return true;
        } catch (\Throwable $e) {
            $classifiedError = $this->classifySmtpFailure($e->getMessage());
            Log::warning("EmailNotificationService: Gagal mengirim email ke {$masked} [Type: {$type}]. Error: {$classifiedError}");
            Log::warning('ReportNotificationTrace: SMTP response received.', [
                'correlation_id' => $correlationId,
                'report_id' => $reportId,
                'application_id' => $context['application_id'] ?? null,
                'type' => $type,
                'email_masked' => $masked,
                'status' => 'SMTP_FAILED',
                'error' => $classifiedError,
            ]);
            $this->persistEmailLog($cleanEmail, $mailableClass, $type, 'SMTP_FAILED', $isDummy, $classifiedError, $context);

            return false;
        }
    }

    /**
     * Coba ulang pengiriman email yang sebelumnya gagal (SMTP_FAILED) untuk pengajuan terkait
     * tanpa perlu membuat pengajuan atau laporan baru.
     *
     * @return array{retried:int, succeeded:int, failed:int}
     */
    public function retryFailedApplicationNotifications(Application $application): array
    {
        $failedLogs = EmailNotificationLog::where('application_id', $application->id)
            ->where('status', 'SMTP_FAILED')
            ->exists();

        if (! $failedLogs) {
            return ['retried' => 0, 'succeeded' => 0, 'failed' => 0];
        }

        $beforeFailed = EmailNotificationLog::where('application_id', $application->id)->where('status', 'SMTP_FAILED')->count();
        $beforeAccepted = EmailNotificationLog::where('application_id', $application->id)->where('status', 'SMTP_ACCEPTED')->count();

        self::resetDeduplication();
        $this->notifyStatusChange($application->fresh(['user']) ?? $application);

        $afterFailed = EmailNotificationLog::where('application_id', $application->id)->where('status', 'SMTP_FAILED')->count();
        $afterAccepted = EmailNotificationLog::where('application_id', $application->id)->where('status', 'SMTP_ACCEPTED')->count();

        $succeeded = max(0, $afterAccepted - $beforeAccepted);
        $failed = max(0, $afterFailed - $beforeFailed);

        return [
            'retried' => $succeeded + $failed,
            'succeeded' => $succeeded,
            'failed' => $failed,
        ];
    }

    public function validateSmtpConfiguration(): ?string
    {
        $host = trim((string) config('mail.mailers.smtp.host', ''));
        $port = (string) config('mail.mailers.smtp.port', '');
        $username = trim((string) config('mail.mailers.smtp.username', ''));
        $password = (string) config('mail.mailers.smtp.password', '');
        $fromAddress = trim((string) config('mail.from.address', ''));

        if ($host === '' || $port === '') {
            return '[SMTP Config Error] Host atau port SMTP belum dikonfigurasi.';
        }

        if ($username === '' || trim($password) === '') {
            return '[SMTP Auth Config Error] MAIL_USERNAME atau MAIL_PASSWORD (App Password) belum diisi pada konfigurasi SMTP.';
        }

        if ($fromAddress === '' || ! filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            return '[SMTP Config Error] MAIL_FROM_ADDRESS tidak valid.';
        }

        return null;
    }

    protected function classifySmtpFailure(?string $rawMessage): string
    {
        $safe = $this->sanitizeErrorMessage($rawMessage);
        $lower = strtolower($safe);

        if (str_contains($lower, '530') || str_contains($lower, '535') || str_contains($lower, 'authentication') || str_contains($lower, 'username and password not accepted')) {
            return '[SMTP Auth Failed] ' . $safe;
        }

        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout') || str_contains($lower, 'connection could not be established') || str_contains($lower, 'connection refused')) {
            return '[SMTP Timeout/Connection Failed] ' . $safe;
        }

        return $safe;
    }

    /**
     * Reset deduplication registry (mainly for testing).
     */
    public static function resetDeduplication(): void
    {
        self::$dispatchedEvents = [];
    }

    protected function shouldBlockDummyTransmission(): bool
    {
        if (! (bool) config('mail.block_dummy_domains', true)) {
            return false;
        }

        if ((bool) config('mail.enforce_dummy_block', false)) {
            return true;
        }

        $isFake = Mail::getFacadeRoot() instanceof MailFake;

        return ! $isFake && config('mail.default') === 'smtp';
    }

    protected function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $email, 2);

        return substr($local, 0, min(3, strlen($local))) . '***@' . $domain;
    }

    protected function sanitizeErrorMessage(?string $message): string
    {
        if (empty($message)) {
            return 'Unknown SMTP error';
        }

        $password = (string) config('mail.mailers.smtp.password', '');
        if ($password !== '') {
            $message = str_replace($password, '[REDACTED_MAIL_PASSWORD]', $message);
            $trimmed = trim($password);
            if ($trimmed !== '') {
                $message = str_replace($trimmed, '[REDACTED_MAIL_PASSWORD]', $message);
            }
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function persistEmailLog(
        string $email,
        string $mailableClass,
        string $type,
        string $status,
        bool $isDummy,
        ?string $error = null,
        array $context = [],
        mixed $acceptedAt = null
    ): void {
        try {
            if (! Schema::hasTable('email_notification_logs')) {
                return;
            }

            EmailNotificationLog::create([
                'application_id' => $context['application_id'] ?? null,
                'user_id' => $context['user_id'] ?? null,
                'recipient_email' => $email,
                'recipient_role' => $context['recipient_role'] ?? null,
                'mailable_class' => $mailableClass,
                'type' => $type,
                'status' => $status,
                'is_dummy_domain' => $isDummy,
                'error_message' => $error,
                'accepted_at' => $acceptedAt,
            ]);
        } catch (\Throwable $e) {
            Log::warning('EmailNotificationService: Gagal menyimpan EmailNotificationLog: ' . $e->getMessage());
        }
    }
}
