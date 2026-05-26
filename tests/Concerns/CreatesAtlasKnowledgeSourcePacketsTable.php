<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AKIF phase 1 — espelho da migration `2026_05_25_040000_create_atlas_knowledge_source_packets_table`.
 */
trait CreatesAtlasKnowledgeSourcePacketsTable
{
    protected function createAtlasKnowledgeSourcePacketsTable(): void
    {
        $this->dropAtlasKnowledgeSourcePacketsTable();

        Schema::create('atlas_knowledge_source_packets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 64)->default('atlas.knowledge.source_packet.v1');
            $table->string('source_type', 40)->index();
            $table->text('origin_uri');
            $table->string('source_hash', 64)->index();
            $table->string('version_hash', 64);
            $table->string('normalized_text_ref', 512)->nullable();
            $table->json('media_refs')->nullable();
            $table->string('language', 8)->nullable();
            $table->decimal('confidence', 5, 3)->nullable();
            $table->json('lineage');
            $table->string('privacy_status', 24)->default('normal')->index();
            $table->boolean('provider_safe')->default(true)->index();
            $table->string('ingestion_status', 32)->default('received')->index();
            $table->string('receipt_hash', 64)->index();
            $table->json('metadata')->nullable();
            $table->string('blocking_reason', 512)->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable()->index();

            $table->unique(['source_hash', 'deleted_at'], 'idx_atlas_knowledge_dedup');
            $table->index(['source_type', 'ingestion_status']);
            $table->index(['privacy_status', 'provider_safe']);
        });
    }

    protected function dropAtlasKnowledgeSourcePacketsTable(): void
    {
        Schema::dropIfExists('atlas_knowledge_source_packets');
    }
}
