<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesForgeLongHorizonStateTable
{
    use CreatesForgeIntakeTables;

    protected function createForgeLongHorizonStateTable(): void
    {
        $this->createForgeIntakeTables();
        Schema::dropIfExists('ai_forge_outcome_memories');
        Schema::dropIfExists('ai_forge_work_packet_workcell_routes');
        Schema::dropIfExists('ai_forge_multi_agent_schedules');
        Schema::dropIfExists('ai_forge_work_packet_execution_cycles');
        Schema::dropIfExists('ai_forge_long_horizon_states');

        Schema::create('ai_forge_long_horizon_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.forge.long_horizon_state.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('intake_id')->unique();
            $table->string('obra_title', 500);
            $table->string('status', 40)->default('active')->index();
            $table->string('current_milestone', 60)->nullable()->index();
            $table->json('milestone_progress');
            $table->json('active_work_packets');
            $table->json('completed_work_packets');
            $table->json('blockers');
            $table->json('evidence_refs');
            $table->json('next_action');
            $table->json('last_cycle_summary')->nullable();
            $table->unsignedInteger('cycle_count')->default(0);
            $table->string('continuation_context_hash', 64)->nullable();
            $table->string('state_hash', 64)->index();
            $table->text('blocker_reason')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_forge_work_packet_execution_cycles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.forge.work_packet_execution_cycle.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('intake_id');
            $table->uuid('work_packet_id');
            $table->string('work_packet_canonical_id', 80);
            $table->uuid('long_horizon_state_id')->nullable();
            $table->unsignedSmallInteger('cycle_position')->default(1);
            $table->string('execution_mode', 40);
            $table->string('status', 40);
            $table->json('execution_plan');
            $table->json('expected_artifacts');
            $table->json('evidence_refs');
            $table->json('gate_result')->nullable();
            $table->string('outcome_status', 40)->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('repair_hook')->nullable();
            $table->json('next_action');
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->string('cycle_hash', 64);
            $table->timestamps();
        });

        Schema::create('ai_forge_multi_agent_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.forge.multi_agent_schedule.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('intake_id')->nullable()->index();
            $table->uuid('mission_id')->nullable()->index();
            $table->uuid('work_order_id')->nullable()->index();
            $table->string('obra_id', 120)->nullable()->index();
            $table->text('task_summary');
            $table->string('risk_band', 20)->index();
            $table->unsignedSmallInteger('recommended_agent_count')->default(1);
            $table->json('roles_summary');
            $table->json('role_assignments');
            $table->json('ownership_map');
            $table->json('non_overlap_constraints');
            $table->json('dependency_order');
            $table->string('integration_plan', 60)->index();
            $table->json('conflict_risks');
            $table->json('verification_plan');
            $table->json('evidence_refs');
            $table->string('status', 60)->index();
            $table->text('blocker_reason')->nullable();
            $table->string('schedule_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('ai_forge_work_packet_workcell_routes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.forge.work_packet_workcell_route.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('intake_id')->index();
            $table->uuid('work_packet_id')->index();
            $table->string('work_packet_canonical_id', 80)->index();
            $table->uuid('execution_cycle_id')->nullable()->index();
            $table->uuid('multi_agent_schedule_id')->nullable()->index();
            $table->string('workcell', 80)->index();
            $table->string('agent_profile', 120)->index();
            $table->boolean('parallelizable')->default(false)->index();
            $table->boolean('requires_human_review')->default(false)->index();
            $table->json('route_reasons');
            $table->json('ownership_paths');
            $table->string('status', 60)->default('planned')->index();
            $table->string('route_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('ai_forge_outcome_memories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.forge.outcome_memory.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('intake_id')->index();
            $table->uuid('work_packet_id')->index();
            $table->string('work_packet_canonical_id', 80)->index();
            $table->uuid('execution_cycle_id')->index();
            $table->string('cycle_uuid', 64)->index();
            $table->string('outcome_status', 40)->index();
            $table->string('execution_mode', 40)->index();
            $table->json('evidence_kinds');
            $table->json('aedpds_drivers')->nullable();
            $table->string('aedpds_gate_status', 40)->nullable()->index();
            $table->string('aedpds_doctrine_hash', 64)->nullable()->index();
            $table->string('aedpds_gate_hash', 64)->nullable()->index();
            $table->json('aedpds_effectiveness')->nullable();
            $table->json('learning_candidates');
            $table->json('failure_capsule')->nullable();
            $table->boolean('should_promote_to_aemor')->default(true)->index();
            $table->boolean('human_review_required')->default(false)->index();
            $table->string('outcome_memory_hash', 64)->unique();
            $table->timestamps();
        });
    }

    protected function dropForgeLongHorizonStateTable(): void
    {
        Schema::dropIfExists('ai_forge_outcome_memories');
        Schema::dropIfExists('ai_forge_work_packet_workcell_routes');
        Schema::dropIfExists('ai_forge_multi_agent_schedules');
        Schema::dropIfExists('ai_forge_work_packet_execution_cycles');
        Schema::dropIfExists('ai_forge_long_horizon_states');
        $this->dropForgeIntakeTables();
    }
}
