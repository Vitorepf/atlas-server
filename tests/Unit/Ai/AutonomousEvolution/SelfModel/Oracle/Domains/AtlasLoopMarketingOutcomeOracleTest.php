<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SelfModel\Oracle\Domains;

use App\Services\Ai\AutonomousEvolution\SelfModel\Oracle\AtlasLoopModelOutcomeOracle;
use App\Services\Ai\AutonomousEvolution\SelfModel\Oracle\Domains\AtlasLoopMarketingOutcomeOracle;
use PHPUnit\Framework\TestCase;

/**
 * Proves the marketing outcome oracle: domain="marketing"; conversions/spend scoring; grounded only when both
 * facts present and spend>0; and that a self-declared self_score is ignored.
 */
final class AtlasLoopMarketingOutcomeOracleTest extends TestCase
{
    private function oracle(): AtlasLoopMarketingOutcomeOracle
    {
        return new AtlasLoopMarketingOutcomeOracle;
    }

    public function test_implements_the_contract_and_domain(): void
    {
        $this->assertInstanceOf(AtlasLoopModelOutcomeOracle::class, $this->oracle());
        $this->assertSame('marketing', $this->oracle()->domain());
    }

    public function test_scores_conversions_per_spend_when_grounded(): void
    {
        $out = $this->oracle()->scoreOutcome(['conversions' => 10, 'spend' => 5]);

        $this->assertTrue($out['grounded']);
        $this->assertSame(2.0, $out['score']);
        $this->assertSame('conversions_per_spend', $out['basis']);
        $this->assertSame('atlas.loop.model_outcome.marketing.v1', $out['schema']);
    }

    public function test_missing_spend_is_ungrounded(): void
    {
        $out = $this->oracle()->scoreOutcome(['conversions' => 10]);

        $this->assertFalse($out['grounded']);
        $this->assertSame(0.0, $out['score']);
        $this->assertSame('ungrounded', $out['basis']);
    }

    public function test_zero_spend_is_ungrounded_no_division_by_zero(): void
    {
        $out = $this->oracle()->scoreOutcome(['conversions' => 10, 'spend' => 0]);

        $this->assertFalse($out['grounded']);
        $this->assertSame(0.0, $out['score']);
    }

    public function test_self_score_field_is_ignored(): void
    {
        $out = $this->oracle()->scoreOutcome(['conversions' => 4, 'spend' => 2, 'self_score' => 999]);

        $this->assertSame(2.0, $out['score'], 'score is computed from facts, never the self-declared field');
        $this->assertTrue($out['grounded']);
    }
}
