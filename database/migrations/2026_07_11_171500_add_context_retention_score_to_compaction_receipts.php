<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_long_horizon_compaction_receipts')) {
            return;
        }

        if (! Schema::hasColumn('atlas_long_horizon_compaction_receipts', 'context_retention_score')) {
            Schema::table('atlas_long_horizon_compaction_receipts', function (Blueprint $table): void {
                $table->decimal('context_retention_score', 5, 4)->nullable()->after('summary_hash');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('atlas_long_horizon_compaction_receipts')) {
            return;
        }

        if (Schema::hasColumn('atlas_long_horizon_compaction_receipts', 'context_retention_score')) {
            Schema::table('atlas_long_horizon_compaction_receipts', function (Blueprint $table): void {
                $table->dropColumn('context_retention_score');
            });
        }
    }
};
