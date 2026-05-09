<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('productive_failure_sessions')) {
            return;
        }

        Schema::create('productive_failure_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('envelope_id');
            $table->uuid('knowledge_node_id');
            $table->string('domain', 64);
            $table->unsignedTinyInteger('dreyfus_stage_target');
            $table->json('phase_1_problem');
            $table->json('phase_1_attempt')->nullable();
            $table->foreignId('phase_2_worked_example_id')->nullable()->constrained('worked_examples');
            $table->json('phase_2_comparison')->nullable();
            $table->json('phase_3_articulation')->nullable();
            $table->string('phase_3_transfer_test_id', 128)->nullable();
            $table->json('phase_3_transfer_test')->nullable();
            $table->string('completion_status', 32)->default('in_progress');
            $table->timestamp('phase_1_started_at')->nullable();
            $table->timestamp('phase_2_started_at')->nullable();
            $table->timestamp('phase_3_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['knowledge_node_id', 'domain']);
            $table->index('completion_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productive_failure_sessions');
    }
};
