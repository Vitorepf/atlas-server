<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_forge_multi_agent_schedules')) {
            return;
        }

        Schema::create('ai_forge_multi_agent_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.forge.multi_agent_schedule.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('intake_id')->nullable()->index();
            $table->uuid('mission_id')->nullable()->index();
            $table->uuid('work_order_id')->nullable()->index();
            $table->string('obra_id', 120)->nullable()->index();
            $table->text('task_summary');
            $table->string('risk_band', 20)->index();
            $table->unsignedSmallInteger('recommended_agent_count')->default(1);
            $table->json('roles_summary');
            $table->json('role_assignments');
            $table->json('ownership_map');
            $table->json('non_overlap_constraints');
            $table->json('dependency_order');
            $table->string('integration_plan', 60)->index();
            $table->json('conflict_risks');
            $table->json('verification_plan');
            $table->json('evidence_refs');
            $table->string('status', 60)->index();
            $table->text('blocker_reason')->nullable();
            $table->string('schedule_hash', 64)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_forge_multi_agent_schedules');
    }
};
