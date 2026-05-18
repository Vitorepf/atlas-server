<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atlas Automation / Tool Factory Runtime (Meta 8 · Automation).
 *
 * Canonical persistence for the Automation Company Runtime:
 *
 *   ai_automation_runs            top-level container with mission/work_order refs
 *   ai_automation_plans           one row per planned action (browser/api/terminal/builder/evolution)
 *   ai_automation_tool_decisions  tool selection decisions with alternatives and scores
 *   ai_automation_evolution_events tool lifecycle observations from the Tool Runtime
 *
 * No row in any of these tables represents an actual external action — Automation
 * Runtime ONLY plans, scores, and records evolution observations. Real execution
 * requires Policy approval and Tool Runtime invocation, which write separately
 * to `ai_safety_decisions` and `ai_tool_invocations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_automation_runs')) {
            Schema::create('ai_automation_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.automation.run.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('mission_id')->nullable()->index();
                $table->uuid('work_order_id')->nullable()->index();
                $table->string('run_kind', 60)->index();
                $table->string('status', 40)->default('open')->index();
                $table->text('objective');
                $table->json('inputs')->nullable();
                $table->json('outputs')->nullable();
                $table->json('blockers')->nullable();
                $table->string('next_action', 200)->nullable();
                $table->string('receipt_hash', 64)->unique();
                $table->timestamp('completed_at')->nullable()->index();
                $table->timestamps();

                $table->index(['run_kind', 'status'], 'idx_ai_automation_runs_kind_status');
            });
        }

        if (! Schema::hasTable('ai_automation_plans')) {
            Schema::create('ai_automation_plans', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.automation.plan.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('automation_run_id')->index();
                $table->string('plan_type', 60)->index();
                $table->string('title', 300);
                $table->text('summary')->nullable();
                $table->json('payload');
                $table->json('safety_factors')->nullable();
                $table->json('rollback')->nullable();
                $table->string('status', 40)->default('planned')->index();
                $table->string('policy_decision', 40)->nullable()->index();
                $table->string('plan_hash', 64)->index();
                $table->timestamps();

                $table->index(['automation_run_id', 'plan_type'], 'idx_ai_automation_plans_run_type');
            });
        }

        if (! Schema::hasTable('ai_automation_tool_decisions')) {
            Schema::create('ai_automation_tool_decisions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.automation.tool_decision.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('automation_run_id')->nullable()->index();
                $table->string('need', 500);
                $table->string('decision_kind', 60)->index();
                $table->string('selected_tool_id', 160)->nullable()->index();
                $table->json('alternatives');
                $table->json('safety_factors')->nullable();
                $table->decimal('score', 5, 4);
                $table->text('justification');
                $table->string('decision_hash', 64)->index();
                $table->timestamps();

                $table->index(['decision_kind', 'created_at'], 'idx_ai_automation_tool_decisions_kind_created');
            });
        }

        if (! Schema::hasTable('ai_automation_evolution_events')) {
            Schema::create('ai_automation_evolution_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.automation.evolution_event.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('automation_run_id')->nullable()->index();
                $table->string('tool_id', 160)->index();
                $table->string('event_kind', 60)->index();
                $table->json('observation');
                $table->string('recommendation', 60)->nullable();
                $table->string('event_hash', 64)->index();
                $table->timestamps();

                $table->index(['tool_id', 'event_kind'], 'idx_ai_automation_evolution_events_tool_kind');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_automation_evolution_events');
        Schema::dropIfExists('ai_automation_tool_decisions');
        Schema::dropIfExists('ai_automation_plans');
        Schema::dropIfExists('ai_automation_runs');
    }
};
