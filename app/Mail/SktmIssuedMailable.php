<?php

namespace App\Mail;

use App\Models\Application;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email ke warga setelah Kepala Desa menyetujui dan PDF SKTM berhasil diterbitkan.
 * Berisi tombol DOWNLOAD SURAT SKTM (signed URL, tanpa login).
 */
class SktmIssuedMailable extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Application $application,
        public string $downloadUrl,
        public CarbonInterface $expiresAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Pelayanan Desa] SKTM ' . $this->application->application_number . ' Telah Diterbitkan',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.sktm-issued',
            with: [
                'application' => $this->application,
                'downloadUrl' => $this->downloadUrl,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }
}
