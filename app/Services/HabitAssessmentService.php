<?php

namespace App\Services;

final class HabitAssessmentService
{
    public function assess(array|object $metrics, array $baseline = []): array
    {
        $value = fn (string $key): float => (float) (is_array($metrics)
            ? ($metrics[$key] ?? 0)
            : ($metrics->{$key} ?? 0));

        $total = max(0, $value('total_access'));
        $confidence = min(1, $total / 20);
        $offHoursRate = $this->rate($value('off_hours_count'), $total);
        $trafficPerEvent = $total > 0 ? $value('total_bytes') / $total : 0;
        $baselineTraffic = max(1, (float) ($baseline['traffic_per_event'] ?? $trafficPerEvent ?: 1));
        $trafficRatio = $trafficPerEvent / $baselineTraffic;
        $trafficSignal = $trafficRatio <= 1 ? 0 : min(1, log($trafficRatio, 2) / 4);

        $denied = $value('denied_count');
        $timeout = $value('timeout_count');
        $reset = $value('reset_count');
        $attentionWeighted = $denied + (($timeout + $reset) * .35);
        $attentionRate = $this->rate($attentionWeighted, $total);
        $attentionSignal = min(1, $attentionRate / .20);

        $nonstandardRate = $this->rate($value('nonstandard_port_count'), $total);
        $nonstandardContext = min(1, $offHoursRate + $attentionSignal);
        $nonstandardSignal = min(1, $nonstandardRate / .50) * $nonstandardContext;

        $activeHours = min(24, max(0, $value('active_hour_count')));
        $activeHoursSignal = min(1, max(0, $activeHours - 8) / 16);

        $appRatio = $value('unique_apps') / max(1, (float) ($baseline['unique_apps'] ?? $value('unique_apps') ?: 1));
        $destinationRatio = $value('unique_destinations') / max(1, (float) ($baseline['unique_destinations'] ?? $value('unique_destinations') ?: 1));
        $diversityRatio = max($appRatio, $destinationRatio);
        $unusualSignal = $diversityRatio <= 1 ? 0 : min(1, log($diversityRatio, 2) / 3);

        $components = [
            'off_hours' => round(30 * $offHoursRate * $confidence, 1),
            'traffic_deviation' => round(20 * $trafficSignal * $confidence, 1),
            'attention_actions' => round(15 * $attentionSignal * $confidence, 1),
            'nonstandard_ports' => round(15 * $nonstandardSignal * $confidence, 1),
            'active_hours_spread' => round(10 * $activeHoursSignal * $confidence, 1),
            'unusual_diversity' => round(10 * $unusualSignal * $confidence, 1),
        ];
        $score = round(min(100, array_sum($components)), 1);
        $level = $score >= 55 ? 'high' : ($score >= 30 ? 'medium' : 'low');

        return [
            'score' => $score,
            'level' => $level,
            'label' => ['high' => 'Tinggi', 'medium' => 'Sedang', 'low' => 'Rendah'][$level],
            'reason' => $this->reason($components, $offHoursRate, $trafficRatio, $attentionRate, $nonstandardRate, $activeHours, $diversityRatio),
            'components' => $components + ['confidence' => round($confidence * 100, 1)],
            'metrics' => [
                'off_hours_rate' => round($offHoursRate * 100, 1),
                'traffic_per_event' => round($trafficPerEvent, 1),
                'traffic_ratio' => round($trafficRatio, 2),
                'attention_rate' => round($attentionRate * 100, 1),
                'nonstandard_port_rate' => round($nonstandardRate * 100, 1),
                'active_hour_count' => (int) $activeHours,
                'diversity_ratio' => round($diversityRatio, 2),
            ],
        ];
    }

    public function combine(array $risk, array $habit): array
    {
        $riskAttention = ($risk['level'] ?? 'low') !== 'low';
        $habitAttention = ($habit['level'] ?? 'low') !== 'low';

        return match (true) {
            $riskAttention && $habitAttention => [
                'key' => 'priority',
                'label' => 'Prioritas tinggi',
                'reason' => 'Risiko aplikasi dan anomali habit sama-sama melewati ambang.',
            ],
            $riskAttention => [
                'key' => 'risk',
                'label' => 'Risiko aplikasi',
                'reason' => 'Risiko aplikasi meningkat, tetapi pola habit belum menyimpang kuat.',
            ],
            $habitAttention => [
                'key' => 'habit',
                'label' => 'Anomali habit',
                'reason' => 'Aplikasi relatif aman, tetapi pola waktu atau perilakunya tidak biasa.',
            ],
            default => [
                'key' => 'normal',
                'label' => 'Normal',
                'reason' => 'Risiko aplikasi dan pola habit sama-sama berada di bawah ambang.',
            ],
        };
    }

    public function assessUnit(iterable $clients): array
    {
        $assessments = collect($clients)->values();

        if ($assessments->isEmpty()) {
            return ['score' => 0.0, 'level' => 'low', 'label' => 'Rendah', 'anomalous_clients' => 0];
        }

        $topCount = min(10, max(1, (int) ceil($assessments->count() * .2)));
        $score = round((float) $assessments->sortByDesc('score')->take($topCount)->avg('score'), 1);
        $level = $score >= 55 ? 'high' : ($score >= 30 ? 'medium' : 'low');

        return [
            'score' => $score,
            'level' => $level,
            'label' => ['high' => 'Tinggi', 'medium' => 'Sedang', 'low' => 'Rendah'][$level],
            'anomalous_clients' => $assessments->where('level', '!=', 'low')->count(),
        ];
    }

    private function rate(float $part, float $total): float
    {
        return $total > 0 ? min(1, max(0, $part / $total)) : 0;
    }

    private function reason(
        array $components,
        float $offHoursRate,
        float $trafficRatio,
        float $attentionRate,
        float $nonstandardRate,
        float $activeHours,
        float $diversityRatio
    ): string {
        $descriptions = [
            'off_hours' => number_format($offHoursRate * 100, 1).'% aktivitas terjadi di luar jam kerja',
            'traffic_deviation' => 'traffic per event '.number_format($trafficRatio, 1).'× baseline unit',
            'attention_actions' => number_format($attentionRate * 100, 1).'% action deny/timeout/reset berbobot',
            'nonstandard_ports' => number_format($nonstandardRate * 100, 1).'% akses memakai port nonstandar dengan indikator pendamping',
            'active_hours_spread' => 'aktif pada '.number_format($activeHours).' dari 24 jam',
            'unusual_diversity' => 'keragaman aplikasi/tujuan '.number_format($diversityRatio, 1).'× baseline unit',
        ];

        arsort($components);
        $reasons = [];

        foreach ($components as $key => $points) {
            if ($points >= 2 && isset($descriptions[$key])) {
                $reasons[] = $descriptions[$key];
            }
            if (count($reasons) === 3) {
                break;
            }
        }

        return $reasons === [] ? 'Tidak ada penyimpangan habit yang signifikan' : implode('; ', $reasons);
    }
}
