<?php

declare(strict_types=1);

namespace Tests\Unit\Semantic;

use App\Models\AtlasMemoryEntry;
use App\Services\Semantic\EmbeddingProvenance;
use Tests\TestCase;

final class EmbeddingProvenanceTest extends TestCase
{
    public function test_embedded_content_hash_is_stable_for_exact_embedded_text(): void
    {
        $this->assertSame(
            hash('sha256', "Title\nSummary\nBody"),
            EmbeddingProvenance::contentHash("Title\nSummary\nBody"),
        );
    }

    public function test_model_id_normalizes_provider_and_model(): void
    {
        $this->assertSame(
            'openai:text-embedding-3-small',
            EmbeddingProvenance::modelId([
                'provider' => 'openai_api',
                'model' => 'text-embedding-3-small',
            ]),
        );

        $this->assertSame(
            'fastembed_local:sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2',
            EmbeddingProvenance::modelId([
                'provider' => 'fastembed_local',
                'model' => 'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2',
            ]),
        );
    }

    public function test_current_model_scope_excludes_known_cross_model_rows_but_keeps_legacy_vectors_until_backfill(): void
    {
        $builder = AtlasMemoryEntry::query();

        EmbeddingProvenance::scopeCurrentModel(
            $builder,
            'atlas_memory_entries',
            'fastembed_local:sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2',
        );

        $this->assertStringContainsString('"embedding_model" = ?', $builder->toSql());
        $this->assertStringContainsString('"embedding_model" is null', $builder->toSql());
        $this->assertSame(
            ['fastembed_local:sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2'],
            $builder->getBindings(),
        );
    }
}
