<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class HabitReportImporter
{
    public function __construct(
        private readonly RiskAssessmentService $riskService,
        private readonly HabitAssessmentService $habitService,
    ) {
    }

    public function import(UploadedFile $file): array
    {
        return in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'xls'], true)
            ? $this->importSpreadsheet($file)
            : $this->importCsv($file);
    }

    private function importCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw new RuntimeException('Laporan tidak dapat dibuka.');
        }

        $header = fgetcsv($handle, separator: ';');
        if (! is_array($header)) {
            fclose($handle);
            throw new RuntimeException('Header laporan tidak ditemukan.');
        }

        $header[0] = ltrim((string) $header[0], "\xEF\xBB\xBF");
        $rows = [];
        while (($values = fgetcsv($handle, separator: ';')) !== false) {
            if (count($values) !== count($header)) {
                continue;
            }
            $rows[] = $this->profile(array_combine($header, $values));
        }
        fclose($handle);

        return $this->result($rows);
    }

    private function importSpreadsheet(UploadedFile $file): array
    {
        try {
            $book = IOFactory::load($file->getRealPath());
        } catch (\Throwable $error) {
            throw new RuntimeException('Workbook Excel tidak dapat dibaca: '.$error->getMessage(), previous: $error);
        }

        $sheet = $book->getSheetByName('Data Lengkap');
        if ($sheet === null) {
            throw new RuntimeException('Sheet "Data Lengkap" tidak ditemukan pada laporan.');
        }

        $matrix = $sheet->toArray(null, true, true, false);
        $headerIndex = collect($matrix)->search(fn (array $row) => in_array('Identity', $row, true) || in_array('IP User', $row, true));
        if ($headerIndex === false) {
            throw new RuntimeException('Header data user tidak ditemukan pada workbook.');
        }

        $header = $matrix[$headerIndex];
        $rows = [];
        foreach (array_slice($matrix, $headerIndex + 1) as $values) {
            $row = array_combine($header, array_slice(array_pad($values, count($header), null), 0, count($header)));
            if (empty($row['Identity'] ?? $row['IP User'] ?? null)) {
                continue;
            }
            $rows[] = $this->profile($row);
        }

        $metadata = [];
        if ($metadataSheet = $book->getSheetByName('Metadata Pembanding')) {
            foreach ($metadataSheet->toArray(null, true, true, false) as $row) {
                if (! empty($row[0])) {
                    $metadata[(string) $row[0]] = $this->decodeMetadata($row[1] ?? null);
                }
            }
        }
        $book->disconnectWorksheets();

        return $this->result($rows, $metadata);
    }

    private function profile(array $row): array
    {
        $metrics = [
            'total_access' => $this->number($row['Akses'] ?? $row['Total Akses'] ?? 0),
            'denied_count' => $this->number($row['Deny'] ?? $row['Koneksi Ditolak'] ?? 0),
            'timeout_count' => $this->number($row['Timeout'] ?? 0),
            'reset_count' => $this->number($row['Reset'] ?? 0),
            'nonstandard_port_count' => $this->number($row['Port Nonstandar'] ?? 0),
            'critical_count' => $this->number($row['Critical'] ?? $row['Event Critical'] ?? 0),
            'high_count' => $this->number($row['High'] ?? $row['Event High'] ?? 0),
            'elevated_count' => $this->number($row['Elevated'] ?? $row['Event Elevated'] ?? 0),
            'medium_count' => $this->number($row['Medium'] ?? $row['Event Medium'] ?? ($row['Akses Berisiko'] ?? 0)),
            'work_hours_count' => $this->number($row['Jam Kerja'] ?? 0),
            'off_hours_count' => $this->number($row['Luar Jam Kerja'] ?? 0),
            'active_hour_count' => $this->number($row['Jumlah Jam Aktif'] ?? 0),
            'dominant_hour' => $this->nullableNumber($row['Jam Dominan'] ?? null),
            'unique_apps' => $this->number($row['Aplikasi Unik'] ?? 0),
            'unique_destinations' => $this->number($row['Tujuan Unik'] ?? 0),
            'total_bytes' => $this->number($row['Total Traffic (byte)'] ?? 0),
            'total_duration' => $this->number($row['Durasi Total (detik)'] ?? 0),
            'average_duration' => (float) ($row['Durasi Rata-rata (detik)'] ?? 0),
        ];

        $risk = $this->riskService->assess($metrics);
        $risk['score'] = isset($row['Skor Risiko']) ? (float) $row['Skor Risiko'] : $risk['score'];
        $risk['level'] = isset($row['Level Risiko']) ? $this->normalizeLevel((string) $row['Level Risiko']) : $risk['level'];
        $risk['reason'] = (string) ($row['Alasan Risiko'] ?? $row['Alasan Penilaian'] ?? $risk['reason']);

        return array_merge($metrics, [
            'identity' => (string) ($row['Identity'] ?? $row['IP User'] ?? $row['Client IP'] ?? '-'),
            'identity_type' => (string) ($row['Tipe Identitas'] ?? 'ip'),
            'ip' => (string) ($row['IP Utama'] ?? $row['IP User'] ?? $row['Client IP'] ?? '-'),
            'associated_ips' => $this->decodeList($row['IP Terkait'] ?? null),
            'name' => (string) ($row['Nama Client'] ?? '-'),
            'total_upload' => $this->number($row['Upload (byte)'] ?? $row['Total Upload (byte)'] ?? 0),
            'total_download' => $this->number($row['Download (byte)'] ?? $row['Total Download (byte)'] ?? 0),
            'first_seen' => $row['Akses Pertama'] ?? null,
            'last_seen' => $row['Akses Terakhir'] ?? null,
            'score' => $risk['score'],
            'level' => $risk['level'],
            'reason' => $risk['reason'],
            'components' => $risk['components'],
            'habit_score' => isset($row['Skor Habit']) ? (float) $row['Skor Habit'] : null,
            'habit_level' => isset($row['Level Habit']) ? $this->normalizeLevel((string) $row['Level Habit']) : null,
            'habit_reason' => $row['Alasan Habit'] ?? null,
            'combined_status' => (string) ($row['Status Gabungan'] ?? ''),
            'combined_label' => (string) ($row['Label Gabungan'] ?? ''),
            'top_app' => (string) ($row['Top App'] ?? ''),
            'top_country' => (string) ($row['Top Negara'] ?? ''),
            'top_policy' => (string) ($row['Top Policy'] ?? ''),
        ]);
    }

    private function result(array $rows, array $metadata = []): array
    {
        $rows = array_values(array_filter($rows, fn (array $row) => ($row['identity'] ?? '-') !== '-'));
        if ($rows === []) {
            throw new RuntimeException('Laporan tidak memiliki data client yang valid.');
        }

        $collection = collect($rows);
        $baseline = [
            'traffic_per_event' => $this->median($collection->map(fn ($row) => $row['total_access'] > 0 ? $row['total_bytes'] / $row['total_access'] : 0)),
            'unique_apps' => $this->median($collection->pluck('unique_apps')),
            'unique_destinations' => $this->median($collection->pluck('unique_destinations')),
        ];
        $riskDistribution = ['low' => 0, 'medium' => 0, 'high' => 0];
        $habitDistribution = ['low' => 0, 'medium' => 0, 'high' => 0];

        foreach ($rows as &$row) {
            if ($row['habit_score'] === null) {
                $habit = $this->habitService->assess($row, $baseline);
                $row['habit_score'] = $habit['score'];
                $row['habit_level'] = $habit['level'];
                $row['habit_reason'] = $habit['reason'];
            }
            if ($row['combined_status'] === '') {
                $combined = $this->habitService->combine(
                    ['score' => $row['score'], 'level' => $row['level']],
                    ['score' => $row['habit_score'], 'level' => $row['habit_level']]
                );
                $row['combined_status'] = $combined['key'];
                $row['combined_label'] = $combined['label'];
            }
            $riskDistribution[$row['level']]++;
            $habitDistribution[$row['habit_level']]++;
        }
        unset($row);

        usort($rows, fn (array $a, array $b) =>
            max($b['score'], $b['habit_score']) <=> max($a['score'], $a['habit_score'])
        );
        $unitRisk = $this->riskService->assessUnit($rows);
        $unitHabit = $this->habitService->assessUnit(collect($rows)->map(fn ($row) => [
            'score' => $row['habit_score'], 'level' => $row['habit_level'],
        ]));

        return [
            'total_clients' => count($rows),
            'total_access' => $metadata['total_access'] ?? array_sum(array_column($rows, 'total_access')),
            'unique_apps' => $metadata['unique_apps'] ?? max(array_column($rows, 'unique_apps') ?: [0]),
            'total_upload' => $metadata['total_upload'] ?? array_sum(array_column($rows, 'total_upload')),
            'total_download' => $metadata['total_download'] ?? array_sum(array_column($rows, 'total_download')),
            'total_duration' => $metadata['total_duration'] ?? array_sum(array_column($rows, 'total_duration')),
            'work_hours_count' => $metadata['work_hours_count'] ?? array_sum(array_column($rows, 'work_hours_count')),
            'off_hours_count' => $metadata['off_hours_count'] ?? array_sum(array_column($rows, 'off_hours_count')),
            'peak_hour' => $metadata['peak_hour'] ?? null,
            'top_app' => $metadata['top_app'] ?? null,
            'top_action' => $metadata['top_action'] ?? null,
            'top_risk' => $metadata['top_risk'] ?? null,
            'first_seen' => $metadata['first_seen'] ?? collect($rows)->pluck('first_seen')->filter()->min(),
            'last_seen' => $metadata['last_seen'] ?? collect($rows)->pluck('last_seen')->filter()->max(),
            'total_denied' => $metadata['total_denied'] ?? array_sum(array_column($rows, 'denied_count')),
            'risky_clients' => $riskDistribution['medium'] + $riskDistribution['high'],
            'anomalous_clients' => $habitDistribution['medium'] + $habitDistribution['high'],
            'risk_score' => $metadata['risk_score'] ?? $unitRisk['score'],
            'risk_level' => $metadata['risk_level'] ?? $unitRisk['level'],
            'habit_score' => $metadata['habit_score'] ?? $unitHabit['score'],
            'risk_distribution' => $metadata['risk_distribution'] ?? $riskDistribution,
            'habit_distribution' => $metadata['habit_distribution'] ?? $habitDistribution,
            'top_risky_clients' => array_slice(array_values(array_filter($rows, fn ($row) => $row['level'] !== 'low')), 0, 10),
            'client_profiles' => $rows,
            'outliers' => $metadata['outliers'] ?? [],
        ];
    }

    private function number(mixed $value): int
    {
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }
        return (int) preg_replace('/[^\d]/', '', (string) $value);
    }

    private function nullableNumber(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : $this->number($value);
    }

    private function normalizeLevel(string $level): string
    {
        return match (strtolower(trim($level))) {
            'tinggi', 'high' => 'high',
            'sedang', 'medium' => 'medium',
            default => 'low',
        };
    }

    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }

    private function decodeMetadata(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function median(Collection $values): float
    {
        $values = $values->map(fn ($value) => (float) $value)->sort()->values();
        $count = $values->count();
        if ($count === 0) {
            return 0;
        }
        $middle = intdiv($count, 2);
        return $count % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
