<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8SystemEvolutionTwinInputBuilder;
use PHPUnit\Framework\TestCase;

final class L8SystemEvolutionTwinInputBuilderTest extends TestCase
{
    private L8SystemEvolutionTwinInputBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new L8SystemEvolutionTwinInputBuilder();
    }

    public function testBuildReturnsCanonicalSchemaVersion(): void
    {
        $result = $this->builder->build([
            'evolution_history' => [
                $this->groundedEvent('frame-001'),
            ],
        ]);

        $this->assertSame(
            'atlas.aaeos.l8.system_evolution_twin_input.v1',
            $result['schema_version']
        );
        $this->assertFalse($result['insufficient_evidence']);
        $this->assertTrue($result['no_provider_call']);
    }

    public function testEachCandidateHasBaselineMetricsPredictedSlotAndOutcomeRefs(): void
    {
        // Two distinct grounded events with DIFFERENT metric names/values so the
        // assertions read computed values, not canned constants.
        $result = $this->builder->build([
            'evolution_history' => [
                [
                    'evolution_id' => 'frame-alpha',
                    'frame_proposal_ref' => 'proposal:alpha',
                    'baseline_metrics' => [
                        'merge_truth' => 0.91,
                        'dm_dt' => 0.04,
                    ],
                    'dm_dt_series' => [0.01, 0.02, 0.03],
                    'outcome_refs' => ['evidence:obra/1', 'evidence:obra/2'],
                ],
                [
                    'evolution_id' => 'frame-beta',
                    'frame_proposal_ref' => 'proposal:beta',
                    'baseline_metrics' => [
                        'observability' => 0.7,
                    ],
                    'dm_dt_series' => [0.5],
                    'outcome_refs' => ['evidence:obra/9'],
                ],
            ],
        ]);

        $this->assertSame(2, $result['candidate_count']);
        $this->assertCount(2, $result['candidates']);

        // Sorted deterministically by evolution_id: alpha before beta.
        [$alpha, $beta] = $result['candidates'];

        // Every candidate carries the three required slots.
        foreach ($result['candidates'] as $candidate) {
            $this->assertArrayHasKey('baseline_metrics', $candidate);
            $this->assertArrayHasKey('predicted_metrics_slot', $candidate);
            $this->assertArrayHasKey('outcome_refs', $candidate);
            $this->assertTrue($candidate['grounded_in_history']);
        }

        // baseline_metrics carry the measured values verbatim (ksorted keys).
        $this->assertSame('frame_alpha', $alpha['evolution_id']);
        $this->assertSame(['dm_dt' => 0.04, 'merge_truth' => 0.91], $alpha['baseline_metrics']);
        $this->assertSame('proposal:alpha', $alpha['frame_proposal_ref']);
        $this->assertSame([0.01, 0.02, 0.03], $alpha['dm_dt_series']);
        $this->assertSame(['evidence:obra/1', 'evidence:obra/2'], $alpha['outcome_refs']);

        // predicted_metrics_slot mirrors EXACTLY the baseline keys, every value
        // null and the slot unfilled — the builder predicts nothing.
        $this->assertSame(
            [
                'status' => 'unfilled',
                'filled' => false,
                'metrics' => ['dm_dt' => null, 'merge_truth' => null],
            ],
            $alpha['predicted_metrics_slot']
        );

        // Second candidate generalises: different name, value, single outcome.
        $this->assertSame('frame_beta', $beta['evolution_id']);
        $this->assertSame(['observability' => 0.7], $beta['baseline_metrics']);
        $this->assertSame(['observability' => null], $beta['predicted_metrics_slot']['metrics']);
        $this->assertFalse($beta['predicted_metrics_slot']['filled']);
        $this->assertSame(['evidence:obra/9'], $beta['outcome_refs']);
    }

    public function testMissingHistoryReturnsInsufficientEvidence(): void
    {
        $result = $this->builder->build([]);

        $this->assertTrue($result['insufficient_evidence']);
        $this->assertSame([], $result['candidates']);
        $this->assertSame(0, $result['candidate_count']);
        $this->assertSame(0, $result['event_count']);
    }

    public function testEmptyEvolutionHistoryReturnsInsufficientEvidence(): void
    {
        $result = $this->builder->build(['evolution_history' => []]);

        $this->assertTrue($result['insufficient_evidence']);
        $this->assertSame([], $result['candidates']);
    }

    public function testNoProviderCallFlagIsAlwaysTrue(): void
    {
        $grounded = $this->builder->build([
            'evolution_history' => [$this->groundedEvent('frame-x')],
        ]);
        $empty = $this->builder->build([]);

        // The acceptance "no provider call" is observable as a computed field
        // on both the populated and the empty path.
        $this->assertTrue($grounded['no_provider_call']);
        $this->assertTrue($empty['no_provider_call']);
    }

    public function testEventWithoutBaselineIsNotGroundedAndIsRejected(): void
    {
        $result = $this->builder->build([
            'evolution_history' => [
                [
                    'evolution_id' => 'no-baseline',
                    'outcome_refs' => ['evidence:obra/1'],
                ],
                $this->groundedEvent('frame-ok'),
            ],
        ]);

        $this->assertSame(1, $result['candidate_count']);
        $this->assertSame('frame_ok', $result['candidates'][0]['evolution_id']);
        $this->assertContains('no_baseline', $result['rejected_event_ids']);
    }

    public function testEventWithoutOutcomeRefsIsNotGroundedAndIsRejected(): void
    {
        // A frame proposal with a measured baseline but NO measured outcome is
        // not grounded in real evolution history -> dropped, not modelled.
        $result = $this->builder->build([
            'evolution_history' => [
                [
                    'evolution_id' => 'no-outcome',
                    'baseline_metrics' => ['merge_truth' => 0.8],
                    'dm_dt_series' => [0.1, 0.2],
                ],
            ],
        ]);

        $this->assertTrue($result['insufficient_evidence']);
        $this->assertSame([], $result['candidates']);
        $this->assertSame(['no_outcome'], $result['rejected_event_ids']);
    }

    public function testUnmeasuredOutcomeRecordsAreNotCountedAsGrounding(): void
    {
        // The only "outcome" is a record explicitly flagged not measured. That
        // ref must be discarded, leaving the event ungrounded -> rejected. This
        // proves grounding is in REAL measured outcomes, not any array shape.
        $result = $this->builder->build([
            'evolution_history' => [
                [
                    'evolution_id' => 'unmeasured-only',
                    'baseline_metrics' => ['merge_truth' => 0.8],
                    'outcome_refs' => [
                        ['ref' => 'evidence:pending', 'measured' => false],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['insufficient_evidence']);
        $this->assertContains('unmeasured_only', $result['rejected_event_ids']);
    }

    public function testOutcomeRecordsResolveToRefsAndMeasuredOnesGround(): void
    {
        // Mixed outcomes: one bare string ref, one measured record, one
        // unmeasured record (dropped). Refs de-duplicate and preserve order.
        $result = $this->builder->build([
            'evolution_history' => [
                [
                    'evolution_id' => 'mixed-outcomes',
                    'baseline_metrics' => ['speed' => 1.5],
                    'outcome_refs' => [
                        'evidence:a',
                        ['ref' => 'evidence:b', 'measured' => true],
                        ['ref' => 'evidence:c', 'measured' => false],
                        'evidence:a',
                    ],
                ],
            ],
        ]);

        $this->assertFalse($result['insufficient_evidence']);
        $this->assertSame(
            ['evidence:a', 'evidence:b'],
            $result['candidates'][0]['outcome_refs']
        );
    }

    public function testNonNumericBaselineEntriesAreDroppedFromMetrics(): void
    {
        // Generalisation: arbitrary metric names flow through; non-numeric
        // values are dropped so a fabricated string metric cannot pollute the
        // baseline or the prediction slot.
        $result = $this->builder->build([
            'evolution_history' => [
                [
                    'evolution_id' => 'noisy-baseline',
                    'baseline_metrics' => [
                        'throughput' => 12,
                        'label' => 'not-a-number',
                        'ratio' => '0.33',
                        'broken' => [1, 2, 3],
                    ],
                    'outcome_refs' => ['evidence:o1'],
                ],
            ],
        ]);

        $candidate = $result['candidates'][0];

        $this->assertSame(
            ['ratio' => 0.33, 'throughput' => 12.0],
            $candidate['baseline_metrics']
        );
        $this->assertSame(
            ['ratio' => null, 'throughput' => null],
            $candidate['predicted_metrics_slot']['metrics']
        );
    }

    public function testPredictionSlotKeysTrackArbitraryBaselineKeys(): void
    {
        // Reviewer-style input not in any other test: the slot must mirror
        // whatever measured baseline keys arrive, never a hard-coded set.
        $result = $this->builder->build([
            'evolution_history' => [
                [
                    'evolution_id' => 'zzz',
                    'baseline_metrics' => [
                        'alpha_score' => 0.2,
                        'omega_score' => 0.9,
                        'mid_score' => 0.5,
                    ],
                    'outcome_refs' => ['evidence:z'],
                ],
            ],
        ]);

        $slot = $result['candidates'][0]['predicted_metrics_slot'];

        // Keys present, ksorted, all null, slot unfilled.
        $this->assertSame(
            ['alpha_score', 'mid_score', 'omega_score'],
            array_keys($slot['metrics'])
        );
        foreach ($slot['metrics'] as $value) {
            $this->assertNull($value);
        }
        $this->assertSame('unfilled', $slot['status']);
        $this->assertFalse($slot['filled']);
    }

    public function testDuplicateEvolutionIdsKeepFirstAndCountOnce(): void
    {
        $result = $this->builder->build([
            'evolution_history' => [
                [
                    'evolution_id' => 'dup',
                    'baseline_metrics' => ['m' => 1.0],
                    'outcome_refs' => ['evidence:first'],
                ],
                [
                    'evolution_id' => 'dup',
                    'baseline_metrics' => ['m' => 9.0],
                    'outcome_refs' => ['evidence:second'],
                ],
            ],
        ]);

        $this->assertSame(1, $result['candidate_count']);
        $this->assertSame(['m' => 1.0], $result['candidates'][0]['baseline_metrics']);
        $this->assertSame(['evidence:first'], $result['candidates'][0]['outcome_refs']);
    }

    public function testDuplicateRejectedEventIdAppearsOnceInRejectedList(): void
    {
        // Two ungrounded events sharing one id must not double the rejection:
        // rejected_event_ids is a clean list, not a bag with duplicates.
        $result = $this->builder->build([
            'evolution_history' => [
                ['evolution_id' => 'dup', 'outcome_refs' => ['evidence:a']],
                ['evolution_id' => 'dup', 'outcome_refs' => ['evidence:b']],
            ],
        ]);

        $this->assertSame(['dup'], $result['rejected_event_ids']);
        $this->assertSame([], $result['candidates']);
    }

    public function testEvolutionIdNeverAppearsInBothCandidatesAndRejected(): void
    {
        // First occurrence of an id is ungrounded (rejected); a later duplicate is
        // grounded. Keep-first doctrine means the id stays rejected and is NOT also
        // emitted as a candidate — the two partitions are mutually exclusive.
        $result = $this->builder->build([
            'evolution_history' => [
                ['evolution_id' => 'dup', 'outcome_refs' => ['evidence:a']],
                [
                    'evolution_id' => 'dup',
                    'baseline_metrics' => ['m' => 5.0],
                    'outcome_refs' => ['evidence:b'],
                ],
            ],
        ]);

        $candidateIds = array_column($result['candidates'], 'evolution_id');
        $this->assertSame(
            [],
            array_intersect($candidateIds, $result['rejected_event_ids']),
            'an evolution_id must not be both a candidate and rejected',
        );
        $this->assertContains('dup', $result['rejected_event_ids']);
        $this->assertNotContains('dup', $candidateIds);
    }

    public function testOutcomeRefsListIsZeroIndexedStringList(): void
    {
        $result = $this->builder->build([
            'evolution_history' => [
                [
                    'evolution_id' => 'list-shape',
                    'baseline_metrics' => ['m' => 1.0],
                    'outcome_refs' => ['evidence:b', 'evidence:a', 'evidence:b', 'evidence:c'],
                ],
            ],
        ]);

        $refs = $result['candidates'][0]['outcome_refs'];

        // De-duplicated, order-preserving, zero-indexed list<string>.
        $this->assertSame(['evidence:b', 'evidence:a', 'evidence:c'], $refs);
        $this->assertSame(range(0, count($refs) - 1), array_keys($refs));
        foreach ($refs as $ref) {
            $this->assertIsString($ref);
        }
    }

    public function testFrameProposalRefFallsBackToEvolutionWhenAbsent(): void
    {
        $result = $this->builder->build([
            'evolution_history' => [
                [
                    'evolution_id' => 'frame-007',
                    'baseline_metrics' => ['m' => 1.0],
                    'outcome_refs' => ['evidence:o'],
                ],
            ],
        ]);

        $this->assertSame(
            'evolution:frame_007',
            $result['candidates'][0]['frame_proposal_ref']
        );
    }

    public function testCandidatesAreSortedDeterministicallyAndBuildIsPure(): void
    {
        $history = [
            'evolution_history' => [
                [
                    'evolution_id' => 'zeta',
                    'baseline_metrics' => ['m' => 2.0],
                    'outcome_refs' => ['evidence:z'],
                ],
                [
                    'evolution_id' => 'alpha',
                    'baseline_metrics' => ['m' => 1.0],
                    'outcome_refs' => ['evidence:a'],
                ],
                [
                    'evolution_id' => 'mike',
                    'baseline_metrics' => ['m' => 3.0],
                    'outcome_refs' => ['evidence:m'],
                ],
            ],
        ];

        $first = $this->builder->build($history);
        $second = $this->builder->build($history);

        $this->assertSame($first, $second);
        $this->assertSame(
            ['alpha', 'mike', 'zeta'],
            array_column($first['candidates'], 'evolution_id')
        );
    }

    /**
     * A minimal, fully-grounded evolution event (measured baseline + measured
     * outcome ref). Pure helper, stable values.
     *
     * @return array<string, mixed>
     */
    private function groundedEvent(string $id): array
    {
        return [
            'evolution_id' => $id,
            'frame_proposal_ref' => 'proposal:' . $id,
            'baseline_metrics' => ['merge_truth' => 0.9, 'observability' => 0.8],
            'dm_dt_series' => [0.01, 0.02],
            'outcome_refs' => ['evidence:obra/' . $id],
        ];
    }
}
