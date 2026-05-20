<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_persistent_context_packs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('uuid', 64)->unique();
            $table->string('schema_version', 120)->default('atlas.persistent_context.runtime.v1');
            $table->string('status', 40)->index();
            $table->string('scope_type', 80)->index();
            $table->string('scope_id', 160)->nullable()->index();
            $table->string('workspace')->nullable()->index();
            $table->string('surface_id', 120)->nullable()->index();
            $table->string('domain', 80)->nullable()->index();
            $table->string('flow_id', 120)->nullable()->index();
            $table->string('provider', 80)->nullable()->index();
            $table->string('prompt_hash', 64)->index();
            $table->string('context_pack_hash', 64)->index();
            $table->string('must_know_ledger_hash', 64)->index();
            $table->string('sufficiency_status', 40)->index();
            $table->json('retrieval_report');
            $table->json('must_know_ledger');
            $table->json('context_pack');
            $table->json('provider_handoff');
            $table->json('post_execution_update')->nullable();
            $table->uuid('memory_delta_id')->nullable()->index();
            $table->json('evidence_refs')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id', 'created_at'], 'idx_apcr_scope_created');
            $table->index(['workspace', 'flow_id', 'created_at'], 'idx_apcr_workspace_flow_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_persistent_context_packs');
    }
};
