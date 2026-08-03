<?php

namespace App\Services;

use Carbon\Carbon;
use Throwable;

class FortiGateLogParser
{
    private const KNOWN_FIELDS = [
        'date', 'time', 'eventtime', 'srcip', 'srcport', 'srcname', 'user', 'srcserver',
        'dstip', 'dstport', 'srccountry', 'dstcountry', 'srcintf',
        'dstintf', 'proto', 'action', 'policyid', 'policyname',
        'service', 'app', 'appcat', 'apprisk', 'duration', 'sentbyte',
        'rcvdbyte', 'sentpkt', 'rcvdpkt',
    ];

    public function __construct(private ?TrafficIdentityResolver $identityResolver = null)
    {
    }

    public function parseLine(string $line): array
    {
        $line = trim($line);

        if ($line === '') {
            return [];
        }

        preg_match_all(
            '/([a-zA-Z0-9_]+)=(?:"([^"]*)"|(\S+))/',
            $line,
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL
        );

        $data = [];

        foreach ($matches as $match) {
            $data[$match[1]] = $match[2] !== null ? $match[2] : ($match[3] ?? '');
        }

        return $data;
    }

    public function toDatabaseRow(int $batchId, array $data, string $rawLog): array
    {
        $extra = array_diff_key($data, array_flip(self::KNOWN_FIELDS));
        $identity = ($this->identityResolver ?? new TrafficIdentityResolver())->resolve($data);
        $sentbyte = $this->unsignedInteger($data['sentbyte'] ?? null, null, 0) ?? 0;
        $rcvdbyte = $this->unsignedInteger($data['rcvdbyte'] ?? null, null, 0) ?? 0;

        return [
            'analysis_batch_id' => $batchId,
            'logged_at' => $this->parseTimestamp($data),
            'eventtime' => $this->unsignedInteger($data['eventtime'] ?? null),
            'srcip' => $this->text($data['srcip'] ?? null, 45),
            'srcport' => $this->unsignedInteger($data['srcport'] ?? null, 4294967295),
            'srcname' => $this->text($data['srcname'] ?? null),
            'user' => $this->text($data['user'] ?? null),
            'srcserver' => $this->text($data['srcserver'] ?? null),
            'identity_key' => $identity['key'] ?? null,
            'identity_type' => $identity['type'] ?? null,
            'identity_value' => $identity['value'] ?? null,
            'dstip' => $this->text($data['dstip'] ?? null, 45),
            'dstport' => $this->unsignedInteger($data['dstport'] ?? null, 4294967295),
            'srccountry' => $this->text($data['srccountry'] ?? null),
            'dstcountry' => $this->text($data['dstcountry'] ?? null),
            'srcintf' => $this->text($data['srcintf'] ?? null),
            'dstintf' => $this->text($data['dstintf'] ?? null),
            'proto' => $this->unsignedInteger($data['proto'] ?? null, 255),
            'action' => $this->text($data['action'] ?? null),
            'policyid' => $this->unsignedInteger($data['policyid'] ?? null, 4294967295),
            'policyname' => $this->text($data['policyname'] ?? null),
            'service' => $this->text($data['service'] ?? null),
            'app' => $this->text($data['app'] ?? null),
            'appcat' => $this->text($data['appcat'] ?? null),
            'apprisk' => $this->text($data['apprisk'] ?? null),
            'duration' => $this->unsignedInteger($data['duration'] ?? null),
            'sentbyte' => $sentbyte,
            'rcvdbyte' => $rcvdbyte,
            'total_bytes' => min(PHP_INT_MAX, $sentbyte + $rcvdbyte),
            'sentpkt' => $this->unsignedInteger($data['sentpkt'] ?? null, null, 0),
            'rcvdpkt' => $this->unsignedInteger($data['rcvdpkt'] ?? null, null, 0),
            'raw_log' => mb_scrub(trim($rawLog), 'UTF-8'),
            'extra_fields' => json_encode($extra, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function parseTimestamp(array $data): ?string
    {
        if (empty($data['date']) || empty($data['time'])) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', $data['date'].' '.$data['time'])
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function text(mixed $value, int $maximumLength = 255): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr(mb_scrub((string) $value, 'UTF-8'), 0, $maximumLength);
    }

    private function unsignedInteger(mixed $value, ?int $maximum = null, ?int $default = null): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $value = (string) $value;

        if (!preg_match('/^\d+$/', $value)) {
            return $default;
        }

        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $phpMaximum = (string) PHP_INT_MAX;

        if (strlen($normalized) > strlen($phpMaximum)
            || (strlen($normalized) === strlen($phpMaximum) && strcmp($normalized, $phpMaximum) > 0)) {
            return $default;
        }

        $number = (int) $normalized;

        return $maximum !== null && $number > $maximum ? $default : $number;
    }
}
