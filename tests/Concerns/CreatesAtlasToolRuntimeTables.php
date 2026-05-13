<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAtlasToolRuntimeTables
{
    protected function createAtlasToolRuntimeTables(): void
    {
        $this->dropAtlasToolRuntimeTables();

        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 32)->primary();
            $table->string('schema_version', 40)->default('atlas.ledger_event.v1');
            $table->string('tenant_id', 120)->index();
            $table->string('operator_id', 120)->index();
            $table->string('envelope_id', 80)->index();
            $table->string('receipt_id', 80)->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('correlation_id', 120)->index();
            $table->string('causation_id', 80)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('emitter_stage', 120)->index();
            $table->string('emitter_version', 80);
            $table->json('payload');
            $table->string('payload_hash', 64)->index();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
        });

        Schema::create('atlas_tool_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('type')->default('validator');
            $table->string('category');
            $table->text('description')->nullable();
            $table->string('homepage')->nullable();
            $table->string('license_posture')->default('open_source');
            $table->string('cost_posture')->default('free_local');
            $table->boolean('default_enabled')->default(true);
            $table->unsignedSmallInteger('default_timeout_seconds')->default(120);
            $table->string('default_failure_policy')->default('advisory');
            $table->string('risk_level')->default('low');
            $table->string('status')->default('active');
            $table->string('execution_tier')->default('T1');
            $table->string('expected_cost')->default('local_fast');
            $table->string('default_trigger')->default('manual_or_policy');
            $table->string('authority_role')->default('primary');
            $table->string('authority_group')->nullable();
            $table->string('detected_version')->nullable();
            $table->json('capabilities_json')->nullable();
            $table->json('runtime_json')->nullable();
            $table->json('detect_json')->nullable();
            $table->json('outputs_json')->nullable();
            $table->json('risks_json')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_tool_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_definition_id')->nullable();
            $table->string('tool_slug');
            $table->string('surface')->default('cli');
            $table->string('workspace_hash')->nullable();
            $table->text('workspace')->nullable();
            $table->string('run_context_type')->nullable();
            $table->string('run_context_id')->nullable();
            $table->string('status');
            $table->boolean('required')->default(false);
            $table->string('failure_policy')->default('advisory');
            $table->string('policy_decision')->default('allowed');
            $table->string('command_hash')->nullable();
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_ms')->default(0);
            $table->uuid('stdout_artifact_id')->nullable();
            $table->uuid('stderr_artifact_id')->nullable();
            $table->json('summary_json')->nullable();
            $table->json('normalized_result_json')->nullable();
            $table->json('policy_decision_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_tool_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_run_id');
            $table->string('type');
            $table->text('path');
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('sha256');
            $table->boolean('is_redacted')->default(true);
            $table->json('preview_json')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_tool_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_run_id');
            $table->string('rule_id')->nullable();
            $table->text('title');
            $table->text('message')->nullable();
            $table->string('severity')->default('medium');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->text('file_path')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->string('fingerprint')->nullable();
            $table->boolean('blocks_resolved')->default(false);
            $table->string('waiver_id')->nullable();
            $table->string('status')->default('open');
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }

    protected function dropAtlasToolRuntimeTables(): void
    {
        Schema::dropIfExists('atlas_tool_findings');
        Schema::dropIfExists('atlas_tool_artifacts');
        Schema::dropIfExists('atlas_tool_runs');
        Schema::dropIfExists('atlas_tool_definitions');
        Schema::dropIfExists('atlas_ledger_events');
    }
}
