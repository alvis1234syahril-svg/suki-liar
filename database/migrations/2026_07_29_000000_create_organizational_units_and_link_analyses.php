<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizational_units', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $names = [
            'UPT BATURAJA',
            'UP3 OGAN ILIR',
            'UP2D S2JB OT',
            'UP2D S2JB DMZ',
            'UP2D S2JB IT',
            'UIP SBS',
            'GEDUNG ARSIP - UIP SBS',
            'UPDL',
        ];

        DB::table('organizational_units')->insert(
            collect($names)->map(fn (string $name, int $index) => [
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'slug' => Str::slug($name),
                'sort_order' => $index + 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );

        Schema::table('analysis_batches', function (Blueprint $table) {
            $table->foreignId('organizational_unit_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();
            $table->string('file_fingerprint', 64)->nullable()->after('file_size');
            $table->timestamp('published_at')->nullable()->after('completed_at');
            $table->timestamp('raw_purged_at')->nullable()->after('published_at');
            $table->index(
                ['organizational_unit_id', 'status', 'completed_at'],
                'analysis_unit_status_completed_idx'
            );
            $table->index('file_fingerprint', 'analysis_file_fingerprint_idx');
        });

        Schema::table('analysis_client_summaries', function (Blueprint $table) {
            $table->json('profile_data')->nullable()->after('top_policies');
        });
    }

    public function down(): void
    {
        Schema::table('analysis_client_summaries', function (Blueprint $table) {
            $table->dropColumn('profile_data');
        });

        Schema::table('analysis_batches', function (Blueprint $table) {
            $table->dropForeign(['organizational_unit_id']);
            $table->dropIndex('analysis_unit_status_completed_idx');
            $table->dropIndex('analysis_file_fingerprint_idx');
            $table->dropColumn([
                'organizational_unit_id',
                'file_fingerprint',
                'published_at',
                'raw_purged_at',
            ]);
        });

        Schema::dropIfExists('organizational_units');
    }
};
