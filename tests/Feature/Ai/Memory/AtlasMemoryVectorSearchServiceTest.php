<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Services\Ai\Memory\AtlasMemoryVectorSearchService;
use App\Services\Semantic\EmbeddingService;
use Tests\TestCase;

/**
 * R1 keystone — locks the ANTI-OVER-CLAIM contract of memory vector recall:
 * "real semantic signal, or NOTHING". Off pgsql (the sqlite test DB), flag-off,
 * or empty input, it must return an EMPTY map so the caller honestly falls back to
 * lexical — it must NEVER fabricate a similarity score.
 */
final class AtlasMemoryVectorSearchServiceTest extends TestCase
{
    private function svc(): AtlasMemoryVectorSearchService
    {
        return new AtlasMemoryVectorSearchService(app(EmbeddingService::class));
    }

    public function test_honestly_degrades_to_empty_off_pgsql(): void
    {
        // Test DB is sqlite -> no pgvector. No semantic score may be invented.
        $svc = $this->svc();
        $this->assertFalse($svc->available(), 'vector recall must report unavailable off pgsql');
        $this->assertSame([], $svc->scoreEntries('domestic pet animal', ['a', 'b']));
        $this->assertSame([], $svc->scoreVerbatims('domestic pet animal', ['a', 'b']));
    }

    public function test_returns_empty_for_empty_query_or_empty_ids(): void
    {
        $svc = $this->svc();
        $this->assertSame([], $svc->scoreEntries('', ['a']));
        $this->assertSame([], $svc->scoreEntries('q', []));
        $this->assertSame([], $svc->scoreVerbatims('   ', ['a']));
    }

    public function test_flag_off_disables_vector_recall(): void
    {
        config()->set('atlas.semantic_memory.memory_vector_recall_enabled', false);
        $this->assertFalse($this->svc()->available());
        $this->assertSame([], $this->svc()->scoreEntries('q', ['a']));
    }
}
