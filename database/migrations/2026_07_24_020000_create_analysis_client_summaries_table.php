<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_client_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_batch_id')->constrained()->cascadeOnDelete();
            $table->string('srcip', 45);
            $table->string('client_name')->nullable();
            $table->string('source_interface')->nullable();
            $table->unsignedBigInteger('total_access')->default(0);
            $table->unsignedBigInteger('total_upload')->default(0);
            $table->unsignedBigInteger('total_download')->default(0);
            $table->timestamp('first_access_at')->nullable();
            $table->timestamp('last_access_at')->nullable();
            $table->unsignedBigInteger('denied_count')->default(0);
            $table->unsignedBigInteger('critical_count')->default(0);
            $table->unsignedBigInteger('high_count')->default(0);
            $table->unsignedBigInteger('elevated_count')->default(0);
            $table->unsignedBigInteger('medium_count')->default(0);
            $table->decimal('risk_score', 5, 1)->default(0);
            $table->string('risk_level', 10);
            $table->string('risk_label', 20);
            $table->text('risk_reason');
            $table->unsignedBigInteger('risky_count')->default(0);
            $table->timestamps();

            $table->unique(['analysis_batch_id', 'srcip']);
            $table->index(['analysis_batch_id', 'total_access']);
            $table->index(['analysis_batch_id', 'risk_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_client_summaries');
    }
};
