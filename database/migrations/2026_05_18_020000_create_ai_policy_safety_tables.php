<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_policy_profiles')) {
            Schema::create('ai_policy_profiles', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.policy_profile.v1');
                $table->string('uuid', 64)->unique();
                $table->string('policy_id', 160)->unique();
                $table->string('name', 200);
                $table->string('scope_type', 40)->index();
                $table->string('scope_ref', 200)->nullable()->index();
                $table->string('autonomy_level', 40)->index();
                $table->string('risk_tolerance', 40)->index();
                $table->json('approval_rules');
                $table->json('tool_permissions');
                $table->json('provider_permissions')->nullable();
                $table->json('data_permissions')->nullable();
                $table->json('budget_defaults');
                $table->json('forbidden_actions');
                $table->string('status', 40)->default('active')->index();
                $table->timestamps();

                $table->index(['scope_type', 'scope_ref'], 'idx_ai_policy_profiles_scope');
                $table->index(['scope_type', 'status'], 'idx_ai_policy_profiles_scope_status');
            });
        }

        if (! Schema::hasTable('ai_permission_gates')) {
            Schema::create('ai_permission_gates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.permission_gate.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('mission_id')->nullable()->index();
                $table->uuid('work_order_id')->nullable()->index();
                $table->string('domain_id', 80)->nullable()->index();
                $table->string('tool_id', 120)->nullable()->index();
                $table->string('gate_type', 60)->index();
                $table->string('requested_action', 200)->index();
                $table->string('risk_level', 40)->index();
                $table->string('decision', 40)->index();
                $table->json('reasons');
                $table->json('required_approvals')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->string('receipt_hash', 64)->index();
                $table->timestamps();

                $table->index(['decision', 'created_at'], 'idx_ai_permission_gates_decision_created');
                $table->index(['requested_action', 'decision'], 'idx_ai_permission_gates_action_decision');
            });
        }

        if (! Schema::hasTable('ai_approval_requests')) {
            Schema::create('ai_approval_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.approval_request.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('mission_id')->nullable()->index();
                $table->uuid('work_order_id')->nullable()->index();
                $table->string('approval_type', 80)->index();
                $table->string('requested_action', 200)->index();
                $table->string('requester_type', 60)->default('system')->index();
                $table->string('status', 40)->default('pending')->index();
                $table->string('approver', 160)->nullable();
                $table->text('reason')->nullable();
                $table->json('decision_payload')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('decided_at')->nullable()->index();
                $table->string('receipt_hash', 64)->nullable()->index();
                $table->timestamps();

                $table->index(['status', 'created_at'], 'idx_ai_approval_requests_status_created');
                $table->index(['approval_type', 'status'], 'idx_ai_approval_requests_type_status');
            });
        }

        if (! Schema::hasTable('ai_budget_envelopes')) {
            Schema::create('ai_budget_envelopes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.budget_envelope.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('mission_id')->nullable()->index();
                $table->uuid('work_order_id')->nullable()->index();
                $table->string('scope_type', 40)->index();
                $table->string('scope_ref', 200)->nullable()->index();
                $table->decimal('max_cost', 18, 6)->nullable();
                $table->unsignedBigInteger('max_tokens')->nullable();
                $table->unsignedBigInteger('max_runtime_seconds')->nullable();
                $table->unsignedBigInteger('max_tool_calls')->nullable();
                $table->unsignedBigInteger('max_external_calls')->nullable();
                $table->decimal('current_cost', 18, 6)->nullable()->default(0);
                $table->unsignedBigInteger('current_tokens')->nullable()->default(0);
                $table->unsignedBigInteger('current_runtime_seconds')->nullable()->default(0);
                $table->unsignedBigInteger('current_tool_calls')->nullable()->default(0);
                $table->string('status', 40)->default('open')->index();
                $table->timestamps();

                $table->index(['scope_type', 'status'], 'idx_ai_budget_envelopes_scope_status');
            });
        }

        if (! Schema::hasTable('ai_risk_assessments')) {
            Schema::create('ai_risk_assessments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.risk_assessment.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('mission_id')->nullable()->index();
                $table->uuid('work_order_id')->nullable()->index();
                $table->string('target_type', 80)->index();
                $table->string('target_ref', 200)->nullable()->index();
                $table->string('risk_level', 40)->index();
                $table->json('risk_factors');
                $table->json('mitigations');
                $table->string('residual_risk', 40)->nullable()->index();
                $table->string('assessor_type', 60)->default('system')->index();
                $table->string('assessment_hash', 64)->index();
                $table->timestamps();

                $table->index(['target_type', 'risk_level'], 'idx_ai_risk_assessments_target_level');
            });
        }

        if (! Schema::hasTable('ai_safety_decisions')) {
            Schema::create('ai_safety_decisions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.safety_decision.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('mission_id')->nullable()->index();
                $table->uuid('work_order_id')->nullable()->index();
                $table->string('decision_type', 80)->index();
                $table->string('requested_action', 200)->index();
                $table->string('decision', 40)->index();
                $table->uuid('risk_assessment_id')->nullable()->index();
                $table->uuid('policy_profile_id')->nullable()->index();
                $table->json('reasons');
                $table->string('receipt_hash', 64)->index();
                $table->timestamps();

                $table->index(['decision', 'created_at'], 'idx_ai_safety_decisions_decision_created');
                $table->index(['requested_action', 'decision'], 'idx_ai_safety_decisions_action_decision');
            });
        }

        if (! Schema::hasTable('ai_forbidden_actions')) {
            Schema::create('ai_forbidden_actions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.forbidden_action.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('policy_profile_id')->nullable()->index();
                $table->string('action_key', 200)->index();
                $table->text('description');
                $table->string('scope_type', 40)->nullable()->index();
                $table->string('scope_ref', 200)->nullable()->index();
                $table->string('severity', 40)->default('high')->index();
                $table->string('status', 40)->default('active')->index();
                $table->timestamps();

                $table->index(['action_key', 'status'], 'idx_ai_forbidden_actions_action_status');
                $table->index(['policy_profile_id', 'status'], 'idx_ai_forbidden_actions_profile_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_forbidden_actions');
        Schema::dropIfExists('ai_safety_decisions');
        Schema::dropIfExists('ai_risk_assessments');
        Schema::dropIfExists('ai_budget_envelopes');
        Schema::dropIfExists('ai_approval_requests');
        Schema::dropIfExists('ai_permission_gates');
        Schema::dropIfExists('ai_policy_profiles');
    }
};
