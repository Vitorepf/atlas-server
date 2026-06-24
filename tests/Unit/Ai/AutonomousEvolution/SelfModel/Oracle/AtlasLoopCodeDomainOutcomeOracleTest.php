<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SelfModel\Oracle;

use App\Services\Ai\AutonomousEvolution\SelfModel\Oracle\AtlasLoopCodeDomainOutcomeOracle;
use App\Services\Ai\AutonomousEvolution\SelfModel\Oracle\AtlasLoopModelOutcomeOracle;
use PHPUnit\Framework\TestCase;

/**
 * Proves the code-domain outcome oracle: domain id, fact-derived score (certified × net_behavior_delta),
 * grounded only when the facts are present, and the hard anti-self-grading rule — a self_score field is
 * ignored, the score comes from facts.
 */
final class AtlasLoopCodeDomainOutcomeOracleTest extends TestCase
{
    private function oracle(): AtlasLoopCodeDomainOutcomeOracle
    {
        return new AtlasLoopCodeDomainOutcomeOracle;
    }

    public function test_it_is_a_model_outcome_oracle_for_the_code_domain(): void
    {
        $oracle = $this->oracle();
        $this->assertInstanceOf(AtlasLoopModelOutcomeOracle::class, $oracle);
        $this->assertSame('code', $oracle->domain());
    }

    public function test_certified_delivery_scores_its_net_behavior_delta(): void
    {
        $out = $this->oracle()->scoreOutcome(['net_behavior_delta' => 3, 'certified' => true]);

        $this->assertSame(3.0, $out['score']);
        $this->assertTrue($out['grounded']);
        $this->assertSame('atlas.loop.model_outcome_score.v1', $out['schema']);
    }

    public function test_uncertified_delivery_scores_zero(): void
    {
        $out = $this->oracle()->scoreOutcome(['net_behavior_delta' => 9, 'certified' => false]);

        $this->assertSame(0.0, $out['score']);
        $this->assertTrue($out['grounded'], 'both facts present ⇒ grounded, even when uncertified');
    }

    public function test_missing_net_behavior_delta_is_ungrounded(): void
    {
        $out = $this->oracle()->scoreOutcome(['certified' => true]);

        $this->assertFalse($out['grounded']);
        $this->assertSame(0.0, $out['score']);
        $this->assertSame('ungrounded', $out['basis']);
    }

    public function test_self_declared_score_is_ignored(): void
    {
        // self_score=999 must NOT leak into the result — the score is computed from facts.
        $out = $this->oracle()->scoreOutcome(['net_behavior_delta' => 2, 'certified' => true, 'self_score' => 999]);

        $this->assertSame(2.0, $out['score'], 'score is the fact-derived value, not the self-declared 999');
    }
}
