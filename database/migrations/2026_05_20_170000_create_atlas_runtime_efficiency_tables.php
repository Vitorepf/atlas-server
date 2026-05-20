<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_runtime_efficiency_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.runtime_efficiency_governor.v1');
            $table->string('status', 40)->index();
            $table->string('surface_id', 100)->nullable()->index();
            $table->string('domain', 80)->nullable()->index();
            $table->string('flow_id', 120)->nullable()->index();
            $table->string('runtime_mode', 60)->index();
            $table->string('path', 60)->index();
            $table->string('prompt_hash', 64)->index();
            $table->unsignedTinyInteger('complexity_score')->default(1)->index();
            $table->unsignedTinyInteger('risk_score')->default(1)->index();
            $table->unsignedInteger('context_budget_tokens')->default(0);
            $table->unsignedInteger('tool_budget')->default(0);
            $table->unsignedInteger('subagent_budget')->default(0);
            $table->json('context_minimum_pack');
            $table->json('layer_admissions');
            $table->json('tool_policy')->nullable();
            $table->json('provider_fit')->nullable();
            $table->json('verification_plan')->nullable();
            $table->json('adaptive_policy')->nullable();
            $table->json('counterfactual_replay')->nullable();
            $table->json('enforcement_policy')->nullable();
            $table->json('quality_prediction')->nullable();
            $table->json('efficiency_risks')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->json('claim_policy')->nullable();
            $table->string('decision_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_runtime_efficiency_outcomes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('decision_id')->nullable()->index();
            $table->string('schema_version', 120)->default('atlas.runtime_efficiency_outcome.v1');
            $table->string('status', 40)->index();
            $table->string('outcome_type', 80)->index();
            $table->decimal('quality_score', 5, 2)->nullable();
            $table->decimal('context_roi_score', 5, 2)->nullable();
            $table->json('signals')->nullable();
            $table->json('learning_candidates')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('outcome_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_runtime_efficiency_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.runtime_efficiency_policy.v1');
            $table->string('status', 40)->index();
            $table->string('flow_id', 120)->index();
            $table->string('domain', 80)->nullable()->index();
            $table->string('recommended_path', 60)->index();
            $table->decimal('context_budget_multiplier', 5, 2)->default(1);
            $table->decimal('tool_budget_multiplier', 5, 2)->default(1);
            $table->json('layer_overrides')->nullable();
            $table->json('quality_stats')->nullable();
            $table->json('policy_rules')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('policy_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_runtime_efficiency_replays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('decision_id')->nullable()->index();
            $table->string('schema_version', 120)->default('atlas.runtime_efficiency_counterfactual_replay.v1');
            $table->string('status', 40)->index();
            $table->string('flow_id', 120)->nullable()->index();
            $table->string('baseline_path', 60)->index();
            $table->string('recommended_path', 60)->index();
            $table->json('candidates');
            $table->json('winning_candidate');
            $table->json('evidence_refs')->nullable();
            $table->string('replay_hash', 64)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_runtime_efficiency_replays');
        Schema::dropIfExists('atlas_runtime_efficiency_policies');
        Schema::dropIfExists('atlas_runtime_efficiency_outcomes');
        Schema::dropIfExists('atlas_runtime_efficiency_decisions');
    }
};
