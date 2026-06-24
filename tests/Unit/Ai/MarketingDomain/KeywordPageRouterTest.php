<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordPageRouter;
use PHPUnit\Framework\TestCase;

/**
 * Locks the #5 rule: the keyword's VSL-artifact density decides the destination page. Coined/possession →
 * order_page (zero re-sell); cold symptom → advertorial (plants a token). PROIBIDO: coined→bridge,
 * symptom→order_page. Deterministic.
 */
class KeywordPageRouterTest extends TestCase
{
    private KeywordPageRouter $r;

    protected function setUp(): void
    {
        $this->r = new KeywordPageRouter;
    }

    private function row(string $kw, string $suffix, string $family = ''): array
    {
        return ['keyword' => $kw, 'suffix_regime' => $suffix, 'family' => $family];
    }

    public function test_high_density_goes_to_order_page(): void
    {
        $this->assertSame('order_page', $this->r->route($this->row('orivelle pen', 'possession'))['page']);
        $this->assertSame('order_page', $this->r->route($this->row('jello diet', 'owned'))['page']);
        $this->assertSame('order_page', $this->r->route($this->row('sanjay gupta brain supplement', 'possession', 'celebrity'))['page']);
    }

    public function test_cold_symptom_goes_to_advertorial_never_order_page(): void
    {
        $page = $this->r->route($this->row('ed treatment', 'neutral'))['page'];
        $this->assertSame('advertorial', $page);
        $this->assertNotSame('order_page', $page, 'sintoma cru NUNCA na order page (converte 0%)');
    }

    public function test_coined_recall_goes_to_bridge_not_order_page(): void
    {
        // coined + sufixo de informação (recall) → bridge curta, não order page direta
        $this->assertSame('bridge', $this->r->route($this->row('gelatin trick variant', 'information', 'mechanism_trick'))['page']);
    }
}
