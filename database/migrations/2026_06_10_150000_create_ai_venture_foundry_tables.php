<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_venture_ideas')) {
            Schema::create('ai_venture_ideas', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.venture.idea.v1');
                $table->string('uuid', 64)->unique();
                $table->string('idea_id', 120)->unique();
                $table->string('title', 500);
                $table->text('problem');
                $table->text('icp');
                $table->text('pain');
                $table->string('urgency', 40)->default('medium')->index();
                $table->string('source', 60)->default('operator')->index();
                $table->uuid('opportunity_id')->nullable()->index();
                $table->decimal('market_size_usd', 20, 2)->nullable();
                $table->unsignedTinyInteger('pain_severity')->default(3);
                $table->unsignedTinyInteger('founder_fit')->default(3);
                $table->unsignedTinyInteger('sovereignty_fit')->default(3);
                $table->decimal('score', 6, 2)->default(0)->index();
                $table->json('score_breakdown')->nullable();
                $table->string('status', 40)->default('proposed')->index();
                $table->uuid('promoted_venture_id')->nullable()->index();
                $table->string('idea_hash', 64)->unique();
                $table->timestamps();

                $table->index(['status', 'score'], 'idx_ai_venture_ideas_status_score');
            });
        }

        if (! Schema::hasTable('ai_ventures')) {
            Schema::create('ai_ventures', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.venture.v1');
                $table->string('uuid', 64)->unique();
                $table->string('venture_id', 120)->unique();
                $table->string('name', 300);
                $table->text('thesis');
                $table->string('status', 40)->default('active')->index();
                $table->string('stage', 12)->default('S0')->index();
                $table->string('stage_key', 60)->default('ideation')->index();
                $table->uuid('idea_id')->nullable()->index();
                $table->uuid('opportunity_id')->nullable()->index();
                $table->uuid('venture_blueprint_id')->nullable()->index();
                $table->json('north_star')->nullable();
                $table->json('founding_inputs')->nullable();
                $table->decimal('target_arr_usd', 20, 2)->default(100000000);
                $table->string('venture_hash', 64)->unique();
                $table->timestamps();

                $table->index(['status', 'stage'], 'idx_ai_ventures_status_stage');
            });
        }

        if (! Schema::hasTable('ai_venture_business_rules')) {
            Schema::create('ai_venture_business_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.venture.business_rule.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('venture_id')->index();
                $table->string('rule_id', 160);
                $table->string('category', 60)->index();
                $table->text('statement');
                $table->text('rationale')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->string('status', 40)->default('active')->index();
                $table->uuid('superseded_by')->nullable();
                $table->string('rule_hash', 64)->unique();
                $table->timestamps();

                $table->unique(['venture_id', 'rule_id', 'version'], 'uniq_ai_venture_rules_version');
                $table->index(['venture_id', 'status'], 'idx_ai_venture_rules_venture_status');
            });
        }

        if (! Schema::hasTable('ai_venture_metric_observations')) {
            Schema::create('ai_venture_metric_observations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.venture.metric_observation.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('venture_id')->index();
                $table->string('metric_key', 120)->index();
                $table->decimal('value', 24, 6);
                $table->string('unit', 40)->nullable();
                $table->string('currency', 8)->nullable();
                $table->string('source', 120)->default('operator');
                $table->text('note')->nullable();
                $table->timestamp('observed_at')->index();
                $table->string('observation_hash', 64)->unique();
                $table->timestamps();

                $table->index(['venture_id', 'metric_key', 'observed_at'], 'idx_ai_venture_metrics_lookup');
            });
        }

        if (! Schema::hasTable('ai_venture_strategy_reviews')) {
            Schema::create('ai_venture_strategy_reviews', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.venture.strategy_review.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('venture_id')->index();
                $table->string('review_kind', 60)->default('stage_review')->index();
                $table->string('current_stage', 12);
                $table->string('recommended_stage', 12);
                $table->boolean('stage_applied')->default(false);
                $table->json('gate_results');
                $table->json('gaps');
                $table->json('next_actions');
                $table->json('playbook')->nullable();
                $table->uuid('strategy_memo_id')->nullable()->index();
                $table->string('status', 40)->default('closed')->index();
                $table->string('review_hash', 64)->unique();
                $table->timestamps();

                $table->index(['venture_id', 'created_at'], 'idx_ai_venture_reviews_venture_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_venture_strategy_reviews');
        Schema::dropIfExists('ai_venture_metric_observations');
        Schema::dropIfExists('ai_venture_business_rules');
        Schema::dropIfExists('ai_ventures');
        Schema::dropIfExists('ai_venture_ideas');
    }
};
