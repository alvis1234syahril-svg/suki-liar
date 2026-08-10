<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAnalysisRequest;
use App\Jobs\BuildAnalysisSummary;
use App\Jobs\ParseFortiGateLog;
use App\Models\AnalysisBatch;
use App\Models\OrganizationalUnit;
use App\Services\HabitAssessmentService;
use App\Services\HabitWorkbookExporter;
use App\Services\RiskAssessmentService;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AnalysisController extends Controller
{
    public function index()
    {
        $batch = AnalysisBatch::query()->latest()->first();

        return $batch
            ? redirect()->route('analysis.show', $batch)
            : redirect()->route('analysis.create');
    }

    public function status(AnalysisBatch $batch)
    {
        $progress = $batch->status === 'summarizing'
            ? (int) $batch->summary_progress
            : ($batch->file_size > 0
            ? min(100, (int) round(($batch->processed_bytes / $batch->file_size) * 100))
            : ($batch->status === 'completed' ? 100 : null));

        return response()->json([
            'status' => $batch->status,
            'processed_lines' => $batch->processed_lines,
            'invalid_lines' => $batch->invalid_lines,
            'total_lines' => $batch->total_lines,
            'file_size' => $batch->file_size,
            'processed_bytes' => $batch->processed_bytes,
            'progress' => $progress,
            'summary_stage' => $batch->summary_stage,
            'result_url' => $batch->organizational_unit_id
                ? route('units.show', $batch->organizationalUnit)
                : route('analysis.show', $batch),
            'failure_message' => $batch->failure_message,
        ]);
    }

    public function show(AnalysisBatch $batch, RiskAssessmentService $riskService)
    {
        if ($batch->status === 'completed' && empty($batch->summary_data)) {
            $claimed = AnalysisBatch::query()
                ->whereKey($batch->id)
                ->where('status', 'completed')
                ->whereNull('summary_data')
                ->update(['status' => 'summarizing']);

            if ($claimed === 1) {
                BuildAnalysisSummary::dispatch($batch->id);
            }

            $batch->refresh();
        }

        if (
            $batch->status === 'completed'
            && $batch->organizational_unit_id
            && request()->boolean('progress')
        ) {
            return redirect()->route('units.show', $batch->organizationalUnit);
        }

        if ($batch->status !== 'completed') {
            return response()
                ->view('analysis.processing', compact('batch'))
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->header('Pragma', 'no-cache');
        }

        $logs = DB::table('traffic_logs')
            ->where('analysis_batch_id', $batch->id);

        $snapshot = $batch->summary_data;
        $summary = $snapshot['summary'] ?? [];
        $topApps = collect($snapshot['top_apps'] ?? [])->map(fn ($row) => (object) $row);
        $topCountries = collect($snapshot['top_countries'] ?? [])->map(fn ($row) => (object) $row);

        $clients = DB::table('analysis_client_summaries')
            ->where('analysis_batch_id', $batch->id)
            ->orderByRaw("CASE combined_status WHEN 'priority' THEN 1 WHEN 'habit' THEN 2 WHEN 'risk' THEN 3 ELSE 4 END")
            ->orderByDesc('habit_score')
            ->orderByDesc('risk_score')
            ->paginate(20);

        $unitRisk = $snapshot['unit_risk'] ?? ['score' => 0, 'level' => 'low', 'label' => 'Rendah', 'risky_clients' => 0];
        $unitHabit = $snapshot['unit_habit'] ?? ['score' => 0, 'level' => 'low', 'label' => 'Rendah', 'anomalous_clients' => 0];
        $riskDistribution = $snapshot['risk_distribution'] ?? ['low' => 0, 'medium' => 0, 'high' => 0];
        $habitDistribution = $snapshot['habit_distribution'] ?? ['low' => 0, 'medium' => 0, 'high' => 0];
        $combinedDistribution = $snapshot['combined_distribution'] ?? ['normal' => 0, 'risk' => 0, 'habit' => 0, 'priority' => 0];
        $activityTrend = collect($snapshot['activity_trend'] ?? [])->map(fn ($row) => (object) $row);
        $hourlyActivity = collect($snapshot['hourly_activity'] ?? [])->map(fn ($row) => (object) $row);
        $actionDistribution = collect($snapshot['action_distribution'] ?? [])->map(fn ($row) => (object) $row);
        $outliers = collect($snapshot['outliers'] ?? [])->map(fn ($row) => (object) $row);

        $appsByClient = $clients->getCollection()->mapWithKeys(fn ($client) => [
            ($client->identity_key ?: $client->srcip) => collect(json_decode($client->top_apps ?: '[]'))->map(fn ($row) => (object) $row),
        ]);
        $countriesByClient = $clients->getCollection()->mapWithKeys(fn ($client) => [
            ($client->identity_key ?: $client->srcip) => collect(json_decode($client->top_countries ?: '[]'))->map(fn ($row) => (object) $row),
        ]);
        $policiesByClient = $clients->getCollection()->mapWithKeys(fn ($client) => [
            ($client->identity_key ?: $client->srcip) => collect(json_decode($client->top_policies ?: '[]'))->map(fn ($row) => (object) $row),
        ]);

        return view('analysis.dashboard', compact(
            'batch',
            'summary',
            'topApps',
            'topCountries',
            'clients',
            'appsByClient',
            'countriesByClient',
            'policiesByClient', 'unitRisk', 'unitHabit', 'riskDistribution', 'habitDistribution', 'combinedDistribution',
            'activityTrend', 'hourlyActivity', 'actionDistribution', 'outliers'
        ));
    }

    public function showClient(
        AnalysisBatch $batch,
        string $identity,
        RiskAssessmentService $riskService,
        HabitAssessmentService $habitService
    ) {
        if ($batch->status !== 'completed') {
            return redirect()->route('analysis.show', $batch);
        }

        $clientSummary = DB::table('analysis_client_summaries')
            ->where('analysis_batch_id', $batch->id)
            ->where(function ($query) use ($identity) {
                $query->where('identity_key', $identity)->orWhere('srcip', $identity);
            })
            ->first();

        abort_unless($clientSummary, 404);

        $logs = DB::table('traffic_logs')->where('analysis_batch_id', $batch->id);
        if ($clientSummary->identity_key) {
            $logs->where('identity_key', $clientSummary->identity_key);
        } else {
            $logs->where('srcip', $identity);
        }

        $summary = [
            'total_access' => (int) $clientSummary->total_access,
            'total_upload' => (int) $clientSummary->total_upload,
            'total_download' => (int) $clientSummary->total_download,
            'first_access_at' => $clientSummary->first_access_at,
            'last_access_at' => $clientSummary->last_access_at,
            'critical_count' => (int) $clientSummary->critical_count,
            'high_count' => (int) $clientSummary->high_count,
            'elevated_count' => (int) $clientSummary->elevated_count,
            'medium_count' => (int) $clientSummary->medium_count,
            'denied_count' => (int) $clientSummary->denied_count,
            'timeout_count' => (int) $clientSummary->timeout_count,
            'reset_count' => (int) $clientSummary->reset_count,
            'nonstandard_port_count' => (int) $clientSummary->nonstandard_port_count,
            'work_hours_count' => (int) $clientSummary->work_hours_count,
            'off_hours_count' => (int) $clientSummary->off_hours_count,
            'active_hour_count' => (int) $clientSummary->active_hour_count,
            'dominant_hour' => $clientSummary->dominant_hour,
            'total_bytes' => (int) $clientSummary->total_bytes,
            'total_duration' => (int) $clientSummary->total_duration,
            'unique_apps' => (int) $clientSummary->unique_apps,
            'unique_destinations' => (int) $clientSummary->unique_destinations,
        ];
        $identity = [
            'key' => $clientSummary->identity_key,
            'value' => $clientSummary->identity_value ?: $clientSummary->srcip,
            'type' => $clientSummary->identity_type ?: 'ip',
            'name' => $clientSummary->client_name ?: $clientSummary->identity_value,
            'interface' => $clientSummary->source_interface,
            'ips' => json_decode($clientSummary->associated_ips ?: '[]', true) ?: [],
        ];

        $riskAssessment = $riskService->assess($summary);
        $habitAssessment = $habitService->assess($summary, data_get($batch->summary_data, 'habit_baseline', []));
        $combinedAssessment = $habitService->combine($riskAssessment, $habitAssessment);
        $summary['risky_count'] = $riskAssessment['risky_events'];

        $profile = json_decode($clientSummary->profile_data ?: '{}', true) ?: [];
        $topApps = collect(json_decode($clientSummary->top_apps ?: '[]', true) ?: [])
            ->map(fn (array $row) => (object) array_merge([
                'app' => 'Tidak teridentifikasi', 'appcat' => null, 'apprisk' => null,
                'total_access' => 0, 'total_upload' => 0, 'total_download' => 0,
            ], $row));
        $topDestinations = collect($profile['top_destinations'] ?? [])
            ->map(fn (array $row) => (object) array_merge([
                'dstip' => '-', 'dstcountry' => null, 'service' => null,
                'total_access' => 0, 'total_upload' => 0, 'total_download' => 0,
            ], $row));
        $clientTrend = collect($profile['daily_activity'] ?? [])->map(fn (array $row) => (object) $row);
        $hourlyActivity = collect($profile['hourly_activity'] ?? [])->map(fn (array $row) => (object) $row);
        if ($hourlyActivity->isEmpty()) {
            $hourlyActivity = collect(range(0, 23))->map(fn (int $hour) => (object) ['hour' => $hour, 'total' => 0]);
        }
        $clientActions = collect($profile['action_distribution'] ?? [])
            ->map(fn (array $row) => (object) [
                'action' => $row['action'] ?? 'unknown',
                'total' => (int) ($row['total_access'] ?? $row['total'] ?? 0),
            ]);
        $behavior = (object) [
            'unique_apps' => (int) $clientSummary->unique_apps,
            'unique_destinations' => (int) $clientSummary->unique_destinations,
            'unique_countries' => count(json_decode($clientSummary->top_countries ?: '[]', true) ?: []),
            'unique_policies' => count(json_decode($clientSummary->top_policies ?: '[]', true) ?: []),
            'average_duration' => $clientSummary->total_access > 0
                ? (float) $clientSummary->total_duration / $clientSummary->total_access
                : 0,
        ];

        if ($batch->raw_purged_at) {
            $recentLogs = new LengthAwarePaginator([], 0, 50, 1, ['path' => request()->url()]);
        } else {
            $recentLogs = (clone $logs)
                ->select([
                    'logged_at', 'app', 'service', 'dstip', 'dstport', 'dstcountry',
                    'action', 'policyname', 'apprisk', 'sentbyte', 'rcvdbyte', 'duration',
                ])
                ->orderByDesc('logged_at')
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString();
        }

        $peerStats = DB::table('analysis_client_summaries')
            ->where('analysis_batch_id', $batch->id)
            ->selectRaw('COUNT(*) as total_clients, AVG(total_access) as average_access, AVG(risk_score) as average_risk, AVG(habit_score) as average_habit')
            ->first();

        $higherRiskClients = $clientSummary
            ? DB::table('analysis_client_summaries')
                ->where('analysis_batch_id', $batch->id)
                ->where('risk_score', '>', $riskAssessment['score'])
                ->count()
            : 0;
        $higherHabitClients = $clientSummary
            ? DB::table('analysis_client_summaries')
                ->where('analysis_batch_id', $batch->id)
                ->where('habit_score', '>', $habitAssessment['score'])
                ->count()
            : 0;

        $peerComparison = [
            'total_clients' => (int) ($peerStats->total_clients ?? 0),
            'average_access' => round((float) ($peerStats->average_access ?? 0), 1),
            'average_risk' => round((float) ($peerStats->average_risk ?? 0), 1),
            'average_habit' => round((float) ($peerStats->average_habit ?? 0), 1),
            'risk_percentile' => ($peerStats->total_clients ?? 0) > 0
                ? max(1, (int) round((1 - ($higherRiskClients / $peerStats->total_clients)) * 100))
                : null,
            'habit_percentile' => ($peerStats->total_clients ?? 0) > 0
                ? max(1, (int) round((1 - ($higherHabitClients / $peerStats->total_clients)) * 100))
                : null,
        ];

        $clientOutliers = collect(data_get($batch->summary_data, 'outliers', []))
            ->filter(fn (array $row) => ($clientSummary?->identity_key && ($row['identity_key'] ?? null) === $clientSummary->identity_key)
                || (! $clientSummary?->identity_key && ($row['srcip'] ?? null) === ($identity['value'] ?? null)))
            ->values();

        return view('analysis.client-detail', compact(
            'batch', 'identity', 'summary', 'behavior', 'riskAssessment', 'habitAssessment',
            'combinedAssessment', 'peerComparison', 'topApps', 'topDestinations', 'recentLogs',
            'clientTrend', 'hourlyActivity', 'clientActions', 'clientOutliers'
        ));
    }

    public function create()
    {
        $units = OrganizationalUnit::query()
            ->where('is_active', true)
            ->withCount('analyses')
            ->orderBy('sort_order')
            ->get();

        return view('analysis.upload', compact('units'));
    }

    public function startUpload(Request $request)
    {
        $validated = $request->validate([
            'file_name' => ['required', 'string', 'max:255'],
            'file_size' => ['required', 'integer', 'min:1', 'max:524288000'],
            'organizational_unit_id' => ['required', 'integer', 'exists:organizational_units,id'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $extension = strtolower(pathinfo($validated['file_name'], PATHINFO_EXTENSION));

        abort_unless(in_array($extension, ['txt', 'log'], true), 422, 'File harus berformat .txt atau .log.');

        $token = (string) Str::uuid();
        $metadataPath = "upload-sessions/{$token}.json";
        $temporaryPath = "upload-sessions/{$token}.part";

        Storage::put($temporaryPath, '');
        Storage::put($metadataPath, json_encode([
            'original_filename' => basename($validated['file_name']),
            'label' => $validated['label'] ?? null,
            'organizational_unit_id' => (int) $validated['organizational_unit_id'],
            'file_size' => (int) $validated['file_size'],
            'uploaded_bytes' => 0,
            'next_index' => 0,
            'expires_at' => now()->addHours(2)->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return response()->json([
            'token' => $token,
            'chunk_url' => route('analysis.uploads.chunk', $token),
            'complete_url' => route('analysis.uploads.complete', $token),
        ], 201);
    }

    public function uploadChunk(Request $request, string $token)
    {
        $metadataPath = "upload-sessions/{$token}.json";
        $temporaryPath = "upload-sessions/{$token}.part";

        abort_unless(Str::isUuid($token) && Storage::exists($metadataPath), 404, 'Sesi upload tidak ditemukan atau sudah kedaluwarsa.');

        $validated = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file', 'max:10240'],
        ]);

        $metadata = json_decode(Storage::get($metadataPath), true, 512, JSON_THROW_ON_ERROR);
        abort_if(now()->isAfter($metadata['expires_at']), 410, 'Sesi upload sudah kedaluwarsa.');

        $index = (int) $validated['index'];

        if ($index < $metadata['next_index']) {
            return response()->json([
                'uploaded_bytes' => $metadata['uploaded_bytes'],
                'next_index' => $metadata['next_index'],
            ]);
        }

        abort_if($index !== $metadata['next_index'], 409, 'Urutan potongan file tidak sesuai.');

        $chunk = $request->file('chunk');
        $chunkSize = $chunk->getSize();
        abort_if(($metadata['uploaded_bytes'] + $chunkSize) > $metadata['file_size'], 422, 'Ukuran upload melebihi ukuran file awal.');

        $destination = @fopen(Storage::path($temporaryPath), 'ab');
        abort_if($destination === false, 500, 'Potongan file tidak dapat disimpan.');

        $source = @fopen($chunk->getRealPath(), 'rb');

        if ($source === false) {
            fclose($destination);
            abort(500, 'Potongan file tidak dapat dibaca.');
        }

        try {
            flock($destination, LOCK_EX);
            stream_copy_to_stream($source, $destination);
            fflush($destination);
            flock($destination, LOCK_UN);
        } finally {
            fclose($source);
            fclose($destination);
        }

        $metadata['uploaded_bytes'] += $chunkSize;
        $metadata['next_index']++;
        Storage::put($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR));

        return response()->json([
            'uploaded_bytes' => $metadata['uploaded_bytes'],
            'next_index' => $metadata['next_index'],
        ]);
    }

    public function completeUpload(Request $request, string $token)
    {
        $metadataPath = "upload-sessions/{$token}.json";
        $temporaryPath = "upload-sessions/{$token}.part";

        abort_unless(Str::isUuid($token) && Storage::exists($metadataPath) && Storage::exists($temporaryPath), 404, 'Sesi upload tidak ditemukan.');

        $metadata = json_decode(Storage::get($metadataPath), true, 512, JSON_THROW_ON_ERROR);
        $actualSize = Storage::size($temporaryPath);

        abort_if($metadata['uploaded_bytes'] !== $metadata['file_size'] || $actualSize !== $metadata['file_size'], 409, 'Upload belum lengkap.');

        $fingerprint = hash_file('sha256', Storage::path($temporaryPath));
        abort_if($fingerprint === false, 500, 'Sidik file tidak dapat dihitung.');

        if ($existing = $this->fingerprintDuplicate($fingerprint)) {
            Storage::delete([$temporaryPath, $metadataPath]);

            return response()->json([
                'message' => 'File yang sama sudah diproses. Kamu diarahkan ke progres yang sedang berjalan.',
                'redirect_url' => route('analysis.show', $existing),
                'duplicate' => true,
                'duplicate_unit' => $existing->organizationalUnit?->name,
            ]);
        }

        $extension = strtolower(pathinfo($metadata['original_filename'], PATHINFO_EXTENSION));
        $storedPath = 'uploads/'.Str::uuid().'.'.$extension;
        abort_unless(
            Storage::move($temporaryPath, $storedPath),
            500,
            'File upload tidak dapat dipindahkan ke penyimpanan analisis.'
        );

        try {
            $batch = AnalysisBatch::create([
                'uuid' => (string) Str::uuid(),
                'label' => $metadata['label'],
                'organizational_unit_id' => $metadata['organizational_unit_id'],
                'original_filename' => $metadata['original_filename'],
                'stored_path' => $storedPath,
                'status' => 'uploaded',
                'file_size' => $actualSize,
                'file_fingerprint' => $fingerprint,
            ]);
        } catch (Throwable $error) {
            Storage::delete($storedPath);
            throw $error;
        } finally {
            Storage::delete($metadataPath);
        }

        ParseFortiGateLog::dispatch($batch->id);

        return response()->json([
            'message' => 'Upload selesai. Analisis dimulai.',
            'redirect_url' => route('analysis.show', ['batch' => $batch, 'progress' => 1]),
        ], 202);
    }

    public function download(AnalysisBatch $batch): StreamedResponse
    {
        abort_unless($batch->status === 'completed', 409, 'Analisis belum selesai diproses.');
        abort_if($batch->raw_purged_at, 410, 'Log mentah sudah dibersihkan. Laporan Excel dan seluruh ringkasan analisis tetap tersedia.');
        set_time_limit(0);

        $name = $batch->label ?: pathinfo($batch->original_filename, PATHINFO_FILENAME);

        return response()->streamDownload(function () use ($batch) {
            $output = fopen('php://output', 'w');

            // Agar karakter Indonesia terbaca baik di Excel.
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, Csv::row([
                'Waktu Akses',
                'Client IP',
                'Client Name',
                'Source Port',
                'Destination IP',
                'Destination Port',
                'Source Country',
                'Destination Country',
                'Source Interface',
                'Destination Interface',
                'Protocol',
                'Action',
                'Policy ID',
                'Policy Name',
                'Service',
                'Application',
                'Application Category',
                'Application Risk',
                'Duration (detik)',
                'Upload (byte)',
                'Download (byte)',
                'Sent Packet',
                'Received Packet',
                'Raw Log',
            ]));

            DB::table('traffic_logs')
                ->where('analysis_batch_id', $batch->id)
                ->orderBy('id')
                ->cursor()
                ->each(function ($log) use ($output) {
                    fputcsv($output, Csv::row([
                        $log->logged_at,
                        $log->srcip,
                        $log->srcname,
                        $log->srcport,
                        $log->dstip,
                        $log->dstport,
                        $log->srccountry,
                        $log->dstcountry,
                        $log->srcintf,
                        $log->dstintf,
                        $log->proto,
                        $log->action,
                        $log->policyid,
                        $log->policyname,
                        $log->service,
                        $log->app,
                        $log->appcat,
                        $log->apprisk,
                        $log->duration,
                        $log->sentbyte,
                        $log->rcvdbyte,
                        $log->sentpkt,
                        $log->rcvdpkt,
                        $log->raw_log,
                    ]));
                });

            fclose($output);
        }, 'laporan-log-'.Str::slug($name).'-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function downloadHabitReport(AnalysisBatch $batch, HabitWorkbookExporter $exporter): StreamedResponse
    {
        abort_unless($batch->status === 'completed', 409, 'Analisis belum selesai diproses.');
        set_time_limit(0);

        $expectedClients = (int) data_get($batch->summary_data, 'summary.total_clients', 0);
        $hasSummary = DB::table('analysis_client_summaries')
            ->where('analysis_batch_id', $batch->id)
            ->exists();

        abort_if(
            $expectedClients > 0 && ! $hasSummary,
            409,
            'Ringkasan user belum tersedia. Tunggu tahap penyusunan selesai.'
        );

        $name = Str::slug($batch->label ?: pathinfo($batch->original_filename, PATHINFO_FILENAME));

        return response()->streamDownload(function () use ($batch, $exporter) {
            $spreadsheet = $exporter->make($batch);
            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 'laporan-habit-user-'.$name.'-'.now()->format('Ymd-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function downloadHabitReportCsvLegacy(AnalysisBatch $batch): StreamedResponse
    {
        abort_unless($batch->status === 'completed', 409, 'Analisis belum selesai diproses.');
        set_time_limit(0);

        $logs = DB::table('traffic_logs')
            ->where('analysis_batch_id', $batch->id);

        // Agregasi aplikasi per IP. SUBSTRING_INDEX mengambil tiga aplikasi teratas
        // dari setiap IP, sehingga tidak perlu membaca raw log lagi saat membuka CSV.
        $appStats = (clone $logs)
            ->select(
                'srcip',
                'app',
                DB::raw('COUNT(*) as total_access'),
                DB::raw('MIN(logged_at) as first_access_at'),
                DB::raw('MAX(logged_at) as last_access_at')
            )
            ->whereNotNull('srcip')
            ->whereNotNull('app')
            ->where('app', '!=', '')
            ->groupBy('srcip', 'app');

        $topApps = DB::query()
            ->fromSub($appStats, 'app_stats')
            ->select(
                'srcip',
                DB::raw("SUBSTRING_INDEX(GROUP_CONCAT(CONCAT(app, ', ', total_access, ' akses (', DATE_FORMAT(first_access_at, '%d-%m-%Y %H:%i'), ' s.d. ', DATE_FORMAT(last_access_at, '%d-%m-%Y %H:%i'), ')') ORDER BY total_access DESC SEPARATOR ' | '), ' | ', 3) as top_apps")
            )
            ->groupBy('srcip');

        $countryStats = (clone $logs)
            ->select('srcip', 'dstcountry', DB::raw('COUNT(*) as total_access'))
            ->whereNotNull('srcip')
            ->whereNotNull('dstcountry')
            ->where('dstcountry', '!=', '')
            ->groupBy('srcip', 'dstcountry');

        $topCountries = DB::query()
            ->fromSub($countryStats, 'country_stats')
            ->select(
                'srcip',
                DB::raw("SUBSTRING_INDEX(GROUP_CONCAT(CONCAT(dstcountry, ' (', total_access, ')') ORDER BY total_access DESC SEPARATOR ' | '), ' | ', 3) as top_countries")
            )
            ->groupBy('srcip');

        $policyStats = (clone $logs)
            ->select('srcip', 'policyname', DB::raw('COUNT(*) as total_access'))
            ->whereNotNull('srcip')
            ->whereNotNull('policyname')
            ->where('policyname', '!=', '')
            ->groupBy('srcip', 'policyname');

        $topPolicies = DB::query()
            ->fromSub($policyStats, 'policy_stats')
            ->select(
                'srcip',
                DB::raw("SUBSTRING_INDEX(GROUP_CONCAT(CONCAT(policyname, ' (', total_access, ')') ORDER BY total_access DESC SEPARATOR ' | '), ' | ', 2) as top_policies")
            )
            ->groupBy('srcip');

        $clients = (clone $logs)
            ->leftJoinSub($topApps, 'top_apps', 'traffic_logs.srcip', '=', 'top_apps.srcip')
            ->leftJoinSub($topCountries, 'top_countries', 'traffic_logs.srcip', '=', 'top_countries.srcip')
            ->leftJoinSub($topPolicies, 'top_policies', 'traffic_logs.srcip', '=', 'top_policies.srcip')
            ->select(
                'traffic_logs.srcip',
                DB::raw("MAX(NULLIF(traffic_logs.srcname, '')) as client_name"),
                DB::raw("MAX(NULLIF(traffic_logs.srcintf, '')) as source_interface"),
                DB::raw('COUNT(*) as total_access'),
                DB::raw('MIN(traffic_logs.logged_at) as first_access_at'),
                DB::raw('MAX(traffic_logs.logged_at) as last_access_at'),
                DB::raw('COALESCE(SUM(traffic_logs.sentbyte), 0) as total_upload'),
                DB::raw('COALESCE(SUM(traffic_logs.rcvdbyte), 0) as total_download'),
                DB::raw("SUM(CASE WHEN traffic_logs.action = 'deny' THEN 1 ELSE 0 END) as denied_count"),
                DB::raw("SUM(CASE WHEN LOWER(traffic_logs.apprisk) = 'critical' THEN 1 ELSE 0 END) as critical_count"),
                DB::raw("SUM(CASE WHEN LOWER(traffic_logs.apprisk) = 'high' THEN 1 ELSE 0 END) as high_count"),
                DB::raw("SUM(CASE WHEN LOWER(traffic_logs.apprisk) = 'elevated' THEN 1 ELSE 0 END) as elevated_count"),
                DB::raw("SUM(CASE WHEN LOWER(traffic_logs.apprisk) = 'medium' THEN 1 ELSE 0 END) as medium_count"),
                DB::raw('MAX(top_apps.top_apps) as top_apps'),
                DB::raw('MAX(top_countries.top_countries) as top_countries'),
                DB::raw('MAX(top_policies.top_policies) as top_policies')
            )
            ->whereNotNull('traffic_logs.srcip')
            ->groupBy('traffic_logs.srcip')
            ->orderByDesc('total_access');

        // Gunakan snapshot per-user yang sudah dibangun setelah parsing. Query agregasi
        // di atas sengaja tidak dieksekusi; snapshot mencegah pemindaian ulang jutaan log.
        $clients = DB::table('analysis_client_summaries')
            ->where('analysis_batch_id', $batch->id)
            ->orderByDesc('total_access');

        $expectedClients = (int) data_get($batch->summary_data, 'summary.total_clients', 0);
        abort_if(
            $expectedClients > 0 && ! (clone $clients)->exists(),
            409,
            'Ringkasan user belum tersedia. Buka dashboard dan tunggu tahap penyusunan selesai.'
        );

        $name = Str::slug($batch->label ?: pathinfo($batch->original_filename, PATHINFO_FILENAME));

        return response()->streamDownload(function () use ($clients) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, Csv::row([
                'Client IP',
                'Nama Client',
                'Interface Asal',
                'Total Akses',
                'Akses Pertama',
                'Akses Terakhir',
                'Top 3 Aplikasi dan Rentang Waktu',
                'Top 3 Negara Tujuan',
                'Policy Terbanyak',
                'Skor Risiko',
                'Level Risiko',
                'Alasan Risiko',
                'Event Critical',
                'Event High',
                'Event Elevated',
                'Event Medium',
                'Koneksi Ditolak',
                'Total Upload (byte)',
                'Total Download (byte)',
            ]), ';');

            foreach ($clients->cursor() as $client) {
                fputcsv($output, Csv::row([
                    $client->srcip,
                    $client->client_name ?: '-',
                    $client->source_interface ?: '-',
                    $client->total_access,
                    $client->first_access_at,
                    $client->last_access_at,
                    $this->formatTopApps($client->top_apps),
                    $this->formatRankedMetric($client->top_countries, 'dstcountry'),
                    $this->formatRankedMetric($client->top_policies, 'policyname'),
                    $client->risk_score,
                    $client->risk_label,
                    $client->risk_reason,
                    $client->critical_count,
                    $client->high_count,
                    $client->elevated_count,
                    $client->medium_count,
                    $client->denied_count,
                    $client->total_upload,
                    $client->total_download,
                ]), ';');
            }

            fclose($output);
        }, 'laporan-habit-user-'.$name.'-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function formatTopApps(?string $json): string
    {
        $apps = json_decode($json ?: '[]', true);

        if (! is_array($apps) || $apps === []) {
            return '-';
        }

        return collect($apps)->map(function (array $app) {
            $range = ! empty($app['first_access_at']) && ! empty($app['last_access_at'])
                ? " ({$app['first_access_at']} s.d. {$app['last_access_at']})"
                : '';

            return ($app['app'] ?? '-').', '.number_format((int) ($app['total_access'] ?? 0)).' akses'.$range;
        })->implode(' | ');
    }

    private function formatRankedMetric(?string $json, string $key): string
    {
        $rows = json_decode($json ?: '[]', true);

        if (! is_array($rows) || $rows === []) {
            return '-';
        }

        return collect($rows)
            ->map(fn (array $row) => ($row[$key] ?? '-').' ('.number_format((int) ($row['total_access'] ?? 0)).')')
            ->implode(' | ');
    }

    public function destroy(AnalysisBatch $batch)
    {
        $unit = $batch->organizationalUnit;
        DB::transaction(function () use ($batch) {
            Storage::delete($batch->stored_path);
            $batch->delete();
        });

        return redirect()
            ->route($unit ? 'units.show' : 'analysis.create', $unit ? [$unit] : [])
            ->with('success', 'Hasil analisis berhasil dihapus.');
    }

    public function retry(AnalysisBatch $batch)
    {
        abort_unless($batch->status === 'failed', 409, 'Hanya analisis yang gagal yang dapat diproses ulang.');
        abort_unless(Storage::exists($batch->stored_path), 410, 'File sumber sudah tidak tersedia. Unggah kembali file log.');

        DB::transaction(function () use ($batch): void {
            $claimed = AnalysisBatch::query()
                ->whereKey($batch->id)
                ->where('status', 'failed')
                ->update([
                    'status' => 'uploaded',
                    'failure_message' => null,
                    'processed_bytes' => 0,
                    'summary_progress' => 0,
                    'summary_stage' => null,
                    'total_lines' => 0,
                    'processed_lines' => 0,
                    'invalid_lines' => 0,
                    'started_at' => null,
                    'completed_at' => null,
                    'published_at' => null,
                    'raw_purged_at' => null,
                    'expires_at' => null,
                    'summary_data' => null,
                    'updated_at' => now(),
                ]);

            abort_unless($claimed === 1, 409, 'Analisis ini sudah masuk antrean proses ulang.');

            DB::table('traffic_logs')->where('analysis_batch_id', $batch->id)->delete();
            DB::table('analysis_client_summaries')->where('analysis_batch_id', $batch->id)->delete();

            ParseFortiGateLog::dispatch($batch->id);
        });

        return redirect()
            ->route('analysis.show', ['batch' => $batch, 'progress' => 1])
            ->with('success', 'File lama berhasil dimasukkan kembali ke antrean analisis.');
    }

    public function update(Request $request, AnalysisBatch $batch)
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'organizational_unit_id' => ['required', 'integer', 'exists:organizational_units,id'],
        ]);

        $updates = [
            'organizational_unit_id' => $validated['organizational_unit_id'],
        ];

        if ($request->exists('label')) {
            $updates['label'] = trim((string) ($validated['label'] ?? '')) ?: null;
        }

        $batch->update($updates);

        return redirect()
            ->route('units.show', $batch->organizationalUnit)
            ->with('success', 'Unit hasil analisis berhasil diperbarui.');
    }

    public function store(StoreAnalysisRequest $request)
    {
        $file = $request->file('log_file');
        $fileSize = (int) $file->getSize();
        $fingerprint = hash_file('sha256', $file->getRealPath());
        abort_if($fingerprint === false, 500, 'Sidik file tidak dapat dihitung.');

        if ($existing = $this->fingerprintDuplicate($fingerprint)) {
            $redirectUrl = route('analysis.show', $existing);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'File yang sama sudah diproses. Membuka progres yang sedang berjalan.',
                    'redirect_url' => $redirectUrl,
                    'duplicate' => true,
                    'duplicate_unit' => $existing->organizationalUnit?->name,
                ]);
            }

            return redirect()->to($redirectUrl)
                ->with('success', 'File yang sama sudah ada di antrean. Progres yang lama dibuka kembali.');
        }

        $path = $file->store('uploads');

        $batch = AnalysisBatch::create([
            'uuid' => (string) Str::uuid(),
            'organizational_unit_id' => $request->integer('organizational_unit_id'),
            'label' => $request->input('label'),
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'status' => 'uploaded',
            'file_size' => $fileSize ?: Storage::size($path),
            'file_fingerprint' => $fingerprint,
        ]);

        ParseFortiGateLog::dispatch($batch->id);

        $redirectUrl = route('analysis.show', ['batch' => $batch, 'progress' => 1]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'File berhasil diunggah. Analisis dimulai.',
                'redirect_url' => $redirectUrl,
            ], 202);
        }

        return redirect()
            ->to($redirectUrl)
            ->with('success', 'File berhasil diunggah. Analisis sedang diproses.');
    }

    private function fingerprintDuplicate(string $fingerprint): ?AnalysisBatch
    {
        return AnalysisBatch::query()
            ->with('organizationalUnit')
            ->where('file_fingerprint', $fingerprint)
            ->where('status', '!=', 'failed')
            ->latest('id')
            ->first();
    }
}
