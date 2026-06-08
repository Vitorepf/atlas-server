<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operator_learning_candidates')) {
            return;
        }

        Schema::create('operator_learning_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('signal_id')->nullable()->index();
            $table->string('operator_id', 120)->index();
            $table->string('taxonomy_item_id', 40)->index();
            $table->text('claim');
            $table->json('value')->nullable();
            $table->string('status', 40)->default('candidate')->index();
            $table->decimal('confidence', 5, 3)->default(0.500);
            $table->string('conflict_group', 160)->nullable()->index();
            $table->uuid('supersedes_id')->nullable()->index();
            $table->boolean('requires_confirmation')->default(true)->index();
            $table->boolean('auto_apply_eligible')->default(false)->index();
            $table->json('gate_receipt')->nullable();
            $table->string('decided_by', 160)->nullable();
            $table->timestamp('decided_at')->nullable()->index();
            $table->timestamps();

            $table->index(['operator_id', 'status', 'created_at'], 'idx_operator_candidates_operator_status');
            $table->index(['operator_id', 'taxonomy_item_id', 'status'], 'idx_operator_candidates_taxonomy_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_learning_candidates');
    }
};
