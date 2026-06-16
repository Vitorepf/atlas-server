<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACDE lever B4b — DELIVERY CONTRACT corpus (the brain-feedback write-end).
 *
 * One row per CERTIFIED+merged delivery, recording the PROVEN provider-safe facts about it: the target file,
 * its changed public symbols (NAMES only), its consumer-set (the blast-radius PATHS), the machine-resolved D2
 * dimensions, and a deterministic confidence derived from those (canary RED => 0; clean+wired > clean+orphan).
 * NO raw diff, NO code, NO model self-report — a downstream reader recalls "this module's proven contract +
 * who depends on it", never the implementation. Anchored on the real merge envelope, never a self-grade.
 *
 * Idempotent (Schema::hasTable guard) — never hand-stamped. A NEW telemetry table that NOTHING in the never-
 * merge layer reads; the merge path writes it best-effort (flag-gated, fail-open) and never depends on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_loop_delivery_contracts')) {
            Schema::create('atlas_loop_delivery_contracts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('candidate_hash', 40)->unique();      // dedupe: target + symbols + commit
                $table->string('target_path', 500)->index();
                $table->json('changed_symbols')->nullable();         // public symbol NAMES (no bodies, no code)
                $table->unsignedInteger('consumer_count')->default(0);
                $table->json('consumers')->nullable();               // blast-radius PATHS (provider-safe)
                $table->string('risk_band', 16)->default('unknown');
                $table->string('canary', 16)->default('not_run');    // green / red / not_run (machine-resolved)
                $table->float('mutation_kill_ratio')->default(0);
                $table->float('completeness')->default(0);
                $table->float('confidence')->default(0);             // deterministic, NEVER a self-grade
                $table->string('commit_sha', 64)->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('atlas_loop_delivery_contracts')) {
            Schema::dropIfExists('atlas_loop_delivery_contracts');
        }
    }
};
