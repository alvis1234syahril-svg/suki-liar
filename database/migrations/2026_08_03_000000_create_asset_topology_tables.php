<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizational_unit_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('asset_uid')->unique();
            $table->string('asset_type', 80);
            $table->string('source_system', 40)->default('legacy-v1');
            $table->string('source_kind', 80)->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_sheet')->nullable();
            $table->unsignedInteger('inventory_number')->nullable();
            $table->string('location_detail')->nullable();
            $table->string('site')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->date('active_date')->nullable();
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('age_category', 80)->nullable();
            $table->string('condition', 80)->nullable();
            $table->string('operational_status', 80)->nullable();
            $table->string('criticality', 80)->nullable();
            $table->text('function_text')->nullable();
            $table->string('firmware')->nullable();
            $table->text('notes')->nullable();
            $table->string('analysis_status')->nullable();
            $table->string('parent_asset_uid')->nullable();
            $table->string('ssid')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('relation_status', 80)->nullable();
            $table->string('lifecycle_status', 80)->default('active');
            $table->string('replaces_asset_uid')->nullable();
            $table->string('replaced_by_asset_uid')->nullable();
            $table->date('inactive_date')->nullable();
            $table->boolean('is_custom')->default(false);
            $table->json('attributes')->nullable();
            $table->timestamps();

            $table->index(['organizational_unit_id', 'asset_type']);
            $table->index(['organizational_unit_id', 'lifecycle_status']);
            $table->index('parent_asset_uid');
        });

        Schema::create('topology_datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizational_unit_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('legacy_location_id');
            $table->string('source_system', 40)->default('legacy-v1');
            $table->string('name');
            $table->string('source_type', 80)->nullable();
            $table->string('source_file')->nullable();
            $table->string('source_site_label')->nullable();
            $table->string('inventory_source', 80)->nullable();
            $table->string('relationship_source', 80)->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->char('source_checksum', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['organizational_unit_id', 'legacy_location_id'],
                'topology_dataset_unit_location_unique'
            );
        });

        Schema::create('topology_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topology_dataset_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('legacy_node_id');
            $table->string('kind', 80)->nullable();
            $table->string('name');
            $table->string('role')->nullable();
            $table->string('status', 80)->nullable();
            $table->string('source_asset_uid')->nullable();
            $table->json('interfaces')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['topology_dataset_id', 'legacy_node_id'],
                'topology_node_dataset_legacy_unique'
            );
        });

        Schema::create('topology_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topology_dataset_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('from_node_id')
                ->constrained('topology_nodes')
                ->cascadeOnDelete();
            $table->foreignId('to_node_id')
                ->constrained('topology_nodes')
                ->cascadeOnDelete();
            $table->string('legacy_edge_id');
            $table->string('label')->nullable();
            $table->string('from_port')->nullable();
            $table->string('to_port')->nullable();
            $table->string('from_interface')->nullable();
            $table->string('status', 80)->nullable();
            $table->string('relation_type', 80)->nullable();
            $table->boolean('is_inferred')->default(false);
            $table->boolean('is_unconnected')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['topology_dataset_id', 'legacy_edge_id'],
                'topology_edge_dataset_legacy_unique'
            );
        });

        Schema::create('topology_asset_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topology_node_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('network_asset_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('assigned_asset_uid')->nullable();
            $table->string('assignment_mode', 40)->default('source');
            $table->boolean('is_explicit')->default(false);
            $table->boolean('is_unconnected')->default(false);
            $table->boolean('is_inferred')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('asset_events', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_event_id')->unique();
            $table->string('source_system', 40)->default('legacy-v1');
            $table->string('event_type', 80);
            $table->foreignId('network_asset_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('related_network_asset_id')
                ->nullable()
                ->constrained('network_assets')
                ->nullOnDelete();
            $table->foreignId('organizational_unit_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('from_organizational_unit_id')
                ->nullable()
                ->constrained('organizational_units')
                ->nullOnDelete();
            $table->foreignId('to_organizational_unit_id')
                ->nullable()
                ->constrained('organizational_units')
                ->nullOnDelete();
            $table->string('asset_uid')->nullable();
            $table->string('related_asset_uid')->nullable();
            $table->date('effective_date')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->string('actor')->nullable();
            $table->text('reason')->nullable();
            $table->text('summary')->nullable();
            $table->json('details')->nullable();
            $table->json('source_payload');
            $table->timestamps();

            $table->index(['event_type', 'occurred_at']);
        });

        Schema::create('asset_topology_workspaces', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->unsignedBigInteger('revision')->default(0);
            $table->string('active_location_id')->nullable();
            $table->json('state');
            $table->char('state_checksum', 64);
            $table->char('source_checksum', 64);
            $table->string('source_name')->nullable();
            $table->timestamp('imported_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_topology_workspaces');
        Schema::dropIfExists('asset_events');
        Schema::dropIfExists('topology_asset_mappings');
        Schema::dropIfExists('topology_edges');
        Schema::dropIfExists('topology_nodes');
        Schema::dropIfExists('topology_datasets');
        Schema::dropIfExists('network_assets');
    }
};
