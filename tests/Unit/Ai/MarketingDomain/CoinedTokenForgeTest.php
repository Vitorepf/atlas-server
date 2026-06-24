<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\CoinedTokenForge;
use PHPUnit\Framework\TestCase;

/**
 * Locks the #8 generation side (the moat): from an offer ingredient/benefit, generate coinable-token
 * candidates (ingredient × proven vehicle: diet/trick/protocol/loophole) — the names to plant in the
 * advertorial so they become tomorrow's branded re-finder search you own alone. Ranked by defensibility
 * (mistype-cone richness + improbability). Deterministic.
 */
class CoinedTokenForgeTest extends TestCase
{
    private CoinedTokenForge $f;

    protected function setUp(): void
    {
        $this->f = new CoinedTokenForge;
    }

    public function test_generates_ingredient_times_proven_vehicle(): void
    {
        $tokens = array_column($this->f->forge(['gelatin']), 'token');
        $this->assertContains('gelatin diet', $tokens, 'o padrão jello-diet (vendeu 32%)');
        $this->assertContains('gelatin trick', $tokens);
        $this->assertContains('gelatin loophole', $tokens, 'o coffee-loophole generalizado');
    }

    public function test_ranks_more_defensible_token_first(): void
    {
        // "blue salt" (multi-palavra, mais improvável-de-gerar) deve ranquear acima de um ingrediente curto
        $r = $this->f->forge(['blue salt', 'tea']);
        $this->assertSame('blue salt', $r[0]['ingredient'], 'o ingrediente mais ownable lidera');
        $this->assertGreaterThan(0, $r[0]['mistype_cone']);
    }

    public function test_deterministic_and_empty_safe(): void
    {
        $this->assertSame($this->f->forge(['gelatin']), $this->f->forge(['gelatin']));
        $this->assertSame([], $this->f->forge([]));
    }
}
