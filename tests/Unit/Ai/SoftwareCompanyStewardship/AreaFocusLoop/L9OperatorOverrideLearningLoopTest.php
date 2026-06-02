<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9OperatorOverrideLearningLoop;
use PHPUnit\Framework\TestCase;

final class L9OperatorOverrideLearningLoopTest extends TestCase
{
    private L9OperatorOverrideLearningLoop $loop;

    protected function setUp(): void
    {
        $this->loop = new L9OperatorOverrideLearningLoop();
    }

    public function testReturnsAllRequiredKeysWithSchemaVersion(): void
    {
        $result = $this->loop->learn(
            [
                ['id' => 'd1', 'decision' => 'merge', 'cluster' => 'low_risk'],
            ],
            [
                ['pre_decision_id' => 'd1', 'operator_decision' => 'merge', 'visible' => true, 'evidence_ref' => 'rcpt-1'],
            ],
        );

        $this->assertSame('atlas.loop.l9_operator_override_learning.v1', $result['schema_version']);
        $this->assertArrayHasKey('override_rate', $result);
        $this->assertArrayHasKey('divergence_clusters', $result);
        $this->assertArrayHasKey('demote_required', $result);
        $this->assertArrayHasKey('relearn_required', $result);
        $this->assertArrayHasKey('evidence_refs', $result);
    }

    public function testAgreementProducesNoDivergenceTeachOrDemote(): void
    {
        // Operator agrees with every pre-decision: nothing to teach, nothing to demote.
        $result = $this->loop->learn(
            [
                ['id' => 'd1', 'decision' => 'merge', 'cluster' => 'low_risk'],
                ['id' => 'd2', 'decision' => 'hold', 'cluster' => 'low_risk'],
            ],
            [
                ['pre_decision_id' => 'd1', 'operator_decision' => 'merge', 'visible' => true],
                ['pre_decision_id' => 'd2', 'operator_decision' => 'hold', 'visible' => true],
            ],
        );

        $this->assertSame(0.0, $result['override_rate']);
        $this->assertSame([], $result['divergence_clusters']);
        $this->assertSame(0, $result['visible_divergence_count']);
        $this->assertFalse($result['demote_required']);
        $this->assertFalse($result['relearn_required']);
        // Agreements are still surfaced as evidence — never hidden.
        $this->assertCount(2, $result['evidence_refs']);
    }

    public function testSingleVisibleDivergenceTeachesButDoesNotDemote(): void
    {
        // One disagreement in a cluster (below the demote threshold of 2):
        // it must TEACH (relearn) but NOT demote.
        $result = $this->loop->learn(
            [
                ['id' => 'd1', 'decision' => 'merge', 'cluster' => 'auth'],
                ['id' => 'd2', 'decision' => 'merge', 'cluster' => 'auth'],
                ['id' => 'd3', 'decision' => 'merge', 'cluster' => 'auth'],
                ['id' => 'd4', 'decision' => 'merge', 'cluster' => 'auth'],
            ],
            [
                ['pre_decision_id' => 'd1', 'operator_decision' => 'revert', 'visible' => true],
                ['pre_decision_id' => 'd2', 'operator_decision' => 'merge', 'visible' => true],
                ['pre_decision_id' => 'd3', 'operator_decision' => 'merge', 'visible' => true],
                ['pre_decision_id' => 'd4', 'operator_decision' => 'merge', 'visible' => true],
            ],
        );

        $this->assertSame(1, $result['visible_divergence_count']);
        // 1 divergence / 4 pre-decisions = 0.25
        $this->assertEqualsWithDelta(0.25, $result['override_rate'], 1e-9);
        $this->assertTrue($result['relearn_required']);
        $this->assertFalse($result['demote_required']);
        $this->assertSame(
            [
                ['cluster' => 'auth', 'divergence_count' => 1, 'demote' => false],
            ],
            $result['divergence_clusters'],
        );
    }

    public function testDivergenceAboveThresholdDemotes(): void
    {
        // Two disagreements in the same cluster reach the demote threshold (>= 2):
        // systematic / unsafe divergence forces a demote.
        $result = $this->loop->learn(
            [
                ['id' => 'd1', 'decision' => 'merge', 'cluster' => 'payments'],
                ['id' => 'd2', 'decision' => 'merge', 'cluster' => 'payments'],
                ['id' => 'd3', 'decision' => 'hold', 'cluster' => 'docs'],
            ],
            [
                ['pre_decision_id' => 'd1', 'operator_decision' => 'revert', 'visible' => true],
                ['pre_decision_id' => 'd2', 'operator_decision' => 'revert', 'visible' => true],
                ['pre_decision_id' => 'd3', 'operator_decision' => 'hold', 'visible' => true],
            ],
        );

        $this->assertSame(2, $result['visible_divergence_count']);
        $this->assertTrue($result['demote_required']);
        $this->assertTrue($result['relearn_required']);
        $this->assertSame(
            [
                ['cluster' => 'payments', 'divergence_count' => 2, 'demote' => true],
            ],
            $result['divergence_clusters'],
        );
    }

    public function testHiddenOverrideIsRejectedAndDemotesButNeverHidden(): void
    {
        // A hidden override (no visibility proof / flagged hidden) is rejected:
        // it never silently mutates the model, it forces a demote, and the
        // rejection is still emitted as evidence so it is never hidden.
        $result = $this->loop->learn(
            [
                ['id' => 'd1', 'decision' => 'merge', 'cluster' => 'auth'],
                ['id' => 'd2', 'decision' => 'merge', 'cluster' => 'auth'],
            ],
            [
                ['pre_decision_id' => 'd1', 'operator_decision' => 'revert', 'hidden' => true],
                ['pre_decision_id' => 'd2', 'operator_decision' => 'merge', 'visible' => true],
            ],
        );

        $this->assertSame(1, $result['rejected_hidden_count']);
        $this->assertSame(1, $result['accepted_override_count']);
        // The hidden override is rejected, so it never contributes a divergence cluster.
        $this->assertSame(0, $result['visible_divergence_count']);
        $this->assertSame([], $result['divergence_clusters']);
        // Hidden override is unsafe -> demote.
        $this->assertTrue($result['demote_required']);
        // The rejection is surfaced in evidence: disagreement is never hidden.
        $this->assertContains('rejected_hidden_override:d1', $result['evidence_refs']);
        $this->assertCount(2, $result['evidence_refs']);
    }

    public function testMissingVisibilityProofIsTreatedAsHidden(): void
    {
        // No `visible` flag and no receipt/evidence ref => hidden => rejected.
        $result = $this->loop->learn(
            [
                ['id' => 'd1', 'decision' => 'merge', 'cluster' => 'auth'],
            ],
            [
                ['pre_decision_id' => 'd1', 'operator_decision' => 'revert'],
            ],
        );

        $this->assertSame(1, $result['rejected_hidden_count']);
        $this->assertSame(0, $result['accepted_override_count']);
        $this->assertTrue($result['demote_required']);
        $this->assertContains('rejected_hidden_override:d1', $result['evidence_refs']);
    }

    public function testReceiptRefAloneCountsAsVisibilityProof(): void
    {
        // A receipt ref is visibility proof even without an explicit `visible` flag.
        $result = $this->loop->learn(
            [
                ['id' => 'd1', 'decision' => 'merge', 'cluster' => 'auth'],
            ],
            [
                ['pre_decision_id' => 'd1', 'operator_decision' => 'revert', 'receipt_ref' => 'rcpt-77'],
            ],
        );

        $this->assertSame(0, $result['rejected_hidden_count']);
        $this->assertSame(1, $result['accepted_override_count']);
        $this->assertSame(1, $result['visible_divergence_count']);
        $this->assertTrue($result['relearn_required']);
        // Evidence ref prefers the override's own receipt ref.
        $this->assertContains('visible_divergence:rcpt-77', $result['evidence_refs']);
    }

    public function testNoMutationOccurs(): void
    {
        $preDecisions = [
            ['id' => 'd1', 'decision' => 'merge', 'cluster' => 'auth'],
            ['id' => 'd2', 'decision' => 'merge', 'cluster' => 'auth'],
        ];
        $overrides = [
            ['pre_decision_id' => 'd1', 'operator_decision' => 'revert', 'visible' => true],
            ['pre_decision_id' => 'd2', 'operator_decision' => 'revert', 'hidden' => true],
        ];

        $preDecisionsSnapshot = $preDecisions;
        $overridesSnapshot = $overrides;

        $result = $this->loop->learn($preDecisions, $overrides);

        // Inputs are unchanged: the loop measures, it never mutates.
        $this->assertSame($preDecisionsSnapshot, $preDecisions);
        $this->assertSame($overridesSnapshot, $overrides);
        $this->assertFalse($result['mutated']);
    }

    public function testOverrideRateNeverExceedsOneEvenWhenOverridesOutnumberDecisions(): void
    {
        // More divergent overrides than pre-decisions (duplicate ids + an unmatched
        // override). The naive count/decisions ratio would exceed 1.0; the bound
        // must hold the rate at exactly 1.0.
        $result = $this->loop->learn(
            [
                ['id' => 'd1', 'decision' => 'merge', 'cluster' => 'auth'],
            ],
            [
                ['pre_decision_id' => 'd1', 'operator_decision' => 'revert', 'visible' => true],
                ['pre_decision_id' => 'd1', 'operator_decision' => 'hold', 'visible' => true],
                ['pre_decision_id' => 'unknown', 'operator_decision' => 'revert', 'visible' => true],
            ],
        );

        $this->assertSame(3, $result['visible_divergence_count']);
        $this->assertSame(1, $result['pre_decision_count']);
        $this->assertSame(1.0, $result['override_rate']);
        $this->assertLessThanOrEqual(1.0, $result['override_rate']);
    }

    public function testEmptyInputsYieldZeroRateNoDemoteNoRelearn(): void
    {
        $result = $this->loop->learn([], []);

        $this->assertSame(0, $result['pre_decision_count']);
        $this->assertSame(0, $result['override_count']);
        $this->assertSame(0.0, $result['override_rate']);
        $this->assertSame([], $result['divergence_clusters']);
        $this->assertSame([], $result['evidence_refs']);
        $this->assertFalse($result['demote_required']);
        $this->assertFalse($result['relearn_required']);
    }

    public function testClustersOrderedByDivergenceCountDescThenClusterAsc(): void
    {
        // Generalisation across multiple clusters: ordering must be deterministic
        // (count desc, then cluster name asc), not keyed to input order.
        $result = $this->loop->learn(
            [
                ['id' => 'd1', 'decision' => 'merge', 'cluster' => 'zeta'],
                ['id' => 'd2', 'decision' => 'merge', 'cluster' => 'alpha'],
                ['id' => 'd3', 'decision' => 'merge', 'cluster' => 'alpha'],
                ['id' => 'd4', 'decision' => 'merge', 'cluster' => 'beta'],
                ['id' => 'd5', 'decision' => 'merge', 'cluster' => 'beta'],
                ['id' => 'd6', 'decision' => 'merge', 'cluster' => 'beta'],
            ],
            [
                ['pre_decision_id' => 'd1', 'operator_decision' => 'revert', 'visible' => true],
                ['pre_decision_id' => 'd2', 'operator_decision' => 'revert', 'visible' => true],
                ['pre_decision_id' => 'd3', 'operator_decision' => 'revert', 'visible' => true],
                ['pre_decision_id' => 'd4', 'operator_decision' => 'revert', 'visible' => true],
                ['pre_decision_id' => 'd5', 'operator_decision' => 'revert', 'visible' => true],
                ['pre_decision_id' => 'd6', 'operator_decision' => 'revert', 'visible' => true],
            ],
        );

        $this->assertSame(
            [
                ['cluster' => 'beta', 'divergence_count' => 3, 'demote' => true],
                ['cluster' => 'alpha', 'divergence_count' => 2, 'demote' => true],
                ['cluster' => 'zeta', 'divergence_count' => 1, 'demote' => false],
            ],
            $result['divergence_clusters'],
        );
        // 6 divergences / 6 pre-decisions = 1.0, still bounded.
        $this->assertSame(1.0, $result['override_rate']);
        $this->assertTrue($result['demote_required']);
    }

    public function testEvidenceRefsListIsStringListAndDeterministic(): void
    {
        // list<string> contract: integer-like ids must not coerce the keys; the
        // evidence_refs array must stay a 0-indexed list of strings, and the same
        // input must always produce the same refs in the same order.
        $preDecisions = [
            ['id' => 'd1', 'decision' => 'merge', 'cluster' => 'auth'],
            ['id' => 'd2', 'decision' => 'merge', 'cluster' => 'auth'],
            ['id' => 'd3', 'decision' => 'merge', 'cluster' => 'auth'],
        ];
        $overrides = [
            ['pre_decision_id' => 'd1', 'operator_decision' => 'revert', 'visible' => true],
            ['pre_decision_id' => 'd2', 'operator_decision' => 'merge', 'visible' => true],
            ['pre_decision_id' => 'd3', 'operator_decision' => 'revert', 'hidden' => true],
        ];

        $first = $this->loop->learn($preDecisions, $overrides);
        $second = $this->loop->learn($preDecisions, $overrides);

        $this->assertSame($first['evidence_refs'], $second['evidence_refs']);
        $this->assertSame(array_values($first['evidence_refs']), $first['evidence_refs']);

        foreach ($first['evidence_refs'] as $key => $ref) {
            $this->assertIsInt($key);
            $this->assertIsString($ref);
        }

        $this->assertSame(
            [
                'visible_divergence:d1',
                'visible_agreement:d2',
                'rejected_hidden_override:d3',
            ],
            $first['evidence_refs'],
        );
    }
}
