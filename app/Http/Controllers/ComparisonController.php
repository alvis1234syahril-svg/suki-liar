<?php

namespace App\Http\Controllers;

use App\Models\OrganizationalUnit;
use Illuminate\Support\Facades\DB;

class ComparisonController extends Controller
{
    public function index()
    {
        $units = OrganizationalUnit::query()
            ->where('is_active', true)
            ->with('latestCompletedAnalysis')
            ->orderBy('sort_order')
            ->get();

        $items = $units->map(function (OrganizationalUnit $unit) {
            $batch = $unit->latestCompletedAnalysis;
            $snapshot = $batch?->summary_data ?? [];

            return (object) [
                'unit' => $unit,
                'batch' => $batch,
                'summary' => $snapshot['summary'] ?? [],
                'unit_risk' => $snapshot['unit_risk'] ?? [],
                'unit_habit' => $snapshot['unit_habit'] ?? [],
                'risk_distribution' => $snapshot['risk_distribution'] ?? [],
                'habit_distribution' => $snapshot['habit_distribution'] ?? [],
                'combined_distribution' => $snapshot['combined_distribution'] ?? [],
            ];
        });

        return view('comparisons.index', compact('items'));
    }

    public function clients(OrganizationalUnit $unit)
    {
        $batch = $unit->analyses()
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();

        if (! $batch) {
            return response()->view('comparisons.partials.clients-empty', compact('unit'));
        }

        $base = DB::table('analysis_client_summaries')
            ->where('analysis_batch_id', $batch->id);
        $attention = (clone $base)
            ->where(function ($query) {
                $query->where('risk_level', '!=', 'low')
                    ->orWhere('habit_level', '!=', 'low');
            })
            ->orderByDesc('habit_score')
            ->orderByDesc('risk_score')
            ->get();
        $healthy = (clone $base)
            ->where('risk_level', 'low')
            ->where('habit_level', 'low')
            ->orderByDesc('total_access')
            ->limit(5)
            ->get();

        return response()->view(
            'comparisons.partials.clients',
            compact('unit', 'batch', 'attention', 'healthy')
        );
    }
}
