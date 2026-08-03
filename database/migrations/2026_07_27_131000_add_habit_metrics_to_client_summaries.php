<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('analysis_client_summaries', 'habit_score')) {
            Schema::table('analysis_client_summaries', function (Blueprint $table) {
                $table->dropUnique(['analysis_batch_id', 'srcip']);
                $table->string('identity_key', 64)->nullable()->after('analysis_batch_id');
                $table->string('identity_type', 20)->nullable()->after('identity_key');
                $table->string('identity_value')->nullable()->after('identity_type');
                $table->json('associated_ips')->nullable()->after('source_interface');
                $table->unsignedBigInteger('total_bytes')->default(0)->after('total_download');
                $table->unsignedBigInteger('total_duration')->default(0)->after('total_bytes');
                $table->decimal('average_duration', 14, 2)->default(0)->after('total_duration');
                $table->unsignedBigInteger('work_hours_count')->default(0)->after('average_duration');
                $table->unsignedBigInteger('off_hours_count')->default(0)->after('work_hours_count');
                $table->unsignedTinyInteger('active_hour_count')->default(0)->after('off_hours_count');
                $table->unsignedTinyInteger('dominant_hour')->nullable()->after('active_hour_count');
                $table->unsignedBigInteger('timeout_count')->default(0)->after('denied_count');
                $table->unsignedBigInteger('reset_count')->default(0)->after('timeout_count');
                $table->unsignedBigInteger('nonstandard_port_count')->default(0)->after('reset_count');
                $table->unsignedInteger('unique_apps')->default(0)->after('nonstandard_port_count');
                $table->unsignedInteger('unique_destinations')->default(0)->after('unique_apps');
                $table->decimal('habit_score', 5, 1)->default(0)->after('risk_reason');
                $table->string('habit_level', 10)->default('low')->after('habit_score');
                $table->string('habit_label', 20)->default('Rendah')->after('habit_level');
                $table->text('habit_reason')->nullable()->after('habit_label');
                $table->json('habit_components')->nullable()->after('habit_reason');
                $table->string('combined_status', 20)->default('normal')->after('habit_components');
                $table->string('combined_label', 40)->default('Normal')->after('combined_status');
            });
        }

        foreach ([
            ['columns' => ['analysis_batch_id', 'identity_key'], 'name' => 'client_summary_identity_idx'],
            ['columns' => ['analysis_batch_id', 'habit_level'], 'name' => 'client_summary_habit_idx'],
            ['columns' => ['analysis_batch_id', 'combined_status'], 'name' => 'client_summary_combined_idx'],
        ] as $index) {
            if (! Schema::hasIndex('analysis_client_summaries', $index['columns'])) {
                Schema::table('analysis_client_summaries', fn (Blueprint $table) => $table->index($index['columns'], $index['name']));
            }
        }
    }

    public function down(): void
    {
        foreach ([
            ['analysis_batch_id', 'identity_key'],
            ['analysis_batch_id', 'habit_level'],
            ['analysis_batch_id', 'combined_status'],
        ] as $columns) {
            $index = collect(Schema::getIndexes('analysis_client_summaries'))
                ->first(fn (array $index) => $index['columns'] === $columns);
            if ($index) {
                Schema::table('analysis_client_summaries', fn (Blueprint $table) => $table->dropIndex($index['name']));
            }
        }

        Schema::table('analysis_client_summaries', function (Blueprint $table) {
            $table->dropColumn([
                'identity_key', 'identity_type', 'identity_value', 'associated_ips', 'total_bytes',
                'total_duration', 'average_duration', 'work_hours_count', 'off_hours_count',
                'active_hour_count', 'dominant_hour', 'timeout_count', 'reset_count',
                'nonstandard_port_count', 'unique_apps', 'unique_destinations', 'habit_score',
                'habit_level', 'habit_label', 'habit_reason', 'habit_components',
                'combined_status', 'combined_label',
            ]);
            $table->unique(['analysis_batch_id', 'srcip']);
        });
    }
};
