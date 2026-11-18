<div 
    x-data="customConfirmationDialog()"
    x-init="initDialog()"
    x-cloak
    style="display: none;"
    @keydown.escape.window="if (isOpen && !isProcessing) handleCancel()"
    class="relative z-[9999]"
    role="dialog"
    aria-modal="true"
    aria-labelledby="confirm-dialog-title"
    aria-describedby="confirm-dialog-desc"
    x-show="isOpen"
>
    <!-- Backdrop Blur & Overlay -->
    <div 
        x-show="isOpen"
        x-cloak
        style="display: none;"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 bg-slate-900/60 dark:bg-slate-950/80 backdrop-blur-sm"
        @click="if (!isProcessing) handleCancel()"
        aria-hidden="true"
    ></div>

    <!-- Modal Container -->
    <div class="fixed inset-0 z-10 w-screen overflow-y-auto" x-show="isOpen" x-cloak style="display: none;">
        <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0">
            <div 
                x-show="isOpen"
                x-cloak
                style="display: none;"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                class="relative transform overflow-hidden rounded-2xl bg-white dark:bg-slate-800 text-left shadow-soft-xl border border-slate-100 dark:border-slate-700/80 transition-all sm:my-8 sm:w-full sm:max-w-md mx-4"
                @click.stop
            >
                <div class="p-6 sm:p-7 text-center">
                    <!-- Contextual Icon Badge -->
                    <div 
                        class="w-14 h-14 rounded-2xl flex items-center justify-center text-2xl mx-auto mb-4 shadow-soft-sm transition-transform duration-300 transform scale-100"
                        :class="getIconBadgeClasses()"
                    >
                        <i :class="getIconClass()"></i>
                    </div>

                    <!-- Title -->
                    <h3 
                        id="confirm-dialog-title" 
                        class="text-base sm:text-lg font-bold text-slate-800 dark:text-slate-100 tracking-tight leading-snug"
                        x-text="title"
                    ></h3>

                    <!-- Description / Body -->
                    <div class="mt-2.5">
                        <p 
                            id="confirm-dialog-desc" 
                            class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 leading-relaxed font-normal"
                            x-text="message"
                        ></p>
                    </div>

                    <!-- Action Buttons -->
                    <div class="mt-6 flex flex-col-reverse sm:flex-row sm:justify-center items-center gap-2.5 sm:gap-3">
                        <button 
                            type="button" 
                            @click="handleCancel()" 
                            :disabled="isProcessing"
                            class="w-full sm:w-auto px-5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700/70 text-slate-600 dark:text-slate-300 text-xs font-bold transition-all cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed shadow-soft-sm"
                        >
                            <span x-text="cancelText">Batal</span>
                        </button>
                        
                        <button 
                            type="button" 
                            x-ref="confirmButton"
                            @click="handleConfirm()" 
                            :disabled="isProcessing"
                            class="w-full sm:w-auto px-5 py-2.5 rounded-xl text-white text-xs font-bold transition-all cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed flex items-center justify-center shadow-soft"
                            :class="getConfirmButtonClasses()"
                        >
                            <span x-show="isProcessing" class="mr-2 inline-flex items-center">
                                <i class="fas fa-circle-notch fa-spin text-xs"></i>
                            </span>
                            <span x-text="isProcessing ? 'Memproses...' : confirmText"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function customConfirmationDialog() {
        return {
            isOpen: false,
            isProcessing: false,
            title: 'Konfirmasi Tindakan',
            message: 'Apakah Anda yakin ingin melanjutkan tindakan ini?',
            confirmText: 'Ya, Lanjutkan',
            cancelText: 'Batal',
            variant: 'primary', // 'primary', 'success', 'danger', 'warning', 'info'
            icon: '',
            resolvePromise: null,
            onConfirmCallback: null,
            onCancelCallback: null,

            initDialog() {
                window._customConfirmInstance = this;
                
                window.addEventListener('open-confirm-dialog', (e) => {
                    const detail = e.detail || {};
                    const extResolve = detail._resolve;
                    this.openDialog(detail).then((res) => {
                        if (typeof extResolve === 'function') {
                            extResolve(res);
                        }
                    });
                });
            },

            openDialog(options = {}) {
                if (typeof options === 'string') {
                    options = { message: options };
                }
                return new Promise((resolve) => {
                    this.title = options.title || 'Konfirmasi Tindakan';
                    this.message = options.message || options.description || 'Apakah Anda yakin ingin melanjutkan tindakan ini?';
                    this.confirmText = options.confirmText || 'Ya, Lanjutkan';
                    this.cancelText = options.cancelText || 'Batal';
                    this.variant = options.variant || options.confirmVariant || 'primary';
                    this.icon = options.icon || this.getDefaultIcon(this.variant);
                    this.onConfirmCallback = typeof options.onConfirm === 'function' ? options.onConfirm : null;
                    this.onCancelCallback = typeof options.onCancel === 'function' ? options.onCancel : null;
                    this.resolvePromise = resolve;
                    this.isProcessing = false;
                    this.isOpen = true;

                    // Prevent background scroll
                    document.body.classList.add('overflow-hidden');

                    this.$nextTick(() => {
                        if (this.$refs.confirmButton) {
                            this.$refs.confirmButton.focus();
                        }
                    });
                });
            },

            async handleConfirm() {
                if (this.isProcessing) return;
                this.isProcessing = true;

                try {
                    if (this.onConfirmCallback) {
                        await this.onConfirmCallback();
                    }
                    if (this.resolvePromise) {
                        this.resolvePromise(true);
                    }
                } catch (error) {
                    console.error('Error during confirmation callback:', error);
                } finally {
                    this.closeDialog();
                }
            },

            handleCancel() {
                if (this.isProcessing) return;
                
                if (this.onCancelCallback) {
                    try {
                        this.onCancelCallback();
                    } catch (e) {
                        console.error('Error during cancel callback:', e);
                    }
                }
                if (this.resolvePromise) {
                    this.resolvePromise(false);
                }
                this.closeDialog();
            },

            closeDialog() {
                this.isOpen = false;
                this.isProcessing = false;
                this.resolvePromise = null;
                this.onConfirmCallback = null;
                this.onCancelCallback = null;
                document.body.classList.remove('overflow-hidden');
            },

            getIconBadgeClasses() {
                switch (this.variant) {
                    case 'danger':
                        return 'bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 border border-rose-200/80 dark:border-rose-800/60';
                    case 'warning':
                        return 'bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400 border border-amber-200/80 dark:border-amber-800/60';
                    case 'success':
                        return 'bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 border border-emerald-200/80 dark:border-emerald-800/60';
                    case 'info':
                        return 'bg-sky-50 dark:bg-sky-950/50 text-sky-600 dark:text-sky-400 border border-sky-200/80 dark:border-sky-800/60';
                    default:
                        return 'bg-pink-50 dark:bg-pink-950/50 text-pink-600 dark:text-pink-400 border border-pink-200/80 dark:border-pink-800/60';
                }
            },

            getIconClass() {
                if (this.icon) {
                    const ic = this.icon.trim();
                    if (ic.startsWith('fa-')) return 'fas ' + ic;
                    if (ic.startsWith('fas ') || ic.startsWith('far ') || ic.startsWith('fab ')) return ic;
                    return 'fas fa-' + ic;
                }
                return 'fas ' + this.getDefaultIcon(this.variant);
            },

            getDefaultIcon(variant) {
                switch (variant) {
                    case 'danger': return 'fa-circle-exclamation';
                    case 'warning': return 'fa-triangle-exclamation';
                    case 'success': return 'fa-circle-check';
                    case 'info': return 'fa-circle-info';
                    default: return 'fa-circle-question';
                }
            },

            getConfirmButtonClasses() {
                switch (this.variant) {
                    case 'danger':
                        return 'bg-gradient-danger hover:opacity-95';
                    case 'warning':
                        return 'bg-gradient-warning hover:opacity-95';
                    case 'success':
                        return 'bg-gradient-success hover:opacity-95';
                    case 'info':
                        return 'bg-gradient-info hover:opacity-95';
                    default:
                        return 'bg-gradient-primary hover:opacity-95';
                }
            }
        };
    }

    window.customConfirmationDialog = customConfirmationDialog;
    window.confirmDialog = function(options) {
        if (typeof options === 'string') {
            options = { message: options };
        }
        if (window._customConfirmInstance) {
            return window._customConfirmInstance.openDialog(options);
        }
        return new Promise((resolve) => {
            window.dispatchEvent(new CustomEvent('open-confirm-dialog', {
                detail: { ...options, _resolve: resolve }
            }));
        });
    };
    window.$confirm = window.confirmDialog;
    window.confirmAction = window.confirmDialog;
</script>
