<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_evidence_packs')) {
            Schema::create('ai_evidence_packs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.evidence.evidence_pack.v1');
                $table->string('uuid', 64)->unique();
                $table->string('target_type', 60)->index();
                $table->string('target_id', 64)->index();
                $table->uuid('mission_id')->nullable()->index();
                $table->uuid('work_order_id')->nullable()->index();
                $table->string('domain_id', 80)->nullable()->index();
                $table->json('artifact_refs')->nullable();
                $table->json('source_refs')->nullable();
                $table->json('command_refs')->nullable();
                $table->json('test_refs')->nullable();
                $table->json('receipt_refs')->nullable();
                $table->json('blocker_refs')->nullable();
                $table->string('evidence_hash', 64)->unique();
                $table->string('status', 40)->default('open')->index();
                $table->timestamps();

                $table->index(['target_type', 'target_id'], 'idx_ai_evidence_packs_target');
                $table->index(['status', 'created_at'], 'idx_ai_evidence_packs_status_created');
            });
        }

        if (! Schema::hasTable('ai_receipts')) {
            Schema::create('ai_receipts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.evidence.receipt.v1');
                $table->string('uuid', 64)->unique();
                $table->string('receipt_type', 60)->index();
                $table->string('target_type', 60)->nullable()->index();
                $table->string('target_id', 64)->nullable()->index();
                $table->uuid('mission_id')->nullable()->index();
                $table->uuid('work_order_id')->nullable()->index();
                $table->string('actor_type', 40)->default('system')->index();
                $table->text('action');
                $table->string('input_hash', 64)->nullable();
                $table->string('output_hash', 64)->nullable();
                $table->json('evidence_refs')->nullable();
                $table->string('status', 40)->default('ok')->index();
                $table->string('receipt_hash', 64)->unique();
                $table->timestamps();

                $table->index(['target_type', 'target_id'], 'idx_ai_receipts_target');
                $table->index(['receipt_type', 'created_at'], 'idx_ai_receipts_type_created');
            });
        }

        if (! Schema::hasTable('ai_claims')) {
            Schema::create('ai_claims', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.evidence.claim.v1');
                $table->string('uuid', 64)->unique();
                $table->text('claim_text');
                $table->string('claim_type', 60)->index();
                $table->decimal('confidence', 4, 3)->nullable();
                $table->json('evidence_refs')->nullable();
                $table->string('verification_status', 40)->default('unverified')->index();
                $table->string('risk_level', 40)->default('low')->index();
                $table->uuid('mission_id')->nullable()->index();
                $table->string('domain_id', 80)->nullable()->index();
                $table->string('claim_hash', 64)->index();
                $table->timestamps();

                $table->index(['verification_status', 'risk_level'], 'idx_ai_claims_status_risk');
            });
        }

        if (! Schema::hasTable('ai_artifacts')) {
            Schema::create('ai_artifacts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.evidence.artifact.v1');
                $table->string('uuid', 64)->unique();
                $table->string('artifact_type', 60)->index();
                $table->string('name', 500);
                $table->text('path_or_ref')->nullable();
                $table->string('content_hash', 64)->nullable()->index();
                $table->json('metadata')->nullable();
                $table->string('status', 40)->default('registered')->index();
                $table->uuid('mission_id')->nullable()->index();
                $table->timestamps();

                $table->index(['artifact_type', 'created_at'], 'idx_ai_artifacts_type_created');
            });
        }

        if (! Schema::hasTable('ai_source_refs')) {
            Schema::create('ai_source_refs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.evidence.source_ref.v1');
                $table->string('uuid', 64)->unique();
                $table->string('source_type', 60)->index();
                $table->text('source_ref');
                $table->string('source_hash', 64)->nullable()->index();
                $table->decimal('source_quality', 4, 3)->nullable();
                $table->json('metadata')->nullable();
                $table->uuid('mission_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_gate_runs')) {
            Schema::create('ai_gate_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.evidence.gate_run.v1');
                $table->string('uuid', 64)->unique();
                $table->string('gate_type', 80)->index();
                $table->string('target_type', 60)->index();
                $table->string('target_id', 64)->index();
                $table->string('status', 40)->index();
                $table->json('checked_requirements');
                $table->json('missing_requirements');
                $table->json('evidence_refs')->nullable();
                $table->string('gate_hash', 64)->index();
                $table->uuid('mission_id')->nullable()->index();
                $table->timestamps();

                $table->index(['target_type', 'target_id'], 'idx_ai_gate_runs_target');
                $table->index(['gate_type', 'status'], 'idx_ai_gate_runs_type_status');
            });
        }

        if (! Schema::hasTable('ai_test_results')) {
            Schema::create('ai_test_results', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.evidence.test_result.v1');
                $table->string('uuid', 64)->unique();
                $table->string('test_scope', 80)->index();
                $table->text('command')->nullable();
                $table->string('status', 40)->index();
                $table->text('output_ref')->nullable();
                $table->string('output_hash', 64)->nullable()->index();
                $table->json('metadata')->nullable();
                $table->uuid('mission_id')->nullable()->index();
                $table->timestamps();

                $table->index(['test_scope', 'status'], 'idx_ai_test_results_scope_status');
            });
        }

        if (! Schema::hasTable('ai_operator_decisions')) {
            Schema::create('ai_operator_decisions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.evidence.operator_decision.v1');
                $table->string('uuid', 64)->unique();
                $table->string('decision_type', 60)->index();
                $table->string('target_type', 60)->index();
                $table->string('target_id', 64)->index();
                $table->string('decision', 40)->index();
                $table->text('reason')->nullable();
                $table->json('payload')->nullable();
                $table->string('decided_by', 160)->nullable();
                $table->timestamp('decided_at')->nullable()->index();
                $table->string('receipt_hash', 64)->nullable();
                $table->uuid('mission_id')->nullable()->index();
                $table->timestamps();

                $table->index(['target_type', 'target_id'], 'idx_ai_operator_decisions_target');
            });
        }

        if (! Schema::hasTable('ai_certifications')) {
            Schema::create('ai_certifications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.evidence.certification.v1');
                $table->string('uuid', 64)->unique();
                $table->string('target_type', 60)->index();
                $table->string('target_id', 64)->index();
                $table->uuid('mission_id')->nullable()->index();
                $table->string('status', 40)->index();
                $table->json('checked_requirements');
                $table->json('missing_requirements');
                $table->json('evidence_refs');
                $table->json('blocker_refs')->nullable();
                $table->string('certification_hash', 64)->unique();
                $table->timestamp('certified_at')->nullable()->index();
                $table->timestamps();

                $table->index(['target_type', 'target_id'], 'idx_ai_certifications_target');
                $table->index(['target_type', 'status'], 'idx_ai_certifications_target_status');
            });
        }

        if (! Schema::hasTable('ai_blockers')) {
            Schema::create('ai_blockers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.evidence.blocker.v1');
                $table->string('uuid', 64)->unique();
                $table->string('target_type', 60)->index();
                $table->string('target_id', 64)->index();
                $table->string('blocker_type', 60)->index();
                $table->string('severity', 40)->default('medium')->index();
                $table->text('reason');
                $table->json('evidence_refs')->nullable();
                $table->string('status', 40)->default('open')->index();
                $table->timestamp('resolved_at')->nullable();
                $table->uuid('mission_id')->nullable()->index();
                $table->timestamps();

                $table->index(['target_type', 'target_id'], 'idx_ai_blockers_target');
                $table->index(['status', 'severity'], 'idx_ai_blockers_status_severity');
            });
        }

        if (! Schema::hasTable('ai_audit_events')) {
            Schema::create('ai_audit_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.evidence.audit_event.v1');
                $table->string('uuid', 64)->unique();
                $table->string('event_type', 80)->index();
                $table->string('target_type', 60)->nullable()->index();
                $table->string('target_id', 64)->nullable()->index();
                $table->string('actor_type', 40)->default('system')->index();
                $table->json('payload');
                $table->string('event_hash', 64)->index();
                $table->uuid('mission_id')->nullable()->index();
                $table->timestamps();

                $table->index(['event_type', 'created_at'], 'idx_ai_audit_events_type_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_audit_events');
        Schema::dropIfExists('ai_blockers');
        Schema::dropIfExists('ai_certifications');
        Schema::dropIfExists('ai_operator_decisions');
        Schema::dropIfExists('ai_test_results');
        Schema::dropIfExists('ai_gate_runs');
        Schema::dropIfExists('ai_source_refs');
        Schema::dropIfExists('ai_artifacts');
        Schema::dropIfExists('ai_claims');
        Schema::dropIfExists('ai_receipts');
        Schema::dropIfExists('ai_evidence_packs');
    }
};
