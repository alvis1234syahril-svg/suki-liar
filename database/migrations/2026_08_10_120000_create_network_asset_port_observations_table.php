<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_asset_port_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_asset_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('port_key', 120);
            $table->string('port_name', 160);
            $table->unsignedInteger('if_index')->nullable();
            $table->string('admin_status', 40)->nullable();
            $table->string('operational_status', 40)->nullable();
            $table->string('health_status', 40)->default('unknown');
            $table->unsignedBigInteger('speed_mbps')->nullable();
            $table->string('description')->nullable();
            $table->string('source', 40)->default('snmp');
            $table->text('failure_reason')->nullable();
            $table->timestamp('last_change_at')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['network_asset_id', 'port_key'],
                'asset_port_observation_unique'
            );
            $table->index(
                ['network_asset_id', 'health_status'],
                'asset_port_health_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_asset_port_observations');
    }
};
