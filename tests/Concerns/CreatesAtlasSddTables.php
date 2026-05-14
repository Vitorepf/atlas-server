<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors the canonical SDD core tables exactly so tests fail when production
 * schema drifts. See `database/migrations/2026_05_13_09*_create_atlas_sdd_*`.
 */
trait CreatesAtlasSddTables
{
    protected function createAtlasSddTables(): void
    {
        $this->dropAtlasSddTables();

        Schema::create('atlas_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id', 80)->nullable();
            $table->string('user_id', 80)->nullable();
            $table->uuid('project_id')->nullable();
            $table->uuid('work_item_id')->nullable();
            $table->text('raw_input');
            $table->text('interpreted_intent')->nullable();
            $table->string('domain', 80)->nullable();
            $table->string('status', 32)->default('received');
            $table->string('risk_level', 16)->default('medium');
            $table->string('confidence_class', 32)->nullable();
            $table->json('routing_metadata_json')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_specs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operation_id');
            $table->uuid('project_id')->nullable();
            $table->uuid('work_item_id')->nullable();
            $table->string('title', 255);
            $table->string('type', 64)->default('feature');
            $table->string('status', 32)->default('draft');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('risk_level', 16)->default('medium');
            $table->string('content_hash', 64);
            $table->json('content_json');
            $table->text('content_markdown')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->uuid('superseded_by_id')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('spec_id');
            $table->string('code', 32);
            $table->text('text');
            $table->string('priority', 16)->default('must');
            $table->string('status', 32)->default('open');
            $table->json('metadata_json')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_acceptance_criteria', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('requirement_id');
            $table->string('code', 32);
            $table->text('given')->nullable();
            $table->text('when');
            $table->text('then');
            $table->json('metadata_json')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_assumptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('spec_id');
            $table->text('text');
            $table->string('confidence_class', 32);
            $table->boolean('blocking')->default(false);
            $table->json('evidence_json')->default('[]');
            $table->json('clarification_questions_json')->default('[]');
            $table->string('resolved_status', 32)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('spec_id');
            $table->string('content_hash', 64);
            $table->json('content_json');
            $table->json('target_files_json')->default('[]');
            $table->json('forbidden_files_json')->default('[]');
            $table->json('hot_file_ownership_json')->default('{}');
            $table->json('test_plan_json')->default('[]');
            $table->json('rollback_plan_json')->default('{}');
            $table->string('status', 32)->default('draft');
            $table->timestamps();
        });

        Schema::create('atlas_sdd_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('plan_id');
            $table->uuid('spec_id');
            $table->string('code', 32);
            $table->string('type', 64)->default('implement');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->json('depends_on_json')->default('[]');
            $table->json('allowed_files_json')->default('[]');
            $table->json('forbidden_files_json')->default('[]');
            $table->json('acceptance_refs_json')->default('[]');
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('order_index')->default(0);
            $table->timestamps();
        });

        Schema::create('atlas_decision_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('receipt_id', 64)->unique();
            $table->uuid('operation_id');
            $table->uuid('spec_id')->nullable();
            $table->uuid('plan_id')->nullable();
            $table->string('autonomy_level', 32);
            $table->json('allowed_actions_json')->default('[]');
            $table->json('forbidden_actions_json')->default('[]');
            $table->json('allowed_files_json')->default('[]');
            $table->json('forbidden_files_json')->default('[]');
            $table->json('required_gates_json')->default('[]');
            $table->json('task_ids_json')->default('[]');
            $table->json('context_pack_refs_json')->default('[]');
            $table->string('input_hash', 64);
            $table->string('output_hash', 64);
            $table->string('signature', 128)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_spec_traceability', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('spec_id');
            $table->uuid('requirement_id')->nullable();
            $table->uuid('acceptance_criteria_id')->nullable();
            $table->uuid('task_id')->nullable();
            $table->string('file_path', 500)->nullable();
            $table->string('test_path', 500)->nullable();
            $table->uuid('evidence_event_id')->nullable();
            $table->string('link_type', 64)->default('implements');
            $table->string('confidence', 32)->default('confirmed');
            $table->json('metadata_json')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_sdd_drift_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('spec_id')->nullable();
            $table->uuid('operation_id')->nullable();
            $table->string('status', 16);
            $table->json('drift_findings_json')->default('[]');
            $table->json('summary_json')->default('{}');
            $table->string('source', 64)->default('scheduled');
            $table->string('detector_version', 32)->default('atlas.sdd_drift.v1');
            $table->timestamps();
        });

        Schema::create('atlas_sdd_learning_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operation_id')->nullable();
            $table->uuid('drift_report_id')->nullable();
            $table->string('proposal_type', 64);
            $table->text('summary');
            $table->json('observation_json')->default('{}');
            $table->json('proposal_json')->default('{}');
            $table->json('evidence_refs_json')->default('[]');
            $table->string('status', 32)->default('proposed');
            $table->string('decided_by', 80)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamps();
        });
    }

    protected function dropAtlasSddTables(): void
    {
        Schema::dropIfExists('atlas_sdd_learning_proposals');
        Schema::dropIfExists('atlas_sdd_drift_reports');
        Schema::dropIfExists('atlas_spec_traceability');
        Schema::dropIfExists('atlas_decision_receipts');
        Schema::dropIfExists('atlas_sdd_tasks');
        Schema::dropIfExists('atlas_plans');
        Schema::dropIfExists('atlas_assumptions');
        Schema::dropIfExists('atlas_acceptance_criteria');
        Schema::dropIfExists('atlas_requirements');
        Schema::dropIfExists('atlas_specs');
        Schema::dropIfExists('atlas_operations');
    }
}
