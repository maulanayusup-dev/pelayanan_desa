<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Application;

class ApplicationStatusChanged extends Notification
{
    use Queueable;

    public $application;
    public $message;
    public $type;
    public $actionUrl;

    /**
     * Create a new notification instance.
     */
    public function __construct(Application $application, $message, $type = 'info', ?string $actionUrl = null)
    {
        $this->application = $application;
        $this->message = $message;
        $this->type = $type; // info, success, warning, error
        $this->actionUrl = $actionUrl ?: ('/pengajuan/' . $application->id);
    }

    /**
     * Kanal pengiriman: HANYA notifikasi in-app (tabel `notifications`, ditampilkan di lonceng navbar).
     * Browser push / desktop notification sudah dihapus; email & WhatsApp dikirim lewat service masing-masing.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'application_number' => $this->application->application_number,
            'message' => $this->message,
            'type' => $this->type,
            'action_url' => $this->resolveActionUrlFor($notifiable)
        ];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $targetUrl = app(\App\Services\PublicUrlService::class)->to($this->resolveActionUrlFor($notifiable));

        return (new MailMessage)
            ->subject('[Pelayanan Desa] ' . $this->message)
            ->greeting('Halo, ' . $notifiable->name . '!')
            ->line($this->message)
            ->line('Nomor Pengajuan: ' . $this->application->application_number)
            ->action('Buka Halaman Pengajuan', $targetUrl)
            ->line('Waktu: ' . now()->translatedFormat('d F Y, H:i') . ' WIB')
            ->salutation("Salam hangat,\nPemerintah Desa Pelayanan Desa");
    }

    /**
     * Resolve target URL depending on recipient role.
     */
    public function resolveActionUrlFor(object $notifiable): string
    {
        $roleName = $notifiable->role->name ?? 'masyarakat';
        if (in_array($roleName, ['staf', 'supervisor', 'kades', 'kepala_desa'])) {
            return '/admin/pengajuan/' . $this->application->id;
        }

        return $this->actionUrl ?: ('/pengajuan/' . $this->application->id);
    }
}
