<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_domain_manifests')) {
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
                $table->unsignedInteger('maturity_stage')->default(1)->index();
                $table->string('owner', 120)->nullable()->index();
                $table->string('manifest_hash', 64)->nullable()->index();
                $table->timestamps();

                $table->index(['status', 'maturity_stage'], 'idx_ai_domain_manifests_status_stage');
            });
        }

        if (! Schema::hasTable('ai_domain_capabilities')) {
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
                $table->string('risk_level', 40)->default('low')->index();
                $table->json('required_gates');
                $table->json('evidence_required');
                $table->unsignedInteger('maturity_level')->default(1)->index();
                $table->string('status', 40)->default('available')->index();
                $table->timestamps();

                $table->unique(['domain_manifest_id', 'capability_id'], 'uniq_ai_domain_capability');
            });
        }

        if (! Schema::hasTable('ai_domain_runtime_records')) {
            Schema::create('ai_domain_runtime_records', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.domain_runtime_record.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('mission_id')->nullable()->index();
                $table->uuid('work_order_id')->nullable()->index();
                $table->uuid('domain_manifest_id')->index();
                $table->string('domain_id', 80)->index();
                $table->string('runtime_status', 40)->default('planned')->index();
                $table->json('selected_capabilities');
                $table->json('execution_plan');
                $table->json('evidence_refs')->nullable();
                $table->json('blockers')->nullable();
                $table->string('receipt_hash', 64)->nullable()->index();
                $table->timestamps();

                $table->index(['domain_id', 'runtime_status'], 'idx_ai_domain_runtime_domain_status');
                $table->index(['mission_id', 'runtime_status'], 'idx_ai_domain_runtime_mission_status');
            });
        }

        if (! Schema::hasTable('ai_domain_handoffs')) {
            Schema::create('ai_domain_handoffs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.domain_handoff.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('mission_id')->nullable()->index();
                $table->uuid('work_order_id')->nullable()->index();
                $table->string('source_domain_id', 80)->index();
                $table->string('target_domain_id', 80)->index();
                $table->text('reason');
                $table->json('context_pack');
                $table->json('evidence_refs');
                $table->json('expected_output');
                $table->json('blockers')->nullable();
                $table->string('status', 40)->default('pending')->index();
                $table->string('receipt_hash', 64)->unique();
                $table->timestamps();

                $table->index(['source_domain_id', 'target_domain_id'], 'idx_ai_domain_handoffs_source_target');
            });
        }

        if (! Schema::hasTable('ai_domain_maturity_assessments')) {
            Schema::create('ai_domain_maturity_assessments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.ai.domain_maturity_assessment.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('domain_manifest_id')->index();
                $table->unsignedInteger('maturity_stage');
                $table->json('checked_requirements');
                $table->json('missing_requirements');
                $table->json('evidence_refs');
                $table->string('status', 40)->index();
                $table->timestamp('assessed_at')->nullable()->index();
                $table->string('assessment_hash', 64)->unique();
                $table->timestamps();

                $table->index(['domain_manifest_id', 'maturity_stage'], 'idx_ai_domain_maturity_domain_stage');
                $table->index(['domain_manifest_id', 'status'], 'idx_ai_domain_maturity_domain_status');
            });
        }
    }

    public function down(): void
    {
        // Repair migration only: never drops shared Domain Runtime tables.
    }
};
