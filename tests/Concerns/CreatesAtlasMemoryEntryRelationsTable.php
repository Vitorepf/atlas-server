<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAtlasMemoryEntryRelationsTable
{
    protected function createAtlasMemoryEntryRelationsTable(): void
    {
        $this->dropAtlasMemoryEntryRelationsTable();

        Schema::create('atlas_memory_entry_relations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_memory_entry_id')->index();
            $table->uuid('target_memory_entry_id')->index();
            $table->string('relation_type', 32)->index();
            $table->string('status', 24)->default('open')->index();
            $table->decimal('confidence', 5, 3)->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();

            $table->unique(
                ['source_memory_entry_id', 'target_memory_entry_id', 'relation_type'],
                'uniq_atlas_memory_relation_pair_type',
            );
            $table->index(['relation_type', 'status'], 'idx_atlas_memory_rel_type_status');
        });
    }

    protected function dropAtlasMemoryEntryRelationsTable(): void
    {
        Schema::dropIfExists('atlas_memory_entry_relations');
    }
}
