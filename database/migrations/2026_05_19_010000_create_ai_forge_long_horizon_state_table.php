<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_forge_long_horizon_states')) {
            return;
        }

        Schema::create('ai_forge_long_horizon_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.forge.long_horizon_state.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('intake_id')->unique();
            $table->string('obra_title', 500);
            $table->string('status', 40)->default('active')->index();
            $table->string('current_milestone', 60)->nullable()->index();
            $table->json('milestone_progress');
            $table->json('active_work_packets');
            $table->json('completed_work_packets');
            $table->json('blockers');
            $table->json('evidence_refs');
            $table->json('next_action');
            $table->json('last_cycle_summary')->nullable();
            $table->unsignedInteger('cycle_count')->default(0);
            $table->string('continuation_context_hash', 64)->nullable();
            $table->string('state_hash', 64)->index();
            $table->text('blocker_reason')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_forge_long_horizon_states');
    }
};
