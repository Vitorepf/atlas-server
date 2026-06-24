<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordOutcomeCalibrator;
use PHPUnit\Framework\TestCase;

/**
 * Locks the L10 flywheel: from real outcomes (clicks/conversions), a winner is up-weighted and a
 * plausible-but-non-converting loser is down-weighted — the PRECISION the text classifier can't give.
 * Bayesian shrinkage prevents low-sample overfit; unseen terms stay neutral (no fabricated precision).
 */
class KeywordOutcomeCalibratorTest extends TestCase
{
    private KeywordOutcomeCalibrator $c;

    protected function setUp(): void
    {
        $this->c = new KeywordOutcomeCalibrator;
    }

    public function test_winner_up_loser_down(): void
    {
        $cal = $this->c->calibrate([
            ['term' => 'gelatin trick', 'clicks' => 200, 'conversions' => 12], // winner
            ['term' => 'gelatin recipe', 'clicks' => 200, 'conversions' => 0], // loser (clicks, no sale)
        ]);

        $winner = $this->c->weightFor('gelatin trick', $cal);
        $loser = $this->c->weightFor('gelatin recipe', $cal);
        $this->assertGreaterThan(1.0, $winner, 'o que vendeu sobe');
        $this->assertLessThan(1.0, $loser, 'o loser plausível-mas-não-vende desce');
        $this->assertGreaterThan($loser, $winner);
    }

    public function test_unseen_term_is_neutral(): void
    {
        $cal = $this->c->calibrate([['term' => 'x', 'clicks' => 50, 'conversions' => 1]]);
        $this->assertSame(1.0, $this->c->weightFor('never seen', $cal));
    }

    public function test_apply_separates_twins_by_real_outcome(): void
    {
        // two text-twins with the SAME base score, separated only by what really sold.
        $cal = $this->c->calibrate([
            ['term' => 'how to lower blood sugar', 'clicks' => 300, 'conversions' => 15],
            ['term' => 'how to lower a1c', 'clicks' => 300, 'conversions' => 0],
        ]);
        $sold = $this->c->apply(55, 'how to lower blood sugar', $cal);
        $flopped = $this->c->apply(55, 'how to lower a1c', $cal);
        $this->assertGreaterThan($sold - 1, $sold); // sanity
        $this->assertGreaterThan($flopped, $sold, 'mesma estrutura de texto, score calibrado pela venda real os separa');
        $this->assertLessThan(40, $flopped, 'o loser cai abaixo da qualificação');
    }

    public function test_low_sample_does_not_overfit(): void
    {
        // 1 lucky sale in 2 clicks must NOT explode to a huge weight (Bayesian shrinkage).
        $cal = $this->c->calibrate([
            ['term' => 'big', 'clicks' => 5000, 'conversions' => 50],
            ['term' => 'lucky', 'clicks' => 2, 'conversions' => 1],
        ]);
        $this->assertLessThan(2.5, $this->c->weightFor('lucky', $cal));
    }
}
