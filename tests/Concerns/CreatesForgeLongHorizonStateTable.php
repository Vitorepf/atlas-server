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
    }

    protected function dropForgeLongHorizonStateTable(): void
    {
        Schema::dropIfExists('ai_forge_work_packet_execution_cycles');
        Schema::dropIfExists('ai_forge_long_horizon_states');
        $this->dropForgeIntakeTables();
    }
}
