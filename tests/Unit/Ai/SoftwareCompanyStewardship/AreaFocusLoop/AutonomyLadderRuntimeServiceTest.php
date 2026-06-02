<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomyLadderRuntimeService;
use PHPUnit\Framework\TestCase;

final class AutonomyLadderRuntimeServiceTest extends TestCase
{
    private AutonomyLadderRuntimeService $service;

    protected function setUp(): void
    {
        $this->service = new AutonomyLadderRuntimeService();
    }

    public function testL0ToL1PromotionIsClearWhenCriteriaSignatureAndEvidenceHold(): void
    {
        $result = $this->service->evaluate(
            ['current_level' => 'L0', 'operator' => true],
            [
                'assist_sessions' => 60,
                'acceptance_rate' => 0.92,
                'severe_hallucination_count' => 0,
            ],
            ['evidence://assist/2026-06-01'],
        );

        $this->assertSame('atlas.autonomy.ladder_runtime.v1', $result['schema_version']);
        $this->assertSame('L0', $result['current_level']);
        $this->assertSame('L1', $result['next_level']);
        $this->assertSame([], $result['promotion_blockers']);
        $this->assertFalse($result['demote_required']);
        $this->assertSame(['evidence://assist/2026-06-01'], $result['evidence_refs']);
    }

    public function testL0BelowThresholdReportsTheExactUnmetExitMetric(): void
    {
        // acceptance_rate 0.79 < 0.80 must surface as a precise per-metric blocker;
        // this input is NOT a canned happy/sad pair, it exercises one failing rule.
        $result = $this->service->evaluate(
            ['current_level' => 'L0', 'operator' => true],
            [
                'assist_sessions' => 50,
                'acceptance_rate' => 0.79,
                'severe_hallucination_count' => 0,
            ],
            ['evidence://assist'],
        );

        $this->assertSame('L0', $result['current_level']);
        $this->assertSame('L1', $result['next_level']);
        $this->assertSame(['exit_criterion_unmet:acceptance_rate'], $result['promotion_blockers']);
    }

    public function testMissingEvidenceNeverPassesEvenWithPerfectMetrics(): void
    {
        $result = $this->service->evaluate(
            ['current_level' => 'L0', 'operator' => true],
            [
                'assist_sessions' => 999,
                'acceptance_rate' => 1.0,
                'severe_hallucination_count' => 0,
            ],
            [],
        );

        $this->assertContains('missing_evidence', $result['promotion_blockers']);
        $this->assertSame([], $result['evidence_refs']);
    }

    public function testL4PromotionBlocksWithoutDualSignature(): void
    {
        $result = $this->service->evaluate(
            ['current_level' => 'L4', 'operator' => true],
            [
                'consecutive_cert_green_obras' => 6,
                'cert_phase_rollback_count' => 0,
                'dual_signature_count' => 5,
            ],
            ['evidence://obra/cert'],
        );

        $this->assertSame('L4', $result['current_level']);
        $this->assertSame('L5', $result['next_level']);
        $this->assertSame(['signature_missing:architect'], $result['promotion_blockers']);
    }

    public function testL4PromotionClearsWithDualSignature(): void
    {
        $result = $this->service->evaluate(
            ['current_level' => 'L4', 'operator' => true, 'architect' => true],
            [
                'consecutive_cert_green_obras' => 6,
                'cert_phase_rollback_count' => 0,
                'dual_signature_count' => 5,
            ],
            ['evidence://obra/cert'],
        );

        $this->assertSame('L5', $result['next_level']);
        $this->assertSame([], $result['promotion_blockers']);
        $this->assertFalse($result['demote_required']);
    }

    public function testL7EntryRequiresTrustLedgerScoreAtLeastPointNineFive(): void
    {
        $area = [
            'current_level' => 'L6',
            'operator' => true,
            'architect' => true,
            'architect_human_review' => true,
        ];
        $metrics = [
            'days_with_3plus_departments' => 30,
            'cross_dept_blocker_resolution_p95_hours' => 2,
        ];

        $atThreshold = $this->service->evaluate(
            $area,
            $metrics + ['trust_ledger_score' => 0.95],
            ['evidence://conductor'],
        );

        $this->assertSame('L6', $atThreshold['current_level']);
        $this->assertSame('L7', $atThreshold['next_level']);
        $this->assertSame([], $atThreshold['promotion_blockers']);

        $belowThreshold = $this->service->evaluate(
            $area,
            $metrics + ['trust_ledger_score' => 0.94],
            ['evidence://conductor'],
        );

        $this->assertSame(['trust_ledger_below_threshold'], $belowThreshold['promotion_blockers']);
    }

    public function testTwoConsecutiveBelowThresholdCyclesSetDemoteRequired(): void
    {
        $result = $this->service->evaluate(
            ['current_level' => 'L4', 'operator' => true, 'architect' => true],
            [
                'recent_cycles' => [
                    [
                        'consecutive_cert_green_obras' => 6,
                        'cert_phase_rollback_count' => 0,
                        'dual_signature_count' => 5,
                    ],
                    [
                        'consecutive_cert_green_obras' => 6,
                        'cert_phase_rollback_count' => 2,
                        'dual_signature_count' => 5,
                    ],
                    [
                        'consecutive_cert_green_obras' => 1,
                        'cert_phase_rollback_count' => 0,
                        'dual_signature_count' => 5,
                    ],
                ],
            ],
            ['evidence://obra/cert'],
        );

        $this->assertTrue($result['demote_required']);
    }

    public function testSingleBreachingCycleDoesNotDemote(): void
    {
        // Only the most-recent cycle breaches; the one before it is healthy, so
        // the 2-consecutive rule must NOT fire.
        $result = $this->service->evaluate(
            ['current_level' => 'L4', 'operator' => true, 'architect' => true],
            [
                'recent_cycles' => [
                    [
                        'consecutive_cert_green_obras' => 1,
                        'cert_phase_rollback_count' => 0,
                        'dual_signature_count' => 5,
                    ],
                    [
                        'consecutive_cert_green_obras' => 6,
                        'cert_phase_rollback_count' => 0,
                        'dual_signature_count' => 5,
                    ],
                    [
                        'consecutive_cert_green_obras' => 4,
                        'cert_phase_rollback_count' => 0,
                        'dual_signature_count' => 5,
                    ],
                ],
            ],
            ['evidence://obra/cert'],
        );

        $this->assertFalse($result['demote_required']);
    }

    public function testFloorLevelNeverDemotes(): void
    {
        $result = $this->service->evaluate(
            ['current_level' => 'L0', 'operator' => true],
            [
                'recent_cycles' => [
                    ['assist_sessions' => 0],
                    ['assist_sessions' => 0],
                ],
            ],
            ['evidence://assist'],
        );

        $this->assertFalse($result['demote_required']);
    }

    public function testTopOfLadderHasNoNextLevelAndReportsCeiling(): void
    {
        $result = $this->service->evaluate(
            ['current_level' => 'L7', 'operator' => true, 'architect' => true, 'architect_human_review' => true],
            [
                'approved_self_construction_proposals' => 12,
                'broken_invariant_count' => 0,
                'trust_ledger_score' => 0.99,
            ],
            ['evidence://self-evolving'],
        );

        $this->assertSame('L7', $result['current_level']);
        $this->assertNull($result['next_level']);
        $this->assertSame(['at_ceiling'], $result['promotion_blockers']);
    }

    public function testEvidenceRefsAreTrimmedDeduplicatedAndStringOnly(): void
    {
        $result = $this->service->evaluate(
            ['current_level' => 'L1', 'operator' => true],
            [
                'consecutive_green_slices' => 20,
                'scope_violation_count' => 0,
                'repair_loop_count' => 1,
            ],
            ['  evidence://a  ', 'evidence://a', '', 0, 'evidence://b'],
        );

        $this->assertSame(['evidence://a', 'evidence://b'], $result['evidence_refs']);
        $this->assertSame('L2', $result['next_level']);
        $this->assertSame([], $result['promotion_blockers']);
    }

    public function testUnknownLevelFallsBackToFloor(): void
    {
        $result = $this->service->evaluate(
            ['current_level' => 'L99'],
            [],
            ['evidence://x'],
        );

        $this->assertSame('L0', $result['current_level']);
        $this->assertSame('L1', $result['next_level']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        // L2 -> L3 needs only a single (operator) signature, so a complete set of
        // inputs clears with zero blockers; running twice must be byte-identical.
        $area = ['current_level' => 'L2', 'operator' => true];
        $metrics = [
            'green_pair_obras' => 30,
            'regression_catch_rate' => 0.90,
        ];
        $evidence = ['evidence://pair'];

        $first = $this->service->evaluate($area, $metrics, $evidence);
        $second = $this->service->evaluate($area, $metrics, $evidence);

        $this->assertSame($first, $second);
        $this->assertSame('L3', $first['next_level']);
        $this->assertSame([], $first['promotion_blockers']);
    }
}
