<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ASI-11 (movement 2) — append-only lineage ledger.
 *
 * Small consultable table wiring commits, memory writes, applier decisions and
 * outcomes to a common decision_id/obra_id. The executor of
 * `atlas:rollback:cascade` reads THIS to compute the transitive closure of a
 * decision it needs to revert. Never mutated after append.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_decision_lineage_ledger')) {
            return;
        }

        Schema::create('atlas_decision_lineage_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('decision_id', 120)->index();
            $table->string('obra_id', 120)->nullable()->index();

            // What was written under this decision.
            // entity_kind ∈ {commit, memory, apply, outcome, receipt}.
            $table->string('entity_kind', 32)->index();
            $table->string('entity_ref', 200); // commit sha, memory id, applier proposal id, outcome id, receipt id.
            $table->string('entity_scope', 60)->nullable()->index();

            // Reverse handle (already-exposed reversibility on the target
            // subsystem: memory-forget id, applier reverse_handle, etc).
            $table->string('reverse_handle', 200)->nullable();

            // The writer (`autonomos_landing`, `learning_applier`,
            // `memory_registry`, `live_outcome_feedback`, …). Provider-safe: no
            // raw payload here.
            $table->string('writer', 80);

            $table->json('meta')->nullable();

            $table->timestampTz('recorded_at')->index();

            $table->unique(['entity_kind', 'entity_ref']);
            $table->index(['decision_id', 'entity_kind'], 'atlas_lineage_decision_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_decision_lineage_ledger');
    }
};
