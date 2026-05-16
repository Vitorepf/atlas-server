<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atlas Dev — security & run-index tables.
 *
 * - atlas_dev_confirmation_tokens: anti-replay HMAC token guarding provider runs.
 *   The plaintext never lands on disk; only an HMAC over the secret is stored.
 *   Single-use is enforced at write time via `used_at`.
 *
 * - atlas_dev_run_index: surface-facing index of Atlas Dev runs. Mirrors only
 *   the routing/completion shape so the Desktop can list and resume runs;
 *   the canonical artifacts continue to live under
 *   storage/atlas-dev/receipts/<run_id>/ via ReceiptStorage.
 *
 * Migration is portable (no triggers / postgres-only SQL) so the same DDL
 * runs cleanly under both Postgres prod and the in-memory SQLite test DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_dev_confirmation_tokens')) {
            Schema::create('atlas_dev_confirmation_tokens', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('run_id', 128)->index();
                $table->string('token_hash', 128)->unique();
                $table->string('surface_id', 80)->index();
                $table->string('task_contract_hash', 128)->index();
                $table->string('compact_sdd_hash', 128)->nullable()->index();
                $table->timestamp('issued_at');
                $table->timestamp('expires_at')->index();
                $table->timestamp('used_at')->nullable();
                $table->timestamps();

                $table->index(['run_id', 'task_contract_hash'], 'idx_atlas_dev_tokens_run_contract');
            });
        }

        if (! Schema::hasTable('atlas_dev_run_index')) {
            Schema::create('atlas_dev_run_index', function (Blueprint $table): void {
                $table->string('run_id', 128)->primary();
                $table->string('surface_id', 80)->index();
                $table->string('workspace_hash', 128)->index();
                $table->string('thread_id', 128)->nullable()->index();
                $table->string('routing_decision', 32);
                $table->string('task_kind', 40);
                $table->string('risk_level', 8);
                $table->string('completion_state', 32)->nullable()->index();
                $table->string('last_receipt_hash', 128)->nullable();
                $table->timestamps();

                $table->index(['workspace_hash', 'created_at'], 'idx_atlas_dev_run_index_workspace_created');
                $table->index(['thread_id', 'created_at'], 'idx_atlas_dev_run_index_thread_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_dev_run_index');
        Schema::dropIfExists('atlas_dev_confirmation_tokens');
    }
};
