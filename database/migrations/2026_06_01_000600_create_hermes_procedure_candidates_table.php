<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hermes_procedure_candidates')) {
            return;
        }

        Schema::create('hermes_procedure_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('candidate_hash', 64)->unique();
            $table->string('status', 40)->index();
            $table->string('source_status', 40)->nullable()->index();
            $table->string('risk_level', 16)->default('medium')->index();
            $table->boolean('promotion_allowed')->default(false)->index();
            $table->boolean('review_required')->default(true)->index();
            $table->json('payload_json')->default('{}');
            $table->json('evidence_refs_json')->default('[]');
            $table->json('promotion_gate_json')->default('{}');
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'expires_at'], 'idx_hermes_proc_candidates_status_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hermes_procedure_candidates');
    }
};
