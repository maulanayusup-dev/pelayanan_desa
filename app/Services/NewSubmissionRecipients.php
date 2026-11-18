<?php

namespace App\Services;

use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Satu sumber aturan penerima notifikasi PENGAJUAN SKTM BARU (status SUBMITTED)
 * untuk semua kanal aktif: WhatsApp Fonnte, notifikasi in-app (lonceng), dan email petugas (Gmail SMTP).
 *
 * Aturan: hanya pengguna aktif dengan role STAF yang menerima. SUPERVISOR, KEPALA_DESA,
 * dan warga pemohon tidak menerima notifikasi internal ini.
 */
class NewSubmissionRecipients
{
    /** Role yang menerima notifikasi pengajuan baru. */
    public const ROLES = ['staf'];

    /** Role yang tidak boleh menerima notifikasi pengajuan baru (kontaknya juga diblokir). */
    public const EXCLUDED_ROLES = ['supervisor', 'kades', 'kepala_desa'];

    public static function isNewSubmission(Application $application): bool
    {
        return $application->status === 'SUBMITTED';
    }

    /**
     * Pengguna STAF aktif yang berhak menerima, tanpa warga pemohon.
     *
     * @return Collection<int, User>
     */
    public static function staff(Application $application): Collection
    {
        return User::with(['role', 'profile'])
            ->whereHas('role', fn ($q) => $q->whereIn('name', self::ROLES))
            ->where('is_active', true)
            ->where('id', '!=', $application->user_id)
            ->get();
    }

    /**
     * Pengguna yang kontaknya (nomor HP) tidak boleh menerima notifikasi pengajuan baru:
     * semua akun SUPERVISOR / KEPALA_DESA dan warga pemohon. Dipakai kanal WhatsApp untuk
     * melewati nomor staf yang ternyata sama dengan nomor mereka.
     *
     * @return Collection<int, User>
     */
    public static function excludedContacts(Application $application): Collection
    {
        $users = User::with(['role', 'profile'])
            ->whereHas('role', fn ($q) => $q->whereIn('name', self::EXCLUDED_ROLES))
            ->get();

        $application->loadMissing('user.profile', 'user.role');
        if ($application->user) {
            $users->push($application->user);
        }

        return $users;
    }

    /**
     * Klaim atomik satu kali kirim per (kanal, pengajuan, waktu submit, penerima).
     * Mengembalikan false bila sudah pernah diklaim, sehingga pemanggilan ganda tidak menggandakan notifikasi.
     */
    public static function claim(string $channel, Application $application, string $recipientKey): bool
    {
        return Cache::add(self::claimKey($channel, $application, $recipientKey), true, now()->addDay());
    }

    /**
     * Lepas klaim saat pengiriman gagal agar percobaan ulang tetap dimungkinkan.
     */
    public static function release(string $channel, Application $application, string $recipientKey): void
    {
        Cache::forget(self::claimKey($channel, $application, $recipientKey));
    }

    private static function claimKey(string $channel, Application $application, string $recipientKey): string
    {
        $submittedAt = $application->submitted_at?->timestamp ?? 'na';

        return "new_submission_notif_{$channel}_{$application->id}_{$submittedAt}_{$recipientKey}";
    }
}
