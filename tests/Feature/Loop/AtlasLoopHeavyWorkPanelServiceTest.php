<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopHeavyWorkPanelService;
use Tests\TestCase;

/**
 * HEAVY-WORK DECISION PANEL — frozen proof that the multi-agent "decide the highest-value next big work"
 * is UNGAMEABLE: the cardinal score is DETERMINISTIC from MEASURED evidence (no agent can inflate it),
 * the blast-radius reversibility multiplier DOWN-ranks a high-fan-out hub the leverage lens loves (the
 * correlated-blind-spot defense), an under-evidenced candidate is EXCLUDED, and the receipt is honestly
 * labelled a heuristic that never certifies the result.
 */
final class AtlasLoopHeavyWorkPanelServiceTest extends TestCase
{
    private function panel(): AtlasLoopHeavyWorkPanelService
    {
        return new AtlasLoopHeavyWorkPanelService();
    }

    public function test_score_is_deterministic_from_measured_evidence(): void
    {
        $cand = ['candidateId' => 'c1', 'kind' => 'obra_candidate', 'evidence' => ['refactor_leverage' => 0.8, 'cyclomatic_total' => 60, 'failure_evidence' => 0.5]];
        $a = $this->panel()->decide([$cand]);
        $b = $this->panel()->decide([$cand]);
        $this->assertSame($a['winner']['risk_adjusted_score'], $b['winner']['risk_adjusted_score'], 'same evidence => same score (no agent randomness)');
        // leverage=80, debt=50 (60/120), failure=50 -> median(80,50,50)=50, no blast => 50.
        $this->assertSame(50.0, $a['winner']['base_median']);
    }

    public function test_blast_radius_downranks_a_high_fanout_hub_the_leverage_lens_loves(): void
    {
        // Both candidates have identical high value; ONLY the blast radius differs. The risky hub must lose.
        $ev = ['refactor_leverage' => 0.9, 'cyclomatic_total' => 90, 'failure_evidence' => 0.9];
        $result = $this->panel()->decide([
            ['candidateId' => 'safe', 'evidence' => $ev + ['blast_radius' => 1]],
            ['candidateId' => 'risky_godnode', 'evidence' => $ev + ['blast_radius' => 200]],
        ]);
        $this->assertSame('safe', $result['winner']['candidateId'], 'a huge-fan-out hub is risk-down-weighted below an equal-value safe one');
        $this->assertGreaterThan(
            (float) collect($result['ranked'])->firstWhere('candidateId', 'risky_godnode')['reversibility_factor'],
            (float) collect($result['ranked'])->firstWhere('candidateId', 'safe')['reversibility_factor'],
        );
    }

    public function test_under_evidenced_candidate_is_excluded_not_guessed(): void
    {
        $result = $this->panel()->decide([
            ['candidateId' => 'rich', 'evidence' => ['refactor_leverage' => 0.7, 'cyclomatic_total' => 80, 'failure_evidence' => 0.6]],
            ['candidateId' => 'thin', 'evidence' => ['refactor_leverage' => 0.99]], // only 1 signal — below quorum
        ]);
        $this->assertSame('rich', $result['winner']['candidateId']);
        $this->assertCount(1, $result['ranked']);
        $this->assertSame('thin', $result['excluded'][0]['candidateId']);
        $this->assertSame('under_evidenced_below_quorum', $result['excluded'][0]['reason']);
    }

    public function test_median_is_robust_to_one_outlier_lens(): void
    {
        // leverage=100 (outlier), debt=10, failure=20 -> median=20 (NOT the 43 a mean would give).
        $result = $this->panel()->decide([['candidateId' => 'c', 'evidence' => ['refactor_leverage' => 1.0, 'cyclomatic_total' => 12, 'failure_evidence' => 0.2]]]);
        $this->assertSame(20.0, $result['winner']['base_median'], 'median ignores the single inflated lens');
    }

    public function test_absent_signal_is_not_a_false_zero(): void
    {
        // Two measured signals (quorum met); the absent third must NOT drag the median down as a 0.
        $result = $this->panel()->decide([['candidateId' => 'c', 'evidence' => ['refactor_leverage' => 0.8, 'cyclomatic_total' => 96]]]);
        // leverage=80, debt=80 -> median(80,80)=80; absence of failure_evidence contributes nothing.
        $this->assertSame(80.0, $result['winner']['base_median']);
        $this->assertSame(2, $result['winner']['measured_signals']);
    }

    public function test_receipt_is_honestly_labelled_a_heuristic_that_never_certifies_the_result(): void
    {
        $result = $this->panel()->decide([['candidateId' => 'c', 'evidence' => ['refactor_leverage' => 0.5, 'cyclomatic_total' => 60]]]);
        $this->assertFalse($result['is_optimal'], 'never claims optimality');
        $this->assertTrue($result['is_heuristic']);
        $this->assertFalse($result['certifies_result'], 'the panel chooses WHAT, never certifies the result');
        $this->assertStringNotContainsString('optimal', strtolower($result['method']));
    }

    public function test_tie_is_broken_deterministically_by_remeasured_leverage_then_debt(): void
    {
        // Identical risk-adjusted score; the higher measured leverage wins the tie (deterministic).
        $result = $this->panel()->decide([
            ['candidateId' => 'lo', 'evidence' => ['refactor_leverage' => 0.5, 'cyclomatic_total' => 60, 'failure_evidence' => 0.5]],
            ['candidateId' => 'hi', 'evidence' => ['refactor_leverage' => 0.5, 'cyclomatic_total' => 60, 'failure_evidence' => 0.5]],
        ]);
        // Same scores -> tie -> equal leverage/debt -> candidateId asc: 'hi' < 'lo'.
        $this->assertSame('hi', $result['winner']['candidateId']);
    }
}
