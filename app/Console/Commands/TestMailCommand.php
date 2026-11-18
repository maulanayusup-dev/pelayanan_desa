<?php

namespace App\Console\Commands;

use App\Mail\TestSmtpMailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestMailCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:test {email? : Alamat email tujuan pengujian}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kirim email uji coba untuk memverifikasi konfigurasi Gmail SMTP';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $recipient = $this->argument('email') ?: config('mail.from.address');

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->error("Alamat email tidak valid: {$recipient}");
            return 1;
        }

        $mailer = config('mail.default');
        $host = config("mail.mailers.{$mailer}.host", '-');
        $port = config("mail.mailers.{$mailer}.port", '-');
        $encryption = config("mail.mailers.{$mailer}.encryption") ?? config("mail.mailers.{$mailer}.scheme") ?? 'tls';
        $username = config("mail.mailers.{$mailer}.username");
        $from = config('mail.from.address');
        $fromName = config('mail.from.name');

        // Mask username for security
        $maskedUser = '-';
        if ($username) {
            $parts = explode('@', $username);
            $namePart = $parts[0] ?? '';
            $domainPart = $parts[1] ?? '';
            $maskedName = strlen($namePart) > 2 ? substr($namePart, 0, 2) . '***' : '***';
            $maskedUser = $domainPart ? "{$maskedName}@{$domainPart}" : '***';
        }

        $sourceIp = config("mail.mailers.{$mailer}.source_ip", '0.0.0.0');

        $this->info('=== UJI COBA PENGIRIMAN EMAIL SMTP PELAYANAN DESA ===');
        $this->table(
            ['Parameter', 'Nilai'],
            [
                ['Driver / Mailer', $mailer],
                ['SMTP Host', $host],
                ['SMTP Port', $port],
                ['Enkripsi', $encryption],
                ['Username (Akun Pengirim)', $maskedUser],
                ['From Address', $from],
                ['From Name', $fromName],
                ['Source IP (IPv4 Stack)', $sourceIp ?: 'Default (Dual Stack)'],
                ['Tujuan Uji Coba', $recipient],
            ]
        );

        $this->line("Mengirim email uji coba ke: {$recipient}...");

        /** @var \App\Services\EmailNotificationService $emailService */
        $emailService = app(\App\Services\EmailNotificationService::class);
        $sent = $emailService->sendSafe($recipient, new TestSmtpMailable(), ['type' => 'SMTP_TEST']);

        if ($sent) {
            $this->info("✓ Diterima oleh server SMTP untuk tujuan {$recipient}.");
            $this->comment('Catatan: Status diterima server SMTP bukan jaminan langsung masuk Inbox. Silakan periksa Inbox/Spam/bounce di akun penerima.');
            return 0;
        }

        $this->error('✗ Gagal mengirim email uji coba ke server SMTP (periksa email tujuan, domain dummy @desa.id, atau log sistem).');
        $this->line('');
        $this->warn('Petunjuk Troubleshooting Gmail SMTP:');
        $this->line('1. Jangan gunakan alamat email dummy @desa.id pada pengiriman nyata.');
        $this->line('2. Pastikan fitur 2-Step Verification aktif di Akun Google Anda.');
        $this->line('3. Gunakan "Google App Password" khusus (16 karakter) di MAIL_PASSWORD.');
        $this->line('4. Pastikan MAIL_USERNAME dan MAIL_FROM_ADDRESS cocok dengan akun Gmail pengirim.');
        $this->line('5. Jika terjadi timeout koneksi, pastikan MAIL_SOURCE_IP=0.0.0.0 di .env.');
        return 1;
    }
}
