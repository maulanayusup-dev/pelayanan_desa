<?php

namespace App\Notifications;

use App\Models\SystemReport;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ReportNotification extends Notification
{
    use Queueable;

    public function __construct(
        public SystemReport $report,
        public string $message,
        public string $type = 'info',
        public string $event = 'REPORT_UPDATED'
    ) {
    }

    /**
     * Kanal pengiriman: notifikasi web in-app melalui tabel `notifications`.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'report_id' => $this->report->id,
            'ticket_number' => $this->report->ticket_number,
            'message' => $this->message,
            'type' => $this->type,
            'event' => $this->event,
            'action_url' => $this->resolveActionUrlFor($notifiable),
        ];
    }

    public function resolveActionUrlFor(object $notifiable): string
    {
        $roleName = $notifiable->role?->name ?? 'masyarakat';
        if ($roleName === 'supervisor') {
            return '/admin/laporan/' . $this->report->id;
        }

        return '/laporan/' . $this->report->id;
    }
}

