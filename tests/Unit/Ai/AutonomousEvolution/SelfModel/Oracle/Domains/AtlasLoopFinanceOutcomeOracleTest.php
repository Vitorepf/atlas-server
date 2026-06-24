<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SelfModel\Oracle\Domains;

use App\Services\Ai\AutonomousEvolution\SelfModel\Oracle\AtlasLoopModelOutcomeOracle;
use App\Services\Ai\AutonomousEvolution\SelfModel\Oracle\Domains\AtlasLoopFinanceOutcomeOracle;
use PHPUnit\Framework\TestCase;

final class AtlasLoopFinanceOutcomeOracleTest extends TestCase
{
    private function oracle(): AtlasLoopFinanceOutcomeOracle
    {
        return new AtlasLoopFinanceOutcomeOracle;
    }

    public function test_implements_contract_and_domain(): void
    {
        $this->assertInstanceOf(AtlasLoopModelOutcomeOracle::class, $this->oracle());
        $this->assertSame('finance', $this->oracle()->domain());
    }

    public function test_reconciled_zero_discrepancy_scores_balanced(): void
    {
        $out = $this->oracle()->scoreOutcome(['reconciled' => true, 'discrepancy_cents' => 0]);

        $this->assertSame('atlas.loop.model_outcome.finance.v1', $out['schema']);
        $this->assertTrue($out['grounded']);
        $this->assertSame(1.0, $out['score']);
        $this->assertSame('balanced', $out['basis']);
    }

    public function test_non_zero_discrepancy_never_scores(): void
    {
        $out = $this->oracle()->scoreOutcome(['reconciled' => true, 'discrepancy_cents' => 50]);

        $this->assertTrue($out['grounded']);
        $this->assertSame(0.0, $out['score']);
        $this->assertSame('unbalanced', $out['basis']);
    }

    public function test_missing_reconciled_or_discrepancy_is_ungrounded(): void
    {
        $missingReconciled = $this->oracle()->scoreOutcome(['discrepancy_cents' => 0]);
        $missingDiscrepancy = $this->oracle()->scoreOutcome(['reconciled' => true]);

        $this->assertFalse($missingReconciled['grounded']);
        $this->assertSame(0.0, $missingReconciled['score']);
        $this->assertSame('ungrounded', $missingReconciled['basis']);
        $this->assertFalse($missingDiscrepancy['grounded']);
        $this->assertSame(0.0, $missingDiscrepancy['score']);
        $this->assertSame('ungrounded', $missingDiscrepancy['basis']);
    }

    public function test_self_score_is_ignored(): void
    {
        $out = $this->oracle()->scoreOutcome([
            'reconciled' => true,
            'discrepancy_cents' => 50,
            'self_score' => 1.0,
        ]);

        $this->assertSame(0.0, $out['score']);
        $this->assertSame('unbalanced', $out['basis']);
    }
}
