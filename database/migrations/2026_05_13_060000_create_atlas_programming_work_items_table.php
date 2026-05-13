<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_programming_work_items')) {
            return;
        }

        Schema::create('atlas_programming_work_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->text('intent_text');
            $table->string('intent_type', 40)->index();
            $table->string('scope_mode', 24)->index();
            $table->string('risk_level', 16)->default('medium')->index();
            $table->string('owner', 80)->nullable()->index();
            $table->string('workspace', 255)->nullable();
            $table->string('status', 32)->index();
            $table->string('current_stage', 32)->index();
            $table->string('spec_hash', 64)->nullable()->index();
            $table->string('plan_hash', 64)->nullable()->index();
            $table->json('placement_json')->default('{}');
            $table->json('code_intelligence_json')->default('{}');
            $table->json('spec_json')->default('{}');
            $table->json('plan_json')->default('{}');
            $table->json('tasks_json')->default('[]');
            $table->json('evidence_refs_json')->default('[]');
            $table->json('gaps_json')->default('[]');
            $table->json('metadata_json')->default('{}');
            $table->timestamp('closed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['intent_type', 'status'], 'idx_atlas_prog_work_items_type_status');
            $table->index(['scope_mode', 'status'], 'idx_atlas_prog_work_items_mode_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_programming_work_items');
    }
};
