<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atlas_engineering_benchmark_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'release_gate_status')) {
                $table->string('release_gate_status', 32)->nullable()->index()->after('quality_metrics_json');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'release_gate_profile')) {
                $table->string('release_gate_profile', 64)->nullable()->index()->after('release_gate_status');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'release_gate_policy_json')) {
                $table->json('release_gate_policy_json')->default('{}')->after('release_gate_profile');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'release_gate_failures_json')) {
                $table->json('release_gate_failures_json')->default('[]')->after('release_gate_policy_json');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'release_gate_warnings_json')) {
                $table->json('release_gate_warnings_json')->default('[]')->after('release_gate_failures_json');
            }
        });
    }

    public function down(): void
    {
        Schema::table('atlas_engineering_benchmark_runs', function (Blueprint $table): void {
            foreach ([
                'release_gate_warnings_json',
                'release_gate_failures_json',
                'release_gate_policy_json',
                'release_gate_profile',
                'release_gate_status',
            ] as $column) {
                if (Schema::hasColumn('atlas_engineering_benchmark_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
