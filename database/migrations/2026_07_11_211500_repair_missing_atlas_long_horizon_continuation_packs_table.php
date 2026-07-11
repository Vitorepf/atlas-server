<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repair migration: the original 2026_05_19_040000 migration creates
 * `atlas_long_horizon_continuation_packs`, but the live DB can drift when a
 * table is dropped after the migration ledger already marks it as Ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_long_horizon_continuation_packs')) {
            return;
        }

        Schema::create('atlas_long_horizon_continuation_packs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.long_horizon.continuation_pack.v2');
            $table->string('uuid', 64)->unique();
            $table->string('scope_type', 40)->index();
            $table->string('scope_id', 64)->nullable()->index();
            $table->text('objective');
            $table->string('current_phase', 80)->nullable()->index();
            $table->text('state_summary');
            $table->json('decisions');
            $table->json('superseded_decisions');
            $table->json('open_tasks');
            $table->json('completed_tasks');
            $table->json('blockers');
            $table->json('risks');
            $table->json('evidence_refs');
            $table->json('context_manifest');
            $table->string('context_pack_hash', 64)->nullable();
            $table->string('summary_hash', 64);
            $table->json('source_receipts');
            $table->timestampTz('stale_after')->nullable()->index();
            $table->string('safe_resume_mode', 40)->default('full')->index();
            $table->string('next_safe_action', 200)->nullable();
            $table->json('human_decisions_required');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('pack_hash', 64)->index();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id'], 'idx_lhcp_scope');
            $table->index(['scope_type', 'created_at'], 'idx_lhcp_scope_created');
        });
    }

    /**
     * Repair migrations must never remove a canonical table on rollback.
     */
    public function down(): void
    {
        // Intentionally irreversible.
    }
};
