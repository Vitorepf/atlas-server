<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atlas Cognition Operating System — Absorcao 1 (Integer ID Mapping).
 *
 * Cria atlas_context_id_remaps em SQLite para feature tests sem rodar
 * migration core que depende de CREATE EXTENSION (pgsql-only).
 *
 * Espelho fiel da migration `2026_05_25_030000_create_atlas_context_id_remaps_table.php`.
 */
trait CreatesAtlasContextIdRemapTable
{
    protected function createAtlasContextIdRemapTable(): void
    {
        $this->dropAtlasContextIdRemapTable();

        Schema::create('atlas_context_id_remaps', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('context_pack_id', 64)->index();
            $table->string('mapping_hash', 64);
            $table->string('schema_version', 64)->default('atlas.context.id_remap.v1');

            // JSONB em Postgres, JSON em SQLite (Laravel mapeia automaticamente).
            $table->json('internal_to_real');
            $table->json('real_to_internal');
            $table->json('ref_types')->nullable();

            $table->string('scope', 128)->default('context_pack');
            $table->boolean('provider_safe')->default(true);

            $table->unsignedInteger('lookup_count')->default(0);
            $table->unsignedInteger('reverse_lookup_count')->default(0);

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('deleted_at')->nullable()->index();

            $table->unique(['context_pack_id', 'deleted_at'], 'idx_context_pack_remap_active');
            $table->index(['schema_version', 'created_at']);
        });
    }

    protected function dropAtlasContextIdRemapTable(): void
    {
        Schema::dropIfExists('atlas_context_id_remaps');
    }
}
