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

    public function test_review_gate_flags_stale_dated_mechanics_only(): void
    {
        // num futuro, as mecânicas DATADAS do Google entram na fila de re-validação...
        $stale = array_column($this->k->needsReview(2030, 2), 'id');
        $this->assertContains('broad-default-2024', $stale, 'mecânica datada do Google envelhece');
        // ...mas math/psicologia (os pais) são ATEMPORAIS e nunca entram
        $this->assertNotContains('rule-of-three', $stale);
        $this->assertNotContains('schwartz-awareness', $stale);

        // em 2026, com o L0 recém-revalidado (AI Max + offline atualizados), nada urgente
        $this->assertSame([], $this->k->needsReview(2026, 2), 'L0 está fresco em 2026');
    }

    public function test_offline_conversion_law_is_current_2026(): void
    {
        $law = $this->k->cite('offline-conversion-upstream');
        $this->assertSame('2026', $law['verified'], 'lei re-validada pós-cutoff de 15/jun/2026');
        $this->assertStringContainsString('Data Manager API', $law['statement']);
    }

    public function test_has_the_value_based_bidding_law_web_verified_2026(): void
    {
        $law = $this->k->cite('value-based-bidding-2026');
        $this->assertNotNull($law, 'VBB/tROAS-default 2026 é mecânica canônica do L0 (o norte: receita por keyword)');
        $this->assertStringContainsString('support.google.com', $law['source']);
        $this->assertStringContainsString('VALOR', $law['statement']);
        $this->assertContains('value-based-bidding-2026', array_column($this->k->byTopic('bidding'), 'id'));
    }

    public function test_has_the_ai_max_keywordless_law_web_verified_2025(): void
    {
        $law = $this->k->cite('ai-max-keywordless-2025');
        $this->assertNotNull($law, 'AI Max for Search (mai/2025) é mecânica canônica do L0');
        $this->assertStringContainsString('support.google.com', $law['source']);
        $this->assertStringContainsString('keywordless', mb_strtolower($law['statement']));

        // é descoberta/match (L2) e carrega a implicação de risco-de-conta (landing dirige a expansão)
        $this->assertContains('ai-max-keywordless-2025', array_column($this->k->byLayer('L2'), 'id'));
        $this->assertContains('ai-max-keywordless-2025', array_column($this->k->byTopic('account_risk'), 'id'));
    }
}
