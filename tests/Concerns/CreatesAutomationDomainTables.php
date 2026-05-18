<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAutomationDomainTables
{
    protected function createAutomationDomainTables(): void
    {
        $this->dropAutomationDomainTables();

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
        });

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
        });

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
        });

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
        });
    }

    protected function dropAutomationDomainTables(): void
    {
        Schema::dropIfExists('ai_automation_evolution_events');
        Schema::dropIfExists('ai_automation_tool_decisions');
        Schema::dropIfExists('ai_automation_plans');
        Schema::dropIfExists('ai_automation_runs');
    }
}
