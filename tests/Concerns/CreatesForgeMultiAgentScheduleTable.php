<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesForgeMultiAgentScheduleTable
{
    protected function createForgeMultiAgentScheduleTable(): void
    {
        Schema::dropIfExists('ai_forge_multi_agent_schedules');
        Schema::create('ai_forge_multi_agent_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.forge.multi_agent_schedule.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('intake_id')->nullable();
            $table->uuid('mission_id')->nullable();
            $table->uuid('work_order_id')->nullable();
            $table->string('obra_id', 120)->nullable();
            $table->text('task_summary');
            $table->string('risk_band', 20);
            $table->unsignedSmallInteger('recommended_agent_count')->default(1);
            $table->json('roles_summary');
            $table->json('role_assignments');
            $table->json('ownership_map');
            $table->json('non_overlap_constraints');
            $table->json('dependency_order');
            $table->string('integration_plan', 60);
            $table->json('conflict_risks');
            $table->json('verification_plan');
            $table->json('evidence_refs');
            $table->string('status', 60);
            $table->text('blocker_reason')->nullable();
            $table->string('schedule_hash', 64);
            $table->timestamps();
        });
    }

    protected function dropForgeMultiAgentScheduleTable(): void
    {
        Schema::dropIfExists('ai_forge_multi_agent_schedules');
    }
}
