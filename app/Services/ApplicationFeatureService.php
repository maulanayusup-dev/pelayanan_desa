<?php

namespace App\Services;

class ApplicationFeatureService
{
    /**
     * Get authoritative metadata of main features, subfeatures, and their actual implementation status.
     */
    public function getMainFeatures(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Pengajuan Surat Keterangan Tidak Mampu (SKTM) Online',
                'category' => 'Layanan Utama Warga',
                'status' => 'Aktif & Tersedia',
                'description' => 'Permohonan surat SKTM secara mandiri dengan formulir wizard multi-langkah dan unggah berkas persyaratan.',
                'subfeatures' => [
                    'Formulir pengisian wizard 3 langkah (Data Pemohon, Data Bantuan/Keperluan, Unggah Berkas)',
                    'Validasi berkas digital persyaratan (KTP, KK, Surat Pengantar RT/RW)',
                    'Penyimpanan otomatis draft permohonan',
                    'Integrasi data profil pemohon otomatis dari database akun'
                ]
            ],
            [
                'id' => 2,
                'name' => 'Pelacakan Status & Linimasa Berkas Real-Time',
                'category' => 'Transparansi Layanan',
                'status' => 'Aktif & Tersedia',
                'description' => 'Pemantauan alur dan histori pengajuan surat secara transparan dari pengajuan hingga selesai.',
                'subfeatures' => [
                    'Visual stepper status permohonan real-time (linimasa alur)',
                    'Riwayat audit perubahan status dan catatan resmi petugas',
                    'Akses pratinjau dokumen lampiran yang telah diunggah',
                    'Tampilan status jelas (Diproses, Perlu Revisi, Disetujui, Selesai, Ditolak)'
                ]
            ],
            [
                'id' => 3,
                'name' => 'Pemeriksaan & Persetujuan SKTM Langsung (Staf -> Kepala Desa)',
                'category' => 'Operasional Perangkat Desa',
                'status' => 'Aktif & Tersedia',
                'description' => 'Alur resmi pemrosesan SKTM langsung dari Masyarakat -> Staf Desa -> Kepala Desa tanpa antrean persetujuan perantara.',
                'subfeatures' => [
                    'Pemeriksaan awal kelengkapan dan keabsahan berkas oleh Staf Desa',
                    'Fitur klaim periksa permohonan (UNDER_REVIEW) oleh Staf Desa',
                    'Penerusan berkas valid oleh Staf Desa langsung ke Kepala Desa (HEAD_APPROVAL)',
                    'Persetujuan akhir (Approval) atau penolakan mutlak oleh Kepala Desa'
                ]
            ],
            [
                'id' => 4,
                'name' => 'Penerbitan Dokumen Resmi SKTM PDF Ber-QR Code',
                'category' => 'Output Dokumen Digital',
                'status' => 'Aktif & Tersedia',
                'description' => 'Penerbitan otomatis dokumen PDF SKTM resmi dengan nomor surat desa dan QR-Code verifikasi tanda tangan elektronik.',
                'subfeatures' => [
                    'Penomoran registrasi surat otomatis sistem (format desa resmi)',
                    'Penyematan QR-Code tanda tangan digital untuk validasi keaslian dokumen',
                    'Penyimpanan berkas PDF digital aman pada server',
                    'Pengunduhan langsung berkas PDF resmi oleh pemohon'
                ]
            ],
            [
                'id' => 5,
                'name' => 'Revisi & Perbaikan Berkas Mandiri oleh Warga',
                'category' => 'Interaksi Layanan',
                'status' => 'Aktif & Tersedia',
                'description' => 'Fasilitas bagi warga untuk mengunggah ulang dokumen perbaikan jika terdapat berkas buram atau tidak sesuai.',
                'subfeatures' => [
                    'Penerimaan catatan perbaikan spesifik dari petugas desa',
                    'Formulir unggah ulang berkas revisi tanpa perlu mengulang dari awal',
                    'Pengembalian otomatis status berkas ke antrean pemeriksaan staf',
                    'Pencatatan riwayat revisi pada linimasa pengajuan'
                ]
            ],
            [
                'id' => 6,
                'name' => 'Manajemen Akun Pengguna & Hak Akses Berbasis Role (RBAC)',
                'category' => 'Administrasi & Keamanan Sistem',
                'status' => 'Aktif & Tersedia (Khusus Supervisor)',
                'description' => 'Pengelolaan akun 4 role resmi (Warga, Staf Desa, Supervisor, Kepala Desa) dengan kontrol akses ketat.',
                'subfeatures' => [
                    'Pendaftaran akun pengguna baru dan penetapan role terotorisasi',
                    'Pembaruan data akun dan perubahan role pengguna',
                    'Aktivasi dan penonaktifan akun (soft-delete)',
                    'Proteksi akun supervisor terakhir agar tidak kehilangan akses sistem',
                    'Pencatatan audit log aktivitas perubahan akun pengguna'
                ]
            ],
            [
                'id' => 7,
                'name' => 'Sistem Notifikasi Multi-Channel',
                'category' => 'Komunikasi & Notifikasi',
                'status' => 'Aktif & Tersedia',
                'description' => 'Pemberitahuan otomatis melalui WhatsApp (Fonnte), Email (Gmail SMTP), dan notifikasi dalam aplikasi (ikon lonceng) untuk alur SKTM dan Laporan Masyarakat.',
                'subfeatures' => [
                    'Notifikasi SKTM dikirim ke Masyarakat, Staf Desa, dan Kepala Desa sesuai tahap alur aktif (Supervisor tidak menerima notifikasi SKTM)',
                    'Notifikasi Laporan Masyarakat baru & balasan pelapor dikirim ke Supervisor via WhatsApp, Email, dan ikon lonceng',
                    'Notifikasi balasan Supervisor & perubahan status laporan dikirim ke pelapor terkait',
                    'Pesan notifikasi tidak memuat data sensitif warga (NIK, nomor KK, atau isi dokumen)'
                ]
            ],
            [
                'id' => 8,
                'name' => 'Asisten Virtual AI Pelayanan Desa (Chatbot Cerdas)',
                'category' => 'Kecerdasan Buatan & Layanan Warga 24/7',
                'status' => 'Aktif & Tersedia',
                'description' => 'Asisten AI interaktif yang terhubung langsung dengan database internal untuk panduan, syarat, status SKTM, dan statistik maupun tiket laporan sesuai hak akses.',
                'subfeatures' => [
                    'Panduan persyaratan SKTM, alur layanan, serta informasi fitur dan navigasi per role',
                    'Pengecekan status dan catatan revisi SKTM milik sendiri secara aman',
                    'Pelaporan statistik SKTM & data tiket Laporan Masyarakat langsung dari database sesuai role tanpa memanggil LLM eksternal',
                    'Proteksi keamanan prompt injection, isolasi riwayat per akun, dan pencegahan kebocoran data/catatan internal'
                ]
            ],
            [
                'id' => 9,
                'name' => 'Sistem Laporan & Keluhan Masyarakat Terintegrasi',
                'category' => 'Pengaduan & Dukungan Operasional',
                'status' => 'Aktif & Tersedia',
                'description' => 'Pengiriman keluhan, kendala sistem, atau gangguan layanan bertiket RPT-* oleh Warga, Staf, dan Kepala Desa untuk ditangani Supervisor.',
                'subfeatures' => [
                    'Pembuatan laporan baru dan unggah lampiran bukti aman (/laporan/buat) oleh Warga, Staf, dan Kepala Desa',
                    'Pemantauan status tiket laporan & diskusi balasan publik di /laporan dan /laporan/{id}',
                    'Pusat Laporan Supervisor (/admin/laporan) untuk filter, penugasan, prioritas, penyelesaian, dan catatan internal',
                    'Pemisahan ketat catatan internal agar hanya dapat diakses oleh Supervisor'
                ]
            ],
        ];
    }

    /**
     * Get summary count of features, subfeatures, roles, and pages.
     */
    public function getFeaturesCountSummary(): array
    {
        $mainFeatures = $this->getMainFeatures();
        $mainCount = count($mainFeatures);
        
        $subCount = 0;
        foreach ($mainFeatures as $f) {
            $subCount += count($f['subfeatures']);
        }

        $rolesCount = 4; // masyarakat, staf, supervisor, kades
        $pagesByRole = [
            'masyarakat' => count($this->getPagesByRole('masyarakat')),
            'staf' => count($this->getPagesByRole('staf')),
            'supervisor' => count($this->getPagesByRole('supervisor')),
            'kades' => count($this->getPagesByRole('kades')),
            'guest' => count($this->getPagesByRole('guest')),
        ];

        return [
            'total_fitur_utama' => $mainCount,
            'total_subfitur' => $subCount,
            'total_role_sistem' => $rolesCount,
            'halaman_per_role' => $pagesByRole,
        ];
    }

    /**
     * Get formatted explanation answering "Ada berapa fitur?".
     */
    public function getFeaturesOverviewAnswer(): string
    {
        $summary = $this->getFeaturesCountSummary();
        $mainFeatures = $this->getMainFeatures();

        $lines = [];
        $lines[] = "Sistem Pelayanan Desa Digital saat ini memiliki total **{$summary['total_fitur_utama']} Fitur Utama** dan **{$summary['total_subfitur']} Subfitur** yang terbagi untuk 4 role pengguna (Warga, Staf Desa, Supervisor, dan Kepala Desa) serta pengunjung umum/tamu.";
        $lines[] = "";
        $lines[] = "Berikut daftar {$summary['total_fitur_utama']} Fitur Utama yang tersedia berdasarkan implementasi aktual sistem:";

        foreach ($mainFeatures as $f) {
            $lines[] = "{$f['id']}. **{$f['name']}** ({$f['category']}) — {$f['description']}";
        }

        $lines[] = "";
        $lines[] = "💡 *Catatan Pembeda:*";
        $lines[] = "• **Fitur Utama:** {$summary['total_fitur_utama']} modul kemampuan inti aplikasi.";
        $lines[] = "• **Subfitur:** {$summary['total_subfitur']} fungsi teknis spesifik di dalam setiap fitur.";
        $lines[] = "• **Halaman/Menu:** Rute akses antarmuka yang disesuaikan dengan kewenangan masing-masing role pengguna.";

        return implode("\n", $lines);
    }

    /**
     * Get detailed list of all features with their subfeatures.
     */
    public function getAllFeaturesListAnswer(): string
    {
        $mainFeatures = $this->getMainFeatures();
        $summary = $this->getFeaturesCountSummary();

        $lines = [];
        $lines[] = "Berikut rincian lengkap **{$summary['total_fitur_utama']} Fitur Utama** beserta subfitur yang tersedia di Sistem Pelayanan Desa:";
        $lines[] = "";

        foreach ($mainFeatures as $f) {
            $lines[] = "### {$f['id']}. {$f['name']}";
            $lines[] = "• **Kategori:** {$f['category']}";
            $lines[] = "• **Status:** {$f['status']}";
            $lines[] = "• **Deskripsi:** {$f['description']}";
            $lines[] = "• **Subfitur:**";
            foreach ($f['subfeatures'] as $sub) {
                $lines[] = "  - {$sub}";
            }
            $lines[] = "";
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Get features accessible by a specific role.
     */
    public function getFeaturesByRole(string $role): array
    {
        $role = strtolower(trim($role));

        return match ($role) {
            'masyarakat', 'warga' => [
                'role_label' => 'Warga (Masyarakat)',
                'fitur' => [
                    'Pengajuan SKTM Online secara mandiri (Formulir wizard 3 langkah)',
                    'Unggah dokumen persyaratan (KTP, KK, Surat Pengantar RT/RW)',
                    'Pemantauan status pengajuan secara real-time melalui linimasa interaktif',
                    'Unggah revisi berkas jika terdapat permintaan perbaikan dari Staf Desa',
                    'Pengunduhan dokumen resmi SKTM PDF ber-QR Code setelah disetujui Kepala Desa',
                    'Pembuatan dan pemantauan Laporan / Keluhan masyarakat (/laporan & /laporan/buat)',
                    'Penerimaan notifikasi pembaruan status SKTM & balasan laporan via WhatsApp, Email, dan ikon lonceng',
                    'Konsultasi 24 jam dengan Asisten Virtual AI Pelayanan Desa untuk panduan, status SKTM, dan tiket laporan pribadi',
                    'Manajemen profil diri dan pembaruan kata sandi'
                ]
            ],
            'staf', 'staff', 'staf desa' => [
                'role_label' => 'Staf Desa',
                'fitur' => [
                    'Dashboard Verifikasi Staf: Pemantauan metrik antrean permohonan baru, berkas sedang diperiksa, dan berkas revisi',
                    'Manajemen Antrean Pengajuan: Daftar seluruh permohonan SKTM warga yang masuk',
                    'Pemeriksaan & Verifikasi Dokumen: Memeriksa berkas KTP, KK, dan Surat Pengantar pemohon',
                    'Fitur Mulai Periksa (Claim): Menandai permohonan yang sedang aktif diperiksa oleh Staf Desa',
                    'Permintaan Revisi Dokumen: Mengembalikan berkas ke pemohon disertai catatan perbaikan resmi jika berkas buram/salah',
                    'Penerusan Langsung ke Kepala Desa: Meneruskan permohonan yang berkasnya telah valid langsung ke tahap persetujuan Kepala Desa',
                    'Pelaporan Kendala Sistem: Mengirim dan memantau tiket laporan milik sendiri ke Supervisor (/laporan)',
                    'Konsultasi AI Staf: Memeriksa statistik antrean periksa staf, berkas belum lengkap, dan status laporan pribadi'
                ]
            ],
            'supervisor', 'spv', 'kasi' => [
                'role_label' => 'Supervisor',
                'fitur' => [
                    'Dashboard Pemantauan Sistem Supervisor: Ringkasan laporan & keluhan masuk serta monitoring read-only progres SKTM desa',
                    'Penanganan Laporan & Keluhan (/admin/laporan): Memantau seluruh tiket laporan, mengubah status, prioritas, penugasan, balasan publik, dan catatan internal',
                    'Pemantauan SKTM Read-Only: Melihat progres dan arsip SKTM tanpa kewenangan menyetujui, menolak, atau meneruskan pengajuan SKTM',
                    'Manajemen Akun Pengguna (RBAC): Membuat, mengedit, mengubah role, serta menonaktifkan akun Warga, Staf, Kepala Desa, dan Supervisor (/admin/users)',
                    'Pengaturan Konfigurasi AI (/admin/ai-settings): Mengelola provider, endpoint, model, API Key terenkripsi, batas token, serta tes koneksi AI global',
                    'Keamanan & Audit Log Akun: Pemantauan riwayat aktivitas sistem serta proteksi akun supervisor aktif terakhir',
                    'Konsultasi AI Supervisor: Akses statistik SKTM sinkron dengan dashboard, statistik/daftar/pelapor laporan, serta statistik akun desa'
                ]
            ],
            'kades', 'kepala_desa', 'kepala desa' => [
                'role_label' => 'Kepala Desa',
                'fitur' => [
                    'Dashboard Eksekutif Kepala Desa: Metrik pengajuan menunggu persetujuan (approval) dan total SKTM yang sudah diterbitkan',
                    'Tinjauan Akhir Permohonan: Memeriksa berkas berstatus HEAD_APPROVAL yang diteruskan langsung dari Staf Desa',
                    'Persetujuan Akhir (Approval): Menyetujui permohonan sekaligus menerbitkan dokumen resmi SKTM PDF dengan tanda tangan digital QR-Code',
                    'Penolakan Resmi (Rejection): Menolak permohonan yang tidak memenuhi kriteria disertai alasan resmi tercatat',
                    'Pelaporan Kendala Sistem: Mengirim dan memantau laporan/masukan kepada Supervisor (/laporan)',
                    'Konsultasi AI Kades: Mengetahui antrean persetujuan Kepala Desa, statistik SKTM terbit, dan status laporan pribadi'
                ]
            ],
            default => [
                'role_label' => 'Pengunjung Publik / Tamu',
                'fitur' => [
                    'Akses informasi umum prosedur dan persyaratan pengajuan SKTM',
                    'Panduan persyaratan dokumen resmi desa',
                    'Halaman Tanya Jawab (FAQ) pelayanan desa',
                    'Konsultasi interaktif dengan Asisten Virtual AI Pelayanan Desa seputar syarat, jam pelayanan, dan alur permohonan',
                    'Pendaftaran mandiri akun Warga (Masyarakat)',
                    'Autentikasi akun (Login, Logout, Verifikasi Email)'
                ]
            ]
        };
    }

    /**
     * Get formatted explanation answering "Apa saja fitur dashboard staf?".
     */
    public function getStaffDashboardFeaturesAnswer(): string
    {
        return "Berikut fitur-fitur yang tersedia pada **Dashboard Staf Desa** (`/admin/dashboard`):\n\n" .
            "1. **Metrik Antrean Permohonan Baru (`SUBMITTED`):**\n" .
            "   Menampilkan jumlah berkas pengajuan SKTM warga yang baru masuk dan menunggu pemeriksaan awal.\n\n" .
            "2. **Metrik Berkas Sedang Diperiksa (`UNDER_REVIEW`):**\n" .
            "   Memantau berkas permohonan yang sedang dalam proses verifikasi aktif oleh staf desa.\n\n" .
            "3. **Metrik Berkas Membutuhkan Revisi (`NEEDS_REVISION`):**\n" .
            "   Memantau pengajuan yang dikembalikan ke pemohon untuk perbaikan dokumen persyaratan.\n\n" .
            "4. **Tabel Antrean Pemeriksaan Berkas:**\n" .
            "   Daftar pengajuan masuk dengan informasi pemohon, tanggal pengajuan, nomor registrasi, serta aksi cepat untuk membuka berkas.\n\n" .
            "5. **Panel Detail & Aksi Verifikasi Dokumen:**\n" .
            "   Akses untuk memeriksa dokumen fisik (KTP, KK, Pengantar RT/RW), tombol 'Minta Revisi' (dengan catatan kesalahan berkas), dan tombol 'Teruskan ke Kepala Desa' jika berkas valid.\n\n" .
            "6. **Bantuan Asisten AI Staf:**\n" .
            "   Staf dapat menanyakan antrean yang perlu diperiksa, pengajuan mana yang butuh perbaikan, atau berkas mana yang belum lengkap melalui chatbot AI.";
    }

    /**
     * Get list of accessible pages/routes for each role.
     */
    public function getPagesByRole(string $role): array
    {
        $role = strtolower(trim($role));

        return match ($role) {
            'masyarakat', 'warga' => [
                [
                    'route' => '/dashboard',
                    'name' => 'Dashboard Warga',
                    'description' => 'Ringkasan statistik pengajuan pribadi (total, disetujui, diproses, perlu revisi), riwayat pengajuan terbaru, dan tautan pengajuan baru.'
                ],
                [
                    'route' => '/pengajuan',
                    'name' => 'Daftar Pengajuan Saya',
                    'description' => 'Tabel seluruh riwayat pengajuan surat SKTM pemohon dengan penomoran halaman (pagination).'
                ],
                [
                    'route' => '/pengajuan/create',
                    'name' => 'Buat Pengajuan SKTM',
                    'description' => 'Formulir permohonan SKTM wizard 3 langkah (Data Pemohon -> Data Bantuan -> Unggah Dokumen KTP, KK, Pengantar RT/RW).'
                ],
                [
                    'route' => '/pengajuan/{id}',
                    'name' => 'Detail & Linimasa Pengajuan',
                    'description' => 'Melihat status linimasa berkas, catatan revisi petugas jika ada, berkas lampiran, dan form unggah revisi.'
                ],
                [
                    'route' => '/pengajuan/{id}/download',
                    'name' => 'Unduh Dokumen SKTM Resmi',
                    'description' => 'Mengunduh berkas PDF resmi SKTM ber-QR Code setelah pengajuan disetujui Kepala Desa.'
                ],
                [
                    'route' => '/pengajuan/dokumen/{id}',
                    'name' => 'Lihat Dokumen Terunggah',
                    'description' => 'Akses aman untuk melihat file berkas KTP/KK milik pemohon sendiri.'
                ],
                [
                    'route' => '/laporan',
                    'name' => 'Laporan & Keluhan Saya',
                    'description' => 'Daftar tiket laporan yang Anda kirim beserta status penanganan dan balasan Supervisor.'
                ],
                [
                    'route' => '/laporan/buat',
                    'name' => 'Buat Laporan & Keluhan',
                    'description' => 'Formulir pelaporan kendala teknis, gangguan layanan, atau masukan disertai lampiran bukti.'
                ],
                [
                    'route' => '/profile',
                    'name' => 'Profil Pengguna',
                    'description' => 'Melihat dan memperbarui data pribadi, alamat, nomor telepon, dan ubah kata sandi.'
                ],
                [
                    'route' => '/email/verify',
                    'name' => 'Verifikasi Email',
                    'description' => 'Halaman konfirmasi pengiriman tautan aktivasi email warga.'
                ],
            ],
            'staf', 'staff', 'staf desa' => [
                [
                    'route' => '/admin/dashboard',
                    'name' => 'Dashboard Petugas (Tab Staf)',
                    'description' => 'Metrik antrean pengajuan baru, berkas sedang diverifikasi, berkas revisi, dan rekap permohonan.'
                ],
                [
                    'route' => '/admin/pengajuan',
                    'name' => 'Daftar Pengajuan Masuk',
                    'description' => 'Daftar permohonan warga yang perlu diperiksa dengan fitur pencarian dan filter status.'
                ],
                [
                    'route' => '/admin/pengajuan/{id}',
                    'name' => 'Detail & Pemeriksaan Berkas',
                    'description' => 'Pemeriksaan lampiran dokumen warga, aksi klaim periksa, minta revisi disertai catatan, dan teruskan langsung ke Kepala Desa.'
                ],
                [
                    'route' => '/laporan',
                    'name' => 'Laporan Saya',
                    'description' => 'Mengirim dan memantau tiket laporan kendala operasional milik Staf kepada Supervisor.'
                ],
                [
                    'route' => '/laporan/buat',
                    'name' => 'Buat Laporan Kendala',
                    'description' => 'Formulir pembuatan tiket laporan kendala layanan atau aplikasi.'
                ],
                [
                    'route' => '/profile',
                    'name' => 'Profil Petugas',
                    'description' => 'Pengaturan akun staf desa dan penggantian kata sandi.'
                ],
            ],
            'supervisor', 'spv', 'kasi' => [
                [
                    'route' => '/admin/dashboard',
                    'name' => 'Dashboard Pemantauan Supervisor',
                    'description' => 'Ringkasan laporan & keluhan masyarakat, monitoring read-only progres SKTM desa, dan aktivitas terbaru.'
                ],
                [
                    'route' => '/admin/laporan',
                    'name' => 'Pusat Laporan & Keluhan',
                    'description' => 'Meninjau, memfilter, menugaskan, mengubah status/prioritas, dan menangani seluruh laporan pengguna.'
                ],
                [
                    'route' => '/admin/laporan/{report}',
                    'name' => 'Detail & Penanganan Laporan',
                    'description' => 'Merespons pelapor dengan balasan publik, mencatat catatan internal Supervisor, serta menyelesaikan tiket laporan.'
                ],
                [
                    'route' => '/admin/pengajuan',
                    'name' => 'Pantauan Pengajuan SKTM (Read-Only)',
                    'description' => 'Memantau seluruh permohonan SKTM desa secara read-only tanpa kewenangan menyetujui, menolak, atau meneruskan.'
                ],
                [
                    'route' => '/admin/users',
                    'name' => 'Manajemen Akun Pengguna',
                    'description' => 'Modul khusus Supervisor untuk membuat akun baru, mengedit data, mengubah role, menonaktifkan/mengaktifkan akun, dan memantau audit log.'
                ],
                [
                    'route' => '/admin/ai-settings',
                    'name' => 'Pengaturan AI Desa',
                    'description' => 'Modul khusus Supervisor untuk mengelola provider, endpoint, model, API Key terenkripsi, timeout, status aktif AI eksternal, serta uji koneksi.'
                ],
                [
                    'route' => '/profile',
                    'name' => 'Profil Supervisor',
                    'description' => 'Pengaturan akun supervisor dan penggantian kata sandi.'
                ],
            ],
            'kades', 'kepala_desa', 'kepala desa' => [
                [
                    'route' => '/admin/dashboard',
                    'name' => 'Dashboard Eksekutif Kepala Desa',
                    'description' => 'Metrik permohonan menunggu persetujuan (approval queue), jumlah SKTM diterbitkan, dan grafik pelayanan.'
                ],
                [
                    'route' => '/admin/pengajuan',
                    'name' => 'Daftar Pengajuan Persetujuan',
                    'description' => 'Daftar permohonan berstatus HEAD_APPROVAL yang diteruskan Staf Desa untuk ditinjau dan disahkan.'
                ],
                [
                    'route' => '/admin/pengajuan/{id}',
                    'name' => 'Pengesahan & Penerbitan Dokumen SKTM',
                    'description' => 'Aksi Persetujuan resmi (terbit PDF otomatis ber-QR Code) atau Penolakan resmi disertai alasan.'
                ],
                [
                    'route' => '/laporan',
                    'name' => 'Laporan Saya',
                    'description' => 'Mengirim dan memantau laporan kendala atau masukan kepada Supervisor.'
                ],
                [
                    'route' => '/laporan/buat',
                    'name' => 'Buat Laporan',
                    'description' => 'Formulir pengiriman laporan atau kendala sistem.'
                ],
                [
                    'route' => '/profile',
                    'name' => 'Profil Kepala Desa',
                    'description' => 'Pengaturan akun kades dan penggantian kata sandi.'
                ],
            ],
            default => [
                [
                    'route' => '/',
                    'name' => 'Beranda Publik',
                    'description' => 'Halaman utama profil sistem pelayanan desa online.'
                ],
                [
                    'route' => '/informasi-sktm',
                    'name' => 'Informasi Pelayanan SKTM',
                    'description' => 'Informasi umum prosedur, kegunaan, dan panduan SKTM desa.'
                ],
                [
                    'route' => '/persyaratan',
                    'name' => 'Persyaratan Dokumen',
                    'description' => 'Rincian berkas persyaratan pembuatan surat keterangan desa.'
                ],
                [
                    'route' => '/faq',
                    'name' => 'Tanya Jawab (FAQ)',
                    'description' => 'Jawaban atas pertanyaan yang sering diajukan warga.'
                ],
                [
                    'route' => '/login',
                    'name' => 'Halaman Masuk (Login)',
                    'description' => 'Autentikasi akun warga dan perangkat desa.'
                ],
                [
                    'route' => '/register',
                    'name' => 'Pendaftaran Warga Baru',
                    'description' => 'Registrasi mandiri akun masyarakat dengan validasi NIK dan No KK.'
                ],
            ]
        };
    }

    /**
     * Get formatted explanation answering "Warga bisa mengakses halaman apa saja?".
     */
    public function getRolePagesAnswer(string $targetRole): string
    {
        $roleInfo = $this->getFeaturesByRole($targetRole);
        $pages = $this->getPagesByRole($targetRole);

        $lines = [];
        $lines[] = "Berdasarkan hak akses sistem pelayanan desa, pengguna dengan role **{$roleInfo['role_label']}** dapat mengakses halaman-halaman berikut:";
        $lines[] = "";

        foreach ($pages as $p) {
            $lines[] = "• **`{$p['route']}`** ({$p['name']})\n  {$p['description']}";
        }

        return implode("\n", $lines);
    }

    /**
     * Get full SKTM workflow and status explanation.
     */
    public function getSktmWorkflow(): array
    {
        return [
            'total_tahapan' => 8,
            'alur_resmi' => 'Masyarakat -> Staf -> Kepala Desa',
            'alur' => [
                [
                    'status' => 'DRAFT',
                    'label' => 'Draft Permohonan',
                    'pelaku' => 'Warga (Pemohon)',
                    'deskripsi' => 'Pengajuan disimpan sementara oleh pemohon sebelum resmi dikirim ke sistem.'
                ],
                [
                    'status' => 'SUBMITTED',
                    'label' => 'Baru Diajukan',
                    'pelaku' => 'Staf Desa',
                    'deskripsi' => 'Pengajuan berhasil dikirim warga dan masuk ke antrean verifikasi berkas oleh Staf Desa.'
                ],
                [
                    'status' => 'UNDER_REVIEW',
                    'label' => 'Sedang Diverifikasi Staf',
                    'pelaku' => 'Staf Desa',
                    'deskripsi' => 'Staf desa memeriksa keabsahan dan kelengkapan berkas fisik KTP, KK, dan Surat Pengantar RT/RW.'
                ],
                [
                    'status' => 'NEEDS_REVISION',
                    'label' => 'Perlu Perbaikan Dokumen',
                    'pelaku' => 'Warga (Pemohon)',
                    'deskripsi' => 'Staf Desa meminta warga mengunggah perbaikan dokumen jika berkas buram atau belum sesuai.'
                ],
                [
                    'status' => 'HEAD_APPROVAL',
                    'label' => 'Menunggu Persetujuan Kades',
                    'pelaku' => 'Kepala Desa',
                    'deskripsi' => 'Berkas yang valid diteruskan langsung oleh Staf Desa ke Kepala Desa untuk persetujuan akhir.'
                ],
                [
                    'status' => 'APPROVED',
                    'label' => 'Disetujui Kepala Desa',
                    'pelaku' => 'Sistem / Kepala Desa',
                    'deskripsi' => 'Kepala Desa menyetujui permohonan. Sistem memproses penerbitan surat resmi SKTM PDF.'
                ],
                [
                    'status' => 'COMPLETED',
                    'label' => 'Selesai & Diterbitkan',
                    'pelaku' => 'Warga (Pemohon)',
                    'deskripsi' => 'Dokumen resmi SKTM PDF bertanda tangan digital ber-QR Code telah terbit dan dapat diunduh pemohon.'
                ],
                [
                    'status' => 'REJECTED',
                    'label' => 'Ditolak',
                    'pelaku' => 'Kepala Desa',
                    'deskripsi' => 'Permohonan ditolak oleh Kepala Desa disertai alasan penolakan resmi yang dapat dilihat warga.'
                ]
            ]
        ];
    }

    /**
     * Get formatted explanation answering "Bagaimana alur pengajuan SKTM?".
     */
    public function getSktmWorkflowAnswer(): string
    {
        // Ringkas (maks. 5 langkah) — alur resmi langsung: Masyarakat -> Staf -> Kepala Desa
        $label = collect($this->getSktmWorkflow()['alur'])->pluck('label', 'status');

        return "Alur resmi pengajuan Surat Keterangan Tidak Mampu (SKTM) adalah Masyarakat -> Staf -> Kepala Desa:\n"
            . "1. Warga mengisi formulir ({$label['DRAFT']}) lalu mengirimnya ({$label['SUBMITTED']}).\n"
            . "2. Staf Desa memeriksa berkas ({$label['UNDER_REVIEW']}); bila kurang, dikembalikan ke warga ({$label['NEEDS_REVISION']}).\n"
            . "3. Staf Desa meneruskan berkas valid langsung ke Kepala Desa ({$label['HEAD_APPROVAL']}).\n"
            . "4. Kepala Desa memutuskan ({$label['APPROVED']} atau {$label['REJECTED']}).\n"
            . "5. SKTM terbit ({$label['COMPLETED']}) ber-QR Code dan dapat diunduh warga (Gratis Rp 0, 1–2 hari kerja; Supervisor memantau secara read-only).";
    }
}
