@props(['title', 'standalone' => false])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Sistem Pelayanan Desa' }} - Pelayanan Desa Digital</title>
    
    <!-- Early Dark Mode Detection (Prevents FOUC) -->
    <script>
        (function() {
            try {
                const storedTheme = localStorage.getItem('theme');
                const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (storedTheme === 'dark' || (!storedTheme && systemPrefersDark)) {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
            } catch (e) {
                console.error('Theme detection error:', e);
            }
        })();
    </script>

    <!-- Fonts: Open Sans for Soft UI Dashboard Aesthetic -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300;0,400;0,600;0,700;1,400&display=swap" rel="stylesheet" />
    
    <!-- Icons: FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="m-0 font-sans text-base antialiased font-normal leading-default bg-[#F8F9FA] dark:bg-[#0b1120] text-[#344767] dark:text-[#e2e8f0] selection:bg-pink-500 selection:text-white transition-colors duration-200 min-h-screen flex flex-col justify-between">
    
    @php
        $isPublicPage = $standalone || request()->is('login', 'register');
    @endphp

    @if(auth()->check() && !$isPublicPage)
        <!-- Horizontal Sticky Top Navbar (Replaces Permanent Sidebar) -->
        <header class="sticky top-0 z-40 w-full">
            <x-navbar :title="$title ?? 'Dashboard'" />
        </header>

        <!-- Main Content Canvas (Optimized Full Screen Width without Dead Sidebar Space) -->
        <main class="w-full max-w-7xl px-4 sm:px-6 lg:px-8 py-6 mx-auto flex-1">
            {{ $slot }}
        </main>

        <!-- Soft UI Dashboard Footer -->
        <footer class="w-full border-t border-slate-200/80 dark:border-slate-800/80 bg-white/75 dark:bg-slate-900/75 backdrop-blur-md text-xs text-slate-500 dark:text-slate-400 py-4 px-4 sm:px-6 lg:px-8 transition-colors">
            <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-3 text-center sm:text-left">
                <div class="flex items-center space-x-2">
                    <div class="w-5 h-5 rounded-md bg-gradient-primary flex items-center justify-center text-white text-[10px]">
                        <i class="fas fa-landmark"></i>
                    </div>
                    <span>
                        &copy; {{ date('Y') }} <strong class="text-slate-700 dark:text-slate-200">Pemerintah Desa</strong> &bull; Sistem Pelayanan Mandiri SKTM Digital
                    </span>
                </div>
                <div class="flex items-center space-x-5 font-semibold">
                    <a href="/informasi-sktm" class="hover:text-pink-600 dark:hover:text-pink-400 transition-colors">Panduan Layanan</a>
                    <a href="/persyaratan" class="hover:text-pink-600 dark:hover:text-pink-400 transition-colors">Persyaratan</a>
                    <a href="/faq" class="hover:text-pink-600 dark:hover:text-pink-400 transition-colors">FAQ & Bantuan</a>
                </div>
            </div>
        </footer>
    @else
        <main class="mt-0 transition-all duration-200 ease-soft-in-out flex-1 flex flex-col">
            {{ $slot }}
        </main>
    @endif

    @if(!request()->is('login') && !request()->is('register'))
        @livewire('chatbot-widget')
        <!-- Global Reusable Confirmation Dialog -->
        <x-confirm-dialog />
    @endif

    <!-- Global Theme Switcher & System Preference Sync Script -->
    <script>
        window.toggleTheme = function() {
            const html = document.documentElement;
            const isDark = html.classList.toggle('dark');
            try {
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
            } catch (e) {}
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: { isDark } }));
        };

        // Listen for OS scheme change when user hasn't set an explicit preference
        try {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
                if (!localStorage.getItem('theme')) {
                    if (e.matches) {
                        document.documentElement.classList.add('dark');
                    } else {
                        document.documentElement.classList.remove('dark');
                    }
                    window.dispatchEvent(new CustomEvent('theme-changed', { detail: { isDark: e.matches } }));
                }
            });
        } catch (e) {}
    </script>
</body>
</html>
