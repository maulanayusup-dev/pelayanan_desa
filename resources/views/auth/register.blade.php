<x-layouts.app :standalone="true">
    <x-slot:title>Pendaftaran Akun Warga</x-slot>

    <div class="min-h-screen bg-[#F8F9FA] dark:bg-slate-900 flex flex-col justify-between transition-colors duration-200">
        
        <!-- Curved Top Header (Soft UI Iconic Style) -->
        <div class="relative pt-12 pb-36 px-4 bg-gradient-dark text-white rounded-b-3xl overflow-hidden shadow-soft-sm">
            <div class="absolute inset-0 bg-gradient-to-r from-blue-900/30 to-purple-900/30"></div>
            <div class="absolute -top-24 -right-24 w-72 h-72 bg-gradient-info rounded-full blur-3xl opacity-20 pointer-events-none"></div>

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
                <h1 class="text-3xl font-extrabold tracking-tight text-white mb-2">Registrasi Akun Baru</h1>
                <p class="text-xs text-slate-300 max-w-xs mx-auto">Daftarkan data diri Anda untuk membuat permohonan SKTM secara online dan mandiri.</p>
            </div>
        </div>

        <!-- Floating Card Container -->
        <div class="max-w-md w-full mx-auto px-4 -mt-24 sm:-mt-28 relative z-20 mb-12">
            <div class="card-soft p-6 sm:p-8 bg-white dark:bg-slate-800 shadow-soft border border-slate-100 dark:border-slate-700">
                
                <div class="text-center pb-4 mb-4 border-b border-slate-100 dark:border-slate-700">
                    <h5 class="font-bold text-slate-800 dark:text-slate-100 text-base">Lengkapi Data Akun</h5>
                    <p class="text-xs text-slate-400 dark:text-slate-400 mt-0.5">Satu akun untuk seluruh pelayanan surat desa</p>
                </div>

                @if($errors->any())
                    <div class="rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 p-3 mb-5 text-xs text-rose-700 dark:text-rose-300 flex items-start">
                        <i class="fas fa-exclamation-circle text-rose-500 mr-2 mt-0.5 shrink-0"></i>
                        <span>{{ $errors->first() }}</span>
                    </div>
                @endif

                <div id="error-message" class="hidden rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 p-3 mb-5 text-xs text-rose-700 dark:text-rose-300 flex items-start">
                    <i class="fas fa-exclamation-circle text-rose-500 mr-2 mt-0.5 shrink-0"></i>
                    <span id="error-text">Pendaftaran gagal.</span>
                </div>

                <form role="form" class="space-y-4" method="POST" action="{{ route('register') }}" onsubmit="event.preventDefault(); register(this);">
                    @csrf

                    <!-- Bagian A: Data Akun -->
                    <div class="space-y-3.5">
                        <div class="flex items-center space-x-2 pb-1 border-b border-slate-100 dark:border-slate-700/80">
                            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-pink-100 dark:bg-pink-950/60 text-pink-600 dark:text-pink-400 text-xxs font-bold">1</span>
                            <span class="text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Data Akun</span>
                        </div>

                        <div>
                            <label for="name" class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Nama Lengkap</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                    <i class="fas fa-user text-xs"></i>
                                </span>
                                <input type="text" id="name" name="name" autocomplete="name" value="{{ old('name') }}" class="block w-full pl-10 pr-4 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500" placeholder="Nama Lengkap" required>
                            </div>
                        </div>

                        <div>
                            <label for="email" class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Alamat Email Aktif</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                    <i class="fas fa-envelope text-xs"></i>
                                </span>
                                <input type="email" id="email" name="email" autocomplete="email" value="{{ old('email') }}" class="block w-full pl-10 pr-4 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500" placeholder="nama@email.com" required>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div>
                                <div class="flex items-center justify-between mb-1.5">
                                    <label for="password" class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Kata Sandi</label>
                                    <span class="text-xxs text-slate-400">Min. 8</span>
                                </div>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                        <i class="fas fa-lock text-xs"></i>
                                    </span>
                                    <input type="password" id="password" name="password" autocomplete="new-password" class="block w-full pl-10 pr-4 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500" placeholder="••••••••" required>
                                </div>
                            </div>

                            <div>
                                <label for="password_confirmation" class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Ulangi Sandi</label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                        <i class="fas fa-shield-alt text-xs"></i>
                                    </span>
                                    <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" class="block w-full pl-10 pr-4 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500" placeholder="••••••••" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bagian B: Data Diri (Hanya Jenis Kelamin & Nomor HP) -->
                    <div class="pt-2 space-y-3.5">
                        <div class="flex items-center space-x-2 pb-1 border-b border-slate-100 dark:border-slate-700/80">
                            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-pink-100 dark:bg-pink-950/60 text-pink-600 dark:text-pink-400 text-xxs font-bold">2</span>
                            <span class="text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Data Diri</span>
                        </div>

                        <div>
                            <label for="gender" class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Jenis Kelamin</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                    <i class="fas fa-venus-mars text-xs"></i>
                                </span>
                                <select id="gender" name="gender" class="block w-full pl-10 pr-9 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 shadow-soft-sm outline-none transition-all cursor-pointer appearance-none" required>
                                    <option value="" disabled {{ old('gender') ? '' : 'selected' }}>-- Pilih Jenis Kelamin --</option>
                                    <option value="L" {{ old('gender') === 'L' ? 'selected' : '' }}>Laki-laki</option>
                                    <option value="P" {{ old('gender') === 'P' ? 'selected' : '' }}>Perempuan</option>
                                </select>
                                <span class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400 text-xs">
                                    <i class="fas fa-chevron-down text-xxs"></i>
                                </span>
                            </div>
                        </div>

                        <div>
                            <label for="phone_number" class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Nomor HP / WhatsApp Aktif</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                    <i class="fas fa-phone-alt text-xs"></i>
                                </span>
                                <input type="tel" id="phone_number" name="phone_number" inputmode="tel" autocomplete="tel" value="{{ old('phone_number') }}" class="block w-full pl-10 pr-4 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500" placeholder="081234567890" required>
                            </div>
                            <span class="text-xxs text-slate-400 dark:text-slate-500 mt-1 block">Format: diawali 08 atau +62 (10 - 20 digit)</span>
                        </div>
                    </div>

                    <div class="pt-3">
                        <button type="submit" id="btn-submit" class="btn-soft-primary w-full py-3 text-xs tracking-wider uppercase cursor-pointer">
                            <i class="fas fa-user-plus mr-2"></i> Daftar Akun Warga
                        </button>
                    </div>
                </form>

                <div class="mt-6 pt-5 border-t border-slate-100 dark:border-slate-700 text-center">
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Sudah memiliki akun terdaftar?
                        <a href="{{ route('login') }}" class="font-bold text-pink-600 dark:text-pink-400 hover:text-pink-700 dark:hover:text-pink-300 ml-1 transition-colors">Masuk di sini</a>
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
        async function register(form) {
            const formData = new FormData(form);
            const errDiv = document.getElementById('error-message');
            const errText = document.getElementById('error-text');
            const btn = document.getElementById('btn-submit');
            
            errDiv.classList.add('hidden');
            btn.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-2"></i> Mendaftarkan Akun...';
            btn.disabled = true;
            
            try {
                const res = await fetch("{{ route('register') }}", {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: formData
                });
                const data = await res.json();
                
                if (res.ok) {
                    if (data.access_token) {
                        localStorage.setItem('auth_token', data.access_token);
                    }
                    btn.innerHTML = '<i class="fas fa-check mr-2"></i> Pendaftaran Berhasil';
                    btn.classList.add('bg-gradient-success');
                    
                    setTimeout(() => {
                        window.location.href = data.redirect_url || "{{ route('verification.notice') }}";
                    }, 400);
                } else {
                    let errMsg = data.message || 'Pendaftaran gagal.';
                    if (data.errors) {
                        const firstKey = Object.keys(data.errors)[0];
                        if (firstKey) errMsg = data.errors[firstKey][0];
                    }
                    errText.innerText = errMsg;
                    errDiv.classList.remove('hidden');

                    // Bersihkan field kata sandi demi keamanan
                    const pwd = form.querySelector('input[name="password"]');
                    const pwdConfirm = form.querySelector('input[name="password_confirmation"]');
                    if (pwd) pwd.value = '';
                    if (pwdConfirm) pwdConfirm.value = '';

                    btn.innerHTML = '<i class="fas fa-user-plus mr-2"></i> Daftar Akun Warga';
                    btn.disabled = false;
                }
            } catch (e) {
                // Fallback to standard form submission if fetch json fails
                form.submit();
            }
        }
    </script>
</x-layouts.app>
