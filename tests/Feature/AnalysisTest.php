<?php

namespace Tests\Feature;

use App\Jobs\BuildAnalysisSummary;
use App\Jobs\ParseFortiGateLog;
use App\Jobs\PurgeRawAnalysisData;
use App\Models\AnalysisBatch;
use App\Models\OrganizationalUnit;
use App\Services\FortiGateLogParser;
use App\Services\RiskAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_page_is_available(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Pilih atau tarik file log');
    }

    public function test_dashboard_lists_the_eight_initial_units(): void
    {
        $this->get(route('analysis.dashboard'))
            ->assertOk()
            ->assertSee('UPT BATURAJA')
            ->assertSee('UP3 OGAN ILIR')
            ->assertSee('UP2D S2JB OT')
            ->assertSee('UP2D S2JB DMZ')
            ->assertSee('UP2D S2JB IT')
            ->assertSee('UIP SBS')
            ->assertSee('GEDUNG ARSIP - UIP SBS')
            ->assertSee('UPDL');

        $this->assertSame(8, OrganizationalUnit::query()->count());
    }

    public function test_new_unit_can_be_added_from_unit_manager(): void
    {
        $this->from(route('analysis.create'))
            ->post(route('units.store'), ['name' => 'unit layanan baru'])
            ->assertRedirect(route('analysis.create'));

        $this->assertDatabaseHas('organizational_units', [
            'name' => 'UNIT LAYANAN BARU',
            'slug' => 'unit-layanan-baru',
            'is_active' => true,
        ]);
    }

    public function test_empty_unit_can_be_deleted_from_unit_manager(): void
    {
        $unit = OrganizationalUnit::create([
            'uuid' => fake()->uuid(),
            'name' => 'UNIT KOSONG',
            'slug' => 'unit-kosong',
            'sort_order' => 9,
            'is_active' => true,
        ]);

        $this->from(route('analysis.dashboard'))
            ->delete(route('units.destroy', $unit))
            ->assertRedirect(route('analysis.dashboard'));

        $this->assertDatabaseMissing('organizational_units', ['id' => $unit->id]);
    }

    public function test_unit_with_analysis_cannot_be_deleted(): void
    {
        $unit = $this->unit('UPT BATURAJA');
        AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'organizational_unit_id' => $unit->id,
            'original_filename' => 'protected.log',
            'stored_path' => 'uploads/protected.log',
            'status' => 'completed',
            'completed_at' => now(),
            'summary_data' => $this->summaryData(),
        ]);

        $this->from(route('analysis.dashboard'))
            ->delete(route('units.destroy', $unit))
            ->assertRedirect(route('analysis.dashboard'))
            ->assertSessionHas('unit_manager_error');

        $this->assertDatabaseHas('organizational_units', ['id' => $unit->id]);
    }

    public function test_valid_log_upload_creates_batch_and_dispatches_parser(): void
    {
        Storage::fake();
        Queue::fake();

        $response = $this->post('/analysis', [
            'organizational_unit_id' => $this->unit()->id,
            'label' => 'Audit Juli',
            'log_file' => UploadedFile::fake()->createWithContent(
                'fortigate.log',
                'date=2026-07-23 time=10:00:00 srcip=10.0.0.2 dstip=8.8.8.8 action=accept'
            ),
        ]);

        $batch = AnalysisBatch::query()->firstOrFail();

        $response->assertRedirect(route('analysis.show', ['batch' => $batch, 'progress' => 1]));
        $this->assertSame('Audit Juli', $batch->label);
        $this->assertNull($batch->expires_at);
        Storage::assertExists($batch->stored_path);
        Queue::assertPushed(ParseFortiGateLog::class, fn ($job) => $job->batchId === $batch->id);
    }

    public function test_ajax_upload_returns_progress_page_url(): void
    {
        Storage::fake();
        Queue::fake();

        $response = $this->postJson('/analysis', [
            'organizational_unit_id' => $this->unit()->id,
            'label' => 'Upload AJAX',
            'log_file' => UploadedFile::fake()->createWithContent(
                'traffic.log',
                'date=2026-07-24 time=10:00:00 srcip=10.0.0.1'
            ),
        ]);

        $batch = AnalysisBatch::query()->firstOrFail();

        $response
            ->assertStatus(202)
            ->assertJsonPath('redirect_url', route('analysis.show', ['batch' => $batch, 'progress' => 1]));
    }

    public function test_active_duplicate_upload_reuses_existing_analysis(): void
    {
        Storage::fake();
        Queue::fake();
        $content = 'date=2026-07-24 time=10:00:00 srcip=10.0.0.1';
        $existing = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'organizational_unit_id' => $this->unit()->id,
            'original_filename' => 'traffic.log',
            'stored_path' => 'uploads/existing.log',
            'status' => 'processing',
            'file_size' => strlen($content),
            'file_fingerprint' => hash('sha256', $content),
        ]);

        $this->postJson('/analysis', [
            'organizational_unit_id' => $this->unit()->id,
            'label' => 'Duplikat',
            'log_file' => UploadedFile::fake()->createWithContent('traffic.log', $content),
        ])->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('redirect_url', route('analysis.show', $existing));

        $this->assertDatabaseCount('analysis_batches', 1);
        Queue::assertNothingPushed();
    }

    public function test_large_file_can_be_uploaded_in_small_chunks(): void
    {
        Storage::fake();
        Queue::fake();

        $session = $this->postJson(route('analysis.uploads.start'), [
            'file_name' => 'million-lines.log',
            'file_size' => 10,
            'label' => 'Data Besar',
            'organizational_unit_id' => $this->unit()->id,
        ])->assertCreated()->json();

        $this->post($session['chunk_url'], [
            'index' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('part-0', '12345'),
        ])->assertOk()->assertJsonPath('uploaded_bytes', 5);

        $this->post($session['chunk_url'], [
            'index' => 1,
            'chunk' => UploadedFile::fake()->createWithContent('part-1', '67890'),
        ])->assertOk()->assertJsonPath('uploaded_bytes', 10);

        $response = $this->postJson($session['complete_url'])->assertStatus(202);
        $batch = AnalysisBatch::query()->firstOrFail();

        $response->assertJsonPath('redirect_url', route('analysis.show', ['batch' => $batch, 'progress' => 1]));
        $this->assertSame('Data Besar', $batch->label);
        $this->assertSame(10, $batch->file_size);
        Storage::assertExists($batch->stored_path);
        Queue::assertPushed(ParseFortiGateLog::class, fn ($job) => $job->batchId === $batch->id);
    }

    public function test_upload_rejects_unsupported_file_type(): void
    {
        Storage::fake();
        Queue::fake();

        $this->from('/')
            ->post('/analysis', [
                'log_file' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
            ])
            ->assertRedirect('/')
            ->assertSessionHasErrors('log_file');

        $this->assertDatabaseCount('analysis_batches', 0);
        Queue::assertNothingPushed();
    }

    public function test_parser_persists_valid_log_and_tolerates_invalid_date(): void
    {
        Storage::fake();
        Queue::fake();
        $path = 'uploads/sample.log';
        Storage::put($path, implode(PHP_EOL, [
            'date=2026-07-23 time=10:00:00 srcip=10.0.0.2 dstip=8.8.8.8 app=DNS action=accept sentbyte=120 rcvdbyte=340',
            'date=not-a-date time=invalid srcip=10.0.0.3 dstip=1.1.1.1 action=deny',
        ]));

        $batch = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'original_filename' => 'sample.log',
            'stored_path' => $path,
            'status' => 'uploaded',
        ]);

        (new ParseFortiGateLog($batch->id))->handle(app(FortiGateLogParser::class));
        (new BuildAnalysisSummary($batch->id))->handle(app(RiskAssessmentService::class));

        $batch->refresh();
        $this->assertSame('completed', $batch->status);
        $this->assertSame(2, $batch->processed_lines);
        $this->assertDatabaseHas('traffic_logs', ['srcip' => '10.0.0.2', 'app' => 'DNS']);
        $this->assertDatabaseHas('traffic_logs', ['srcip' => '10.0.0.3', 'logged_at' => null]);

        $summary = DB::table('analysis_client_summaries')->where('srcip', '10.0.0.2')->first();
        $this->assertSame('DNS', json_decode($summary->top_apps, true, flags: JSON_THROW_ON_ERROR)[0]['app']);
    }

    public function test_parser_chunks_large_inserts_below_the_sqlite_parameter_limit(): void
    {
        Storage::fake();
        Queue::fake();
        $path = 'uploads/more-than-one-thousand.log';
        $line = 'date=2026-08-10 time=08:00:00 srcip=10.10.0.2 dstip=8.8.8.8 app=DNS action=accept sentbyte=120 rcvdbyte=340';
        Storage::put($path, implode(PHP_EOL, array_fill(0, 1001, $line)));

        $batch = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'original_filename' => 'more-than-one-thousand.log',
            'stored_path' => $path,
            'status' => 'uploaded',
            'file_size' => Storage::size($path),
        ]);

        (new ParseFortiGateLog($batch->id))->handle(app(FortiGateLogParser::class));

        $batch->refresh();
        $this->assertSame('summarizing', $batch->status);
        $this->assertSame(1001, $batch->processed_lines);
        $this->assertDatabaseCount('traffic_logs', 1001);
        Queue::assertPushed(BuildAnalysisSummary::class, fn (BuildAnalysisSummary $job): bool => $job->batchId === $batch->id);
    }

    public function test_failed_analysis_can_retry_the_saved_file_without_uploading_again(): void
    {
        Storage::fake();
        Queue::fake();
        Storage::put('uploads/retry.log', 'date=2026-08-10 time=08:00:00 srcip=10.0.0.2 dstip=8.8.8.8 action=accept');
        $batch = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'original_filename' => 'retry.log',
            'stored_path' => 'uploads/retry.log',
            'status' => 'failed',
            'failure_message' => 'SQLite parameter limit',
            'processed_bytes' => 400,
            'processed_lines' => 10,
            'invalid_lines' => 2,
            'summary_progress' => 12,
            'summary_stage' => 'Gagal',
            'summary_data' => ['partial' => true],
        ]);
        DB::table('traffic_logs')->insert([
            'analysis_batch_id' => $batch->id,
            'srcip' => '10.0.0.2',
            'raw_log' => 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('analysis_client_summaries')->insert([
            'analysis_batch_id' => $batch->id,
            'srcip' => '10.0.0.2',
            'risk_level' => 'low',
            'risk_label' => 'Rendah',
            'risk_reason' => 'Partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get(route('analysis.show', $batch))
            ->assertOk()
            ->assertSee('Proses ulang file ini');

        $this->post(route('analysis.retry', $batch))
            ->assertRedirect(route('analysis.show', ['batch' => $batch, 'progress' => 1]));

        $batch->refresh();
        $this->assertSame('uploaded', $batch->status);
        $this->assertNull($batch->failure_message);
        $this->assertNull($batch->summary_data);
        $this->assertSame(0, $batch->processed_bytes);
        $this->assertSame(0, $batch->processed_lines);
        $this->assertDatabaseMissing('traffic_logs', ['analysis_batch_id' => $batch->id]);
        $this->assertDatabaseMissing('analysis_client_summaries', ['analysis_batch_id' => $batch->id]);
        Queue::assertPushed(ParseFortiGateLog::class, fn (ParseFortiGateLog $job): bool => $job->batchId === $batch->id);
    }

    public function test_dashboard_displays_real_batch_totals(): void
    {
        $batch = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'label' => 'Data Nyata',
            'original_filename' => 'sample.log',
            'stored_path' => 'uploads/sample.log',
            'status' => 'completed',
            'summary_data' => $this->summaryData(),
        ]);

        DB::table('traffic_logs')->insert([
            'analysis_batch_id' => $batch->id,
            'srcip' => '10.0.0.2',
            'dstip' => '8.8.8.8',
            'app' => 'DNS',
            'action' => 'deny',
            'apprisk' => 'high',
            'sentbyte' => 100,
            'rcvdbyte' => 200,
            'raw_log' => 'sample',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('analysis_client_summaries')->insert([
            'analysis_batch_id' => $batch->id,
            'identity_key' => 'ip:10.10.10.5',
            'identity_type' => 'ip',
            'identity_value' => '10.10.10.5',
            'srcip' => '10.10.10.5',
            'total_access' => 1,
            'total_upload' => 10,
            'total_download' => 20,
            'unique_apps' => 1,
            'unique_destinations' => 1,
            'risk_score' => 0,
            'risk_level' => 'low',
            'risk_label' => 'Rendah',
            'risk_reason' => 'Tidak ada indikator kuat',
            'habit_score' => 0,
            'habit_level' => 'low',
            'habit_label' => 'Normal',
            'habit_reason' => 'Pola normal',
            'combined_status' => 'normal',
            'combined_label' => 'Sehat',
            'top_apps' => json_encode([['app' => 'Google.DNS', 'total_access' => 1]]),
            'top_countries' => json_encode([['dstcountry' => 'United States', 'total_access' => 1]]),
            'top_policies' => '[]',
            'profile_data' => json_encode([
                'top_destinations' => [[
                    'dstip' => '8.8.4.4',
                    'dstcountry' => 'United States',
                    'service' => 'DNS',
                    'total_access' => 1,
                ]],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get(route('analysis.show', $batch))
            ->assertOk()
            ->assertSee('Data Nyata')
            ->assertSee('DNS')
            ->assertSee('RENDAH');
    }

    public function test_batch_routes_use_uuid_instead_of_sequential_id(): void
    {
        $batch = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'original_filename' => 'private.log',
            'stored_path' => 'uploads/private.log',
            'status' => 'completed',
        ]);

        $this->assertStringContainsString($batch->uuid, route('analysis.show', $batch));
        $this->get("/analysis/{$batch->id}")->assertNotFound();
    }

    public function test_client_detail_displays_activity_from_the_selected_batch(): void
    {
        $batch = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'original_filename' => 'client.log',
            'stored_path' => 'uploads/client.log',
            'status' => 'completed',
        ]);

        DB::table('traffic_logs')->insert([
            'analysis_batch_id' => $batch->id,
            'logged_at' => now(),
            'srcip' => '10.10.10.5',
            'dstip' => '8.8.4.4',
            'dstcountry' => 'United States',
            'app' => 'Google.DNS',
            'service' => 'DNS',
            'action' => 'accept',
            'sentbyte' => 10,
            'rcvdbyte' => 20,
            'raw_log' => 'sample',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('analysis_client_summaries')->insert([
            'analysis_batch_id' => $batch->id,
            'identity_key' => 'ip:10.10.10.5',
            'identity_type' => 'ip',
            'identity_value' => '10.10.10.5',
            'srcip' => '10.10.10.5',
            'total_access' => 1,
            'total_upload' => 10,
            'total_download' => 20,
            'unique_apps' => 1,
            'unique_destinations' => 1,
            'risk_score' => 0,
            'risk_level' => 'low',
            'risk_label' => 'Rendah',
            'risk_reason' => 'Tidak ada indikator kuat',
            'habit_score' => 0,
            'habit_level' => 'low',
            'habit_label' => 'Normal',
            'habit_reason' => 'Pola normal',
            'combined_status' => 'normal',
            'combined_label' => 'Sehat',
            'top_apps' => json_encode([['app' => 'Google.DNS', 'total_access' => 1]]),
            'top_countries' => json_encode([['dstcountry' => 'United States', 'total_access' => 1]]),
            'top_policies' => '[]',
            'profile_data' => json_encode([
                'top_destinations' => [[
                    'dstip' => '8.8.4.4',
                    'dstcountry' => 'United States',
                    'service' => 'DNS',
                    'total_access' => 1,
                ]],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get(route('analysis.client.show', [$batch, '10.10.10.5']))
            ->assertOk()
            ->assertSee('10.10.10.5')
            ->assertSee('Google.DNS')
            ->assertSee('United States')
            ->assertSee('Rincian skor risiko')
            ->assertSee('Cari aktivitas user')
            ->assertSee('data-profile-tab', false)
            ->assertSee('data-log-filter', false);
    }

    public function test_reports_cannot_be_downloaded_before_analysis_completes(): void
    {
        $batch = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'original_filename' => 'processing.log',
            'stored_path' => 'uploads/processing.log',
            'status' => 'processing',
        ]);

        $this->get(route('analysis.download', $batch))->assertStatus(409);
        $this->get(route('analysis.habit-report', $batch))->assertStatus(409);
    }

    public function test_habit_report_uses_precomputed_client_summary(): void
    {
        $batch = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'label' => 'Unit Cepat',
            'original_filename' => 'large.log',
            'stored_path' => 'uploads/large.log',
            'status' => 'completed',
            'summary_data' => [
                'summary' => ['total_clients' => 1],
            ],
        ]);

        DB::table('analysis_client_summaries')->insert([
            'analysis_batch_id' => $batch->id,
            'srcip' => '10.0.0.8',
            'client_name' => 'Client Cepat',
            'source_interface' => 'lan',
            'total_access' => 250,
            'total_upload' => 1000,
            'total_download' => 2000,
            'risk_score' => 35.5,
            'risk_level' => 'medium',
            'risk_label' => 'Sedang',
            'risk_reason' => 'Koneksi ditolak berulang',
            'top_apps' => json_encode([['app' => 'HTTPS', 'total_access' => 200]]),
            'top_countries' => json_encode([['dstcountry' => 'Indonesia', 'total_access' => 180]]),
            'top_policies' => json_encode([['policyname' => 'Internet Access', 'total_access' => 250]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get(route('analysis.habit-report', $batch));

        $response->assertOk()->assertDownload();
        $content = $response->streamedContent();
        $this->assertStringStartsWith('PK', $content);
        $this->assertStringContainsString('xl/worksheets/sheet1.xml', $content);
        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));
    }

    public function test_status_endpoint_returns_polling_data(): void
    {
        $batch = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'original_filename' => 'processing.log',
            'stored_path' => 'uploads/processing.log',
            'status' => 'processing',
            'file_size' => 1000,
            'processed_bytes' => 500,
            'total_lines' => 100,
            'processed_lines' => 40,
            'invalid_lines' => 10,
        ]);

        $this->getJson(route('analysis.status', $batch))
            ->assertOk()
            ->assertJson([
                'status' => 'processing',
                'processed_lines' => 40,
                'progress' => 50,
                'result_url' => route('analysis.show', $batch),
            ]);
    }

    public function test_status_endpoint_reports_real_summary_stage(): void
    {
        $batch = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'original_filename' => 'processing.log',
            'stored_path' => 'uploads/processing.log',
            'status' => 'summarizing',
            'file_size' => 1000,
            'processed_bytes' => 1000,
            'summary_progress' => 64,
            'summary_stage' => 'Meringkas 50.000 dari 100.000 event',
        ]);

        $this->getJson(route('analysis.status', $batch))
            ->assertOk()
            ->assertJson([
                'status' => 'summarizing',
                'progress' => 64,
                'summary_stage' => 'Meringkas 50.000 dari 100.000 event',
            ]);
    }

    public function test_running_analysis_uses_lightweight_progress_page(): void
    {
        $batch = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'original_filename' => 'million-lines.log',
            'stored_path' => 'uploads/million-lines.log',
            'status' => 'processing',
            'file_size' => 1000,
            'processed_bytes' => 250,
            'processed_lines' => 500000,
        ]);

        $this->get(route('analysis.show', $batch))
            ->assertOk()
            ->assertSee('Proses Analisis')
            ->assertSee('500,000')
            ->assertSee('25%');
    }

    public function test_completed_progress_page_redirects_to_its_unit_dashboard(): void
    {
        $unit = $this->unit();
        $batch = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'organizational_unit_id' => $unit->id,
            'original_filename' => 'done.log',
            'stored_path' => 'uploads/done.log',
            'status' => 'completed',
            'completed_at' => now(),
            'summary_data' => $this->summaryData(),
        ]);

        $this->get(route('analysis.show', ['batch' => $batch, 'progress' => 1]))
            ->assertRedirect(route('units.show', $unit));
    }

    public function test_comparison_automatically_uses_latest_completed_analysis(): void
    {
        $unit = $this->unit();
        $older = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'organizational_unit_id' => $unit->id,
            'original_filename' => 'lama.log',
            'stored_path' => 'uploads/lama.log',
            'status' => 'completed',
            'completed_at' => now()->subHour(),
            'summary_data' => $this->summaryData(),
        ]);
        $latestSummary = $this->summaryData();
        $latestSummary['summary']['total_logs'] = 345;
        $latest = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'organizational_unit_id' => $unit->id,
            'original_filename' => 'baru.log',
            'stored_path' => 'uploads/baru.log',
            'status' => 'completed',
            'completed_at' => now(),
            'summary_data' => $latestSummary,
        ]);
        DB::table('analysis_client_summaries')->insert([
            'analysis_batch_id' => $latest->id,
            'identity_key' => 'user:client-a',
            'identity_type' => 'user',
            'identity_value' => 'Client A',
            'srcip' => '10.0.0.5',
            'total_access' => 100,
            'risk_score' => 62.5,
            'risk_level' => 'high',
            'risk_label' => 'Tinggi',
            'risk_reason' => 'Aktivitas kritis berulang',
            'habit_score' => 48,
            'habit_level' => 'medium',
            'habit_label' => 'Perlu dicek',
            'habit_reason' => 'Aktivitas di luar jam kerja',
            'combined_status' => 'priority',
            'combined_label' => 'Prioritas',
            'top_apps' => '[]',
            'top_countries' => '[]',
            'top_policies' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get(route('comparisons.index'))
            ->assertOk()
            ->assertSee($unit->name)
            ->assertSee('345')
            ->assertSee('data-traffic-chart', false);

        $this->get(route('comparisons.clients', $unit))
            ->assertOk()
            ->assertSee('Client A')
            ->assertSee('Aktivitas kritis berulang');

        $latest->delete();
        $this->get(route('comparisons.index'))
            ->assertOk()
            ->assertSee('1');

        $this->assertDatabaseHas('analysis_batches', ['id' => $older->id]);
    }

    public function test_deleting_batch_removes_record_and_stored_file(): void
    {
        Storage::fake();
        Storage::put('uploads/delete-me.log', 'sample');

        $batch = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'original_filename' => 'delete-me.log',
            'stored_path' => 'uploads/delete-me.log',
            'status' => 'completed',
        ]);

        $this->delete(route('analysis.destroy', $batch))
            ->assertRedirect(route('analysis.create'));

        $this->assertDatabaseMissing('analysis_batches', ['id' => $batch->id]);
        Storage::assertMissing('uploads/delete-me.log');
    }

    public function test_completed_result_can_be_moved_to_another_unit_without_reanalysis(): void
    {
        Queue::fake();
        $from = $this->unit();
        $to = OrganizationalUnit::query()->whereKeyNot($from->id)->orderBy('sort_order')->firstOrFail();
        $batch = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'organizational_unit_id' => $from->id,
            'label' => 'Judul lama',
            'original_filename' => 'same.log',
            'stored_path' => 'uploads/same.log',
            'status' => 'completed',
            'completed_at' => now(),
            'summary_data' => $this->summaryData(),
        ]);

        $this->patch(route('analysis.update', $batch), [
            'label' => 'Judul baru',
            'organizational_unit_id' => $to->id,
        ])->assertRedirect(route('units.show', $to));

        $batch->refresh();
        $this->assertSame('Judul baru', $batch->label);
        $this->assertSame($to->id, $batch->organizational_unit_id);
        Queue::assertNothingPushed();
    }

    public function test_moving_result_from_unit_dialog_preserves_its_existing_label(): void
    {
        $from = $this->unit('UPT BATURAJA');
        $to = $this->unit('UPDL');
        $batch = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'organizational_unit_id' => $from->id,
            'label' => 'Nama hasil tetap',
            'original_filename' => 'same.log',
            'stored_path' => 'uploads/same.log',
            'status' => 'completed',
            'completed_at' => now(),
            'summary_data' => $this->summaryData(),
        ]);

        $this->patch(route('analysis.update', $batch), [
            'organizational_unit_id' => $to->id,
        ])->assertRedirect(route('units.show', $to));

        $batch->refresh();
        $this->assertSame('Nama hasil tetap', $batch->label);
        $this->assertSame($to->id, $batch->organizational_unit_id);
    }

    public function test_raw_data_is_purged_only_after_summary_is_published(): void
    {
        Storage::fake();
        Storage::put('uploads/large.log', 'raw');
        $batch = AnalysisBatch::create([
            'uuid' => fake()->uuid(),
            'organizational_unit_id' => $this->unit()->id,
            'original_filename' => 'large.log',
            'stored_path' => 'uploads/large.log',
            'status' => 'completed',
            'completed_at' => now(),
            'published_at' => now(),
            'summary_data' => $this->summaryData(),
        ]);
        DB::table('traffic_logs')->insert([
            'analysis_batch_id' => $batch->id,
            'srcip' => '10.0.0.9',
            'raw_log' => 'sample',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new PurgeRawAnalysisData($batch->id))->handle();

        $batch->refresh();
        $this->assertNotNull($batch->raw_purged_at);
        $this->assertDatabaseMissing('traffic_logs', ['analysis_batch_id' => $batch->id]);
        Storage::assertMissing('uploads/large.log');
        $this->assertNotEmpty($batch->summary_data);
    }

    private function summaryData(): array
    {
        return [
            'summary' => [
                'total_logs' => 1,
                'total_clients' => 1,
                'total_upload' => 100,
                'total_download' => 200,
                'total_denied' => 1,
                'total_risk_events' => 1,
            ],
            'top_apps' => [['app' => 'DNS', 'total' => 1]],
            'top_countries' => [],
            'activity_trend' => [],
            'action_distribution' => [['action' => 'deny', 'total' => 1]],
            'unit_risk' => ['score' => 5.0, 'level' => 'low', 'label' => 'Rendah', 'risky_clients' => 0],
            'risk_distribution' => ['low' => 1, 'medium' => 0, 'high' => 0],
        ];
    }

    private function unit(): OrganizationalUnit
    {
        return OrganizationalUnit::query()->orderBy('sort_order')->firstOrFail();
    }
}
