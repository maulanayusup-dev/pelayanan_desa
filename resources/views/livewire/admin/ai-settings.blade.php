<div class="space-y-6">
    <!-- Header Banner -->
    <div class="relative overflow-hidden bg-gradient-dark text-white rounded-2xl shadow-soft p-6 sm:p-8 animate-fade-in-up">
        <div class="absolute -right-12 -bottom-12 w-64 h-64 bg-pink-600/20 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div>
                <span class="badge-soft-purple text-xxs font-bold mb-2 inline-flex items-center text-white bg-white/10 border-white/20">
                    <i class="fas fa-sliders mr-1.5 text-pink-300"></i> Kontrol Pusat AI Global
                </span>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight mb-1">
                    Pengaturan Konfigurasi AI Desa
                </h1>
                <p class="text-xs sm:text-sm text-slate-300 max-w-2xl">
                    Kelola provider, endpoint Base URL, model, API Key terenkripsi, serta status layanan AI eksternal. Satu konfigurasi aktif berlaku seragam untuk Masyarakat, Staf, Kepala Desa, dan Supervisor.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if($configSource === 'database')
                    <span class="px-3 py-1.5 rounded-xl bg-white/10 border border-white/15 text-xs font-bold text-emerald-300 flex items-center gap-1.5">
                        <i class="fas fa-database text-[11px]"></i> Sumber: Database
                    </span>
                @else
                    <span class="px-3 py-1.5 rounded-xl bg-white/10 border border-white/15 text-xs font-bold text-slate-200 flex items-center gap-1.5">
                        <i class="fas fa-sliders text-[11px]"></i> Sumber: Default Sistem
                    </span>
                @endif
            </div>
        </div>
    </div>

    <!-- Flash Feedback -->
    @if(!empty($feedbackMessage))
        <div class="p-4 rounded-xl flex items-center justify-between {{ $feedbackType === 'success' ? 'bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300' : 'bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 text-rose-800 dark:text-rose-300' }} shadow-soft-sm animate-fade-in-up">
            <div class="flex items-center space-x-3">
                <div class="w-8 h-8 rounded-lg {{ $feedbackType === 'success' ? 'bg-emerald-500 text-white' : 'bg-rose-500 text-white' }} flex items-center justify-center text-sm shadow-soft-sm shrink-0">
                    <i class="fas {{ $feedbackType === 'success' ? 'fa-check' : 'fa-exclamation-triangle' }}"></i>
                </div>
                <span class="text-xs font-semibold">{{ $feedbackMessage }}</span>
            </div>
            <button type="button" wire:click="dismissFeedback" class="text-xs opacity-60 hover:opacity-100 p-1 cursor-pointer">
                <i class="fas fa-times"></i>
            </button>
        </div>
    @endif

    <!-- Status Konfigurasi Aktif (Metric Cards) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Status Layanan & AI Eksternal -->
        <div class="card-soft p-5 flex items-center justify-between gap-3">
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Status AI Eksternal</span>
                <h3 class="text-lg font-extrabold mt-1 {{ $activeStatusCode === 'ready' ? 'text-emerald-600 dark:text-emerald-400' : ($activeStatusCode === 'disabled' ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600 dark:text-rose-400') }}">
                    @if($activeStatusCode === 'ready')
                        Aktif &amp; Siap
                    @elseif($activeStatusCode === 'disabled')
                        Nonaktif (Mode Lokal)
                    @else
                        API Key Kosong
                    @endif
                </h3>
                <span class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 block">
                    Toggle: <strong>{{ $activeIsEnabled ? 'Aktif' : 'Nonaktif' }}</strong>
                </span>
            </div>
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0 shadow-soft-sm {{ $activeStatusCode === 'ready' ? 'bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400' : ($activeStatusCode === 'disabled' ? 'bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400' : 'bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400') }}">
                <i class="fas {{ $activeStatusCode === 'ready' ? 'fa-circle-check' : ($activeStatusCode === 'disabled' ? 'fa-power-off' : 'fa-key') }} text-lg"></i>
            </div>
        </div>

        <!-- Provider & Sumber Konfigurasi -->
        <div class="card-soft p-5 flex items-center justify-between gap-3">
            <div class="min-w-0">
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Provider Aktif</span>
                <h3 class="text-lg font-extrabold text-slate-800 dark:text-slate-100 mt-1 truncate">{{ $activeProviderLabel }}</h3>
                <span class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 block">
                    Sumber: <strong>{{ $configSource === 'database' ? 'Database' : 'Default Sistem' }}</strong>
                </span>
            </div>
            <div class="w-11 h-11 rounded-xl bg-gradient-primary text-white flex items-center justify-center shrink-0 shadow-soft-sm">
                <i class="fas fa-microchip text-lg"></i>
            </div>
        </div>

        <!-- Model Aktif -->
        <div class="card-soft p-5 flex items-center justify-between gap-3">
            <div class="min-w-0">
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Model Aktif</span>
                <h3 class="text-sm font-extrabold text-slate-800 dark:text-slate-100 mt-1 break-all">{{ $activeModel ?: '-' }}</h3>
                <span class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 block">
                    Key Aktif:
                    <strong>
                        @if($hasEffectiveKey && $activeKeySource === 'database')
                            Database ({{ $maskedKeyPreview ?? 'Tersimpan' }})
                        @else
                            Belum tersedia
                        @endif
                    </strong>
                </span>
            </div>
            <div class="w-11 h-11 rounded-xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
                <i class="fas fa-brain text-lg"></i>
            </div>
        </div>

        <!-- Status Tes Koneksi Terakhir (Hanya Konfigurasi Tersimpan) -->
        <div class="card-soft p-5 flex items-center justify-between gap-3">
            <div class="min-w-0">
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Tes Konfigurasi Tersimpan</span>
                <h3 class="text-base font-extrabold mt-1 {{ $lastTestStatus === 'success' ? 'text-emerald-600 dark:text-emerald-400' : ($lastTestStatus === 'failed' ? 'text-rose-600 dark:text-rose-400' : 'text-slate-500 dark:text-slate-400') }}">
                    @if($lastTestStatus === 'success')
                        Berhasil
                    @elseif($lastTestStatus === 'failed')
                        Gagal
                    @else
                        Belum Diuji
                    @endif
                </h3>
                <span class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 block truncate">
                    {{ $lastTestedAt ? $lastTestedAt : 'Belum ada riwayat tes' }}
                </span>
            </div>
            <div class="w-11 h-11 rounded-xl {{ $lastTestStatus === 'success' ? 'bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400' : ($lastTestStatus === 'failed' ? 'bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400' : 'bg-slate-100 dark:bg-slate-800 text-slate-400') }} flex items-center justify-center shrink-0">
                <i class="fas {{ $lastTestStatus === 'success' ? 'fa-plug-circle-check' : ($lastTestStatus === 'failed' ? 'fa-plug-circle-xmark' : 'fa-plug') }} text-lg"></i>
            </div>
        </div>
    </div>

    <!-- Main Form + Security / Reference Panel -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Form Konfigurasi (2 Columns) -->
        <div class="lg:col-span-2 card-soft p-6 space-y-5">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-4 border-b border-slate-100 dark:border-slate-800">
                <div>
                    <h2 class="text-base font-extrabold text-slate-800 dark:text-slate-100">Konfigurasi Penyedia &amp; Model AI</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Ubah pengaturan penyedia OpenAI-compatible atau uji koneksi sebelum menyimpan.</p>
                </div>
                @if($updatedAtLabel)
                    <span class="text-[11px] text-slate-400 dark:text-slate-500">
                        Diperbarui {{ $updatedAtLabel }}{{ $updatedByName ? ' oleh ' . $updatedByName : '' }}
                    </span>
                @endif
            </div>

            <!-- Live Test Connection Result (Dipisahkan tegas antara konfigurasi sementara vs tersimpan) -->
            @if(!empty($liveTestResult))
                <div class="p-4 rounded-xl border {{ $liveTestResult['ok'] ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-800 text-emerald-900 dark:text-emerald-200' : 'bg-rose-50 dark:bg-rose-950/40 border-rose-200 dark:border-rose-800 text-rose-900 dark:text-rose-200' }} space-y-1">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2 font-bold text-xs">
                            <i class="fas {{ $liveTestResult['ok'] ? 'fa-circle-check text-emerald-600 dark:text-emerald-400' : 'fa-circle-xmark text-rose-600 dark:text-rose-400' }}"></i>
                            <span>
                                {{ $liveTestResult['is_temporary'] ? 'Hasil Tes Konfigurasi Sementara (Belum Disimpan)' : 'Hasil Tes Konfigurasi Aktif Tersimpan' }}
                            </span>
                        </div>
                        <span class="text-[10px] opacity-75">{{ $liveTestResult['tested_at'] }}</span>
                    </div>
                    <p class="text-xs leading-relaxed">{{ $liveTestResult['message'] }}</p>
                    <p class="text-[11px] opacity-80">
                        Target diuji: <strong>{{ $liveTestResult['provider_label'] }}</strong> &bull; Model: <code>{{ $liveTestResult['model'] }}</code>
                        @if($liveTestResult['is_temporary'])
                            &bull; <em>Konfigurasi aktif di sistem belum berubah hingga Anda menekan Simpan.</em>
                        @endif
                    </p>
                </div>
            @endif

            <form wire:submit.prevent="save" class="space-y-5">
                <!-- Toggle Aktifkan AI Eksternal -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-900/60 border border-slate-200/80 dark:border-slate-700/80 space-y-2">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <label for="ai-is-enabled" class="text-xs font-bold text-slate-800 dark:text-slate-100 block">
                                Aktifkan Layanan AI Eksternal
                            </label>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                Jika dinonaktifkan, sistem tidak mengirim request HTTP keluar. DB Intelligence (statistik SKTM, laporan, akun) dan panduan lokal tetap aktif penuh.
                            </p>
                        </div>
                        <label class="inline-flex items-center cursor-pointer shrink-0">
                            <input id="ai-is-enabled" type="checkbox" wire:model.live="isEnabled" class="sr-only peer">
                            <div class="w-11 h-6 bg-slate-300 dark:bg-slate-700 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-pink-500/40 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all relative peer-checked:bg-pink-600"></div>
                        </label>
                    </div>
                    @if($isEnabled !== $activeIsEnabled)
                        <div class="px-3 py-2 rounded-lg bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 text-[11px] font-semibold text-amber-800 dark:text-amber-200 flex items-center gap-1.5">
                            <i class="fas fa-circle-exclamation shrink-0"></i>
                            <span>Perubahan status AI eksternal (<strong>{{ $isEnabled ? 'Aktif' : 'Nonaktif / Mode Lokal' }}</strong>) belum diterapkan. Klik <strong>Simpan Konfigurasi</strong> di bawah untuk menyimpannya.</span>
                        </div>
                    @endif
                </div>

                <!-- Preset Provider & Label -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="ai-provider" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                            Preset Provider AI
                        </label>
                        <select id="ai-provider" wire:model.live="provider" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 outline-none">
                            @foreach($presets as $key => $preset)
                                <option value="{{ $key }}">{{ $preset['name'] }}</option>
                            @endforeach
                        </select>
                        @error('provider') <p class="mt-1 text-[11px] text-rose-600 dark:text-rose-400 font-semibold">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="ai-label" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                            Label Konfigurasi (Opsional)
                        </label>
                        <input id="ai-label" type="text" wire:model="label" placeholder="Contoh: Google AI Studio - Produksi Desa" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 outline-none">
                        @error('label') <p class="mt-1 text-[11px] text-rose-600 dark:text-rose-400 font-semibold">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if(isset($presets[$provider]['protocol_note']))
                    <div class="p-3 rounded-xl bg-blue-50/70 dark:bg-blue-950/30 border border-blue-200/80 dark:border-blue-800/70 text-[11px] text-blue-800 dark:text-blue-200 flex items-start gap-2">
                        <i class="fas fa-circle-info mt-0.5 shrink-0"></i>
                        <span>{{ $presets[$provider]['protocol_note'] }}</span>
                    </div>
                @endif

                <!-- Base URL -->
                <div>
                    <label for="ai-base-url" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                        Base URL Endpoint (Wajib HTTPS Resmi Provider)
                    </label>
                    <input id="ai-base-url" type="url" wire:model="baseUrl" placeholder="https://generativelanguage.googleapis.com/v1beta/models" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 outline-none">
                    <p class="mt-1 text-[11px] text-slate-400 dark:text-slate-500">
                        Dilindungi validasi anti-SSRF: wajib HTTPS publik tanpa kredensial URL, query string, IP privat/loopback, atau metadata cloud.
                    </p>
                    @error('baseUrl') <p class="mt-1 text-[11px] text-rose-600 dark:text-rose-400 font-semibold">{{ $message }}</p> @enderror
                </div>

                <!-- Model AI Dinamis & Custom Model -->
                <div class="p-4 rounded-xl bg-slate-50/80 dark:bg-slate-900/50 border border-slate-200/80 dark:border-slate-700/80 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <label for="ai-model-select" class="block text-xs font-bold text-slate-700 dark:text-slate-200">
                                Pilihan Model AI
                            </label>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400">
                                Model aktif tersimpan: <code class="font-mono font-semibold text-slate-700 dark:text-slate-200">{{ $activeModel ?: '-' }}</code>
                                &bull; Sumber daftar:
                                <span class="font-semibold {{ $modelCatalogSource === 'api' ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-600 dark:text-slate-300' }}">
                                    {{ $modelCatalogSource === 'api' ? 'API Provider Langsung' : 'Katalog Preset' }}
                                </span>
                            </p>
                        </div>

                        <button
                            type="button"
                            wire:click="refreshModels"
                            wire:loading.attr="disabled"
                            wire:target="refreshModels"
                            class="px-3 py-1.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-pink-400 dark:hover:border-pink-500 text-[11px] font-bold text-slate-700 dark:text-slate-200 flex items-center gap-1.5 transition-colors cursor-pointer disabled:opacity-60">
                            <span wire:loading.remove wire:target="refreshModels"><i class="fas fa-arrows-rotate text-pink-500"></i> Muat Ulang Daftar Model</span>
                            <span wire:loading wire:target="refreshModels"><i class="fas fa-spinner fa-spin text-pink-500"></i> Memuat...</span>
                        </button>
                    </div>

                    @if(!empty($modelFetchStatus))
                        <div class="p-2.5 rounded-lg text-[11px] border {{ $modelFetchStatus['ok'] ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300' : 'bg-amber-50 dark:bg-amber-950/40 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300' }}">
                            <i class="fas {{ $modelFetchStatus['ok'] ? 'fa-circle-check' : 'fa-circle-info' }} mr-1"></i>
                            {{ $modelFetchStatus['message'] }}
                        </div>
                    @endif

                    @if(!empty($modelSelectionNotice))
                        <div class="p-2.5 rounded-lg text-[11px] bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-200">
                            <i class="fas fa-triangle-exclamation mr-1"></i>
                            {{ $modelSelectionNotice }}
                        </div>
                    @endif

                    <div>
                        <select
                            id="ai-model-select"
                            wire:model.live="selectedModelOption"
                            class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 outline-none">
                            @if($provider !== 'custom')
                                <option value="">-- Pilih Model ({{ $presets[$provider]['name'] ?? strtoupper($provider) }}) --</option>
                            @endif
                            @foreach($availableModels as $item)
                                <option value="{{ $item['id'] }}">
                                    {{ $item['label'] }}{{ !empty($item['is_reasoning']) ? ' [Reasoning]' : '' }}
                                </option>
                            @endforeach
                            <option value="__custom__">Model Khusus / Custom (Ketik Manual)</option>
                        </select>
                    </div>

                    @if($selectedModelOption === '__custom__' || $provider === 'custom')
                        <div class="space-y-2 pt-1">
                            <label for="ai-model" class="block text-[11px] font-bold text-slate-700 dark:text-slate-200">
                                ID Model Khusus / Custom
                            </label>
                            <input
                                id="ai-model"
                                type="text"
                                wire:model.live="model"
                                placeholder="Contoh: openai/gpt-oss-20b, my-org/custom-model-v1"
                                class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 outline-none">
                            <p class="text-[11px] text-slate-500 dark:text-slate-400">
                                Anda dapat memasukkan nama model baru apa pun yang didukung oleh endpoint OpenAI-compatible Anda.
                            </p>

                            @if($this->isUnlistedPresetModel())
                                <label class="flex items-start gap-2 p-2.5 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 cursor-pointer">
                                    <input id="ai-confirm-unlisted-model" type="checkbox" wire:model="confirmUnlistedModel" class="mt-0.5 rounded border-amber-400 text-pink-600 focus:ring-pink-500">
                                    <span class="text-[11px] text-amber-900 dark:text-amber-200">
                                        Saya mengonfirmasi bahwa model <strong>{{ $model }}</strong> tersedia pada akun/provider saya meski belum ada dalam daftar preset.
                                    </span>
                                </label>
                            @endif
                        </div>
                    @endif

                    @error('model') <p class="mt-1 text-[11px] text-rose-600 dark:text-rose-400 font-semibold">{{ $message }}</p> @enderror
                </div>

                <!-- API Key (Write-only, selalu kosong saat dibuka; tersimpan terpisah per provider) -->
                @php
                    $selectedProviderTitle = $presets[$provider]['name'] ?? strtoupper($provider);
                @endphp
                <div class="p-4 rounded-xl bg-slate-50/80 dark:bg-slate-900/50 border border-slate-200/80 dark:border-slate-700/80 space-y-2.5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <label for="ai-api-key" class="text-xs font-bold text-slate-700 dark:text-slate-200">
                                API Key — {{ $selectedProviderTitle }} (Terenkripsi per Provider)
                            </label>
                            @if($provider !== $activeProvider)
                                <p class="text-[10px] text-indigo-600 dark:text-indigo-400 font-semibold mt-0.5">
                                    Pratinjau form draft (konfigurasi tersimpan saat ini masih: {{ $activeProviderLabel }}).
                                </p>
                            @endif
                        </div>

                        @if($selectedProviderKeySource === 'database' && $selectedProviderHasDbKey)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300">
                                <i class="fas fa-lock mr-1"></i> Sumber: Database ({{ $selectedProviderMaskedKey }})
                            </span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 dark:bg-rose-950/60 text-rose-700 dark:text-rose-300">
                                <i class="fas fa-triangle-exclamation mr-1"></i> Sumber: Belum tersedia
                            </span>
                        @endif
                    </div>

                    <input
                        id="ai-api-key"
                        wire:key="ai-api-key-{{ $provider }}"
                        type="password"
                        wire:model="newApiKey"
                        autocomplete="new-password"
                        placeholder="{{ $selectedProviderHasDbKey ? 'Kosongkan untuk tetap memakai API Key tersimpan, atau isi key baru...' : ($presets[$provider]['key_placeholder'] ?? 'Masukkan API Key baru...') }}"
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 outline-none">

                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">
                            @if($selectedProviderHasDbKey)
                                API Key asli tidak pernah dikirim kembali ke browser. Mengosongkan kolom ini mempertahankan key database untuk {{ $selectedProviderTitle }} dan tidak mempengaruhi provider lain.
                            @elseif($selectedProviderIsCleared)
                                API Key untuk {{ $selectedProviderTitle }} telah dihapus (status <code>not_configured</code>). Masukkan API Key baru untuk mengaktifkan provider ini kembali.
                            @else
                                Belum tersedia API Key di database untuk {{ $selectedProviderTitle }}. Masukkan API Key valid untuk mengaktifkan permintaan ke {{ $selectedProviderTitle }}.
                            @endif
                        </p>

                        @if($selectedProviderHasDbKey)
                            <div class="flex flex-wrap items-center gap-1.5 shrink-0">
                                <button
                                    type="button"
                                    wire:click="openClearKeyModal"
                                    class="px-3 py-1.5 rounded-lg bg-rose-50 dark:bg-rose-950/50 text-rose-700 dark:text-rose-300 hover:bg-rose-100 dark:hover:bg-rose-900/50 border border-rose-200 dark:border-rose-800 text-[11px] font-bold transition-colors cursor-pointer">
                                    <i class="fas fa-trash-can mr-1"></i> Hapus API Key
                                </button>
                            </div>
                        @endif
                    </div>
                    @error('newApiKey') <p class="mt-1 text-[11px] text-rose-600 dark:text-rose-400 font-semibold">{{ $message }}</p> @enderror
                </div>

                <!-- Temperature, Timeout & Max Tokens -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label for="ai-temperature" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                            Temperature (0.0 - 2.0)
                        </label>
                        <input id="ai-temperature" type="number" step="0.1" min="0" max="2" wire:model="temperature" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 outline-none">
                        @error('temperature') <p class="mt-1 text-[11px] text-rose-600 dark:text-rose-400 font-semibold">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="ai-timeout" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                            Timeout Request (detik)
                        </label>
                        <input id="ai-timeout" type="number" min="5" max="120" wire:model="timeout" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 outline-none">
                        @error('timeout') <p class="mt-1 text-[11px] text-rose-600 dark:text-rose-400 font-semibold">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="ai-max-tokens" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                            Maksimum Token Jawaban (Max Tokens)
                        </label>
                        <input id="ai-max-tokens" type="number" min="50" max="4096" wire:model="maxTokens" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 outline-none">
                        @error('maxTokens') <p class="mt-1 text-[11px] text-rose-600 dark:text-rose-400 font-semibold">{{ $message }}</p> @enderror
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="save,testConnection"
                            class="px-5 py-2.5 rounded-xl bg-gradient-primary text-white text-xs font-bold shadow-soft-sm hover:opacity-95 transition-all flex items-center gap-2 cursor-pointer disabled:opacity-60">
                            <span wire:loading.remove wire:target="save"><i class="fas fa-save"></i> Simpan Konfigurasi</span>
                            <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin"></i> Menyimpan...</span>
                        </button>

                        <button
                            type="button"
                            wire:click="testConnection"
                            wire:loading.attr="disabled"
                            wire:target="testConnection,save"
                            class="px-4 py-2.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 text-xs font-bold hover:bg-indigo-100 dark:hover:bg-indigo-900/50 transition-all flex items-center gap-2 cursor-pointer disabled:opacity-60">
                            <span wire:loading.remove wire:target="testConnection"><i class="fas fa-plug-circle-bolt"></i> Tes Koneksi</span>
                            <span wire:loading wire:target="testConnection"><i class="fas fa-spinner fa-spin"></i> Menguji...</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Panel Informasi & Catatan Keamanan (1 Column) -->
        <div class="space-y-4">
            <div class="card-soft p-5 space-y-3">
                <h3 class="text-sm font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                    <i class="fas fa-circle-nodes text-pink-500"></i> Ringkasan Konfigurasi Saat Ini
                </h3>
                <dl class="space-y-2.5 text-xs">
                    <div class="flex justify-between gap-2 pb-2 border-b border-slate-100 dark:border-slate-800">
                        <dt class="text-slate-400">Sumber Konfigurasi</dt>
                        <dd class="font-bold text-slate-700 dark:text-slate-200">{{ $configSource === 'database' ? 'Database Global' : 'Default Sistem' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2 pb-2 border-b border-slate-100 dark:border-slate-800">
                        <dt class="text-slate-400">Provider Aktif</dt>
                        <dd class="font-bold text-slate-700 dark:text-slate-200">{{ $activeProviderLabel }}</dd>
                    </div>
                    <div class="flex justify-between gap-2 pb-2 border-b border-slate-100 dark:border-slate-800">
                        <dt class="text-slate-400">Sumber API Key</dt>
                        <dd class="font-bold text-slate-700 dark:text-slate-200">
                            {{ $activeKeySource === 'database' ? 'Database' : 'Belum tersedia' }}
                        </dd>
                    </div>
                    <div class="pb-2 border-b border-slate-100 dark:border-slate-800">
                        <dt class="text-slate-400 mb-0.5">Base URL Aktif</dt>
                        <dd class="font-mono text-[11px] text-slate-700 dark:text-slate-200 break-all">{{ $activeBaseUrl ?: '-' }}</dd>
                    </div>
                    <div class="pb-2 border-b border-slate-100 dark:border-slate-800">
                        <dt class="text-slate-400 mb-0.5">Model Aktif</dt>
                        <dd class="font-mono text-[11px] text-slate-700 dark:text-slate-200 break-all">{{ $activeModel ?: '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400 mb-0.5">Catatan Tes Tersimpan</dt>
                        <dd class="text-[11px] text-slate-600 dark:text-slate-300">
                            {{ $lastTestMessage ?: 'Belum pernah menjalankan tes pada konfigurasi tersimpan.' }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="card-soft p-5 space-y-2.5">
                <h3 class="text-sm font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                    <i class="fas fa-shield-halved text-emerald-500"></i> Keamanan &amp; Arsitektur
                </h3>
                <ul class="space-y-2 text-[11px] text-slate-600 dark:text-slate-300 leading-relaxed list-disc pl-4">
                    <li>API Key dienkripsi di database dan tidak pernah dikirim ulang ke tampilan browser atau AuditLog.</li>
                    <li>Seluruh role (Warga, Staf, Kepala Desa, Supervisor) otomatis menggunakan satu konfigurasi aktif ini.</li>
                    <li>Fitur statistik SKTM, data laporan, jumlah akun, panduan SKTM lokal, dan ringkasan lokal tetap berfungsi walaupun AI eksternal dinonaktifkan.</li>
                    <li>Google AI Studio menggunakan protokol native Gemini REST (<code>generateContent</code> + header <code>x-goog-api-key</code>), sedangkan Groq, OpenAI, DeepSeek, OpenRouter, Ollama, dan Custom Gateway menggunakan protokol OpenAI-compatible.</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Modal Konfirmasi Hapus API Key -->
    @if($showClearKeyModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
            <div class="w-full max-w-md bg-white dark:bg-slate-800 rounded-2xl shadow-soft-xl border border-slate-200 dark:border-slate-700 p-6 space-y-4">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl bg-rose-100 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
                        <i class="fas fa-key"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-extrabold text-slate-800 dark:text-slate-100">Hapus API Key {{ $presets[$provider]['name'] ?? strtoupper($provider) }}?</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                            API Key untuk provider <strong>{{ $presets[$provider]['name'] ?? strtoupper($provider) }}</strong> akan dihapus dari database. Provider akan berstatus belum terkonfigurasi hingga API Key baru disimpan. Kredensial provider lain tidak terpengaruh.
                        </p>
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="cancelClearKeyModal" class="px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-600 dark:text-slate-300 cursor-pointer">
                        Batal
                    </button>
                    <button type="button" wire:click="clearApiKey" class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold cursor-pointer">
                        Ya, Hapus API Key
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

