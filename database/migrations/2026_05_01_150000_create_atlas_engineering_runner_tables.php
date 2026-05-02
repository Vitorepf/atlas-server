<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_engineering_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id')->index();
            $table->uuid('project_id')->nullable()->index();
            $table->uuid('project_step_id')->nullable()->index();
            $table->uuid('blueprint_snapshot_id')->nullable()->index();
            $table->string('blueprint_id', 120)->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('context_pack_id')->nullable()->index();
            $table->string('workspace_path_hash', 64);
            $table->string('workspace_label', 180);
            $table->json('provider_strategy_json')->default('{}');
            $table->string('context_pack_hash', 64)->nullable()->index();
            $table->unsignedSmallInteger('harnessability_score')->nullable();
            $table->string('status', 32)->default('queued')->index();
            $table->string('decision', 32)->nullable()->index();
            $table->unsignedSmallInteger('score')->nullable();
            $table->unsignedSmallInteger('max_attempts')->default(1);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->index(['task_id', 'created_at'], 'idx_atlas_eng_runs_task_created');
            $table->index(['status', 'decision', 'created_at'], 'idx_atlas_eng_runs_status_decision');
        });

        Schema::create('atlas_engineering_run_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id')->index();
            $table->unsignedSmallInteger('attempt_number');
            $table->uuid('trace_id')->nullable()->index();
            $table->string('provider', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('phase', 32)->default('edit');
            $table->string('prompt_hash', 64)->nullable();
            $table->json('input_summary_json')->default('{}');
            $table->string('patch_hash', 64)->nullable()->index();
            $table->json('diff_stat_json')->default('{}');
            $table->json('changed_files_json')->default('[]');
            $table->string('status', 32)->default('completed')->index();
            $table->text('failure_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->unique(['engineering_run_id', 'attempt_number'], 'idx_atlas_eng_attempts_run_number');
        });

        Schema::create('atlas_engineering_patch_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id')->index();
            $table->uuid('attempt_id')->nullable()->index();
            $table->string('base_ref', 120)->nullable();
            $table->string('head_ref', 120)->nullable();
            $table->string('diff_hash', 64)->nullable()->index();
            $table->text('diff_excerpt')->nullable();
            $table->text('diff_path')->nullable();
            $table->json('changed_files_json')->default('[]');
            $table->json('created_files_json')->default('[]');
            $table->json('deleted_files_json')->default('[]');
            $table->json('risk_flags_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->index(['engineering_run_id', 'created_at'], 'idx_atlas_eng_patch_run_created');
        });

        Schema::create('atlas_engineering_controls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('name', 180);
            $table->string('direction', 24);
            $table->string('execution_type', 24);
            $table->string('regulation_category', 64);
            $table->string('timing', 40);
            $table->boolean('required')->default(false);
            $table->string('risk_level', 24)->default('low');
            $table->json('applies_when_json')->default('{}');
            $table->string('failure_policy', 40)->default('advisory');
            $table->string('command', 500)->nullable();
            $table->string('skill_slug', 120)->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->index(['direction', 'execution_type'], 'idx_atlas_eng_controls_direction_execution');
            $table->index(['regulation_category', 'required'], 'idx_atlas_eng_controls_category_required');
        });

        Schema::create('atlas_engineering_control_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id')->index();
            $table->uuid('attempt_id')->nullable()->index();
            $table->uuid('control_id')->nullable()->index();
            $table->string('control_slug', 120)->index();
            $table->string('status', 32)->index();
            $table->text('signal_summary');
            $table->text('output_excerpt')->nullable();
            $table->integer('duration_ms')->default(0);
            $table->text('artifact_path')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->index(['engineering_run_id', 'status'], 'idx_atlas_eng_control_results_run_status');
        });

        Schema::create('atlas_engineering_test_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id')->index();
            $table->string('blueprint_id', 120)->nullable()->index();
            $table->uuid('control_id')->nullable()->index();
            $table->string('case_code', 120)->index();
            $table->string('source', 32)->default('detected');
            $table->string('type', 32);
            $table->string('priority', 8)->default('p1');
            $table->string('command', 500)->nullable();
            $table->text('expected_signal')->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->default(900);
            $table->boolean('required')->default(true);
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->unique(['task_id', 'case_code'], 'idx_atlas_eng_test_cases_task_code');
        });

        Schema::create('atlas_engineering_test_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id')->index();
            $table->uuid('attempt_id')->nullable()->index();
            $table->uuid('test_case_id')->nullable()->index();
            $table->string('command', 500)->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('status', 32)->index();
            $table->integer('duration_ms')->default(0);
            $table->text('stdout_excerpt')->nullable();
            $table->text('stderr_excerpt')->nullable();
            $table->text('artifact_path')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->index(['engineering_run_id', 'status'], 'idx_atlas_eng_test_runs_run_status');
        });

        Schema::create('atlas_engineering_context_packs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id')->nullable()->index();
            $table->uuid('task_id')->index();
            $table->string('hash', 64)->index();
            $table->json('contract_json')->default('{}');
            $table->json('blueprint_json')->default('{}');
            $table->json('repo_profile_json')->default('{}');
            $table->json('selected_files_json')->default('[]');
            $table->json('prior_runs_json')->default('[]');
            $table->json('memory_refs_json')->default('[]');
            $table->json('prompt_sections_json')->default('[]');
            $table->json('token_budget_json')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->index(['task_id', 'created_at'], 'idx_atlas_eng_context_task_created');
        });

        foreach ([
            'atlas_engineering_runs',
            'atlas_engineering_run_attempts',
            'atlas_engineering_patch_artifacts',
            'atlas_engineering_controls',
            'atlas_engineering_control_results',
            'atlas_engineering_test_cases',
            'atlas_engineering_test_runs',
            'atlas_engineering_context_packs',
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
        Schema::dropIfExists('atlas_engineering_context_packs');
        Schema::dropIfExists('atlas_engineering_test_runs');
        Schema::dropIfExists('atlas_engineering_test_cases');
        Schema::dropIfExists('atlas_engineering_control_results');
        Schema::dropIfExists('atlas_engineering_controls');
        Schema::dropIfExists('atlas_engineering_patch_artifacts');
        Schema::dropIfExists('atlas_engineering_run_attempts');
        Schema::dropIfExists('atlas_engineering_runs');
    }
};
