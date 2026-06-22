<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesMarketingDomainTables
{
    protected function createMarketingDomainTables(): void
    {
        $this->dropMarketingDomainTables();

        Schema::create('ai_marketing_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.marketing_run.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('mission_id')->nullable()->index();
            $table->uuid('work_order_id')->nullable()->index();
            $table->string('product', 200);
            $table->text('objective');
            $table->string('status', 40)->default('planned')->index();
            $table->string('certification_status', 40)->nullable()->index();
            $table->json('missing_requirements')->nullable();
            $table->string('certification_hash', 64)->nullable()->index();
            $table->string('evidence_pack_hash', 64)->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('ai_marketing_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.marketing_artifact.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('marketing_run_id')->index();
            $table->string('artifact_type', 60)->index();
            $table->string('title', 300);
            $table->json('payload');
            $table->string('status', 40)->default('draft')->index();
            $table->string('artifact_hash', 64)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_marketing_experiments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.marketing_experiment.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('marketing_run_id')->index();
            $table->string('name', 200);
            $table->text('hypothesis');
            $table->string('primary_metric', 160);
            $table->text('success_criterion');
            $table->string('decision_rule', 200);
            $table->json('variants');
            $table->json('guardrails')->nullable();
            $table->string('status', 40)->default('proposed')->index();
            $table->string('experiment_hash', 64)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_marketing_decision_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.marketing_decision.v1');
            $table->string('campaign_ref', 200)->nullable()->index();
            $table->uuid('vsl_asset_id')->nullable()->index();
            $table->string('niche', 80)->nullable()->index();
            $table->string('stage', 60)->index();
            $table->text('symptom');
            $table->string('action', 60)->index();
            $table->string('lever', 200);
            $table->string('vsl_block', 60)->nullable();
            $table->text('numeric_rule')->nullable();
            $table->text('predicted_effect')->nullable();
            $table->json('offer_state')->nullable();
            $table->timestamp('decided_at')->nullable()->index();
            $table->string('outcome', 30)->nullable()->index();
            $table->json('result_metrics')->nullable();
            $table->text('result_note')->nullable();
            $table->timestamp('measured_at')->nullable();
            $table->string('decision_hash', 64)->index();
            $table->timestamps();
        });

        Schema::create('ai_marketing_approval_gates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.marketing_approval_gate.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('marketing_run_id')->index();
            $table->uuid('artifact_id')->nullable()->index();
            $table->uuid('experiment_id')->nullable()->index();
            $table->string('gate_type', 60)->index();
            $table->string('requested_action', 200);
            $table->decimal('proposed_budget', 18, 6)->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('status', 40)->default('pending')->index();
            $table->string('approver', 160)->nullable();
            $table->text('reason')->nullable();
            $table->uuid('policy_approval_request_id')->nullable()->index();
            $table->string('receipt_hash', 64)->index();
            $table->timestamps();
        });
    }

    protected function dropMarketingDomainTables(): void
    {
        foreach ([
            'ai_marketing_decision_ledger',
            'ai_marketing_approval_gates',
            'ai_marketing_experiments',
            'ai_marketing_artifacts',
            'ai_marketing_runs',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
