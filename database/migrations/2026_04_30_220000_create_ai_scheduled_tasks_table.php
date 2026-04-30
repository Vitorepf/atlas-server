<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_scheduled_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('prompt');
            $table->string('schedule');
            $table->string('kind', 16);
            $table->json('skill_ids')->default('[]');
            $table->string('target_platform', 32)->default('local');
            $table->uuid('target_device_id')->nullable();
            $table->text('workspace')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->text('last_output_path')->nullable();
            $table->integer('repeat_remaining')->nullable();
            $table->json('context_from_task_ids')->default('[]');
            $table->boolean('wrap_response')->default(true);
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->index(['enabled', 'next_run_at'], 'idx_ai_scheduled_tasks_next_run');
            $table->index('workspace', 'idx_ai_scheduled_tasks_workspace');
            $table->index(['target_platform', 'enabled'], 'idx_ai_scheduled_tasks_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_scheduled_tasks');
    }
};
