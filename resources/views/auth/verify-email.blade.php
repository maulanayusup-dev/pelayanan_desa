<x-layouts.app :standalone="true">
    <x-slot:title>Verifikasi Alamat Email - Pelayanan Desa</x-slot>

    <div class="min-h-screen bg-[#F8F9FA] dark:bg-slate-900 flex flex-col justify-between transition-colors duration-200">
        <!-- Curved Top Header -->
        <div class="relative pt-12 pb-36 px-4 bg-gradient-dark text-white rounded-b-3xl overflow-hidden shadow-soft-sm">
            <div class="absolute inset-0 bg-gradient-to-r from-blue-900/30 to-purple-900/30"></div>
            <div class="absolute -top-24 -right-24 w-72 h-72 bg-gradient-info rounded-full blur-3xl opacity-20 pointer-events-none"></div>

            <div class="absolute top-4 right-4 z-20">
                <button type="button" onclick="window.toggleTheme()" class="p-2.5 rounded-xl bg-white/10 hover:bg-white/20 text-white backdrop-blur-md transition-all shadow-sm cursor-pointer" title="Ganti Tema">
                    <i class="fas fa-sun block dark:hidden text-amber-300 text-sm"></i>
                    <i class="fas fa-moon hidden dark:block text-indigo-300 text-sm"></i>
                </button>
            </div>

            <div class="max-w-md mx-auto text-center relative z-10">
                <a href="/" class="inline-flex items-center space-x-2 text-white mb-6 group">
                    <div class="w-10 h-10 rounded-xl bg-gradient-primary flex items-center justify-center text-white shadow-soft-sm group-hover:scale-105 transition-transform">
                        <i class="fas fa-landmark text-base"></i>
                    </div>
                    <span class="font-extrabold text-lg tracking-tight">Pelayanan Desa</span>
                </a>
                <h1 class="text-3xl font-extrabold tracking-tight text-white mb-2">Verifikasi Email</h1>
                <p class="text-xs text-slate-300 max-w-xs mx-auto">Satu langkah lagi untuk mengamankan dan mengaktifkan akun warga Anda.</p>
            </div>
        </div>

        <!-- Floating Card Container -->
        <div class="max-w-md w-full mx-auto px-4 -mt-24 sm:-mt-28 relative z-20 mb-12">
            <div class="card-soft p-6 sm:p-8 bg-white dark:bg-slate-800 shadow-soft border border-slate-100 dark:border-slate-700 text-center">
                
                <div class="w-16 h-16 rounded-2xl bg-pink-50 dark:bg-pink-950/40 text-pink-600 dark:text-pink-400 mx-auto flex items-center justify-center text-2xl shadow-soft-sm mb-4">
                    <i class="fas fa-envelope-open-text"></i>
                </div>

                <h5 class="font-bold text-slate-800 dark:text-slate-100 text-lg mb-2">Periksa Email Anda</h5>
                <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed mb-4">
                    Tautan verifikasi resmi telah dikirimkan ke alamat email <strong>{{ auth()->user()->email ?? 'Anda' }}</strong>.
                    Silakan periksa <strong>Kotak Masuk (Inbox)</strong> atau folder <strong>Spam / Junk</strong> email Anda, lalu klik tautan di dalamnya untuk mengaktifkan akun warga.
                </p>

                <div class="rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 p-3 mb-5 text-xs text-amber-800 dark:text-amber-200 flex items-start text-left">
                    <i class="fas fa-shield-halved text-amber-500 mr-2.5 mt-0.5 shrink-0 text-sm"></i>
                    <span class="leading-relaxed">Akses dashboard dan pengajuan surat dilindungi. Anda wajib memverifikasi kepemilikan email sebelum dapat membuat permohonan surat.</span>
                </div>

                @if (session('status') == 'verification-link-sent')
                    <div class="rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 p-3.5 mb-5 text-xs text-emerald-700 dark:text-emerald-300 flex items-start text-left">
                        <i class="fas fa-check-circle mr-2.5 mt-0.5 text-emerald-500 shrink-0 text-sm"></i>
                        <span class="leading-relaxed">Tautan verifikasi resmi telah berhasil dikirimkan ke email Anda. Silakan periksa kotak masuk (Inbox) atau folder Spam/Junk.</span>
                    </div>
                @endif

                @if (session('warning'))
                    <div class="rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 p-3.5 mb-5 text-xs text-amber-800 dark:text-amber-200 flex items-start text-left">
                        <i class="fas fa-triangle-exclamation mr-2.5 mt-0.5 text-amber-500 shrink-0 text-sm"></i>
                        <span class="leading-relaxed">{{ session('warning') }}</span>
                    </div>
                @endif

                @if (session('error'))
                    <div class="rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 p-3.5 mb-5 text-xs text-rose-700 dark:text-rose-300 flex items-start text-left">
                        <i class="fas fa-circle-xmark mr-2.5 mt-0.5 text-rose-500 shrink-0 text-sm"></i>
                        <span class="leading-relaxed">{{ session('error') }}</span>
                    </div>
                @endif

                <div class="space-y-3">
                    <form method="POST" action="{{ route('verification.send') }}">
                        @csrf
                        <button type="submit" class="btn-soft-primary w-full py-3 text-xs tracking-wider uppercase cursor-pointer">
                            <i class="fas fa-paper-plane mr-2"></i> Kirim Ulang Tautan Verifikasi
                        </button>
                    </form>
                </div>

                <div class="mt-6 pt-5 border-t border-slate-100 dark:border-slate-700 flex items-center justify-between text-xs text-slate-400 dark:text-slate-500">
                    <span>Salah alamat email?</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="font-bold text-pink-600 dark:text-pink-400 hover:underline cursor-pointer bg-transparent border-0 p-0">
                            Keluar Akun
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="text-center pb-8 text-xs text-slate-400 dark:text-slate-500">
            &copy; {{ date('Y') }} Sistem Pelayanan Desa Terpadu &bull; Dilindungi Keamanan Enkripsi
        </div>
    </div>
</x-layouts.app>
