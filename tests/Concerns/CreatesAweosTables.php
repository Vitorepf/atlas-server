<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAweosTables
{
    protected function createAweosTables(): void
    {
        $this->dropAweosTables();

        Schema::create('atlas_aweos_executions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->string('maturity_level', 120)->index();
            $table->string('surface_id', 120)->nullable()->index();
            $table->string('domain', 80)->nullable()->index();
            $table->string('flow_id', 120)->nullable()->index();
            $table->string('objective_hash', 64)->index();
            $table->text('objective');
            $table->uuid('mission_id')->nullable()->index();
            $table->uuid('persistent_context_pack_id')->nullable()->index();
            $table->string('persistent_context_hash', 64)->nullable()->index();
            $table->uuid('runtime_efficiency_decision_id')->nullable()->index();
            $table->uuid('workcell_id')->nullable()->index();
            $table->uuid('aemor_episode_id')->nullable()->index();
            $table->json('persistent_context')->nullable();
            $table->json('runtime_efficiency')->nullable();
            $table->json('agentic_workcell')->nullable();
            $table->json('aemor_episode')->nullable();
            $table->json('execution_plan');
            $table->json('tool_orchestration');
            $table->json('repair_recovery_loop');
            $table->json('operator_decision_economy');
            $table->json('continuation_engine');
            $table->json('strategic_next_action');
            $table->json('mission_control');
            $table->json('evidence_refs')->nullable();
            $table->json('claim_policy');
            $table->string('execution_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_aweos_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('execution_id')->index();
            $table->string('schema_version', 120);
            $table->string('event_type', 100)->index();
            $table->string('status', 40)->index();
            $table->json('payload')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('event_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_aweos_certified_outcomes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('execution_id')->index();
            $table->string('schema_version', 120);
            $table->string('status', 40)->index();
            $table->string('certification_level', 40)->index();
            $table->string('claim_hash', 64)->index();
            $table->text('claim');
            $table->json('patch_boundary')->nullable();
            $table->json('command_ledger')->nullable();
            $table->json('test_impact')->nullable();
            $table->json('evidence_bundle');
            $table->json('replay_manifest');
            $table->json('learning_decision');
            $table->json('aemor_outcome')->nullable();
            $table->json('areg_outcome')->nullable();
            $table->json('aawr_outcome')->nullable();
            $table->json('evidence_refs');
            $table->string('outcome_hash', 64)->index();
            $table->timestamps();
        });
    }

    protected function dropAweosTables(): void
    {
        Schema::dropIfExists('atlas_aweos_certified_outcomes');
        Schema::dropIfExists('atlas_aweos_events');
        Schema::dropIfExists('atlas_aweos_executions');
    }
}
