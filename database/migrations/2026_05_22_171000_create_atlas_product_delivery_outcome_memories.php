<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_product_delivery_outcome_memories')) {
            return;
        }

        Schema::create('atlas_product_delivery_outcome_memories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.product_delivery.outcome_memory.v1');
            $table->string('uuid', 64)->unique();
            $table->string('delivery_hash', 64)->index();
            $table->string('truth_hash', 64)->nullable()->index();
            $table->string('proof_hash', 64)->nullable()->index();
            $table->string('route', 40)->index();
            $table->string('outcome_status', 40)->index();
            $table->json('evidence_kinds');
            $table->json('required_repairs');
            $table->json('learning_candidates');
            $table->json('delivery_summary');
            $table->json('proof_summary');
            $table->boolean('should_promote_to_aemor')->default(true)->index();
            $table->boolean('human_review_required')->default(false)->index();
            $table->string('outcome_memory_hash', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_product_delivery_outcome_memories');
    }
};
