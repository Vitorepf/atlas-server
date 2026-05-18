<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_programming_runtime_telemetry_events')) {
            return;
        }

        Schema::create('ai_programming_runtime_telemetry_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 80)->default('atlas.programming.runtime_telemetry.event.v1');
            $table->string('event_name', 80)->index();
            $table->string('event_phase', 60)->nullable();
            $table->string('flow', 80)->nullable()->index();
            $table->string('selected_core', 24)->nullable()->index();
            $table->string('run_id', 160)->nullable()->index();
            $table->uuid('mission_id')->nullable()->index();
            $table->uuid('work_order_id')->nullable()->index();
            $table->uuid('obra_id')->nullable()->index();
            $table->uuid('route_decision_id')->nullable()->index();
            $table->string('rag_gate_status', 40)->nullable()->index();
            $table->unsignedTinyInteger('context_sufficiency')->nullable();
            $table->string('execution_status', 40)->nullable()->index();
            $table->string('test_status', 40)->nullable()->index();
            $table->unsignedSmallInteger('repair_attempt_count')->nullable();
            $table->unsignedTinyInteger('evidence_completeness')->nullable();
            $table->string('certification_status', 40)->nullable()->index();
            $table->unsignedSmallInteger('blocker_count')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->decimal('cost_estimate_usd', 12, 6)->nullable();
            $table->json('metadata')->nullable();
            $table->string('event_hash', 64)->unique();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamps();

            $table->index(['flow', 'selected_core', 'created_at']);
            $table->index(['event_name', 'execution_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_programming_runtime_telemetry_events');
    }
};
