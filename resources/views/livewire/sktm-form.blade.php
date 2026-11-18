<div class="animate-fade-in-up">
    @if (session()->has('error'))
        <div class="p-4 mb-6 text-xs text-rose-700 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 rounded-2xl shadow-soft-sm flex items-center" role="alert">
            <i class="fas fa-exclamation-triangle mr-2.5 text-rose-500 text-base shrink-0"></i>
            <div>
                <span class="font-bold">Error:</span> {{ session('error') }}
            </div>
        </div>
    @endif

    @if ($errors->any())
        <div class="p-4 mb-6 text-xs text-rose-700 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 rounded-2xl shadow-soft-sm flex items-start animate-fade-in-up" role="alert">
            <i class="fas fa-exclamation-circle mr-2.5 text-rose-500 text-base mt-0.5 shrink-0"></i>
            <div>
                <span class="font-bold">Formulir belum lengkap atau belum valid:</span>
                <p class="text-rose-600 dark:text-rose-300 mt-0.5">Silakan periksa kolom formulir yang ditandai pesan merah di bawah.</p>
            </div>
        </div>
    @endif

    <!-- Soft UI Stepper -->
    <div class="mb-8 relative max-w-2xl mx-auto">
        <div class="absolute top-1/2 left-0 w-full h-1 bg-slate-200 dark:bg-slate-700 -z-10 rounded-full transform -translate-y-1/2"></div>
        <div class="absolute top-1/2 left-0 h-1 bg-gradient-primary -z-10 rounded-full transform -translate-y-1/2 transition-all duration-500" style="width: {{ ($currentStep - 1) * 50 }}%"></div>
        
        <div class="flex items-center justify-between">
            <!-- Step 1 -->
            <div class="text-center group cursor-pointer" wire:click="$set('currentStep', 1)">
                <div class="w-10 h-10 mx-auto rounded-xl flex items-center justify-center font-bold text-sm transition-all duration-300 {{ $currentStep >= 1 ? 'bg-gradient-primary text-white shadow-soft scale-110' : 'bg-white dark:bg-slate-800 text-slate-400 border border-slate-200 dark:border-slate-700 shadow-soft-sm' }}">
                    @if($currentStep > 1) <i class="fas fa-check text-xs"></i> @else 1 @endif
                </div>
                <p class="mt-2 text-xs font-bold {{ $currentStep >= 1 ? 'text-pink-600 dark:text-pink-400' : 'text-slate-400 dark:text-slate-500' }}">Data Diri</p>
            </div>
            
            <!-- Step 2 -->
            <div class="text-center group {{ $currentStep > 2 ? 'cursor-pointer' : '' }}" @if($currentStep > 2) wire:click="$set('currentStep', 2)" @endif>
                <div class="w-10 h-10 mx-auto rounded-xl flex items-center justify-center font-bold text-sm transition-all duration-300 {{ $currentStep >= 2 ? 'bg-gradient-primary text-white shadow-soft scale-110' : 'bg-white dark:bg-slate-800 text-slate-400 border border-slate-200 dark:border-slate-700 shadow-soft-sm' }}">
                    @if($currentStep > 2) <i class="fas fa-check text-xs"></i> @else 2 @endif
                </div>
                <p class="mt-2 text-xs font-bold {{ $currentStep >= 2 ? 'text-pink-600 dark:text-pink-400' : 'text-slate-400 dark:text-slate-500' }}">Keperluan</p>
            </div>
            
            <!-- Step 3 -->
            <div class="text-center group">
                <div class="w-10 h-10 mx-auto rounded-xl flex items-center justify-center font-bold text-sm transition-all duration-300 {{ $currentStep == 3 ? 'bg-gradient-primary text-white shadow-soft scale-110' : 'bg-white dark:bg-slate-800 text-slate-400 border border-slate-200 dark:border-slate-700 shadow-soft-sm' }}">
                    3
                </div>
                <p class="mt-2 text-xs font-bold {{ $currentStep == 3 ? 'text-pink-600 dark:text-pink-400' : 'text-slate-400 dark:text-slate-500' }}">Dokumen</p>
            </div>
        </div>
    </div>

    <form wire:submit="submit" class="relative">
        
        <!-- Step 1: Data Diri -->
        @if ($currentStep == 1)
            <div class="card-soft overflow-hidden animate-fade-in-up">
                <div class="p-6 border-b border-slate-100 dark:border-slate-700 flex items-center bg-white dark:bg-slate-800 justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-xl bg-pink-50 dark:bg-pink-950/40 text-pink-600 dark:text-pink-400 flex items-center justify-center text-sm font-bold shadow-soft-sm">
                            <i class="fas fa-id-card"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-slate-800 dark:text-slate-100">1. Identitas Pemohon</h3>
                            <p class="text-xs text-slate-400 dark:text-slate-400">Pastikan data sesuai dengan KTP elektronik dan Kartu Keluarga Anda.</p>
                        </div>
                    </div>
                    <span class="badge-soft-info text-xxs hidden sm:inline-flex">Langkah 1 dari 3</span>
                </div>
                
                <div class="p-6 sm:p-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">NIK (16 Digit) <span class="text-rose-500">*</span></label>
                            <input type="text" wire:model.live="nik" maxlength="16" class="block w-full px-3.5 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 text-slate-800 dark:text-slate-100 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500 @error('nik') border-rose-400 @enderror" placeholder="16 digit nomor NIK">
                            @error('nik') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Nomor KK (16 Digit) <span class="text-rose-500">*</span></label>
                            <input type="text" wire:model.live="no_kk" maxlength="16" class="block w-full px-3.5 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 text-slate-800 dark:text-slate-100 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500 @error('no_kk') border-rose-400 @enderror" placeholder="16 digit nomor KK">
                            @error('no_kk') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Nama Lengkap (Sesuai KTP) <span class="text-rose-500">*</span></label>
                            <input type="text" wire:model.live="name" class="block w-full px-3.5 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 text-slate-800 dark:text-slate-100 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500 @error('name') border-rose-400 @enderror" placeholder="Nama lengkap">
                            @error('name') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Tempat Lahir <span class="text-rose-500">*</span></label>
                            <input type="text" wire:model.live="place_of_birth" class="block w-full px-3.5 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 text-slate-800 dark:text-slate-100 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500 @error('place_of_birth') border-rose-400 @enderror" placeholder="Kota / Kabupaten Lahir">
                            @error('place_of_birth') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Tanggal Lahir <span class="text-rose-500">*</span></label>
                            <input type="date" wire:model.live="date_of_birth" class="block w-full px-3.5 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 text-slate-800 dark:text-slate-100 shadow-soft-sm outline-none transition-all @error('date_of_birth') border-rose-400 @enderror">
                            @error('date_of_birth') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Jenis Kelamin <span class="text-rose-500">*</span></label>
                            <select wire:model.live="gender" class="block w-full px-3.5 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 text-slate-800 dark:text-slate-100 shadow-soft-sm outline-none transition-all @error('gender') border-rose-400 @enderror">
                                <option value="">-- Pilih Jenis Kelamin --</option>
                                <option value="L">Laki-Laki</option>
                                <option value="P">Perempuan</option>
                            </select>
                            @error('gender') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                        </div>
                        
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Pekerjaan <span class="text-rose-500">*</span></label>
                                <button type="button" wire:click="askAiExplain('occupation')" class="text-xxs font-bold text-purple-700 dark:text-purple-300 bg-purple-50 dark:bg-purple-950/50 hover:bg-purple-100 dark:hover:bg-purple-900/50 px-2 py-0.5 rounded-lg transition-colors flex items-center cursor-pointer">
                                    <i class="fas fa-robot mr-1 text-pink-500"></i> Tanya AI
                                </button>
                            </div>
                            <input type="text" wire:model.live="occupation" class="block w-full px-3.5 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 text-slate-800 dark:text-slate-100 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500 @error('occupation') border-rose-400 @enderror" placeholder="Contoh: Buruh Harian Lepas">
                            @error('occupation') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                            
                            <div wire:loading wire:target="askAiExplain('occupation')" class="mt-2 text-xxs font-bold text-purple-600 dark:text-purple-400 flex items-center bg-purple-50 dark:bg-purple-950/40 px-2.5 py-1.5 rounded-lg">
                                <i class="fas fa-circle-notch fa-spin mr-1.5"></i> AI sedang menyiapkan penjelasan...
                            </div>
                            @if($activeAiField === 'occupation' && $aiExplanation)
                            <div class="mt-2 p-3 bg-purple-50/70 dark:bg-purple-950/40 border border-purple-200 dark:border-purple-800 rounded-xl text-xs text-purple-900 dark:text-purple-200 shadow-soft-sm relative animate-fade-in-up" wire:loading.remove wire:target="askAiExplain">
                                <div class="flex items-start">
                                    <i class="fas fa-lightbulb text-purple-600 dark:text-purple-400 mr-2 mt-0.5 shrink-0"></i>
                                    <div class="text-xs leading-relaxed font-medium">{{ $aiExplanation }}</div>
                                </div>
                                <button type="button" wire:click="$set('activeAiField', '')" class="absolute top-2 right-2 text-purple-400 hover:text-purple-700 dark:hover:text-purple-200 cursor-pointer"><i class="fas fa-times text-xs"></i></button>
                            </div>
                            @endif
                        </div>

                        <div class="md:col-span-2">
                            <div class="flex items-center justify-between mb-1.5">
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Penghasilan Per Bulan (Rp) <span class="text-rose-500">*</span></label>
                                <button type="button" wire:click="askAiExplain('penghasilan')" class="text-xxs font-bold text-purple-700 dark:text-purple-300 bg-purple-50 dark:bg-purple-950/50 hover:bg-purple-100 dark:hover:bg-purple-900/50 px-2 py-0.5 rounded-lg transition-colors flex items-center cursor-pointer">
                                    <i class="fas fa-robot mr-1 text-pink-500"></i> Tanya AI
                                </button>
                            </div>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400 font-bold text-xs">Rp</span>
                                <input type="number" wire:model.live="penghasilan" class="block w-full pl-10 pr-4 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 text-slate-800 dark:text-slate-100 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500 @error('penghasilan') border-rose-400 @enderror" placeholder="Contoh: 1000000">
                            </div>
                            @error('penghasilan') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                            
                            <div wire:loading wire:target="askAiExplain('penghasilan')" class="mt-2 text-xxs font-bold text-purple-600 dark:text-purple-400 flex items-center bg-purple-50 dark:bg-purple-950/40 px-2.5 py-1.5 rounded-lg">
                                <i class="fas fa-circle-notch fa-spin mr-1.5"></i> AI sedang menyiapkan penjelasan...
                            </div>
                            @if($activeAiField === 'penghasilan' && $aiExplanation)
                            <div class="mt-2 p-3 bg-purple-50/70 dark:bg-purple-950/40 border border-purple-200 dark:border-purple-800 rounded-xl text-xs text-purple-900 dark:text-purple-200 shadow-soft-sm relative animate-fade-in-up" wire:loading.remove wire:target="askAiExplain">
                                <div class="flex items-start">
                                    <i class="fas fa-lightbulb text-purple-600 dark:text-purple-400 mr-2 mt-0.5 shrink-0"></i>
                                    <div class="text-xs leading-relaxed font-medium">{{ $aiExplanation }}</div>
                                </div>
                                <button type="button" wire:click="$set('activeAiField', '')" class="absolute top-2 right-2 text-purple-400 hover:text-purple-700 dark:hover:text-purple-200 cursor-pointer"><i class="fas fa-times text-xs"></i></button>
                            </div>
                            @endif
                        </div>

                        <div class="md:col-span-2 pt-2 border-t border-slate-100 dark:border-slate-700">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 flex items-center">
                                <i class="fas fa-map-marker-alt text-pink-600 dark:text-pink-400 mr-2"></i> Alamat Domisili KTP
                            </h4>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Jalan / Dusun / No. Rumah <span class="text-rose-500">*</span></label>
                            <input type="text" wire:model.live="address" class="block w-full px-3.5 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 text-slate-800 dark:text-slate-100 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500 @error('address') border-rose-400 @enderror" placeholder="Nama Jalan, Gang, Blok, No Rumah">
                            @error('address') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">RT <span class="text-rose-500">*</span></label>
                            <input type="number" wire:model.live="rt" class="block w-full px-3.5 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 text-slate-800 dark:text-slate-100 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500 @error('rt') border-rose-400 @enderror" placeholder="Contoh: 001">
                            @error('rt') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">RW <span class="text-rose-500">*</span></label>
                            <input type="number" wire:model.live="rw" class="block w-full px-3.5 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 text-slate-800 dark:text-slate-100 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500 @error('rw') border-rose-400 @enderror" placeholder="Contoh: 002">
                            @error('rw') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Desa / Kelurahan <span class="text-rose-500">*</span></label>
                            <input type="text" wire:model.live="desa" class="block w-full px-3.5 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 text-slate-800 dark:text-slate-100 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500 @error('desa') border-rose-400 @enderror" placeholder="Nama Desa">
                            @error('desa') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Kecamatan <span class="text-rose-500">*</span></label>
                            <input type="text" wire:model.live="kecamatan" class="block w-full px-3.5 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 text-slate-800 dark:text-slate-100 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500 @error('kecamatan') border-rose-400 @enderror" placeholder="Nama Kecamatan">
                            @error('kecamatan') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Kabupaten / Kota <span class="text-rose-500">*</span></label>
                            <input type="text" wire:model.live="kabupaten" class="block w-full px-3.5 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 text-slate-800 dark:text-slate-100 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500 @error('kabupaten') border-rose-400 @enderror" placeholder="Nama Kabupaten / Kota">
                            @error('kabupaten') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="mt-8 border-t border-slate-100 dark:border-slate-700 pt-6 flex items-center justify-between">
                        <div>
                            @if ($errors->any())
                                <p class="text-xs text-rose-500 font-bold flex items-center">
                                    <i class="fas fa-exclamation-triangle mr-1.5"></i> Periksa kembali kolom (*) yang bertanda merah.
                                </p>
                            @endif
                        </div>
                        <button type="button" wire:click="nextStep" wire:loading.attr="disabled" wire:target="nextStep" class="btn-soft-primary text-xs py-2.5 px-6 shadow-soft cursor-pointer">
                            <span wire:loading.remove wire:target="nextStep">Selanjutnya <i class="fas fa-arrow-right ml-1.5"></i></span>
                            <span wire:loading wire:target="nextStep"><i class="fas fa-circle-notch fa-spin mr-1.5"></i> Memvalidasi...</span>
                        </button>
                    </div>
                </div>
            </div>
        @endif

        <!-- Step 2: Keperluan -->
        @if ($currentStep == 2)
            <div class="card-soft overflow-hidden animate-fade-in-up">
                <div class="p-6 border-b border-slate-100 dark:border-slate-700 flex items-center bg-white dark:bg-slate-800 justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 flex items-center justify-center text-sm font-bold shadow-soft-sm">
                            <i class="fas fa-bullseye"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-slate-800 dark:text-slate-100">2. Maksud & Tujuan SKTM</h3>
                            <p class="text-xs text-slate-400 dark:text-slate-400">Pilih keperluan surat keterangan tidak mampu yang diajukan.</p>
                        </div>
                    </div>
                    <span class="badge-soft-info text-xxs hidden sm:inline-flex">Langkah 2 dari 3</span>
                </div>
                
                <div class="p-6 sm:p-8">
                    <div class="mb-5">
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Tujuan Pembuatan SKTM <span class="text-rose-500">*</span></label>
                            <button type="button" wire:click="askAiExplain('purpose')" class="text-xxs font-bold text-purple-700 dark:text-purple-300 bg-purple-50 dark:bg-purple-950/50 hover:bg-purple-100 dark:hover:bg-purple-900/50 px-2 py-0.5 rounded-lg transition-colors flex items-center cursor-pointer">
                                <i class="fas fa-robot mr-1 text-pink-500"></i> Tanya AI
                            </button>
                        </div>
                        
                        <div class="relative">
                            <select wire:model.live="purpose" class="block w-full px-3.5 py-3 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 shadow-soft-sm outline-none transition-all text-slate-800 dark:text-slate-100 @error('purpose') border-rose-400 @enderror">
                                <option value="">-- Pilih Tujuan Pembuatan --</option>
                                <option value="Beasiswa Pendidikan (KIP/Bidikmisi)">🎓 Beasiswa Pendidikan (KIP Kuliah / Bidikmisi / Keringanan SPP)</option>
                                <option value="Keringanan Biaya Rumah Sakit">🏥 Keringanan Biaya Pengobatan Rumah Sakit (BPJS PBI / RSUD)</option>
                                <option value="Bantuan Sosial (PKH/BPNT)">📦 Pendaftaran Program Bantuan Sosial (PKH / BPNT / DTKS)</option>
                                <option value="Lainnya">📝 Keperluan Administratif Lainnya</option>
                            </select>
                        </div>
                        @error('purpose') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                        
                        <div wire:loading wire:target="askAiExplain('purpose')" class="mt-2 text-xxs font-bold text-purple-600 dark:text-purple-400 flex items-center bg-purple-50 dark:bg-purple-950/40 px-2.5 py-1.5 rounded-lg">
                            <i class="fas fa-circle-notch fa-spin mr-1.5"></i> AI sedang menyiapkan penjelasan...
                        </div>
                        @if($activeAiField === 'purpose' && $aiExplanation)
                        <div class="mt-2 p-3 bg-purple-50/70 dark:bg-purple-950/40 border border-purple-200 dark:border-purple-800 rounded-xl text-xs text-purple-900 dark:text-purple-200 shadow-soft-sm relative animate-fade-in-up" wire:loading.remove wire:target="askAiExplain">
                            <div class="flex items-start">
                                <i class="fas fa-lightbulb text-purple-600 dark:text-purple-400 mr-2 mt-0.5 shrink-0"></i>
                                <div class="text-xs leading-relaxed font-medium">{{ $aiExplanation }}</div>
                            </div>
                            <button type="button" wire:click="$set('activeAiField', '')" class="absolute top-2 right-2 text-purple-400 hover:text-purple-700 dark:hover:text-purple-200 cursor-pointer"><i class="fas fa-times text-xs"></i></button>
                        </div>
                        @endif
                    </div>

                    <div class="mb-4">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Keterangan Tambahan <span class="text-slate-400 lowercase">(opsional)</span></label>
                        <textarea wire:model.live="applicant_notes" class="block w-full px-3.5 py-2.5 text-sm bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-100 dark:focus:ring-pink-900/30 shadow-soft-sm outline-none transition-all placeholder:text-slate-400 dark:placeholder:text-slate-500 text-slate-800 dark:text-slate-100 @error('applicant_notes') border-rose-400 @enderror" rows="3" placeholder="Tuliskan keterangan detail keperluan Anda..."></textarea>
                        @error('applicant_notes') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                    </div>

                    <div class="mt-8 border-t border-slate-100 dark:border-slate-700 pt-6 flex justify-between items-center">
                        <button type="button" wire:click="prevStep" class="btn-soft-white text-xs py-2 px-5 cursor-pointer">
                            <i class="fas fa-arrow-left mr-1.5"></i> Kembali
                        </button>
                        <button type="button" wire:click="nextStep" wire:loading.attr="disabled" wire:target="nextStep" class="btn-soft-primary text-xs py-2.5 px-6 shadow-soft cursor-pointer">
                            <span wire:loading.remove wire:target="nextStep">Selanjutnya <i class="fas fa-arrow-right ml-1.5"></i></span>
                            <span wire:loading wire:target="nextStep"><i class="fas fa-circle-notch fa-spin mr-1.5"></i> Memvalidasi...</span>
                        </button>
                    </div>
                </div>
            </div>
        @endif

        <!-- Step 3: Upload Dokumen -->
        @if ($currentStep == 3)
            <div class="card-soft overflow-hidden animate-fade-in-up">
                <div class="p-6 border-b border-slate-100 dark:border-slate-700 flex items-center bg-white dark:bg-slate-800 justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm font-bold shadow-soft-sm">
                            <i class="fas fa-upload"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-slate-800 dark:text-slate-100">3. Berkas Persyaratan & Konfirmasi</h3>
                            <p class="text-xs text-slate-400 dark:text-slate-400">Unggah foto atau scan dokumen resmi yang jelas.</p>
                        </div>
                    </div>
                    <span class="badge-soft-success text-xxs hidden sm:inline-flex">Langkah Terakhir</span>
                </div>
                
                <div class="p-6 sm:p-8">
                    <!-- Ringkasan Pemohon -->
                    <div class="mb-6 p-4 rounded-xl bg-slate-50 dark:bg-slate-900/60 border border-slate-200/70 dark:border-slate-700">
                        <div class="flex items-center justify-between mb-2.5 pb-2 border-b border-slate-200 dark:border-slate-700">
                            <h4 class="text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider flex items-center">
                                <i class="fas fa-check-double text-pink-600 dark:text-pink-400 mr-2"></i> Ringkasan Permohonan
                            </h4>
                            <button type="button" wire:click="$set('currentStep', 1)" class="text-xxs font-bold text-pink-600 dark:text-pink-400 hover:text-pink-700 dark:hover:text-pink-300 underline cursor-pointer">
                                Ubah Data Diri
                            </button>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-slate-600 dark:text-slate-300">
                            <div><span class="text-slate-400 dark:text-slate-500 font-semibold">Nama:</span> <strong class="text-slate-800 dark:text-slate-100">{{ $name }}</strong></div>
                            <div><span class="text-slate-400 dark:text-slate-500 font-semibold">NIK:</span> <strong class="text-slate-800 dark:text-slate-100">{{ $nik }}</strong></div>
                            <div><span class="text-slate-400 dark:text-slate-500 font-semibold">No. KK:</span> <strong class="text-slate-800 dark:text-slate-100">{{ $no_kk }}</strong></div>
                            <div><span class="text-slate-400 dark:text-slate-500 font-semibold">Keperluan:</span> <strong class="text-pink-600 dark:text-pink-400">{{ $purpose }}</strong></div>
                            <div class="sm:col-span-2"><span class="text-slate-400 dark:text-slate-500 font-semibold">Alamat:</span> {{ $address }}, RT {{ $rt }}/RW {{ $rw }}, Desa {{ $desa }}, {{ $kabupaten }}</div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <!-- Upload 1: KTP -->
                        <div class="relative p-5 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-2xl bg-white dark:bg-slate-800/80 hover:bg-slate-50/60 dark:hover:bg-slate-750 transition-colors text-center group">
                            <div class="w-10 h-10 mx-auto bg-pink-50 dark:bg-pink-950/40 text-pink-600 dark:text-pink-400 rounded-xl flex items-center justify-center mb-2 shadow-soft-sm">
                                <i class="fas fa-id-card text-base"></i>
                            </div>
                            <label class="block text-xs font-bold text-slate-800 dark:text-slate-100 mb-0.5">1. KTP Asli <span class="text-rose-500">*</span></label>
                            <p class="text-xxs text-slate-400 mb-3">Maksimal 2MB (JPG/PNG/PDF)</p>
                            
                            <input type="file" wire:model="ktp" id="ktp-upload" class="hidden" accept=".jpg,.jpeg,.png,.pdf">
                            <label for="ktp-upload" class="btn-soft-white text-xs py-1.5 px-4 cursor-pointer inline-flex">
                                <i class="fas fa-folder-open mr-1.5 text-slate-400"></i> Pilih File
                            </label>
                            
                            <div wire:loading wire:target="ktp" class="absolute inset-0 bg-white/90 dark:bg-slate-900/90 backdrop-blur-sm flex flex-col items-center justify-center rounded-2xl z-20">
                                <i class="fas fa-circle-notch fa-spin text-pink-600 text-xl mb-1.5"></i>
                                <span class="text-xxs font-bold text-pink-700 dark:text-pink-400">Mengunggah...</span>
                            </div>
                            
                            @if ($ktp)
                                <div class="absolute inset-0 bg-emerald-50/95 dark:bg-emerald-950/90 border-2 border-emerald-400 flex flex-col items-center justify-center rounded-2xl z-20 p-3">
                                    <i class="fas fa-check-circle text-emerald-600 dark:text-emerald-400 text-2xl mb-1"></i>
                                    <span class="text-xs font-bold text-emerald-800 dark:text-emerald-200 truncate max-w-[200px]">{{ $ktp->getClientOriginalName() }}</span>
                                    <button type="button" wire:click="$set('ktp', null)" class="mt-1.5 text-xxs font-bold text-rose-500 hover:text-rose-700 underline cursor-pointer">Ganti File</button>
                                </div>
                            @endif
                            @error('ktp') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                        </div>

                        <!-- Upload 2: KK -->
                        <div class="relative p-5 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-2xl bg-white dark:bg-slate-800/80 hover:bg-slate-50/60 dark:hover:bg-slate-750 transition-colors text-center group">
                            <div class="w-10 h-10 mx-auto bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 rounded-xl flex items-center justify-center mb-2 shadow-soft-sm">
                                <i class="fas fa-users text-base"></i>
                            </div>
                            <label class="block text-xs font-bold text-slate-800 dark:text-slate-100 mb-0.5">2. Kartu Keluarga (KK) <span class="text-rose-500">*</span></label>
                            <p class="text-xxs text-slate-400 mb-3">Maksimal 2MB (JPG/PNG/PDF)</p>
                            
                            <input type="file" wire:model="kk" id="kk-upload" class="hidden" accept=".jpg,.jpeg,.png,.pdf">
                            <label for="kk-upload" class="btn-soft-white text-xs py-1.5 px-4 cursor-pointer inline-flex">
                                <i class="fas fa-folder-open mr-1.5 text-slate-400"></i> Pilih File
                            </label>
                            
                            <div wire:loading wire:target="kk" class="absolute inset-0 bg-white/90 dark:bg-slate-900/90 backdrop-blur-sm flex flex-col items-center justify-center rounded-2xl z-20">
                                <i class="fas fa-circle-notch fa-spin text-blue-600 text-xl mb-1.5"></i>
                                <span class="text-xxs font-bold text-blue-700 dark:text-blue-400">Mengunggah...</span>
                            </div>
                            
                            @if ($kk)
                                <div class="absolute inset-0 bg-emerald-50/95 dark:bg-emerald-950/90 border-2 border-emerald-400 flex flex-col items-center justify-center rounded-2xl z-20 p-3">
                                    <i class="fas fa-check-circle text-emerald-600 dark:text-emerald-400 text-2xl mb-1"></i>
                                    <span class="text-xs font-bold text-emerald-800 dark:text-emerald-200 truncate max-w-[200px]">{{ $kk->getClientOriginalName() }}</span>
                                    <button type="button" wire:click="$set('kk', null)" class="mt-1.5 text-xxs font-bold text-rose-500 hover:text-rose-700 underline cursor-pointer">Ganti File</button>
                                </div>
                            @endif
                            @error('kk') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                        </div>

                        <!-- Upload 3: Pendukung -->
                        <div class="md:col-span-2 relative p-5 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-2xl bg-white dark:bg-slate-800/80 hover:bg-slate-50/60 dark:hover:bg-slate-750 transition-colors text-center group">
                            <div class="w-10 h-10 mx-auto bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 rounded-xl flex items-center justify-center mb-2 shadow-soft-sm">
                                <i class="fas fa-paperclip text-base"></i>
                            </div>
                            <label class="block text-xs font-bold text-slate-800 dark:text-slate-100 mb-0.5">3. Dokumen Pendukung <span class="text-slate-400 lowercase">(opsional)</span></label>
                            <p class="text-xxs text-slate-400 mb-3">Surat pengantar RT/RW, foto rumah prasejahtera, slip gaji (Maks. 2MB)</p>
                            
                            <input type="file" wire:model="pendukung" id="pendukung-upload" class="hidden" accept=".jpg,.jpeg,.png,.pdf">
                            <label for="pendukung-upload" class="btn-soft-white text-xs py-1.5 px-4 cursor-pointer inline-flex">
                                <i class="fas fa-folder-open mr-1.5 text-slate-400"></i> Pilih File
                            </label>
                            
                            <div wire:loading wire:target="pendukung" class="absolute inset-0 bg-white/90 dark:bg-slate-900/90 backdrop-blur-sm flex flex-col items-center justify-center rounded-2xl z-20">
                                <i class="fas fa-circle-notch fa-spin text-slate-600 text-xl mb-1.5"></i>
                                <span class="text-xxs font-bold text-slate-700 dark:text-slate-300">Mengunggah...</span>
                            </div>
                            
                            @if ($pendukung)
                                <div class="absolute inset-0 bg-emerald-50/95 dark:bg-emerald-950/90 border-2 border-emerald-400 flex flex-col items-center justify-center rounded-2xl z-20 p-3">
                                    <i class="fas fa-check-circle text-emerald-600 dark:text-emerald-400 text-2xl mb-1"></i>
                                    <span class="text-xs font-bold text-emerald-800 dark:text-emerald-200 truncate max-w-[200px]">{{ $pendukung->getClientOriginalName() }}</span>
                                    <button type="button" wire:click="$set('pendukung', null)" class="mt-1.5 text-xxs font-bold text-rose-500 hover:text-rose-700 underline cursor-pointer">Ganti File</button>
                                </div>
                            @endif
                            @error('pendukung') <span class="text-xxs text-rose-500 mt-1 block font-bold"><i class="fas fa-exclamation-circle mr-1"></i> {{ $message }}</span> @enderror
                        </div>
                    </div>

                    <!-- Deklarasi Pernyataan -->
                    <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 text-xs text-amber-900 dark:text-amber-200 flex items-start space-x-3 mb-6">
                        <i class="fas fa-shield-halved text-amber-600 dark:text-amber-400 text-base mt-0.5 shrink-0"></i>
                        <p class="leading-relaxed">
                            <strong>Pernyataan Kejujuran:</strong> Saya menyatakan bahwa seluruh data diri dan berkas yang saya unggah adalah sah dan benar. Jika di kemudian hari ditemukan pemalsuan, saya bersedia bertanggung jawab sesuai hukum yang berlaku.
                        </p>
                    </div>

                    <div class="mt-8 border-t border-slate-100 dark:border-slate-700 pt-6 flex flex-col sm:flex-row justify-between items-center gap-4">
                        <div>
                            @if ($errors->any())
                                <p class="text-xs text-rose-500 font-bold flex items-center">
                                    <i class="fas fa-exclamation-triangle mr-1.5"></i> Periksa kembali berkas persyaratan (*) yang bertanda merah.
                                </p>
                            @endif
                        </div>
                        <div class="flex items-center space-x-3 w-full sm:w-auto justify-between sm:justify-end">
                            <button type="button" wire:click="prevStep" class="btn-soft-white text-xs py-2 px-5 cursor-pointer">
                                <i class="fas fa-arrow-left mr-1.5"></i> Kembali
                            </button>
                            <button type="submit" id="btn-submit-application" data-cy="submit-application" aria-label="Kirim Pengajuan SKTM" class="btn-soft-primary text-xs py-2.5 px-7 shadow-soft cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed" wire:loading.attr="disabled" wire:target="submit, ktp, kk, pendukung">
                                <span wire:loading.remove wire:target="submit, ktp, kk, pendukung"><i class="fas fa-paper-plane mr-1.5"></i> Kirim Pengajuan SKTM</span>
                                <span wire:loading wire:target="submit"><i class="fas fa-circle-notch fa-spin mr-1.5"></i> Memproses Pengajuan...</span>
                                <span wire:loading wire:target="ktp, kk, pendukung"><i class="fas fa-upload fa-spin mr-1.5"></i> Mengunggah Berkas...</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </form>
</div>
