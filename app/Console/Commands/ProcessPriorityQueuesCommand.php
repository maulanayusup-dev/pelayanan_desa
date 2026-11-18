<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ProcessPriorityQueuesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:work-priority
                            {--connection=database : Nama koneksi queue}
                            {--sleep=3 : Waktu tidur dalam detik saat antrean kosong}
                            {--tries=3 : Jumlah percobaan maksimal sebelum job dinyatakan gagal}
                            {--timeout=30 : Batas waktu eksekusi job dalam detik}
                            {--stop-when-empty : Hentikan worker ketika seluruh antrean prioritas kosong}
                            {--max-jobs= : Batas maksimal job yang diproses sebelum berhenti}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Jalankan queue worker untuk seluruh antrean prioritas aplikasi Pelayanan Desa (sktm, laporan-prioritas, laporan, default)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connection = (string) $this->option('connection');
        $priorityQueues = (array) config('queue.priority_queues', ['sktm', 'laporan-prioritas', 'laporan', 'default']);
        $queueParam = implode(',', $priorityQueues);

        $this->info("Menjalankan queue worker untuk koneksi [{$connection}] antrean [{$queueParam}]...");

        $params = [
            'connection' => $connection,
            '--queue' => $queueParam,
            '--sleep' => (int) $this->option('sleep'),
            '--tries' => (int) $this->option('tries'),
            '--timeout' => (int) $this->option('timeout'),
        ];

        if ($this->option('stop-when-empty')) {
            $params['--stop-when-empty'] = true;
        }

        if ($this->option('max-jobs')) {
            $params['--max-jobs'] = (int) $this->option('max-jobs');
        }

        return $this->call('queue:work', $params);
    }
}

