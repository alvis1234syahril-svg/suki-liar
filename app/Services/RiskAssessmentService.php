<?php

namespace App\Services;

final class RiskAssessmentService
{
    public function assess(array|object $metrics): array
    {
        $value = fn (string $key): float => (float) (is_array($metrics)
            ? ($metrics[$key] ?? 0)
            : ($metrics->{$key} ?? 0));

        $total = max(0, $value('total_access'));
        $critical = $value('critical_count');
        $high = $value('high_count');
        $elevated = $value('elevated_count');
        $medium = $value('medium_count');
        $denied = $value('denied_count');
        $confidence = min(1, $total / 20);

        $weightedExposure = $total > 0
            ? (($critical * 4 + $high * 3 + $elevated * 2 + $medium) / ($total * 4)) * 60
            : 0;
        $deniedContribution = $total > 0
            ? min(25, ($denied / $total) * 100)
            : 0;
        $repetitionContribution = min(15, ($critical + $high) / 5);

        $score = round(min(
            100,
            (($weightedExposure + $deniedContribution) * $confidence) + $repetitionContribution
        ), 1);
        $exposurePoints = round($weightedExposure * $confidence, 1);
        $deniedPoints = round($deniedContribution * $confidence, 1);
        $repetitionPoints = round($repetitionContribution, 1);

        $level = $score >= 55 ? 'high' : ($score >= 30 ? 'medium' : 'low');

        return [
            'score' => $score,
            'level' => $level,
            'label' => ['high' => 'Tinggi', 'medium' => 'Sedang', 'low' => 'Rendah'][$level],
            'risky_events' => (int) ($critical + $high + $elevated + $medium),
            'critical_high_events' => (int) ($critical + $high),
            'denied_rate' => $total > 0 ? round(($denied / $total) * 100, 1) : 0,
            'sample_size' => (int) $total,
            'components' => [
                'application_exposure' => $exposurePoints,
                'denied_activity' => $deniedPoints,
                'repetition' => $repetitionPoints,
                'confidence' => round($confidence * 100, 1),
            ],
            'reason' => $this->reason($critical, $high, $elevated, $medium, $denied, $total),
        ];
    }

    public function assessUnit(iterable $clients): array
    {
        $assessments = [];

        foreach ($clients as $client) {
            $assessments[] = is_array($client) && array_key_exists('score', $client)
                ? $client
                : $this->assess($client);
        }

        if ($assessments === []) {
            return ['score' => 0.0, 'level' => 'low', 'label' => 'Rendah', 'risky_clients' => 0];
        }

        usort($assessments, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $topCount = min(10, max(1, (int) ceil(count($assessments) * 0.2)));
        $score = round(array_sum(array_column(array_slice($assessments, 0, $topCount), 'score')) / $topCount, 1);
        $level = $score >= 55 ? 'high' : ($score >= 30 ? 'medium' : 'low');

        return [
            'score' => $score,
            'level' => $level,
            'label' => ['high' => 'Tinggi', 'medium' => 'Sedang', 'low' => 'Rendah'][$level],
            'risky_clients' => count(array_filter($assessments, fn (array $item) => $item['level'] !== 'low')),
        ];
    }

    private function reason(
        float $critical,
        float $high,
        float $elevated,
        float $medium,
        float $denied,
        float $total
    ): string {
        $parts = [];

        if ($critical + $high > 0) {
            $parts[] = number_format($critical + $high).' event kritis/tinggi';
        }

        if ($elevated + $medium > 0) {
            $parts[] = number_format($elevated + $medium).' event elevated/medium';
        }

        if ($denied > 0 && $total > 0) {
            $parts[] = number_format(($denied / $total) * 100, 1).'% koneksi ditolak';
        }

        return $parts === [] ? 'Tidak ada pola risiko yang signifikan' : implode(', ', $parts);
    }
}
