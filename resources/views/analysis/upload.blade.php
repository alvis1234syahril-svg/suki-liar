@extends('layouts.app')

@section('title', 'Analisis Baru')
@section('header', 'Analisis Baru')
@section('subtitle', 'Pilih log yang ingin diperiksa')

@section('content')
<div class="space-y-6">
    @if (session('success'))<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-800">{{ session('success') }}</div>@endif
    @if ($errors->any())<div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-semibold text-red-800">Ada bagian yang belum benar. Cek file dan nama unitnya, ya.</div>@endif

    <section class="electric-hero relative overflow-hidden rounded-3xl bg-[#0b1220] px-6 py-7 text-white shadow-xl shadow-slate-300/40 sm:px-8 sm:py-8">
        <div class="absolute -right-16 -top-24 h-64 w-64 rounded-full bg-blue-500/20 blur-3xl"></div>
        <div class="relative grid items-center gap-7 lg:grid-cols-[1fr_280px]">
            <div class="max-w-3xl">
                <h2 class="max-w-3xl text-3xl font-extrabold leading-[1.08] tracking-[-.04em] sm:text-4xl">
                    Ubah log menjadi insight.
                    <span class="electric-text block">Kenali pola setiap unit.</span>
                </h2>
                <p class="mt-4 max-w-2xl text-sm leading-6 text-slate-300">Unggah log sekali. Sistem merangkum traffic, membaca kebiasaan user, lalu menyiapkan hasilnya di dashboard dan pembanding.</p>
            </div>
            <div class="electric-orbit relative hidden h-52 lg:grid">
                <span class="electric-orbit-ring electric-orbit-ring-one"></span>
                <span class="electric-orbit-ring electric-orbit-ring-two"></span>
                <span class="electric-orbit-beam"></span>
                <div class="electric-orbit-core">
                    <svg class="h-9 w-9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 17V7l8-4 8 4v10l-8 4-8-4Z"/><path d="m9 12 2 2 4-5"/></svg>
                    <span>LOG</span>
                </div>
                <span class="electric-orbit-tag electric-orbit-tag-a">TRAFFIC</span>
                <span class="electric-orbit-tag electric-orbit-tag-b">HABIT</span>
                <span class="electric-orbit-tag electric-orbit-tag-c">USER</span>
                <span class="electric-orbit-tag electric-orbit-tag-d">SUSPICIOUS</span>
                <span class="electric-orbit-tag electric-orbit-tag-e">TOP APP</span>
            </div>
        </div>
    </section>

    <form id="analysis-upload-form" method="POST" action="{{ route('analysis.store') }}" data-chunk-upload-url="{{ route('analysis.uploads.start') }}" enctype="multipart/form-data" class="app-panel overflow-hidden">
        @csrf
        <div class="grid lg:grid-cols-[1fr_360px]">
            <label for="log_file" class="electric-dropzone group flex min-h-[290px] cursor-pointer flex-col items-center justify-center border-b border-dashed border-slate-200 p-7 text-center transition lg:border-b-0 lg:border-r sm:p-9">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-blue-600 text-white shadow-lg shadow-blue-200 transition group-hover:-translate-y-1"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 16V4m0 0L7 9m5-5 5 5"/><path d="M5 15v4h14v-4"/></svg></span>
                <span class="mt-5 block text-xl font-extrabold tracking-tight text-slate-900">Pilih atau tarik file log</span>
                <span class="mt-2 block max-w-md text-sm leading-6 text-slate-500">Gunakan file .txt atau .log.</span>
                <input id="log_file" type="file" name="log_file" accept=".txt,.log" required class="mt-5 block max-w-full rounded-xl border border-slate-200 bg-white p-3 text-xs text-slate-500 shadow-sm">
                <span id="selected-file-info" class="mt-3 hidden text-xs font-semibold text-blue-700"></span>
                @error('log_file')<span class="mt-3 block text-sm font-semibold text-red-600">{{ $message }}</span>@enderror
            </label>

            <div class="flex flex-col justify-between p-6 sm:p-8">
                <div>
                    <div class="text-[10px] font-bold uppercase tracking-[0.18em] text-blue-600">Sebelum mulai</div>
                    <div class="mt-2 flex items-center justify-between gap-3">
                        <h3 class="text-xl font-extrabold text-slate-900">Pilih unit tujuan</h3>
                        <button type="button" data-unit-manager-open class="shrink-0 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-[10px] font-extrabold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100">Kelola unit</button>
                    </div>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Hasil akan masuk ke dashboard unit dan pembanding secara otomatis.</p>
                    <label for="organizational_unit_id" class="mt-6 block text-xs font-bold uppercase tracking-wide text-slate-600">Unit</label>
                    <select id="organizational_unit_id" name="organizational_unit_id" required class="mt-2 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-100">
                        <option value="">Pilih unit</option>
                        @foreach($units as $unit)
                            <option value="{{ $unit->id }}" @selected((string) old('organizational_unit_id', request('unit')) === (string) $unit->id)>{{ $unit->name }}</option>
                        @endforeach
                    </select>
                    @error('organizational_unit_id')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                </div>
                <button id="upload-submit" type="submit" class="mt-8 w-full rounded-xl bg-blue-600 px-5 py-4 text-sm font-extrabold text-white shadow-lg shadow-blue-200 transition hover:-translate-y-0.5 hover:bg-blue-700">Analisis sekarang <span class="ml-2">→</span></button>
            </div>
        </div>
        <div id="upload-progress-panel" class="hidden border-t border-blue-100 bg-blue-50 px-6 py-5" aria-live="polite">
            <div class="flex items-center justify-between gap-4">
                <div><div id="upload-progress-title" class="text-sm font-extrabold text-blue-950">Sedang mengunggah file</div><div id="upload-progress-message" class="mt-1 text-xs text-blue-700">Tunggu sebentar sampai proses unggah selesai.</div></div>
                <span id="upload-progress-percent" class="font-mono text-sm font-extrabold text-blue-800">0%</span>
            </div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-blue-100"><div id="upload-progress-bar" class="h-full w-0 rounded-full bg-blue-600 transition-all"></div></div>
        </div>
    </form>

    @include('components.unit-manager-dialog', ['units' => $units])

</div>
@endsection
