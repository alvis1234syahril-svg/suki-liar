<?php

namespace App\Services;

use App\Models\AnalysisBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class HabitWorkbookExporter
{
    private const NAVY = '0F172A';
    private const SLATE = '334155';
    private const BLUE = 'DBEAFE';
    private const WHITE = 'FFFFFF';

    public function make(AnalysisBatch $batch): Spreadsheet
    {
        $clients = DB::table('analysis_client_summaries')
            ->where('analysis_batch_id', $batch->id)
            ->orderByDesc('risk_score')
            ->orderByDesc('total_access')
            ->get();

        $book = new Spreadsheet();
        $book->getProperties()->setCreator('Log Traffic')->setTitle("Laporan Habit User {$batch->label}");
        $summary = $book->getActiveSheet()->setTitle('Ringkasan');
        $risk = $book->createSheet()->setTitle('Analisis Risiko');
        $habit = $book->createSheet()->setTitle('Perilaku User');
        $habitScore = $book->createSheet()->setTitle('Analisis Habit');
        $outlier = $book->createSheet()->setTitle('Outlier');
        $detail = $book->createSheet()->setTitle('Data Lengkap');
        $reference = $book->createSheet()->setTitle('Referensi');
        $metadata = $book->createSheet()->setTitle('Metadata Pembanding');

        $this->summary($summary, $reference, $clients, $batch);
        $this->risk($risk, $clients, $batch);
        $this->habit($habit, $clients, $batch);
        $this->habitScore($habitScore, $clients, $batch);
        $this->outlier($outlier, $batch);
        $this->detailV2($detail, $clients, $batch);
        $this->reference($reference);
        $this->metadata($metadata, $batch);
        $metadata->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function summary(Worksheet $sheet, Worksheet $reference, Collection $clients, AnalysisBatch $batch): void
    {
        $this->title($sheet, 'A1:N2', 'LAPORAN HABIT USER • '.($batch->label ?: 'UNIT'), 'A3:N3',
            'Executive dashboard traffic, perilaku penggunaan, dan profil risiko per user');
        $this->section($sheet, 'A5:N5', 'IKHTISAR ANALISIS');
        $snapshot = $batch->summary_data['summary'] ?? [];
        $cards = [
            ['A7:B7', 'A8:B10', 'TOTAL USER', $clients->count()],
            ['D7:E7', 'D8:E10', 'TOTAL EVENT', (int) ($snapshot['total_logs'] ?? $clients->sum('total_access'))],
            ['G7:H7', 'G8:H10', 'EVENT DENY', (int) ($snapshot['total_denied'] ?? $clients->sum('denied_count'))],
            ['J7:K7', 'J8:K10', 'EVENT BERISIKO', (int) ($snapshot['total_risk_events'] ?? $clients->sum('risky_count'))],
            ['M7:N7', 'M8:N10', 'DATA VALID', number_format(max(0, $batch->processed_lines - $batch->invalid_lines), 0, ',', '.').' baris'],
        ];
        foreach ($cards as [$labelRange, $valueRange, $label, $value]) {
            $sheet->mergeCells($labelRange)->setCellValue(strtok($labelRange, ':'), $label);
            $sheet->mergeCells($valueRange)->setCellValue(strtok($valueRange, ':'), $value);
            $sheet->getStyle($labelRange)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SLATE]],
                'font' => ['color' => ['rgb' => 'CBD5E1'], 'bold' => true, 'size' => 9],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle($valueRange)->applyFromArray([
                'font' => ['color' => ['rgb' => self::NAVY], 'bold' => true, 'size' => 20],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['outline' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'CBD5E1']]],
            ]);
            $sheet->getStyle($valueRange)->getNumberFormat()->setFormatCode('#,##0');
        }

        $this->section($sheet, 'A12:G12', 'USER DENGAN PRIORITAS TERTINGGI');
        $sheet->fromArray(['IP User', 'Skor', 'Level', 'Event Risiko', 'Deny', 'Pemicu Utama'], null, 'A14');
        $this->header($sheet, 'A14:F14');
        foreach ($clients->take(10)->values() as $i => $client) {
            $sheet->fromArray([$client->srcip, (float) $client->risk_score, $client->risk_label,
                (int) $client->risky_count, (int) $client->denied_count, $client->risk_reason], null, 'A'.(15 + $i));
        }
        $this->body($sheet, 'A15:F24');

        $topApps = collect($batch->summary_data['top_apps'] ?? [])->take(10)->values();
        $this->section($sheet, 'A27:C27', 'TOP APLIKASI UNIT');
        $sheet->fromArray(['Peringkat', 'Aplikasi', 'Total Event'], null, 'A29');
        $this->header($sheet, 'A29:C29');
        for ($i = 0; $i < 10; $i++) {
            $sheet->fromArray([$i + 1, data_get($topApps, "{$i}.app", '-'), (int) data_get($topApps, "{$i}.total", 0)], null, 'A'.(30 + $i));
        }
        $this->body($sheet, 'A30:C39');

        $this->section($sheet, 'A42:N42', 'CATATAN PENTING & REKOMENDASI');
        $notes = [
            ['Temuan', $clients->where('risk_level', 'high')->count().' user berisiko tinggi dan '.$clients->where('risk_level', 'medium')->count().' user berisiko menengah memerlukan validasi konteks.'],
            ['Aplikasi dominan', data_get($topApps, '0.app', 'Tidak tersedia').' memiliki event terbanyak. Volume tinggi tidak otomatis berbahaya.'],
            ['Nama client', 'Tidak tersedia pada log sumber. IP digunakan sebagai identitas utama dan laporan tidak menebak nama user.'],
            ['Tindak lanjut', 'Prioritaskan kombinasi skor tinggi, deny berulang, event critical/high, lalu cocokkan IP dengan inventaris aset.'],
        ];
        foreach ($notes as $i => [$label, $text]) {
            $row = 44 + $i;
            $sheet->setCellValue("A{$row}", $label)->mergeCells("B{$row}:N{$row}")->setCellValue("B{$row}", $text);
        }
        $this->body($sheet, 'A44:N47');
        $sheet->getStyle('A44:A47')->getFont()->setBold(true);
        $sheet->getStyle('B44:N47')->getAlignment()->setWrapText(true);

        $this->chartData($reference, $clients, $topApps);
        $this->chart($sheet, 'doughnut', 'Distribusi Risiko User', "'Referensi'!\$J\$5:\$J\$7", "'Referensi'!\$K\$5:\$K\$7", 'H12', 'N25', 3);
        $this->chart($sheet, 'bar', 'Top 10 User Berdasarkan Akses', "'Referensi'!\$J\$11:\$J\$20", "'Referensi'!\$K\$11:\$K\$20", 'H27', 'N40', 10);
        $this->chart($sheet, 'bar', 'Top 10 Aplikasi Unit', "'Referensi'!\$J\$24:\$J\$33", "'Referensi'!\$K\$24:\$K\$33", 'D27', 'G40', 10);

        $this->widths($sheet, [18, 12, 18, 13, 13, 45, 13, 13, 13, 13, 13, 13, 13, 13]);
        $sheet->freezePane('A4')->setShowGridlines(false);
    }

    private function risk(Worksheet $sheet, Collection $clients, AnalysisBatch $batch): void
    {
        $this->title($sheet, 'A1:P2', 'ANALISIS RISIKO PER USER • '.($batch->label ?: 'UNIT'), 'A3:P3',
            'Skor adalah alat prioritas investigasi, bukan vonis bahwa user melakukan pelanggaran');
        $sheet->fromArray(['Prioritas', 'IP User', 'Nama Client', 'Skor', 'Level', 'Label', 'Event Risiko',
            'Critical', 'High', 'Elevated', 'Medium', 'Deny', 'Total Akses', '% Event Risiko', 'Pemicu Penilaian', 'Top App'], null, 'A5');
        $this->header($sheet, 'A5:P5');
        foreach ($clients->values() as $i => $c) {
            $sheet->fromArray([$i + 1, $c->srcip, $c->client_name ?: 'Tidak tersedia pada log', (float) $c->risk_score,
                $c->risk_level, $c->risk_label, (int) $c->risky_count, (int) $c->critical_count, (int) $c->high_count,
                (int) $c->elevated_count, (int) $c->medium_count, (int) $c->denied_count, (int) $c->total_access,
                $c->total_access ? $c->risky_count / $c->total_access : 0, $c->risk_reason, $this->ranked($c->top_apps, 'app')], null, 'A'.(6 + $i));
        }
        $last = 5 + $clients->count();
        $this->finishTable($sheet, "A6:P{$last}", "A5:P{$last}", 'D6', [10, 16, 25, 11, 12, 13, 14, 11, 11, 11, 11, 11, 13, 14, 58, 42]);
        $sheet->getStyle("N6:N{$last}")->getNumberFormat()->setFormatCode('0.0%');
        $sheet->getStyle("O6:P{$last}")->getAlignment()->setWrapText(true);
    }

    private function habit(Worksheet $sheet, Collection $clients, AnalysisBatch $batch): void
    {
        $this->title($sheet, 'A1:Q2', 'PERILAKU & HABIT USER • '.($batch->label ?: 'UNIT'), 'A3:Q3',
            'Pola akses, traffic, aplikasi, negara tujuan, policy, dan rentang aktivitas');
        $sheet->fromArray(['IP User', 'Nama Client', 'Interface', 'Total Akses', 'Upload (MB)', 'Download (MB)',
            'Total Traffic (MB)', 'Akses Pertama', 'Akses Terakhir', 'Deny', 'Top App', 'Jumlah App Utama',
            'Top 3 Aplikasi', 'Top Negara', 'Top Policy', 'Skor Risiko', 'Level'], null, 'A5');
        $this->header($sheet, 'A5:Q5');
        foreach ($clients->sortByDesc('total_access')->values() as $i => $c) {
            $apps = json_decode($c->top_apps ?: '[]', true) ?: [];
            $sheet->fromArray([$c->srcip, $c->client_name ?: 'Tidak tersedia pada log', $c->source_interface ?: 'Tidak tersedia',
                (int) $c->total_access, $c->total_upload / 1048576, $c->total_download / 1048576,
                ($c->total_upload + $c->total_download) / 1048576, $c->first_access_at, $c->last_access_at,
                (int) $c->denied_count, data_get($apps, '0.app', 'Tidak tersedia'), (int) data_get($apps, '0.total_access', 0),
                $this->ranked($c->top_apps, 'app'), $this->ranked($c->top_countries, 'dstcountry'),
                $this->ranked($c->top_policies, 'policyname'), (float) $c->risk_score, $c->risk_level], null, 'A'.(6 + $i));
        }
        $last = 5 + $clients->count();
        $this->finishTable($sheet, "A6:Q{$last}", "A5:Q{$last}", 'D6', [16, 25, 17, 14, 15, 15, 18, 20, 20, 10, 18, 17, 42, 42, 42, 13, 12]);
        $sheet->getStyle("E6:G{$last}")->getNumberFormat()->setFormatCode('#,##0.00" MB"');
        $sheet->getStyle("M6:O{$last}")->getAlignment()->setWrapText(true);
    }

    private function detail(Worksheet $sheet, Collection $clients, AnalysisBatch $batch): void
    {
        $this->title($sheet, 'A1:U2', 'DATA LENGKAP • '.($batch->label ?: 'UNIT'), 'A3:U3',
            "Sumber: {$batch->original_filename} | {$clients->count()} user");
        $sheet->fromArray(['IP User', 'Nama Client', 'Interface', 'Akses', 'Upload (byte)', 'Download (byte)', 'Akses Pertama',
            'Akses Terakhir', 'Deny', 'Critical', 'High', 'Elevated', 'Medium', 'Event Berisiko', 'Skor Risiko',
            'Level Risiko', 'Label Risiko', 'Alasan Penilaian', 'Top App', 'Top Negara', 'Top Policy'], null, 'A5');
        $this->header($sheet, 'A5:U5');
        foreach ($clients->values() as $i => $c) {
            $sheet->fromArray([$c->srcip, $c->client_name ?: 'Tidak tersedia pada log', $c->source_interface ?: 'Tidak tersedia',
                (int) $c->total_access, (int) $c->total_upload, (int) $c->total_download, $c->first_access_at, $c->last_access_at,
                (int) $c->denied_count, (int) $c->critical_count, (int) $c->high_count, (int) $c->elevated_count,
                (int) $c->medium_count, (int) $c->risky_count, (float) $c->risk_score, $c->risk_level, $c->risk_label,
                $c->risk_reason, $this->ranked($c->top_apps, 'app'), $this->ranked($c->top_countries, 'dstcountry'),
                $this->ranked($c->top_policies, 'policyname')], null, 'A'.(6 + $i));
        }
        $last = 5 + $clients->count();
        $this->finishTable($sheet, "A6:U{$last}", "A5:U{$last}", 'D6', [16, 25, 17, 13, 16, 16, 20, 20, 10, 11, 11, 11, 11, 14, 13, 13, 14, 58, 42, 42, 42]);
        $sheet->getStyle("R6:U{$last}")->getAlignment()->setWrapText(true);
    }

    private function detailV2(Worksheet $sheet, Collection $clients, AnalysisBatch $batch): void
    {
        $this->title($sheet, 'A1:AN2', 'DATA LENGKAP • '.($batch->label ?: 'UNIT'), 'A3:AN3',
            "Sumber: {$batch->original_filename} | {$clients->count()} user");
        $sheet->fromArray([
            'Identity', 'Tipe Identitas', 'IP Utama', 'IP Terkait', 'Nama Client', 'Interface', 'Akses',
            'Upload (byte)', 'Download (byte)', 'Total Traffic (byte)', 'Durasi Total (detik)',
            'Durasi Rata-rata (detik)', 'Jam Kerja', 'Luar Jam Kerja', 'Jumlah Jam Aktif', 'Jam Dominan',
            'Akses Pertama', 'Akses Terakhir', 'Deny', 'Timeout', 'Reset', 'Port Nonstandar',
            'Aplikasi Unik', 'Tujuan Unik', 'Critical', 'High', 'Elevated', 'Medium', 'Event Berisiko',
            'Skor Risiko', 'Level Risiko', 'Alasan Risiko', 'Skor Habit', 'Level Habit', 'Alasan Habit',
            'Status Gabungan', 'Label Gabungan', 'Top App', 'Top Negara', 'Top Policy',
        ], null, 'A5');
        $this->header($sheet, 'A5:AN5');

        foreach ($clients->values() as $i => $c) {
            $sheet->fromArray([
                $c->identity_value ?: $c->srcip, $c->identity_type ?: 'ip', $c->srcip,
                json_encode(json_decode($c->associated_ips ?: '[]', true) ?: [], JSON_UNESCAPED_SLASHES),
                $c->client_name ?: 'Tidak tersedia pada log', $c->source_interface ?: 'Tidak tersedia',
                (int) $c->total_access, (int) $c->total_upload, (int) $c->total_download, (int) $c->total_bytes,
                (int) $c->total_duration, (float) $c->average_duration, (int) $c->work_hours_count,
                (int) $c->off_hours_count, (int) $c->active_hour_count, $c->dominant_hour,
                $c->first_access_at, $c->last_access_at, (int) $c->denied_count, (int) $c->timeout_count,
                (int) $c->reset_count, (int) $c->nonstandard_port_count, (int) $c->unique_apps,
                (int) $c->unique_destinations, (int) $c->critical_count, (int) $c->high_count,
                (int) $c->elevated_count, (int) $c->medium_count, (int) $c->risky_count,
                (float) $c->risk_score, $c->risk_level, $c->risk_reason, (float) $c->habit_score,
                $c->habit_level, $c->habit_reason, $c->combined_status, $c->combined_label,
                $this->ranked($c->top_apps, 'app'), $this->ranked($c->top_countries, 'dstcountry'),
                $this->ranked($c->top_policies, 'policyname'),
            ], null, 'A'.(6 + $i));
        }

        $last = 5 + $clients->count();
        $widths = array_fill(0, 40, 14);
        foreach ([0, 2, 3, 4] as $index) {
            $widths[$index] = 22;
        }
        foreach ([31, 34, 37, 38, 39] as $index) {
            $widths[$index] = 42;
        }
        $this->finishTable($sheet, "A6:AN{$last}", "A5:AN{$last}", 'G6', $widths);
        $sheet->getStyle("AF6:AN{$last}")->getAlignment()->setWrapText(true);
    }

    private function habitScore(Worksheet $sheet, Collection $clients, AnalysisBatch $batch): void
    {
        $this->title($sheet, 'A1:Q2', 'SKOR HABIT & ANOMALI • '.($batch->label ?: 'UNIT'), 'A3:Q3',
            'Dimensi perilaku terpisah dari risiko aplikasi; skor tinggi berarti pola berbeda dan perlu konteks');
        $sheet->fromArray([
            'Prioritas', 'Identity', 'Tipe', 'IP Terkait', 'Skor Habit', 'Level', 'Status Gabungan',
            'Luar Jam Kerja', 'Traffic vs Baseline', 'Deny/Timeout/Reset', 'Port Nonstandar',
            'Sebaran Jam Aktif', 'Tujuan/App Tidak Biasa', 'Jam Dominan', 'Jam Aktif', 'Alasan Habit', 'Skor Risiko',
        ], null, 'A5');
        $this->header($sheet, 'A5:Q5');
        foreach ($clients->sortByDesc('habit_score')->values() as $i => $c) {
            $components = json_decode($c->habit_components ?: '[]', true) ?: [];
            $ips = collect(json_decode($c->associated_ips ?: '[]', true) ?: [])->pluck('ip')->implode(', ');
            $sheet->fromArray([
                $i + 1, $c->identity_value ?: $c->srcip, $c->identity_type ?: 'ip', $ips,
                (float) $c->habit_score, $c->habit_level, $c->combined_label,
                (float) ($components['off_hours'] ?? 0), (float) ($components['traffic_deviation'] ?? 0),
                (float) ($components['attention_actions'] ?? 0), (float) ($components['nonstandard_ports'] ?? 0),
                (float) ($components['active_hours_spread'] ?? 0), (float) ($components['unusual_diversity'] ?? 0),
                $c->dominant_hour === null ? '-' : sprintf('%02d:00', $c->dominant_hour),
                (int) $c->active_hour_count, $c->habit_reason, (float) $c->risk_score,
            ], null, 'A'.(6 + $i));
        }
        $last = 5 + $clients->count();
        $this->finishTable($sheet, "A6:Q{$last}", "A5:Q{$last}", 'B6',
            [10, 24, 12, 28, 13, 12, 20, 16, 18, 20, 17, 17, 20, 14, 12, 58, 13]);
        $sheet->getStyle("P6:P{$last}")->getAlignment()->setWrapText(true);
    }

    private function outlier(Worksheet $sheet, AnalysisBatch $batch): void
    {
        $rows = collect($batch->summary_data['outliers'] ?? []);
        $this->title($sheet, 'A1:O2', 'OUTLIER AKTIVITAS • '.($batch->label ?: 'UNIT'), 'A3:O3',
            'Aktivitas dengan traffic atau durasi paling menonjol; bukan otomatis pelanggaran');
        $sheet->fromArray([
            'Jenis Outlier', 'Waktu', 'Identity', 'Tipe', 'IP', 'Aplikasi/Service', 'Destination',
            'Port', 'Negara', 'Action', 'Policy', 'App Risk', 'Durasi (detik)', 'Traffic (byte)', 'Periode',
        ], null, 'A5');
        $this->header($sheet, 'A5:O5');
        foreach ($rows->values() as $i => $row) {
            $sheet->fromArray([
                $row['type'] ?? '-', $row['logged_at'] ?? '-', $row['identity_value'] ?? '-',
                $row['identity_type'] ?? '-', $row['srcip'] ?? '-', $row['app'] ?? '-',
                $row['dstip'] ?? '-', $row['dstport'] ?? '-', $row['dstcountry'] ?? '-',
                $row['action'] ?? '-', $row['policyname'] ?? '-', $row['apprisk'] ?? '-',
                (int) ($row['duration'] ?? 0), (int) ($row['total_bytes'] ?? 0), $row['period'] ?? '-',
            ], null, 'A'.(6 + $i));
        }
        $last = max(6, 5 + $rows->count());
        $this->finishTable($sheet, "A6:O{$last}", "A5:O{$last}", 'C6',
            [20, 20, 24, 12, 16, 24, 18, 10, 16, 13, 24, 12, 16, 18, 18]);
    }

    private function metadata(Worksheet $sheet, AnalysisBatch $batch): void
    {
        $summary = $batch->summary_data['summary'] ?? [];
        $sheet->fromArray([
            ['format_version', 2],
            ['total_access', (int) ($summary['total_logs'] ?? 0)],
            ['unique_apps', (int) ($summary['unique_apps'] ?? 0)],
            ['total_upload', (int) ($summary['total_upload'] ?? 0)],
            ['total_download', (int) ($summary['total_download'] ?? 0)],
            ['total_duration', (int) ($summary['total_duration'] ?? 0)],
            ['work_hours_count', (int) ($summary['work_hours_count'] ?? 0)],
            ['off_hours_count', (int) ($summary['off_hours_count'] ?? 0)],
            ['peak_hour', $summary['peak_hour'] ?? null],
            ['top_app', $summary['top_app'] ?? null],
            ['top_action', $summary['top_action'] ?? null],
            ['top_risk', $summary['top_risk'] ?? null],
            ['first_seen', $summary['first_seen'] ?? null],
            ['last_seen', $summary['last_seen'] ?? null],
            ['total_denied', (int) ($summary['total_denied'] ?? 0)],
            ['risk_score', data_get($batch->summary_data, 'unit_risk.score', 0)],
            ['risk_level', data_get($batch->summary_data, 'unit_risk.level', 'low')],
            ['habit_score', data_get($batch->summary_data, 'unit_habit.score', 0)],
            ['risk_distribution', json_encode($batch->summary_data['risk_distribution'] ?? [])],
            ['habit_distribution', json_encode($batch->summary_data['habit_distribution'] ?? [])],
            ['outliers', json_encode($batch->summary_data['outliers'] ?? [], JSON_UNESCAPED_SLASHES)],
        ], null, 'A1');
    }

    private function reference(Worksheet $sheet): void
    {
        $this->title($sheet, 'A1:H2', 'REFERENSI & CARA MEMBACA LAPORAN', 'A3:H3', 'Definisi, batasan data, dan panduan tindak lanjut');
        $this->section($sheet, 'A5:H5', 'KETERSEDIAAN DATA');
        $sheet->fromArray(['Elemen', 'Nilai', 'Penjelasan'], null, 'A7');
        $this->header($sheet, 'A7:C7');
        $sheet->fromArray([
            ['Identitas utama', 'IP User (srcip)', 'Nama client tidak terdapat pada log sumber. Laporan tidak membuat nama tebakan.'],
            ['Nama Client', 'Tidak tersedia pada log', 'Ini bukan bug; tambahkan pemetaan IP–pemilik dari inventaris aset bila tersedia.'],
            ['Top App', 'Jumlah event per aplikasi', 'Top App berasal dari field app dan diurutkan berdasarkan jumlah event per user.'],
            ['Total Akses', 'Jumlah event traffic', 'Volume aktivitas tidak otomatis berarti tingkat bahaya.'],
            ['Skor Risiko', 'Prioritas investigasi', 'Skor bukan vonis. Selalu validasi konteks pekerjaan dan pemilik IP.'],
        ], null, 'A8');
        $this->body($sheet, 'A8:C12');
        $sheet->getStyle('C8:C12')->getAlignment()->setWrapText(true);
        $this->widths($sheet, [20, 32, 72, 12, 12, 12, 12, 12, 4, 18, 18]);
        $sheet->setShowGridlines(false);
    }

    private function chartData(Worksheet $sheet, Collection $clients, Collection $apps): void
    {
        $sheet->fromArray([['Level Risiko', 'Jumlah User'], ['Rendah', $clients->where('risk_level', 'low')->count()],
            ['Menengah', $clients->where('risk_level', 'medium')->count()], ['Tinggi', $clients->where('risk_level', 'high')->count()]], null, 'J4');
        $sheet->fromArray([['IP User', 'Total Akses']], null, 'J10');
        foreach ($clients->sortByDesc('total_access')->take(10)->values() as $i => $c) $sheet->fromArray([$c->srcip, (int) $c->total_access], null, 'J'.(11 + $i));
        $sheet->fromArray([['Aplikasi', 'Total Event']], null, 'J23');
        foreach ($apps as $i => $app) $sheet->fromArray([$app['app'] ?? '-', (int) ($app['total'] ?? 0)], null, 'J'.(24 + $i));
    }

    private function chart(Worksheet $sheet, string $type, string $title, string $categories, string $values, string $top, string $bottom, int $points): void
    {
        $series = new DataSeries(
            $type === 'doughnut' ? DataSeries::TYPE_DOUGHNUTCHART : DataSeries::TYPE_BARCHART,
            $type === 'bar' ? DataSeries::GROUPING_CLUSTERED : null,
            [0], [],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $categories, null, $points)],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $values, null, $points)],
            $type === 'bar' ? DataSeries::DIRECTION_COL : null
        );
        $chart = new Chart(preg_replace('/\W+/', '', $title), new Title($title),
            $type === 'doughnut' ? new Legend(Legend::POSITION_BOTTOM) : null, new PlotArea(null, [$series]));
        $chart->setTopLeftPosition($top)->setBottomRightPosition($bottom);
        $sheet->addChart($chart);
    }

    private function title(Worksheet $sheet, string $range, string $title, string $subRange, string $subtitle): void
    {
        $sheet->mergeCells($range)->setCellValue(strtok($range, ':'), $title);
        $sheet->mergeCells($subRange)->setCellValue(strtok($subRange, ':'), $subtitle);
        foreach ([$range, $subRange] as $target) $sheet->getStyle($target)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::NAVY);
        $sheet->getStyle($range)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::WHITE))->setBold(true)->setSize(22);
        $sheet->getStyle($subRange)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('CBD5E1'))->setSize(10);
    }

    private function section(Worksheet $sheet, string $range, string $text): void
    {
        $sheet->mergeCells($range)->setCellValue(strtok($range, ':'), $text);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::BLUE);
        $sheet->getStyle($range)->getFont()->setBold(true)->setSize(12)->getColor()->setRGB(self::NAVY);
    }

    private function header(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SLATE]],
            'font' => ['color' => ['rgb' => self::WHITE], 'bold' => true],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
    }

    private function body(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['color' => ['rgb' => self::NAVY], 'size' => 9],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
            'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'E2E8F0']]],
        ]);
    }

    private function finishTable(Worksheet $sheet, string $body, string $filter, string $freeze, array $widths): void
    {
        $this->body($sheet, $body);
        $sheet->setAutoFilter($filter)->freezePane($freeze)->setShowGridlines(false);
        $this->widths($sheet, $widths);
    }

    private function widths(Worksheet $sheet, array $widths): void
    {
        foreach ($widths as $index => $width) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
    }

    private function ranked(?string $json, string $key): string
    {
        $rows = json_decode($json ?: '[]', true);
        if (!is_array($rows) || $rows === []) return 'Tidak tersedia';

        return collect($rows)->take(3)->map(fn (array $row) =>
            ($row[$key] ?? '-').' ('.number_format((int) ($row['total_access'] ?? 0), 0, ',', '.').')'
        )->implode(' • ');
    }
}
