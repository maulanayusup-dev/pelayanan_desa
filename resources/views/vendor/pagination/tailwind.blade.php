@if ($paginator->hasPages())
    <div class="flex flex-col sm:flex-row items-center justify-between gap-4 py-3 px-4">
        <!-- Range info -->
        <div class="text-xs text-slate-500 dark:text-slate-400">
            Menampilkan 
            <span class="font-bold text-slate-800 dark:text-slate-200">{{ $paginator->firstItem() ?? 0 }}</span> 
            sampai 
            <span class="font-bold text-slate-800 dark:text-slate-200">{{ $paginator->lastItem() ?? 0 }}</span> 
            dari 
            <span class="font-bold text-slate-800 dark:text-slate-200">{{ $paginator->total() }}</span> 
            data
        </div>

        <!-- Pagination Controls -->
        <nav role="navigation" aria-label="Navigasi Halaman" class="flex items-center space-x-1 text-xs">
            {{-- Previous Page Link --}}
            @if ($paginator->onFirstPage())
                <span class="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-300 dark:text-slate-600 cursor-not-allowed select-none">
                    <i class="fas fa-chevron-left text-[10px] mr-1"></i> Sebelumnya
                </span>
            @else
                @if(method_exists($paginator, 'previousPageUrl'))
                    @if(isset($this))
                        <button type="button" wire:click="previousPage" wire:loading.attr="disabled" class="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 font-semibold shadow-soft-sm transition-all cursor-pointer">
                            <i class="fas fa-chevron-left text-[10px] mr-1"></i> Sebelumnya
                        </button>
                    @else
                        <a href="{{ $paginator->previousPageUrl() }}" class="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 font-semibold shadow-soft-sm transition-all">
                            <i class="fas fa-chevron-left text-[10px] mr-1"></i> Sebelumnya
                        </a>
                    @endif
                @endif
            @endif

            {{-- Pagination Elements --}}
            @foreach ($elements as $element)
                {{-- "Three Dots" Separator --}}
                @if (is_string($element))
                    <span class="px-2 py-1 text-slate-400 dark:text-slate-600 select-none">{{ $element }}</span>
                @endif

                {{-- Array Of Links --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="w-8 h-8 rounded-xl bg-gradient-primary text-white font-bold flex items-center justify-center shadow-soft-sm select-none">
                                {{ $page }}
                            </span>
                        @else
                            @if(isset($this))
                                <button type="button" wire:click="gotoPage({{ $page }})" class="w-8 h-8 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 font-semibold flex items-center justify-center shadow-soft-sm transition-all cursor-pointer">
                                    {{ $page }}
                                </button>
                            @else
                                <a href="{{ $url }}" class="w-8 h-8 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 font-semibold flex items-center justify-center shadow-soft-sm transition-all">
                                    {{ $page }}
                                </a>
                            @endif
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next Page Link --}}
            @if ($paginator->hasMorePages())
                @if(isset($this))
                    <button type="button" wire:click="nextPage" wire:loading.attr="disabled" class="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 font-semibold shadow-soft-sm transition-all cursor-pointer">
                        Selanjutnya <i class="fas fa-chevron-right text-[10px] ml-1"></i>
                    </button>
                @else
                    <a href="{{ $paginator->nextPageUrl() }}" class="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 font-semibold shadow-soft-sm transition-all">
                        Selanjutnya <i class="fas fa-chevron-right text-[10px] ml-1"></i>
                    </a>
                @endif
            @else
                <span class="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-300 dark:text-slate-600 cursor-not-allowed select-none">
                    Selanjutnya <i class="fas fa-chevron-right text-[10px] ml-1"></i>
                </span>
            @endif
        </nav>
    </div>
@elseif($paginator->total() > 0)
    <div class="py-3 px-4 text-xs text-slate-500 dark:text-slate-400">
        Menampilkan 
        <span class="font-bold text-slate-800 dark:text-slate-200">1</span> 
        sampai 
        <span class="font-bold text-slate-800 dark:text-slate-200">{{ $paginator->total() }}</span> 
        dari 
        <span class="font-bold text-slate-800 dark:text-slate-200">{{ $paginator->total() }}</span> 
        data
    </div>
@endif
