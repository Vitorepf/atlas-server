<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atlas Cognition Operating System — Integer ID Mapping table.
 *
 * Schema canonico: atlas.context.id_remap.v1
 *
 * Persiste o mapping interno_id -> real_uuid e o inverso, para alimentar
 * Anti-Halucinacao em prompts ACOS. Provider recebe apenas inteiros
 * sequenciais; parser reverso recupera UUID real antes de qualquer
 * persistencia (Decision Receipt, Evidence Ledger, AiMemoryDelta).
 *
 * Origem da absorcao: mem0/02-algoritmo-memoria.md + dissecar/mem0/13-ideias-portaveis-atlas.md.
 * Doc canon mae: atlas-cognition-operating-system.md.
 * Doc absorcao: atlas-external-memory-pattern-absorptions-v1.md.
 *
 * Privacy:
 *   - provider_safe=true por default (nao expoe payload completo).
 *   - real_to_internal e internal_to_real persistidos como JSONB no banco.
 *   - context_pack_id e a chave operacional; mapping_hash e fingerprint
 *     deterministico para deteccao de drift.
 *
 * TTL:
 *   - Default expires_at = created_at + atlas.cognition.id_remap.ttl_seconds
 *     (default 3600, configuravel).
 *   - Garbage collection via comando atlas:cognition:id-remap:prune (futuro).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_context_id_remaps', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('context_pack_id', 64)->index();
            $table->string('mapping_hash', 64);
            $table->string('schema_version', 64)->default('atlas.context.id_remap.v1');

            // Mapping bidirecional. JSONB no Postgres para indexacao eficiente.
            $table->jsonb('internal_to_real');
            $table->jsonb('real_to_internal');

            // Metadata opcional por ref: type, source_type, privacy_class, etc.
            // Nao contem payload bruto; somente classificacao para reverse lookup.
            $table->jsonb('ref_types')->nullable();

            $table->string('scope', 128)->default('context_pack');

            // Provider safety flag — sempre true por design v1; reservado para
            // futuras variantes que possam alterar provider exposure.
            $table->boolean('provider_safe')->default(true);

            // Tracking de uso opcional para diagnosticar misses.
            $table->unsignedInteger('lookup_count')->default(0);
            $table->unsignedInteger('reverse_lookup_count')->default(0);

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('deleted_at')->nullable()->index();

            // Apenas um remap ativo por context_pack_id.
            $table->unique(['context_pack_id', 'deleted_at'], 'idx_context_pack_remap_active');

            $table->index(['schema_version', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_context_id_remaps');
    }
};
