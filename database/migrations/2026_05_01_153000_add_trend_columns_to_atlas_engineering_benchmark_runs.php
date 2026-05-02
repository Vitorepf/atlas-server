<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atlas_engineering_benchmark_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'benchmark_key')) {
                $table->string('benchmark_key', 180)->nullable()->index()->after('suite_id');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'provider')) {
                $table->string('provider', 80)->nullable()->index()->after('benchmark_key');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'model')) {
                $table->string('model', 120)->nullable()->index()->after('provider');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'mode')) {
                $table->string('mode', 40)->nullable()->index()->after('model');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'case_set_hash')) {
                $table->string('case_set_hash', 64)->nullable()->index()->after('mode');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'baseline_run_id')) {
                $table->uuid('baseline_run_id')->nullable()->index()->after('failed_cases');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'pass_rate_delta')) {
                $table->decimal('pass_rate_delta', 6, 2)->nullable()->after('pass_rate');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'average_score_delta')) {
                $table->decimal('average_score_delta', 6, 2)->nullable()->after('average_score');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'duration_ms')) {
                $table->unsignedInteger('duration_ms')->default(0)->after('average_score_delta');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'trend_status')) {
                $table->string('trend_status', 32)->nullable()->index()->after('duration_ms');
            }
        });
    }

    public function down(): void
    {
        Schema::table('atlas_engineering_benchmark_runs', function (Blueprint $table): void {
            foreach ([
                'trend_status',
                'duration_ms',
                'average_score_delta',
                'pass_rate_delta',
                'baseline_run_id',
                'case_set_hash',
                'mode',
                'model',
                'provider',
                'benchmark_key',
            ] as $column) {
                if (Schema::hasColumn('atlas_engineering_benchmark_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
