<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analysis_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('file_size')->default(0)->after('status');
            $table->unsignedBigInteger('processed_bytes')->default(0)->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('analysis_batches', function (Blueprint $table) {
            $table->dropColumn(['file_size', 'processed_bytes']);
        });
    }
};
