<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\NegativeKeywordForge;
use PHPUnit\Framework\TestCase;

/**
 * Locks the layered negative-keyword forge (report §4): junk/informational/price/polarity layers with
 * morphological variant expansion (negatives don't auto-match plurals) and the anti-campeã gate (never
 * negate a token that lives inside an owned root — also resolves the legacy 'recipe' collision).
 */
class NegativeKeywordForgeTest extends TestCase
{
    private NegativeKeywordForge $f;

    protected function setUp(): void
    {
        $this->f = new NegativeKeywordForge;
    }

    public function test_produces_the_four_layers(): void
    {
        $r = $this->f->forge();
        foreach (['junk_universal', 'informational', 'price_freebie', 'polarity_negative'] as $layer) {
            $this->assertArrayHasKey($layer, $r['layers']);
            $this->assertNotEmpty($r['layers'][$layer]);
        }
        $this->assertGreaterThan(0, $r['count']);
        $this->assertContains('scam', $r['flat']);
        $this->assertContains('cancelar', $r['flat']);
    }

    public function test_expands_singular_plural_variants(): void
    {
        $flat = $this->f->forge()['flat'];
        // negatives don't auto-match plurals, so both forms must be present.
        $this->assertContains('job', $flat);
        $this->assertContains('jobs', $flat);
    }

    public function test_anti_champion_gate_protects_owned_root(): void
    {
        // "recipe" is junk in general, but a buyer variant inside this coined mechanism — must NOT be negated.
        $protected = $this->f->forge(['protect' => ['blue salt trick recipe']]);
        $this->assertNotContains('recipe', $protected['flat']);

        // with no owned root to protect, "recipe" is correctly negated.
        $this->assertContains('recipe', $this->f->forge()['flat']);
    }

    public function test_merges_mined_real_waste_negatives_under_same_root_safety(): void
    {
        $r = $this->f->forge([
            'protect' => ['gelatin trick'],
            'mined' => ['recipe weight', 'blueberry trick', 'gelatin trick'], // último = owned root
        ]);
        $this->assertContains('recipe weight', $r['flat'], 'negativo do waste real entra');
        $this->assertContains('blueberry trick', $r['flat'], 'mecanismo concorrente entra');
        $this->assertNotContains('gelatin trick', $r['flat'], 'mined que é owned root é protegido');
        $this->assertArrayHasKey('mined_real_waste', $r['layers']);
    }
}
