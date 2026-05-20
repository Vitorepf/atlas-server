<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesPersistentContextTables
{
    protected function createPersistentContextTables(bool $withMemoryDeltas = true): void
    {
        $this->dropPersistentContextTables();

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
        });

        if ($withMemoryDeltas) {
            Schema::create('ai_memory_deltas', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('source_trace_id')->nullable()->index();
                $table->uuid('source_session_id')->nullable()->index();
                $table->string('source_workspace')->nullable();
                $table->string('type', 32)->default('process');
                $table->text('claim');
                $table->json('evidence');
                $table->string('scope', 255)->default('global');
                $table->float('confidence')->default(0.5);
                $table->timestamp('valid_from')->nullable();
                $table->timestamp('valid_until')->nullable();
                $table->json('use_when')->nullable();
                $table->json('do_not_use_when')->nullable();
                $table->boolean('requires_confirmation')->default(true);
                $table->string('status', 16)->default('pending');
                $table->uuid('superseded_by')->nullable();
                $table->uuid('promoted_memory_entry_id')->nullable();
                $table->timestamp('promoted_at')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function dropPersistentContextTables(): void
    {
        foreach (['ai_memory_deltas', 'atlas_persistent_context_packs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
