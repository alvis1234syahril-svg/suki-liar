<?php

namespace App\Services;

use App\Models\AnalysisBatch;
use App\Models\NetworkAsset;
use App\Models\OrganizationalUnit;
use App\Models\TopologyDataset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AssetTrafficCorrelationService
{
    /**
     * Correlate the latest published traffic summary with active assets in one unit.
     *
     * This service is deliberately observational. It never changes asset lifecycle,
     * topology mappings, or parent/child relationships.
     *
     * @param  Collection<int, NetworkAsset>  $assets
     * @return array<string, mixed>
     */
    public function forUnit(
        OrganizationalUnit $unit,
        Collection $assets,
        ?TopologyDataset $dataset = null
    ): array {
        $batch = AnalysisBatch::query()
            ->where('organizational_unit_id', $unit->id)
            ->where('status', 'completed')
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        if (! $batch) {
            return $this->emptyResult();
        }

        $activeAssets = $assets
            ->where('lifecycle_status', '!=', 'replaced')
            ->keyBy('id');
        $candidates = [];

        foreach ($activeAssets as $asset) {
            foreach ($this->ipAddresses($asset->ip_address) as $ip) {
                $this->addCandidate($candidates, $ip, (int) $asset->id, 'asset_ip');
            }
        }

        if ($dataset) {
            foreach ($dataset->nodes as $node) {
                $mappedAsset = $node->assetMapping?->networkAsset;
                if (
                    ! $mappedAsset
                    || (int) $mappedAsset->organizational_unit_id !== (int) $unit->id
                    || ! $activeAssets->has($mappedAsset->id)
                ) {
                    continue;
                }

                foreach ($this->interfaceAddresses($node->interfaces ?? []) as $ip) {
                    $this->addCandidate($candidates, $ip, (int) $mappedAsset->id, 'topology_interface');
                }
            }
        }

        $assetObservations = [];
        $ambiguousAssets = [];
        $unmapped = [];
        $ambiguous = [];
        $summary = [
            'observedIdentities' => 0,
            'matchedIdentities' => 0,
            'matchedAssets' => 0,
            'ambiguousIdentities' => 0,
            'unmappedIdentities' => 0,
            'ignoredExternalIdentities' => 0,
            'invalidIdentities' => 0,
            'totalEvents' => 0,
            'totalBytes' => 0,
        ];

        DB::table('analysis_client_summaries')
            ->where('analysis_batch_id', $batch->id)
            ->select([
                'id', 'identity_type', 'identity_value', 'srcip', 'client_name',
                'source_interface', 'total_access', 'total_upload', 'total_download',
                'total_bytes', 'first_access_at', 'last_access_at', 'denied_count',
                'risky_count', 'risk_level', 'associated_ips',
            ])
            ->orderBy('id')
            ->lazyById(1000)
            ->each(function (object $row) use (
                &$assetObservations,
                &$ambiguousAssets,
                &$unmapped,
                &$ambiguous,
                &$summary,
                $activeAssets,
                $candidates,
                $batch
            ): void {
                $events = max(0, (int) $row->total_access);
                $bytes = max(0, (int) $row->total_bytes);
                $summary['totalEvents'] += $events;
                $summary['totalBytes'] += $bytes;

                $observedIps = $this->observedIps($row);
                if ($observedIps === []) {
                    $summary['invalidIdentities']++;

                    return;
                }

                $summary['observedIdentities']++;
                $candidateSet = [];
                foreach ($observedIps as $ip) {
                    foreach ($candidates[$ip] ?? [] as $assetId => $methods) {
                        $candidateSet[$assetId]['ips'][$ip] = true;
                        foreach (array_keys($methods) as $method) {
                            $candidateSet[$assetId]['methods'][$method] = true;
                        }
                    }
                }
                $candidateIds = array_map('intval', array_keys($candidateSet));
                $identity = $this->identityPayload($row, $observedIps[0], $observedIps);

                if (count($candidateIds) === 1) {
                    $assetId = $candidateIds[0];
                    $summary['matchedIdentities']++;
                    $observation = &$assetObservations[$assetId];
                    if (! isset($observation)) {
                        $observation = $this->newAssetObservation($batch);
                    }
                    $this->mergeObservation(
                        $observation,
                        $row,
                        array_keys($candidateSet[$assetId]['ips'] ?? []),
                        array_keys($candidateSet[$assetId]['methods'] ?? [])
                    );
                    unset($observation);

                    return;
                }

                if (count($candidateIds) > 1) {
                    $summary['ambiguousIdentities']++;
                    $candidateAssets = collect($candidateIds)
                        ->map(fn (int $assetId): array => [
                            'assetId' => $activeAssets->get($assetId)?->asset_uid,
                            'name' => $activeAssets->get($assetId)?->model
                                ?: $activeAssets->get($assetId)?->asset_type,
                        ])
                        ->filter(fn (array $asset): bool => (bool) $asset['assetId'])
                        ->values()
                        ->all();
                    $ambiguous[] = [
                        ...$identity,
                        'candidateCount' => count($candidateAssets),
                        'candidates' => $candidateAssets,
                    ];
                    foreach ($candidateIds as $assetId) {
                        $ambiguousAssets[$assetId] ??= [
                            'ips' => [],
                            'identityCount' => 0,
                            'candidateCount' => count($candidateIds),
                            'lastSeenAt' => null,
                        ];
                        foreach (array_keys($candidateSet[$assetId]['ips'] ?? []) as $matchedIp) {
                            $ambiguousAssets[$assetId]['ips'][$matchedIp] = true;
                        }
                        $ambiguousAssets[$assetId]['identityCount']++;
                        $ambiguousAssets[$assetId]['candidateCount'] = max(
                            $ambiguousAssets[$assetId]['candidateCount'],
                            count($candidateIds)
                        );
                        $ambiguousAssets[$assetId]['lastSeenAt'] = $this->maximumDate(
                            $ambiguousAssets[$assetId]['lastSeenAt'],
                            $row->last_access_at
                        );
                    }

                    return;
                }

                $internalIps = array_values(array_filter($observedIps, $this->isInternalIp(...)));
                if ($internalIps !== []) {
                    $summary['unmappedIdentities']++;
                    $unmapped[] = $this->identityPayload($row, $internalIps[0], $observedIps);
                } else {
                    $summary['ignoredExternalIdentities']++;
                }
            });

        foreach ($ambiguousAssets as $assetId => $candidate) {
            if (isset($assetObservations[$assetId])) {
                $assetObservations[$assetId]['ambiguousIdentityCount'] = $candidate['identityCount'];

                continue;
            }

            $assetObservations[$assetId] = [
                ...$this->newAssetObservation($batch),
                'observed' => true,
                'matchStatus' => 'ambiguous',
                'sourceIps' => array_keys($candidate['ips']),
                'identityCount' => $candidate['identityCount'],
                'lastSeenAt' => $candidate['lastSeenAt'],
                'candidateCount' => $candidate['candidateCount'],
            ];
        }

        $summary['matchedAssets'] = collect($assetObservations)
            ->where('matchStatus', 'exact')
            ->count();

        $sortDevices = fn (array $left, array $right): int => ($right['totalAccess'] <=> $left['totalAccess'])
            ?: strcmp((string) $left['ip'], (string) $right['ip']);
        usort($unmapped, $sortDevices);
        usort($ambiguous, $sortDevices);

        return [
            'available' => true,
            'analysis' => [
                'uuid' => $batch->uuid,
                'label' => $batch->label ?: $batch->original_filename,
                'completedAt' => $batch->published_at?->toIso8601String(),
                'url' => route('analysis.show', $batch),
            ],
            'summary' => $summary,
            'assetObservations' => $assetObservations,
            'unmappedDevices' => array_slice($unmapped, 0, 50),
            'ambiguousDevices' => array_slice($ambiguous, 0, 50),
        ];
    }

    /** @return array<string, mixed> */
    public function emptyResult(): array
    {
        return [
            'available' => false,
            'analysis' => null,
            'summary' => [
                'observedIdentities' => 0,
                'matchedIdentities' => 0,
                'matchedAssets' => 0,
                'ambiguousIdentities' => 0,
                'unmappedIdentities' => 0,
                'ignoredExternalIdentities' => 0,
                'invalidIdentities' => 0,
                'totalEvents' => 0,
                'totalBytes' => 0,
            ],
            'assetObservations' => [],
            'unmappedDevices' => [],
            'ambiguousDevices' => [],
        ];
    }

    /** @param array<string, array<int, array<string, bool>>> $candidates */
    private function addCandidate(array &$candidates, string $ip, int $assetId, string $method): void
    {
        $candidates[$ip][$assetId][$method] = true;
    }

    /** @return array<int, string> */
    private function ipAddresses(mixed $value): array
    {
        if (! is_scalar($value)) {
            return [];
        }

        $tokens = preg_split('/[\s,;|]+/', trim((string) $value)) ?: [];

        return collect($tokens)
            ->map(fn (string $token): ?string => $this->canonicalIp($token))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function interfaceAddresses(array $interfaces): array
    {
        $addresses = [];
        foreach ($interfaces as $interface) {
            if (! is_array($interface) || ! array_key_exists('address', $interface)) {
                continue;
            }
            foreach ($this->ipAddresses($interface['address']) as $ip) {
                $addresses[$ip] = true;
            }
        }

        return array_keys($addresses);
    }

    private function canonicalIp(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $candidate = trim((string) $value);
        if ($candidate === '') {
            return null;
        }
        $candidate = trim(explode('/', $candidate, 2)[0], " \t\n\r\0\x0B[]");
        if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = @inet_pton($candidate);

        return $packed === false ? null : inet_ntop($packed);
    }

    private function isInternalIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /** @return array<int, string> */
    private function observedIps(object $row): array
    {
        $ips = [];
        if (($primary = $this->canonicalIp($row->srcip)) !== null) {
            $ips[$primary] = true;
        }

        $associated = $row->associated_ips ?? null;
        if (is_string($associated) && $associated !== '') {
            $associated = json_decode($associated, true);
        }
        foreach (is_array($associated) ? $associated : [] as $entry) {
            $value = is_array($entry) ? ($entry['ip'] ?? null) : $entry;
            if (($ip = $this->canonicalIp($value)) !== null) {
                $ips[$ip] = true;
            }
        }

        return array_keys($ips);
    }

    /** @return array<string, mixed> */
    private function identityPayload(object $row, string $ip, array $associatedIps = []): array
    {
        return [
            'ip' => $ip,
            'associatedIps' => $associatedIps ?: [$ip],
            'identity' => $row->identity_value ?: $row->client_name ?: $ip,
            'identityType' => $row->identity_type ?: 'ip',
            'name' => $row->client_name,
            'sourceInterface' => $row->source_interface,
            'firstSeenAt' => $row->first_access_at,
            'lastSeenAt' => $row->last_access_at,
            'totalAccess' => max(0, (int) $row->total_access),
            'totalBytes' => max(0, (int) $row->total_bytes),
            'riskLevel' => $row->risk_level,
        ];
    }

    /** @return array<string, mixed> */
    private function newAssetObservation(AnalysisBatch $batch): array
    {
        return [
            'observed' => false,
            'matchStatus' => 'exact',
            'matchMethods' => [],
            'sourceIps' => [],
            'identityCount' => 0,
            'ambiguousIdentityCount' => 0,
            'totalAccess' => 0,
            'totalUpload' => 0,
            'totalDownload' => 0,
            'totalBytes' => 0,
            'deniedCount' => 0,
            'riskyCount' => 0,
            'firstSeenAt' => null,
            'lastSeenAt' => null,
            'riskLevel' => 'low',
            'metricsScope' => 'identity_summary',
            'analysisUrl' => route('analysis.show', $batch),
            'candidateCount' => 1,
        ];
    }

    /** @param array<string, mixed> $observation */
    private function mergeObservation(array &$observation, object $row, array $ips, array $methods): void
    {
        $observation['observed'] = true;
        $observation['sourceIps'] = array_values(array_unique([...$observation['sourceIps'], ...$ips]));
        $observation['matchMethods'] = array_values(array_unique([...$observation['matchMethods'], ...$methods]));
        $observation['identityCount']++;
        $observation['totalAccess'] += max(0, (int) $row->total_access);
        $observation['totalUpload'] += max(0, (int) $row->total_upload);
        $observation['totalDownload'] += max(0, (int) $row->total_download);
        $observation['totalBytes'] += max(0, (int) $row->total_bytes);
        $observation['deniedCount'] += max(0, (int) $row->denied_count);
        $observation['riskyCount'] += max(0, (int) $row->risky_count);
        $observation['firstSeenAt'] = $this->minimumDate($observation['firstSeenAt'], $row->first_access_at);
        $observation['lastSeenAt'] = $this->maximumDate($observation['lastSeenAt'], $row->last_access_at);
        if ($this->riskRank($row->risk_level) > $this->riskRank($observation['riskLevel'])) {
            $observation['riskLevel'] = $row->risk_level;
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

    private function riskRank(?string $risk): int
    {
        return match (strtolower((string) $risk)) {
            'critical' => 5,
            'high' => 4,
            'elevated' => 3,
            'medium' => 2,
            default => 1,
        };
    }
}
