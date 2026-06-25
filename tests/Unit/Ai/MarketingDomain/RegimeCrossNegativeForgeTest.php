<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\RegimeCrossNegativeForge;
use PHPUnit\Framework\TestCase;

/**
 * Locks the cross-negation that enforces regime isolation: each campaign negates the OTHER campaigns' terms
 * (exact), never its own. No-hole coverage; deterministic; anti-cannibalization.
 */
class RegimeCrossNegativeForgeTest extends TestCase
{
    private RegimeCrossNegativeForge $f;

    protected function setUp(): void
    {
        $this->f = new RegimeCrossNegativeForge;
    }

    public function test_each_regime_negates_the_others_terms_exactly(): void
    {
        $part = [
            'harvest' => [['keyword' => 'orivelle']],
            'seed' => [['keyword' => 'gelatin trick'], ['keyword' => 'pink recipe']],
            'probe' => [['keyword' => 'weight loss treatment']],
        ];
        $r = $this->f->forge($part);

        $harvestNeg = array_column($r['by_regime']['harvest']['negatives'], 'term');
        sort($harvestNeg);
        $this->assertSame(['gelatin trick', 'pink recipe', 'weight loss treatment'], $harvestNeg, 'harvest nega seed+probe');
        $this->assertSame('exact', $r['by_regime']['harvest']['negatives'][0]['match']);

        $seedNeg = array_column($r['by_regime']['seed']['negatives'], 'term');
        $this->assertContains('orivelle', $seedNeg);
        $this->assertContains('weight loss treatment', $seedNeg);
    }

    public function test_never_negates_own_term(): void
    {
        // termo aparece em harvest E (defensivamente) em seed → não pode auto-bloquear no harvest
        $part = [
            'harvest' => [['keyword' => 'orivelle']],
            'seed' => [['keyword' => 'orivelle'], ['keyword' => 'gelatin trick']],
            'probe' => [],
        ];
        $r = $this->f->forge($part);
        $harvestNeg = array_column($r['by_regime']['harvest']['negatives'], 'term');
        $this->assertNotContains('orivelle', $harvestNeg, 'nunca negativa o próprio termo');
        $this->assertContains('gelatin trick', $harvestNeg);
    }

    public function test_deterministic_and_empty_safe(): void
    {
        $part = ['harvest' => [], 'seed' => [], 'probe' => []];
        $r = $this->f->forge($part);
        $this->assertSame([], $r['by_regime']['harvest']['negatives']);
        $this->assertSame($this->f->forge($part), $r);
    }
}
