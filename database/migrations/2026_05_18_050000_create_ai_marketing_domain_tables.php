<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_marketing_runs')) {
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

                $table->index(['status', 'created_at'], 'idx_ai_marketing_runs_status_created');
            });
        }

        if (! Schema::hasTable('ai_marketing_artifacts')) {
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

                $table->index(['marketing_run_id', 'artifact_type'], 'idx_ai_marketing_artifacts_run_type');
            });
        }

        if (! Schema::hasTable('ai_marketing_experiments')) {
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

                $table->index(['marketing_run_id', 'status'], 'idx_ai_marketing_experiments_run_status');
            });
        }

        if (! Schema::hasTable('ai_marketing_approval_gates')) {
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

                $table->index(['marketing_run_id', 'gate_type'], 'idx_ai_marketing_approval_gates_run_type');
                $table->index(['status', 'created_at'], 'idx_ai_marketing_approval_gates_status_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_marketing_approval_gates');
        Schema::dropIfExists('ai_marketing_experiments');
        Schema::dropIfExists('ai_marketing_artifacts');
        Schema::dropIfExists('ai_marketing_runs');
    }
};
