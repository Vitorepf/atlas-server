<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_tool_definitions')) {
            Schema::create('ai_tool_definitions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.tool_definition.v1');
                $table->string('uuid', 64)->unique();
                $table->string('tool_id', 120)->unique();
                $table->string('name', 200);
                $table->text('description')->nullable();
                $table->string('tool_type', 40)->index();
                $table->string('authority_group', 40)->index();
                $table->string('risk_level', 40)->default('low')->index();
                $table->json('input_schema');
                $table->json('output_schema');
                $table->json('auth_requirements')->nullable();
                $table->json('cost_profile')->nullable();
                $table->json('side_effects');
                $table->json('evidence_emitted');
                $table->string('health_status', 40)->default('unknown')->index();
                $table->string('status', 40)->default('active')->index();
                $table->timestamps();

                $table->index(['tool_type', 'status'], 'idx_ai_tool_definitions_type_status');
                $table->index(['authority_group', 'risk_level'], 'idx_ai_tool_definitions_auth_risk');
            });
        }

        if (! Schema::hasTable('ai_tool_capabilities')) {
            Schema::create('ai_tool_capabilities', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.tool_capability.v1');
                $table->string('uuid', 64)->unique();
                $table->string('capability_id', 120);
                $table->uuid('tool_definition_id')->index();
                $table->string('name', 200);
                $table->text('description')->nullable();
                $table->json('input_schema');
                $table->json('output_schema');
                $table->json('required_policy_gates');
                $table->json('required_evidence');
                $table->unsignedInteger('maturity_level')->default(1)->index();
                $table->string('status', 40)->default('available')->index();
                $table->timestamps();

                $table->unique(['tool_definition_id', 'capability_id'], 'uniq_ai_tool_capability');
            });
        }

        if (! Schema::hasTable('ai_tool_plans')) {
            Schema::create('ai_tool_plans', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.tool_plan.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('mission_id')->nullable()->index();
                $table->uuid('work_order_id')->nullable()->index();
                $table->string('domain_id', 80)->nullable()->index();
                $table->text('objective');
                $table->json('tools_considered');
                $table->json('tools_selected');
                $table->json('selection_reason');
                $table->json('rejected_tools');
                $table->json('safety_notes');
                $table->string('status', 40)->default('draft')->index();
                $table->string('receipt_hash', 64)->nullable()->index();
                $table->timestamps();

                $table->index(['mission_id', 'status'], 'idx_ai_tool_plans_mission_status');
            });
        }

        if (! Schema::hasTable('ai_tool_invocations')) {
            Schema::create('ai_tool_invocations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.tool_invocation.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('tool_definition_id')->index();
                $table->uuid('capability_id')->nullable()->index();
                $table->uuid('mission_id')->nullable()->index();
                $table->uuid('work_order_id')->nullable()->index();
                $table->string('invocation_status', 40)->default('planned')->index();
                $table->string('input_hash', 64)->nullable()->index();
                $table->string('output_hash', 64)->nullable()->index();
                $table->string('policy_decision_ref', 64)->nullable()->index();
                $table->json('evidence_refs')->nullable();
                $table->text('error_summary')->nullable();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('finished_at')->nullable()->index();
                $table->timestamps();

                $table->index(['tool_definition_id', 'invocation_status'], 'idx_ai_tool_invocations_tool_status');
                $table->index(['mission_id', 'invocation_status'], 'idx_ai_tool_invocations_mission_status');
            });
        }

        if (! Schema::hasTable('ai_tool_receipts')) {
            Schema::create('ai_tool_receipts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.tool_receipt.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('tool_invocation_id')->index();
                $table->string('receipt_type', 60)->default('tool_call')->index();
                $table->string('status', 40)->index();
                $table->json('evidence_refs');
                $table->string('receipt_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_tool_health_checks')) {
            Schema::create('ai_tool_health_checks', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.tool_health_check.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('tool_definition_id')->index();
                $table->string('status', 40)->index();
                $table->json('checked_requirements');
                $table->json('missing_requirements');
                $table->json('output_summary')->nullable();
                $table->timestamp('checked_at')->nullable()->index();
                $table->string('health_hash', 64)->unique();
                $table->timestamps();

                $table->index(['tool_definition_id', 'status'], 'idx_ai_tool_health_tool_status');
            });
        }

        if (! Schema::hasTable('ai_tool_validation_runs')) {
            Schema::create('ai_tool_validation_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.tool_validation_run.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('tool_definition_id')->index();
                $table->string('validation_type', 60)->index();
                $table->string('status', 40)->index();
                $table->string('input_fixture_ref', 200)->nullable();
                $table->string('output_ref', 200)->nullable();
                $table->json('evidence_refs')->nullable();
                $table->string('validation_hash', 64)->unique();
                $table->timestamps();

                $table->index(['tool_definition_id', 'status'], 'idx_ai_tool_validation_tool_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_validation_runs');
        Schema::dropIfExists('ai_tool_health_checks');
        Schema::dropIfExists('ai_tool_receipts');
        Schema::dropIfExists('ai_tool_invocations');
        Schema::dropIfExists('ai_tool_plans');
        Schema::dropIfExists('ai_tool_capabilities');
        Schema::dropIfExists('ai_tool_definitions');
    }
};
