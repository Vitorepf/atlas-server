<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atlas_engineering_benchmark_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'harness_version')) {
                $table->string('harness_version', 80)->nullable()->index()->after('trend_status');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'total_attempts')) {
                $table->unsignedInteger('total_attempts')->default(0)->after('harness_version');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'failed_control_count')) {
                $table->unsignedInteger('failed_control_count')->default(0)->after('total_attempts');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'blocked_control_count')) {
                $table->unsignedInteger('blocked_control_count')->default(0)->after('failed_control_count');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'skipped_required_control_count')) {
                $table->unsignedInteger('skipped_required_control_count')->default(0)->after('blocked_control_count');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'failed_test_count')) {
                $table->unsignedInteger('failed_test_count')->default(0)->after('skipped_required_control_count');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'open_review_finding_count')) {
                $table->unsignedInteger('open_review_finding_count')->default(0)->after('failed_test_count');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'blocking_review_finding_count')) {
                $table->unsignedInteger('blocking_review_finding_count')->default(0)->after('open_review_finding_count');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'changed_files_count')) {
                $table->unsignedInteger('changed_files_count')->default(0)->after('blocking_review_finding_count');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'risk_flag_count')) {
                $table->unsignedInteger('risk_flag_count')->default(0)->after('changed_files_count');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'total_tokens')) {
                $table->unsignedBigInteger('total_tokens')->nullable()->after('risk_flag_count');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'cost_microusd')) {
                $table->bigInteger('cost_microusd')->nullable()->after('total_tokens');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'telemetry_coverage_count')) {
                $table->unsignedInteger('telemetry_coverage_count')->default(0)->after('cost_microusd');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'quality_metrics_json')) {
                $table->json('quality_metrics_json')->default('{}')->after('telemetry_coverage_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('atlas_engineering_benchmark_runs', function (Blueprint $table): void {
            foreach ([
                'quality_metrics_json',
                'telemetry_coverage_count',
                'cost_microusd',
                'total_tokens',
                'risk_flag_count',
                'changed_files_count',
                'blocking_review_finding_count',
                'open_review_finding_count',
                'failed_test_count',
                'skipped_required_control_count',
                'blocked_control_count',
                'failed_control_count',
                'total_attempts',
                'harness_version',
            ] as $column) {
                if (Schema::hasColumn('atlas_engineering_benchmark_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
