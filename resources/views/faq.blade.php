<x-layouts.app :standalone="true">
    <x-slot:title>Pertanyaan yang Sering Diajukan (FAQ)</x-slot>

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
                        <span class="text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider">Portal Digital</span>
                    </div>
                </a>

                <div class="hidden md:flex items-center space-x-6 text-sm font-semibold text-slate-600 dark:text-slate-300">
                    <a href="/" class="hover:text-pink-600 dark:hover:text-pink-400 transition-colors">Beranda</a>
                    <a href="/informasi-sktm" class="hover:text-pink-600 dark:hover:text-pink-400 transition-colors">Info SKTM</a>
                    <a href="/persyaratan" class="hover:text-pink-600 dark:hover:text-pink-400 transition-colors">Persyaratan</a>
                    <a href="/faq" class="text-pink-600 dark:text-pink-400 font-bold">FAQ</a>
                </div>

                <div class="flex items-center space-x-3">
                    <button type="button" onclick="window.toggleTheme()" class="p-2 rounded-xl text-slate-500 dark:text-slate-300 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all cursor-pointer" title="Ganti Tema">
                        <i class="fas fa-sun block dark:hidden text-amber-500 text-sm"></i>
                        <i class="fas fa-moon hidden dark:block text-indigo-400 text-sm"></i>
                    </button>
                    @auth
                        <a href="{{ auth()->user()->role?->name === 'masyarakat' ? route('dashboard') : route('admin.dashboard') }}" class="btn-soft-primary text-xs py-2 px-5">
                            Dashboard
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="btn-soft-white text-xs py-2 px-4">
                            Masuk
                        </a>
                        <a href="{{ route('register') }}" class="btn-soft-primary text-xs py-2 px-5 hidden sm:inline-flex">
                            Daftar
                        </a>
                    @endauth
                </div>
            </nav>
        </div>
    </header>

    <!-- Header Section -->
    <section class="pt-36 pb-16 bg-[#F8F9FA] dark:bg-slate-900 relative overflow-hidden transition-colors duration-200">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative z-10 animate-fade-in-up">
            <span class="badge-soft-warning text-xs mb-3">Pusat Bantuan & FAQ</span>
            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-slate-900 dark:text-white tracking-tight mb-4">
                Pertanyaan yang Sering Diajukan
            </h1>
            <p class="text-sm sm:text-base text-slate-500 dark:text-slate-400 max-w-xl mx-auto">
                Temukan jawaban ringkas seputar prosedur, syarat, biaya, dan validasi SKTM online desa.
            </p>
        </div>
    </section>

    <!-- FAQ List -->
    <section class="pb-24 bg-[#F8F9FA]">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4 animate-fade-in-up" style="animation-delay: 0.1s;">
            
            <!-- Item 1 -->
            <div class="card-soft p-6 bg-white shadow-soft border border-slate-100">
                <h3 class="text-base font-bold text-slate-800 mb-2 flex items-center">
                    <span class="w-7 h-7 rounded-lg bg-gradient-primary text-white text-xs font-bold flex items-center justify-center mr-3 shadow-soft-sm">1</span>
                    Berapa lama proses pembuatan SKTM online hingga selesai?
                </h3>
                <p class="text-sm text-slate-500 leading-relaxed pl-10">
                    Proses verifikasi berjenjang (Staf &rarr; Supervisor &rarr; Kepala Desa) memakan waktu estimasi <strong>1 hingga 2 hari kerja</strong> setelah berkas dinyatakan lengkap dan valid. Anda akan menerima notifikasi setiap ada perubahan status permohonan.
                </p>
            </div>

            <!-- Item 2 -->
            <div class="card-soft p-6 bg-white shadow-soft border border-slate-100">
                <h3 class="text-base font-bold text-slate-800 mb-2 flex items-center">
                    <span class="w-7 h-7 rounded-lg bg-gradient-success text-white text-xs font-bold flex items-center justify-center mr-3 shadow-soft-sm">2</span>
                    Apakah pengurusan SKTM ini dipungut biaya?
                </h3>
                <p class="text-sm text-slate-500 leading-relaxed pl-10">
                    <strong>100% GRATIS (Rp 0).</strong> Seluruh pengurusan surat pelayanan publik di desa ini tidak dipungut biaya apa pun. Laporkan bila menemukan indikasi pungutan liar.
                </p>
            </div>

            <!-- Item 3 -->
            <div class="card-soft p-6 bg-white shadow-soft border border-slate-100">
                <h3 class="text-base font-bold text-slate-800 mb-2 flex items-center">
                    <span class="w-7 h-7 rounded-lg bg-gradient-info text-white text-xs font-bold flex items-center justify-center mr-3 shadow-soft-sm">3</span>
                    Bagaimana jika berkas permohonan saya dikembalikan (Revisi)?
                </h3>
                <p class="text-sm text-slate-500 leading-relaxed pl-10">
                    Jika terdapat foto berkas yang buram, tidak terbaca, atau salah dokumen, petugas akan memberi catatan revisi. Anda dapat membuka halaman detail pengajuan, membaca catatan petugas, lalu mengunggah ulang berkas perbaikan tanpa perlu membuat pengajuan dari awal.
                </p>
            </div>

            <!-- Item 4 -->
            <div class="card-soft p-6 bg-white shadow-soft border border-slate-100">
                <h3 class="text-base font-bold text-slate-800 mb-2 flex items-center">
                    <span class="w-7 h-7 rounded-lg bg-gradient-warning text-white text-xs font-bold flex items-center justify-center mr-3 shadow-soft-sm">4</span>
                    Bagaimana pihak sekolah / kampus mengecek keabsahan dokumen saya?
                </h3>
                <p class="text-sm text-slate-500 leading-relaxed pl-10">
                    Setiap SKTM resmi yang diterbitkan memiliki <strong>QR Code digital dan nomor surat unik</strong>. Pihak universitas atau rumah sakit cukup memindai QR Code tersebut dengan kamera ponsel untuk langsung membuka lembar verifikasi resmi di portal desa.
                </p>
            </div>

            <!-- Item 5 -->
            <div class="card-soft p-6 bg-white shadow-soft border border-slate-100">
                <h3 class="text-base font-bold text-slate-800 mb-2 flex items-center">
                    <span class="w-7 h-7 rounded-lg bg-gradient-dark text-white text-xs font-bold flex items-center justify-center mr-3 shadow-soft-sm">5</span>
                    Apakah pengajuan bisa dilakukan lewat smartphone?
                </h3>
                <p class="text-sm text-slate-500 leading-relaxed pl-10">
                    Ya, portal ini sepenuhnya responsif dan dioptimalkan untuk layar ponsel pintar. Anda dapat langsung memfoto dokumen KTP & KK menggunakan kamera HP dan mengunggahnya ke formulir.
                </p>
            </div>

            <!-- Prompt card for AI Assistant -->
            <div class="card-soft p-6 bg-gradient-to-r from-purple-50 to-pink-50 border border-pink-200 mt-8 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-primary text-white flex items-center justify-center text-sm shadow-soft-sm shrink-0">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div>
                        <h4 class="font-bold text-slate-800 text-sm">Punya pertanyaan lain?</h4>
                        <p class="text-xs text-slate-500">Tanyakan langsung pada Asisten AI yang siap menjawab 24 jam.</p>
                    </div>
                </div>
                <button type="button" onclick="window.dispatchEvent(new CustomEvent('open-chatbot'))" class="btn-soft-primary text-xs py-2 px-5 whitespace-nowrap cursor-pointer">
                    Buka Asisten AI
                </button>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-white border-t border-slate-200/60 py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row justify-between items-center text-xs text-slate-400 gap-4">
            <div>&copy; {{ date('Y') }} Sistem Pelayanan Desa Terpadu. All rights reserved.</div>
            <div class="flex items-center space-x-4">
                <a href="/" class="hover:text-pink-600 transition-colors">Beranda</a>
                <a href="/informasi-sktm" class="hover:text-pink-600 transition-colors">Informasi</a>
                <a href="/persyaratan" class="hover:text-pink-600 transition-colors">Persyaratan</a>
            </div>
        </div>
    </footer>
</x-layouts.app>
