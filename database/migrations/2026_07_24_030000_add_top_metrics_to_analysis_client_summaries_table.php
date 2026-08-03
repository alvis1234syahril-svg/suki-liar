<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analysis_client_summaries', function (Blueprint $table) {
            $table->json('top_apps')->nullable()->after('risky_count');
            $table->json('top_countries')->nullable()->after('top_apps');
            $table->json('top_policies')->nullable()->after('top_countries');
        });
    }

    public function down(): void
    {
        Schema::table('analysis_client_summaries', function (Blueprint $table) {
            $table->dropColumn(['top_apps', 'top_countries', 'top_policies']);
        });
    }
};
