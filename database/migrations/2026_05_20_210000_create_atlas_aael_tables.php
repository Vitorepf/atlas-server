<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_aael_opportunities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.aael.opportunity.v1');
            $table->string('status', 40)->index();
            $table->string('source_type', 80)->index();
            $table->string('domain', 80)->nullable()->index();
            $table->string('flow_id', 120)->nullable()->index();
            $table->string('opportunity_type', 80)->index();
            $table->string('risk_level', 40)->index();
            $table->float('priority_score')->index();
            $table->float('strategic_alignment_score')->index();
            $table->string('objective_hash', 64)->index();
            $table->text('objective');
            $table->json('signals');
            $table->json('roi_model');
            $table->json('dependencies')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('opportunity_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_aael_portfolio_cycles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.aael.portfolio_cycle.v1');
            $table->string('status', 40)->index();
            $table->string('surface_id', 120)->nullable()->index();
            $table->string('workspace_hash', 64)->nullable()->index();
            $table->json('portfolio_snapshot');
            $table->json('selection_policy');
            $table->json('autonomy_budget');
            $table->json('strategic_alignment_gate');
            $table->json('anti_drift_doctrine_gate');
            $table->json('selected_opportunities');
            $table->json('deferred_opportunities')->nullable();
            $table->json('operator_queue')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('cycle_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_aael_evolution_experiments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('cycle_id')->index();
            $table->uuid('opportunity_id')->nullable()->index();
            $table->uuid('aweos_execution_id')->nullable()->index();
            $table->uuid('intelligence_factory_gap_id')->nullable()->index();
            $table->string('schema_version', 120)->default('atlas.aael.evolution_experiment.v1');
            $table->string('status', 40)->index();
            $table->string('lane', 80)->index();
            $table->json('spec_packet');
            $table->json('impact_simulation');
            $table->json('execution_plan');
            $table->json('verification_plan');
            $table->json('rollback_plan');
            $table->json('learning_plan');
            $table->json('evidence_refs')->nullable();
            $table->string('experiment_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_aael_promotion_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('cycle_id')->index();
            $table->uuid('experiment_id')->nullable()->index();
            $table->string('schema_version', 120)->default('atlas.aael.promotion_decision.v1');
            $table->string('status', 40)->index();
            $table->string('trust_level', 80)->index();
            $table->json('promotion_gate');
            $table->json('risk_controls');
            $table->json('operator_action');
            $table->json('evidence_refs')->nullable();
            $table->string('decision_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_aael_audit_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('cycle_id')->nullable()->index();
            $table->string('schema_version', 120)->default('atlas.aael.audit_report.v1');
            $table->string('status', 40)->index();
            $table->json('audit_court');
            $table->json('self_evolution_memory');
            $table->json('dormant_capability_activation');
            $table->json('quality_score');
            $table->json('claim_policy');
            $table->json('evidence_refs')->nullable();
            $table->string('audit_hash', 64)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_aael_audit_reports');
        Schema::dropIfExists('atlas_aael_promotion_decisions');
        Schema::dropIfExists('atlas_aael_evolution_experiments');
        Schema::dropIfExists('atlas_aael_portfolio_cycles');
        Schema::dropIfExists('atlas_aael_opportunities');
    }
};
