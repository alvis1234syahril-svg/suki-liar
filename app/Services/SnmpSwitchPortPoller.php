<?php

namespace App\Services;

use App\Models\NetworkAsset;
use App\Models\NetworkAssetPortObservation;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SnmpSwitchPortPoller
{
    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';

    private const IF_TYPE = '1.3.6.1.2.1.2.2.1.3';

    private const IF_SPEED = '1.3.6.1.2.1.2.2.1.5';

    private const IF_ADMIN_STATUS = '1.3.6.1.2.1.2.2.1.7';

    private const IF_OPER_STATUS = '1.3.6.1.2.1.2.2.1.8';

    private const IF_HIGH_SPEED = '1.3.6.1.2.1.31.1.1.1.15';

    private const IF_ALIAS = '1.3.6.1.2.1.31.1.1.1.18';

    /**
     * Poll one switch through IF-MIB and persist a current observation per Ethernet port.
     *
     * @return array{assetId: string, ports: int, healthy: int, problematic: int, disabled: int, unknown: int}
     */
    public function poll(NetworkAsset $asset): array
    {
        $this->assertReady();
        $host = $this->managementHost($asset);
        $community = (string) config('asset-topology.snmp.community');
        $observedAt = now();

        $this->recordPollAttempt($asset, null, $observedAt->toIso8601String());

        try {
            $descriptions = $this->walk($host, $community, self::IF_DESCR);
            $types = $this->walk($host, $community, self::IF_TYPE);
            $speeds = $this->walk($host, $community, self::IF_SPEED);
            $adminStatuses = $this->walk($host, $community, self::IF_ADMIN_STATUS);
            $operationalStatuses = $this->walk($host, $community, self::IF_OPER_STATUS);
            $highSpeeds = $this->walk($host, $community, self::IF_HIGH_SPEED, false);
            $aliases = $this->walk($host, $community, self::IF_ALIAS, false);
            $summary = [
                'assetId' => $asset->asset_uid,
                'ports' => 0,
                'healthy' => 0,
                'problematic' => 0,
                'disabled' => 0,
                'unknown' => 0,
            ];

            foreach ($descriptions as $ifIndex => $description) {
                if ($this->enumNumber($types[$ifIndex] ?? null) !== 6) {
                    continue;
                }

                $portName = trim((string) $description) ?: "Port {$ifIndex}";
                $admin = $this->linkStatus($adminStatuses[$ifIndex] ?? null);
                $operational = $this->linkStatus($operationalStatuses[$ifIndex] ?? null);
                $health = $this->classifyHealth($admin, $operational);
                $speedMbps = $this->speedMbps(
                    $highSpeeds[$ifIndex] ?? null,
                    $speeds[$ifIndex] ?? null
                );
                $failureReason = $health === 'problematic'
                    ? 'Port sudah diaktifkan, tetapi koneksi perangkat tidak terdeteksi. Periksa kabel, perangkat tujuan, sumber daya, dan kondisi port.'
                    : null;

                NetworkAssetPortObservation::query()->updateOrCreate(
                    [
                        'network_asset_id' => $asset->id,
                        'port_key' => $this->portKey($portName, (int) $ifIndex),
                    ],
                    [
                        'port_name' => $portName,
                        'if_index' => (int) $ifIndex,
                        'admin_status' => $admin,
                        'operational_status' => $operational,
                        'health_status' => $health,
                        'speed_mbps' => $speedMbps,
                        'description' => trim((string) ($aliases[$ifIndex] ?? '')) ?: null,
                        'source' => 'snmp',
                        'failure_reason' => $failureReason,
                        'observed_at' => $observedAt,
                        'metadata' => [
                            'host' => $host,
                            'ifType' => 6,
                        ],
                    ]
                );

                $summary['ports']++;
                $summary[$health]++;
            }

            $this->recordPollSuccess($asset, $observedAt->toIso8601String());

            return $summary;
        } catch (\Throwable $exception) {
            $message = mb_strimwidth($exception->getMessage(), 0, 500, '...');
            $this->recordPollAttempt($asset, $message, $observedAt->toIso8601String());
            Log::warning('Polling port switch melalui SNMP gagal.', [
                'asset_id' => $asset->asset_uid,
                'host' => $host,
                'error' => $message,
            ]);

            throw $exception;
        }
    }

    private function assertReady(): void
    {
        if (! config('asset-topology.snmp.enabled')) {
            throw new RuntimeException('Pemantauan SNMP belum diaktifkan pada konfigurasi aplikasi.');
        }

        if (! extension_loaded('snmp') || ! function_exists('snmp2_real_walk')) {
            throw new RuntimeException('Ekstensi PHP SNMP belum aktif pada Laragon.');
        }

        if (trim((string) config('asset-topology.snmp.community')) === '') {
            throw new RuntimeException('SNMP community belum dikonfigurasi.');
        }
    }

    private function managementHost(NetworkAsset $asset): string
    {
        $host = trim(explode('/', (string) $asset->ip_address, 2)[0]);

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException("IP manajemen switch {$asset->asset_uid} tidak valid.");
        }

        return $host;
    }

    /** @return array<int, string> */
    private function walk(string $host, string $community, string $oid, bool $required = true): array
    {
        snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
        snmp_set_quick_print(true);
        $result = @snmp2_real_walk(
            $host.':'.(int) config('asset-topology.snmp.port', 161),
            $community,
            $oid,
            (int) config('asset-topology.snmp.timeout_microseconds', 1000000),
            (int) config('asset-topology.snmp.retries', 1)
        );

        if ($result === false) {
            if ($required) {
                throw new RuntimeException("Switch {$host} tidak memberikan respons untuk OID {$oid}.");
            }

            return [];
        }

        $indexed = [];

        foreach ($result as $key => $value) {
            if (preg_match('/\.(\d+)$/', (string) $key, $matches) === 1) {
                $indexed[(int) $matches[1]] = trim((string) $value, " \t\n\r\0\x0B\"");
            }
        }

        return $indexed;
    }

    private function enumNumber(mixed $value): ?int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
            return (int) $value;
        }

        return preg_match('/\((\d+)\)|(-?\d+)/', (string) $value, $matches) === 1
            ? (int) ($matches[1] !== '' ? $matches[1] : $matches[2])
            : null;
    }

    private function linkStatus(mixed $value): string
    {
        return match ($this->enumNumber($value)) {
            1 => 'up',
            2 => 'down',
            3 => 'testing',
            4 => 'unknown',
            5 => 'dormant',
            6 => 'not-present',
            7 => 'lower-layer-down',
            default => 'unknown',
        };
    }

    public function classifyHealth(string $admin, string $operational): string
    {
        if ($admin === 'down') {
            return 'disabled';
        }

        if ($admin === 'up' && $operational === 'up') {
            return 'healthy';
        }

        if ($admin === 'up' && in_array($operational, ['down', 'not-present', 'lower-layer-down'], true)) {
            return 'problematic';
        }

        return 'unknown';
    }

    private function speedMbps(mixed $highSpeed, mixed $speed): ?int
    {
        $highSpeedMbps = $this->enumNumber($highSpeed);

        if ($highSpeedMbps !== null && $highSpeedMbps > 0) {
            return $highSpeedMbps;
        }

        $bitsPerSecond = $this->enumNumber($speed);

        return $bitsPerSecond !== null && $bitsPerSecond > 0
            ? max(1, (int) round($bitsPerSecond / 1_000_000))
            : null;
    }

    private function portKey(string $name, int $ifIndex): string
    {
        $normalized = mb_strtolower(trim($name), 'UTF-8');
        $normalized = preg_replace('/\s+/u', '-', $normalized) ?: "if-{$ifIndex}";

        return mb_strimwidth($normalized, 0, 100, '').'-'.$ifIndex;
    }

    private function recordPollSuccess(NetworkAsset $asset, string $timestamp): void
    {
        $this->updateMonitoringMetadata($asset, [
            'lastPollAttemptAt' => $timestamp,
            'lastSuccessfulPollAt' => $timestamp,
            'lastPollError' => null,
        ]);
    }

    private function recordPollAttempt(NetworkAsset $asset, ?string $error, string $timestamp): void
    {
        $this->updateMonitoringMetadata($asset, [
            'lastPollAttemptAt' => $timestamp,
            'lastPollError' => $error,
        ]);
    }

    /** @param array<string, mixed> $monitoring */
    private function updateMonitoringMetadata(NetworkAsset $asset, array $monitoring): void
    {
        $attributes = is_array($asset->attributes) ? $asset->attributes : [];
        $attributes['portMonitoring'] = [
            ...(is_array($attributes['portMonitoring'] ?? null) ? $attributes['portMonitoring'] : []),
            ...$monitoring,
        ];
        $asset->forceFill(['attributes' => $attributes])->saveQuietly();
        $asset->refresh();
    }
}
