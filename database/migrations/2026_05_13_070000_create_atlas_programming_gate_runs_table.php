<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_programming_gate_runs')) {
            return;
        }

        Schema::create('atlas_programming_gate_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('work_item_id')->index();
            $table->string('gate_name', 64)->index();
            $table->string('status', 24)->index();
            $table->boolean('blocking')->default(true)->index();
            $table->string('input_hash', 64)->nullable();
            $table->string('output_hash', 64)->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('waiver_reason', 255)->nullable();
            $table->string('decided_by', 80)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->json('payload_json')->default('{}');
            $table->timestamps();

            $table->index(['work_item_id', 'gate_name'], 'idx_atlas_prog_gate_runs_item_gate');
            $table->index(['work_item_id', 'created_at'], 'idx_atlas_prog_gate_runs_item_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_programming_gate_runs');
    }
};
