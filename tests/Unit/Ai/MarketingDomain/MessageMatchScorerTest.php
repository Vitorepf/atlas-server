<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Scoring\MessageMatchScorer;
use PHPUnit\Framework\TestCase;

class MessageMatchScorerTest extends TestCase
{
    private MessageMatchScorer $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new MessageMatchScorer;
    }

    public function test_strong_congruency_scores_high(): void
    {
        $s = $this->svc->score(
            'Pink Gelatin Weight Loss Recipe',
            'The Pink Gelatin Weight Loss Recipe',
            'lose weight with the pink gelatin recipe',
        );

        $this->assertGreaterThanOrEqual(70, $s['score']);
        $this->assertSame([], $s['missing_keywords']);
    }

    public function test_incongruent_page_scores_low_and_lists_missing_keywords(): void
    {
        $s = $this->svc->score(
            'Pink Gelatin Weight Loss',
            'Buy Cheap Supplements Online',
            'pink gelatin recipe',
        );

        $this->assertLessThan(40, $s['score']);
        $this->assertContains('gelatin', $s['missing_keywords']);
        $this->assertGreaterThan(0, $s['delta']);
    }

    public function test_diagnose_recommends_h1_angles_from_vsl(): void
    {
        $vsl = new AiMarketingVslAsset([
            'core_promise' => 'lose 30 pounds',
            'mechanism_name' => 'Pink Gelatin Protocol',
            'problem_mechanism' => 'slow metabolism',
            'power_phrases' => ['the pink trick'],
        ]);

        $d = $this->svc->diagnoseHeadlineMismatch('Pink Gelatin Weight Loss', 'Cheap Diet Pills', $vsl);

        $this->assertArrayHasKey('mismatch_type', $d);
        $this->assertNotEmpty($d['recommended_h1_angles']);
        $this->assertContains('mecanismo: Pink Gelatin Protocol', $d['recommended_h1_angles']);
    }

    public function test_deterministic(): void
    {
        $this->assertSame(
            $this->svc->score('a b c', 'a b d', 'a e f'),
            $this->svc->score('a b c', 'a b d', 'a e f'),
        );
    }
}
