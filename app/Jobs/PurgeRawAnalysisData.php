<?php

namespace App\Jobs;

use App\Models\AnalysisBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurgeRawAnalysisData implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 3;

    public function __construct(public int $batchId)
    {
        $this->delay(now()->addSeconds(10));
    }

    public function handle(): void
    {
        $batch = AnalysisBatch::find($this->batchId);

        if (
            ! $batch
            || $batch->status !== 'completed'
            || ! $batch->published_at
            || $batch->raw_purged_at
        ) {
            return;
        }

        do {
            $ids = DB::table('traffic_logs')
                ->where('analysis_batch_id', $batch->id)
                ->orderBy('id')
                ->limit(5000)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                DB::table('traffic_logs')->whereIn('id', $ids)->delete();
            }
        } while ($ids->isNotEmpty());

        if ($batch->stored_path) {
            Storage::delete($batch->stored_path);
        }

        $batch->update([
            'raw_purged_at' => now(),
            'summary_stage' => 'Hasil tersimpan',
        ]);
    }
}
