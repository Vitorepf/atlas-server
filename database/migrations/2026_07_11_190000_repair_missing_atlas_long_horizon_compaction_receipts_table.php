<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repair migration: the 02/07 wiper incident dropped
 * `atlas_long_horizon_compaction_receipts` from the live database while the
 * migrations ledger still marks the original 2026_05_19_040000 migration as
 * Ran, so a plain `php artisan migrate` never recreates it. Same pattern as
 * 2026_07_09_153500_repair_missing_ai_memory_deltas_table.
 *
 * Includes `context_retention_score` because the pending
 * 2026_07_11_171500 add-column migration no-ops when the table is absent and
 * runs before this repair.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_long_horizon_compaction_receipts')) {
            Schema::create('atlas_long_horizon_compaction_receipts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.long_horizon.compaction_receipt.v1');
                $table->string('uuid', 64)->unique();
                $table->string('scope_type', 40)->index();
                $table->string('scope_id', 64)->nullable()->index();
                $table->json('source_context_refs');
                $table->json('retained_items');
                $table->json('discarded_items');
                $table->string('discarded_reason', 40)->nullable()->index();
                $table->json('must_keep_items');
                $table->decimal('must_keep_coverage', 4, 3)->default(0);
                $table->json('unresolved_loss');
                $table->string('loss_risk', 20)->default('low')->index();
                $table->json('recovery_queries');
                $table->json('evidence_refs');
                $table->string('summary_hash', 64);
                $table->decimal('context_retention_score', 5, 4)->nullable();
                $table->decimal('quality_score', 4, 3)->nullable();
                $table->json('detected_contradictions');
                $table->json('stale_risks');
                $table->string('receipt_hash', 64)->index();
                $table->timestamps();

                $table->index(['scope_type', 'scope_id'], 'idx_lhcr_scope');
                $table->index(['loss_risk', 'created_at'], 'idx_lhcr_risk_created');
            });

            return;
        }

        if (! Schema::hasColumn('atlas_long_horizon_compaction_receipts', 'context_retention_score')) {
            Schema::table('atlas_long_horizon_compaction_receipts', function (Blueprint $table): void {
                $table->decimal('context_retention_score', 5, 4)->nullable()->after('summary_hash');
            });
        }
    }

    /**
     * Repair migrations must never remove a canonical table on rollback.
     */
    public function down(): void
    {
        // Intentionally irreversible.
    }
};
