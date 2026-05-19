<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_long_horizon_replay_manifests')) {
            return;
        }

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
            $table->string('context_pack_hash', 64)->nullable()->index();
            $table->text('reader_instructions');
            $table->text('provider_independent_summary');
            $table->json('safety_notes');
            $table->string('replay_status', 40)->index();
            $table->string('hash', 64)->index();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id'], 'idx_lhrm_scope');
            $table->index(['replay_status', 'created_at'], 'idx_lhrm_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_long_horizon_replay_manifests');
    }
};
