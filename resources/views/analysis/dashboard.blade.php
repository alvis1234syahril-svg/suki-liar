@extends('layouts.app')

@section('title', 'Hasil Analisis')
@section('header', 'Hasil Analisis')
@section('subtitle', 'Risiko dan kebiasaan user')

@section('content')
@php
    $isRunning = in_array($batch->status, ['uploaded', 'processing', 'summarizing']);
    $statusLabel = match($batch->status) {
        'completed' => 'Selesai',
        'uploaded' => 'Menunggu',
        'processing' => 'Diproses',
        'summarizing' => 'Menyiapkan hasil',
        'failed' => 'Gagal',
        default => ucfirst($batch->status),
    };
    $progress = $batch->file_size > 0 ? min(100, (int) round(($batch->processed_bytes / $batch->file_size) * 100)) : 0;
    $dailyLabels = $activityTrend->pluck('period')->map(fn ($date) => \Carbon\Carbon::parse($date)->format('d M'))->all();
    $dailySeries = [['name' => 'Total event', 'values' => $activityTrend->pluck('total')->map(fn ($value) => (int) $value)->all()]];
    $riskSeries = [
        ['name' => 'Tinggi', 'values' => [(int) $riskDistribution['high']], 'color' => '#a3262e'],
        ['name' => 'Sedang', 'values' => [(int) $riskDistribution['medium']], 'color' => '#ffd400'],
        ['name' => 'Rendah', 'values' => [(int) $riskDistribution['low']], 'color' => '#005b96'],
    ];
    $habitSeries = [
        ['name' => 'Anomali tinggi', 'values' => [(int) $habitDistribution['high']], 'color' => '#a3262e'],
        ['name' => 'Anomali sedang', 'values' => [(int) $habitDistribution['medium']], 'color' => '#ffd400'],
        ['name' => 'Habit normal', 'values' => [(int) $habitDistribution['low']], 'color' => '#005b96'],
    ];
    $combinedSeries = [
        ['name' => 'Normal', 'values' => [(int) ($combinedDistribution['normal'] ?? 0)], 'color' => '#005b96'],
        ['name' => 'Risiko aplikasi', 'values' => [(int) ($combinedDistribution['risk'] ?? 0)], 'color' => '#ffd400'],
        ['name' => 'Anomali habit', 'values' => [(int) ($combinedDistribution['habit'] ?? 0)], 'color' => '#e7ad00'],
        ['name' => 'Prioritas tinggi', 'values' => [(int) ($combinedDistribution['priority'] ?? 0)], 'color' => '#a3262e'],
    ];
    $hourMap = $hourlyActivity->keyBy('hour');
    $hourLabels = collect(range(0, 23))->map(fn ($hour) => str_pad($hour, 2, '0', STR_PAD_LEFT).':00')->all();
    $hourlySeries = [['name' => 'Jumlah event', 'values' => collect(range(0, 23))->map(fn ($hour) => (int) ($hourMap->get($hour)->total ?? 0))->all(), 'color' => '#005b96']];
    $workSeries = [
        ['name' => 'Jam kerja', 'values' => [(int) ($summary['work_hours_count'] ?? 0)], 'color' => '#005b96'],
        ['name' => 'Luar jam kerja', 'values' => [(int) ($summary['off_hours_count'] ?? 0)], 'color' => '#ffd400'],
    ];
    $appSeries = [['name' => 'Jumlah akses', 'values' => $topApps->pluck('total')->map(fn ($value) => (int) $value)->all()]];
    $countrySeries = [['name' => 'Jumlah akses', 'values' => $topCountries->pluck('total')->map(fn ($value) => (int) $value)->all()]];
    $actionSeries = $actionDistribution->map(fn ($item) => ['name' => ucfirst($item->action), 'values' => [(int) $item->total]])->all();
    $trafficSeries = [
        ['name' => 'Upload', 'values' => [(int) ($summary['total_upload'] ?? 0)], 'color' => '#005b96'],
        ['name' => 'Download', 'values' => [(int) ($summary['total_download'] ?? 0)], 'color' => '#287fb5'],
    ];
    $metricHelp = [
        'Total event' => ['Semua baris log yang berhasil diparsing.', 'Setiap baris valid dihitung sebagai satu event.', 'Menunjukkan ukuran aktivitas yang dianalisis, bukan jumlah user.', 'Gunakan sebagai konteks saat membandingkan angka lain.'],
        'User terdeteksi' => ['Kolom user, srcname, srcserver, dan srcip.', 'Identitas dipilih dengan urutan user → srcname → srcserver → srcip; IP terkait digabung.', 'Satu user yang berpindah IP tetap dihitung sebagai satu identitas bila nama user tersedia.', 'Periksa detail identity bila jumlah terasa tidak sesuai.'],
        'Aplikasi berbeda' => ['Kolom app atau service pada log.', 'Nama aplikasi unik dihitung satu kali setelah nilai kosong dibuang.', 'Menunjukkan keragaman aplikasi, bukan jumlah pemakaian.', 'Buka grafik Top App untuk melihat aplikasi yang paling dominan.'],
        'Deny' => ['Kolom action pada setiap event.', 'Action yang bernilai deny dijumlahkan.', 'Koneksi ditolak oleh policy atau sistem. Deny sesekali belum tentu masalah.', 'Periksa deny berulang dari user dan tujuan yang sama.'],
        'Perlu dicek' => ['Skor risiko aplikasi setiap user.', 'User dihitung bila skor risiko mencapai 30 atau lebih.', 'Ada cukup indikator aplikasi/action berisiko untuk ditinjau.', 'Buka detail user dan baca alasan skornya sebelum menyimpulkan.'],
        'Pola tidak biasa' => ['Skor habit setiap user.', 'User dihitung bila skor habit mencapai 30 atau lebih.', 'Pola waktu, volume, action, port, atau tujuan berbeda dari kebiasaan umum.', 'Konfirmasi dengan jadwal dan tugas operasional user.'],
    ];
    $scoreHelp = [
        'Risiko aplikasi' => [
            'Kolom apprisk, action, deny, serta pengulangan high/critical.',
            'Komponen berbobot dijumlahkan menjadi skor 0–100; jumlah sampel memengaruhi keyakinan.',
            'Mengukur risiko teknis aplikasi dan koneksi, bukan niat user.',
            'Prioritaskan user dengan skor tinggi dan alasan event berulang.',
        ],
        'Pola tidak biasa' => [
            'Waktu akses, traffic, action, port, aplikasi, negara, dan destination.',
            'Enam komponen habit berbobot digabung menjadi skor 0–100.',
            'Mengukur seberapa berbeda perilaku user dari pola normal unit.',
            'Cocokkan dengan shift, backup, tugas khusus, dan aktivitas resmi.',
        ],
    ];
@endphp

<div class="space-y-5">
    @if(session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-800">{{ session('success') }}</div>@endif

    <section class="relative overflow-hidden rounded-[2rem] bg-[#0b1220] p-6 text-white shadow-2xl shadow-slate-300/50 sm:p-8">
        <div class="absolute -right-20 -top-28 h-72 w-72 rounded-full bg-blue-500/20 blur-3xl"></div>
        <div class="relative flex flex-col gap-8 xl:flex-row xl:items-center xl:justify-between">
            <div class="max-w-3xl">
                <div class="flex flex-wrap items-center gap-3"><span class="rounded-full border border-white/10 bg-white/10 px-3 py-1 text-[10px] font-bold uppercase tracking-[.18em] text-blue-200">Ringkasan analisis</span><span class="rounded-full bg-emerald-400/15 px-3 py-1 text-xs font-bold text-emerald-300">● {{ $statusLabel }}</span></div>
                <h2 class="mt-4 text-2xl font-extrabold tracking-tight sm:text-3xl">{{ $batch->label ?: $batch->original_filename }}</h2>
                <p class="mt-3 text-sm text-slate-400">{{ $batch->original_filename }} · dianalisis {{ $batch->created_at->format('d M Y, H:i') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @foreach([
                    ['Risiko aplikasi', $unitRisk, 'text-amber-300'],
                    ['Pola tidak biasa', $unitHabit, 'text-violet-300'],
                ] as [$label, $assessment, $tone])
                    <div class="min-w-40 rounded-2xl border border-white/10 bg-white/[.07] p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="text-[10px] font-bold uppercase tracking-[.16em] text-slate-400">{{ $label }}</div>
                            <x-explanation :title="$label" :source="$scoreHelp[$label][0]" :method="$scoreHelp[$label][1]" :meaning="$scoreHelp[$label][2]" :action="$scoreHelp[$label][3]" />
                        </div>
                        <div class="mt-2 flex items-end gap-2"><span class="text-2xl font-extrabold {{ $assessment['level'] === 'low' ? 'text-emerald-300' : $tone }}">{{ strtoupper($assessment['label']) }}</span><span class="mb-1 text-xs text-slate-400">{{ number_format($assessment['score'], 1) }}/100</span></div>
                    </div>
                @endforeach
                <a href="{{ route('analysis.create') }}" class="rounded-2xl bg-white px-5 py-3 text-sm font-extrabold text-slate-900">+ Analisis baru</a>
            </div>
        </div>
    </section>

    @if($isRunning)
        <section id="progress-panel" data-status-url="{{ route('analysis.status', $batch) }}" class="rounded-2xl border border-blue-200 bg-blue-50 p-6">
            <div class="flex items-center justify-between gap-4"><div><h3 class="font-bold text-blue-950">Log sedang diproses</h3><p id="progress-message" class="mt-1 text-sm text-blue-700">Progress diperbarui langsung tanpa memuat ulang halaman.</p></div><span id="progress-count" class="font-mono text-sm font-bold text-blue-800">{{ number_format($batch->processed_lines) }} baris</span></div>
            <div class="mt-4 h-2 overflow-hidden rounded-full bg-blue-100"><div id="progress-bar" class="h-full rounded-full bg-blue-600" style="width: {{ max(3, $progress) }}%"></div></div>
        </section>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
        @foreach([
            ['Total event', $summary['total_logs'] ?? 0, 'Log terbaca', '#005b96'],
            ['User terdeteksi', $summary['total_clients'] ?? 0, 'user, perangkat, server, atau IP', '#287fb5'],
            ['Aplikasi berbeda', $summary['unique_apps'] ?? 0, 'aplikasi dan service', '#005b96'],
            ['Deny', $summary['total_denied'] ?? 0, 'Koneksi ditolak', '#ffd400'],
            ['Perlu dicek', $unitRisk['risky_clients'], 'skor risiko 30 atau lebih', '#a3262e'],
            ['Pola tidak biasa', $unitHabit['anomalous_clients'], 'skor habit 30 atau lebih', '#e7ad00'],
        ] as [$label, $value, $note, $accent])
            <div class="metric-card" style="--metric-accent: {{ $accent }}">
                <div class="flex items-start justify-between gap-2">
                    <div class="text-[10px] font-bold uppercase tracking-[.13em] text-slate-400">{{ $label }}</div>
                    <x-explanation :title="$label" :source="$metricHelp[$label][0]" :method="$metricHelp[$label][1]" :meaning="$metricHelp[$label][2]" :action="$metricHelp[$label][3]" />
                </div>
                <div class="mt-3 text-3xl font-extrabold text-slate-900">{{ number_format($value) }}</div>
                <div class="mt-2 text-xs text-slate-500">{{ $note }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <x-traffic-chart title="Tren aktivitas harian" subtitle="Jumlah event per hari." type="line" :labels="$dailyLabels" :series="$dailySeries" />
        <x-traffic-chart title="Status user" subtitle="Risiko aplikasi dan pola penggunaan dinilai terpisah." type="donut" :labels="['User']" :series="$combinedSeries" unit="user" />
        <x-traffic-chart title="Tingkat risiko aplikasi" subtitle="Dihitung dari level risiko, deny, dan event yang berulang." type="donut" :labels="['User']" :series="$riskSeries" unit="user" />
        <x-traffic-chart title="Pola penggunaan user" subtitle="Melihat waktu akses, volume, action, port, dan tujuan." type="donut" :labels="['User']" :series="$habitSeries" unit="user" />
        <x-traffic-chart title="Aktivitas per jam" subtitle="Jam berapa traffic paling ramai." :labels="$hourLabels" :series="$hourlySeries" />
        <x-traffic-chart title="Jam kerja vs luar jam" subtitle="Senin–Jumat pukul 08.00–17.00." type="donut" :labels="['Periode']" :series="$workSeries" unit="event" />
        <x-traffic-chart title="Aplikasi yang paling sering dipakai" subtitle="Nama aplikasi diambil dari data terbaik yang tersedia di log." :labels="$topApps->pluck('app')->all()" :series="$appSeries" />
        <x-traffic-chart title="Negara tujuan" subtitle="Negara yang paling sering muncul di traffic." :labels="$topCountries->pluck('dstcountry')->all()" :series="$countrySeries" />
        <x-traffic-chart title="Hasil koneksi" subtitle="Termasuk accept, deny, timeout, dan reset." type="donut" :labels="['Action']" :series="$actionSeries" unit="event" />
        <x-traffic-chart title="Arah traffic" subtitle="Total upload dan download." :labels="['Traffic unit']" :series="$trafficSeries" unit="byte" />
    </div>

    <section class="app-panel overflow-hidden">
        <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
            <div><h3 class="font-bold text-slate-900">Aktivitas tiap user</h3><p class="mt-1 text-sm text-slate-500">User yang sama tetap digabung meskipun sempat memakai IP berbeda.</p></div>
            <x-explanation title="Cara membaca tabel user" source="Ringkasan seluruh event yang sudah dikelompokkan berdasarkan identity user." method="Identity mengikuti user → srcname → srcserver → srcip. Akses, top app, skor risiko, dan skor habit dihitung per identity." meaning="Status adalah gabungan dua dimensi. Normal berarti risiko dan habit di bawah 30; status lain menunjukkan dimensi mana yang melewati ambang." action="Klik Detail pada user yang perlu dicek untuk melihat event, waktu, aplikasi, tujuan, serta alasan skor." />
        </div>
        <div class="overflow-x-auto"><table class="min-w-[1150px] divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-[10px] uppercase tracking-wider text-slate-500"><tr><th class="px-5 py-3">Identity</th><th class="px-5 py-3">Akses</th><th class="px-5 py-3">Top app</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Risiko</th><th class="px-5 py-3">Habit</th><th class="px-5 py-3"></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse($clients as $client)
                @php($clientKey = $client->identity_key ?: $client->srcip)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-4"><div class="font-mono font-bold text-slate-900">{{ $client->identity_value ?: $client->srcip }}</div><div class="mt-1 text-[10px] text-slate-400">{{ strtoupper($client->identity_type ?: 'ip') }} · {{ collect(json_decode($client->associated_ips ?: '[]', true))->pluck('ip')->take(3)->implode(', ') ?: $client->srcip }}</div></td>
                    <td class="px-5 py-4"><strong>{{ number_format($client->total_access) }}</strong><div class="mt-1 text-[10px] text-slate-400">{{ $client->active_hour_count }} jam aktif · dominan {{ str_pad((string) ($client->dominant_hour ?? 0), 2, '0', STR_PAD_LEFT) }}:00</div></td>
                    <td class="px-5 py-4">@forelse($appsByClient->get($clientKey, collect()) as $app)<span class="mr-1 inline-flex rounded-lg bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-600">{{ $app->app }}</span>@empty<span class="text-slate-400">-</span>@endforelse</td>
                    <td class="px-5 py-4"><span class="rounded-lg px-2.5 py-1 text-[10px] font-extrabold {{ $client->combined_status === 'priority' ? 'bg-red-50 text-red-700' : ($client->combined_status === 'habit' ? 'bg-violet-50 text-violet-700' : ($client->combined_status === 'risk' ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700')) }}">{{ $client->combined_label }}</span></td>
                    <td class="max-w-56 px-5 py-4"><strong class="{{ $client->risk_level === 'low' ? 'text-emerald-600' : 'text-amber-600' }}">{{ number_format($client->risk_score, 1) }}</strong><div class="mt-1 text-[10px] leading-4 text-slate-400">{{ $client->risk_reason }}</div></td>
                    <td class="max-w-64 px-5 py-4"><strong class="{{ $client->habit_level === 'low' ? 'text-emerald-600' : 'text-violet-600' }}">{{ number_format($client->habit_score, 1) }}</strong><div class="mt-1 text-[10px] leading-4 text-slate-400">{{ $client->habit_reason }}</div></td>
                    <td class="px-5 py-4 text-right"><a class="font-bold text-blue-600" href="{{ route('analysis.client.show', ['batch' => $batch, 'ip' => $clientKey]) }}">Detail →</a></td>
                </tr>
            @empty<tr><td colspan="7" class="px-5 py-10 text-center text-slate-400">Belum ada identity.</td></tr>@endforelse
            </tbody>
        </table></div>
        @if($clients->hasPages())<div class="border-t border-slate-200 px-6 py-4">{{ $clients->withQueryString()->links() }}</div>@endif
    </section>

    <section class="app-panel overflow-hidden">
        <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
            <div><p class="text-[10px] font-bold uppercase tracking-[.18em] text-fuchsia-600">Aktivitas menonjol</p><h3 class="mt-1 text-xl font-extrabold text-slate-900">Event yang paling berbeda</h3><p class="mt-1 text-sm text-slate-500">Bagian ini menampilkan traffic terbesar dan koneksi terlama. Belum tentu bermasalah, tetapi layak dilihat.</p></div>
            <x-explanation title="Mengapa event ini ditampilkan?" source="Traffic, durasi, port, service, destination, policy, waktu, dan identity dari event log." method="Sistem mengambil event dengan total byte terbesar dan durasi terpanjang, lalu memberi konteks jam kerja atau luar jam kerja." meaning="Outlier berarti paling menonjol dibanding event lain, bukan otomatis serangan atau pelanggaran." action="Cocokkan aplikasi, tujuan, policy, dan waktunya dengan aktivitas resmi unit." />
        </div>
        <div class="overflow-x-auto"><table class="min-w-[1050px] divide-y divide-slate-200 text-xs">
            <thead class="bg-slate-50 text-left text-[10px] uppercase tracking-wider text-slate-500"><tr><th class="px-5 py-3">Tipe</th><th class="px-5 py-3">Waktu</th><th class="px-5 py-3">Identity</th><th class="px-5 py-3">App / tujuan</th><th class="px-5 py-3">Action / policy</th><th class="px-5 py-3">Traffic</th><th class="px-5 py-3">Durasi</th><th class="px-5 py-3">Periode</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse($outliers->take(20) as $outlier)
                <tr><td class="px-5 py-4 font-bold text-fuchsia-700">{{ $outlier->type }}</td><td class="whitespace-nowrap px-5 py-4 font-mono text-slate-500">{{ $outlier->logged_at ?: '-' }}</td><td class="px-5 py-4"><strong class="font-mono text-slate-800">{{ $outlier->identity_value }}</strong><div class="text-[10px] text-slate-400">{{ $outlier->srcip }}</div></td><td class="px-5 py-4"><strong>{{ $outlier->app }}</strong><div class="font-mono text-[10px] text-slate-400">{{ $outlier->dstip }}:{{ $outlier->dstport }}</div></td><td class="max-w-56 px-5 py-4"><strong>{{ strtoupper($outlier->action ?: '-') }}</strong><div class="truncate text-[10px] text-slate-400">{{ $outlier->policyname ?: '-' }}</div></td><td class="px-5 py-4 font-mono font-bold">{{ number_format($outlier->total_bytes) }} B</td><td class="px-5 py-4 font-mono">{{ number_format($outlier->duration) }} dtk</td><td class="px-5 py-4"><span class="rounded-lg px-2 py-1 font-bold {{ $outlier->period === 'Jam kerja' ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700' }}">{{ $outlier->period }}</span></td></tr>
            @empty<tr><td colspan="8" class="px-5 py-10 text-center text-slate-400">Belum ada outlier.</td></tr>@endforelse
            </tbody>
        </table></div>
    </section>

    <section class="app-panel p-6"><div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between"><div><p class="text-[10px] font-bold uppercase tracking-[.18em] text-blue-500">Unduh laporan</p><h3 class="mt-1.5 font-extrabold text-slate-900">Simpan hasil analisis</h3><p class="mt-1 text-sm text-slate-500">{{ $batch->raw_purged_at ? 'Ringkasan lengkap tersedia dalam format Excel.' : 'Pilih CSV untuk data mentah atau Excel untuk laporan yang sudah diringkas.' }}</p></div><div class="flex flex-wrap gap-3">@unless($batch->raw_purged_at)<a class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-bold text-slate-700" href="{{ route('analysis.download', $batch) }}">Unduh CSV</a>@endunless<a class="rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white" href="{{ route('analysis.habit-report', $batch) }}">Unduh laporan Excel</a><form method="POST" action="{{ route('analysis.destroy', $batch) }}" onsubmit="return confirm('Hapus hasil analisis ini?')">@csrf @method('DELETE')<button class="rounded-xl px-4 py-2.5 text-sm font-bold text-red-600" type="submit">Hapus hasil</button></form></div></div></section>
</div>

@if($isRunning)
<script>
(() => {
    const panel = document.getElementById('progress-panel');
    const bar = document.getElementById('progress-bar');
    const count = document.getElementById('progress-count');
    const message = document.getElementById('progress-message');
    const poll = async () => {
        try {
            const response = await fetch(panel.dataset.statusUrl, {headers: {'Accept': 'application/json'}});
            const data = await response.json();
            bar.style.width = `${data.progress ?? 99}%`;
            count.textContent = `${new Intl.NumberFormat('id-ID').format(data.processed_lines)} baris`;
            if (data.status === 'completed') { window.location.reload(); return; }
            if (data.status === 'failed') { message.textContent = data.failure_message || 'Analisis gagal.'; return; }
            window.setTimeout(poll, 2000);
        } catch { message.textContent = 'Koneksi terputus. Mencoba kembali…'; window.setTimeout(poll, 5000); }
    };
    window.setTimeout(poll, 1500);
})();
</script>
@endif
@endsection
