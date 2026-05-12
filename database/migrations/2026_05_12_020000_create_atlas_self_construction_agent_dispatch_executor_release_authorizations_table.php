<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_self_construction_agent_dispatch_executor_release_authorizations')) {
            return;
        }

        Schema::create('atlas_self_construction_agent_dispatch_executor_release_authorizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('authorization_key', 180)->unique();
            $table->string('receipt_key', 180)->index();
            $table->string('authorization_id', 180)->index();
            $table->string('packet_id', 160)->nullable()->index();
            $table->string('provider', 80)->nullable()->index();
            $table->string('provider_role', 120)->nullable()->index();
            $table->string('decision', 80)->index();
            $table->string('status', 40)->default('persisted')->index();
            $table->string('signed_by', 160)->index();
            $table->timestamp('signed_at')->index();
            $table->timestamp('expires_at')->index();
            $table->string('signed_receipt_template_hash', 64)->index();
            $table->string('signed_receipt_preflight_hash', 64)->index();
            $table->string('persistence_template_hash', 64)->index();
            $table->string('persistence_preflight_hash', 64)->index();
            $table->string('external_signature_validation_report_hash', 64)->index();
            $table->string('signed_receipt_hash', 64)->unique();
            $table->json('payload');
            $table->timestamp('persisted_at')->index();
            $table->timestamps();

            $table->index(['decision', 'status'], 'idx_self_construction_release_auth_decision_status');
            $table->index(['provider', 'status'], 'idx_self_construction_release_auth_provider_status');
            $table->index(['packet_id', 'status'], 'idx_self_construction_release_auth_packet_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_self_construction_agent_dispatch_executor_release_authorizations');
    }
};
