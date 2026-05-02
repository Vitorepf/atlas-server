<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_report_findings')) {
            return;
        }

        Schema::create('ai_report_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('run_id')->nullable();
            $table->date('report_date');
            $table->string('report_type', 32);
            $table->string('metric', 80);
            $table->string('direction', 16);                  // 'up' | 'down'
            $table->double('magnitude_pct')->nullable();
            $table->double('explained_fraction')->nullable();
            $table->integer('affected_n')->default(0);
            $table->json('attribution_dimensions')->default('{}');
            $table->json('evidence')->default('{}');
            $table->json('signals')->default('[]');
            $table->double('confidence_score')->default(0.0);
            $table->string('confidence_band', 16);             // 'low' | 'medium' | 'high'
            $table->string('suggested_action_seed', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['report_date', 'metric'], 'idx_ai_findings_date_metric');
            $table->index('run_id', 'idx_ai_findings_run');
            $table->index(['confidence_band', 'created_at'], 'idx_ai_findings_confidence');
        });

        if (DB::connection()->getDriverName() === 'pgsql' && Schema::hasTable('ai_performance_report_runs')) {
            $exists = DB::selectOne("SELECT 1 FROM pg_constraint WHERE conname = 'ai_report_findings_run_id_foreign'");
            if (! $exists) {
                DB::statement(<<<'SQL'
                    ALTER TABLE ai_report_findings
                    ADD CONSTRAINT ai_report_findings_run_id_foreign
                    FOREIGN KEY (run_id) REFERENCES ai_performance_report_runs(id) ON DELETE CASCADE
                SQL);
            }

            $checkConfidence = DB::selectOne("SELECT 1 FROM pg_constraint WHERE conname = 'ai_report_findings_confidence_band_check'");
            if (! $checkConfidence) {
                DB::statement(<<<'SQL'
                    ALTER TABLE ai_report_findings
                    ADD CONSTRAINT ai_report_findings_confidence_band_check
                    CHECK (confidence_band IN ('low', 'medium', 'high'))
                SQL);
            }

            $checkDir = DB::selectOne("SELECT 1 FROM pg_constraint WHERE conname = 'ai_report_findings_direction_check'");
            if (! $checkDir) {
                DB::statement(<<<'SQL'
                    ALTER TABLE ai_report_findings
                    ADD CONSTRAINT ai_report_findings_direction_check
                    CHECK (direction IN ('up', 'down'))
                SQL);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_report_findings')) {
            return;
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_report_findings DROP CONSTRAINT IF EXISTS ai_report_findings_direction_check');
            DB::statement('ALTER TABLE ai_report_findings DROP CONSTRAINT IF EXISTS ai_report_findings_confidence_band_check');
            DB::statement('ALTER TABLE ai_report_findings DROP CONSTRAINT IF EXISTS ai_report_findings_run_id_foreign');
        }
        Schema::dropIfExists('ai_report_findings');
    }
};
