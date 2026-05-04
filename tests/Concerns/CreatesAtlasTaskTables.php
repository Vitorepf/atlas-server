<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAtlasTaskTables
{
    protected function createAtlasTaskTables(): void
    {
        $this->dropAtlasTaskTables();

        // atlas_tasks — SQLite-compatible (no FK to atlas_domains, no CHECK constraints, JSONB→JSON)
        Schema::create('atlas_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->string('priority', 24)->default('normal');
            $table->string('domain', 80)->default('dev')->index();
            $table->uuid('source_capture_id')->nullable();
            $table->uuid('project_id')->nullable()->index();
            $table->uuid('project_step_id')->nullable();
            $table->uuid('routine_id')->nullable();
            $table->date('routine_occurrence_date')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->date('planned_for_date')->nullable();
            $table->dateTime('planned_start_at')->nullable();
            $table->dateTime('planned_end_at')->nullable();
            $table->integer('estimated_minutes')->default(25);
            $table->string('energy_required', 24)->default('medium');
            $table->smallInteger('urgency_score')->default(50);
            $table->smallInteger('impact_score')->default(50);
            $table->smallInteger('effort_score')->default(50);
            $table->smallInteger('priority_score')->default(50);
            $table->string('planning_status', 24)->default('unscheduled');
            $table->string('execution_mode', 40)->nullable();
            $table->smallInteger('friction_level')->nullable();
            $table->smallInteger('emotional_resistance')->nullable();
            $table->smallInteger('clarity_level')->nullable();
            $table->text('starter_step')->nullable();
            $table->text('minimum_viable_action')->nullable();
            $table->text('if_then_plan')->nullable();
            $table->text('reward_hint')->nullable();
            $table->text('failure_reason_last')->nullable();
            $table->integer('attempt_count')->default(0);
            $table->integer('recovery_count')->default(0);
            $table->dateTime('completed_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        // atlas_task_events — SQLite-compatible (JSONB→JSON, no FK constraints)
        Schema::create('atlas_task_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id')->index();
            $table->string('event_type', 80)->index();
            $table->string('source', 80)->default('app');
            $table->json('payload')->default('{}');
            $table->dateTime('occurred_at');
            $table->timestamps();
        });
    }

    protected function dropAtlasTaskTables(): void
    {
        Schema::dropIfExists('atlas_task_events');
        Schema::dropIfExists('atlas_tasks');
    }
}
