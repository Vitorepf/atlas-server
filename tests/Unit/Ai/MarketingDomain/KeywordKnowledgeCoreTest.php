<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordKnowledgeCore;
use PHPUnit\Framework\TestCase;

/**
 * Locks the L0 Knowledge Core: a versioned, SOURCED, queryable body of the keyword discipline that every
 * engine can cite for provenance (the Decision-Receipt seed). Every law must carry a source — knowledge
 * without provenance is not deterministic.
 */
class KeywordKnowledgeCoreTest extends TestCase
{
    private KeywordKnowledgeCore $k;

    protected function setUp(): void
    {
        $this->k = new KeywordKnowledgeCore;
    }

    public function test_every_law_carries_a_source_layer_and_statement(): void
    {
        $ids = [];
        foreach ($this->k->entries() as $e) {
            foreach (['id', 'layer', 'kind', 'topic', 'statement', 'source', 'verified'] as $field) {
                $this->assertArrayHasKey($field, $e);
                $this->assertNotEmpty($e[$field], "campo {$field} vazio em ".($e['id'] ?? '?'));
            }
            $this->assertMatchesRegularExpression('/^L\d+$/', $e['layer']);
            $this->assertContains($e['kind'], ['google_mechanic', 'master_principle', 'decision_rule']);
            $ids[] = $e['id'];
        }
        $this->assertSame($ids, array_unique($ids), 'ids de lei devem ser únicos');
        $this->assertGreaterThanOrEqual(15, count($ids));
    }

    public function test_query_by_layer_and_topic(): void
    {
        $l4 = array_column($this->k->byLayer('L4'), 'id');
        $this->assertContains('rule-of-three', $l4);
        $this->assertContains('breakeven-epc', $l4);

        $neg = array_column($this->k->byTopic('negatives'), 'id');
        $this->assertContains('negatives-dumber', $neg);
        $this->assertContains('exclusion-over-attraction', $neg);
    }

    public function test_cite_returns_provenance_with_version(): void
    {
        $c = $this->k->cite('rule-of-three');
        $this->assertNotNull($c);
        $this->assertArrayHasKey('source', $c);
        $this->assertArrayHasKey('core_version', $c);
        $this->assertSame(KeywordKnowledgeCore::VERSION, $c['core_version']);

        $this->assertNull($this->k->cite('lei-inexistente'));
    }
}
