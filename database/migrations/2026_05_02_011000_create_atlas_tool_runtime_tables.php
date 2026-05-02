<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_tool_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('name', 180);
            $table->string('type', 40)->default('validator')->index();
            $table->string('category', 80)->index();
            $table->text('description')->nullable();
            $table->string('homepage', 240)->nullable();
            $table->string('license_posture', 80)->default('open_source');
            $table->string('cost_posture', 80)->default('free_local');
            $table->boolean('default_enabled')->default(true)->index();
            $table->unsignedSmallInteger('default_timeout_seconds')->default(120);
            $table->string('default_failure_policy', 40)->default('advisory');
            $table->string('risk_level', 24)->default('low')->index();
            $table->string('status', 32)->default('active')->index();
            $table->string('detected_version', 120)->nullable();
            $table->json('capabilities_json')->default('[]');
            $table->json('runtime_json')->default('{}');
            $table->json('detect_json')->default('{}');
            $table->json('outputs_json')->default('[]');
            $table->json('risks_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->index(['category', 'status'], 'idx_atlas_tool_definitions_category_status');
        });

        Schema::create('atlas_tool_installations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_definition_id')->index();
            $table->string('workspace_hash', 64)->index();
            $table->string('execution_layer', 40)->index();
            $table->string('status', 32)->index();
            $table->string('version', 120)->nullable();
            $table->string('binary_path_hash', 64)->nullable();
            $table->string('node_modules_path_hash', 64)->nullable();
            $table->timestamp('detected_at')->nullable()->index();
            $table->json('metadata_json')->default('{}');
            $table->timestamps();

            $table->unique(['tool_definition_id', 'workspace_hash', 'execution_layer'], 'idx_atlas_tool_install_unique');
        });

        Schema::create('atlas_tool_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope_type', 40)->default('global')->index();
            $table->string('scope_id', 120)->nullable()->index();
            $table->string('tool_slug', 120)->index();
            $table->boolean('enabled')->default(true);
            $table->json('required_when_json')->default('[]');
            $table->string('failure_policy', 40)->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->nullable();
            $table->json('thresholds_json')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'tool_slug'], 'idx_atlas_tool_policies_scope_tool');
        });

        Schema::create('atlas_tool_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_definition_id')->nullable()->index();
            $table->string('tool_slug', 120)->index();
            $table->string('surface', 80)->default('cli')->index();
            $table->string('workspace_hash', 64)->nullable()->index();
            $table->text('workspace')->nullable();
            $table->string('run_context_type', 80)->nullable()->index();
            $table->string('run_context_id', 120)->nullable()->index();
            $table->string('status', 32)->index();
            $table->boolean('required')->default(false)->index();
            $table->string('failure_policy', 40)->default('advisory');
            $table->string('policy_decision', 40)->default('allowed')->index();
            $table->string('command_hash', 64)->nullable();
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_ms')->default(0);
            $table->uuid('stdout_artifact_id')->nullable()->index();
            $table->uuid('stderr_artifact_id')->nullable()->index();
            $table->json('summary_json')->default('{}');
            $table->json('normalized_result_json')->default('{}');
            $table->json('policy_decision_json')->default('{}');
            $table->json('metadata_json')->default('{}');
            $table->timestamps();

            $table->index(['run_context_type', 'run_context_id', 'created_at'], 'idx_atlas_tool_runs_context_created');
            $table->index(['tool_slug', 'status', 'created_at'], 'idx_atlas_tool_runs_tool_status');
        });

        Schema::create('atlas_tool_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_run_id')->index();
            $table->string('type', 60)->index();
            $table->text('path');
            $table->string('filename', 180);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('sha256', 64)->index();
            $table->boolean('is_redacted')->default(true);
            $table->json('preview_json')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_tool_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_run_id')->index();
            $table->string('rule_id', 180)->nullable()->index();
            $table->text('title');
            $table->text('message')->nullable();
            $table->string('severity', 24)->default('medium')->index();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->text('file_path')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->string('fingerprint', 64)->nullable()->index();
            $table->boolean('blocks_resolved')->default(false)->index();
            $table->string('waiver_id', 120)->nullable()->index();
            $table->string('status', 32)->default('open')->index();
            $table->json('metadata_json')->default('{}');
            $table->timestamps();

            $table->index(['tool_run_id', 'severity'], 'idx_atlas_tool_findings_run_severity');
        });

        foreach ([
            'atlas_tool_definitions',
            'atlas_tool_installations',
            'atlas_tool_policies',
            'atlas_tool_runs',
            'atlas_tool_findings',
        ] as $table) {
            DB::statement(<<<SQL
                CREATE TRIGGER trg_{$table}_updated_at
                BEFORE UPDATE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_tool_findings');
        Schema::dropIfExists('atlas_tool_artifacts');
        Schema::dropIfExists('atlas_tool_runs');
        Schema::dropIfExists('atlas_tool_policies');
        Schema::dropIfExists('atlas_tool_installations');
        Schema::dropIfExists('atlas_tool_definitions');
    }
};
