<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACDE lever O2 — ORIGINATION OUTCOME ledger (the operator accept/reject signal — the 3rd multiplier).
 *
 * One row per operator decision on an O1 origination proposal, keyed by a deterministic SHAPE TOKEN (the
 * origination's structure: target directory + criteria bucket — never the content). The accept-rate Wilson
 * lower bound per shape token tells the O1 producer to BACK OFF shapes the operator empirically rejects, so the
 * NEXT authored origination is sharpened by the only ground truth for origination quality: the human's
 * accept/reject. The signal is rate-bounded by review cadence and human-frozen (never loop self-graded).
 *
 * Idempotent (Schema::hasTable guard) — never hand-stamped. A NEW telemetry table NOTHING in the never-merge
 * layer reads; the feedback loop is contained within the origination subsystem (O1 reads it), never touching
 * the shared readiness gate or the merge door.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_loop_origination_outcomes')) {
            Schema::create('atlas_loop_origination_outcomes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('shape_token', 40)->index();   // deterministic origination-shape fingerprint
                $table->boolean('accepted');                   // the operator's decision (true=accept, false=reject)
                $table->string('proposal_id', 64)->nullable()->index();
                $table->string('target_path', 500)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('atlas_loop_origination_outcomes')) {
            Schema::dropIfExists('atlas_loop_origination_outcomes');
        }
    }
};
