<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8TrustPenaltyAndDemotePolicy;
use PHPUnit\Framework\TestCase;

final class L8TrustPenaltyAndDemotePolicyTest extends TestCase
{
    private L8TrustPenaltyAndDemotePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new L8TrustPenaltyAndDemotePolicy();
    }

    public function testDecideReturnsCanonicalSchemaVersion(): void
    {
        $result = $this->policy->decide(
            ['gaming_detected' => true, 'severity' => 0.4],
            ['current_trust_score' => 0.97, 'ledger_evidence_present' => true],
        );

        $this->assertSame('atlas.aaeos.l8.trust_penalty_policy.v1', $result['schema_version']);
    }

    public function testGamingDetectedLowersProjectedScoreAndDemotesWhenThresholdCrossed(): void
    {
        $result = $this->policy->decide(
            ['gaming_detected' => true, 'severity' => 1.0],
            ['current_trust_score' => 0.98, 'ledger_evidence_present' => true],
        );

        // Full-severity gaming subtracts the max penalty (1.0 * 0.5 = 0.5).
        $this->assertTrue($result['gaming_detected']);
        $this->assertEqualsWithDelta(0.5, $result['penalty_applied'], 1e-9);
        $this->assertEqualsWithDelta(0.48, $result['projected_trust_score'], 1e-9);
        // Projected 0.48 < 0.95 demote gate -> demote forced without signature.
        $this->assertTrue($result['demote_required']);
        $this->assertSame('demote_required', $result['status']);
        // The projected score is strictly below the unchanged current score.
        $this->assertLessThan($result['current_trust_score'], $result['projected_trust_score']);
    }

    public function testGamingBelowThresholdPenalizesButDoesNotDemote(): void
    {
        $result = $this->policy->decide(
            ['gaming_detected' => true, 'severity' => 0.02],
            ['current_trust_score' => 0.99, 'ledger_evidence_present' => true],
        );

        // Tiny severity: 0.02 * 0.5 = 0.01 penalty -> projected 0.98, still >= 0.95.
        $this->assertTrue($result['gaming_detected']);
        $this->assertEqualsWithDelta(0.01, $result['penalty_applied'], 1e-9);
        $this->assertEqualsWithDelta(0.98, $result['projected_trust_score'], 1e-9);
        $this->assertFalse($result['demote_required']);
        $this->assertSame('trust_penalized', $result['status']);
        $this->assertLessThan($result['current_trust_score'], $result['projected_trust_score']);
    }

    public function testProjectedScoreExactlyOnTheDemoteGateDoesNotDemote(): void
    {
        // 0.95 is the L7-eligible floor: a projected score sitting EXACTLY on the
        // gate stays eligible (the gate is strict `< 0.95`, not `<= 0.95`). A
        // regression to `<=` would wrongly demote a still-eligible score.
        $onGate = $this->policy->decide(
            ['gaming_detected' => true, 'severity' => 0.1],
            ['current_trust_score' => 1.0, 'ledger_evidence_present' => true],
        );

        // 0.1 * 0.5 = 0.05 penalty -> projected 1.0 - 0.05 = 0.95, exactly the gate.
        $this->assertEqualsWithDelta(0.05, $onGate['penalty_applied'], 1e-9);
        $this->assertEqualsWithDelta(0.95, $onGate['projected_trust_score'], 1e-9);
        $this->assertFalse($onGate['demote_required']);
        $this->assertSame('trust_penalized', $onGate['status']);

        // One increment of penalty below the gate (projected 0.949999) DOES demote,
        // pinning the gate direction: eligible at the floor, demoted just beneath it.
        $belowGate = $this->policy->decide(
            ['gaming_detected' => true, 'severity' => 0.100002],
            ['current_trust_score' => 1.0, 'ledger_evidence_present' => true],
        );

        $this->assertLessThan(0.95, $belowGate['projected_trust_score']);
        $this->assertTrue($belowGate['demote_required']);
        $this->assertSame('demote_required', $belowGate['status']);
    }

    public function testNoDivergenceLeavesTrustUnchanged(): void
    {
        $result = $this->policy->decide(
            ['gaming_detected' => false, 'divergence_detected' => false],
            ['current_trust_score' => 0.92, 'ledger_evidence_present' => true],
        );

        $this->assertFalse($result['gaming_detected']);
        $this->assertSame('trust_unchanged', $result['status']);
        $this->assertEqualsWithDelta(0.0, $result['penalty_applied'], 1e-9);
        // Projected score equals the current score exactly: trust is untouched.
        $this->assertSame($result['current_trust_score'], $result['projected_trust_score']);
        $this->assertEqualsWithDelta(0.92, $result['projected_trust_score'], 1e-9);
        $this->assertFalse($result['demote_required']);
        $this->assertSame([], $result['blockers']);
    }

    public function testNoLedgerEvidenceReturnsUnknownBlocked(): void
    {
        $result = $this->policy->decide(
            ['gaming_detected' => true, 'severity' => 1.0],
            ['current_trust_score' => 0.96, 'ledger_evidence_present' => false],
        );

        $this->assertSame('unknown_blocked', $result['status']);
        $this->assertFalse($result['ledger_evidence_present']);
        $this->assertSame(['no_ledger_evidence'], $result['blockers']);
        // Fail-closed: no penalty is projected and no demote is forced without evidence.
        $this->assertEqualsWithDelta(0.0, $result['penalty_applied'], 1e-9);
        $this->assertFalse($result['demote_required']);
        $this->assertSame($result['current_trust_score'], $result['projected_trust_score']);
    }

    public function testTrustFollowsIndependentRealityNotSelfReportedSuccess(): void
    {
        // The trust state self-reports a perfect score, but an independent
        // divergence signal proves gaming -> the reported success must NOT
        // protect the score from the penalty and demote.
        $result = $this->policy->decide(
            [
                'divergence_detected' => true,
                'divergent_metrics' => ['useful_cycle_rate', 'trust_ledger_score', 'dm_dt'],
                'anchor_count' => 5,
            ],
            [
                'current_trust_score' => 1.0,
                'self_reported_success' => true,
                'ledger_evidence_present' => true,
            ],
        );

        $this->assertTrue($result['gaming_detected']);
        // 3 divergent metrics over a 5-anchor population -> severity 0.6.
        $this->assertEqualsWithDelta(0.6, $result['severity'], 1e-9);
        // 0.6 * 0.5 = 0.30 penalty -> projected 0.70, below the 0.95 gate.
        $this->assertEqualsWithDelta(0.3, $result['penalty_applied'], 1e-9);
        $this->assertEqualsWithDelta(0.7, $result['projected_trust_score'], 1e-9);
        $this->assertTrue($result['demote_required']);
        $this->assertSame('demote_required', $result['status']);
    }

    public function testSeverityDerivedFromAdversarialCasesWhenNoExplicitSeverity(): void
    {
        // S104-shaped payload: adversarial cases over an anchor population, no
        // explicit severity supplied -> severity computed as the share.
        $result = $this->policy->decide(
            [
                'gaming_detected' => true,
                'adversarial_cases' => [['case' => 'a'], ['case' => 'b']],
                'optimized_metric_count' => 4,
            ],
            ['current_trust_score' => 0.99, 'ledger_evidence_present' => true],
        );

        // 2 cases / 4 metrics = 0.5 severity -> 0.25 penalty -> 0.74 projected.
        $this->assertEqualsWithDelta(0.5, $result['severity'], 1e-9);
        $this->assertEqualsWithDelta(0.25, $result['penalty_applied'], 1e-9);
        $this->assertEqualsWithDelta(0.74, $result['projected_trust_score'], 1e-9);
        $this->assertTrue($result['demote_required']);
    }

    public function testProjectedScoreNeverFallsBelowZeroForOverwhelmingPenalty(): void
    {
        // Already-low score plus full-severity gaming must clamp at the 0.0
        // floor rather than going negative.
        $result = $this->policy->decide(
            ['gaming_detected' => true, 'severity' => 1.0],
            ['current_trust_score' => 0.2, 'ledger_evidence_present' => true],
        );

        $this->assertGreaterThanOrEqual(0.0, $result['projected_trust_score']);
        $this->assertEqualsWithDelta(0.0, $result['projected_trust_score'], 1e-9);
        $this->assertTrue($result['demote_required']);
    }

    public function testScoresAndSeverityStayWithinUnitBoundsAcrossManyInputs(): void
    {
        $scores = [0.0, 0.13, 0.5, 0.87, 0.95, 0.999, 1.0, 1.7, -0.4];
        $severities = [0.0, 0.07, 0.33, 0.5, 0.91, 1.0, 2.5, -1.0];

        foreach ($scores as $score) {
            foreach ($severities as $severity) {
                $result = $this->policy->decide(
                    ['gaming_detected' => true, 'severity' => $severity],
                    ['current_trust_score' => $score, 'ledger_evidence_present' => true],
                );

                $this->assertGreaterThanOrEqual(0.0, $result['projected_trust_score']);
                $this->assertLessThanOrEqual(1.0, $result['projected_trust_score']);
                $this->assertGreaterThanOrEqual(0.0, $result['current_trust_score']);
                $this->assertLessThanOrEqual(1.0, $result['current_trust_score']);
                $this->assertGreaterThanOrEqual(0.0, $result['severity']);
                $this->assertLessThanOrEqual(1.0, $result['severity']);
                $this->assertGreaterThanOrEqual(0.0, $result['penalty_applied']);
                $this->assertLessThanOrEqual(0.5, $result['penalty_applied']);
            }
        }
    }

    public function testBlockersFieldIsAlwaysAListOfStrings(): void
    {
        $unknown = $this->policy->decide(
            ['gaming_detected' => true],
            ['current_trust_score' => 0.9],
        );
        $penalized = $this->policy->decide(
            ['gaming_detected' => true, 'severity' => 0.5],
            ['current_trust_score' => 0.9, 'ledger_evidence_present' => true],
        );

        foreach ([$unknown, $penalized] as $result) {
            $this->assertArrayHasKey('blockers', $result);
            $this->assertSame(array_values($result['blockers']), $result['blockers']);
            foreach ($result['blockers'] as $blocker) {
                $this->assertIsString($blocker);
            }
        }
    }

    public function testLedgerEvidenceInferredFromEventCountWhenFlagAbsent(): void
    {
        // No explicit flag, but event_count proves ledger evidence exists.
        $result = $this->policy->decide(
            ['gaming_detected' => false],
            ['current_trust_score' => 0.88, 'event_count' => 12],
        );

        $this->assertTrue($result['ledger_evidence_present']);
        $this->assertSame('trust_unchanged', $result['status']);
        $this->assertSame([], $result['blockers']);
    }

    public function testDecisionIsDeterministicForIdenticalInput(): void
    {
        $divergence = ['gaming_detected' => true, 'severity' => 0.45];
        $trust = ['current_trust_score' => 0.96, 'ledger_evidence_present' => true];

        $first = $this->policy->decide($divergence, $trust);
        $second = $this->policy->decide($divergence, $trust);

        $this->assertSame($first, $second);
    }
}
