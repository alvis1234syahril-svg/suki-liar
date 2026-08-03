<div class="flex items-start justify-between gap-4">
    <div>
        <h3 class="font-extrabold text-slate-900">{{ $unit->name }}</h3>
        <p class="mt-1 text-xs text-slate-500">Seluruh user yang perlu diperiksa dan lima contoh user normal.</p>
    </div>
    <a href="{{ route('analysis.show', $batch) }}" class="text-xs font-bold text-blue-600">Buka hasil lengkap →</a>
</div>

<div class="mt-5 overflow-x-auto">
    <table class="w-full min-w-[720px] text-left text-xs">
        <thead class="border-b border-slate-200 text-[10px] uppercase tracking-wider text-slate-400">
            <tr><th class="pb-3">User</th><th class="pb-3">Status</th><th class="pb-3">Akses</th><th class="pb-3">Risiko</th><th class="pb-3">Habit</th><th class="pb-3">Alasan</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
        @forelse($attention as $client)
            <tr>
                <td class="py-3 font-mono font-bold text-slate-800">{{ $client->identity_value ?: $client->srcip }}</td>
                <td class="py-3 text-amber-700">{{ $client->combined_label }}</td>
                <td class="py-3">{{ number_format($client->total_access) }}</td>
                <td class="py-3 font-bold">{{ number_format($client->risk_score, 1) }}</td>
                <td class="py-3 font-bold">{{ number_format($client->habit_score, 1) }}</td>
                <td class="max-w-sm py-3 text-slate-500">
                    @if($client->risk_level !== 'low')<span class="block"><strong>Risiko:</strong> {{ $client->risk_reason }}</span>@endif
                    @if($client->habit_level !== 'low')<span class="mt-1 block"><strong>Habit:</strong> {{ $client->habit_reason }}</span>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="py-6 text-center text-slate-400">Tidak ada user yang melewati ambang perhatian.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if($healthy->isNotEmpty())
<div class="mt-5">
    <h4 class="text-xs font-extrabold uppercase tracking-wider text-emerald-700">Contoh user normal</h4>
    <div class="mt-2 flex flex-wrap gap-2">
        @foreach($healthy as $client)
            <span class="rounded-lg bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800">{{ $client->identity_value ?: $client->srcip }} · {{ number_format($client->total_access) }} akses</span>
        @endforeach
    </div>
</div>
@endif
