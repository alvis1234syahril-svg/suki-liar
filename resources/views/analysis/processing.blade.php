@extends('layouts.app')

@section('title', 'Progres Analisis')
@section('header', 'Proses Analisis')
@section('subtitle', 'Kamu boleh membiarkan halaman ini terbuka')

@section('content')
@php
    $isFailed = $batch->status === 'failed';
    $progress = $batch->status === 'summarizing'
        ? (int) $batch->summary_progress
        : ($batch->file_size > 0
        ? min(100, (int) round(($batch->processed_bytes / $batch->file_size) * 100))
        : 0);
@endphp

<div class="mx-auto max-w-4xl space-y-6">
    <section class="relative overflow-hidden rounded-3xl bg-[#0b1220] p-7 text-white shadow-xl sm:p-9">
        <div class="absolute -right-16 -top-24 h-64 w-64 rounded-full bg-blue-500/20 blur-3xl"></div>
        <div class="relative">
            <div class="text-[10px] font-bold uppercase tracking-[0.2em] text-blue-300">Sedang dikerjakan</div>
            <h2 class="mt-3 break-words text-2xl font-extrabold sm:text-3xl">{{ $batch->label ?: $batch->original_filename }}</h2>
            <p class="mt-2 break-all text-sm text-slate-400">{{ $batch->original_filename }}</p>
        </div>
    </section>

    <section id="analysis-progress-panel"
        data-status-url="{{ route('analysis.status', $batch) }}"
        class="app-panel p-6 sm:p-8"
        aria-live="polite">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div id="analysis-status-badge" class="inline-flex items-center gap-2 rounded-full {{ $isFailed ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700' }} px-3 py-1.5 text-xs font-extrabold">
                    <span class="h-2 w-2 rounded-full bg-current"></span>
                    {{ $isFailed ? 'Proses gagal' : match($batch->status) {
                        'uploaded' => 'Menunggu antrean',
                        'summarizing' => 'Menyusun dashboard',
                        default => 'Sedang menganalisis',
                    } }}
                </div>
                <h3 id="analysis-progress-title" class="mt-4 text-xl font-extrabold text-slate-900">{{ $isFailed ? 'Analisis belum berhasil' : ($batch->status === 'summarizing' ? 'Sedang menyiapkan hasil' : 'Sedang membaca log') }}</h3>
                <p id="analysis-progress-message" class="mt-2 max-w-2xl text-sm leading-6 {{ $isFailed ? 'text-red-600' : 'text-slate-500' }}">{{ $isFailed ? ($batch->failure_message ?: 'Ada masalah saat memproses file ini.') : ($batch->status === 'summarizing' ? ($batch->summary_stage ?: 'Semua log sudah dibaca. Tinggal menyiapkan ringkasan dan grafiknya.') : 'Progres akan berubah otomatis. Halaman tidak perlu dimuat ulang.') }}</p>
            </div>
            <div class="text-left sm:text-right">
                <div id="analysis-progress-percent" class="text-4xl font-extrabold {{ $isFailed ? 'text-red-600' : 'text-blue-600' }}">{{ $isFailed ? ',' : $progress.'%' }}</div>
                <div class="mt-1 text-xs font-semibold text-slate-400">{{ $batch->status === 'summarizing' ? 'progres penyusunan hasil' : 'berdasarkan ukuran file' }}</div>
            </div>
        </div>

        @unless($isFailed)
            <div class="mt-7 h-3 overflow-hidden rounded-full bg-slate-100"><div id="analysis-progress-bar" class="h-full rounded-full bg-gradient-to-r from-blue-600 to-cyan-400 transition-all" style="width: {{ max(1, $progress) }}%"></div></div>
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl bg-slate-50 p-4"><div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Baris diperiksa</div><div id="analysis-line-count" class="mt-2 font-mono text-lg font-extrabold text-slate-800">{{ number_format($batch->processed_lines + $batch->invalid_lines) }}</div></div>
                <div class="rounded-2xl bg-slate-50 p-4"><div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Baris valid</div><div id="analysis-valid-count" class="mt-2 font-mono text-lg font-extrabold text-emerald-600">{{ number_format($batch->processed_lines) }}</div></div>
                <div class="rounded-2xl bg-slate-50 p-4"><div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Baris dilewati</div><div id="analysis-invalid-count" class="mt-2 font-mono text-lg font-extrabold text-amber-600">{{ number_format($batch->invalid_lines) }}</div></div>
            </div>
            <p class="mt-5 text-xs leading-5 text-slate-400">File dengan jutaan baris memang butuh waktu lebih lama. Biarkan halaman ini terbuka dan progres akan terus diperbarui.</p>
        @else
            <div class="mt-6 flex flex-wrap gap-3">
                <form method="POST" action="{{ route('analysis.retry', $batch) }}">
                    @csrf
                    <button type="submit" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-extrabold text-white">Proses ulang file ini</button>
                </form>
                <a href="{{ route('analysis.create') }}" class="inline-flex rounded-xl border border-slate-200 px-5 py-3 text-sm font-extrabold text-slate-700">Unggah file lain</a>
                <form method="POST" action="{{ route('analysis.destroy', $batch) }}" onsubmit="return confirm('Hapus file dan seluruh data parsial dari analisis yang gagal?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rounded-xl border border-red-200 px-5 py-3 text-sm font-extrabold text-red-600">Hapus data gagal</button>
                </form>
            </div>
        @endunless
    </section>
</div>

@unless($isFailed)
<script>
(() => {
    const panel = document.getElementById('analysis-progress-panel');
    const bar = document.getElementById('analysis-progress-bar');
    const percent = document.getElementById('analysis-progress-percent');
    const message = document.getElementById('analysis-progress-message');
    const lineCount = document.getElementById('analysis-line-count');
    const validCount = document.getElementById('analysis-valid-count');
    const invalidCount = document.getElementById('analysis-invalid-count');
    const number = new Intl.NumberFormat('id-ID');

    const poll = async () => {
        try {
            const response = await fetch(panel.dataset.statusUrl, {
                headers: {'Accept': 'application/json'},
                cache: 'no-store',
            });
            if (!response.ok) throw new Error('Status tidak tersedia');
            const data = await response.json();
            const value = data.progress ?? 0;

            bar.style.width = `${Math.max(1, value)}%`;
            percent.textContent = `${value}%`;
            lineCount.textContent = number.format(data.processed_lines + data.invalid_lines);
            validCount.textContent = number.format(data.processed_lines);
            invalidCount.textContent = number.format(data.invalid_lines);

            if (data.status === 'completed') {
                bar.style.width = '100%';
                percent.textContent = '100%';
                message.textContent = 'Analisis selesai. Membuka hasilnya…';
                const separator = data.result_url.includes('?') ? '&' : '?';
                window.location.replace(`${data.result_url}${separator}hasil=${Date.now()}`);
                return;
            }

            if (data.status === 'failed') {
                window.location.reload();
                return;
            }

            message.textContent = data.status === 'uploaded'
                ? 'File sudah tersimpan dan sedang menunggu giliran.'
                : (data.status === 'summarizing'
                    ? (data.summary_stage || 'Semua log sudah dibaca. Tinggal menyiapkan ringkasannya.')
                    : 'Log sedang dibaca. Angka di halaman ini akan berubah otomatis.');
            window.setTimeout(poll, 2000);
        } catch (error) {
            message.textContent = 'Koneksi sempat terputus. Sistem akan mencoba lagi…';
            window.setTimeout(poll, 5000);
        }
    };

    window.setTimeout(poll, 1000);
})();
</script>
@endunless
@endsection
