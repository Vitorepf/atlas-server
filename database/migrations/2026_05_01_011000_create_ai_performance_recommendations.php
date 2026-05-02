<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_performance_recommendations')) {
            return;
        }

        Schema::create('ai_performance_recommendations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id', 64)->default('vitor');
            $table->uuid('finding_id')->nullable();
            $table->date('origin_report_date');
            $table->string('kind', 64);                 // 'cost_rate_missing' | 'latency_regression_provider' | etc.
            $table->string('target_metric', 80);
            $table->json('target_dimension')->default('{}');
            $table->json('expected_impact')->default('{}');
            $table->string('state', 32)->default('proposed');
            $table->json('state_history')->default('[]');
            $table->json('baseline_snapshot')->nullable();
            $table->json('observed_impact')->nullable();
            $table->timestamp('measurement_due_at')->nullable();
            $table->integer('measurement_window_days')->nullable();
            $table->integer('priority_score')->default(50);
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_reason', 64)->nullable();
            $table->uuid('superseded_by_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'state', 'created_at'], 'idx_ai_perfrec_user_state_created');
            $table->index(['user_id', 'kind', 'target_metric', 'state'], 'idx_ai_perfrec_dedup');
            $table->index('finding_id', 'idx_ai_perfrec_finding');
            $table->index('measurement_due_at', 'idx_ai_perfrec_measurement_due');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $exists = DB::selectOne("SELECT 1 FROM pg_constraint WHERE conname = 'ai_performance_recommendations_finding_fk'");
            if (! $exists && Schema::hasTable('ai_report_findings')) {
                DB::statement(<<<'SQL'
                    ALTER TABLE ai_performance_recommendations
                    ADD CONSTRAINT ai_performance_recommendations_finding_fk
                    FOREIGN KEY (finding_id) REFERENCES ai_report_findings(id) ON DELETE CASCADE
                SQL);
            }

            $checkState = DB::selectOne("SELECT 1 FROM pg_constraint WHERE conname = 'ai_performance_recommendations_state_check'");
            if (! $checkState) {
                DB::statement(<<<'SQL'
                    ALTER TABLE ai_performance_recommendations
                    ADD CONSTRAINT ai_performance_recommendations_state_check
                    CHECK (state IN ('proposed','acknowledged','in_progress','applied','measured','resolved','rejected','snoozed','expired','superseded','self_healed'))
                SQL);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_performance_recommendations')) {
            return;
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_performance_recommendations DROP CONSTRAINT IF EXISTS ai_performance_recommendations_state_check');
            DB::statement('ALTER TABLE ai_performance_recommendations DROP CONSTRAINT IF EXISTS ai_performance_recommendations_finding_fk');
        }
        Schema::dropIfExists('ai_performance_recommendations');
    }
};
