<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-memory schema for `atlas_long_horizon_continuation_packs` and
 * `atlas_long_horizon_compaction_receipts`. Mirrors the production
 * migration with SQLite-compatible types so tests can hydrate the model
 * without touching Postgres.
 */
trait CreatesLongHorizonPersistenceTables
{
    protected function createLongHorizonPersistenceTables(): void
    {
        $this->dropLongHorizonPersistenceTables();

        Schema::create('atlas_long_horizon_continuation_packs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.long_horizon.continuation_pack.v2');
            $table->string('uuid', 64)->unique();
            $table->string('scope_type', 40)->index();
            $table->string('scope_id', 64)->nullable()->index();
            $table->text('objective');
            $table->string('current_phase', 80)->nullable();
            $table->text('state_summary');
            $table->json('decisions');
            $table->json('superseded_decisions');
            $table->json('open_tasks');
            $table->json('completed_tasks');
            $table->json('blockers');
            $table->json('risks');
            $table->json('evidence_refs');
            $table->json('context_manifest');
            $table->string('context_pack_hash', 64)->nullable();
            $table->string('summary_hash', 64);
            $table->json('source_receipts');
            $table->timestampTz('stale_after')->nullable();
            $table->string('safe_resume_mode', 40)->default('full');
            $table->string('next_safe_action', 200)->nullable();
            $table->json('human_decisions_required');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('pack_hash', 64);
            $table->timestamps();
        });

        Schema::create('atlas_long_horizon_compaction_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.long_horizon.compaction_receipt.v1');
            $table->string('uuid', 64)->unique();
            $table->string('scope_type', 40)->index();
            $table->string('scope_id', 64)->nullable()->index();
            $table->json('source_context_refs');
            $table->json('retained_items');
            $table->json('discarded_items');
            $table->string('discarded_reason', 40)->nullable();
            $table->json('must_keep_items');
            $table->decimal('must_keep_coverage', 4, 3)->default(0);
            $table->json('unresolved_loss');
            $table->string('loss_risk', 20)->default('low');
            $table->json('recovery_queries');
            $table->json('evidence_refs');
            $table->string('summary_hash', 64);
            $table->decimal('quality_score', 4, 3)->nullable();
            $table->json('detected_contradictions');
            $table->json('stale_risks');
            $table->string('receipt_hash', 64);
            $table->timestamps();
        });

        Schema::create('atlas_long_horizon_replay_manifests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.long_horizon.replay_manifest.v1');
            $table->string('uuid', 64)->unique();
            $table->string('scope_type', 40)->index();
            $table->string('scope_id', 64)->nullable()->index();
            $table->uuid('continuation_pack_id')->nullable()->index();
            $table->uuid('compaction_receipt_id')->nullable()->index();
            $table->json('required_refs');
            $table->json('available_refs');
            $table->json('missing_refs');
            $table->json('event_refs');
            $table->json('evidence_refs');
            $table->string('context_pack_hash', 64)->nullable();
            $table->text('reader_instructions');
            $table->text('provider_independent_summary');
            $table->json('safety_notes');
            $table->string('replay_status', 40)->index();
            $table->string('hash', 64)->index();
            $table->timestamps();
        });
    }

    protected function dropLongHorizonPersistenceTables(): void
    {
        Schema::dropIfExists('atlas_long_horizon_replay_manifests');
        Schema::dropIfExists('atlas_long_horizon_compaction_receipts');
        Schema::dropIfExists('atlas_long_horizon_continuation_packs');
    }
}
