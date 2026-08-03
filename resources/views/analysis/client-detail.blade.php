@extends('layouts.app')

@section('title', 'Detail User')
@section('header', 'Detail User')
@section('subtitle', 'Aktivitas, risiko, dan pola penggunaan')

@section('content')
@php
    $riskLevels = ['medium', 'elevated', 'high', 'critical'];
    $riskTone = match($riskAssessment['level']) {
        'high' => ['text' => 'text-red-600', 'soft' => 'bg-red-50 text-red-700 border-red-100', 'hex' => '#a3262e'],
        'medium' => ['text' => 'text-amber-600', 'soft' => 'bg-amber-50 text-amber-700 border-amber-100', 'hex' => '#ffd400'],
        default => ['text' => 'text-emerald-600', 'soft' => 'bg-emerald-50 text-emerald-700 border-emerald-100', 'hex' => '#005b96'],
    };
    $formatBytes = function ($bytes) {
        $bytes = (float) $bytes;
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 1).' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 1).' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 1).' KB';
        return number_format($bytes).' B';
    };
    $totalTraffic = max(0, (int) $summary['total_upload'] + (int) $summary['total_download']);
    $deniedRate = $summary['total_access'] > 0 ? ($summary['denied_count'] / $summary['total_access']) * 100 : 0;
    $riskEventRate = $summary['total_access'] > 0 ? ($summary['risky_count'] / $summary['total_access']) * 100 : 0;
    $averageTraffic = $summary['total_access'] > 0 ? $totalTraffic / $summary['total_access'] : 0;
    $peakHour = $hourlyActivity->sortByDesc('total')->first();
    $clientTrendLabels = $clientTrend->pluck('period')->map(fn ($date) => \Carbon\Carbon::parse($date)->format('d M'))->all();
    $clientTrendSeries = [['name' => 'Total event', 'values' => $clientTrend->pluck('total')->map(fn ($value) => (int) $value)->all()]];
    $hourlySeries = [['name' => 'Jumlah event', 'values' => $hourlyActivity->pluck('total')->all(), 'color' => '#005b96']];
    $clientAppSeries = [['name' => 'Jumlah akses', 'values' => $topApps->pluck('total_access')->map(fn ($value) => (int) $value)->all()]];
    $destinationSeries = [['name' => 'Jumlah akses', 'values' => $topDestinations->pluck('total_access')->map(fn ($value) => (int) $value)->all()]];
    $riskEventSeries = [
        ['name' => 'Critical', 'values' => [(int) $summary['critical_count']], 'color' => '#991b1b'],
        ['name' => 'High', 'values' => [(int) $summary['high_count']], 'color' => '#a3262e'],
        ['name' => 'Elevated', 'values' => [(int) $summary['elevated_count']], 'color' => '#ffd400'],
        ['name' => 'Medium', 'values' => [(int) $summary['medium_count']], 'color' => '#facc15'],
    ];
    $actionSeries = $clientActions->map(fn ($action) => [
        'name' => ucfirst($action->action),
        'values' => [(int) $action->total],
        'color' => strtolower($action->action) === 'deny' ? '#a3262e' : '#005b96',
    ])->all();
    $trafficSeries = [
        ['name' => 'Upload', 'values' => [(int) $summary['total_upload']], 'color' => '#005b96'],
        ['name' => 'Download', 'values' => [(int) $summary['total_download']], 'color' => '#287fb5'],
    ];
    $components = [
        ['Eksposur aplikasi', $riskAssessment['components']['application_exposure'], 60, 'Bobot critical, high, elevated, dan medium.'],
        ['Aktivitas ditolak', $riskAssessment['components']['denied_activity'], 25, 'Kontribusi berdasarkan rasio koneksi deny.'],
        ['Pengulangan event', $riskAssessment['components']['repetition'], 15, 'Pola high/critical yang terjadi berulang.'],
    ];
    $habitComponents = [
        ['Luar jam kerja', $habitAssessment['components']['off_hours'], 30, 'Senin–Jumat di luar 08.00–17.00 dan akhir pekan.'],
        ['Deviasi traffic', $habitAssessment['components']['traffic_deviation'], 20, 'Traffic per event dibanding baseline unit.'],
        ['Action perhatian', $habitAssessment['components']['attention_actions'], 15, 'Deny berbobot penuh; timeout/reset berbobot lebih rendah.'],
        ['Port nonstandar', $habitAssessment['components']['nonstandard_ports'], 15, 'Aktif bila ada indikator waktu atau action pendamping.'],
        ['Sebaran jam aktif', $habitAssessment['components']['active_hours_spread'], 10, 'Aktivitas tersebar melampaui jam normal.'],
        ['Keragaman akses', $habitAssessment['components']['unusual_diversity'], 10, 'Aplikasi dan destination dibanding baseline unit.'],
    ];
    $userMetricHelp = [
        'Total event' => ['Seluruh event valid yang terhubung dengan identitas user ini.', 'Setiap event dihitung satu kali.', 'Besarnya aktivitas user, bukan jumlah ancaman.', 'Bandingkan dengan periode dan user lain pada unit yang sama.'],
        'Total traffic' => ['Nilai sentbyte dan rcvdbyte milik user.', 'Upload dan download dijumlahkan; rata-rata dibagi total event.', 'Banyaknya data yang dikirim dan diterima user.', 'Periksa bila volumenya jauh di atas kebutuhan kerja atau rata-rata unit.'],
        'Koneksi ditolak' => ['Event dengan action deny.', 'Jumlah deny dibagi seluruh event untuk mendapatkan persentase.', 'Koneksi yang diblokir policy. Deny berulang lebih penting daripada satu deny.', 'Lihat aplikasi dan destination yang paling sering ditolak.'],
        'Event berisiko' => ['Event dengan apprisk medium, elevated, high, atau critical.', 'Seluruh event berlevel tersebut dihitung lalu dibagi total aktivitas.', 'Ada aplikasi berlevel risiko, tetapi belum berarti user melakukan pelanggaran.', 'Utamakan high/critical yang berulang dan cocokkan dengan fungsi user.'],
    ];
@endphp

<div class="space-y-6" data-client-profile>
    <section class="relative overflow-hidden rounded-3xl bg-[#0b1220] p-6 text-white shadow-xl shadow-slate-300/40 sm:p-8">
        <div class="absolute -right-16 -top-24 h-72 w-72 rounded-full bg-blue-500/20 blur-3xl"></div>
        <div class="absolute -bottom-28 right-1/3 h-56 w-56 rounded-full bg-cyan-400/10 blur-3xl"></div>
        <div class="relative flex flex-col gap-7 xl:flex-row xl:items-center xl:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-[10px] font-bold uppercase tracking-[0.2em] text-cyan-300">Ringkasan user</span>
                    <span class="rounded-full border px-2.5 py-1 text-[10px] font-extrabold {{ $riskTone['soft'] }}">RISIKO {{ strtoupper($riskAssessment['label']) }}</span>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <h2 class="break-all font-mono text-2xl font-extrabold tracking-tight sm:text-3xl">{{ $identity['value'] }}</h2>
                    <button type="button" data-copy-ip="{{ $identity['value'] }}" class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-bold text-slate-300 transition hover:bg-white/10 hover:text-white">Salin</button>
                </div>
                <p class="mt-3 text-sm text-slate-400">
                    {{ strtoupper($identity['type']) }} · {{ $identity['name'] ?: 'Tanpa nama tambahan' }}
                    <span class="mx-2 text-slate-600">•</span>
                    {{ $identity['interface'] ?: 'Interface tidak tercatat' }}
                    <span class="mx-2 text-slate-600">•</span>
                    {{ $batch->label ?: $batch->original_filename }}
                </p>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:flex">
                <div class="min-w-32 rounded-2xl border border-white/10 bg-white/[.06] p-4">
                    <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Aktif sejak</div>
                    <div class="mt-2 text-sm font-extrabold text-white">{{ $summary['first_access_at'] ? \Carbon\Carbon::parse($summary['first_access_at'])->format('d M Y') : '-' }}</div>
                </div>
                <div class="min-w-32 rounded-2xl border border-white/10 bg-white/[.06] p-4">
                    <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Terakhir aktif</div>
                    <div class="mt-2 text-sm font-extrabold text-white">{{ $summary['last_access_at'] ? \Carbon\Carbon::parse($summary['last_access_at'])->format('d M Y H:i') : '-' }}</div>
                </div>
                <a class="col-span-2 flex items-center justify-center rounded-2xl bg-white px-5 py-3 text-sm font-extrabold text-slate-900 transition hover:bg-blue-50 sm:col-span-1" href="{{ route('analysis.show', $batch) }}">Kembali ke dashboard</a>
            </div>
        </div>
    </section>

    <nav class="app-panel flex gap-2 overflow-x-auto p-2" aria-label="Bagian profil user">
        <button type="button" data-profile-tab="overview" class="profile-tab is-active">Gambaran</button>
        <button type="button" data-profile-tab="behavior" class="profile-tab">Perilaku & tujuan</button>
        <button type="button" data-profile-tab="logs" class="profile-tab">Log aktivitas <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px]">{{ number_format($recentLogs->total()) }}</span></button>
    </nav>

    <div data-profile-panel="overview" class="space-y-6">
        <section class="app-panel p-5 sm:p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div><p class="text-[10px] font-bold uppercase tracking-[.18em] text-blue-600">Identitas yang digunakan</p><h3 class="mt-1 text-lg font-extrabold text-slate-900">{{ $identity['value'] }}</h3><p class="mt-1 text-xs text-slate-500">Sistem memilih data terbaik yang tersedia: user, nama perangkat, server, lalu IP.</p></div>
                <div class="flex flex-wrap gap-2">
                    @forelse($identity['ips'] as $ipRow)
                        <span class="rounded-xl bg-slate-100 px-3 py-2 font-mono text-xs font-bold text-slate-600">{{ $ipRow['ip'] }} <small class="text-slate-400">{{ number_format($ipRow['total']) }}×</small></span>
                    @empty
                        <span class="rounded-xl bg-slate-100 px-3 py-2 font-mono text-xs font-bold text-slate-600">{{ $identity['value'] }}</span>
                    @endforelse
                </div>
            </div>
        </section>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach([
                ['Total event', number_format($summary['total_access']), 'Seluruh aktivitas user', '#005b96'],
                ['Total traffic', $formatBytes($totalTraffic), $formatBytes($averageTraffic).' per event', '#287fb5'],
                ['Koneksi ditolak', number_format($summary['denied_count']), number_format($deniedRate, 1).'% dari aktivitas', '#ffd400'],
                ['Event berisiko', number_format($summary['risky_count']), number_format($riskEventRate, 1).'% dari aktivitas', $riskTone['hex']],
            ] as [$label, $value, $note, $accent])
                <div class="metric-card" style="--metric-accent: {{ $accent }}">
                    <div class="flex items-start justify-between gap-2">
                        <div class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">{{ $label }}</div>
                        <x-explanation
                            :title="$label"
                            :source="$userMetricHelp[$label][0]"
                            :method="$userMetricHelp[$label][1]"
                            :meaning="$userMetricHelp[$label][2]"
                            :action="$userMetricHelp[$label][3]"
                        />
                    </div>
                    <div class="mt-3 text-2xl font-extrabold tracking-tight text-slate-900">{{ $value }}</div>
                    <div class="mt-2 text-xs font-medium text-slate-500">{{ $note }}</div>
                </div>
            @endforeach
        </div>

        <div class="grid gap-6 xl:grid-cols-[1.15fr_.85fr]">
            <section class="app-panel p-6 sm:p-7">
                <div class="flex flex-col gap-6 sm:flex-row sm:items-center">
                    <div class="risk-gauge shrink-0" style="--risk-value: {{ $riskAssessment['score'] }}; --risk-color: {{ $riskTone['hex'] }}">
                        <div><strong>{{ number_format($riskAssessment['score'], 1) }}</strong><span>/100</span></div>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex justify-end">
                            <x-explanation
                                title="Skor risiko user"
                                source="Level apprisk, action deny, pengulangan high/critical, dan jumlah event user."
                                method="Eksposur aplikasi maksimal 60 poin, deny 25 poin, dan pengulangan 15 poin. Skor kemudian disesuaikan dengan keyakinan berdasarkan jumlah sampel."
                                meaning="Nilai 0–29 rendah, 30–59 sedang, dan 60–100 tinggi. Skor menunjukkan prioritas pemeriksaan, bukan vonis."
                                action="Baca komponen yang menyumbang poin paling besar, lalu cocokkan dengan aplikasi, action, waktu, dan tujuan user."
                            />
                        </div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-blue-600">Kenapa skornya {{ strtolower($riskAssessment['label']) }}?</p>
                        <h3 class="mt-2 text-xl font-extrabold text-slate-900">Rincian skor risiko</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ $riskAssessment['reason'] }}. Skor ini dihitung dari {{ number_format($riskAssessment['sample_size']) }} event dengan tingkat keyakinan {{ number_format($riskAssessment['components']['confidence'], 0) }}%.</p>
                    </div>
                </div>
                <div class="mt-7 space-y-5">
                    @foreach($components as [$label, $value, $maximum, $description])
                        <div>
                            <div class="flex items-center justify-between gap-4"><div><span class="text-sm font-bold text-slate-800">{{ $label }}</span><p class="mt-0.5 text-xs text-slate-400">{{ $description }}</p></div><span class="font-mono text-sm font-extrabold text-slate-700">{{ number_format($value, 1) }}<span class="text-slate-400">/{{ $maximum }}</span></span></div>
                            <div class="mt-2.5 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full" style="width: {{ min(100, ($value / $maximum) * 100) }}%; background: {{ $riskTone['hex'] }}"></div></div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="app-panel p-6 sm:p-7">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-violet-600">Dibanding user lain</p>
                    <x-explanation
                        title="Persentil user"
                        source="Skor risiko seluruh user pada unit dan hasil analisis yang sama."
                        method="Skor user diurutkan dari rendah ke tinggi. Persentil menunjukkan berapa persen user yang skornya lebih rendah atau sama."
                        meaning="Persentil 90 berarti skor user ini lebih tinggi atau setara dari sekitar 90% user unit, bukan berarti tingkat kesalahan 90%."
                        action="Gunakan untuk menentukan urutan pemeriksaan, lalu tetap baca alasan dan konteks kerja user."
                    />
                </div>
                <h3 class="mt-2 text-xl font-extrabold text-slate-900">Posisi di unit ini</h3>
                @if($peerComparison['risk_percentile'])
                    <div class="mt-6 rounded-2xl bg-slate-950 p-5 text-white">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Persentil risiko</div>
                        <div class="mt-2 flex items-end gap-2"><span class="text-4xl font-extrabold">{{ $peerComparison['risk_percentile'] }}</span><span class="mb-1 text-sm text-slate-400">dari 100</span></div>
                        <p class="mt-3 text-xs leading-5 text-slate-400">Skor user ini lebih tinggi atau setara dibanding sekitar {{ $peerComparison['risk_percentile'] }}% user pada unit yang sama.</p>
                    </div>
                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <div class="rounded-2xl bg-slate-50 p-4"><div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Rata-rata unit</div><div class="mt-2 text-xl font-extrabold text-slate-900">{{ number_format($peerComparison['average_risk'], 1) }}</div><div class="text-xs text-slate-400">skor risiko</div></div>
                        <div class="rounded-2xl bg-slate-50 p-4"><div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Populasi</div><div class="mt-2 text-xl font-extrabold text-slate-900">{{ number_format($peerComparison['total_clients']) }}</div><div class="text-xs text-slate-400">user dibandingkan</div></div>
                    </div>
                @else
                    <p class="mt-5 rounded-2xl bg-slate-50 p-5 text-sm leading-6 text-slate-500">Data pembanding belum tersedia untuk unit ini.</p>
                @endif
            </section>
        </div>

        <section class="app-panel p-6 sm:p-7">
            <div class="grid gap-7 xl:grid-cols-[300px_1fr] xl:items-start">
                <div>
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-[10px] font-bold uppercase tracking-[.18em] text-violet-600">Skor pola penggunaan</p>
                        <x-explanation
                            title="Skor anomali habit"
                            source="Jam akses, traffic per event, deny/timeout/reset, port, aplikasi, destination, dan baseline unit."
                            method="Luar jam kerja 30%, deviasi traffic 20%, action perhatian 15%, port nonstandar 15%, sebaran jam 10%, dan keragaman akses 10%."
                            meaning="Nilai tinggi berarti kebiasaan user berbeda dari pola umum unit. Perbedaan dapat tetap sah karena shift, backup, atau tugas khusus."
                            action="Periksa penyumbang skor terbesar dan konfirmasi dengan jadwal serta tanggung jawab user."
                        />
                    </div>
                    <div class="mt-4 flex items-center gap-5">
                    <div class="risk-gauge shrink-0" style="--risk-value: {{ $habitAssessment['score'] }}; --risk-color: #ffd400"><div><strong>{{ number_format($habitAssessment['score'], 1) }}</strong><span>/100</span></div></div>
                        <div><span class="rounded-full bg-violet-50 px-3 py-1 text-[10px] font-extrabold text-violet-700">{{ $combinedAssessment['label'] }}</span><p class="mt-3 text-sm leading-6 text-slate-600">{{ $combinedAssessment['reason'] }}</p></div>
                    </div>
                    <p class="mt-5 text-sm leading-6 text-slate-500">{{ $habitAssessment['reason'] }}.</p>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach($habitComponents as [$label, $value, $maximum, $description])
                        <div class="rounded-2xl border border-slate-200 p-4">
                            <div class="flex items-center justify-between gap-3"><strong class="text-sm text-slate-800">{{ $label }}</strong><span class="font-mono text-xs font-extrabold text-violet-700">{{ number_format($value, 1) }}/{{ $maximum }}</span></div>
                            <p class="mt-1 text-[10px] leading-4 text-slate-400">{{ $description }}</p>
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-violet-600" style="width: {{ min(100, ($value / $maximum) * 100) }}%"></div></div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="app-panel p-6 sm:p-7">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-xl">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-cyan-600">Kebiasaan user</p>
                        <x-explanation
                            title="Ringkasan penggunaan user"
                            source="Seluruh event user: timestamp, aplikasi, destination, negara, dan service."
                            method="Sistem mencari jam dengan event terbanyak, aplikasi dan tujuan paling sering, serta jumlah kategori unik."
                            meaning="Bagian ini menggambarkan kebiasaan utama user agar skor dapat dibaca dalam konteks."
                            action="Pastikan pola dominan sesuai pekerjaan; buka tab Perilaku & tujuan untuk rincian."
                        />
                    </div>
                    <h3 class="mt-2 text-xl font-extrabold text-slate-900">Ringkasan penggunaan</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Diambil dari seluruh event user pada file ini.</p>
                </div>
                <div class="grid flex-1 gap-3 sm:grid-cols-2 xl:max-w-4xl xl:grid-cols-4">
                    <div class="behavior-insight"><span>Waktu teraktif</span><strong>{{ str_pad($peakHour->hour ?? 0, 2, '0', STR_PAD_LEFT) }}:00–{{ str_pad((($peakHour->hour ?? 0) + 1) % 24, 2, '0', STR_PAD_LEFT) }}:00</strong><small>{{ number_format($peakHour->total ?? 0) }} event</small></div>
                    <div class="behavior-insight"><span>Aplikasi dominan</span><strong>{{ $topApps->first()->app ?? '-' }}</strong><small>{{ number_format($topApps->first()->total_access ?? 0) }} akses</small></div>
                    <div class="behavior-insight"><span>Tujuan utama</span><strong class="font-mono">{{ $topDestinations->first()->dstip ?? '-' }}</strong><small>{{ $topDestinations->first()->dstcountry ?? 'Negara tidak tercatat' }}</small></div>
                    <div class="behavior-insight"><span>Keragaman akses</span><strong>{{ number_format($behavior->unique_apps ?? 0) }} aplikasi</strong><small>{{ number_format($behavior->unique_countries ?? 0) }} negara tujuan</small></div>
                </div>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-traffic-chart title="Tren aktivitas user" subtitle="Perubahan frekuensi event harian." type="line" :labels="$clientTrendLabels" :series="$clientTrendSeries" />
            <x-traffic-chart title="Pola aktivitas per jam" subtitle="Jam dengan aktivitas paling padat sepanjang hari." :labels="collect(range(0, 23))->map(fn ($hour) => str_pad($hour, 2, '0', STR_PAD_LEFT).':00')->all()" :series="$hourlySeries" />
            <x-traffic-chart title="Komposisi event risiko" subtitle="Distribusi level risiko aplikasi yang teramati." type="donut" :labels="['Risiko']" :series="$riskEventSeries" unit="event" />
            <x-traffic-chart title="Action koneksi" subtitle="Perbandingan koneksi diterima dan ditolak." type="donut" :labels="['Action']" :series="$actionSeries" unit="event" />
        </div>

        @if($clientOutliers->isNotEmpty())
            <section class="app-panel overflow-hidden">
                <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
                    <div><p class="text-[10px] font-bold uppercase tracking-wider text-fuchsia-600">Aktivitas menonjol</p><h3 class="mt-1 font-extrabold text-slate-900">Event user yang paling berbeda</h3></div>
                    <x-explanation
                        title="Event yang paling berbeda"
                        source="Traffic, durasi, port, service, destination, policy, dan waktu pada event user."
                        method="Event ekstrem dipilih dari volume traffic atau durasi yang paling jauh menonjol dibanding aktivitas user lainnya."
                        meaning="Outlier adalah aktivitas yang tidak biasa, bukan otomatis ancaman."
                        action="Periksa apakah event sesuai transfer file, backup, maintenance, atau proses resmi lainnya."
                    />
                </div>
                <div class="divide-y divide-slate-100">
                    @foreach($clientOutliers as $outlier)
                        <div class="grid gap-3 px-6 py-4 text-xs md:grid-cols-[150px_1fr_140px_120px] md:items-center"><div><strong class="text-fuchsia-700">{{ $outlier['type'] }}</strong><p class="mt-1 font-mono text-slate-400">{{ $outlier['logged_at'] }}</p></div><div><strong class="text-slate-800">{{ $outlier['app'] }}</strong><p class="mt-1 text-slate-400">{{ $outlier['dstip'] }}:{{ $outlier['dstport'] }} · {{ $outlier['policyname'] ?: '-' }}</p></div><div class="font-mono font-bold text-slate-700">{{ number_format($outlier['total_bytes']) }} B</div><span class="rounded-lg px-2 py-1 text-center font-bold {{ $outlier['period'] === 'Jam kerja' ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700' }}">{{ $outlier['period'] }}</span></div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>

    <div data-profile-panel="behavior" class="hidden space-y-6">
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach([
                ['Aplikasi', $behavior->unique_apps ?? 0, 'Jenis aplikasi teramati'],
                ['Destination', $behavior->unique_destinations ?? 0, 'IP tujuan unik'],
                ['Negara', $behavior->unique_countries ?? 0, 'Negara tujuan unik'],
                ['Policy', $behavior->unique_policies ?? 0, 'Policy yang terpakai'],
            ] as [$label, $value, $note])
                <div class="metric-card">
                    <div class="flex items-start justify-between gap-2">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">{{ $label }}</div>
                        <x-explanation
                            :title="$label.' user'"
                            source="Ringkasan aplikasi, destination, negara, dan policy pada seluruh event user."
                            method="Nilai unik yang kosong dibuang, lalu setiap nilai berbeda dihitung satu kali."
                            meaning="Angka menunjukkan keragaman akses user, bukan tingkat bahaya."
                            action="Bandingkan daftar teratas dengan kebutuhan kerja dan skor habit user."
                        />
                    </div>
                    <div class="mt-2 text-2xl font-extrabold text-slate-900">{{ number_format($value) }}</div>
                    <div class="mt-1 text-xs text-slate-500">{{ $note }}</div>
                </div>
            @endforeach
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-traffic-chart title="Aplikasi utama user" subtitle="Aplikasi dengan frekuensi akses tertinggi." :labels="$topApps->pluck('app')->all()" :series="$clientAppSeries" />
            <x-traffic-chart title="Tujuan utama user" subtitle="Destination IP dengan frekuensi tertinggi." :labels="$topDestinations->pluck('dstip')->all()" :series="$destinationSeries" />
            <x-traffic-chart title="Arah traffic" subtitle="Perbandingan total data keluar dan masuk." type="donut" :labels="['Traffic']" :series="$trafficSeries" unit="byte" />
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <section class="app-panel overflow-hidden">
                <div class="border-b border-slate-200 px-6 py-5"><p class="text-[10px] font-bold uppercase tracking-wider text-blue-600">Application footprint</p><h3 class="mt-1 font-extrabold text-slate-900">Aplikasi teratas</h3></div>
                <div class="divide-y divide-slate-100">
                    @forelse($topApps as $app)
                        <div class="flex items-center gap-4 px-6 py-4">
                            <div class="min-w-0 flex-1"><div class="truncate font-bold text-slate-900">{{ $app->app }}</div><div class="mt-1 text-xs text-slate-400">{{ $app->appcat ?: 'Kategori tidak tercatat' }} • {{ number_format($app->total_access) }} akses</div></div>
                            <span class="rounded-full px-2.5 py-1 text-[10px] font-extrabold {{ in_array(strtolower($app->apprisk ?? ''), $riskLevels) ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-600' }}">{{ strtoupper($app->apprisk ?: 'unknown') }}</span>
                            <div class="hidden text-right text-xs text-slate-500 sm:block"><div>↑ {{ $formatBytes($app->total_upload) }}</div><div>↓ {{ $formatBytes($app->total_download) }}</div></div>
                        </div>
                    @empty
                        <div class="px-6 py-10 text-center text-sm text-slate-400">Tidak ada data aplikasi.</div>
                    @endforelse
                </div>
            </section>

            <section class="app-panel overflow-hidden">
                <div class="border-b border-slate-200 px-6 py-5"><p class="text-[10px] font-bold uppercase tracking-wider text-cyan-600">Destination footprint</p><h3 class="mt-1 font-extrabold text-slate-900">Tujuan teratas</h3></div>
                <div class="divide-y divide-slate-100">
                    @forelse($topDestinations as $destination)
                        <div class="flex items-center gap-4 px-6 py-4">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-xs font-extrabold text-slate-500">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</div>
                            <div class="min-w-0 flex-1"><div class="truncate font-mono font-bold text-slate-900">{{ $destination->dstip }}</div><div class="mt-1 text-xs text-slate-400">{{ $destination->dstcountry ?: 'Negara tidak tercatat' }} • {{ $destination->service ?: 'Service unknown' }}</div></div>
                            <div class="text-right"><div class="font-mono text-sm font-bold text-slate-700">{{ number_format($destination->total_access) }}</div><div class="text-[10px] text-slate-400">akses</div></div>
                        </div>
                    @empty
                        <div class="px-6 py-10 text-center text-sm text-slate-400">Tidak ada data tujuan.</div>
                    @endforelse
                </div>
            </section>
        </div>
    </div>

    <div data-profile-panel="logs" class="hidden space-y-5">
        @if($batch->raw_purged_at)
            <div class="rounded-2xl border border-blue-100 bg-blue-50 px-5 py-4 text-sm leading-6 text-blue-800">
                Log mentah sudah dibersihkan setelah ringkasan berhasil dibuat. Grafik, skor, pola waktu, aplikasi, dan tujuan utama user tetap tersedia dari ringkasan analisis.
            </div>
        @endif
        <section class="app-panel overflow-hidden" data-log-explorer>
            <div class="border-b border-slate-200 p-5 sm:p-6">
                <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div><p class="text-[10px] font-bold uppercase tracking-wider text-blue-600">Log terbaru</p><h3 class="mt-1 text-xl font-extrabold text-slate-900">Cari aktivitas user</h3><p class="mt-1 text-sm text-slate-500">Pencarian berlaku untuk 50 event yang sedang tampil.</p></div>
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <label class="relative block">
                            <span class="sr-only">Cari aktivitas</span>
                            <input data-log-search type="search" placeholder="Cari aplikasi, IP, policy…" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-100 sm:w-72">
                        </label>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" data-log-filter="all" class="log-filter is-active">Semua</button>
                            <button type="button" data-log-filter="deny" class="log-filter">Ditolak</button>
                            <button type="button" data-log-filter="risky" class="log-filter">Berisiko</button>
                            <button type="button" data-log-filter="accept" class="log-filter">Diterima</button>
                        </div>
                    </div>
                </div>
                <div class="mt-4 flex items-center justify-between text-xs text-slate-400"><span><strong data-visible-log-count class="text-slate-700">{{ $recentLogs->count() }}</strong> event terlihat</span><span>{{ number_format($recentLogs->total()) }} total event</span></div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-[1100px] divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-[10px] uppercase tracking-[0.12em] text-slate-500"><tr><th class="px-5 py-3">Waktu</th><th class="px-5 py-3">Aplikasi</th><th class="px-5 py-3">Tujuan</th><th class="px-5 py-3">Action</th><th class="px-5 py-3">Policy</th><th class="px-5 py-3">Risk</th><th class="px-5 py-3">Traffic</th><th class="px-5 py-3">Durasi</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($recentLogs as $log)
                            @php
                                $logRisk = strtolower($log->apprisk ?? '');
                                $logAction = strtolower($log->action ?? '');
                                $isRisky = in_array($logRisk, $riskLevels);
                                $searchText = strtolower(implode(' ', [$log->app, $log->service, $log->dstip, $log->dstcountry, $log->policyname, $log->action, $log->apprisk]));
                            @endphp
                            <tr data-log-row data-action="{{ $logAction }}" data-risky="{{ $isRisky ? 'true' : 'false' }}" data-search="{{ $searchText }}" class="transition hover:bg-blue-50/40">
                                <td class="whitespace-nowrap px-5 py-4 font-mono text-xs text-slate-600">{{ $log->logged_at ?: '-' }}</td>
                                <td class="px-5 py-4"><div class="font-bold text-slate-900">{{ $log->app ?: '-' }}</div><div class="mt-1 text-xs text-slate-400">{{ $log->service ?: '-' }}</div></td>
                                <td class="px-5 py-4"><div class="font-mono text-slate-700">{{ $log->dstip ?: '-' }}:{{ $log->dstport ?: '-' }}</div><div class="mt-1 text-xs text-slate-400">{{ $log->dstcountry ?: '-' }}</div></td>
                                <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-[10px] font-extrabold {{ $logAction === 'deny' ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700' }}">{{ strtoupper($log->action ?: 'unknown') }}</span></td>
                                <td class="max-w-48 truncate px-5 py-4 text-slate-600" title="{{ $log->policyname }}">{{ $log->policyname ?: '-' }}</td>
                                <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-[10px] font-extrabold {{ $isRisky ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-600' }}">{{ strtoupper($log->apprisk ?: 'unknown') }}</span></td>
                                <td class="whitespace-nowrap px-5 py-4 text-xs text-slate-500"><div>↑ {{ $formatBytes($log->sentbyte) }}</div><div>↓ {{ $formatBytes($log->rcvdbyte) }}</div></td>
                                <td class="whitespace-nowrap px-5 py-4 font-mono text-xs text-slate-600">{{ number_format($log->duration) }} dtk</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-5 py-12 text-center text-slate-400">Tidak ada log untuk user ini.</td></tr>
                        @endforelse
                        <tr data-log-empty class="hidden"><td colspan="8" class="px-5 py-12 text-center"><div class="font-bold text-slate-700">Tidak ada event yang cocok</div><div class="mt-1 text-sm text-slate-400">Ubah kata pencarian atau pilih filter lain.</div></td></tr>
                    </tbody>
                </table>
            </div>
            @if($recentLogs->hasPages())<div class="border-t border-slate-200 px-6 py-4">{{ $recentLogs->withQueryString()->links() }}</div>@endif
        </section>
    </div>
</div>
@endsection
