<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_engineering_runs')) {
            return;
        }

        Schema::create('atlas_engineering_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id')->index();
            $table->uuid('project_id')->nullable()->index();
            $table->uuid('project_step_id')->nullable()->index();
            $table->uuid('blueprint_snapshot_id')->nullable()->index();
            $table->string('blueprint_id', 120)->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('context_pack_id')->nullable()->index();
            $table->string('workspace_path_hash', 64);
            $table->string('workspace_label', 180);
            $table->json('provider_strategy_json')->default('{}');
            $table->string('context_pack_hash', 64)->nullable()->index();
            $table->unsignedSmallInteger('harnessability_score')->nullable();
            $table->string('status', 32)->default('queued')->index();
            $table->string('decision', 32)->nullable()->index();
            $table->unsignedSmallInteger('score')->nullable();
            $table->unsignedSmallInteger('max_attempts')->default(1);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->index(['task_id', 'created_at'], 'idx_atlas_eng_runs_task_created');
            $table->index(['status', 'decision', 'created_at'], 'idx_atlas_eng_runs_status_decision');
        });
    }

    public function down(): void
    {
        // Repair migrations are intentionally non-destructive.
    }
};
