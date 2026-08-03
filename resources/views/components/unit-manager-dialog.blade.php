<dialog
    id="unit-manager-dialog"
    data-unit-manager-dialog
    @if($errors->unitManager->any() || session('unit_manager_error')) data-open-on-load @endif
    class="analysis-edit-dialog w-[calc(100%-2rem)] max-w-xl rounded-2xl border-0 bg-white p-0 text-left shadow-2xl"
>
    <div class="p-5 sm:p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <span class="text-[10px] font-extrabold uppercase tracking-[.18em] text-blue-600">Pengaturan unit</span>
                <h3 class="mt-1 text-xl font-extrabold text-slate-900">Kelola daftar unit</h3>
                <p class="mt-1 text-xs leading-5 text-slate-500">Tambahkan unit baru atau hapus unit kosong yang sudah tidak digunakan.</p>
            </div>
            <button type="button" data-unit-manager-close class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-100 text-lg text-slate-500 transition hover:bg-slate-200" aria-label="Tutup">×</button>
        </div>

        @if(session('unit_manager_error'))
            <div class="mt-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2.5 text-xs font-semibold leading-5 text-red-700">{{ session('unit_manager_error') }}</div>
        @endif

        <form method="POST" action="{{ route('units.store') }}" class="mt-5 rounded-xl border border-blue-100 bg-blue-50/60 p-4">
            @csrf
            <label for="new-unit-name" class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Nama unit baru</label>
            <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                <input id="new-unit-name" name="name" value="{{ old('name') }}" required maxlength="100" placeholder="Contoh: UNIT PELAYANAN BARU" class="min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm font-semibold text-slate-800 focus:border-blue-500 focus:ring-blue-500">
                <button class="rounded-xl bg-blue-600 px-4 py-3 text-xs font-bold text-white transition hover:bg-blue-700">+ Tambah unit</button>
            </div>
            @error('name', 'unitManager')<p class="mt-2 text-xs font-semibold text-red-600">{{ $message }}</p>@enderror
        </form>

        <div class="mt-5">
            <div class="mb-2 flex items-center justify-between">
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Unit tersedia</span>
                <span class="text-[10px] font-semibold text-slate-400">{{ $units->count() }} unit</span>
            </div>
            <div class="max-h-72 space-y-2 overflow-y-auto pr-1">
                @foreach($units as $managedUnit)
                    <div class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-3 py-3">
                        <div class="min-w-0">
                            <strong class="block truncate text-sm text-slate-800">{{ $managedUnit->name }}</strong>
                            <span class="mt-0.5 block text-[10px] text-slate-400">{{ number_format($managedUnit->analyses_count ?? 0) }} hasil analisis</span>
                        </div>
                        @if(($managedUnit->analyses_count ?? 0) > 0)
                            <span class="shrink-0 rounded-lg bg-slate-100 px-2.5 py-2 text-[10px] font-bold text-slate-400" title="Pindahkan atau hapus hasil analisis terlebih dahulu">Masih dipakai</span>
                        @else
                            <form method="POST" action="{{ route('units.destroy', $managedUnit) }}" onsubmit="return confirm('Hapus unit kosong ini?')">
                                @csrf @method('DELETE')
                                <button class="shrink-0 rounded-lg px-2.5 py-2 text-[10px] font-bold text-red-600 transition hover:bg-red-50">Hapus</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</dialog>
