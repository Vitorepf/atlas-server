<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bridge page (or any standalone artifact derived directly from a VSL asset) is a legitimate
 * artifact that does not belong to a campaign run — it is traced by metadata.asset_id, not by a run.
 * Make marketing_run_id nullable so those artifacts persist. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ai_marketing_artifacts', 'marketing_run_id')) {
            return;
        }

        Schema::table('ai_marketing_artifacts', function (Blueprint $table): void {
            $table->uuid('marketing_run_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // No-op: re-imposing NOT NULL would fail on any standalone artifact already stored.
    }
};
