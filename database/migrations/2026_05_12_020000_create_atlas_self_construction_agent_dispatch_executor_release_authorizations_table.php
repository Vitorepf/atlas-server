<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_self_construction_agent_dispatch_authorizations')) {
            return;
        }

        Schema::create('atlas_self_construction_agent_dispatch_authorizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('authorization_key', 180)->unique('idx_release_auth_authorization_key_unique');
            $table->string('receipt_key', 180)->index('idx_release_auth_receipt_key');
            $table->string('authorization_id', 180)->index('idx_release_auth_authorization_id');
            $table->string('packet_id', 160)->nullable()->index('idx_release_auth_packet_id');
            $table->string('provider', 80)->nullable()->index('idx_release_auth_provider');
            $table->string('provider_role', 120)->nullable()->index('idx_release_auth_provider_role');
            $table->string('decision', 80)->index('idx_release_auth_decision');
            $table->string('status', 40)->default('persisted')->index('idx_release_auth_status');
            $table->string('signed_by', 160)->index('idx_release_auth_signed_by');
            $table->timestamp('signed_at')->index('idx_release_auth_signed_at');
            $table->timestamp('expires_at')->index('idx_release_auth_expires_at');
            $table->string('signed_receipt_template_hash', 64)->index('idx_release_auth_signed_template_hash');
            $table->string('signed_receipt_preflight_hash', 64)->index('idx_release_auth_signed_preflight_hash');
            $table->string('persistence_template_hash', 64)->index('idx_release_auth_persistence_template_hash');
            $table->string('persistence_preflight_hash', 64)->index('idx_release_auth_persistence_preflight_hash');
            $table->string('external_signature_validation_report_hash', 64)->index('idx_release_auth_external_sig_report_hash');
            $table->string('signed_receipt_hash', 64)->unique('idx_release_auth_signed_receipt_hash_unique');
            $table->json('payload');
            $table->timestamp('persisted_at')->index('idx_release_auth_persisted_at');
            $table->timestamps();

            $table->index(['decision', 'status'], 'idx_self_construction_release_auth_decision_status');
            $table->index(['provider', 'status'], 'idx_self_construction_release_auth_provider_status');
            $table->index(['packet_id', 'status'], 'idx_self_construction_release_auth_packet_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_self_construction_agent_dispatch_authorizations');
    }
};
