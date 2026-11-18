<x-layouts.app :standalone="true">
    <x-slot:title>Selamat Datang di Portal Pelayanan Desa</x-slot>

    <!-- Floating Soft UI Navbar -->
    <header class="fixed top-0 inset-x-0 z-50 px-4 sm:px-6 py-4">
        <div class="max-w-7xl mx-auto">
            <nav class="bg-white/85 dark:bg-slate-900/85 backdrop-blur-md border border-white/80 dark:border-slate-800 shadow-soft rounded-2xl px-6 py-3 flex items-center justify-between">
                <a href="/" class="flex items-center space-x-3 text-slate-800 dark:text-slate-100 group">
                    <div class="w-10 h-10 rounded-xl bg-gradient-primary flex items-center justify-center text-white shadow-soft-sm group-hover:scale-105 transition-transform">
                        <i class="fas fa-landmark text-base"></i>
                    </div>
                    <div class="flex flex-col">
                        <span class="font-extrabold text-slate-800 dark:text-slate-100 text-base tracking-tight leading-tight">Pelayanan Desa</span>
                        <span class="text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider">Layanan Mandiri Warga</span>
                    </div>
                </a>

                <div class="hidden md:flex items-center space-x-6 text-sm font-semibold text-slate-600 dark:text-slate-300">
                    <a href="#fitur" class="hover:text-pink-600 dark:hover:text-pink-400 transition-colors">Keunggulan</a>
                    <a href="#alur" class="hover:text-pink-600 dark:hover:text-pink-400 transition-colors">Alur Proses</a>
                    <a href="/informasi-sktm" class="hover:text-pink-600 dark:hover:text-pink-400 transition-colors">Info SKTM</a>
                    <a href="/persyaratan" class="hover:text-pink-600 dark:hover:text-pink-400 transition-colors">Persyaratan</a>
                    <a href="/faq" class="hover:text-pink-600 dark:hover:text-pink-400 transition-colors">FAQ</a>
                </div>

                <div class="flex items-center space-x-3">
                    <button type="button" onclick="window.toggleTheme()" class="p-2 rounded-xl text-slate-500 dark:text-slate-300 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all cursor-pointer" title="Ganti Tema">
                        <i class="fas fa-sun block dark:hidden text-amber-500 text-sm"></i>
                        <i class="fas fa-moon hidden dark:block text-indigo-400 text-sm"></i>
                    </button>
                    @auth
                        <a href="{{ auth()->user()->role?->name === 'masyarakat' ? route('dashboard') : route('admin.dashboard') }}" class="btn-soft-primary text-xs py-2 px-5">
                            <i class="fas fa-columns mr-1.5"></i> Buka Dashboard
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="btn-soft-white text-xs py-2 px-4">
                            Masuk
                        </a>
                        <a href="{{ route('register') }}" class="btn-soft-primary text-xs py-2 px-5 hidden sm:inline-flex">
                            Daftar Akun
                        </a>
                    @endauth
                </div>
            </nav>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="relative pt-36 pb-20 md:pt-44 md:pb-28 overflow-hidden">
        <!-- Soft UI Ambient Background Elements -->
        <div class="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[350px] bg-gradient-to-tr from-purple-200/50 via-pink-200/40 to-cyan-100/40 rounded-full blur-3xl pointer-events-none -z-10"></div>
        
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative z-10">
            <!-- Pill Tag -->
            <div class="inline-flex items-center space-x-2 px-4 py-1.5 rounded-full bg-white shadow-soft-sm border border-slate-100 mb-6 animate-fade-in-up">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <span class="text-xs font-bold text-slate-700 tracking-wide uppercase">Digitalisasi Pelayanan Publik 2026</span>
            </div>

            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-slate-900 tracking-tight max-w-4xl mx-auto leading-tight sm:leading-none mb-6 animate-fade-in-up" style="animation-delay: 0.1s;">
                Permohonan SKTM Online<br />
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-purple-700 via-pink-600 to-indigo-600">Mudah, Cepat & Terverifikasi</span>
            </h1>

            <p class="text-base sm:text-lg text-slate-500 max-w-2xl mx-auto mb-10 leading-relaxed font-normal animate-fade-in-up" style="animation-delay: 0.2s;">
                Ajukan Surat Keterangan Tidak Mampu langsung dari smartphone Anda tanpa antre di balai desa. Dilengkapi validasi berjenjang, QR Code pengesahan Kepala Desa, dan asisten cerdas AI.
            </p>

            <div class="flex flex-col sm:flex-row items-center justify-center gap-4 animate-fade-in-up" style="animation-delay: 0.3s;">
                @auth
                    <a href="{{ auth()->user()->role?->name === 'masyarakat' ? route('pengajuan.create') : route('admin.dashboard') }}" class="btn-soft-primary text-sm py-3 px-7 w-full sm:w-auto">
                        <i class="fas fa-file-alt mr-2"></i> Buat Pengajuan Baru
                    </a>
                    <a href="{{ route('dashboard') }}" class="btn-soft-white text-sm py-3 px-7 w-full sm:w-auto">
                        <i class="fas fa-columns mr-2 text-slate-500"></i> Buka Dashboard Saya
                    </a>
                @else
                    <a href="{{ route('register') }}" class="btn-soft-primary text-sm py-3 px-7 w-full sm:w-auto">
                        <i class="fas fa-paper-plane mr-2"></i> Daftar & Ajukan SKTM
                    </a>
                    <a href="/persyaratan" class="btn-soft-white text-sm py-3 px-7 w-full sm:w-auto">
                        <i class="fas fa-clipboard-list mr-2 text-slate-500"></i> Cek Syarat Berkas
                    </a>
                @endauth
            </div>

            <!-- Soft UI Dashboard Preview Mockup -->
            <div class="mt-16 max-w-5xl mx-auto animate-fade-in-up" style="animation-delay: 0.4s;">
                <div class="bg-white rounded-3xl p-4 sm:p-6 shadow-soft border border-slate-100 relative">
                    <!-- Browser Header dots -->
                    <div class="flex items-center space-x-2 pb-4 mb-4 border-b border-slate-100">
                        <div class="w-3 h-3 rounded-full bg-rose-400"></div>
                        <div class="w-3 h-3 rounded-full bg-amber-400"></div>
                        <div class="w-3 h-3 rounded-full bg-emerald-400"></div>
                        <div class="ml-4 text-xs font-semibold text-slate-400">portal.pelayanandesa.go.id &bull; Sistem Terpadu Pelayanan Masyarakat</div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-left">
                        <!-- Preview Card 1 -->
                        <div class="card-soft p-4 bg-slate-50/70 border-slate-100">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xxs font-bold text-slate-400 uppercase">Status Pengajuan</span>
                                <span class="badge-soft-success text-xxs">Disetujui</span>
                            </div>
                            <div class="font-bold text-slate-800 text-sm">SKTM-2026-000412</div>
                            <div class="text-xs text-slate-500 mt-1">Keperluan: KIP Kuliah / Beasiswa</div>
                            <div class="mt-3 pt-3 border-t border-slate-200/60 flex items-center justify-between text-xs">
                                <span class="text-slate-400"><i class="fas fa-check-circle text-emerald-500 mr-1"></i> TTD Digital Kades</span>
                                <span class="font-bold text-pink-600">Unduh PDF</span>
                            </div>
                        </div>

                        <!-- Preview Card 2 -->
                        <div class="card-soft p-4 bg-slate-50/70 border-slate-100">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xxs font-bold text-slate-400 uppercase">Verifikasi Berkas</span>
                                <span class="badge-soft-info text-xxs">Review Kasi</span>
                            </div>
                            <div class="font-bold text-slate-800 text-sm">SKTM-2026-000418</div>
                            <div class="text-xs text-slate-500 mt-1">Keperluan: Pengobatan RSUD</div>
                            <div class="mt-3 pt-3 border-t border-slate-200/60 flex items-center justify-between text-xs">
                                <span class="text-slate-400"><i class="fas fa-spinner fa-spin text-blue-500 mr-1"></i> Pemeriksaan Staf OK</span>
                                <span class="font-bold text-blue-600">Diproses</span>
                            </div>
                        </div>

                        <!-- Preview Card 3 -->
                        <div class="card-soft p-4 bg-slate-50/70 border-slate-100">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xxs font-bold text-slate-400 uppercase">Asisten Virtual</span>
                                <span class="badge-soft-purple text-xxs">AI Ready</span>
                            </div>
                            <div class="font-bold text-slate-800 text-sm">Konsultasi Persyaratan</div>
                            <div class="text-xs text-slate-500 mt-1">"Berapa lama SKTM diproses?" &rarr; 1-2 Hari Kerja</div>
                            <div class="mt-3 pt-3 border-t border-slate-200/60 flex items-center justify-between text-xs">
                                <span class="text-slate-400"><i class="fas fa-robot text-purple-500 mr-1"></i> 24/7 Responsif</span>
                                <span class="font-bold text-purple-600">Tanya AI</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Key Advantages (Features) -->
    <section id="fitur" class="py-20 bg-white border-y border-slate-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto mb-16">
                <span class="text-xs font-bold uppercase tracking-wider text-pink-600">Keunggulan Pelayanan</span>
                <h2 class="text-3xl font-extrabold text-slate-900 mt-2">Transparan, Aman & Akuntabel</h2>
                <p class="text-slate-500 text-sm mt-3">Dirancang khusus untuk mempermudah warga dan efisiensi kerja aparatur pemerintah desa.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Feature 1 -->
                <div class="card-soft p-8 hover:-translate-y-1 hover:shadow-soft-md transition-all duration-300">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-primary flex items-center justify-center text-white text-xl shadow-soft mb-6">
                        <i class="fas fa-mobile-alt"></i>
                    </div>
                    <h3 class="text-lg font-bold text-slate-800 mb-2">Akses Cepat Kapan Saja</h3>
                    <p class="text-sm text-slate-500 leading-relaxed">
                        Cukup gunakan smartphone atau komputer. Isi formulir identitas, unggah foto KTP dan Kartu Keluarga, selesai tanpa harus izin kerja atau antre.
                    </p>
                </div>

                <!-- Feature 2 -->
                <div class="card-soft p-8 hover:-translate-y-1 hover:shadow-soft-md transition-all duration-300">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-info flex items-center justify-center text-white text-xl shadow-soft mb-6">
                        <i class="fas fa-shield-halved"></i>
                    </div>
                    <h3 class="text-lg font-bold text-slate-800 mb-2">Alur Verifikasi 3 Tahap</h3>
                    <p class="text-sm text-slate-500 leading-relaxed">
                        Setiap permohonan melalui verifikasi Staf Desa, peninjauan Kasi/Supervisor, dan pengesahan sah Kepala Desa dengan riwayat audit transparan.
                    </p>
                </div>

                <!-- Feature 3 -->
                <div class="card-soft p-8 hover:-translate-y-1 hover:shadow-soft-md transition-all duration-300">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-success flex items-center justify-center text-white text-xl shadow-soft mb-6">
                        <i class="fas fa-qrcode"></i>
                    </div>
                    <h3 class="text-lg font-bold text-slate-800 mb-2">Dokumen QR Code Resmi</h3>
                    <p class="text-sm text-slate-500 leading-relaxed">
                        Surat SKTM diterbitkan dalam format PDF berstandar dinas dengan QR Code unik untuk validasi keaslian dokumen oleh instansi peminta.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- 3-Step Process -->
    <section id="alur" class="py-20 bg-[#F8F9FA]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto mb-16">
                <span class="text-xs font-bold uppercase tracking-wider text-pink-600">Alur Pengajuan</span>
                <h2 class="text-3xl font-extrabold text-slate-900 mt-2">3 Langkah Pengurusan SKTM</h2>
                <p class="text-slate-500 text-sm mt-3">Proses terstruktur yang ringkas, jelas, dan dapat dipantau statusnya setiap saat.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Step 1 -->
                <div class="card-soft p-8 relative">
                    <div class="w-12 h-12 rounded-xl bg-gradient-primary text-white font-extrabold flex items-center justify-center text-lg shadow-soft mb-6">
                        1
                    </div>
                    <h4 class="text-base font-bold text-slate-800 mb-2">Daftar Akun NIK</h4>
                    <p class="text-sm text-slate-500 leading-relaxed">
                        Registrasikan akun menggunakan data NIK dan email aktif untuk mendapatkan akses portal pelayanan warga.
                    </p>
                </div>

                <!-- Step 2 -->
                <div class="card-soft p-8 relative">
                    <div class="w-12 h-12 rounded-xl bg-gradient-dark text-white font-extrabold flex items-center justify-center text-lg shadow-soft mb-6">
                        2
                    </div>
                    <h4 class="text-base font-bold text-slate-800 mb-2">Lengkapi Data & Berkas</h4>
                    <p class="text-sm text-slate-500 leading-relaxed">
                        Isi form keperluan SKTM (beasiswa, kesehatan, bansos) dan unggah foto KTP asli serta Kartu Keluarga.
                    </p>
                </div>

                <!-- Step 3 -->
                <div class="card-soft p-8 relative">
                    <div class="w-12 h-12 rounded-xl bg-gradient-success text-white font-extrabold flex items-center justify-center text-lg shadow-soft mb-6">
                        3
                    </div>
                    <h4 class="text-base font-bold text-slate-800 mb-2">Unduh SKTM Sah</h4>
                    <p class="text-sm text-slate-500 leading-relaxed">
                        Setelah disetujui Kepala Desa, dokumen SKTM PDF bertanda tangan digital resmi langsung dapat diunduh dan dicetak.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Bottom CTA Banner -->
    <section class="py-16 px-4 sm:px-6 lg:px-8">
        <div class="max-w-5xl mx-auto rounded-3xl bg-gradient-primary p-8 sm:p-12 text-center text-white shadow-soft-xl relative overflow-hidden">
            <div class="absolute -right-16 -bottom-16 w-64 h-64 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>
            <div class="relative z-10">
                <h3 class="text-3xl font-extrabold mb-3">Siap Mengajukan Permohonan?</h3>
                <p class="text-white/90 text-sm max-w-xl mx-auto mb-8 leading-relaxed">
                    Aparatur desa kami siap memberikan pelayanan terbaik secara transparan, profesional, dan akuntabel.
                </p>
                <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                    @auth
                        <a href="{{ route('dashboard') }}" class="btn-soft-white text-sm py-3 px-8 text-slate-800 font-bold">
                            Buka Dashboard Saya
                        </a>
                    @else
                        <a href="{{ route('register') }}" class="btn-soft-white text-sm py-3 px-8 text-slate-800 font-bold">
                            Mulai Sekarang &bull; Gratis
                        </a>
                        <a href="{{ route('login') }}" class="text-sm text-white/90 hover:text-white font-bold underline">
                            Sudah punya akun? Masuk &rarr;
                        </a>
                    @endauth
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-white border-t border-slate-200/60 py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row justify-between items-center gap-6">
            <div class="flex items-center space-x-3">
                <div class="w-8 h-8 rounded-lg bg-gradient-primary flex items-center justify-center text-white text-xs">
                    <i class="fas fa-landmark"></i>
                </div>
                <span class="font-bold text-slate-800 text-sm">Sistem Pelayanan Desa Terpadu</span>
            </div>
            <div class="flex items-center space-x-6 text-xs text-slate-500 font-medium">
                <a href="/informasi-sktm" class="hover:text-pink-600 transition-colors">Informasi SKTM</a>
                <a href="/persyaratan" class="hover:text-pink-600 transition-colors">Persyaratan</a>
                <a href="/faq" class="hover:text-pink-600 transition-colors">FAQ</a>
                <a href="{{ route('login') }}" class="hover:text-pink-600 transition-colors">Portal Petugas</a>
            </div>
            <p class="text-slate-400 text-xs">
                &copy; {{ date('Y') }} Hak Cipta Dilindungi Undang-Undang.
            </p>
        </div>
    </footer>
</x-layouts.app>
