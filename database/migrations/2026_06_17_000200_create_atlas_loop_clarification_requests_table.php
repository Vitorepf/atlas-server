<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACDE U5 — OPERATOR CLARIFICATION QUEUE (the loop ASKS instead of silently guessing).
 *
 * When the structured planner ABSTAINS — a vague goal with no anchor, an un-ready spec, or an ill-formed
 * DAG — today it silently falls back to the dumb one-shot buildPlan and the operator never knows the
 * planner gave up. This durable queue records one pending clarification request per (goal, abstention
 * reason): the calibrated-abstain-and-ask mechanism made persistent. U6 caches an operator's answer keyed
 * on goal_fingerprint so the loop never re-asks the same thing; U7 routes a request to the right surface.
 *
 * goal_fingerprint is a deterministic hash of the normalized goal, so the SAME goal re-abstaining
 * de-dupes onto one row (times_seen increments) and U6's cache can match an answer to a re-ask. Default
 * OFF => nothing is enqueued => byte-identical. Idempotent (Schema::hasTable guard) — never hand-stamped.
 * Propose-only invariant untouched: a NEW table nothing in the never-merge layer depends on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_loop_clarification_requests')) {
            Schema::create('atlas_loop_clarification_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('goal_fingerprint', 32)->index();   // deterministic hash of the normalized goal
                $table->text('goal');                              // the raw objective (operator-local, never a provider)
                $table->string('objective_kind')->nullable()->index();
                $table->string('family')->nullable();
                $table->string('reason');                          // vague_goal_no_anchor | spec_not_ready | plan_not_ready
                $table->string('status')->default('pending')->index(); // pending | answered | dismissed
                $table->text('answer')->nullable();                // the operator's clarification (U6 caches this)
                $table->unsignedInteger('times_seen')->default(1); // how many times this exact abstention recurred
                $table->timestamp('answered_at')->nullable();
                $table->timestamps();
                $table->unique(['goal_fingerprint', 'reason']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('atlas_loop_clarification_requests')) {
            Schema::dropIfExists('atlas_loop_clarification_requests');
        }
    }
};
