<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atlas Evolution Loop — durable 24h runtime spine.
 *
 * The per-task engine (frozen judge + scenario explorer + loop runner) was proven
 * but ran entirely in-memory per command: a crash at hour 7 lost everything. These
 * tables make a 24h autonomous campaign REAL and crash-safe — a durable queue that
 * survives restarts, a certified-for-review proposal ledger that NEVER merges, and
 * an exploration audit (the "19 that didn't work").
 *
 * This is a NEW, propose-only concern. It deliberately does NOT collide with the
 * `atlas_aael_*` tables nor reuse the `software_company_stewardship` AP-790 stack,
 * whose execution core is a self-documented fixture and whose terminal action is
 * merge-to-main — both incompatible with the operator's propose-only loop.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The 24h CAMPAIGN — the durable unit a supervisor drives for a long horizon,
        // surviving crashes, pauses and the kill-switch via the exclusive lock lease.
        Schema::create('atlas_loop_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.loop.campaign.v1');
            $table->string('status', 40)->index();              // running|paused|completed|aborted|killed
            $table->text('goal');
            $table->string('provider', 120)->nullable();        // null => loop-default (provider-agnostic)
            $table->string('worktree_path', 1024)->nullable();  // isolated checkout the grind runs against
            // Budgets — denormalized for cheap per-tick supervisor checks (full knobs in `config`).
            $table->unsignedInteger('max_seconds')->default(0); // 0 = no wall-clock cap
            $table->unsignedInteger('max_proposals')->nullable();
            $table->unsignedInteger('max_tasks')->nullable();
            $table->json('config');                             // scenarios_per_task, workers, refill thresholds, shadow...
            // Rolling counters — cheap observability without scanning child rows.
            $table->unsignedInteger('tasks_processed')->default(0);
            $table->unsignedInteger('proposals_count')->default(0);
            $table->unsignedInteger('scenarios_explored')->default(0);
            $table->unsignedInteger('refills')->default(0);
            $table->unsignedInteger('loopbacks')->default(0);
            $table->json('totals')->nullable();
            $table->string('stop_reason', 80)->nullable();
            // Crash-recovery exclusive lease (mirrors the proven AP-790 lock pattern, propose-only scale).
            $table->string('lock_token', 64)->nullable();
            $table->timestamp('lock_expires_at')->nullable()->index();
            $table->timestamp('heartbeat_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // The durable TASK QUEUE — survives restarts; a crashed worker's lease expires and the task is reclaimed.
        Schema::create('atlas_loop_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->string('schema_version', 120)->default('atlas.loop.task.v1');
            $table->string('status', 40)->index();              // pending|claimed|running|done|failed|deferred
            $table->string('source', 40)->index();              // discovery|generator|loopback|manual|seed
            $table->string('target_path', 1024)->nullable();
            $table->text('objective');
            // Durable, restart-safe SPEC to REBUILD the scoped base workspace at claim time. The ephemeral
            // temp base_workspace never survives a restart, so we persist what rebuilds it (the real repo
            // path of the target + the frozen test bodies + acceptance), not a dead temp path.
            $table->json('payload');                            // { target_repo_path, frozen_tests:[{path,content}], acceptance, allowed_files, validation_commands, provider }
            $table->integer('priority')->default(0)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(1);
            $table->string('dedupe_key', 64)->index();          // hash(target+objective) — loop-back never regenerates the same task forever
            // Claim/lease — parallel workers never double-process; a crash => lease expiry => reclaim.
            $table->string('claimed_by', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'dedupe_key']);      // same campaign never queues the same task twice
        });

        // The certified-for-review PROPOSAL LEDGER — the stack you wake up to. NEVER merged.
        Schema::create('atlas_loop_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->uuid('task_id')->nullable()->index();
            $table->string('schema_version', 120)->default('atlas.loop.proposal.v1');
            $table->string('status', 40)->default('certified_for_review')->index(); // INVARIANT: never 'merged'
            $table->text('objective');
            $table->string('provider', 120)->nullable();
            $table->string('target_path', 1024)->nullable();
            $table->longText('diff_text');                      // the reviewable patch — a self-contained artifact
            $table->string('proposal_hash', 64)->index();
            $table->json('metric')->nullable();
            $table->string('acceptance_hash', 64)->nullable();
            $table->unsignedInteger('scenarios_explored')->default(0);
            $table->unsignedInteger('scenarios_accepted')->default(0);
            $table->string('winning_scenario', 40)->nullable();
            // HARD INVARIANT column — always false; the model forces it false on every save.
            $table->boolean('merged_to_main')->default(false);
            $table->timestamp('reviewed_at')->nullable();       // operator review happens OUTSIDE the loop
            $table->timestamps();

            $table->unique(['campaign_id', 'proposal_hash']);   // dedupe identical proposals within a campaign
        });

        // The EXPLORATION AUDIT — the "19 that didn't work" (the loop's growing, durable experience).
        Schema::create('atlas_loop_explorations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id')->index();
            $table->uuid('task_id')->nullable()->index();
            $table->string('schema_version', 120)->default('atlas.loop.exploration.v1');
            $table->text('objective');
            $table->string('provider', 120)->nullable();
            $table->unsignedInteger('scenarios_explored')->default(0);
            $table->unsignedInteger('scenarios_accepted')->default(0);
            $table->boolean('has_winner')->default(false);
            $table->boolean('converged')->nullable();
            $table->json('rejected_reasons')->nullable();
            $table->float('elapsed_seconds')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_loop_explorations');
        Schema::dropIfExists('atlas_loop_proposals');
        Schema::dropIfExists('atlas_loop_tasks');
        Schema::dropIfExists('atlas_loop_campaigns');
    }
};
