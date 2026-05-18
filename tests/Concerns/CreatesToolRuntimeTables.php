<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesToolRuntimeTables
{
    protected function createToolRuntimeTables(): void
    {
        $this->dropToolRuntimeTables();

        Schema::create('ai_tool_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.tool_definition.v1');
            $table->string('uuid', 64)->unique();
            $table->string('tool_id', 120)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('tool_type', 40);
            $table->string('authority_group', 40);
            $table->string('risk_level', 40)->default('low');
            $table->json('input_schema');
            $table->json('output_schema');
            $table->json('auth_requirements')->nullable();
            $table->json('cost_profile')->nullable();
            $table->json('side_effects');
            $table->json('evidence_emitted');
            $table->string('health_status', 40)->default('unknown');
            $table->string('status', 40)->default('active');
            $table->timestamps();
        });

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
            $table->unsignedInteger('maturity_level')->default(1);
            $table->string('status', 40)->default('available');
            $table->timestamps();
            $table->unique(['tool_definition_id', 'capability_id'], 'uniq_ai_tool_capability_test');
        });

        Schema::create('ai_tool_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.tool_plan.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('mission_id')->nullable();
            $table->uuid('work_order_id')->nullable();
            $table->string('domain_id', 80)->nullable();
            $table->text('objective');
            $table->json('tools_considered');
            $table->json('tools_selected');
            $table->json('selection_reason');
            $table->json('rejected_tools');
            $table->json('safety_notes');
            $table->string('status', 40)->default('draft');
            $table->string('receipt_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('ai_tool_invocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.tool_invocation.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('tool_definition_id')->index();
            $table->uuid('capability_id')->nullable();
            $table->uuid('mission_id')->nullable();
            $table->uuid('work_order_id')->nullable();
            $table->string('invocation_status', 40)->default('planned');
            $table->string('input_hash', 64)->nullable();
            $table->string('output_hash', 64)->nullable();
            $table->string('policy_decision_ref', 64)->nullable();
            $table->json('evidence_refs')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_tool_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.tool_receipt.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('tool_invocation_id')->index();
            $table->string('receipt_type', 60)->default('tool_call');
            $table->string('status', 40);
            $table->json('evidence_refs');
            $table->string('receipt_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('ai_tool_health_checks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.tool_health_check.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('tool_definition_id')->index();
            $table->string('status', 40);
            $table->json('checked_requirements');
            $table->json('missing_requirements');
            $table->json('output_summary')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->string('health_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('ai_tool_validation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.tool_validation_run.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('tool_definition_id')->index();
            $table->string('validation_type', 60);
            $table->string('status', 40);
            $table->string('input_fixture_ref', 200)->nullable();
            $table->string('output_ref', 200)->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('validation_hash', 64)->unique();
            $table->timestamps();
        });
    }

    protected function dropToolRuntimeTables(): void
    {
        foreach ([
            'ai_tool_validation_runs',
            'ai_tool_health_checks',
            'ai_tool_receipts',
            'ai_tool_invocations',
            'ai_tool_plans',
            'ai_tool_capabilities',
            'ai_tool_definitions',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
