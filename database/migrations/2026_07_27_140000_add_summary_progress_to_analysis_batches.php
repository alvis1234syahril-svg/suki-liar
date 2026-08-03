<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analysis_batches', function (Blueprint $table) {
            $table->unsignedTinyInteger('summary_progress')->default(0)->after('processed_bytes');
            $table->string('summary_stage')->nullable()->after('summary_progress');
        });
    }

    public function down(): void
    {
        Schema::table('analysis_batches', function (Blueprint $table) {
            $table->dropColumn(['summary_progress', 'summary_stage']);
        });
    }
};
