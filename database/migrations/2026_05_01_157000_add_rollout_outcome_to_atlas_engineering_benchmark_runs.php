<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atlas_engineering_benchmark_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'rollout_status')) {
                $table->string('rollout_status', 40)->nullable()->index()->after('release_gate_warnings_json');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'rollout_policy_json')) {
                $table->json('rollout_policy_json')->default('{}')->after('rollout_status');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'rollout_decision_at')) {
                $table->timestamp('rollout_decision_at')->nullable()->after('rollout_policy_json');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'outcome_status')) {
                $table->string('outcome_status', 40)->nullable()->index()->after('rollout_decision_at');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'outcome_score')) {
                $table->unsignedSmallInteger('outcome_score')->nullable()->after('outcome_status');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'outcome_json')) {
                $table->json('outcome_json')->default('{}')->after('outcome_score');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'outcome_recorded_at')) {
                $table->timestamp('outcome_recorded_at')->nullable()->after('outcome_json');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_runs', 'outcome_recorded_by')) {
                $table->string('outcome_recorded_by', 120)->nullable()->after('outcome_recorded_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('atlas_engineering_benchmark_runs', function (Blueprint $table): void {
            foreach ([
                'outcome_recorded_by',
                'outcome_recorded_at',
                'outcome_json',
                'outcome_score',
                'outcome_status',
                'rollout_decision_at',
                'rollout_policy_json',
                'rollout_status',
            ] as $column) {
                if (Schema::hasColumn('atlas_engineering_benchmark_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
