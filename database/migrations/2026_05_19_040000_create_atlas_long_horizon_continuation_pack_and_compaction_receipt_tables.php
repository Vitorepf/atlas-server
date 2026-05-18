<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_long_horizon_continuation_packs')) {
            Schema::create('atlas_long_horizon_continuation_packs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.long_horizon.continuation_pack.v2');
                $table->string('uuid', 64)->unique();
                $table->string('scope_type', 40)->index();
                $table->string('scope_id', 64)->nullable()->index();
                $table->text('objective');
                $table->string('current_phase', 80)->nullable()->index();
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
                $table->timestampTz('stale_after')->nullable()->index();
                $table->string('safe_resume_mode', 40)->default('full')->index();
                $table->string('next_safe_action', 200)->nullable();
                $table->json('human_decisions_required');
                $table->decimal('confidence', 4, 3)->nullable();
                $table->string('pack_hash', 64)->index();
                $table->timestamps();

                $table->index(['scope_type', 'scope_id'], 'idx_lhcp_scope');
                $table->index(['scope_type', 'created_at'], 'idx_lhcp_scope_created');
            });
        }

        if (! Schema::hasTable('atlas_long_horizon_compaction_receipts')) {
            Schema::create('atlas_long_horizon_compaction_receipts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_version', 120)->default('atlas.long_horizon.compaction_receipt.v1');
                $table->string('uuid', 64)->unique();
                $table->string('scope_type', 40)->index();
                $table->string('scope_id', 64)->nullable()->index();
                $table->json('source_context_refs');
                $table->json('retained_items');
                $table->json('discarded_items');
                $table->string('discarded_reason', 40)->nullable()->index();
                $table->json('must_keep_items');
                $table->decimal('must_keep_coverage', 4, 3)->default(0);
                $table->json('unresolved_loss');
                $table->string('loss_risk', 20)->default('low')->index();
                $table->json('recovery_queries');
                $table->json('evidence_refs');
                $table->string('summary_hash', 64);
                $table->decimal('quality_score', 4, 3)->nullable();
                $table->json('detected_contradictions');
                $table->json('stale_risks');
                $table->string('receipt_hash', 64)->index();
                $table->timestamps();

                $table->index(['scope_type', 'scope_id'], 'idx_lhcr_scope');
                $table->index(['loss_risk', 'created_at'], 'idx_lhcr_risk_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_long_horizon_compaction_receipts');
        Schema::dropIfExists('atlas_long_horizon_continuation_packs');
    }
};
