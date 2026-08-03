<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comparison_units', function (Blueprint $table) {
            $table->json('client_profiles')->nullable()->after('top_risky_clients');
        });
    }

    public function down(): void
    {
        Schema::table('comparison_units', function (Blueprint $table) {
            $table->dropColumn('client_profiles');
        });
    }
};
