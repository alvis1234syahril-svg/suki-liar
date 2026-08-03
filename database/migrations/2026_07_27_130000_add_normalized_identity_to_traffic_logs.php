<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('traffic_logs', function (Blueprint $table) {
            $table->string('user')->nullable()->after('srcname');
            $table->string('srcserver')->nullable()->after('user');
            $table->string('identity_key', 64)->nullable()->after('srcserver');
            $table->string('identity_type', 20)->nullable()->after('identity_key');
            $table->string('identity_value')->nullable()->after('identity_type');
            $table->unsignedBigInteger('total_bytes')->default(0)->after('rcvdbyte');
            $table->index(['analysis_batch_id', 'identity_key']);
            $table->index(['analysis_batch_id', 'total_bytes']);
            $table->index(['analysis_batch_id', 'duration']);
        });

        DB::table('traffic_logs')->update([
            'total_bytes' => DB::raw('sentbyte + rcvdbyte'),
        ]);
    }

    public function down(): void
    {
        Schema::table('traffic_logs', function (Blueprint $table) {
            $table->dropIndex(['analysis_batch_id', 'identity_key']);
            $table->dropIndex(['analysis_batch_id', 'total_bytes']);
            $table->dropIndex(['analysis_batch_id', 'duration']);
            $table->dropColumn(['user', 'srcserver', 'identity_key', 'identity_type', 'identity_value', 'total_bytes']);
        });
    }
};
