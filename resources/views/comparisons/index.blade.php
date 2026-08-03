@extends('layouts.app')

@section('title', 'Perbandingan Unit')
@section('header', 'Perbandingan Unit')
@section('subtitle', 'Hasil terbaru setiap unit')

@section('content')
@php
    $available = $items->filter(fn ($item) => $item->batch);
    $labels = $available->map(fn ($item) => $item->unit->name)->values()->all();
    $accessSeries = [['name' => 'Akses per user', 'color' => '#005b96', 'values' => $available->map(fn ($item) => ($item->summary['total_clients'] ?? 0) > 0 ? round(($item->summary['total_logs'] ?? 0) / $item->summary['total_clients'], 1) : 0)->values()->all()]];
    $scoreSeries = [
        ['name' => 'Risiko', 'color' => '#a3262e', 'values' => $available->map(fn ($item) => (float) ($item->unit_risk['score'] ?? 0))->values()->all()],
        ['name' => 'Habit', 'color' => '#ffd400', 'values' => $available->map(fn ($item) => (float) ($item->unit_habit['score'] ?? 0))->values()->all()],
    ];
    $statusSeries = [
        ['name' => 'Normal', 'color' => '#005b96', 'values' => $available->map(fn ($item) => (int) ($item->combined_distribution['normal'] ?? 0))->values()->all()],
        ['name' => 'Perlu perhatian', 'color' => '#e7ad00', 'values' => $available->map(fn ($item) => array_sum(array_intersect_key($item->combined_distribution, array_flip(['risk', 'habit', 'priority']))))->values()->all()],
    ];
    $trafficSeries = [
        ['name' => 'Upload', 'color' => '#005b96', 'values' => $available->map(fn ($item) => (int) ($item->summary['total_upload'] ?? 0))->values()->all()],
        ['name' => 'Download', 'color' => '#287fb5', 'values' => $available->map(fn ($item) => (int) ($item->summary['total_download'] ?? 0))->values()->all()],
    ];
@endphp

<div class="space-y-5">
    <section class="rounded-2xl bg-slate-950 p-6 text-white shadow-lg sm:p-7">
        <p class="text-[10px] font-bold uppercase tracking-[.2em] text-blue-300">Pembanding otomatis</p>
        <h2 class="mt-2 text-2xl font-extrabold sm:text-3xl">Bandingkan pola terbaru setiap unit</h2>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-300">Tidak ada upload di halaman ini. Hasil terbaru dari Analisis Baru akan masuk otomatis setelah proses selesai.</p>
    </section>

    <section class="comparison-filter-bar flex flex-col gap-3 rounded-2xl px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <strong>Unit yang dibandingkan</strong>
            <p>Tekan tombol mata untuk memasukkan atau mengeluarkan unit dari seluruh grafik.</p>
        </div>
        <div class="flex items-center gap-2">
            <span data-comparison-visible-count class="comparison-visible-count"></span>
            <button type="button" data-comparison-show-all class="comparison-show-all">Tampilkan semua</button>
        </div>
    </section>

    <section data-comparison-unit-grid class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach($items as $item)
            <article
                class="comparison-unit-card rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
                @if($item->batch) data-comparison-unit-card data-unit-label="{{ $item->unit->name }}" @endif
            >
                <div class="flex items-start justify-between gap-3">
                    <h3 class="text-sm font-extrabold leading-5 text-slate-900">{{ $item->unit->name }}</h3>
                    <div class="flex items-center gap-2">
                        @if($item->batch)
                            <button
                                type="button"
                                data-comparison-unit-toggle
                                data-unit-label="{{ $item->unit->name }}"
                                class="comparison-eye-toggle"
                                aria-label="Sembunyikan {{ $item->unit->name }} dari perbandingan"
                                aria-pressed="true"
                                title="Tampilkan atau sembunyikan unit"
                            >
                                <svg data-eye-open viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/>
                                    <circle cx="12" cy="12" r="2.7"/>
                                </svg>
                                <svg data-eye-closed viewBox="0 0 24 24" aria-hidden="true" hidden>
                                    <path d="m3 3 18 18M10.6 6.2A10 10 0 0 1 12 6c6 0 9.5 6 9.5 6a16 16 0 0 1-2.1 2.8M6.2 6.2C3.8 8 2.5 12 2.5 12s3.5 6 9.5 6a10 10 0 0 0 3-.5M9.9 9.9a3 3 0 0 0 4.2 4.2"/>
                                </svg>
                            </button>
                            <div data-comparison-unit-visible-controls class="flex items-center gap-2">
                                <x-explanation
                                    :title="'Ringkasan '.$item->unit->name"
                                    source="Ringkasan hasil analisis selesai terbaru pada unit ini."
                                    method="User dan event dihitung dari seluruh catatan log valid pada hasil terbaru unit."
                                    meaning="User menunjukkan jumlah pengguna atau perangkat yang terdeteksi. Event menunjukkan jumlah catatan aktivitas yang berhasil dibaca."
                                    action="Baca grafik Intensitas Akses untuk perbandingan yang dinormalisasi, lalu klik Lihat user untuk alasan per user."
                                />
                                <span class="rounded-full bg-emerald-50 px-2 py-1 text-[9px] font-extrabold text-emerald-700">SIAP</span>
                            </div>
                            <span data-comparison-unit-hidden-label hidden class="comparison-hidden-label">DISEMBUNYIKAN</span>
                        @else
                            <span class="rounded-full bg-slate-100 px-2 py-1 text-[9px] font-extrabold text-slate-500">KOSONG</span>
                        @endif
                    </div>
                </div>
                @if($item->batch)
                    <div data-comparison-unit-content>
                        <div class="mt-4 grid grid-cols-2 gap-3 text-xs">
                            <div><span class="text-slate-400">User</span><strong class="mt-1 block">{{ number_format($item->summary['total_clients'] ?? 0) }}</strong></div>
                            <div><span class="text-slate-400">Event</span><strong class="mt-1 block">{{ number_format($item->summary['total_logs'] ?? 0) }}</strong></div>
                        </div>
                        <div class="mt-4 flex gap-2">
                            <a href="{{ route('analysis.show', $item->batch) }}" class="rounded-lg border border-slate-200 px-3 py-2 text-[11px] font-bold text-slate-700">Hasil</a>
                            <button type="button" data-load-comparison-clients data-url="{{ route('comparisons.clients', $item->unit) }}" class="rounded-lg bg-blue-50 px-3 py-2 text-[11px] font-bold text-blue-700">Lihat user</button>
                        </div>
                    </div>
                @else
                    <p class="mt-3 text-xs leading-5 text-slate-500">Menunggu hasil dari Analisis Baru. Unit ini akan terisi otomatis setelah analisis selesai.</p>
                @endif
            </article>
        @endforeach
    </section>

    <div data-comparison-charts class="grid gap-5 xl:grid-cols-2">
        <x-traffic-chart title="Intensitas akses" subtitle="Rata-rata akses per user agar perbedaan ukuran unit tetap adil." :labels="$labels" :series="$accessSeries" unit="akses/client" />
        <x-traffic-chart title="Risiko dan anomali habit" subtitle="Dua skor dipisahkan agar mudah dibaca." :labels="$labels" :series="$scoreSeries" unit="score" />
        <x-traffic-chart title="Komposisi status user" subtitle="User normal dibandingkan dengan user yang perlu diperiksa." :labels="$labels" :series="$statusSeries" unit="client" />
        <x-traffic-chart title="Arah traffic" subtitle="Total upload dan download hasil terbaru." :labels="$labels" :series="$trafficSeries" unit="byte" />
    </div>

    <section id="comparison-client-panel" class="hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-live="polite">
        <div id="comparison-client-content" class="p-5"></div>
    </section>
</div>
@endsection
