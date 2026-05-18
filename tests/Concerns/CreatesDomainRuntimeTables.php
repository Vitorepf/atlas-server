<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesDomainRuntimeTables
{
    protected function createDomainRuntimeTables(): void
    {
        $this->dropDomainRuntimeTables();

        Schema::create('ai_domain_manifests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.domain_manifest.v1');
            $table->string('uuid', 64)->unique();
            $table->string('domain_id', 80)->unique();
            $table->string('name', 200);
            $table->string('status', 40)->default('scaffold')->index();
            $table->json('charter');
            $table->json('ontology');
            $table->json('departments');
            $table->json('flow_profiles');
            $table->json('tools_allowed');
            $table->json('policy_profile')->nullable();
            $table->json('memory_scope')->nullable();
            $table->json('evidence_schema');
            $table->json('quality_gates');
            $table->json('handoff_rules');
            $table->json('delivery_types');
            $table->json('metrics');
            $table->json('forbidden_actions');
            $table->unsignedInteger('maturity_stage')->default(1);
            $table->string('owner', 120)->nullable();
            $table->string('manifest_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('ai_domain_capabilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.domain_capability.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('domain_manifest_id')->index();
            $table->string('capability_id', 120);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->json('input_schema');
            $table->json('output_schema');
            $table->json('allowed_tools');
            $table->string('risk_level', 40)->default('low');
            $table->json('required_gates');
            $table->json('evidence_required');
            $table->unsignedInteger('maturity_level')->default(1);
            $table->string('status', 40)->default('available');
            $table->timestamps();
            $table->unique(['domain_manifest_id', 'capability_id'], 'uniq_ai_domain_capability_test');
        });

        Schema::create('ai_domain_runtime_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.domain_runtime_record.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('mission_id')->nullable();
            $table->uuid('work_order_id')->nullable();
            $table->uuid('domain_manifest_id')->index();
            $table->string('domain_id', 80);
            $table->string('runtime_status', 40)->default('planned');
            $table->json('selected_capabilities');
            $table->json('execution_plan');
            $table->json('evidence_refs')->nullable();
            $table->json('blockers')->nullable();
            $table->string('receipt_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('ai_domain_handoffs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.domain_handoff.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('mission_id')->nullable();
            $table->uuid('work_order_id')->nullable();
            $table->string('source_domain_id', 80);
            $table->string('target_domain_id', 80);
            $table->text('reason');
            $table->json('context_pack');
            $table->json('evidence_refs');
            $table->json('expected_output');
            $table->json('blockers')->nullable();
            $table->string('status', 40)->default('pending');
            $table->string('receipt_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('ai_domain_maturity_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.domain_maturity_assessment.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('domain_manifest_id')->index();
            $table->unsignedInteger('maturity_stage');
            $table->json('checked_requirements');
            $table->json('missing_requirements');
            $table->json('evidence_refs');
            $table->string('status', 40);
            $table->timestamp('assessed_at')->nullable();
            $table->string('assessment_hash', 64)->unique();
            $table->timestamps();
        });
    }

    protected function dropDomainRuntimeTables(): void
    {
        foreach ([
            'ai_domain_maturity_assessments',
            'ai_domain_handoffs',
            'ai_domain_runtime_records',
            'ai_domain_capabilities',
            'ai_domain_manifests',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
