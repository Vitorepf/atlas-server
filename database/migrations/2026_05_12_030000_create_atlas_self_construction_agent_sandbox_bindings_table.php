<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_self_construction_agent_sandbox_bindings')) {
            return;
        }

        Schema::create('atlas_self_construction_agent_sandbox_bindings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('binding_key', 180)->unique();
            $table->string('receipt_hash', 64)->unique();
            $table->string('receipt_key', 180)->nullable()->index();
            $table->string('packet_id', 160)->index();
            $table->string('provider', 80)->index();
            $table->string('provider_role', 120)->nullable()->index();
            $table->string('status', 60)->default('active_pending_provider_start')->index();
            $table->string('workspace_root', 1024);
            $table->string('worktree_path', 1024);
            $table->string('branch', 240)->index();
            $table->string('executor_contract_hash', 64)->index();
            $table->string('executor_release_authorization_hash', 64)->index();
            $table->string('allowed_files_hash', 64)->index();
            $table->string('forbidden_scope_hash', 64)->index();
            $table->string('scope_validator_hash', 64)->index();
            $table->string('actor', 160)->index();
            $table->string('session', 160)->index();
            $table->json('payload');
            $table->timestamp('activated_at')->index();
            $table->timestamp('released_at')->nullable()->index();
            $table->timestamps();

            $table->index(['packet_id', 'status'], 'idx_self_construction_sandbox_packet_status');
            $table->index(['provider', 'status'], 'idx_self_construction_sandbox_provider_status');
            $table->index(['actor', 'session'], 'idx_self_construction_sandbox_actor_session');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_self_construction_agent_sandbox_bindings');
    }
};
