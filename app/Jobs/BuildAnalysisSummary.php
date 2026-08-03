<?php

namespace App\Jobs;

use App\Models\AnalysisBatch;
use App\Services\HabitAssessmentService;
use App\Services\RiskAssessmentService;
use App\Services\TrafficIdentityResolver;
use App\Services\TrafficSummaryAccumulator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class BuildAnalysisSummary implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $batchId) {}

    public function handle(
        RiskAssessmentService $riskService,
        ?HabitAssessmentService $habitService = null,
        ?TrafficIdentityResolver $identityResolver = null,
        ?TrafficSummaryAccumulator $accumulator = null
    ): void {
        $habitService ??= app(HabitAssessmentService::class);
        $identityResolver ??= app(TrafficIdentityResolver::class);
        $accumulator ??= app(TrafficSummaryAccumulator::class);

        $batch = AnalysisBatch::findOrFail($this->batchId);
        $this->progress($batch, 2, 'Menyiapkan data ringkasan');
        $this->backfillLegacyIdentities($batch->id, $identityResolver);

        $aggregate = $accumulator->accumulate(
            $batch->id,
            (int) $batch->processed_lines,
            function (int $processed, int $total) use ($batch): void {
                $percent = 5 + (int) floor(min(1, $processed / max(1, $total)) * 75);
                $this->progress(
                    $batch,
                    $percent,
                    'Meringkas '.number_format($processed).' dari '.number_format($total).' event'
                );
            }
        );

        /** @var Collection<int, object> $rawClients */
        $rawClients = $aggregate['rawClients'];
        $baseline = [
            'traffic_per_event' => $this->median($rawClients->map(
                fn ($row) => $row->total_access > 0 ? $row->total_bytes / $row->total_access : 0
            )),
            'unique_apps' => $this->median($rawClients->pluck('unique_apps')),
            'unique_destinations' => $this->median($rawClients->pluck('unique_destinations')),
        ];

        DB::table('analysis_client_summaries')->where('analysis_batch_id', $batch->id)->delete();

        $riskAssessments = collect();
        $habitAssessments = collect();
        $combinedDistribution = ['normal' => 0, 'risk' => 0, 'habit' => 0, 'priority' => 0];
        $clientRows = [];
        $clientCount = $rawClients->count();
        $timestamp = now();
        $this->progress($batch, 82, 'Menghitung skor setiap user');

        foreach ($rawClients as $clientIndex => $row) {
            $risk = $riskService->assess($row);
            $habit = $habitService->assess($row, $baseline);
            $combined = $habitService->combine($risk, $habit);
            $riskAssessments->push($risk);
            $habitAssessments->push($habit);
            $combinedDistribution[$combined['key']]++;

            $clientRows[] = [
                'analysis_batch_id' => $batch->id,
                'identity_key' => $row->identity_key,
                'identity_type' => $row->identity_type ?: 'ip',
                'identity_value' => $row->identity_value ?: $row->srcip,
                'srcip' => $row->srcip ?: $row->identity_value ?: '-',
                'client_name' => $row->client_name,
                'source_interface' => $row->source_interface,
                'associated_ips' => json_encode($row->associated_ips, JSON_THROW_ON_ERROR),
                'total_access' => $row->total_access,
                'total_upload' => $row->total_upload,
                'total_download' => $row->total_download,
                'total_bytes' => $row->total_bytes,
                'total_duration' => $row->total_duration,
                'average_duration' => $row->average_duration,
                'work_hours_count' => $row->work_hours_count,
                'off_hours_count' => $row->off_hours_count,
                'active_hour_count' => $row->active_hour_count,
                'dominant_hour' => $row->dominant_hour,
                'first_access_at' => $row->first_access_at,
                'last_access_at' => $row->last_access_at,
                'denied_count' => $row->denied_count,
                'timeout_count' => $row->timeout_count,
                'reset_count' => $row->reset_count,
                'nonstandard_port_count' => $row->nonstandard_port_count,
                'unique_apps' => $row->unique_apps,
                'unique_destinations' => $row->unique_destinations,
                'critical_count' => $row->critical_count,
                'high_count' => $row->high_count,
                'elevated_count' => $row->elevated_count,
                'medium_count' => $row->medium_count,
                'risk_score' => $risk['score'],
                'risk_level' => $risk['level'],
                'risk_label' => $risk['label'],
                'risk_reason' => $risk['reason'],
                'habit_score' => $habit['score'],
                'habit_level' => $habit['level'],
                'habit_label' => $habit['label'],
                'habit_reason' => $habit['reason'],
                'habit_components' => json_encode($habit['components'], JSON_THROW_ON_ERROR),
                'combined_status' => $combined['key'],
                'combined_label' => $combined['label'],
                'risky_count' => $risk['risky_events'],
                'top_apps' => json_encode($row->top_apps, JSON_THROW_ON_ERROR),
                'top_countries' => json_encode($row->top_countries, JSON_THROW_ON_ERROR),
                'top_policies' => json_encode($row->top_policies, JSON_THROW_ON_ERROR),
                'profile_data' => json_encode($row->profile_data, JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            if (count($clientRows) >= 500) {
                DB::table('analysis_client_summaries')->insert($clientRows);
                $clientRows = [];
            }

            if (($clientIndex + 1) % 100 === 0) {
                $percent = 82 + (int) floor((($clientIndex + 1) / max(1, $clientCount)) * 10);
                $this->progress($batch, min(92, $percent), 'Menghitung skor setiap user');
            }
        }

        if ($clientRows !== []) {
            DB::table('analysis_client_summaries')->insert($clientRows);
        }

        $this->progress($batch, 95, 'Menyimpan dashboard dan grafik');
        $snapshot = $this->snapshot(
            $aggregate,
            $baseline,
            $riskAssessments,
            $habitAssessments,
            $combinedDistribution,
            $riskService,
            $habitService
        );

        $batch->update([
            'summary_data' => $snapshot,
            'status' => 'completed',
            'summary_progress' => 100,
            'summary_stage' => 'Selesai',
            'published_at' => now(),
        ]);

        if ($batch->organizational_unit_id) {
            PurgeRawAnalysisData::dispatch($batch->id);
        }
    }

    public function failed(Throwable $error): void
    {
        AnalysisBatch::whereKey($this->batchId)->update([
            'status' => 'failed',
            'summary_stage' => 'Penyusunan ringkasan gagal',
            'failure_message' => mb_strimwidth(
                'Penyusunan ringkasan gagal: '.$error->getMessage(),
                0,
                1000,
                '…'
            ),
        ]);
    }

    private function snapshot(
        array $aggregate,
        array $baseline,
        Collection $riskAssessments,
        Collection $habitAssessments,
        array $combinedDistribution,
        RiskAssessmentService $riskService,
        HabitAssessmentService $habitService
    ): array {
        $summary = $aggregate['summaryRow'];
        $topApps = $aggregate['topApps'];
        $topCountries = $aggregate['topCountries'];
        $activityTrend = $aggregate['activityTrend'];
        $unitHourly = $aggregate['unitHourly'];
        $actionDistribution = $aggregate['actionDistribution'];
        $riskDistribution = $aggregate['riskLevelDistribution'];
        $peakHour = $unitHourly->sortByDesc('total')->first();

        return [
            'summary' => [
                'total_logs' => (int) $summary->total_logs,
                'total_clients' => (int) $summary->total_clients,
                'unique_apps' => (int) $summary->unique_apps,
                'total_upload' => (int) $summary->total_upload,
                'total_download' => (int) $summary->total_download,
                'total_duration' => (int) $summary->total_duration,
                'total_denied' => (int) $summary->total_denied,
                'total_timeout' => (int) $summary->total_timeout,
                'total_reset' => (int) $summary->total_reset,
                'total_risk_events' => (int) $summary->total_risk_events,
                'work_hours_count' => (int) $summary->work_hours_count,
                'off_hours_count' => (int) $summary->off_hours_count,
                'peak_hour' => $peakHour ? (int) $peakHour->hour : null,
                'top_app' => $topApps->first()->app ?? null,
                'top_action' => $actionDistribution->first()->action ?? null,
                'top_risk' => $riskDistribution->first()->apprisk ?? null,
                'first_seen' => $summary->first_seen,
                'last_seen' => $summary->last_seen,
            ],
            'top_apps' => $topApps->map(fn ($row) => ['app' => $row->app, 'total' => (int) $row->total])->all(),
            'top_countries' => $topCountries->map(
                fn ($row) => ['dstcountry' => $row->dstcountry, 'total' => (int) $row->total]
            )->all(),
            'activity_trend' => $activityTrend->map(fn ($row) => [
                'period' => $row->period,
                'total' => (int) $row->total,
                'total_upload' => (int) $row->total_upload,
                'total_download' => (int) $row->total_download,
            ])->all(),
            'hourly_activity' => $unitHourly->map(
                fn ($row) => ['hour' => (int) $row->hour, 'total' => (int) $row->total]
            )->values()->all(),
            'action_distribution' => $actionDistribution->map(
                fn ($row) => ['action' => $row->action, 'total' => (int) $row->total]
            )->all(),
            'app_risk_distribution' => $riskDistribution->map(
                fn ($row) => ['apprisk' => $row->apprisk, 'total' => (int) $row->total]
            )->all(),
            'unit_risk' => $riskService->assessUnit($riskAssessments),
            'unit_habit' => $habitService->assessUnit($habitAssessments),
            'risk_distribution' => [
                'low' => $riskAssessments->where('level', 'low')->count(),
                'medium' => $riskAssessments->where('level', 'medium')->count(),
                'high' => $riskAssessments->where('level', 'high')->count(),
            ],
            'habit_distribution' => [
                'low' => $habitAssessments->where('level', 'low')->count(),
                'medium' => $habitAssessments->where('level', 'medium')->count(),
                'high' => $habitAssessments->where('level', 'high')->count(),
            ],
            'combined_distribution' => $combinedDistribution,
            'habit_baseline' => $baseline,
            'outliers' => $aggregate['outliers'],
        ];
    }

    private function progress(AnalysisBatch $batch, int $percent, string $stage): void
    {
        $batch->forceFill([
            'status' => 'summarizing',
            'summary_progress' => min(99, max(0, $percent)),
            'summary_stage' => $stage,
        ])->save();
    }

    private function median(Collection $values): float
    {
        $values = $values->map(fn ($value) => (float) $value)
            ->filter(fn ($value) => $value >= 0)
            ->sort()
            ->values();
        $count = $values->count();
        if ($count === 0) {
            return 0;
        }

        $middle = intdiv($count, 2);

        return $count % 2
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function backfillLegacyIdentities(int $batchId, TrafficIdentityResolver $resolver): void
    {
        $query = DB::table('traffic_logs')
            ->where('analysis_batch_id', $batchId)
            ->whereNull('identity_key');
        if (! (clone $query)->exists()) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "UPDATE traffic_logs
                SET user = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(extra_fields, '$.user')), 'null'),
                    srcserver = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(extra_fields, '$.srcserver')), 'null')
                WHERE analysis_batch_id = ? AND identity_key IS NULL",
                [$batchId]
            );

            $valid = "IS NOT NULL AND TRIM(%s) <> '' AND LOWER(TRIM(%s)) NOT IN ('-', 'n/a', 'na', 'null', 'unknown', 'tidak tersedia', '0')";
            $userValid = sprintf($valid, 'user', 'user');
            $nameValid = sprintf($valid, 'srcname', 'srcname');
            $serverValid = sprintf($valid, 'srcserver', 'srcserver');
            $ipValid = sprintf($valid, 'srcip', 'srcip');
            $valueCase = "CASE
                WHEN user {$userValid} THEN TRIM(user)
                WHEN srcname {$nameValid} THEN TRIM(srcname)
                WHEN srcserver {$serverValid} THEN TRIM(srcserver)
                WHEN srcip {$ipValid} THEN TRIM(srcip)
                ELSE NULL END";
            $typeCase = "CASE
                WHEN user {$userValid} THEN 'user'
                WHEN srcname {$nameValid} THEN 'device'
                WHEN srcserver {$serverValid} THEN 'server'
                WHEN srcip {$ipValid} THEN 'ip'
                ELSE NULL END";

            DB::statement(
                "UPDATE traffic_logs
                SET identity_value = {$valueCase},
                    identity_type = {$typeCase},
                    total_bytes = COALESCE(sentbyte, 0) + COALESCE(rcvdbyte, 0)
                WHERE analysis_batch_id = ? AND identity_key IS NULL",
                [$batchId]
            );
            DB::statement(
                "UPDATE traffic_logs
                SET identity_key = SHA2(CONCAT(identity_type, '|', LOWER(identity_value)), 256)
                WHERE analysis_batch_id = ? AND identity_key IS NULL AND identity_value IS NOT NULL",
                [$batchId]
            );

            return;
        }

        DB::table('traffic_logs')
            ->where('analysis_batch_id', $batchId)
            ->whereNull('identity_key')
            ->select('id', 'user', 'srcname', 'srcserver', 'srcip', 'sentbyte', 'rcvdbyte', 'extra_fields')
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use ($resolver) {
                foreach ($rows as $row) {
                    $extra = json_decode($row->extra_fields ?: '[]', true) ?: [];
                    $fields = [
                        'user' => $row->user ?: ($extra['user'] ?? null),
                        'srcname' => $row->srcname,
                        'srcserver' => $row->srcserver ?: ($extra['srcserver'] ?? null),
                        'srcip' => $row->srcip,
                    ];
                    $identity = $resolver->resolve($fields);
                    DB::table('traffic_logs')->where('id', $row->id)->update([
                        'user' => $fields['user'],
                        'srcserver' => $fields['srcserver'],
                        'identity_key' => $identity['key'] ?? null,
                        'identity_type' => $identity['type'] ?? null,
                        'identity_value' => $identity['value'] ?? null,
                        'total_bytes' => min(PHP_INT_MAX, (int) $row->sentbyte + (int) $row->rcvdbyte),
                    ]);
                }
            });
    }
}
