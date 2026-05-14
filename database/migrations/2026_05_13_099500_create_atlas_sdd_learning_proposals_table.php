<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_sdd_learning_proposals')) {
            return;
        }

        Schema::create('atlas_sdd_learning_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operation_id')->nullable()->index();
            $table->uuid('drift_report_id')->nullable()->index();
            $table->string('proposal_type', 64)->index();
            $table->text('summary');
            $table->json('observation_json')->default('{}');
            $table->json('proposal_json')->default('{}');
            $table->json('evidence_refs_json')->default('[]');
            // proposal-only — never auto-applied per drift-detector-and-learning.md:137-138
            $table->string('status', 32)->default('proposed')->index();
            $table->string('decided_by', 80)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_sdd_learning_proposals');
    }
};
