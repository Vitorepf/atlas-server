<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_forge_work_packet_workcell_routes')) {
            Schema::create('ai_forge_work_packet_workcell_routes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.forge.work_packet_workcell_route.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('intake_id')->index();
                $table->uuid('work_packet_id')->index();
                $table->string('work_packet_canonical_id', 80)->index();
                $table->uuid('execution_cycle_id')->nullable()->index();
                $table->uuid('multi_agent_schedule_id')->nullable()->index();
                $table->string('workcell', 80)->index();
                $table->string('agent_profile', 120)->index();
                $table->boolean('parallelizable')->default(false)->index();
                $table->boolean('requires_human_review')->default(false)->index();
                $table->json('route_reasons');
                $table->json('ownership_paths');
                $table->string('status', 60)->default('planned')->index();
                $table->string('route_hash', 64)->index();
                $table->timestamps();

                $table->unique(['execution_cycle_id', 'work_packet_canonical_id'], 'uniq_forge_route_cycle_packet');
            });
        }

        if (! Schema::hasTable('ai_forge_outcome_memories')) {
            Schema::create('ai_forge_outcome_memories', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.forge.outcome_memory.v1');
                $table->string('uuid', 64)->unique();
                $table->uuid('intake_id')->index();
                $table->uuid('work_packet_id')->index();
                $table->string('work_packet_canonical_id', 80)->index();
                $table->uuid('execution_cycle_id')->index();
                $table->string('cycle_uuid', 64)->index();
                $table->string('outcome_status', 40)->index();
                $table->string('execution_mode', 40)->index();
                $table->json('evidence_kinds');
                $table->json('aedpds_drivers')->nullable();
                $table->string('aedpds_gate_status', 40)->nullable()->index();
                $table->string('aedpds_doctrine_hash', 64)->nullable()->index();
                $table->string('aedpds_gate_hash', 64)->nullable()->index();
                $table->json('aedpds_effectiveness')->nullable();
                $table->json('learning_candidates');
                $table->json('failure_capsule')->nullable();
                $table->boolean('should_promote_to_aemor')->default(true)->index();
                $table->boolean('human_review_required')->default(false)->index();
                $table->string('outcome_memory_hash', 64)->unique();
                $table->timestamps();

                $table->unique(['execution_cycle_id', 'work_packet_canonical_id'], 'uniq_forge_outcome_cycle_packet');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_forge_outcome_memories');
        Schema::dropIfExists('ai_forge_work_packet_workcell_routes');
    }
};
