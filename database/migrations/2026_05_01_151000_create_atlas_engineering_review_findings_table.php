<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_engineering_review_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id')->index();
            $table->uuid('attempt_id')->nullable()->index();
            $table->uuid('task_id')->nullable()->index();
            $table->string('source', 80)->default('manual_review')->index();
            $table->string('severity', 8)->default('p2')->index();
            $table->string('status', 32)->default('open')->index();
            $table->string('title', 180);
            $table->text('body')->nullable();
            $table->text('file_path')->nullable();
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->json('evidence_json')->default('{}');
            $table->json('resolution_json')->default('{}');
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->index(['engineering_run_id', 'status', 'severity'], 'idx_atlas_eng_review_run_status_severity');
            $table->index(['task_id', 'status', 'created_at'], 'idx_atlas_eng_review_task_status_created');
        });

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_atlas_engineering_review_findings_updated_at
            BEFORE UPDATE ON atlas_engineering_review_findings
            FOR EACH ROW EXECUTE FUNCTION set_updated_at();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_engineering_review_findings');
    }
};
