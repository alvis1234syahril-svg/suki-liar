<?php

namespace App\Services;

use App\Models\NetworkAssetPortObservation;
use App\Models\TopologyDataset;
use Illuminate\Support\Collection;

class TopologyPortService
{
    /**
     * Build presentation-safe port inventories from Excel/LLD edges and SNMP observations.
     *
     * @return array<string, array{ports: array<int, array<string, mixed>>, summary: array<string, mixed>}>
     */
    public function forDataset(TopologyDataset $dataset): array
    {
        $nodes = $dataset->nodes;
        $nodesById = $nodes->keyBy('id');
        $switchNodes = $nodes->filter(fn ($node): bool => $this->isSwitch((string) $node->kind));
        $assetIds = $switchNodes
            ->map(fn ($node) => $node->assetMapping?->network_asset_id)
            ->filter()
            ->unique()
            ->values();
        $observations = $assetIds->isEmpty()
            ? collect()
            : NetworkAssetPortObservation::query()
                ->whereIn('network_asset_id', $assetIds)
                ->get()
                ->groupBy('network_asset_id');
        $result = [];

        foreach ($switchNodes as $node) {
            $asset = $node->assetMapping?->networkAsset;
            $ports = [];

            foreach ($dataset->edges as $edge) {
                $portName = null;
                $connectedNodeId = null;

                if ((int) $edge->from_node_id === (int) $node->id) {
                    $portName = $edge->from_port;
                    $connectedNodeId = $edge->to_node_id;
                } elseif ((int) $edge->to_node_id === (int) $node->id) {
                    $portName = $edge->to_port;
                    $connectedNodeId = $edge->from_node_id;
                }

                if (! $this->usablePortName($portName)) {
                    continue;
                }

                $connected = $nodesById->get($connectedNodeId);
                $key = $this->portKey((string) $portName);
                $ports[$key] ??= $this->emptyPort((string) $portName);
                $ports[$key]['inventorySources'][] = $this->inventorySource($dataset);
                $ports[$key]['connections'][] = [
                    'nodeId' => $connected?->legacy_node_id,
                    'assetId' => $connected?->assetMapping?->networkAsset?->asset_uid
                        ?: $connected?->source_asset_uid,
                    'name' => $connected?->assetMapping?->networkAsset?->model ?: $connected?->name,
                    'type' => $connected?->kind,
                    'relation' => $edge->status ?: $edge->relation_type,
                ];
            }

            [$capacity, $capacitySource] = $this->portCapacity(
                $asset?->attributes ?? [],
                (string) $asset?->model
            );

            for ($number = 1; $number <= $capacity; $number++) {
                $name = (string) $number;
                $key = $this->portKey($name);
                $ports[$key] ??= $this->emptyPort($name);
                $ports[$key]['inventorySources'][] = $capacitySource;
            }

            /** @var Collection<int, NetworkAssetPortObservation> $assetObservations */
            $assetObservations = $asset
                ? ($observations->get($asset->id) ?? collect())
                : collect();

            foreach ($assetObservations as $observation) {
                $key = $this->observationPortKey($observation, $ports);
                $ports[$key] ??= $this->emptyPort($observation->port_name);
                $hasInventoryName = $ports[$key]['inventorySources'] !== [];
                $description = trim((string) $observation->description);

                if ($hasInventoryName && $description === '' && $ports[$key]['name'] !== $observation->port_name) {
                    $description = $observation->port_name;
                }
                $ports[$key] = [
                    ...$ports[$key],
                    'name' => $hasInventoryName ? $ports[$key]['name'] : $observation->port_name,
                    'ifIndex' => $observation->if_index,
                    'adminStatus' => $observation->admin_status,
                    'operationalStatus' => $observation->operational_status,
                    'healthStatus' => $observation->health_status,
                    'speedMbps' => $observation->speed_mbps,
                    'description' => $description ?: null,
                    'failureReason' => $observation->failure_reason,
                    'lastChangeAt' => $observation->last_change_at?->toIso8601String(),
                    'observedAt' => $observation->observed_at?->toIso8601String(),
                    'telemetrySource' => $observation->source,
                ];
            }

            foreach ($ports as &$port) {
                $port['inventorySources'] = array_values(array_unique($port['inventorySources']));
                $port['connections'] = array_values(array_filter(
                    $port['connections'],
                    fn (array $connection): bool => (string) ($connection['nodeId'] ?? '') !== ''
                ));
            }
            unset($port);

            $portList = array_values($ports);
            usort($portList, fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));
            $monitoring = is_array(data_get($asset?->attributes, 'portMonitoring'))
                ? data_get($asset?->attributes, 'portMonitoring')
                : [];

            $result[$node->legacy_node_id] = [
                'ports' => $portList,
                'summary' => $this->summary($portList, $monitoring),
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function emptyPort(string $name): array
    {
        return [
            'key' => $this->portKey($name),
            'name' => trim($name),
            'ifIndex' => null,
            'adminStatus' => null,
            'operationalStatus' => null,
            'healthStatus' => 'unmonitored',
            'speedMbps' => null,
            'description' => null,
            'failureReason' => null,
            'lastChangeAt' => null,
            'observedAt' => null,
            'telemetrySource' => null,
            'inventorySources' => [],
            'connections' => [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $ports
     * @param  array<string, mixed>  $monitoring
     * @return array<string, mixed>
     */
    private function summary(array $ports, array $monitoring): array
    {
        $counts = array_count_values(array_map(
            fn (array $port): string => (string) ($port['healthStatus'] ?? 'unmonitored'),
            $ports
        ));
        $lastObserved = collect($ports)
            ->pluck('observedAt')
            ->filter()
            ->sortDesc()
            ->first();

        return [
            'total' => count($ports),
            'monitored' => count($ports) - (int) ($counts['unmonitored'] ?? 0),
            'healthy' => (int) ($counts['healthy'] ?? 0),
            'problematic' => (int) ($counts['problematic'] ?? 0),
            'disabled' => (int) ($counts['disabled'] ?? 0),
            'unknown' => (int) ($counts['unknown'] ?? 0),
            'unmonitored' => (int) ($counts['unmonitored'] ?? 0),
            'lastObservedAt' => $lastObserved,
            'lastPollAttemptAt' => $monitoring['lastPollAttemptAt'] ?? null,
            'lastPollError' => $monitoring['lastPollError'] ?? null,
            'telemetryConfigured' => (bool) config('asset-topology.snmp.enabled'),
            'telemetryAvailable' => extension_loaded('snmp'),
        ];
    }

    /**
     * Use an explicit Excel column first. A conservative model-name fallback remains
     * visibly labelled as an estimate and never creates a health observation.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: int, 1: string}
     */
    private function portCapacity(array $attributes, string $model): array
    {
        $capacity = data_get($attributes, 'workbookSource.portCapacity');

        if (is_numeric($capacity)) {
            return [max(0, min(512, (int) $capacity)), 'Excel'];
        }

        $model = mb_strtoupper(trim($model), 'UTF-8');
        $patterns = [
            '/\b(?:USW(?:\s+PRO)?|US)\s*[- ]?(\d{1,2})(?:\b|-)/',
            '/\b(\d{1,2})\s*[- ]?(?:PORTS?|POE\+*)\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $model, $matches) !== 1) {
                continue;
            }

            $inferred = (int) $matches[1];

            if ($inferred >= 2 && $inferred <= 96) {
                return [$inferred, 'Model aset (perkiraan)'];
            }
        }

        return [0, 'Excel'];
    }

    private function isSwitch(string $kind): bool
    {
        return str_contains(mb_strtolower($kind, 'UTF-8'), 'switch');
    }

    private function usablePortName(mixed $port): bool
    {
        $port = trim((string) $port);

        return $port !== '' && preg_match('/^(ip|management|mgmt)$/i', $port) !== 1;
    }

    private function portKey(string $port): string
    {
        $key = mb_strtolower(trim($port), 'UTF-8');
        $key = preg_replace('/\s+/u', '-', $key) ?: $key;

        return $key;
    }

    /**
     * Prefer an Excel/LLD port name, then IF-MIB index or the numeric suffix of an SNMP name.
     *
     * @param  array<string, array<string, mixed>>  $knownPorts
     */
    private function observationPortKey(NetworkAssetPortObservation $observation, array $knownPorts): string
    {
        $candidates = [
            $this->portKey($observation->port_key ?: $observation->port_name),
            $observation->if_index ? $this->portKey((string) $observation->if_index) : null,
        ];

        if (preg_match('/(?:^|[\/\s-])(\d+)$/', $observation->port_name, $matches) === 1) {
            $candidates[] = $this->portKey($matches[1]);
        }

        foreach (array_filter($candidates) as $candidate) {
            if (isset($knownPorts[$candidate])) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    private function inventorySource(TopologyDataset $dataset): string
    {
        return str_contains(mb_strtolower((string) $dataset->source_type), 'excel')
            ? 'Excel'
            : 'LLD';
    }
}
