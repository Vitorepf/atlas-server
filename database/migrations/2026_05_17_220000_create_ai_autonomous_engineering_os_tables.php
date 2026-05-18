<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_autonomous_engineering_goals')) {
            Schema::create('ai_autonomous_engineering_goals', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.autonomous_engineering.goal.v1');
                $table->string('goal_id', 120)->unique();
                $table->text('goal');
                $table->string('status', 40)->index();
                $table->string('intent_flow_id', 80)->nullable()->index();
                $table->string('promotion_target', 80)->nullable()->index();
                $table->json('evidence_refs')->nullable();
                $table->string('outcome_receipt_hash', 64)->nullable()->index();
                $table->string('certification_hash', 64)->nullable()->index();
                $table->json('receipt')->nullable();
                $table->string('receipt_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_autonomous_work_cycles')) {
            Schema::create('ai_autonomous_work_cycles', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->index();
                $table->string('schema_version', 120)->default('atlas.ai.autonomous_engineering.work_cycle.v1');
                $table->string('cycle_id', 120)->unique();
                $table->unsignedInteger('cycle_index')->default(1);
                $table->string('status', 40)->index();
                $table->string('flow_id', 80)->index();
                $table->json('objective')->nullable();
                $table->json('next_action')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('receipt_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_autonomous_work_steps')) {
            Schema::create('ai_autonomous_work_steps', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->index();
                $table->uuid('cycle_record_id')->index();
                $table->string('schema_version', 120)->default('atlas.ai.autonomous_engineering.work_step.v1');
                $table->string('step_id', 120)->unique();
                $table->unsignedInteger('step_index')->default(1);
                $table->string('status', 40)->index();
                $table->string('action_type', 80)->index();
                $table->string('execution_mode', 80)->default('safe_simulation')->index();
                $table->json('expected_files')->nullable();
                $table->json('expected_tests')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('receipt_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_codebase_world_models')) {
            Schema::create('ai_codebase_world_models', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->nullable()->index();
                $table->string('schema_version', 120)->default('atlas.ai.autonomous_engineering.codebase_world_model.v1');
                $table->string('model_id', 120)->unique();
                $table->string('scope', 160)->default('atlas-server')->index();
                $table->string('status', 40)->default('built')->index();
                $table->json('capabilities')->nullable();
                $table->json('risks')->nullable();
                $table->json('receipt')->nullable();
                $table->string('model_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_codebase_world_model_nodes')) {
            Schema::create('ai_codebase_world_model_nodes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('world_model_id')->index();
                $table->string('node_id', 160)->index();
                $table->string('node_type', 80)->index();
                $table->string('path', 500)->nullable()->index();
                $table->string('flow_id', 80)->nullable()->index();
                $table->json('capabilities')->nullable();
                $table->json('risks')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['world_model_id', 'node_id'], 'idx_world_model_node_unique');
            });
        }

        if (! Schema::hasTable('ai_codebase_world_model_edges')) {
            Schema::create('ai_codebase_world_model_edges', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('world_model_id')->index();
                $table->string('from_node_id', 160)->index();
                $table->string('to_node_id', 160)->index();
                $table->string('edge_type', 80)->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_mandatory_rag_gates')) {
            Schema::create('ai_mandatory_rag_gates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->nullable()->index();
                $table->uuid('cycle_record_id')->nullable()->index();
                $table->string('schema_version', 120)->default('atlas.ai.autonomous_engineering.rag_gate.v1');
                $table->string('gate_id', 120)->unique();
                $table->string('status', 40)->index();
                $table->json('retrieval_plan')->nullable();
                $table->unsignedInteger('included_sources')->default(0);
                $table->unsignedInteger('used_sources')->default(0);
                $table->unsignedInteger('noise_sources')->default(0);
                $table->json('missed_required_sources')->nullable();
                $table->unsignedTinyInteger('context_sufficiency')->default(0)->index();
                $table->json('evidence_refs')->nullable();
                $table->string('context_pack_hash', 64)->index();
                $table->json('receipt')->nullable();
                $table->string('receipt_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_execution_plans')) {
            Schema::create('ai_execution_plans', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->index();
                $table->uuid('cycle_record_id')->index();
                $table->uuid('rag_gate_id')->nullable()->index();
                $table->uuid('world_model_id')->nullable()->index();
                $table->string('schema_version', 120)->default('atlas.ai.autonomous_engineering.execution_plan.v1');
                $table->string('plan_id', 120)->unique();
                $table->string('status', 40)->index();
                $table->string('target_flow_id', 80)->index();
                $table->json('steps')->nullable();
                $table->json('expected_files')->nullable();
                $table->json('expected_tests')->nullable();
                $table->json('risks')->nullable();
                $table->json('rollback_plan')->nullable();
                $table->json('compounding_memories')->nullable();
                $table->json('receipt')->nullable();
                $table->string('plan_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_repair_loops')) {
            Schema::create('ai_repair_loops', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->index();
                $table->uuid('cycle_record_id')->index();
                $table->uuid('step_record_id')->nullable()->index();
                $table->string('schema_version', 120)->default('atlas.ai.autonomous_engineering.repair_loop.v1');
                $table->string('repair_id', 120)->unique();
                $table->string('status', 40)->index();
                $table->string('failure_class', 80)->index();
                $table->json('failure')->nullable();
                $table->json('repair_steps')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('receipt_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_engineering_control_plane_events')) {
            Schema::create('ai_engineering_control_plane_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->nullable()->index();
                $table->string('schema_version', 120)->default('atlas.ai.autonomous_engineering.control_plane_event.v1');
                $table->string('event_id', 120)->unique();
                $table->string('event_type', 80)->index();
                $table->string('status', 40)->index();
                $table->json('payload')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('receipt_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_rivals_shadow_runs')) {
            Schema::create('ai_rivals_shadow_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->index();
                $table->uuid('cycle_record_id')->nullable()->index();
                $table->string('schema_version', 120)->default('atlas.ai.autonomous_engineering.rivals_shadow_run.v1');
                $table->string('shadow_run_id', 120)->unique();
                $table->string('status', 40)->index();
                $table->json('rivals')->nullable();
                $table->json('comparison_plan')->nullable();
                $table->boolean('false_claim_blocked')->default(true)->index();
                $table->json('benchmark_candidate')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('receipt')->nullable();
                $table->string('receipt_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_autonomous_engineering_certifications')) {
            Schema::create('ai_autonomous_engineering_certifications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->nullable()->index();
                $table->string('schema_version', 120)->default('atlas.ai.autonomous_engineering.certification.v1');
                $table->string('certification_id', 120)->unique();
                $table->string('status', 40)->index();
                $table->json('checks')->nullable();
                $table->json('blockers')->nullable();
                $table->json('claim_policy')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->string('certification_hash', 64)->unique();
                $table->timestamp('certified_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_autonomous_engineering_certifications');
        Schema::dropIfExists('ai_rivals_shadow_runs');
        Schema::dropIfExists('ai_engineering_control_plane_events');
        Schema::dropIfExists('ai_repair_loops');
        Schema::dropIfExists('ai_execution_plans');
        Schema::dropIfExists('ai_mandatory_rag_gates');
        Schema::dropIfExists('ai_codebase_world_model_edges');
        Schema::dropIfExists('ai_codebase_world_model_nodes');
        Schema::dropIfExists('ai_codebase_world_models');
        Schema::dropIfExists('ai_autonomous_work_steps');
        Schema::dropIfExists('ai_autonomous_work_cycles');
        Schema::dropIfExists('ai_autonomous_engineering_goals');
    }
};
