<x-layouts.app :standalone="true">
    <x-slot:title>Persyaratan Berkas Pengajuan SKTM</x-slot>

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
                    <a href="/persyaratan" class="text-pink-600 dark:text-pink-400 font-bold">Persyaratan</a>
                    <a href="/faq" class="hover:text-pink-600 dark:hover:text-pink-400 transition-colors">FAQ</a>
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
            <span class="badge-soft-info text-xs mb-3">Ketentuan Administratif</span>
            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-slate-900 dark:text-white tracking-tight mb-4">
                Persyaratan Berkas SKTM
            </h1>
            <p class="text-sm sm:text-base text-slate-500 dark:text-slate-400 max-w-xl mx-auto">
                Pastikan dokumen fisik Anda telah disiapkan dalam bentuk scan atau foto yang jelas dan terbaca sebelum mengisi formulir online.
            </p>
        </div>
    </section>

    <!-- Content Section -->
    <section class="pb-24 bg-[#F8F9FA]">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 animate-fade-in-up" style="animation-delay: 0.1s;">
                
                <!-- Syarat 1: KTP -->
                <div class="card-soft p-6 sm:p-8 hover:-translate-y-1 hover:shadow-soft-md transition-all duration-300">
                    <div class="flex items-start justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-primary text-white flex items-center justify-center text-lg shadow-soft">
                            <i class="fas fa-id-card"></i>
                        </div>
                        <span class="badge-soft-danger text-xxs uppercase">Wajib &bull; Mutlak</span>
                    </div>
                    <h3 class="text-base font-bold text-slate-800 mb-2">1. KTP Elektronik Asli</h3>
                    <p class="text-xs text-slate-500 leading-relaxed mb-4">
                        Foto atau scan KTP pemohon yang berdomisili di desa setempat. Pastikan NIK 16 digit, nama lengkap, dan tanda tangan terlihat jelas.
                    </p>
                    <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 text-xxs text-slate-500 space-y-1 font-medium">
                        <div>&bull; Format: JPG, JPEG, PNG, atau PDF</div>
                        <div>&bull; Ukuran maksimal: 2 MB</div>
                    </div>
                </div>

                <!-- Syarat 2: KK -->
                <div class="card-soft p-6 sm:p-8 hover:-translate-y-1 hover:shadow-soft-md transition-all duration-300">
                    <div class="flex items-start justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-info text-white flex items-center justify-center text-lg shadow-soft">
                            <i class="fas fa-users"></i>
                        </div>
                        <span class="badge-soft-danger text-xxs uppercase">Wajib &bull; Mutlak</span>
                    </div>
                    <h3 class="text-base font-bold text-slate-800 mb-2">2. Kartu Keluarga (KK) Asli</h3>
                    <p class="text-xs text-slate-500 leading-relaxed mb-4">
                        Foto atau scan lembar Kartu Keluarga asli. Nama pemohon harus tercantum dalam daftar anggota keluarga pada KK yang diunggah.
                    </p>
                    <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 text-xxs text-slate-500 space-y-1 font-medium">
                        <div>&bull; Format: JPG, JPEG, PNG, atau PDF</div>
                        <div>&bull; Ukuran maksimal: 2 MB</div>
                    </div>
                </div>

                <!-- Syarat 3: Pengantar RT/RW -->
                <div class="card-soft p-6 sm:p-8 hover:-translate-y-1 hover:shadow-soft-md transition-all duration-300">
                    <div class="flex items-start justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-warning text-white flex items-center justify-center text-lg shadow-soft">
                            <i class="fas fa-envelope-open-text"></i>
                        </div>
                        <span class="badge-soft-info text-xxs uppercase">Pendukung &bull; Opsional</span>
                    </div>
                    <h3 class="text-base font-bold text-slate-800 mb-2">3. Surat Pengantar RT / RW</h3>
                    <p class="text-xs text-slate-500 leading-relaxed mb-4">
                        Surat pengantar keterangan tidak mampu yang ditandatangani oleh Ketua RT dan RW setempat sebagai penguat verifikasi faktual lapangan.
                    </p>
                    <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 text-xxs text-slate-500 space-y-1 font-medium">
                        <div>&bull; Opsional (mempercepat proses verifikasi staf)</div>
                        <div>&bull; Ukuran maksimal: 2 MB</div>
                    </div>
                </div>

                <!-- Syarat 4: Dokumen Tambahan -->
                <div class="card-soft p-6 sm:p-8 hover:-translate-y-1 hover:shadow-soft-md transition-all duration-300">
                    <div class="flex items-start justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-success text-white flex items-center justify-center text-lg shadow-soft">
                            <i class="fas fa-folder-plus"></i>
                        </div>
                        <span class="badge-soft-info text-xxs uppercase">Pendukung &bull; Opsional</span>
                    </div>
                    <h3 class="text-base font-bold text-slate-800 mb-2">4. Bukti Pendukung Lainnya</h3>
                    <p class="text-xs text-slate-500 leading-relaxed mb-4">
                        Bisa berupa foto kondisi rumah tinggal, slip gaji / surat keterangan penghasilan dari tempat bekerja, atau kartu KIS/PBI yang dimiliki.
                    </p>
                    <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 text-xxs text-slate-500 space-y-1 font-medium">
                        <div>&bull; Sangat dianjurkan untuk verifikasi bansos</div>
                        <div>&bull; Ukuran maksimal: 2 MB</div>
                    </div>
                </div>

            </div>

            <!-- Action Card -->
            <div class="mt-10 card-soft p-8 bg-white border border-slate-100 text-center shadow-soft">
                <h4 class="text-lg font-bold text-slate-800 mb-2">Sudah Mempersiapkan Berkas?</h4>
                <p class="text-xs text-slate-500 max-w-md mx-auto mb-6">
                    Lanjutkan ke formulir pengajuan online. Asisten AI kami siap memandu jika Anda menemui kendala dalam mengisi kolom data diri.
                </p>
                <div class="flex items-center justify-center space-x-3">
                    <a href="{{ route('register') }}" class="btn-soft-primary text-xs py-2.5 px-6">
                        Buat Pengajuan Sekarang <i class="fas fa-arrow-right ml-1.5"></i>
                    </a>
                </div>
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
                <a href="/faq" class="hover:text-pink-600 transition-colors">FAQ</a>
            </div>
        </div>
    </footer>
</x-layouts.app>
