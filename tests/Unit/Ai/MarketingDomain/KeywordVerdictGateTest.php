<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordVerdictGate;
use PHPUnit\Framework\TestCase;

/**
 * Locks the L4 three-gate AND ("nunca chute"): a verdict is PROVEN only when significância, atribuição and
 * lag all pass; attribution protects an undervalued assistant from being cut; an immature window holds the
 * verdict at 'teste'; missing attribution/lag data keeps the basis at prior (honest, no fabricated proof).
 */
class KeywordVerdictGateTest extends TestCase
{
    private KeywordVerdictGate $g;

    protected function setUp(): void
    {
        $this->g = new KeywordVerdictGate;
    }

    public function test_proven_requires_all_three_gates(): void
    {
        $v = $this->g->verdict(
            ['verdict' => 'investimento', 'basis' => 'proven'],
            ['last_click_conv' => 10, 'dda_conv' => 10],
            ['days_since_launch' => 14, 'bake_days' => 7],
        );
        $this->assertTrue($v['proven']);
        $this->assertSame('proven', $v['basis']);
    }

    public function test_missing_attribution_and_lag_stays_prior(): void
    {
        $v = $this->g->verdict(['verdict' => 'investimento', 'basis' => 'proven']);
        $this->assertFalse($v['proven']);
        $this->assertSame('prior', $v['basis']);
        $this->assertStringContainsString('atribuição', $v['reason']);
        $this->assertStringContainsString('lag', $v['reason']);
    }

    public function test_attribution_protects_undervalued_assistant(): void
    {
        // last-click says "gasto" (cut it), but DDA shows it assisted a lot → don't cut.
        $v = $this->g->verdict(
            ['verdict' => 'gasto', 'basis' => 'proven'],
            ['last_click_conv' => 1, 'dda_conv' => 5],
            ['days_since_launch' => 30, 'bake_days' => 7],
        );
        $this->assertSame('teste', $v['verdict'], 'assistente subvalorizado não vira gasto');
        $this->assertTrue($v['gates']['attribution']['protect']);
    }

    public function test_immature_window_holds_verdict(): void
    {
        $v = $this->g->verdict(
            ['verdict' => 'gasto', 'basis' => 'proven'],
            ['last_click_conv' => 3, 'dda_conv' => 3],
            ['days_since_launch' => 2, 'bake_days' => 7],
        );
        $this->assertSame('teste', $v['verdict'], 'janela imatura segura o veredito');
        $this->assertSame('immature', $v['gates']['lag']['status']);
        $this->assertFalse($v['proven']);
    }
}
