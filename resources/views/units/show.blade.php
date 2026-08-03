@extends('layouts.app')

@section('title', $unit->name)
@section('header', $unit->name)
@section('subtitle', 'Hasil terbaru dan riwayat analisis')

@section('content')
@php
    $summary = $latest?->summary_data['summary'] ?? [];
    $metricHelp = [
        'Total event' => [
            'source' => 'Seluruh baris log valid pada hasil analisis terbaru unit ini.',
            'method' => 'Setiap baris yang berhasil diparse dihitung sebagai satu event.',
            'meaning' => 'Menunjukkan volume aktivitas, bukan jumlah tindakan berbahaya.',
            'action' => 'Bandingkan dengan jumlah user dan hasil unit sebelumnya agar lonjakan lebih mudah dikenali.',
        ],
        'User' => [
            'source' => 'Identitas pada kolom user, srcname, srcserver, lalu srcip sebagai pilihan terakhir.',
            'method' => 'Identitas yang sama digabung sehingga perpindahan IP tidak selalu dihitung sebagai user baru.',
            'meaning' => 'Jumlah ini adalah user atau perangkat unik yang teramati dalam log.',
            'action' => 'Buka hasil terbaru untuk melihat status dan alasan penilaian setiap user.',
        ],
        'Aplikasi' => [
            'source' => 'Kolom app atau service pada event yang berhasil dibaca.',
            'method' => 'Nama aplikasi unik dihitung satu kali walaupun muncul pada banyak event.',
            'meaning' => 'Menunjukkan keragaman aplikasi yang digunakan unit.',
            'action' => 'Periksa aplikasi yang tidak sesuai fungsi unit, terutama bila risikonya tinggi.',
        ],
    ];
@endphp
<div class="space-y-5">
    @if(session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ session('success') }}</div>
    @endif

    <section class="rounded-2xl bg-slate-950 p-6 text-white shadow-lg sm:p-7">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <a href="{{ route('analysis.dashboard') }}" class="text-xs font-bold text-blue-300">← Semua unit</a>
                <h2 class="mt-3 text-2xl font-extrabold sm:text-3xl">{{ $unit->name }}</h2>
                <p class="mt-2 text-sm text-slate-400">{{ $analyses->total() }} hasil analisis tersimpan</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if($running)<a href="{{ route('analysis.show', $running) }}" class="rounded-xl border border-blue-400/30 bg-blue-400/10 px-4 py-2.5 text-sm font-bold text-blue-200">Lihat progres</a>@endif
                @if($latest)<a href="{{ route('analysis.show', $latest) }}" class="rounded-xl bg-white px-4 py-2.5 text-sm font-extrabold text-slate-900">Buka hasil terbaru</a>@endif
                <a href="{{ route('analysis.create', ['unit' => $unit->id]) }}" class="rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-extrabold">+ Upload log</a>
            </div>
        </div>
    </section>

    @if($latest)
        <section class="grid gap-3 sm:grid-cols-3">
            @foreach([
                ['Total event', $summary['total_logs'] ?? 0, 'text-blue-600'],
                ['User', $summary['total_clients'] ?? 0, 'text-cyan-600'],
                ['Aplikasi', $summary['unique_apps'] ?? 0, 'text-slate-800'],
            ] as [$label, $value, $tone])
                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-2">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">{{ $label }}</span>
                        <x-explanation
                            :title="$label"
                            :source="$metricHelp[$label]['source']"
                            :method="$metricHelp[$label]['method']"
                            :meaning="$metricHelp[$label]['meaning']"
                            :action="$metricHelp[$label]['action']"
                        />
                    </div>
                    <strong class="mt-2 block text-2xl font-extrabold {{ $tone }}">{{ is_float($value) ? number_format($value, 1) : number_format($value) }}</strong>
                </article>
            @endforeach
        </section>
    @else
        <section class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <h3 class="font-extrabold text-slate-900">Belum ada hasil</h3>
            <p class="mt-2 text-sm text-slate-500">Upload log pertama untuk mulai membuat dashboard unit ini.</p>
        </section>
    @endif

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
            <div>
                <h3 class="font-extrabold text-slate-900">Riwayat analisis</h3>
                <p class="mt-1 text-xs text-slate-500">Hasil terbaru otomatis dipakai pada pembanding unit.</p>
            </div>
            <x-explanation
                title="Cara kerja riwayat analisis"
                source="Semua analisis yang pernah selesai, sedang diproses, atau gagal pada unit ini."
                method="Hasil selesai dengan waktu terbaru dipilih otomatis sebagai isi dashboard dan pembanding."
                meaning="Riwayat lama tetap tersedia. Memindahkan atau menghapus hasil terbaru membuat sistem kembali ke hasil selesai sebelumnya."
                action="Gunakan Pindahkan hanya jika salah memilih unit, dan Hapus hanya untuk hasil yang tidak lagi diperlukan."
            />
        </div>
        <div class="divide-y divide-slate-100">
            @forelse($analyses as $analysis)
                <article class="p-5">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h4 class="truncate font-bold text-slate-900">{{ $analysis->label ?: $analysis->original_filename }}</h4>
                                <span class="rounded-full px-2 py-1 text-[9px] font-extrabold {{ $analysis->status === 'completed' ? 'bg-emerald-50 text-emerald-700' : ($analysis->status === 'failed' ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700') }}">{{ strtoupper($analysis->status) }}</span>
                            </div>
                            <p class="mt-1 truncate text-xs text-slate-400">{{ $analysis->original_filename }} · {{ $analysis->created_at->format('d M Y') }}</p>
                        </div>
                        <div class="flex flex-wrap items-start gap-2 lg:justify-end">
                            <a href="{{ route('analysis.show', $analysis) }}" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700">Lihat</a>
                            <button
                                type="button"
                                data-analysis-edit-open
                                data-dialog-id="analysis-edit-{{ $analysis->uuid }}"
                                class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700"
                            >
                                Pindahkan
                            </button>
                            <form method="POST" action="{{ route('analysis.destroy', $analysis) }}" onsubmit="return confirm('Hapus hasil analisis ini?')">
                                @csrf @method('DELETE')
                                <button class="rounded-lg px-3 py-2 text-xs font-bold text-red-600">Hapus</button>
                            </form>
                        </div>
                    </div>
                    <dialog id="analysis-edit-{{ $analysis->uuid }}" data-analysis-edit-dialog class="analysis-edit-dialog w-[calc(100%-2rem)] max-w-md rounded-2xl border-0 bg-white p-0 text-left shadow-2xl">
                        <div class="p-5 sm:p-6">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <span class="text-[10px] font-extrabold uppercase tracking-[.18em] text-blue-600">Ubah unit tujuan</span>
                                    <h5 class="mt-1 text-lg font-extrabold text-slate-900">Pindahkan hasil analisis</h5>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">Gunakan ini jika log sebelumnya dimasukkan ke unit yang salah.</p>
                                </div>
                                <button type="button" data-analysis-edit-close class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-100 text-lg text-slate-500 transition hover:bg-slate-200" aria-label="Tutup">×</button>
                            </div>

                            <div class="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <span class="block text-[9px] font-bold uppercase tracking-wider text-slate-400">File analisis</span>
                                <strong class="mt-1 block truncate text-sm text-slate-800">{{ $analysis->original_filename }}</strong>
                                <span class="mt-1 block text-xs text-slate-500">Saat ini: {{ $analysis->organizationalUnit?->name }}</span>
                            </div>

                            <form method="POST" action="{{ route('analysis.update', $analysis) }}" class="mt-5">
                                @csrf @method('PATCH')
                                <label class="block">
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Pindahkan ke unit</span>
                                    <select name="organizational_unit_id" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm font-semibold text-slate-800 focus:border-blue-500 focus:ring-blue-500">
                                        @foreach($allUnits as $option)<option value="{{ $option->id }}" @selected($analysis->organizational_unit_id === $option->id)>{{ $option->name }}</option>@endforeach
                                    </select>
                                </label>
                                <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-[11px] leading-5 text-amber-800">Hasil akan muncul di dashboard unit baru dan pembanding akan diperbarui otomatis.</p>
                                <div class="mt-5 flex justify-end gap-2">
                                    <button type="button" data-analysis-edit-close class="rounded-xl border border-slate-200 px-4 py-2.5 text-xs font-bold text-slate-600 transition hover:bg-slate-50">Batal</button>
                                    <button class="rounded-xl bg-blue-600 px-4 py-2.5 text-xs font-bold text-white transition hover:bg-blue-700">Simpan unit</button>
                                </div>
                            </form>
                        </div>
                    </dialog>
                </article>
            @empty
                <p class="px-5 py-10 text-center text-sm text-slate-400">Belum ada riwayat analisis.</p>
            @endforelse
        </div>
        @if($analyses->hasPages())<div class="border-t border-slate-200 px-5 py-4">{{ $analyses->links() }}</div>@endif
    </section>
</div>
@endsection
