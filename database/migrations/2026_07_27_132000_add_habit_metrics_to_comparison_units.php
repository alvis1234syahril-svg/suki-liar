<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comparison_units', function (Blueprint $table) {
            $table->unsignedInteger('unique_apps')->default(0)->after('total_access');
            $table->unsignedBigInteger('total_duration')->default(0)->after('total_download');
            $table->unsignedBigInteger('work_hours_count')->default(0)->after('total_duration');
            $table->unsignedBigInteger('off_hours_count')->default(0)->after('work_hours_count');
            $table->unsignedTinyInteger('peak_hour')->nullable()->after('off_hours_count');
            $table->string('top_app')->nullable()->after('peak_hour');
            $table->string('top_action')->nullable()->after('top_app');
            $table->string('top_risk')->nullable()->after('top_action');
            $table->timestamp('first_seen')->nullable()->after('top_risk');
            $table->timestamp('last_seen')->nullable()->after('first_seen');
            $table->unsignedInteger('anomalous_clients')->default(0)->after('risky_clients');
            $table->decimal('habit_score', 5, 1)->default(0)->after('risk_level');
            $table->json('habit_distribution')->nullable()->after('risk_distribution');
            $table->json('outliers')->nullable()->after('client_profiles');
        });
    }

    public function down(): void
    {
        Schema::table('comparison_units', function (Blueprint $table) {
            $table->dropColumn([
                'unique_apps', 'total_duration', 'work_hours_count', 'off_hours_count', 'peak_hour',
                'top_app', 'top_action', 'top_risk', 'first_seen', 'last_seen',
                'anomalous_clients', 'habit_score', 'habit_distribution', 'outliers',
            ]);
        });
    }
};
