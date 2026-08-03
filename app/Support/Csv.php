<?php

namespace App\Support;

final class Csv
{
    /**
     * Mencegah spreadsheet mengeksekusi nilai log sebagai formula.
     */
    public static function cell(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = ltrim($value);

        if ($trimmed !== '' && $trimmed !== '-' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }

    public static function row(array $values): array
    {
        return array_map(self::cell(...), $values);
    }
}
