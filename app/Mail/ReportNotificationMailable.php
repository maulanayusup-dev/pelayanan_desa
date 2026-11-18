<?php

namespace App\Mail;

use App\Models\SystemReport;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReportNotificationMailable extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $recipient,
        public SystemReport $report,
        public string $eventTitle,
        public string $eventDescription,
        public string $actionUrl,
        public string $actionLabel = 'Buka Detail Laporan'
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Laporan Pelayanan Desa] ' . $this->eventTitle . ' (' . $this->report->ticket_number . ')',
        );
    }

    public function content(): Content
    {
        $reporterRole = match ($this->report->reporter_role) {
            'masyarakat' => 'Warga / Masyarakat',
            'staf' => 'Staf Pelayanan Desa',
            'kades', 'kepala_desa' => 'Kepala Desa',
            'supervisor' => 'Supervisor',
            default => $this->report->reporter_role ? ucfirst($this->report->reporter_role) : 'Pengguna',
        };

        return new Content(
            view: 'emails.report-notification',
            with: [
                'recipient' => $this->recipient,
                'report' => $this->report,
                'eventTitle' => $this->eventTitle,
                'eventDescription' => $this->eventDescription,
                'actionUrl' => $this->actionUrl,
                'actionLabel' => $this->actionLabel,
                'reporterName' => $this->report->reporter?->name ?? 'Pengguna',
                'reporterRoleLabel' => $reporterRole,
                'categoryLabel' => SystemReport::categoryLabel($this->report->category),
                'priorityLabel' => SystemReport::priorityLabel($this->report->priority),
                'statusLabel' => SystemReport::statusLabel($this->report->status),
            ],
        );
    }
}

