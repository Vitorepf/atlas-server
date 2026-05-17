<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_engineering_benchmark_suites')) {
            Schema::create('atlas_engineering_benchmark_suites', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('slug', 120)->unique();
                $table->string('name', 180);
                $table->text('description')->nullable();
                $table->string('status', 32)->default('active')->index();
                $table->json('default_runner_options_json')->default('{}');
                $table->json('metadata')->default('{}');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_engineering_benchmark_cases')) {
            Schema::create('atlas_engineering_benchmark_cases', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('suite_id')->index();
                $table->uuid('task_id')->nullable()->index();
                $table->string('case_code', 120);
                $table->string('title', 180);
                $table->text('description')->nullable();
                $table->string('workspace_path_hash', 64)->nullable()->index();
                $table->json('task_contract_json')->default('{}');
                $table->json('runner_options_json')->default('{}');
                $table->string('expected_decision', 32)->nullable();
                $table->unsignedSmallInteger('min_score')->default(85);
                $table->string('corpus_tier', 40)->nullable();
                $table->string('domain_slug', 80)->nullable();
                $table->string('risk_profile', 40)->nullable();
                $table->string('curation_status', 40)->default('candidate');
                $table->unsignedSmallInteger('curation_score')->nullable();
                $table->string('corpus_fingerprint', 64)->nullable();
                $table->timestamp('curated_at')->nullable();
                $table->json('tags_json')->default('[]');
                $table->string('status', 32)->default('active')->index();
                $table->json('metadata')->default('{}');
                $table->timestamps();
                $table->unique(['suite_id', 'case_code'], 'idx_atlas_eng_bench_cases_suite_code');
            });
        }

        if (! Schema::hasTable('atlas_engineering_benchmark_runs')) {
            Schema::create('atlas_engineering_benchmark_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('suite_id')->index();
                $table->string('benchmark_key', 180)->nullable()->index();
                $table->string('provider', 80)->nullable()->index();
                $table->string('model', 120)->nullable()->index();
                $table->string('mode', 40)->nullable()->index();
                $table->string('case_set_hash', 64)->nullable()->index();
                $table->string('status', 32)->default('running')->index();
                $table->unsignedInteger('total_cases')->default(0);
                $table->unsignedInteger('passed_cases')->default(0);
                $table->unsignedInteger('failed_cases')->default(0);
                $table->uuid('baseline_run_id')->nullable()->index();
                $table->decimal('pass_rate', 5, 2)->nullable();
                $table->decimal('pass_rate_delta', 6, 2)->nullable();
                $table->decimal('average_score', 6, 2)->nullable();
                $table->decimal('average_score_delta', 6, 2)->nullable();
                $table->unsignedInteger('duration_ms')->default(0);
                $table->string('trend_status', 32)->nullable()->index();
                $table->string('harness_version', 40)->nullable();
                $table->unsignedInteger('total_attempts')->default(0);
                $table->unsignedInteger('failed_control_count')->default(0);
                $table->unsignedInteger('blocked_control_count')->default(0);
                $table->unsignedInteger('skipped_required_control_count')->default(0);
                $table->unsignedInteger('failed_test_count')->default(0);
                $table->unsignedInteger('open_review_finding_count')->default(0);
                $table->unsignedInteger('blocking_review_finding_count')->default(0);
                $table->unsignedInteger('changed_files_count')->default(0);
                $table->unsignedInteger('risk_flag_count')->default(0);
                $table->unsignedBigInteger('total_tokens')->default(0);
                $table->unsignedBigInteger('cost_microusd')->default(0);
                $table->unsignedInteger('telemetry_coverage_count')->default(0);
                $table->json('quality_metrics_json')->default('{}');
                $table->json('runner_options_json')->default('{}');
                $table->json('summary_json')->default('{}');
                $table->string('release_gate_status', 32)->nullable();
                $table->string('release_gate_profile', 64)->nullable();
                $table->json('release_gate_policy_json')->default('{}');
                $table->json('release_gate_failures_json')->default('[]');
                $table->json('release_gate_warnings_json')->default('[]');
                $table->string('rollout_status', 32)->nullable();
                $table->json('rollout_policy_json')->default('{}');
                $table->timestamp('rollout_decision_at')->nullable();
                $table->string('outcome_status', 40)->nullable()->index();
                $table->unsignedSmallInteger('outcome_score')->nullable();
                $table->json('outcome_json')->default('{}');
                $table->timestamp('outcome_recorded_at')->nullable();
                $table->string('outcome_recorded_by', 120)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->json('metadata')->default('{}');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_engineering_benchmark_results')) {
            Schema::create('atlas_engineering_benchmark_results', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('benchmark_run_id')->index();
                $table->uuid('suite_id')->index();
                $table->uuid('case_id')->index();
                $table->uuid('engineering_run_id')->nullable()->index();
                $table->uuid('task_id')->nullable()->index();
                $table->string('status', 32)->default('failed')->index();
                $table->string('decision', 32)->nullable()->index();
                $table->unsignedSmallInteger('score')->nullable();
                $table->boolean('passed')->default(false)->index();
                $table->integer('duration_ms')->default(0);
                $table->json('expectation_json')->default('{}');
                $table->json('observed_json')->default('{}');
                $table->text('failure_summary')->nullable();
                $table->json('metadata')->default('{}');
                $table->timestamps();
                $table->unique(['benchmark_run_id', 'case_id'], 'idx_atlas_eng_bench_results_run_case');
            });
        }
    }

    public function down(): void
    {
        //
    }
};
