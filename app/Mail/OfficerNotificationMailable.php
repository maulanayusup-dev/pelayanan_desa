<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OfficerNotificationMailable extends Mailable
{
    use Queueable, SerializesModels;

    public User $officer;
    public Application $application;
    public string $actionTitle;
    public string $actionDescription;

    /**
     * Create a new message instance.
     */
    public function __construct(User $officer, Application $application, string $actionTitle, string $actionDescription)
    {
        $this->officer = $officer;
        $this->application = $application;
        $this->actionTitle = $actionTitle;
        $this->actionDescription = $actionDescription;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Petugas Pelayanan Desa] ' . $this->actionTitle . ' - ' . $this->application->application_number,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.officer-notification',
            with: [
                'officer' => $this->officer,
                'application' => $this->application,
                'actionTitle' => $this->actionTitle,
                'actionDescription' => $this->actionDescription,
            ],
        );
    }
}
