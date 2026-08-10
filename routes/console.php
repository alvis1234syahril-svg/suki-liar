<?php

use App\Jobs\BuildAnalysisSummary;
use App\Models\AnalysisBatch;
use App\Models\ComparisonUnit;
use App\Services\ComparisonUnitSnapshotService;
use App\Services\HabitAssessmentService;
use App\Services\RiskAssessmentService;
use App\Services\TrafficIdentityResolver;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('analysis:cleanup', function () {
    $deleted = 0;
    $expiredUploads = 0;

    AnalysisBatch::query()
        ->where('status', 'failed')
        ->whereNotNull('expires_at')
        ->where('expires_at', '<=', now())
        ->eachById(function (AnalysisBatch $batch) use (&$deleted) {
            Storage::delete($batch->stored_path);
            $batch->delete();
            $deleted++;
        });

    foreach (Storage::files('upload-sessions') as $path) {
        if (! str_ends_with($path, '.json')) {
            continue;
        }

        try {
            $metadata = json_decode(Storage::get($path), true, 512, JSON_THROW_ON_ERROR);

            if (now()->isAfter($metadata['expires_at'])) {
                $token = pathinfo($path, PATHINFO_FILENAME);
                Storage::delete([$path, "upload-sessions/{$token}.part"]);
                $expiredUploads++;
            }
        } catch (Throwable) {
            Storage::delete($path);
        }
    }

    $this->info("{$deleted} batch dan {$expiredUploads} sesi upload kedaluwarsa berhasil dihapus.");
})->purpose('Menghapus percobaan gagal dan sesi upload yang sudah kedaluwarsa');

Artisan::command('analysis:reset-unit-workspace {--force}', function () {
    if (! $this->option('force') && ! $this->confirm('Hapus seluruh hasil analisis lama dan data pembanding impor?')) {
        $this->warn('Pembersihan dibatalkan.');

        return;
    }

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ([
            'traffic_logs',
            'analysis_client_summaries',
            'comparison_units',
            'analysis_batches',
            'sessions',
            'jobs',
            'failed_jobs',
        ] as $table) {
            DB::table($table)->truncate();
        }
    } finally {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    Storage::deleteDirectory('uploads');
    Storage::deleteDirectory('upload-sessions');

    $this->info('Data analisis, log, sesi upload, file mentah, dan pembanding impor lama berhasil dibersihkan.');
})->purpose('Mengosongkan data lama sebelum memakai dashboard unit');

Artisan::command('analysis:summarize {uuid}', function (string $uuid) {
    $batch = AnalysisBatch::where('uuid', $uuid)->firstOrFail();

    $this->info("Menyusun ringkasan {$batch->original_filename}...");

    (new BuildAnalysisSummary($batch->id))->handle(app(RiskAssessmentService::class));

    $this->info('Ringkasan selesai dan dashboard siap dibuka.');
})->purpose('Membangun ulang snapshot dashboard untuk satu analisis');

Artisan::command('analysis:rebuild-all', function () {
    $batches = AnalysisBatch::query()
        ->whereIn('status', ['completed', 'summarizing'])
        ->orderBy('id')
        ->get();

    if ($batches->isEmpty()) {
        $this->info('Tidak ada analisis selesai yang perlu dihitung ulang.');

        return;
    }

    $bar = $this->output->createProgressBar($batches->count());
    foreach ($batches as $batch) {
        (new BuildAnalysisSummary($batch->id))->handle(
            app(RiskAssessmentService::class),
            app(HabitAssessmentService::class),
            app(TrafficIdentityResolver::class),
        );
        $bar->advance();
    }
    $bar->finish();
    $this->newLine();
    $this->info("{$batches->count()} analisis selesai dihitung ulang dengan identitas dan skor habit terbaru.");
})->purpose('Menghitung ulang seluruh analisis selesai dengan mesin analitik terbaru');

Artisan::command('analysis:analytics-status', function () {
    AnalysisBatch::query()->orderBy('id')->get()->each(function (AnalysisBatch $batch) {
        $version = data_get($batch->summary_data, 'unit_habit.score') !== null ? 'habit siap' : 'habit belum dihitung';
        $sizeMb = round($batch->file_size / 1048576, 1);
        $this->line("#{$batch->id} {$batch->uuid} {$batch->label}: {$batch->status} · {$version} · {$batch->processed_lines}/{$batch->total_lines} baris · {$sizeMb} MB · update {$batch->updated_at}");
    });
})->purpose('Menampilkan status ringkasan analitik setiap batch');

Artisan::command('comparisons:sync-analysis', function (ComparisonUnitSnapshotService $snapshots) {
    $synced = 0;
    ComparisonUnit::query()->each(function (ComparisonUnit $unit) use ($snapshots, &$synced) {
        $batch = AnalysisBatch::query()
            ->where('status', 'completed')
            ->whereRaw('LOWER(TRIM(label)) = ?', [strtolower(trim($unit->unit_name))])
            ->latest('id')
            ->first();
        if (! $batch || data_get($batch->summary_data, 'unit_habit.score') === null) {
            return;
        }
        $unit->update($snapshots->fromBatch($batch));
        $synced++;
    });
    $this->info("{$synced} unit pembanding disinkronkan dari hasil analisis lokal terbaru.");
})->purpose('Memperbarui unit pembanding yang namanya sama dengan label analisis');

Artisan::command('analysis:database-activity', function () {
    collect(DB::select('SHOW FULL PROCESSLIST'))
        ->reject(fn ($row) => strtolower((string) $row->Command) === 'sleep')
        ->each(fn ($row) => $this->line("#{$row->Id} {$row->Command} {$row->Time}s · {$row->State} · ".mb_strimwidth((string) $row->Info, 0, 180, '…')));
})->purpose('Menampilkan query database yang masih aktif saat analisis berjalan');

Schedule::command('analysis:cleanup')->hourly()->withoutOverlapping();
Schedule::command('topology:poll-ports')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(fn (): bool => (bool) config('asset-topology.snmp.enabled'));
