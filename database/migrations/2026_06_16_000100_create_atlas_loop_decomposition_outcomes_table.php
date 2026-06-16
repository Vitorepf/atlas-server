<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACDE Leap 5 — DECOMPOSITION OUTCOME LEDGER (the loop compounds decomposition competence).
 *
 * Durable shape->outcome corpus: one row per EXECUTED obra, anchored on the REAL terminal outcome
 * (the certifier envelope from Leaps 2-3 — done && certified && main_untouched && reduced — or the
 * machine-resolved post-merge canary GREEN), keyed on a deterministic structural plan fingerprint.
 * AtlasLoopDecompositionShapePrior computes a Wilson lower-bound certified-rate per fingerprint that
 * AtlasLoopPlanReadinessGate consults as a NON-fatal advisory band ("shape_historically_thrashes" =>
 * REPLAN, cheap) — bending the weak engine away from shapes that empirically fail the frozen gates.
 *
 * Every learned signal is anchored on a human-frozen-bar terminal outcome, never on the model declaring
 * its plan is good. Idempotent by construction (Schema::hasTable guard) — never hand-stamped. Propose-only
 * invariant untouched: a NEW telemetry table nothing in the never-merge layer depends on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_loop_decomposition_outcomes')) {
            Schema::create('atlas_loop_decomposition_outcomes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('fingerprint_hash', 32)->index();    // deterministic structural plan fingerprint
                $table->string('objective_kind')->nullable()->index(); // recorded for future bucketing (NOT in the hash)
                $table->unsignedInteger('node_count')->default(0);
                $table->boolean('certified');                        // terminal outcome (frozen-bar envelope / canary GREEN)
                $table->boolean('thrashed');                         // !certified — executed but did not clear the bar
                $table->string('terminal_reason')->nullable();       // the named refusal/certify reason
                $table->unsignedInteger('rounds')->default(1);       // planning/repair rounds spent
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('atlas_loop_decomposition_outcomes')) {
            Schema::dropIfExists('atlas_loop_decomposition_outcomes');
        }
    }
};
