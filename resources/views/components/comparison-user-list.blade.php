@props(['unit', 'formatBytes'])

@php
    $profiles = collect($unit->client_profiles ?? []);
    $suspiciousUsers = $profiles
        ->filter(fn ($client) => ($client['level'] ?? 'low') !== 'low' || ($client['habit_level'] ?? 'low') !== 'low')
        ->sortByDesc(fn ($client) => max($client['score'] ?? 0, $client['habit_score'] ?? 0))
        ->values();
    $healthyUsers = $profiles
        ->filter(fn ($client) => ($client['level'] ?? 'low') === 'low' && ($client['habit_level'] ?? 'low') === 'low')
        ->sortByDesc('total_access')
        ->take(5)
        ->values();
    $healthyTotal = max(0, $unit->total_clients - $unit->risky_clients);

    $riskDetails = function (array $client): array {
        $total = max(0, (int) ($client['total_access'] ?? 0));
        $critical = max(0, (int) ($client['critical_count'] ?? 0));
        $high = max(0, (int) ($client['high_count'] ?? 0));
        $elevated = max(0, (int) ($client['elevated_count'] ?? 0));
        $medium = max(0, (int) ($client['medium_count'] ?? 0));
        $denied = max(0, (int) ($client['denied_count'] ?? 0));
        $risky = $critical + $high + $elevated + $medium;
        $riskRate = $total > 0 ? ($risky / $total) * 100 : 0;
        $deniedRate = $total > 0 ? ($denied / $total) * 100 : 0;
        $score = (float) ($client['score'] ?? 0);
        $level = $client['level'] ?? 'low';
        $drivers = [];
        $categories = [
            ['label' => 'critical', 'count' => $critical, 'weight' => 4],
            ['label' => 'high', 'count' => $high, 'weight' => 3],
            ['label' => 'elevated', 'count' => $elevated, 'weight' => 2],
            ['label' => 'medium', 'count' => $medium, 'weight' => 1],
        ];
        usort($categories, fn (array $a, array $b) => $b['count'] <=> $a['count']);
        $dominant = $categories[0];

        if ($critical > 0 || $high > 0) {
            $drivers[] = number_format($critical + $high).' event kritis/tinggi';
        }
        if ($elevated > 0) {
            $drivers[] = number_format($elevated).' elevated';
        }
        if ($medium > 0) {
            $drivers[] = number_format($medium).' medium';
        }
        if ($denied > 0) {
            $drivers[] = number_format($denied).' deny ('.number_format($deniedRate, 1).'%)';
        }

        $classification = match ($level) {
            'high' => 'Skor '.number_format($score, 1).' melewati ambang 55 sehingga masuk risiko tinggi.',
            'medium' => 'Skor '.number_format($score, 1).' melewati ambang 30 sehingga perlu perhatian.',
            default => 'Skor '.number_format($score, 1).' masih di bawah ambang perhatian 30.',
        };

        if ($risky > 0 && $total > 0) {
            $summary = number_format($riskRate, 1).'% dari seluruh akses termasuk event berisiko; paling banyak '
                .number_format($dominant['count']).' '.$dominant['label'].' (bobot ×'.$dominant['weight'].')';
        } elseif ($drivers !== []) {
            $summary = implode(', ', $drivers);
        } else {
            $summary = 'Tidak ditemukan event berisiko yang signifikan';
        }

        return compact(
            'total', 'critical', 'high', 'elevated', 'medium', 'denied', 'risky',
            'riskRate', 'deniedRate', 'score', 'level', 'drivers', 'dominant',
            'classification', 'summary'
        );
    };
@endphp

<div class="border-t border-slate-100 p-5 sm:p-6" data-comparison-user-list>
    <div class="mb-5 overflow-hidden rounded-2xl border border-blue-100 bg-blue-50/60">
        <div class="grid gap-px bg-blue-100 lg:grid-cols-[1.35fr_.65fr]">
            <div class="bg-blue-50/95 p-5">
                <p class="text-[10px] font-extrabold uppercase tracking-[.16em] text-blue-700">Cara membaca status user</p>
                <p class="mt-2 text-sm font-bold leading-6 text-slate-800">Event medium atau elevated tidak langsung berarti ada masalah.</p>
                <p class="mt-1 text-xs leading-5 text-slate-600">Risiko aplikasi dan pola penggunaan dihitung terpisah. User masuk daftar perhatian jika salah satu skornya mencapai 30.</p>
                <div class="mt-4 flex flex-wrap gap-2 text-[10px] font-extrabold">
                    <span class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-slate-600">Medium × 1</span>
                    <span class="rounded-lg border border-amber-200 bg-white px-2.5 py-1.5 text-amber-700">Elevated × 2</span>
                    <span class="rounded-lg border border-orange-200 bg-white px-2.5 py-1.5 text-orange-700">High × 3</span>
                    <span class="rounded-lg border border-red-200 bg-white px-2.5 py-1.5 text-red-700">Critical × 4</span>
                </div>
            </div>
            <div class="bg-white p-5">
                <p class="text-[10px] font-extrabold uppercase tracking-[.16em] text-slate-400">Ambang klasifikasi</p>
                <div class="mt-3 space-y-2 text-xs font-bold">
                    <div class="flex items-center justify-between rounded-lg bg-emerald-50 px-3 py-2 text-emerald-700"><span>Sehat · risiko rendah</span><strong>0–29,9</strong></div>
                    <div class="flex items-center justify-between rounded-lg bg-amber-50 px-3 py-2 text-amber-700"><span>Mencurigakan · sedang</span><strong>30–54,9</strong></div>
                    <div class="flex items-center justify-between rounded-lg bg-red-50 px-3 py-2 text-red-700"><span>Prioritas · tinggi</span><strong>55–100</strong></div>
                </div>
            </div>
        </div>
        <details class="group border-t border-blue-100 bg-white px-5 py-3">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-xs font-extrabold text-blue-700">
                <span>Lihat rumus perhitungan lengkap</span>
                <span class="transition group-open:rotate-180">⌄</span>
            </summary>
            <div class="mt-3 grid gap-3 text-[11px] leading-5 text-slate-600 md:grid-cols-3">
                <div class="rounded-xl bg-slate-50 p-3"><strong class="block text-slate-800">1. Eksposur aplikasi · maks. 60</strong><span>((Critical×4 + High×3 + Elevated×2 + Medium) ÷ (Total akses×4)) × 60</span></div>
                <div class="rounded-xl bg-slate-50 p-3"><strong class="block text-slate-800">2. Aktivitas deny · maks. 25</strong><span>Persentase koneksi ditolak terhadap seluruh akses, dibatasi 25 poin.</span></div>
                <div class="rounded-xl bg-slate-50 p-3"><strong class="block text-slate-800">3. Pengulangan · maks. 15</strong><span>(Critical + High) ÷ 5, dibatasi 15 poin. Sampel kecil juga dikurangi tingkat keyakinannya.</span></div>
            </div>
            <div class="mt-3 rounded-xl bg-violet-50 p-3 text-[11px] leading-5 text-violet-900">
                <strong class="block">Skor Habit / Anomali</strong>
                Luar jam kerja 30% · traffic terhadap baseline user 20% · deny/timeout/reset 15% · port nonstandar 15% · sebaran jam aktif 10% · aplikasi/tujuan tidak biasa 10%.
            </div>
        </details>
    </div>

    <div class="rounded-2xl border border-amber-100 bg-amber-50/60 p-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-[10px] font-extrabold uppercase tracking-[.16em] text-amber-700">User yang perlu dicek</p>
                <p class="mt-1 text-xs leading-5 text-slate-600">Alasan dan angka pemicunya ditampilkan di setiap baris. Cocokkan lagi dengan kebutuhan kerja user sebelum mengambil kesimpulan.</p>
            </div>
            <label class="relative block sm:w-72">
                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                </span>
                <input data-client-search type="search" placeholder="Cari IP atau nama user…" class="w-full rounded-xl border border-amber-200 bg-white py-2.5 pl-9 pr-3 text-xs outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-200">
            </label>
        </div>
    </div>

    <div class="mt-4 overflow-x-auto rounded-2xl border border-slate-200">
        <table class="w-full min-w-[900px] text-left text-xs">
            <thead class="bg-slate-900 text-[9px] font-extrabold uppercase tracking-[.14em] text-slate-300">
                <tr>
                    <th class="px-4 py-3">No.</th>
                    <th class="px-4 py-3">User / IP</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Akses</th>
                    <th class="px-4 py-3">Traffic</th>
                    <th class="px-4 py-3">Deny</th>
                    <th class="px-4 py-3">Risiko / Habit</th>
                    <th class="px-4 py-3">Kenapa perlu dicek?</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
                @forelse($suspiciousUsers as $index => $client)
                    @php($detail = $riskDetails($client))
                    <tr data-client-row data-search-text="{{ strtolower(($client['ip'] ?? '').' '.($client['name'] ?? '')) }}" class="transition hover:bg-amber-50/40">
                        <td class="px-4 py-3 font-bold text-slate-400">{{ $index + 1 }}</td>
                        <td class="px-4 py-3"><strong class="font-mono text-sm text-slate-900">{{ $client['ip'] ?? '-' }}</strong><span class="mt-1 block text-[10px] text-slate-400">{{ $client['name'] ?: 'Tidak tersedia pada log' }}</span></td>
                        <td class="px-4 py-3"><span class="rounded-lg bg-amber-50 px-2 py-1 font-extrabold text-amber-700">{{ $client['combined_label'] ?? (($client['level'] ?? '') === 'high' ? 'Prioritas tinggi' : 'Perlu perhatian') }}</span></td>
                        <td class="px-4 py-3 font-mono font-bold text-slate-700">{{ number_format($client['total_access'] ?? 0) }}</td>
                        <td class="px-4 py-3 font-semibold text-slate-600">{{ $formatBytes(($client['total_upload'] ?? 0) + ($client['total_download'] ?? 0)) }}</td>
                        <td class="px-4 py-3 font-mono font-bold text-slate-700">{{ number_format($client['denied_count'] ?? 0) }}</td>
                        <td class="px-4 py-3">
                            <strong class="block text-sm text-amber-600">R {{ number_format($client['score'] ?? 0, 1) }}</strong>
                            <strong class="block text-sm text-violet-600">H {{ number_format($client['habit_score'] ?? 0, 1) }}</strong>
                        </td>
                        <td class="max-w-md px-4 py-3 leading-5 text-slate-600">
                            <strong class="block text-slate-800">{{ $detail['classification'] }}</strong>
                            <span class="mt-1 block">{{ $detail['summary'] }}.</span>
                            @if($detail['drivers'] !== [])
                                <span class="mt-2 flex flex-wrap gap-1">
                                    @if($detail['critical'] > 0)<span class="rounded bg-red-50 px-1.5 py-0.5 text-[9px] font-bold text-red-700">{{ number_format($detail['critical']) }} critical</span>@endif
                                    @if($detail['high'] > 0)<span class="rounded bg-orange-50 px-1.5 py-0.5 text-[9px] font-bold text-orange-700">{{ number_format($detail['high']) }} high</span>@endif
                                    @if($detail['elevated'] > 0)<span class="rounded bg-amber-50 px-1.5 py-0.5 text-[9px] font-bold text-amber-700">{{ number_format($detail['elevated']) }} elevated</span>@endif
                                    @if($detail['medium'] > 0)<span class="rounded bg-yellow-50 px-1.5 py-0.5 text-[9px] font-bold text-yellow-700">{{ number_format($detail['medium']) }} medium</span>@endif
                                    @if($detail['denied'] > 0)<span class="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold text-slate-600">{{ number_format($detail['denied']) }} deny</span>@endif
                                </span>
                            @elseif(! empty($client['reason']))
                                <span class="mt-1 block text-[10px] text-slate-400">{{ $client['reason'] }}</span>
                            @endif
                            @if(! empty($client['habit_reason']))
                                <span class="mt-2 block rounded-lg bg-violet-50 px-2 py-1.5 text-[10px] text-violet-700"><strong>Habit:</strong> {{ $client['habit_reason'] }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-6 py-10 text-center text-sm text-slate-400">Tidak ada user yang perlu dicek pada unit ini.</td></tr>
                @endforelse
                <tr data-client-empty hidden><td colspan="8" class="px-6 py-10 text-center text-sm text-slate-400">Tidak ada user yang cocok dengan pencarian.</td></tr>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-right text-[10px] font-bold text-slate-400"><span data-client-visible-count>{{ $suspiciousUsers->count() }}</span> dari {{ $suspiciousUsers->count() }} user ditampilkan</p>

    <div class="mt-7 flex items-end justify-between gap-4">
        <div><p class="text-[10px] font-extrabold uppercase tracking-[.16em] text-emerald-700">Contoh user sehat</p><p class="mt-1 text-xs text-slate-500">Lima user berindeks rendah dengan aktivitas terbesar sebagai sampel pembanding.</p></div>
        <span class="rounded-full bg-emerald-50 px-3 py-1 text-[10px] font-extrabold text-emerald-700">{{ number_format($healthyTotal) }} sehat total</span>
    </div>
    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
        @forelse($healthyUsers as $client)
            @php($detail = $riskDetails($client))
            <article class="rounded-2xl border border-emerald-100 bg-emerald-50/40 p-4">
                <div class="flex items-start justify-between gap-2"><strong class="font-mono text-xs text-slate-900">{{ $client['ip'] ?? '-' }}</strong><span class="rounded-md bg-white px-1.5 py-0.5 text-[9px] font-extrabold text-emerald-700">RENDAH</span></div>
                <p class="mt-3 text-[10px] text-slate-500">{{ number_format($client['total_access'] ?? 0) }} akses · {{ $formatBytes(($client['total_upload'] ?? 0) + ($client['total_download'] ?? 0)) }}</p>
                <p class="mt-2 text-[10px] leading-4 text-slate-500"><span class="font-bold text-emerald-700">Risiko {{ number_format($detail['score'], 1) }} · Habit {{ number_format($client['habit_score'] ?? 0, 1) }}, keduanya di bawah 30.</span> {{ $detail['summary'] }}.</p>
                @if($detail['risky'] > 0)
                    <p class="mt-2 text-[9px] font-bold text-slate-400">{{ number_format($detail['critical']) }} critical · {{ number_format($detail['high']) }} high · {{ number_format($detail['elevated']) }} elevated · {{ number_format($detail['medium']) }} medium</p>
                @endif
            </article>
        @empty
            <p class="text-sm text-slate-400">Tidak ada sampel user sehat.</p>
        @endforelse
    </div>
</div>
