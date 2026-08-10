<?php

namespace App\Http\Controllers;

use App\Models\OrganizationalUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function index()
    {
        $units = OrganizationalUnit::query()
            ->where('is_active', true)
            ->with([
                'latestAnalysis',
                'latestCompletedAnalysis',
            ])
            ->withCount('analyses')
            ->orderBy('sort_order')
            ->get();

        return view('units.index', compact('units'));
    }

    public function show(OrganizationalUnit $unit)
    {
        $analyses = $unit->analyses()
            ->latest('id')
            ->paginate(10);
        $latest = $unit->analyses()
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();
        $running = $unit->analyses()
            ->whereIn('status', ['uploaded', 'processing', 'summarizing'])
            ->latest('id')
            ->first();
        $allUnits = OrganizationalUnit::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return view('units.show', compact('unit', 'analyses', 'latest', 'running', 'allUnits'));
    }

    public function store(Request $request)
    {
        $request->merge([
            'name' => mb_strtoupper(trim((string) $request->input('name'))),
        ]);

        $validated = $request->validateWithBag('unitManager', [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('organizational_units', 'name'),
            ],
        ]);

        $name = $validated['name'];
        $baseSlug = Str::slug($name) ?: 'unit';
        $slug = $baseSlug;
        $suffix = 2;

        while (OrganizationalUnit::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        OrganizationalUnit::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => $slug,
            'sort_order' => ((int) OrganizationalUnit::max('sort_order')) + 1,
            'is_active' => true,
        ]);

        return back()->with('success', "Unit {$name} berhasil ditambahkan.");
    }

    public function destroy(OrganizationalUnit $unit)
    {
        if ($unit->networkAssets()->exists() || $unit->topologyDatasets()->exists()) {
            return back()->with(
                'unit_manager_error',
                "Unit {$unit->name} masih digunakan oleh data aset atau topologi. Pindahkan data tersebut terlebih dahulu."
            );
        }

        if ($unit->analyses()->exists()) {
            return back()->with(
                'unit_manager_error',
                "Unit {$unit->name} masih memiliki hasil analisis. Hapus atau pindahkan hasilnya terlebih dahulu."
            );
        }

        if (OrganizationalUnit::query()->where('is_active', true)->count() <= 1) {
            return back()->with('unit_manager_error', 'Minimal satu unit harus tetap tersedia.');
        }

        $name = $unit->name;
        $unit->delete();

        return back()->with('success', "Unit {$name} berhasil dihapus.");
    }
}
