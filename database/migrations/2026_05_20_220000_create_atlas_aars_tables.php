<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_aars_scenarios', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.aars.scenario.v1');
            $table->string('status', 40)->index();
            $table->string('surface_id', 120)->nullable()->index();
            $table->string('domain', 80)->nullable()->index();
            $table->string('flow_id', 120)->nullable()->index();
            $table->string('scenario_type', 80)->index();
            $table->string('scope_hash', 64)->nullable()->index();
            $table->string('objective_hash', 64)->index();
            $table->text('objective');
            $table->json('world_state');
            $table->json('assumptions');
            $table->json('constraints')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('scenario_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_aars_simulations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('scenario_id')->nullable()->index();
            $table->string('schema_version', 120)->default('atlas.aars.simulation.v1');
            $table->string('status', 40)->index();
            $table->string('mode', 80)->default('dry_run')->index();
            $table->json('options');
            $table->json('predicted_outcomes');
            $table->json('impact_model');
            $table->json('uncertainty');
            $table->json('required_validation');
            $table->json('claim_policy');
            $table->json('evidence_refs')->nullable();
            $table->string('simulation_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_aars_counterfactuals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('simulation_id')->nullable()->index();
            $table->string('schema_version', 120)->default('atlas.aars.counterfactual.v1');
            $table->string('status', 40)->index();
            $table->json('baseline');
            $table->json('alternatives');
            $table->json('delta_analysis');
            $table->json('decision_effects');
            $table->json('evidence_refs')->nullable();
            $table->string('counterfactual_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_aars_risk_projections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('simulation_id')->nullable()->index();
            $table->string('schema_version', 120)->default('atlas.aars.risk_projection.v1');
            $table->string('status', 40)->index();
            $table->string('risk_level', 40)->index();
            $table->json('risks');
            $table->json('mitigations');
            $table->json('rollback_requirements');
            $table->json('operator_gates');
            $table->json('evidence_refs')->nullable();
            $table->string('risk_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_aars_certifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('simulation_id')->nullable()->index();
            $table->string('schema_version', 120)->default('atlas.aars.certification_result.v1');
            $table->string('status', 40)->index();
            $table->json('checks');
            $table->json('promotion_gate');
            $table->json('claim_policy');
            $table->json('evidence_refs')->nullable();
            $table->string('certification_hash', 64)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_aars_certifications');
        Schema::dropIfExists('atlas_aars_risk_projections');
        Schema::dropIfExists('atlas_aars_counterfactuals');
        Schema::dropIfExists('atlas_aars_simulations');
        Schema::dropIfExists('atlas_aars_scenarios');
    }
};
