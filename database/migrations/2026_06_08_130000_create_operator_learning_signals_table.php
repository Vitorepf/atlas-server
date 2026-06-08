<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operator_learning_signals')) {
            return;
        }

        Schema::create('operator_learning_signals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('operator_id', 120)->index();
            $table->string('taxonomy_item_id', 40)->index();
            $table->string('signal_kind', 80)->index();
            $table->string('source_type', 80)->index();
            $table->string('source_ref_type', 80)->nullable()->index();
            $table->string('source_ref_id', 160)->nullable()->index();
            $table->string('trace_id', 120)->nullable()->index();
            $table->string('session_id', 120)->nullable()->index();
            $table->string('raw_excerpt_hash', 64)->nullable()->index();
            $table->text('normalized_claim');
            $table->json('evidence_refs')->nullable();
            $table->string('privacy_class', 40)->default('normal')->index();
            $table->string('risk_level', 40)->default('low')->index();
            $table->decimal('confidence', 5, 3)->default(0.500);
            $table->string('scope_type', 40)->default('global')->index();
            $table->string('scope_id', 160)->nullable()->index();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['operator_id', 'taxonomy_item_id'], 'idx_operator_learning_signals_operator_taxonomy');
            $table->index(['operator_id', 'source_type', 'created_at'], 'idx_operator_learning_signals_operator_source');
            $table->index(['privacy_class', 'risk_level'], 'idx_operator_learning_signals_privacy_risk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_learning_signals');
    }
};
