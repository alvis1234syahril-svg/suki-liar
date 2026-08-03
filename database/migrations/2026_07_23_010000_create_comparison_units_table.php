<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comparison_units', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('unit_name');
            $table->string('source_filename');
            $table->unsignedBigInteger('total_clients')->default(0);
            $table->unsignedBigInteger('total_access')->default(0);
            $table->unsignedBigInteger('total_upload')->default(0);
            $table->unsignedBigInteger('total_download')->default(0);
            $table->unsignedBigInteger('total_denied')->default(0);
            $table->unsignedBigInteger('risky_clients')->default(0);
            $table->decimal('risk_score', 5, 1)->default(0);
            $table->string('risk_level', 20)->default('low');
            $table->json('risk_distribution')->nullable();
            $table->json('top_risky_clients')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comparison_units');
    }
};
