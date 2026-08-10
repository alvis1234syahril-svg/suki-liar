<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Log Traffic')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="theme-electric font-sans text-slate-200 antialiased">
<div class="electric-shell min-h-screen lg:flex">
    <aside class="app-sidebar relative z-30 text-white lg:fixed lg:inset-y-0 lg:flex lg:flex-col">
        <div class="electric-brand relative overflow-hidden border-b border-white/10 px-4 py-4 lg:py-5">
            <a href="{{ route('analysis.dashboard') }}" aria-label="Dashboard Traffic Log" class="electric-brand-title block overflow-hidden rounded-xl px-4 py-3 outline-none transition focus:ring-2 focus:ring-yellow-300/60">
                <strong class="relative z-10 block text-center text-sm font-extrabold tracking-[.16em] text-white">TRAFFIC LOG</strong>
            </a>
        </div>
        <nav class="nav-scroll flex gap-2 overflow-x-auto px-4 py-3 lg:flex-1 lg:flex-col lg:gap-1.5 lg:overflow-visible lg:px-4 lg:py-6">
            <p class="mb-2 hidden px-3 text-[10px] font-bold uppercase tracking-[0.24em] text-yellow-300/60 lg:block">Workspace</p>
            <a href="{{ route('analysis.dashboard') }}" class="electric-nav-link flex shrink-0 items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold transition {{ request()->routeIs('analysis.show', 'analysis.dashboard', 'analysis.client.*', 'units.*') ? 'is-active' : '' }}"><svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></svg><span>Dashboard</span></a>
            <a href="{{ route('analysis.create') }}" class="electric-nav-link flex shrink-0 items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold transition {{ request()->routeIs('analysis.create') ? 'is-active' : '' }}"><svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span>Analisis Baru</span></a>
            <a href="{{ route('comparisons.index') }}" class="electric-nav-link flex shrink-0 items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold transition {{ request()->routeIs('comparisons.*') ? 'is-active' : '' }}"><svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 18V9m6 9V5m6 13v-7m4 7H2"/></svg><span>Bandingkan Unit</span></a>
            <a href="{{ route('topology.index') }}" class="electric-nav-link flex shrink-0 items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold transition {{ request()->routeIs('topology.*') ? 'is-active' : '' }}"><svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="9" y="3" width="6" height="5" rx="1.5"/><rect x="3" y="16" width="6" height="5" rx="1.5"/><rect x="15" y="16" width="6" height="5" rx="1.5"/><path d="M12 8v4M6 16v-4h12v4"/></svg><span>Topologi Aset</span></a>
        </nav>
    </aside>

    <main class="app-main min-w-0 flex-1">
        <header class="electric-header sticky top-0 z-20 px-5 py-4 backdrop-blur-xl sm:px-8">
            <div class="mx-auto flex max-w-[1560px] items-center justify-between gap-4">
                <div><h1 class="text-lg font-extrabold tracking-tight text-white sm:text-xl">@yield('header', 'Dashboard')</h1><p class="mt-0.5 hidden text-xs font-medium text-slate-400 sm:block">@yield('subtitle', 'Ringkasan traffic dan pola penggunaan')</p></div>
                <div class="flex items-center gap-3">
                    <div class="electric-date hidden items-center gap-2.5 rounded-xl px-3 py-2 sm:flex">
                        <svg class="h-4 w-4 text-yellow-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4m8-4v4M3 10h18"/></svg>
                        <div><span class="block text-[8px] font-bold uppercase tracking-[.16em] text-slate-500">Tanggal</span><time datetime="{{ now()->format('Y-m-d') }}" class="mt-0.5 block text-[11px] font-bold text-slate-200">{{ now()->format('d M Y') }}</time></div>
                    </div>
                    <a href="{{ route('analysis.dashboard') }}" aria-label="PLN, Buka dashboard" class="electric-pln-header group rounded-xl outline-none transition hover:-translate-y-0.5 focus:ring-2 focus:ring-yellow-300/60">
                        <img src="{{ asset('images/pln-mark.svg') }}" alt="PLN" class="h-11 w-11 select-none rounded-xl object-contain shadow-lg ring-1 ring-white/10 transition group-hover:shadow-yellow-500/10" draggable="false">
                    </a>
                </div>
            </div>
        </header>
        <section class="mx-auto max-w-[1440px] p-4 sm:p-6 lg:p-7">@yield('content')</section>
    </main>
</div>
@stack('scripts')
</body>
</html>
