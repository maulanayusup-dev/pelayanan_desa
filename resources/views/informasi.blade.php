<x-layouts.app :standalone="true">
    <x-slot:title>Informasi Surat Keterangan Tidak Mampu (SKTM)</x-slot>

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
                    <a href="/informasi-sktm" class="text-pink-600 dark:text-pink-400 font-bold">Info SKTM</a>
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
            <span class="badge-soft-purple text-xs mb-3">Panduan Resmi Pelayanan</span>
            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-slate-900 dark:text-white tracking-tight mb-4">
                Mengenal SKTM Desa
            </h1>
            <p class="text-sm sm:text-base text-slate-500 dark:text-slate-400 max-w-xl mx-auto">
                Surat Keterangan Tidak Mampu adalah bukti administratif resmi bagi keluarga prasejahtera untuk pemenuhan hak bantuan dan beasiswa.
            </p>
        </div>
    </section>

    <!-- Main Content -->
    <section class="pb-24 bg-[#F8F9FA]">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="card-soft p-6 sm:p-10 bg-white shadow-soft border border-slate-100 animate-fade-in-up" style="animation-delay: 0.1s;">
                
                <div class="mb-8 pb-8 border-b border-slate-100">
                    <h2 class="text-xl font-bold text-slate-800 mb-3 flex items-center">
                        <i class="fas fa-file-invoice text-pink-600 mr-2.5"></i> Definisi & Fungsi SKTM
                    </h2>
                    <p class="text-sm text-slate-600 leading-relaxed">
                        Surat Keterangan Tidak Mampu (SKTM) diterbitkan oleh Pemerintah Desa berdasarkan data kependudukan dan kondisi sosial ekonomi pemohon. Dokumen ini menjadi rujukan resmi bagi kementerian, perguruan tinggi, dinas kesehatan, maupun lembaga penyalur donasi sosial.
                    </p>
                </div>

                <!-- 4 Kegunaan Utama -->
                <div class="mb-10">
                    <h3 class="text-base font-bold text-slate-800 mb-5 flex items-center">
                        <i class="fas fa-bullseye text-pink-600 mr-2"></i> 4 Manfaat Utama Penggunaan SKTM
                    </h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="p-5 rounded-2xl bg-slate-50 border border-slate-100 hover:border-pink-200 transition-colors">
                            <div class="w-10 h-10 rounded-xl bg-gradient-info text-white flex items-center justify-center text-sm mb-3 shadow-soft-sm">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <h4 class="font-bold text-slate-800 text-sm mb-1">Beasiswa & Pendidikan</h4>
                            <p class="text-xs text-slate-500 leading-relaxed">
                                Pendaftaran KIP Kuliah, program Bidikmisi, atau permohonan keringanan uang kuliah tunggal (UKT) di perguruan tinggi negeri maupun swasta.
                            </p>
                        </div>

                        <div class="p-5 rounded-2xl bg-slate-50 border border-slate-100 hover:border-pink-200 transition-colors">
                            <div class="w-10 h-10 rounded-xl bg-gradient-success text-white flex items-center justify-center text-sm mb-3 shadow-soft-sm">
                                <i class="fas fa-heartbeat"></i>
                            </div>
                            <h4 class="font-bold text-slate-800 text-sm mb-1">Kesehatan & Rumah Sakit</h4>
                            <p class="text-xs text-slate-500 leading-relaxed">
                                Pengajuan jaminan kesehatan daerah (Jamkesda), keringanan biaya rawat inap di RSUD, atau percepatan aktivasi BPJS PBI.
                            </p>
                        </div>

                        <div class="p-5 rounded-2xl bg-slate-50 border border-slate-100 hover:border-pink-200 transition-colors">
                            <div class="w-10 h-10 rounded-xl bg-gradient-warning text-white flex items-center justify-center text-sm mb-3 shadow-soft-sm">
                                <i class="fas fa-hands-holding-child"></i>
                            </div>
                            <h4 class="font-bold text-slate-800 text-sm mb-1">Bantuan Sosial (Bansos)</h4>
                            <p class="text-xs text-slate-500 leading-relaxed">
                                Kelengkapan verifikasi DTKS (Data Terpadu Kesejahteraan Sosial), PKH (Program Keluarga Harapan), dan sembako/BPNT.
                            </p>
                        </div>

                        <div class="p-5 rounded-2xl bg-slate-50 border border-slate-100 hover:border-pink-200 transition-colors">
                            <div class="w-10 h-10 rounded-xl bg-gradient-dark text-white flex items-center justify-center text-sm mb-3 shadow-soft-sm">
                                <i class="fas fa-scale-balanced"></i>
                            </div>
                            <h4 class="font-bold text-slate-800 text-sm mb-1">Bantuan Hukum (Prodeo)</h4>
                            <p class="text-xs text-slate-500 leading-relaxed">
                                Permohonan pembebasan biaya perkara di pengadilan atau pendampingan lembaga bantuan hukum secara cuma-cuma.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Call to action inside card -->
                <div class="rounded-2xl bg-gradient-to-r from-slate-900 to-slate-800 p-6 sm:p-8 text-white flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div>
                        <h4 class="font-bold text-base text-white mb-1">Siapkan Dokumen Anda</h4>
                        <p class="text-xs text-slate-300">Lihat berkas persyaratan resmi sebelum mengajukan.</p>
                    </div>
                    <div class="flex items-center space-x-3 w-full sm:w-auto">
                        <a href="/persyaratan" class="btn-soft-white text-xs py-2.5 px-5 w-full sm:w-auto text-center">
                            Lihat Persyaratan
                        </a>
                        <a href="{{ route('login') }}" class="btn-soft-primary text-xs py-2.5 px-5 w-full sm:w-auto text-center">
                            Ajukan Sekarang
                        </a>
                    </div>
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
                <a href="/persyaratan" class="hover:text-pink-600 transition-colors">Persyaratan</a>
                <a href="/faq" class="hover:text-pink-600 transition-colors">FAQ</a>
            </div>
        </div>
    </footer>
</x-layouts.app>
