<?php

namespace App\Mail;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApplicationStatusChangedMailable extends Mailable
{
    use Queueable, SerializesModels;

    public Application $application;
    public string $statusLabel;
    public string $statusDescription;
    public ?string $note;

    /**
     * Create a new message instance.
     */
    public function __construct(Application $application, string $statusLabel, string $statusDescription, ?string $note = null)
    {
        $this->application = $application;
        $this->statusLabel = $statusLabel;
        $this->statusDescription = $statusDescription;
        $this->note = $note;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Pelayanan Desa] Status Pengajuan ' . $this->application->application_number . ': ' . $this->statusLabel,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.application-status-changed',
            with: [
                'application' => $this->application,
                'statusLabel' => $this->statusLabel,
                'statusDescription' => $this->statusDescription,
                'note' => $this->note,
            ],
        );
    }
}
