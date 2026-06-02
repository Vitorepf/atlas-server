<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hermes_mcp_capability_candidates')) {
            return;
        }

        Schema::create('hermes_mcp_capability_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('server_name', 190)->index();
            $table->string('candidate_hash', 64)->unique();
            $table->string('transport', 40)->default('stdio')->index();
            $table->string('status', 40)->index();
            $table->string('source', 40)->nullable()->index();
            $table->string('risk_class', 16)->default('high')->index();
            $table->boolean('promotion_allowed')->default(false)->index();
            $table->boolean('review_required')->default(true)->index();
            $table->json('payload_json')->default('{}');
            $table->json('evidence_refs_json')->default('[]');
            $table->json('promotion_gate_json')->default('{}');
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'expires_at'], 'idx_hermes_mcp_candidates_status_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hermes_mcp_capability_candidates');
    }
};
