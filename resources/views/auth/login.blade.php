<x-layouts.app :standalone="true">
    <x-slot:title>Masuk ke Portal Pelayanan Desa</x-slot>

    <div class="min-h-screen bg-[#F8F9FA] dark:bg-slate-900 flex flex-col justify-between transition-colors duration-200">
        
        <!-- Curved Top Header (Soft UI Iconic Style) -->
        <div class="relative pt-12 pb-36 px-4 bg-gradient-dark text-white rounded-b-3xl overflow-hidden shadow-soft-sm">
            <div class="absolute inset-0 bg-gradient-to-r from-purple-900/30 to-pink-900/30"></div>
            <div class="absolute -top-24 -right-24 w-72 h-72 bg-gradient-primary rounded-full blur-3xl opacity-20 pointer-events-none"></div>

            <!-- Standalone Theme Toggle at top right -->
            <div class="absolute top-4 right-4 z-20">
                <button type="button" onclick="window.toggleTheme()" class="p-2.5 rounded-xl bg-white/10 hover:bg-white/20 text-white backdrop-blur-md transition-all shadow-sm cursor-pointer" title="Ganti Tema (Light/Dark)">
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
                <h1 class="text-3xl font-extrabold tracking-tight text-white mb-2">Selamat Datang Kembali</h1>
                <p class="text-xs text-slate-300 max-w-xs mx-auto">Masuk dengan email dan password akun Anda untuk mengakses layanan surat desa.</p>
            </div>
        </div>

        <!-- Floating Card Container -->
        <div class="max-w-md w-full mx-auto px-4 -mt-24 sm:-mt-28 relative z-20 mb-12">
            <div class="card-soft p-6 sm:p-8 bg-white dark:bg-slate-800 shadow-soft border border-slate-100 dark:border-slate-700">
                
                <div class="text-center pb-4 mb-4 border-b border-slate-100 dark:border-slate-700">
                    <h5 class="font-bold text-slate-800 dark:text-slate-100 text-base">Login ke Sistem Pelayanan Desa</h5>
                    <p class="text-xs text-slate-400 dark:text-slate-400 mt-0.5">Warga, Staf, Supervisor, & Kepala Desa</p>
                </div>

                @if($errors->any())
                    <div class="rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 p-3 mb-5 text-xs text-rose-700 dark:text-rose-300 flex items-start">
                        <i class="fas fa-exclamation-circle text-rose-500 mr-2 mt-0.5 shrink-0"></i>
                        <span>{{ $errors->first() }}</span>
                    </div>
                @endif

                <div id="error-message" class="hidden rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 p-3 mb-5 text-xs text-rose-700 dark:text-rose-300 flex items-start">
                    <i class="fas fa-exclamation-circle text-rose-500 mr-2 mt-0.5 shrink-0"></i>
                    <span id="error-text">Login gagal.</span>
                </div>

                <form role="form" class="space-y-4" method="POST" action="{{ route('login') }}" onsubmit="event.preventDefault(); login(this);">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Alamat Email</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                <i class="fas fa-envelope text-xs"></i>
                            </span>
                            <input type="email" name="email" value="{{ old('email') }}" class="block w-full pl-10 pr-4 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500" placeholder="nama@email.com" required>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Kata Sandi</label>
                            <span class="text-xxs text-slate-400">Min. 8 karakter</span>
                        </div>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                <i class="fas fa-lock text-xs"></i>
                            </span>
                            <input type="password" name="password" class="block w-full pl-10 pr-4 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500" placeholder="••••••••" required>
                        </div>
                    </div>

                    <div class="flex items-center justify-between py-1">
                        <label class="flex items-center text-xs text-slate-600 dark:text-slate-300 cursor-pointer">
                            <input id="rememberMe" name="remember" type="checkbox" class="w-4 h-4 text-pink-600 focus:ring-pink-500 border-slate-300 dark:border-slate-600 rounded">
                            <span class="ml-2 font-medium">Ingat saya di perangkat ini</span>
                        </label>
                    </div>

                    <button type="submit" id="btn-submit" class="btn-soft-primary w-full py-3 text-xs tracking-wider uppercase cursor-pointer">
                        <i class="fas fa-sign-in-alt mr-2"></i> Masuk Sekarang
                    </button>
                </form>

                <div class="mt-6 pt-5 border-t border-slate-100 dark:border-slate-700 text-center">
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Belum memiliki akun warga?
                        <a href="{{ route('register') }}" class="font-bold text-pink-600 dark:text-pink-400 hover:text-pink-700 dark:hover:text-pink-300 ml-1 transition-colors">Daftar Akun Baru</a>
                    </p>
                    <a href="{{ url('/') }}" class="inline-flex items-center mt-3 text-xs text-slate-400 dark:text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 transition-colors">
                        <i class="fas fa-arrow-left mr-1.5"></i> Kembali ke Beranda
                    </a>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="text-center pb-8 text-xs text-slate-400 dark:text-slate-500">
            &copy; {{ date('Y') }} Sistem Pelayanan Desa Terpadu &bull; Dilindungi Keamanan Enkripsi
        </div>
    </div>

    <script>
        async function login(form) {
            const formData = new FormData(form);
            const errDiv = document.getElementById('error-message');
            const errText = document.getElementById('error-text');
            const btn = document.getElementById('btn-submit');
            
            errDiv.classList.add('hidden');
            btn.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-2"></i> Memeriksa Sesi...';
            btn.disabled = true;
            
            try {
                const res = await fetch("{{ route('login') }}", {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: formData
                });
                const data = await res.json();
                
                if (res.ok) {
                    if (data.access_token) {
                        localStorage.setItem('auth_token', data.access_token);
                    }
                    btn.innerHTML = '<i class="fas fa-check mr-2"></i> Berhasil Masuk';
                    btn.classList.add('bg-gradient-success');
                    
                    setTimeout(() => {
                        window.location.href = data.redirect_url || (data.role === 'masyarakat' ? "{{ route('dashboard') }}" : "{{ route('admin.dashboard') }}");
                    }, 400);
                } else {
                    errText.innerText = (data.errors && data.errors.email ? data.errors.email[0] : null) || data.message || 'Login gagal. Periksa kembali email dan kata sandi Anda.';
                    errDiv.classList.remove('hidden');
                    btn.innerHTML = '<i class="fas fa-sign-in-alt mr-2"></i> Masuk Sekarang';
                    btn.disabled = false;
                }
            } catch (e) {
                // Fallback to standard form submission if fetch json fails
                form.submit();
            }
        }
    </script>
</x-layouts.app>
