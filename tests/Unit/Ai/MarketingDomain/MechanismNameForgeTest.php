<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\MechanismNameForge;
use PHPUnit\Framework\TestCase;

/**
 * MechanismNameForge — ORIGINATES the named proprietary mechanism (Eixo 2, Schwartz level-4) from the
 * asset's real ammunition. Number + concrete core + method word, in the structures elite copy uses.
 */
class MechanismNameForgeTest extends TestCase
{
    public function test_forges_a_number_anchored_named_mechanism(): void
    {
        $r = (new MechanismNameForge)->forge(new AiMarketingVslAsset([
            'mechanism_name' => '3 hormone metabolic drops', 'big_idea' => 'a metabolic switch', 'niche' => 'weight loss',
        ]));
        $this->assertNotNull($r['best']);
        $this->assertMatchesRegularExpression('/^The \d-\w+ \w+$/', $r['best'], 'best should be a number-anchored proprietary name');
        $this->assertNotEmpty($r['candidates']);
    }

    public function test_always_yields_a_usable_name_even_from_thin_assets(): void
    {
        $r = (new MechanismNameForge)->forge(new AiMarketingVslAsset(['niche' => 'finance']));
        $this->assertNotNull($r['best']);
        $this->assertStringContainsString('The', $r['best']);
    }

    public function test_candidates_are_ranked_and_deduped(): void
    {
        $r = (new MechanismNameForge)->forge(new AiMarketingVslAsset([
            'mechanism_name' => 'allocation rule', 'core_promise' => 'protect capital', 'niche' => 'finance',
        ]));
        $names = array_column($r['candidates'], 'name');
        $this->assertSame($names, array_values(array_unique($names)), 'candidates must be deduped');
        // Ranked descending by score.
        $scores = array_column($r['candidates'], 'score');
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores);
    }

    public function test_cross_niche_core_noun_is_used(): void
    {
        $rel = (new MechanismNameForge)->forge(new AiMarketingVslAsset([
            'mechanism_name' => 'reconnection sequence', 'niche' => 'relationship',
        ]));
        $this->assertStringContainsStringIgnoringCase('Reconnection', $rel['best']);
    }
}
