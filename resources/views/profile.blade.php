<x-layouts.app>
    <x-slot:title>Profil Saya</x-slot>

    <!-- Header Section -->
    <div class="relative overflow-hidden bg-gradient-primary rounded-2xl shadow-soft mb-6 p-6 text-white animate-fade-in-up">
        <div class="absolute -top-24 -right-24 w-64 h-64 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -left-24 w-64 h-64 bg-purple-900/20 rounded-full blur-2xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="flex flex-col md:flex-row items-center text-center md:text-left gap-4">
                <div class="w-20 h-20 rounded-2xl bg-white/20 backdrop-blur-md flex items-center justify-center text-3xl font-extrabold text-white shadow-soft border-2 border-white/30 shrink-0">
                    {{ substr(auth()->user()->name, 0, 1) }}
                </div>
                <div>
                    <div class="flex flex-wrap items-center justify-center md:justify-start gap-2 mb-1">
                        <h1 class="text-2xl font-extrabold tracking-tight text-white">
                            {{ auth()->user()->name }}
                        </h1>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-white/25 backdrop-blur-md text-white uppercase tracking-wider">
                            {{ auth()->user()->role->name ?? 'Masyarakat' }}
                        </span>
                    </div>
                    <p class="text-xs text-white/85 flex items-center justify-center md:justify-start">
                        <i class="fas fa-envelope mr-1.5 text-white/70"></i> {{ auth()->user()->email }}
                    </p>
                </div>
            </div>

            <div class="text-center md:text-right">
                <span class="text-[11px] text-white/75 font-semibold block">Terdaftar Dalam Sistem:</span>
                <span class="text-xs text-white font-bold bg-white/15 px-3 py-1 rounded-lg backdrop-blur-sm border border-white/10 inline-block mt-0.5">
                    {{ auth()->user()->created_at->translatedFormat('d F Y') }}
                </span>
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="p-4 mb-6 text-sm text-emerald-800 dark:text-emerald-200 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-2xl shadow-soft flex items-center animate-fade-in-up" role="alert">
            <div class="w-8 h-8 rounded-xl bg-emerald-500 text-white flex items-center justify-center mr-3 shrink-0 shadow-sm">
                <i class="fas fa-check text-sm"></i>
            </div>
            <div>
                <span class="font-bold">Berhasil!</span> {{ session('success') }}
            </div>
        </div>
    @endif

    @if (session('success_password'))
        <div class="p-4 mb-6 text-sm text-emerald-800 dark:text-emerald-200 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-2xl shadow-soft flex items-center animate-fade-in-up" role="alert">
            <div class="w-8 h-8 rounded-xl bg-emerald-500 text-white flex items-center justify-center mr-3 shrink-0 shadow-sm">
                <i class="fas fa-key text-sm"></i>
            </div>
            <div>
                <span class="font-bold">Berhasil!</span> {{ session('success_password') }}
            </div>
        </div>
    @endif

    @if (session('error'))
        <div class="p-4 mb-6 text-sm text-rose-800 dark:text-rose-200 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 rounded-2xl shadow-soft flex items-center animate-fade-in-up" role="alert">
            <div class="w-8 h-8 rounded-xl bg-rose-500 text-white flex items-center justify-center mr-3 shrink-0 shadow-sm">
                <i class="fas fa-exclamation-triangle text-sm"></i>
            </div>
            <div>
                <span class="font-bold">Perhatian:</span> {{ session('error') }}
            </div>
        </div>
    @endif

    @if ($errors->any())
        <div class="p-4 mb-6 text-sm text-rose-800 dark:text-rose-200 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 rounded-2xl shadow-soft animate-fade-in-up" role="alert">
            <div class="flex items-center mb-1 font-bold">
                <i class="fas fa-exclamation-triangle mr-2 text-rose-500"></i> Perhatian, periksa kembali formulir:
            </div>
            <ul class="list-disc list-inside space-y-0.5 text-xs text-rose-700 dark:text-rose-300">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Form Edit Data Profil & Kependudukan -->
        <div class="lg:col-span-2 animate-fade-in-up">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-soft border border-slate-100 dark:border-slate-700 overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700 flex items-center">
                    <div class="w-10 h-10 rounded-xl bg-gradient-primary text-white flex items-center justify-center mr-3 shadow-soft">
                        <i class="fas fa-id-card text-sm"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-slate-800 dark:text-slate-100">Data Profil & Kependudukan</h3>
                        <p class="text-xs text-slate-400">Data ini digunakan otomatis saat Anda mengajukan surat pelayanan desa</p>
                    </div>
                </div>

                @php $profile = auth()->user()->profile; @endphp
                <form action="{{ route('profile.update') }}" method="POST" class="p-6 space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Nama Lengkap -->
                        <div class="md:col-span-2">
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Nama Lengkap *</label>
                            <input type="text" name="name" value="{{ old('name', auth()->user()->name) }}" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 text-xs font-semibold text-slate-800 dark:text-slate-100 transition-all" placeholder="Nama lengkap sesuai KTP" />
                        </div>

                        <!-- Email (Read-Only) -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider mb-1.5">Email Akun (Tetap)</label>
                            <input type="email" value="{{ auth()->user()->email }}" disabled class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950/60 text-slate-400 dark:text-slate-500 text-xs cursor-not-allowed font-medium" />
                        </div>

                        <!-- Nomor Telepon -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Nomor Telepon / WhatsApp</label>
                            <input type="text" name="phone_number" value="{{ old('phone_number', $profile->phone_number ?? '') }}" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 text-xs font-semibold text-slate-800 dark:text-slate-100 transition-all" placeholder="Contoh: 081234567890" />
                        </div>

                        <!-- NIK -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">NIK (16 Digit)</label>
                            <input type="text" name="nik" maxlength="16" value="{{ old('nik', $profile->nik ?? '') }}" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 text-xs font-mono font-semibold text-slate-800 dark:text-slate-100 transition-all" placeholder="16 digit NIK" />
                        </div>

                        <!-- No KK -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Nomor KK (16 Digit)</label>
                            <input type="text" name="no_kk" maxlength="16" value="{{ old('no_kk', $profile->no_kk ?? '') }}" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 text-xs font-mono font-semibold text-slate-800 dark:text-slate-100 transition-all" placeholder="16 digit No KK" />
                        </div>

                        <!-- Tempat Lahir -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Tempat Lahir</label>
                            <input type="text" name="place_of_birth" value="{{ old('place_of_birth', $profile->place_of_birth ?? '') }}" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 text-xs font-semibold text-slate-800 dark:text-slate-100 transition-all" placeholder="Kota / Kabupaten lahir" />
                        </div>

                        <!-- Tanggal Lahir -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Tanggal Lahir</label>
                            <input type="date" name="date_of_birth" value="{{ old('date_of_birth', $profile && $profile->date_of_birth ? \Carbon\Carbon::parse($profile->date_of_birth)->format('Y-m-d') : '') }}" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 text-xs font-semibold text-slate-800 dark:text-slate-100 transition-all" />
                        </div>

                        <!-- Jenis Kelamin -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Jenis Kelamin</label>
                            <select name="gender" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 text-xs font-semibold text-slate-800 dark:text-slate-100 bg-white dark:bg-slate-900 transition-all">
                                <option value="">-- Pilih Jenis Kelamin --</option>
                                <option value="L" {{ old('gender', $profile->gender ?? '') === 'L' ? 'selected' : '' }}>Laki-laki</option>
                                <option value="P" {{ old('gender', $profile->gender ?? '') === 'P' ? 'selected' : '' }}>Perempuan</option>
                            </select>
                        </div>

                        <!-- Pekerjaan -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Pekerjaan</label>
                            <input type="text" name="occupation" value="{{ old('occupation', $profile->occupation ?? '') }}" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 text-xs font-semibold text-slate-800 dark:text-slate-100 transition-all" placeholder="Pekerjaan saat ini" />
                        </div>

                        <!-- Penghasilan per Bulan -->
                        <div class="md:col-span-2">
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Penghasilan Rata-rata per Bulan (Rp)</label>
                            <input type="number" name="penghasilan" min="0" step="10000" value="{{ old('penghasilan', $profile->penghasilan ?? '') }}" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 text-xs font-semibold text-slate-800 dark:text-slate-100 transition-all" placeholder="Contoh: 1500000" />
                        </div>

                        <!-- Alamat Jalan -->
                        <div class="md:col-span-2">
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Alamat Rumah / Dusun</label>
                            <textarea name="address" rows="2" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 text-xs font-semibold text-slate-800 dark:text-slate-100 transition-all" placeholder="Nama jalan, nomor rumah, nama dusun">{{ old('address', $profile->address ?? '') }}</textarea>
                        </div>

                        <!-- RT & RW -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">RT</label>
                            <input type="text" name="rt" value="{{ old('rt', $profile->rt ?? '') }}" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 text-xs font-semibold text-slate-800 dark:text-slate-100 transition-all" placeholder="001" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">RW</label>
                            <input type="text" name="rw" value="{{ old('rw', $profile->rw ?? '') }}" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 text-xs font-semibold text-slate-800 dark:text-slate-100 transition-all" placeholder="002" />
                        </div>

                        <!-- Desa & Kecamatan -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Desa / Kelurahan</label>
                            <input type="text" name="desa" value="{{ old('desa', $profile->desa ?? 'Desa Pelayanan') }}" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 text-xs font-semibold text-slate-800 dark:text-slate-100 transition-all" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Kecamatan</label>
                            <input type="text" name="kecamatan" value="{{ old('kecamatan', $profile->kecamatan ?? 'Kecamatan Contoh') }}" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 text-xs font-semibold text-slate-800 dark:text-slate-100 transition-all" />
                        </div>
                    </div>

                    <div class="pt-4 border-t border-slate-100 dark:border-slate-700 flex justify-end">
                        <button type="submit" class="btn-soft-primary flex items-center cursor-pointer">
                            <i class="fas fa-check-circle mr-2 text-xs"></i> Simpan Perubahan Profil
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Kolom Kanan: Ganti Password & Sesi Akun -->
        <div class="space-y-6 animate-fade-in-up" style="animation-delay: 0.1s;">
            <!-- Kanal Notifikasi Aktif (WhatsApp Fonnte, Email Gmail, Lonceng In-App) -->
            @php
                $user = auth()->user();
                $roleName = $user->role?->name ?? 'masyarakat';
                $isOfficer = in_array($roleName, ['staf', 'supervisor', 'kades', 'kepala_desa']);
                $waNumber = $user->phone_number ?: $user->profile?->phone_number;
            @endphp
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-soft border border-slate-100 dark:border-slate-700 overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700 flex items-center">
                    <div class="w-10 h-10 rounded-xl bg-gradient-primary text-white flex items-center justify-center mr-3 shadow-soft">
                        <i class="fas fa-bell text-base"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-slate-800 dark:text-slate-100">Kanal Notifikasi</h3>
                        <p class="text-[11px] text-slate-400">Saluran pemberitahuan yang aktif untuk akun Anda</p>
                    </div>
                </div>

                <div class="p-6 space-y-3 text-xs">
                    <!-- Email (Gmail SMTP) -->
                    <div class="flex items-start p-3.5 rounded-xl border border-slate-100 dark:border-slate-700">
                        <i class="fas fa-envelope text-slate-500 mt-0.5 mr-3"></i>
                        <div class="flex-1">
                            <div class="font-bold text-slate-800 dark:text-slate-100">Email</div>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400">
                                {{ $isOfficer ? 'Pemberitahuan tugas pengajuan sesuai peran Anda.' : 'Konfirmasi pengajuan dan setiap perubahan status pengajuan Anda.' }}
                            </p>
                            <p class="text-[11px] font-semibold text-slate-700 dark:text-slate-300 mt-1">{{ $user->email }}</p>
                        </div>
                    </div>

                    <!-- WhatsApp (Fonnte) -->
                    <div class="flex items-start p-3.5 rounded-xl border border-slate-100 dark:border-slate-700">
                        <i class="fab fa-whatsapp text-slate-500 mt-0.5 mr-3"></i>
                        <div class="flex-1">
                            <div class="font-bold text-slate-800 dark:text-slate-100">WhatsApp</div>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400">
                                {{ $isOfficer ? 'Pemberitahuan tugas petugas (pengajuan SKTM baru dikirim ke Staf).' : 'Saat ini WhatsApp digunakan untuk pemberitahuan internal petugas desa.' }}
                            </p>
                            <p class="text-[11px] font-semibold text-slate-700 dark:text-slate-300 mt-1">
                                {{ $waNumber ?: 'Nomor HP belum diisi — lengkapi pada formulir profil.' }}
                            </p>
                        </div>
                    </div>

                    <!-- Lonceng In-App -->
                    <div class="flex items-start p-3.5 rounded-xl border border-slate-100 dark:border-slate-700">
                        <i class="fas fa-bell text-slate-500 mt-0.5 mr-3"></i>
                        <div class="flex-1">
                            <div class="font-bold text-slate-800 dark:text-slate-100">Notifikasi Aplikasi</div>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400">Riwayat pemberitahuan tersimpan dan dapat dilihat melalui ikon lonceng di navbar.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ganti Password -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-soft border border-slate-100 dark:border-slate-700 overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700 flex items-center">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tl from-slate-700 to-slate-800 text-white flex items-center justify-center mr-3 shadow-soft">
                        <i class="fas fa-lock text-sm"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-slate-800 dark:text-slate-100">Keamanan Sandi</h3>
                        <p class="text-[11px] text-slate-400">Perbarui kata sandi akun Anda</p>
                    </div>
                </div>

                <form action="{{ route('profile.password') }}" method="POST" class="p-6 space-y-4">
                    @csrf
                    @method('PUT')

                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Kata Sandi Saat Ini *</label>
                        <input type="password" name="current_password" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 text-xs text-slate-800 dark:text-slate-100 transition-all" placeholder="••••••••" />
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Kata Sandi Baru *</label>
                        <input type="password" name="password" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 text-xs text-slate-800 dark:text-slate-100 transition-all" placeholder="Minimal 8 karakter" />
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Konfirmasi Sandi Baru *</label>
                        <input type="password" name="password_confirmation" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 text-xs text-slate-800 dark:text-slate-100 transition-all" placeholder="Ulangi kata sandi baru" />
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="btn-soft-dark w-full justify-center flex items-center cursor-pointer">
                            <i class="fas fa-key mr-2 text-xs"></i> Perbarui Kata Sandi
                        </button>
                    </div>
                </form>
            </div>

            <!-- Logout Session Card -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-soft border border-slate-100 dark:border-slate-700 p-6">
                <div class="flex items-center mb-3">
                    <div class="w-8 h-8 rounded-lg bg-rose-50 dark:bg-rose-950/40 text-rose-500 flex items-center justify-center mr-2.5 text-xs">
                        <i class="fas fa-shield-halved"></i>
                    </div>
                    <div>
                        <h4 class="text-xs font-bold text-slate-800 dark:text-slate-100 uppercase tracking-wider">Sesi Aplikasi</h4>
                        <p class="text-[10px] text-slate-400">Keluar secara aman dari akun ini</p>
                    </div>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-4 leading-relaxed font-medium">
                    Pastikan Anda selalu keluar dari akun setelah selesai menggunakan layanan pada komputer atau perangkat publik.
                </p>
                <form method="POST" action="{{ route('logout') }}" id="profile-logout-form">
                    @csrf
                    <button type="submit" onclick="localStorage.removeItem('auth_token');" class="w-full flex items-center justify-center px-4 py-2.5 border border-rose-200 dark:border-rose-800 text-rose-600 dark:text-rose-400 bg-rose-50/70 dark:bg-rose-950/30 hover:bg-rose-100/70 dark:hover:bg-rose-900/40 rounded-xl font-bold transition-all text-xs cursor-pointer shadow-sm">
                        <i class="fas fa-arrow-right-from-bracket mr-2 text-xs"></i> Keluar dari Sistem
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-layouts.app>
