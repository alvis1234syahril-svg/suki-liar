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
        Schema::create('traffic_logs', function (Blueprint $table) {
        $table->id();
        $table->foreignId('analysis_batch_id')
            ->constrained()
            ->cascadeOnDelete();

        $table->timestamp('logged_at')->nullable();
        $table->unsignedBigInteger('eventtime')->nullable();

        $table->string('srcip', 45)->nullable();
        $table->unsignedInteger('srcport')->nullable();
        $table->string('srcname')->nullable();

        $table->string('dstip', 45)->nullable();
        $table->unsignedInteger('dstport')->nullable();
        $table->string('srccountry')->nullable();
        $table->string('dstcountry')->nullable();

        $table->string('srcintf')->nullable();
        $table->string('dstintf')->nullable();
        $table->unsignedTinyInteger('proto')->nullable();

        $table->string('action')->nullable();
        $table->unsignedInteger('policyid')->nullable();
        $table->string('policyname')->nullable();
        $table->string('service')->nullable();

        $table->string('app')->nullable();
        $table->string('appcat')->nullable();
        $table->string('apprisk')->nullable();

        $table->unsignedBigInteger('duration')->nullable();
        $table->unsignedBigInteger('sentbyte')->default(0);
        $table->unsignedBigInteger('rcvdbyte')->default(0);
        $table->unsignedBigInteger('sentpkt')->default(0);
        $table->unsignedBigInteger('rcvdpkt')->default(0);

        $table->mediumText('raw_log');
        $table->json('extra_fields')->nullable();
        $table->timestamps();

        $table->index(['analysis_batch_id', 'logged_at']);
        $table->index(['analysis_batch_id', 'srcip']);
        $table->index(['analysis_batch_id', 'dstip']);
        $table->index(['analysis_batch_id', 'app']);
        $table->index(['analysis_batch_id', 'action']);
        $table->index(['analysis_batch_id', 'apprisk']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('traffic_logs');
    }
};
