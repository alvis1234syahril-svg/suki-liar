<?php

namespace App\Services;

final class TrafficIdentityResolver
{
    private const EMPTY_VALUES = ['', '-', '0', 'n/a', 'na', 'none', 'null', 'unknown', 'tidak tersedia'];

    public function resolve(array $fields): ?array
    {
        $candidates = [
            'user' => 'user',
            'srcname' => 'device',
            'srcserver' => 'server',
            'srcip' => 'ip',
        ];

        foreach ($candidates as $field => $type) {
            $value = trim((string) ($fields[$field] ?? ''));

            if (in_array(strtolower($value), self::EMPTY_VALUES, true)) {
                continue;
            }

            return [
                'key' => hash('sha256', $type.'|'.mb_strtolower($value)),
                'type' => $type,
                'value' => mb_substr($value, 0, 255),
                'source_field' => $field,
            ];
        }

        return null;
    }
}
