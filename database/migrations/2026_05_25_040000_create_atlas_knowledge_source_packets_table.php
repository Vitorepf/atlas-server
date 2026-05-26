<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atlas Cognition Operating System — AKIF (Knowledge Ingestion Fabric) Phase 1.
 *
 * Schema canonico: `atlas.knowledge.source_packet.v1`.
 * Doc canon: `atlas-cognition-operating-system.md` (bloco AUCRI #14).
 *
 * Persiste packets canonicos de ingestao de fontes externas (docs, PDFs,
 * repos, YouTube, planilhas, URLs, dados brutos) ANTES de qualquer
 * passagem para ASEF/AHRI/AURG. Sem packet com lineage, fonte nao
 * alimenta AUCRI.
 *
 * Phase 1 (esta migration):
 *  - source_packets canonicos com hash, lineage, privacy_status.
 *  - Sem extracao (texto/imagem/audio/video) — feita externamente em phase 2.
 *  - Sem indexing/chunking — ASEF processa packet aprovado em pipeline downstream.
 *
 * Phase 2 (proximo AP):
 *  - Pipeline extractor (OCR, transcricao YouTube com confidence, PDF parse).
 *  - normalized_text_ref + media_refs populados.
 *  - Integracao com ARPTL para privacy gate antes de ingestion completion.
 *  - Queue jobs para extraction async.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_knowledge_source_packets', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('schema_version', 64)->default('atlas.knowledge.source_packet.v1');

            // source_type canonico: doc | pdf | youtube | repo | url | dataset | image | audio | manual.
            $table->string('source_type', 40)->index();

            // origin_uri: URL, path, ou identificador da fonte original (provider-safe).
            $table->text('origin_uri');

            // Hash do conteudo bruto (sha256). Usado em dedup.
            $table->string('source_hash', 64)->index();

            // Hash da versao especifica (sha256 do conteudo + metadata version).
            $table->string('version_hash', 64);

            // Reference para texto normalizado (path ou ID). Phase 1: nullable.
            $table->string('normalized_text_ref', 512)->nullable();

            // Media refs como JSON (imagens, audio, video). Phase 1: nullable.
            $table->json('media_refs')->nullable();

            // Lingua detectada (ISO 639-1: pt, en, es, ...).
            $table->string('language', 8)->nullable();

            // Confidence (0.0-1.0) da extracao quando aplicavel.
            $table->decimal('confidence', 5, 3)->nullable();

            // Lineage canonico: de onde veio, quando, por quem.
            $table->json('lineage');

            // Privacy classification (normal | private | sensitive | secret).
            $table->string('privacy_status', 24)->default('normal')->index();

            // Provider-safe flag derivado de privacy_status + ARPTL gate.
            $table->boolean('provider_safe')->default(true)->index();

            // Status da ingestao: received | classifying | extracting | ready | blocked | failed.
            $table->string('ingestion_status', 32)->default('received')->index();

            // Receipt hash canonico (sha256 do packet completo).
            $table->string('receipt_hash', 64)->index();

            // Metadata adicional canonico.
            $table->json('metadata')->nullable();

            // Auditoria de blocking quando aplicavel.
            $table->string('blocking_reason', 512)->nullable();

            // Bytes brutos NUNCA persistidos aqui; apenas refs e hashes.
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable()->index();

            $table->unique(['source_hash', 'deleted_at'], 'idx_atlas_knowledge_dedup');
            $table->index(['source_type', 'ingestion_status']);
            $table->index(['privacy_status', 'provider_safe']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_knowledge_source_packets');
    }
};
