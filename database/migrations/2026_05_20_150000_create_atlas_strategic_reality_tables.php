<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_reality_entities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.strategic_reality.entity.v1');
            $table->string('status', 40)->index();
            $table->string('entity_key', 180)->unique();
            $table->string('entity_type', 80)->index();
            $table->string('name');
            $table->string('authority_level', 60)->default('operator_declared')->index();
            $table->string('freshness_status', 40)->default('unknown')->index();
            $table->timestamp('observed_at')->nullable()->index();
            $table->timestamp('valid_until')->nullable()->index();
            $table->json('attributes')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->json('source_refs')->nullable();
            $table->string('entity_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_reality_relationships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.strategic_reality.relationship.v1');
            $table->string('status', 40)->index();
            $table->uuid('source_entity_id')->nullable()->index();
            $table->uuid('target_entity_id')->nullable()->index();
            $table->string('relationship_type', 80)->index();
            $table->decimal('weight', 5, 2)->default(1);
            $table->json('attributes')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('relationship_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_strategic_assumptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.strategic_reality.assumption.v1');
            $table->string('status', 40)->index();
            $table->string('scope_type', 80)->nullable()->index();
            $table->string('scope_id', 180)->nullable()->index();
            $table->text('statement');
            $table->string('confidence', 40)->default('medium')->index();
            $table->json('invalidators')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->timestamp('review_at')->nullable()->index();
            $table->string('assumption_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_opportunity_signals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.strategic_reality.opportunity_signal.v1');
            $table->string('status', 40)->index();
            $table->string('opportunity_type', 80)->index();
            $table->string('domain', 80)->nullable()->index();
            $table->text('summary');
            $table->unsignedTinyInteger('leverage_score')->default(1)->index();
            $table->json('evidence_refs')->nullable();
            $table->json('metadata')->nullable();
            $table->string('opportunity_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_risk_signals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.strategic_reality.risk_signal.v1');
            $table->string('status', 40)->index();
            $table->string('risk_type', 80)->index();
            $table->string('severity', 40)->index();
            $table->text('summary');
            $table->json('mitigations')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->json('metadata')->nullable();
            $table->string('risk_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_priority_rankings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.strategic_reality.priority_ranking.v1');
            $table->string('status', 40)->index();
            $table->string('scope_type', 80)->nullable()->index();
            $table->string('scope_id', 180)->nullable()->index();
            $table->json('criteria');
            $table->json('ranked_options');
            $table->json('evidence_refs')->nullable();
            $table->string('ranking_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_resource_allocation_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.strategic_reality.resource_allocation.v1');
            $table->string('status', 40)->index();
            $table->json('resources');
            $table->json('allocation');
            $table->json('constraints')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('allocation_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_strategic_simulations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.strategic_reality.simulation.v1');
            $table->string('status', 40)->index();
            $table->string('mode', 40)->default('dry_run')->index();
            $table->json('options');
            $table->json('predicted_outcomes');
            $table->json('risks')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('simulation_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_strategic_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.strategic_reality.decision.v1');
            $table->string('status', 40)->index();
            $table->string('question_hash', 64)->index();
            $table->text('question');
            $table->json('reality_scope');
            $table->text('recommended_action');
            $table->text('why_now')->nullable();
            $table->text('why_not')->nullable();
            $table->decimal('confidence', 4, 2)->default(0.50);
            $table->json('options');
            $table->json('tradeoffs')->nullable();
            $table->json('assumption_refs')->nullable();
            $table->json('risk_refs')->nullable();
            $table->json('opportunity_refs')->nullable();
            $table->json('freshness')->nullable();
            $table->json('next_actions')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->json('claim_policy')->nullable();
            $table->string('decision_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('atlas_executive_briefings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('strategic_decision_id')->nullable()->index();
            $table->string('schema_version', 120)->default('atlas.strategic_reality.executive_briefing.v1');
            $table->string('status', 40)->index();
            $table->string('briefing_type', 80)->default('next_best_action')->index();
            $table->json('sections');
            $table->json('evidence_refs')->nullable();
            $table->string('briefing_hash', 64)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_executive_briefings');
        Schema::dropIfExists('atlas_strategic_decisions');
        Schema::dropIfExists('atlas_strategic_simulations');
        Schema::dropIfExists('atlas_resource_allocation_plans');
        Schema::dropIfExists('atlas_priority_rankings');
        Schema::dropIfExists('atlas_risk_signals');
        Schema::dropIfExists('atlas_opportunity_signals');
        Schema::dropIfExists('atlas_strategic_assumptions');
        Schema::dropIfExists('atlas_reality_relationships');
        Schema::dropIfExists('atlas_reality_entities');
    }
};
