<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('analysis_batches', function (Blueprint $table) {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->string('label')->nullable();
        $table->string('original_filename');
        $table->string('stored_path');
        $table->string('status')->default('uploaded');
        $table->unsignedBigInteger('total_lines')->default(0);
        $table->unsignedBigInteger('processed_lines')->default(0);
        $table->unsignedBigInteger('invalid_lines')->default(0);
        $table->timestamp('started_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();

        $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analysis_batches');
    }
};
