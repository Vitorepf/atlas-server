<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hermes_skill_candidates')) {
            return;
        }

        Schema::create('hermes_skill_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('candidate_hash', 64)->unique();
            $table->string('status', 40)->index();
            $table->string('source', 40)->nullable()->index();
            $table->string('hub_skill_id', 191)->nullable()->index();
            $table->string('hub_skill_name', 191)->nullable()->index();
            $table->boolean('install_allowed')->default(false)->index();
            $table->boolean('review_required')->default(true)->index();
            $table->json('payload_json')->default('{}');
            $table->json('evidence_refs_json')->default('[]');
            $table->json('capability_gate_json')->default('{}');
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'expires_at'], 'idx_hermes_skill_candidates_status_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hermes_skill_candidates');
    }
};
