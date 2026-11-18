<?php

namespace App\Console\Commands;

use App\Services\FonnteService;
use Illuminate\Console\Command;

class FonnteTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fonnte:test 
                            {phone? : Nomor WhatsApp penerima pengujian (contoh: 081234567890)}
                            {--message= : Pesan kustom pengujian}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Uji koneksi dan pengiriman pesan WhatsApp via Fonnte API Gateway';

    /**
     * Execute the console command.
     */
    public function handle(FonnteService $fonnteService): int
    {
        $this->info("=======================================================");
        $this->info("  Uji Integrasi WhatsApp Fonnte Gateway               ");
        $this->info("=======================================================");

        $isConfigured = $fonnteService->isConfigured();
        $isEnabled = $fonnteService->isEnabled();

        $this->table(
            ['Parameter', 'Nilai'],
            [
                ['Status Aktif (FONNTE_ENABLED)', $isEnabled ? '<info>AKTIF (true)</info>' : '<comment>NONAKTIF (false)</comment>'],
                ['API Token', $fonnteService->getMaskedToken()],
                ['Default Country Code', config('services.fonnte.country_code', '62')],
                ['Queue Enabled', config('services.fonnte.use_queue') ? 'Ya (Background Queue)' : 'Tidak (Sync)'],
                ['Endpoint Kirim Pesan', config('services.fonnte.url', 'https://api.fonnte.com/send')],
                ['Default Recipient', config('services.fonnte.default_recipient') ?: '<comment>(Kosong)</comment>'],
            ]
        );

        if (!$isConfigured) {
            $this->error("\n[!] Token API Fonnte belum dikonfigurasi!");
            $this->line("Silakan tambahkan token perangkat Anda pada file .env:");
            $this->line("  FONNTE_ENABLED=true");
            $this->line("  FONNTE_API_TOKEN=your_fonnte_device_token\n");
            return Command::FAILURE;
        }

        // Cek status profil perangkat di Fonnte
        $this->line("\nMemeriksa status perangkat WhatsApp di Fonnte...");
        $deviceInfo = $fonnteService->checkDeviceStatus();

        if ($deviceInfo['success']) {
            $statusColor = $deviceInfo['connected'] ? 'info' : 'comment';
            $this->table(
                ['Informasi Perangkat Fonnte', 'Status'],
                [
                    ['Nomor Perangkat Terhubung', $deviceInfo['device'] ?? '-'],
                    ['Status Koneksi', "<{$statusColor}>" . strtoupper($deviceInfo['device_status'] ?? 'unknown') . "</{$statusColor}>"],
                    ['Nama Perangkat', $deviceInfo['name'] ?? '-'],
                    ['Sisa Kuota Pesan', $deviceInfo['quota'] ?? '-'],
                    ['Masa Aktif Akun', $deviceInfo['expired'] ?? '-'],
                ]
            );

            if (!$deviceInfo['connected']) {
                $this->warn("\n[PERINGATAN] Perangkat WhatsApp di Fonnte terdeteksi belum terhubung (DISCONNECT)!");
                $this->line("Silakan buka dashboard https://md.fonnte.com/ dan scan QR Code WhatsApp Anda.");
            }
        } else {
            $this->warn("Gagal mengecek status perangkat Fonnte: " . ($deviceInfo['message'] ?? 'Tidak ada respons.'));
        }

        // Ambil nomor tujuan pengujian
        $targetPhone = $this->argument('phone') ?: config('services.fonnte.default_recipient');

        if (empty($targetPhone)) {
            $targetPhone = $this->ask('Masukkan nomor WhatsApp tujuan uji coba (contoh: 081234567890)');
        }

        if (empty($targetPhone)) {
            $this->warn('Pengujian pengiriman pesan dibatalkan: Nomor tujuan tidak diisi.');
            return Command::SUCCESS;
        }

        $formattedPhone = $fonnteService->formatPhoneNumber($targetPhone);
        if (!$formattedPhone) {
            $this->error("Format nomor telepon '{$targetPhone}' tidak valid!");
            $this->line("Gunakan nomor ponsel Indonesia yang benar (contoh: 081234567890 atau 6281234567890).");
            return Command::FAILURE;
        }

        if ($fonnteService->isPlaceholderPhone($formattedPhone)) {
            $this->error("Nomor '{$formattedPhone}' terdeteksi sebagai nomor placeholder dan tidak boleh digunakan untuk pengujian nyata!");
            return Command::FAILURE;
        }

        $this->info("\nNomor tujuan terformat: {$formattedPhone}");

        $customMessage = $this->option('message');
        $timeStr = now()->format('d/m/Y H:i:s');
        $messageText = !empty($customMessage)
            ? $customMessage
            : "🔔 *PENGUJIAN NOTIFIKASI SISTEM PELAYANAN DESA*\n\n" .
              "Halo,\n\n" .
              "Ini adalah pesan pengujian resmi integrasi WhatsApp untuk memastikan koneksi layanan notifikasi Sistem Pelayanan Desa Digital berjalan dengan baik.\n\n" .
              "Waktu Uji: {$timeStr} WIB\n\n" .
              "Terima kasih.\n" .
              "_Pesan otomatis dari Sistem Pelayanan Desa Digital._";

        $this->line("Mengirim pesan pengujian via Fonnte API...");
        $result = $fonnteService->sendMessage($formattedPhone, $messageText, null, null, 'FONNTE_TEST_COMMAND');

        if ($result['success']) {
            $gatewayStatus = $result['delivery_status'] ?? 'ACCEPTED';
            $this->info("\n[GATEWAY {$gatewayStatus}] Permintaan pengiriman pesan diterima oleh gateway Fonnte.");
            if (!empty($result['message_id'])) {
                $this->line("• Fonnte Message ID: <info>{$result['message_id']}</info>");
            }
            $this->line("• Status Gateway: {$gatewayStatus} (status ACCEPTED/PENDING berarti masuk antrean gateway, bukan jaminan sudah diterima HP tujuan).");
            $this->line("• Nomor Tujuan: {$formattedPhone}");
            return Command::SUCCESS;
        }

        $this->error("\n[GAGAL] Pengiriman pesan Fonnte gagal!");
        $this->line("• Alasan Error: " . ($result['error'] ?? 'Terjadi kesalahan teknis'));

        $errorText = strtolower($result['error'] ?? '');
        $this->line("\n--- PANDUAN PENYELESAIAN (TROUBLESHOOTING) ---");
        if (str_contains($errorText, 'token invalid')) {
            $this->warn("Token API Fonnte tidak valid.");
            $this->line("Solusi: Masuk ke https://md.fonnte.com/ -> Menu Device -> Salin Token perangkat Anda ke FONNTE_API_TOKEN di .env.");
        } elseif (str_contains($errorText, 'device disconnect')) {
            $this->warn("Perangkat WhatsApp di Fonnte terputus.");
            $this->line("Solusi: Masuk ke https://md.fonnte.com/ dan scan ulang QR code dengan WhatsApp di ponsel Anda.");
        } elseif (str_contains($errorText, 'quota')) {
            $this->warn("Kuota pesan Fonnte Anda telah habis.");
            $this->line("Solusi: Periksa sisa kuota Anda di dashboard Fonnte.");
        } else {
            $this->line("Pastikan koneksi internet server stabil dan token API Fonnte aktif.");
        }

        return Command::FAILURE;
    }
}

