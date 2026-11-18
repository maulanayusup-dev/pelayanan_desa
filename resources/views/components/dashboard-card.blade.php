@props(['title', 'value', 'icon', 'color' => 'blue', 'subtitle' => null])

@php
    $gradients = [
        'blue' => 'from-blue-600 to-cyan-400 shadow-blue-500/30 text-blue-600',
        'green' => 'from-emerald-600 to-teal-400 shadow-emerald-500/30 text-emerald-600',
        'orange' => 'from-amber-500 to-yellow-400 shadow-amber-500/30 text-amber-600',
        'red' => 'from-rose-600 to-red-400 shadow-rose-500/30 text-rose-600',
        'purple' => 'from-purple-700 to-pink-500 shadow-pink-500/30 text-purple-600',
    ];
    $gradientData = $gradients[$color] ?? $gradients['blue'];
    $bgGradient = explode(' ', $gradientData)[0] . ' ' . explode(' ', $gradientData)[1];
    $shadowColor = explode(' ', $gradientData)[2];
    $textColor = explode(' ', $gradientData)[3];
@endphp

<div class="w-full max-w-full px-3 mb-6 sm:w-1/2 sm:flex-none xl:mb-0 xl:w-1/4 animate-fade-in-up">
    <div class="card-soft p-4 hover:-translate-y-1 hover:shadow-soft-md transition-all duration-300 group h-full">
        <div class="flex items-center justify-between">
            <div class="flex-1 pr-3">
                <p class="text-xs font-bold text-slate-400 dark:text-slate-400 uppercase tracking-wider mb-1 line-clamp-1">{{ $title }}</p>
                <div class="flex items-baseline space-x-2">
                    <h3 class="text-2xl font-bold text-slate-800 dark:text-slate-100 tracking-tight leading-none">{{ $value }}</h3>
                    @if($subtitle)
                        <span class="text-[11px] font-semibold text-slate-400 dark:text-slate-400">{{ $subtitle }}</span>
                    @endif
                </div>
            </div>
            <div class="w-12 h-12 rounded-xl bg-gradient-to-tl {{ $bgGradient }} flex items-center justify-center text-white shadow-soft {{ $shadowColor }} group-hover:scale-110 transition-transform duration-300 flex-shrink-0">
                <i class="fas {{ $icon }} text-base"></i>
            </div>
        </div>
    </div>
</div>
