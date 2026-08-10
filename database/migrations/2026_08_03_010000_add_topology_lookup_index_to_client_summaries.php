<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'client_summary_batch_srcip_idx';

    public function up(): void
    {
        if (! Schema::hasIndex('analysis_client_summaries', ['analysis_batch_id', 'srcip'])) {
            Schema::table('analysis_client_summaries', function (Blueprint $table): void {
                $table->index(['analysis_batch_id', 'srcip'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        $index = collect(Schema::getIndexes('analysis_client_summaries'))
            ->first(fn (array $index): bool => $index['name'] === self::INDEX);

        if ($index) {
            Schema::table('analysis_client_summaries', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};
