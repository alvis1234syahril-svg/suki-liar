<?php

namespace App\Jobs;

use App\Models\AnalysisBatch;
use App\Services\FortiGateLogParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ParseFortiGateLog implements ShouldQueue
{
    use Queueable;

    /**
     * Keep one insert below SQLite's bound-parameter limit. A parsed traffic
     * row currently contains roughly 35 columns, so 1,000 rows can exceed the
     * default SQLite limit and leave an upload stuck or failed at zero percent.
     */
    private const INSERT_BATCH_SIZE = 500;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public int $batchId
    ) {}

    public function handle(FortiGateLogParser $parser): void
    {
        $batch = AnalysisBatch::findOrFail($this->batchId);

        $batch->update([
            'status' => 'processing',
            'started_at' => now(),
            'processed_bytes' => 0,
        ]);

        $path = Storage::path($batch->stored_path);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('File log tidak dapat dibuka.');
        }

        $rows = [];
        $total = 0;
        $processed = 0;
        $invalid = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $total++;

                $data = $parser->parseLine($line);

                if (empty($data)) {
                    $invalid++;

                    continue;
                }

                $rows[] = $parser->toDatabaseRow($batch->id, $data, $line);
                $processed++;

                if (count($rows) >= self::INSERT_BATCH_SIZE) {
                    DB::table('traffic_logs')->insert($rows);
                    $rows = [];

                    $batch->update([
                        'total_lines' => $total,
                        'processed_lines' => $processed,
                        'invalid_lines' => $invalid,
                        'processed_bytes' => ftell($handle) ?: 0,
                    ]);
                }
            }

            if (! empty($rows)) {
                DB::table('traffic_logs')->insert($rows);
            }

            $batch->update([
                'status' => 'summarizing',
                'summary_progress' => 0,
                'summary_stage' => 'Menunggu penyusunan ringkasan',
                'total_lines' => $total,
                'processed_lines' => $processed,
                'invalid_lines' => $invalid,
                'processed_bytes' => $batch->file_size ?: (filesize($path) ?: 0),
                'completed_at' => now(),
            ]);

            BuildAnalysisSummary::dispatch($batch->id);
        } catch (Throwable $error) {
            $batch->update([
                'status' => 'failed',
                'expires_at' => now()->addDay(),
                'failure_message' => mb_strimwidth($error->getMessage(), 0, 1000, '…'),
            ]);

            throw $error;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    public function failed(Throwable $error): void
    {
        AnalysisBatch::whereKey($this->batchId)->update([
            'status' => 'failed',
            'expires_at' => now()->addDay(),
            'failure_message' => mb_strimwidth($error->getMessage(), 0, 1000, '…'),
        ]);
    }
}
