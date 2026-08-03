<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class TrafficSummaryAccumulator
{
    private const STANDARD_PORTS = [
        20, 21, 22, 25, 53, 67, 68, 80, 110, 123, 143, 161, 162, 389, 443,
        445, 465, 514, 587, 636, 853, 993, 995, 1433, 1521, 3306, 3389, 5432,
        8080, 8443,
    ];

    public function accumulate(int $batchId, int $expectedRows = 0, ?callable $progress = null): array
    {
        $summary = [
            'total_logs' => 0, 'total_clients' => 0, 'unique_apps' => 0,
            'total_upload' => 0, 'total_download' => 0, 'total_duration' => 0,
            'total_denied' => 0, 'total_timeout' => 0, 'total_reset' => 0,
            'work_hours_count' => 0, 'off_hours_count' => 0,
            'first_seen' => null, 'last_seen' => null, 'total_risk_events' => 0,
        ];
        $clients = [];
        $apps = [];
        $countries = [];
        $actions = [];
        $risks = [];
        $days = [];
        $hours = [];
        $trafficOutliers = [];
        $durationOutliers = [];
        $processed = 0;

        $columns = [
            'id', 'logged_at', 'identity_key', 'identity_type', 'identity_value',
            'srcip', 'srcname', 'srcintf', 'sentbyte', 'rcvdbyte', 'total_bytes',
            'duration', 'action', 'dstport', 'app', 'service', 'appcat', 'dstip',
            'dstcountry', 'policyname', 'apprisk',
        ];

        DB::table('traffic_logs')
            ->where('analysis_batch_id', $batchId)
            ->select($columns)
            ->lazyById(5000)
            ->each(function (object $row) use (
                &$summary, &$clients, &$apps, &$countries, &$actions, &$risks,
                &$days, &$hours, &$trafficOutliers, &$durationOutliers,
                &$processed, $expectedRows, $progress
            ) {
                $processed++;
                $upload = (int) ($row->sentbyte ?? 0);
                $download = (int) ($row->rcvdbyte ?? 0);
                $bytes = (int) ($row->total_bytes ?? ($upload + $download));
                $duration = (int) ($row->duration ?? 0);
                $action = strtolower(trim((string) ($row->action ?? '')));
                $risk = strtolower(trim((string) ($row->apprisk ?? '')));
                $app = $this->appName($row);
                [$day, $hour, $isWorkHours] = $this->timeParts($row->logged_at);

                $summary['total_logs']++;
                $summary['total_upload'] += $upload;
                $summary['total_download'] += $download;
                $summary['total_duration'] += $duration;
                $summary['total_denied'] += (int) ($action === 'deny');
                $summary['total_timeout'] += (int) ($action === 'timeout');
                $summary['total_reset'] += (int) $this->isReset($action);
                $summary['total_risk_events'] += (int) in_array($risk, ['medium', 'elevated', 'high', 'critical'], true);
                $summary['first_seen'] = $this->minimumDate($summary['first_seen'], $row->logged_at);
                $summary['last_seen'] = $this->maximumDate($summary['last_seen'], $row->logged_at);

                $apps[$app] = ($apps[$app] ?? 0) + 1;
                $this->incrementIfPresent($countries, $row->dstcountry);
                $this->incrementIfPresent($actions, $row->action);
                $this->incrementIfPresent($risks, $row->apprisk);

                if ($day !== null && $hour !== null) {
                    $hours[$hour] = ($hours[$hour] ?? 0) + 1;
                    $days[$day] ??= ['total' => 0, 'total_upload' => 0, 'total_download' => 0];
                    $days[$day]['total']++;
                    $days[$day]['total_upload'] += $upload;
                    $days[$day]['total_download'] += $download;
                    $summary[$isWorkHours ? 'work_hours_count' : 'off_hours_count']++;
                }

                $identity = trim((string) ($row->identity_key ?? ''));
                if ($identity !== '') {
                    $client = &$clients[$identity];
                    if (! isset($client)) {
                        $client = $this->newClient($row);
                    }

                    $client->total_access++;
                    $client->total_upload += $upload;
                    $client->total_download += $download;
                    $client->total_bytes += $bytes;
                    $client->total_duration += $duration;
                    if ($duration > 0) {
                        $client->_duration_samples++;
                    }
                    $client->first_access_at = $this->minimumDate($client->first_access_at, $row->logged_at);
                    $client->last_access_at = $this->maximumDate($client->last_access_at, $row->logged_at);
                    $client->denied_count += (int) ($action === 'deny');
                    $client->timeout_count += (int) ($action === 'timeout');
                    $client->reset_count += (int) $this->isReset($action);
                    $client->nonstandard_port_count += (int) (
                        $row->dstport !== null && ! in_array((int) $row->dstport, self::STANDARD_PORTS, true)
                    );
                    $client->critical_count += (int) ($risk === 'critical');
                    $client->high_count += (int) ($risk === 'high');
                    $client->elevated_count += (int) ($risk === 'elevated');
                    $client->medium_count += (int) ($risk === 'medium');
                    $client->_apps[$app] = ($client->_apps[$app] ?? 0) + 1;
                    $this->incrementIfPresent($client->_destinations, $row->dstip);
                    $this->incrementIfPresent($client->_countries, $row->dstcountry);
                    $this->incrementIfPresent($client->_policies, $row->policyname);
                    $this->incrementIfPresent($client->_ips, $row->srcip);
                    $this->incrementIfPresent($client->_actions, $row->action);

                    if ($day !== null) {
                        $client->_days[$day] = ($client->_days[$day] ?? 0) + 1;
                    }

                    $destination = trim((string) ($row->dstip ?? ''));
                    if ($destination !== '') {
                        $destinationStats = &$client->_destination_stats[$destination];
                        if (! isset($destinationStats)) {
                            $destinationStats = [
                                'dstip' => $destination,
                                'dstcountry' => $row->dstcountry,
                                'service' => $row->service,
                                'total_access' => 0,
                                'total_upload' => 0,
                                'total_download' => 0,
                            ];
                        }
                        $destinationStats['total_access']++;
                        $destinationStats['total_upload'] += $upload;
                        $destinationStats['total_download'] += $download;
                    }

                    $appStats = &$client->_app_stats[$app];
                    if (! isset($appStats)) {
                        $appStats = [
                            'app' => $app, 'appcat' => $row->appcat, 'apprisk' => $row->apprisk,
                            'total_access' => 0, 'first_access_at' => null, 'last_access_at' => null,
                            'total_upload' => 0, 'total_download' => 0,
                        ];
                    }
                    $appStats['total_access']++;
                    $appStats['total_upload'] += $upload;
                    $appStats['total_download'] += $download;
                    $appStats['first_access_at'] = $this->minimumDate($appStats['first_access_at'], $row->logged_at);
                    $appStats['last_access_at'] = $this->maximumDate($appStats['last_access_at'], $row->logged_at);

                    if ($hour !== null) {
                        $client->_hours[$hour] = ($client->_hours[$hour] ?? 0) + 1;
                        $client->{$isWorkHours ? 'work_hours_count' : 'off_hours_count'}++;
                    }

                    $outlier = $this->outlierRow($row, $app, $isWorkHours);
                    $this->retainLargest($trafficOutliers, $outlier, 'total_bytes');
                    $this->retainLargest($durationOutliers, $outlier, 'duration');
                    unset($client, $appStats, $destinationStats);
                }

                if ($progress && $processed % 5000 === 0) {
                    $progress($processed, max($expectedRows, $processed));
                }
            });

        $hourByIdentity = [];
        foreach ($clients as $identity => $client) {
            arsort($client->_hours);
            $hourByIdentity[$identity] = $client->_hours;
            $client->dominant_hour = $client->_hours === [] ? null : (int) array_key_first($client->_hours);
            $client->active_hour_count = count($client->_hours);
            $client->unique_apps = count($client->_apps);
            $client->unique_destinations = count($client->_destinations);
            $client->average_duration = $client->_duration_samples > 0
                ? $client->total_duration / $client->_duration_samples
                : 0;
            $client->top_apps = $this->topRows($client->_app_stats, 3, 'total_access');
            $client->top_countries = $this->namedTopRows($client->_countries, 'dstcountry', 3);
            $client->top_policies = $this->namedTopRows($client->_policies, 'policyname', 2);
            $client->associated_ips = $this->namedTopRows($client->_ips, 'ip', 10, 'total');
            ksort($client->_days);
            $client->profile_data = [
                'hourly_activity' => collect(range(0, 23))->map(fn (int $hour) => [
                    'hour' => $hour,
                    'total' => (int) ($client->_hours[$hour] ?? 0),
                ])->all(),
                'daily_activity' => collect(array_slice($client->_days, -30, 30, true))
                    ->map(fn (int $total, string $period) => compact('period', 'total'))
                    ->values()
                    ->all(),
                'action_distribution' => $this->namedTopRows($client->_actions, 'action', 8),
                'top_destinations' => $this->topRows($client->_destination_stats, 10, 'total_access'),
            ];
            unset(
                $client->_hours, $client->_apps, $client->_destinations,
                $client->_countries, $client->_policies, $client->_ips,
                $client->_app_stats, $client->_duration_samples, $client->_actions,
                $client->_days, $client->_destination_stats
            );
        }

        $summary['total_clients'] = count($clients);
        $summary['unique_apps'] = count($apps);
        ksort($days);
        ksort($hours);

        return [
            'summaryRow' => (object) $summary,
            'rawClients' => collect(array_values($clients)),
            'topApps' => $this->namedCollection($apps, 'app', 10),
            'topCountries' => $this->namedCollection($countries, 'dstcountry', 10),
            'activityTrend' => collect(array_slice(array_map(
                fn (string $period, array $values) => (object) (['period' => $period] + $values),
                array_keys($days),
                array_values($days)
            ), 0, 30)),
            'actionDistribution' => $this->namedCollection($actions, 'action', 12),
            'riskLevelDistribution' => $this->namedCollection($risks, 'apprisk', 8),
            'unitHourly' => collect(array_map(
                fn (int $hour, int $total) => (object) compact('hour', 'total'),
                array_keys($hours),
                array_values($hours)
            )),
            'hourByIdentity' => $hourByIdentity,
            'topAppsByClient' => collect($clients)->mapWithKeys(fn ($client, $key) => [$key => $client->top_apps])->all(),
            'topCountriesByClient' => collect($clients)->mapWithKeys(fn ($client, $key) => [$key => $client->top_countries])->all(),
            'topPoliciesByClient' => collect($clients)->mapWithKeys(fn ($client, $key) => [$key => $client->top_policies])->all(),
            'associatedIps' => collect($clients)->mapWithKeys(fn ($client, $key) => [$key => $client->associated_ips])->all(),
            'outliers' => collect($trafficOutliers)->map(fn ($row) => ['type' => 'Traffic terbesar'] + $row)
                ->concat(collect($durationOutliers)->map(fn ($row) => ['type' => 'Durasi terpanjang'] + $row))
                ->unique(fn ($row) => $row['type'].'|'.$row['id'])->values()->all(),
        ];
    }

    private function newClient(object $row): object
    {
        return (object) [
            'identity_key' => $row->identity_key,
            'identity_type' => $row->identity_type ?: 'ip',
            'identity_value' => $row->identity_value ?: $row->srcip,
            'srcip' => $row->srcip,
            'client_name' => $row->srcname,
            'source_interface' => $row->srcintf,
            'total_access' => 0, 'total_upload' => 0, 'total_download' => 0,
            'total_bytes' => 0, 'total_duration' => 0, 'average_duration' => 0,
            'first_access_at' => null, 'last_access_at' => null,
            'work_hours_count' => 0, 'off_hours_count' => 0, 'active_hour_count' => 0,
            'dominant_hour' => null, 'denied_count' => 0, 'timeout_count' => 0,
            'reset_count' => 0, 'nonstandard_port_count' => 0, 'unique_apps' => 0,
            'unique_destinations' => 0, 'critical_count' => 0, 'high_count' => 0,
            'elevated_count' => 0, 'medium_count' => 0,
            '_duration_samples' => 0, '_hours' => [], '_apps' => [],
            '_destinations' => [], '_countries' => [], '_policies' => [],
            '_ips' => [], '_app_stats' => [], '_actions' => [], '_days' => [],
            '_destination_stats' => [],
        ];
    }

    private function appName(object $row): string
    {
        foreach ([$row->app, $row->service, $row->appcat, $row->dstport] as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return 'Tidak teridentifikasi';
    }

    private function timeParts(?string $value): array
    {
        if (! $value || ($timestamp = strtotime($value)) === false) {
            return [null, null, false];
        }

        $hour = (int) date('G', $timestamp);
        $weekday = (int) date('N', $timestamp);

        return [date('Y-m-d', $timestamp), $hour, $weekday <= 5 && $hour >= 8 && $hour < 17];
    }

    private function isReset(string $action): bool
    {
        return str_contains($action, 'rst') || str_contains($action, 'reset');
    }

    private function incrementIfPresent(array &$values, mixed $value): void
    {
        $key = trim((string) ($value ?? ''));
        if ($key !== '') {
            $values[$key] = ($values[$key] ?? 0) + 1;
        }
    }

    private function minimumDate(?string $current, ?string $candidate): ?string
    {
        return $candidate && ($current === null || $candidate < $current) ? $candidate : $current;
    }

    private function maximumDate(?string $current, ?string $candidate): ?string
    {
        return $candidate && ($current === null || $candidate > $current) ? $candidate : $current;
    }

    private function namedCollection(array $values, string $name, int $limit): Collection
    {
        arsort($values);

        return collect(array_slice($values, 0, $limit, true))
            ->map(fn (int $total, string $value) => (object) [$name => $value, 'total' => $total])
            ->values();
    }

    private function topRows(array $rows, int $limit, string $score): array
    {
        uasort($rows, fn (array $left, array $right) => $right[$score] <=> $left[$score]);

        return array_values(array_slice($rows, 0, $limit, true));
    }

    private function namedTopRows(array $values, string $name, int $limit, string $countName = 'total_access'): array
    {
        arsort($values);
        $result = [];
        foreach (array_slice($values, 0, $limit, true) as $value => $total) {
            $result[] = [$name => $value, $countName => $total];
        }

        return $result;
    }

    private function retainLargest(array &$rows, array $candidate, string $metric): void
    {
        if (count($rows) >= 15) {
            $smallest = array_key_last($rows);
            if ($smallest !== null && $candidate[$metric] <= $rows[$smallest][$metric]) {
                return;
            }
        }

        $rows[] = $candidate;
        usort($rows, fn (array $left, array $right) => $right[$metric] <=> $left[$metric]);
        if (count($rows) > 15) {
            array_pop($rows);
        }
    }

    private function outlierRow(object $row, string $app, bool $isWorkHours): array
    {
        return [
            'id' => (int) $row->id,
            'logged_at' => $row->logged_at,
            'identity_key' => $row->identity_key,
            'identity_type' => $row->identity_type,
            'identity_value' => $row->identity_value ?: $row->srcip,
            'srcip' => $row->srcip,
            'app' => $app,
            'dstip' => $row->dstip,
            'dstport' => $row->dstport,
            'dstcountry' => $row->dstcountry,
            'action' => $row->action,
            'policyname' => $row->policyname,
            'apprisk' => $row->apprisk,
            'duration' => (int) ($row->duration ?? 0),
            'total_bytes' => (int) ($row->total_bytes ?? 0),
            'period' => $row->logged_at ? ($isWorkHours ? 'Jam kerja' : 'Luar jam kerja') : 'Waktu tidak tersedia',
        ];
    }
}
