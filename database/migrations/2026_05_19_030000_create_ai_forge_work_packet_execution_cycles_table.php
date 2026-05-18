<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_forge_work_packet_execution_cycles')) {
            return;
        }

        Schema::create('ai_forge_work_packet_execution_cycles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.forge.work_packet_execution_cycle.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('intake_id')->index();
            $table->uuid('work_packet_id')->index();
            $table->string('work_packet_canonical_id', 80)->index();
            $table->uuid('long_horizon_state_id')->nullable()->index();
            $table->unsignedSmallInteger('cycle_position')->default(1);
            $table->string('execution_mode', 40)->index();
            $table->string('status', 40)->index();
            $table->json('execution_plan');
            $table->json('expected_artifacts');
            $table->json('evidence_refs');
            $table->json('gate_result')->nullable();
            $table->string('outcome_status', 40)->nullable()->index();
            $table->text('failure_reason')->nullable();
            $table->json('repair_hook')->nullable();
            $table->json('next_action');
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->string('cycle_hash', 64)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_forge_work_packet_execution_cycles');
    }
};
