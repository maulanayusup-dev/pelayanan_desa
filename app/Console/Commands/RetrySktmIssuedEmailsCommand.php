<?php

namespace App\Console\Commands;

use App\Services\SktmIssuanceService;
use Illuminate\Console\Command;

/**
 * Kirim ulang email penerbitan SKTM yang belum berhasil (SMTP gagal / proses terhenti).
 * Aman diulang: dokumen yang sudah ditandai terkirim dilewati, dan cache lock mencegah pengiriman ganda.
 */
class RetrySktmIssuedEmailsCommand extends Command
{
    protected $signature = 'sktm:retry-issued-emails {--days= : Hanya PDF yang diterbitkan dalam N hari terakhir (default = masa berlaku link)}';

    protected $description = 'Kirim ulang email penerbitan SKTM (tombol download) yang belum berhasil terkirim';

    public function handle(SktmIssuanceService $issuance): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;
        $summary = $issuance->retryPending($days);

        if (empty($summary)) {
            $this->info('Tidak ada email penerbitan SKTM yang tertunda.');
            return self::SUCCESS;
        }

        foreach ($summary as $result => $count) {
            $this->line("{$result}: {$count}");
        }

        return self::SUCCESS;
    }
}
