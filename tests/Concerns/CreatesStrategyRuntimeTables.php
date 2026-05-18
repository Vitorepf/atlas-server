<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesStrategyRuntimeTables
{
    protected function createStrategyRuntimeTables(): void
    {
        $this->dropStrategyRuntimeTables();

        Schema::create('ai_strategy_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.strategy.run.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('mission_id')->nullable()->index();
            $table->uuid('work_order_id')->nullable()->index();
            $table->string('run_kind', 60)->index();
            $table->string('status', 40)->default('open')->index();
            $table->text('summary')->nullable();
            $table->json('inputs')->nullable();
            $table->json('outputs')->nullable();
            $table->json('blockers')->nullable();
            $table->string('next_action', 200)->nullable();
            $table->string('receipt_hash', 64)->unique();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_opportunities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.strategy.opportunity.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('strategy_run_id')->nullable()->index();
            $table->uuid('mission_id')->nullable()->index();
            $table->string('opportunity_id', 120)->index();
            $table->string('title', 500);
            $table->text('problem');
            $table->text('icp');
            $table->text('pain');
            $table->string('urgency', 40)->default('medium')->index();
            $table->json('market');
            $table->json('competitors');
            $table->json('risks');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('status', 40)->default('proposed')->index();
            $table->string('opportunity_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('ai_venture_blueprints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.strategy.venture_blueprint.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('opportunity_id')->index();
            $table->uuid('strategy_run_id')->nullable()->index();
            $table->string('blueprint_id', 120)->index();
            $table->string('title', 500);
            $table->json('product');
            $table->json('gtm');
            $table->json('unit_economics');
            $table->json('hiring_plan');
            $table->json('operations');
            $table->json('milestones')->nullable();
            $table->json('risks')->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->string('blueprint_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('ai_market_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.strategy.market_model.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('opportunity_id')->nullable()->index();
            $table->uuid('strategy_run_id')->nullable()->index();
            $table->string('model_id', 120)->index();
            $table->string('title', 500);
            $table->decimal('tam', 20, 2)->nullable();
            $table->decimal('sam', 20, 2)->nullable();
            $table->decimal('som', 20, 2)->nullable();
            $table->string('currency', 8)->default('USD');
            $table->json('assumptions');
            $table->json('sources');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->string('model_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('ai_unit_economics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.strategy.unit_economics.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('venture_blueprint_id')->nullable()->index();
            $table->uuid('opportunity_id')->nullable()->index();
            $table->uuid('strategy_run_id')->nullable()->index();
            $table->string('unit_id', 120)->index();
            $table->decimal('cac', 18, 4)->nullable();
            $table->decimal('ltv', 18, 4)->nullable();
            $table->decimal('arpu', 18, 4)->nullable();
            $table->decimal('gross_margin', 6, 4)->nullable();
            $table->decimal('payback_months', 8, 2)->nullable();
            $table->decimal('churn_monthly', 6, 4)->nullable();
            $table->string('currency', 8)->default('USD');
            $table->json('assumptions');
            $table->json('breakdown')->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->string('unit_economics_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('ai_experiment_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.strategy.experiment_plan.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('strategy_run_id')->nullable()->index();
            $table->uuid('opportunity_id')->nullable()->index();
            $table->uuid('venture_blueprint_id')->nullable()->index();
            $table->string('experiment_id', 120)->index();
            $table->text('hypothesis');
            $table->string('hypothesis_kind', 60)->default('value')->index();
            $table->json('success_metric');
            $table->json('design');
            $table->json('sample')->nullable();
            $table->decimal('budget_max', 18, 2)->nullable();
            $table->string('currency', 8)->default('USD');
            $table->json('duration')->nullable();
            $table->json('safety_gates')->nullable();
            $table->string('status', 40)->default('planned')->index();
            $table->json('decision')->nullable();
            $table->json('result')->nullable();
            $table->string('experiment_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('ai_strategy_memos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.strategy.memo.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('strategy_run_id')->nullable()->index();
            $table->uuid('opportunity_id')->nullable()->index();
            $table->uuid('venture_blueprint_id')->nullable()->index();
            $table->string('memo_kind', 60)->index();
            $table->string('title', 500);
            $table->text('decision');
            $table->json('rationale');
            $table->json('assumptions')->nullable();
            $table->json('experiment_refs')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->json('next_actions');
            $table->string('status', 40)->default('draft')->index();
            $table->string('memo_hash', 64)->unique();
            $table->timestamps();
        });
    }

    protected function dropStrategyRuntimeTables(): void
    {
        foreach ([
            'ai_strategy_memos',
            'ai_experiment_plans',
            'ai_unit_economics',
            'ai_market_models',
            'ai_venture_blueprints',
            'ai_opportunities',
            'ai_strategy_runs',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
