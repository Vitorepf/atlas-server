<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S3 — REAL BUG-FIX WORK SUPPLY: the durable BUG-HANDLE store that feeds the already-wired
 * bug-fix reproduction lane. Distinct from `failure_signatures` (which carries COGNITIVE columns —
 * envelope/signature/domain/vector_embedding for the failure brain, NOT a runnable handle): this
 * table stores the runnable repro handle the lane needs (a target path + a reproducing test path
 * and/or explicit failing command + the failing assertion/message for objective context).
 *
 * A row here is a DETERMINISTIC RED already triaged as a real_failure (environmental/unknown reds
 * are dropped by the harvester's flaky-filter). The discovery stamp reads handlesByPath(...) and
 * writes the handle onto the matching target's signals so AtlasLoopQueueRefiller::tryBugReproduction
 * fires (objective_kind=bug_fix + acceptance.red_required + revert_recheck). Read-model only — nothing
 * in the never-merge layer depends on it. Idempotent (Schema::hasTable guard) — never hand-stamped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_loop_failure_handles')) {
            Schema::create('atlas_loop_failure_handles', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('dedup_key');                       // deterministic path|test dedup key
                $table->string('path');                            // repo-relative target file believed to hold the bug
                $table->string('failure_test_path');               // repo-relative reproducing test path
                $table->string('failure_command')->nullable();     // explicit failing command (alternative handle)
                $table->text('failing_assertion')->nullable();     // assertion / case name that fails
                $table->text('failure_message')->nullable();       // failure message (human context)
                $table->unsignedInteger('recurrence_count')->default(1);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
                $table->unique('dedup_key');                       // deterministic path|test dedup
                $table->index('path');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_loop_failure_handles');
    }
};
