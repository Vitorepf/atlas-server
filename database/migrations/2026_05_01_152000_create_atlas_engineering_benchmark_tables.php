<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
            $table->json('tags_json')->default('[]');
            $table->string('status', 32)->default('active')->index();
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->unique(['suite_id', 'case_code'], 'idx_atlas_eng_bench_cases_suite_code');
        });

        Schema::create('atlas_engineering_benchmark_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('suite_id')->index();
            $table->string('status', 32)->default('running')->index();
            $table->unsignedInteger('total_cases')->default(0);
            $table->unsignedInteger('passed_cases')->default(0);
            $table->unsignedInteger('failed_cases')->default(0);
            $table->decimal('pass_rate', 5, 2)->nullable();
            $table->decimal('average_score', 6, 2)->nullable();
            $table->json('runner_options_json')->default('{}');
            $table->json('summary_json')->default('{}');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

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

        foreach ([
            'atlas_engineering_benchmark_suites',
            'atlas_engineering_benchmark_cases',
            'atlas_engineering_benchmark_runs',
            'atlas_engineering_benchmark_results',
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
        Schema::dropIfExists('atlas_engineering_benchmark_results');
        Schema::dropIfExists('atlas_engineering_benchmark_runs');
        Schema::dropIfExists('atlas_engineering_benchmark_cases');
        Schema::dropIfExists('atlas_engineering_benchmark_suites');
    }
};
