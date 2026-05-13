<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_programming_stage_receipts')) {
            return;
        }

        Schema::create('atlas_programming_stage_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('receipt_id', 64)->unique();
            $table->string('plan_id', 160)->index();
            $table->string('parent_plan_id', 160)->nullable()->index();
            $table->string('stage', 40)->index();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('status', 32)->index();
            $table->string('input_hash', 64);
            $table->string('output_hash', 64);
            $table->json('evidence_refs_json')->default('[]');
            $table->json('payload_json')->default('{}');
            $table->json('validation_json')->default('{}');
            $table->timestamps();

            $table->unique(['plan_id', 'stage', 'attempt'], 'idx_atlas_prog_stage_receipts_unique_stage');
            $table->index(['plan_id', 'attempt', 'stage'], 'idx_atlas_prog_stage_receipts_plan_attempt_stage');
            $table->index(['parent_plan_id', 'created_at'], 'idx_atlas_prog_stage_receipts_parent_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_programming_stage_receipts');
    }
};
