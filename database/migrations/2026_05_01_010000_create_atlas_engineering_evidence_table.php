<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_engineering_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id')->index();
            $table->uuid('project_id')->nullable()->index();
            $table->uuid('project_step_id')->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('evidence_type', 80);
            $table->string('target_id', 120)->nullable();
            $table->string('status', 32);
            $table->decimal('confidence', 5, 3)->nullable();
            $table->text('summary');
            $table->string('command', 500)->nullable();
            $table->text('artifact_url')->nullable();
            $table->text('output_excerpt')->nullable();
            $table->json('files')->default('[]');
            $table->json('metadata')->default('{}');
            $table->string('source', 160)->default('tasks.engineering.evidence');
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->index(['task_id', 'recorded_at'], 'idx_atlas_engineering_evidence_task_recorded');
            $table->index(['task_id', 'evidence_type', 'target_id'], 'idx_atlas_engineering_evidence_target');
            $table->index(['status', 'recorded_at'], 'idx_atlas_engineering_evidence_status');
        });

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_atlas_engineering_evidence_updated_at
            BEFORE UPDATE ON atlas_engineering_evidence
            FOR EACH ROW EXECUTE FUNCTION set_updated_at();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_engineering_evidence');
    }
};
