@extends('layouts.app')

@section('title', 'Dashboard Unit')
@section('header', 'Dashboard Unit')
@section('subtitle', 'Pilih unit untuk melihat hasil dan riwayat analisis')

@section('content')
<div class="space-y-5">
    @if(session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ session('success') }}</div>
    @endif

    <section class="rounded-2xl bg-slate-950 px-6 py-6 text-white shadow-lg sm:px-7">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-[.2em] text-blue-300">Workspace unit</p>
                <h2 class="mt-2 text-2xl font-extrabold tracking-tight sm:text-3xl">Analisis traffic per unit</h2>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" data-unit-manager-open class="inline-flex items-center justify-center rounded-xl border border-white/20 bg-white/10 px-4 py-3 text-sm font-extrabold text-white transition hover:bg-white/15">Kelola unit</button>
                <a href="{{ route('analysis.create') }}" class="inline-flex items-center justify-center rounded-xl bg-blue-600 px-4 py-3 text-sm font-extrabold text-white hover:bg-blue-500">+ Analisis baru</a>
            </div>
        </div>
    </section>

    @include('components.unit-manager-dialog', ['units' => $units])

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach($units as $unit)
            @php
                $result = $unit->latestCompletedAnalysis;
                $active = $unit->latestAnalysis;
                $summary = $result?->summary_data['summary'] ?? [];
                $risk = data_get($result?->summary_data, 'unit_risk.score', 0);
                $habit = data_get($result?->summary_data, 'unit_habit.score', 0);
                $running = $active && in_array($active->status, ['uploaded', 'processing', 'summarizing']);
            @endphp
            <a href="{{ route('units.show', $unit) }}" class="group flex min-h-52 flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-blue-200 hover:shadow-lg">
                <div class="flex items-start justify-between gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-xs font-black text-blue-700">{{ str($unit->name)->substr(0, 2) }}</span>
                    <span class="rounded-full px-2.5 py-1 text-[10px] font-extrabold {{ $running ? 'bg-blue-50 text-blue-700' : ($result ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500') }}">
                        {{ $running ? 'DIPROSES' : ($result ? 'SIAP' : 'BELUM ADA DATA') }}
                    </span>
                </div>
                <h3 class="mt-4 text-base font-extrabold leading-6 text-slate-900">{{ $unit->name }}</h3>
                @if($result)
                    <div class="mt-4 grid grid-cols-2 gap-3 text-xs">
                        <div><span class="block text-slate-400">User</span><strong class="mt-1 block text-slate-800">{{ number_format($summary['total_clients'] ?? 0) }}</strong></div>
                        <div><span class="block text-slate-400">Event</span><strong class="mt-1 block text-slate-800">{{ number_format($summary['total_logs'] ?? 0) }}</strong></div>
                        <div><span class="block text-slate-400">Risiko</span><strong class="mt-1 block text-amber-600">{{ number_format($risk, 1) }}</strong></div>
                        <div><span class="block text-slate-400">Habit</span><strong class="mt-1 block text-violet-600">{{ number_format($habit, 1) }}</strong></div>
                    </div>
                    <p class="mt-auto pt-4 text-[11px] text-slate-400">Terbaru {{ $result->completed_at?->format('d M Y, H:i') }}</p>
                @else
                    <p class="mt-3 text-sm leading-6 text-slate-500">Belum ada hasil analisis untuk unit ini.</p>
                    <span class="mt-auto pt-4 text-xs font-bold text-blue-600">Buka unit →</span>
                @endif
            </a>
        @endforeach
    </div>
</div>
@endsection
