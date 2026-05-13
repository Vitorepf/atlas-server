<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_programming_context_packs')) {
            return;
        }

        Schema::create('atlas_programming_context_packs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('plan_id', 160)->index();
            $table->string('parent_plan_id', 160)->nullable()->index();
            $table->string('context_pack_hash', 64)->unique();
            $table->string('schema_version', 120)->index();
            $table->string('status', 32)->index();
            $table->boolean('provider_safe')->default(true)->index();
            $table->string('retrieval_strategy', 80)->index();
            $table->json('ranked_refs_json')->default('[]');
            $table->json('excluded_refs_json')->default('[]');
            $table->json('source_counts_json')->default('{}');
            $table->json('metrics_json')->default('{}');
            $table->json('budget_json')->default('{}');
            $table->json('payload_json')->default('{}');
            $table->timestamps();

            $table->index(['plan_id', 'status'], 'idx_atlas_prog_context_packs_plan_status');
            $table->index(['retrieval_strategy', 'created_at'], 'idx_atlas_prog_context_packs_strategy_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_programming_context_packs');
    }
};
