<?php

namespace App\Services;

use App\Models\AnalysisBatch;
use Illuminate\Support\Facades\DB;

class ComparisonUnitSnapshotService
{
    public function fromBatch(AnalysisBatch $batch): array
    {
        $snapshot = $batch->summary_data ?? [];
        $summary = $snapshot['summary'] ?? [];
        $profiles = DB::table('analysis_client_summaries')
            ->where('analysis_batch_id', $batch->id)
            ->orderByDesc('habit_score')
            ->orderByDesc('risk_score')
            ->get()
            ->map(fn ($client) => [
                'identity' => $client->identity_value ?: $client->srcip,
                'identity_type' => $client->identity_type ?: 'ip',
                'ip' => $client->srcip,
                'associated_ips' => json_decode($client->associated_ips ?: '[]', true) ?: [],
                'name' => $client->client_name ?: 'Tidak tersedia pada log',
                'total_access' => (int) $client->total_access,
                'total_upload' => (int) $client->total_upload,
                'total_download' => (int) $client->total_download,
                'total_bytes' => (int) $client->total_bytes,
                'total_duration' => (int) $client->total_duration,
                'average_duration' => (float) $client->average_duration,
                'work_hours_count' => (int) $client->work_hours_count,
                'off_hours_count' => (int) $client->off_hours_count,
                'active_hour_count' => (int) $client->active_hour_count,
                'dominant_hour' => $client->dominant_hour,
                'denied_count' => (int) $client->denied_count,
                'timeout_count' => (int) $client->timeout_count,
                'reset_count' => (int) $client->reset_count,
                'nonstandard_port_count' => (int) $client->nonstandard_port_count,
                'unique_apps' => (int) $client->unique_apps,
                'unique_destinations' => (int) $client->unique_destinations,
                'critical_count' => (int) $client->critical_count,
                'high_count' => (int) $client->high_count,
                'elevated_count' => (int) $client->elevated_count,
                'medium_count' => (int) $client->medium_count,
                'score' => (float) $client->risk_score,
                'level' => $client->risk_level,
                'reason' => $client->risk_reason,
                'habit_score' => (float) $client->habit_score,
                'habit_level' => $client->habit_level,
                'habit_reason' => $client->habit_reason,
                'combined_status' => $client->combined_status,
                'combined_label' => $client->combined_label,
                'top_app' => data_get(json_decode($client->top_apps ?: '[]', true), '0.app', ''),
                'first_seen' => $client->first_access_at,
                'last_seen' => $client->last_access_at,
            ])
            ->values();

        $riskDistribution = $snapshot['risk_distribution'] ?? ['low' => 0, 'medium' => 0, 'high' => 0];
        $habitDistribution = $snapshot['habit_distribution'] ?? ['low' => 0, 'medium' => 0, 'high' => 0];

        return [
            'total_clients' => $profiles->count(),
            'total_access' => (int) ($summary['total_logs'] ?? 0),
            'unique_apps' => (int) ($summary['unique_apps'] ?? 0),
            'total_upload' => (int) ($summary['total_upload'] ?? 0),
            'total_download' => (int) ($summary['total_download'] ?? 0),
            'total_duration' => (int) ($summary['total_duration'] ?? 0),
            'work_hours_count' => (int) ($summary['work_hours_count'] ?? 0),
            'off_hours_count' => (int) ($summary['off_hours_count'] ?? 0),
            'peak_hour' => $summary['peak_hour'] ?? null,
            'top_app' => $summary['top_app'] ?? null,
            'top_action' => $summary['top_action'] ?? null,
            'top_risk' => $summary['top_risk'] ?? null,
            'first_seen' => $summary['first_seen'] ?? null,
            'last_seen' => $summary['last_seen'] ?? null,
            'total_denied' => (int) ($summary['total_denied'] ?? 0),
            'risky_clients' => (int) ($riskDistribution['medium'] + $riskDistribution['high']),
            'anomalous_clients' => (int) ($habitDistribution['medium'] + $habitDistribution['high']),
            'risk_score' => (float) data_get($snapshot, 'unit_risk.score', 0),
            'risk_level' => (string) data_get($snapshot, 'unit_risk.level', 'low'),
            'habit_score' => (float) data_get($snapshot, 'unit_habit.score', 0),
            'risk_distribution' => $riskDistribution,
            'habit_distribution' => $habitDistribution,
            'top_risky_clients' => $profiles->where('level', '!=', 'low')->take(10)->values()->all(),
            'client_profiles' => $profiles->all(),
            'outliers' => $snapshot['outliers'] ?? [],
        ];
    }
}
