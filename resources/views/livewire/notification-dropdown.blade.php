<div class="relative flex items-center pr-2" x-data="{ open: false }" wire:poll.15s="loadNotifications">
    <a href="javascript:;" @click="open = !open" class="relative block p-2 text-sm transition-all text-slate-500 dark:text-slate-300 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-slate-100/60 dark:hover:bg-slate-800 rounded-xl" :class="{ 'bg-primary-50 dark:bg-primary-950/40 text-primary-600 dark:text-primary-400': open }">
        <i class="cursor-pointer fa-solid fa-bell text-base"></i>
        @if($unreadCount > 0)
        <span class="absolute top-1 right-1 inline-flex items-center justify-center min-w-4 h-4 px-1 text-[9px] font-extrabold leading-none text-white transform translate-x-1/4 -translate-y-1/4 bg-gradient-danger rounded-full shadow-sm border-2 border-white dark:border-slate-900">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
        @endif
    </a>

    <div x-show="open" @click.away="open = false" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 translate-y-2" x-transition:enter-end="opacity-100 scale-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100 translate-y-0" x-transition:leave-end="opacity-0 scale-95 translate-y-2" class="absolute right-0 top-full mt-2.5 w-80 sm:w-96 bg-white dark:bg-slate-800 rounded-2xl shadow-soft-xl overflow-hidden z-50 border border-slate-100 dark:border-slate-700" style="display: none;">
        
        <!-- Header -->
        <div class="px-5 py-3.5 border-b border-slate-100 dark:border-slate-700 flex justify-between items-center bg-slate-50/50 dark:bg-slate-900/50">
            <div class="flex items-center gap-2">
                <h6 class="mb-0 text-xs font-extrabold text-slate-800 dark:text-slate-100 uppercase tracking-wider">
                    Notifikasi
                </h6>
                @if($unreadCount > 0)
                    <span class="badge-soft-primary !text-[10px] !py-0.5">{{ $unreadCount }} Baru</span>
                @endif
            </div>
            @if($unreadCount > 0)
            <button wire:click="markAllAsRead" class="text-[11px] text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 font-bold cursor-pointer transition-colors">Tandai Dibaca</button>
            @endif
        </div>
        
        <!-- Notification List -->
        <div class="max-h-[360px] overflow-y-auto divide-y divide-slate-100 dark:divide-slate-700">
            @forelse($notifications as $notif)
            @php
                $data = $notif->data;
                $color = 'blue';
                $icon = 'fa-info';
                
                if(isset($data['type'])) {
                    if($data['type'] == 'success') { $color = 'emerald'; $icon = 'fa-check'; }
                    if($data['type'] == 'warning') { $color = 'amber'; $icon = 'fa-triangle-exclamation'; }
                    if($data['type'] == 'error') { $color = 'rose'; $icon = 'fa-xmark'; }
                }

                $targetUrl = $data['action_url'] ?? null;
                if (!$targetUrl && !empty($data['application_id'])) {
                    $roleName = auth()->user()->role->name ?? 'masyarakat';
                    $targetUrl = in_array($roleName, ['staf', 'supervisor', 'kades', 'kepala_desa']) 
                        ? ('/admin/pengajuan/' . $data['application_id']) 
                        : ('/pengajuan/' . $data['application_id']);
                }
            @endphp
            
            <div class="p-4 transition-colors {{ is_null($notif->read_at) ? 'bg-primary-50/20 dark:bg-primary-950/30 hover:bg-primary-50/40 dark:hover:bg-primary-950/50' : 'bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700/50' }} group relative">
                @if(is_null($notif->read_at))
                    <div class="absolute left-0 top-0 bottom-0 w-1 bg-primary-500 rounded-r"></div>
                @endif
                
                <a href="{{ $targetUrl ?? '#' }}" wire:click.prevent="markAsReadAndRedirect('{{ $notif->id }}')" class="flex items-start">
                    <div class="w-9 h-9 rounded-xl bg-{{ $color }}-50 dark:bg-{{ $color }}-950/40 text-{{ $color }}-600 dark:text-{{ $color }}-400 flex items-center justify-center mr-3 mt-0.5 shrink-0 shadow-sm border border-{{ $color }}-100 dark:border-{{ $color }}-800 group-hover:scale-105 transition-transform">
                        <i class="fas {{ $icon }} text-xs"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-bold mb-0.5 leading-snug {{ is_null($notif->read_at) ? 'text-slate-800 dark:text-slate-100' : 'text-slate-600 dark:text-slate-300' }} group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors">
                            {{ $data['message'] ?? 'Ada pemberitahuan baru' }}
                        </p>
                        <p class="text-[10px] text-slate-400 font-semibold flex items-center">
                            <i class="far fa-clock mr-1 text-[9px]"></i>{{ $notif->created_at->diffForHumans() }}
                        </p>
                    </div>
                </a>
            </div>
            @empty
            <div class="p-8 text-center flex flex-col items-center justify-center">
                <div class="w-12 h-12 rounded-full bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-700 flex items-center justify-center mb-3 text-slate-300 dark:text-slate-600">
                    <i class="fas fa-bell-slash text-base"></i>
                </div>
                <h6 class="text-xs font-bold text-slate-700 dark:text-slate-200 mb-0.5">Semua Tenang!</h6>
                <p class="text-[11px] text-slate-400 font-medium">Tidak ada notifikasi baru untuk saat ini.</p>
            </div>
            @endforelse
        </div>
        
        <!-- Footer -->
        @if(count($notifications) > 0)
        <div class="p-2.5 border-t border-slate-100 dark:border-slate-700 text-center bg-slate-50/50 dark:bg-slate-900/50">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                Sistem Notifikasi Terpadu
            </span>
        </div>
        @endif
    </div>
</div>
