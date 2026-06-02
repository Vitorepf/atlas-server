<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9EngineeringMethodCandidateSpecBuilder;
use PHPUnit\Framework\TestCase;

final class L9EngineeringMethodCandidateSpecBuilderTest extends TestCase
{
    private L9EngineeringMethodCandidateSpecBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new L9EngineeringMethodCandidateSpecBuilder();
    }

    public function testBuildReturnsRequiredCandidateFieldsFromMeasuredOutcome(): void
    {
        $result = $this->builder->build([
            'outcomes' => [
                [
                    'method' => 'Property-Based Review Pass',
                    'scope' => 'engineering',
                    'engineering_stage' => 'review',
                    'metric_before' => 12.0,
                    'metric_after' => 30.0,
                    'source_refs' => ['cycle:914', 'cycle:914'],
                ],
            ],
        ]);

        $this->assertSame('atlas.aaeos.l9.engineering_method_candidate_spec.v1', $result['schema_version']);
        $this->assertSame('engineering_only', $result['scope']);
        $this->assertFalse($result['insufficient_evidence']);
        $this->assertSame(1, $result['candidate_count']);
        $this->assertCount(1, $result['candidates']);

        $candidate = $result['candidates'][0];

        // method_candidate_id is the deterministic slug of the named method.
        $this->assertSame('property_based_review_pass', $candidate['method_candidate_id']);
        // affected_engineering_stage is normalised against the engineering lexicon.
        $this->assertSame('review', $candidate['affected_engineering_stage']);
        // expected_metric_delta = after - before = 30 - 12 = 18.0 (computed, not canned).
        $this->assertEqualsWithDelta(18.0, $candidate['expected_metric_delta'], 0.0001);
        // scope is pinned to engineering_only per candidate.
        $this->assertSame('engineering_only', $candidate['scope']);
        // hypothesis is a non-empty computed string referencing method, direction and stage.
        $this->assertIsString($candidate['hypothesis']);
        $this->assertStringContainsString('property_based_review_pass', $candidate['hypothesis']);
        $this->assertStringContainsString('improves', $candidate['hypothesis']);
        $this->assertStringContainsString('review', $candidate['hypothesis']);
        // duplicate source_refs collapse to a unique list<string>.
        $this->assertSame(['cycle:914'], $candidate['source_refs']);
    }

    public function testExpectedMetricDeltaUsesExplicitDeltaAndComputesDirectionForRegression(): void
    {
        $result = $this->builder->build([
            [
                'method' => 'aggressive_inlining',
                'scope' => 'engineering',
                'stage' => 'implementation',
                'metric_delta' => -4.5,
            ],
        ]);

        $candidate = $result['candidates'][0];

        $this->assertSame('aggressive_inlining', $candidate['method_candidate_id']);
        $this->assertSame('implementation', $candidate['affected_engineering_stage']);
        // Explicit metric_delta wins over before/after derivation; sign is preserved.
        $this->assertEqualsWithDelta(-4.5, $candidate['expected_metric_delta'], 0.0001);
        // Negative delta yields a "regresses" hypothesis direction.
        $this->assertStringContainsString('regresses', $candidate['hypothesis']);
    }

    public function testNoMeasuredOutcomeBlocks(): void
    {
        $result = $this->builder->build([
            'outcomes' => [
                [
                    'method' => 'speculative_refactor',
                    'scope' => 'engineering',
                    'stage' => 'design',
                    // No metric_before/after, no metric_delta, no measured_value.
                ],
            ],
        ]);

        $this->assertTrue($result['insufficient_evidence']);
        $this->assertSame(0, $result['candidate_count']);
        $this->assertSame([], $result['candidates']);
        $this->assertSame(0, $result['measured_outcome_count']);
    }

    public function testEmptyOutcomesBlocks(): void
    {
        $result = $this->builder->build([]);

        $this->assertTrue($result['insufficient_evidence']);
        $this->assertSame(0, $result['candidate_count']);
        $this->assertSame([], $result['candidates']);
        $this->assertSame('engineering_only', $result['scope']);
    }

    public function testNonEngineeringScopeRejects(): void
    {
        $result = $this->builder->build([
            'outcomes' => [
                [
                    'method' => 'campaign_split_test',
                    'scope' => 'marketing',
                    'metric_before' => 1.0,
                    'metric_after' => 9.0,
                ],
                [
                    'method' => 'portfolio_rebalance',
                    'domain' => 'trading',
                    'metric_delta' => 7.0,
                ],
            ],
        ]);

        // Both outcomes leave engineering scope: zero candidates, both rejected.
        $this->assertSame(0, $result['candidate_count']);
        $this->assertSame([], $result['candidates']);
        $this->assertTrue($result['insufficient_evidence']);
        $this->assertSame(
            ['campaign_split_test:marketing', 'portfolio_rebalance:trading'],
            $result['rejected_non_engineering']
        );
        $this->assertSame([], $result['rejected_duplicates']);
    }

    public function testNonEngineeringOutcomeIsRejectedEvenWhenMeasured(): void
    {
        $result = $this->builder->build([
            [
                'method' => 'fraud_scoring_tune',
                'scope' => 'finance',
                'metric_before' => 2.0,
                'metric_after' => 40.0,
            ],
        ]);

        // A measured non-engineering outcome must not count as a measured outcome
        // nor become a candidate: scope guard runs first.
        $this->assertSame(0, $result['candidate_count']);
        $this->assertSame(0, $result['measured_outcome_count']);
        $this->assertSame(['fraud_scoring_tune:finance'], $result['rejected_non_engineering']);
    }

    public function testDuplicateMethodRejects(): void
    {
        $result = $this->builder->build([
            'outcomes' => [
                [
                    'method' => 'Differential Testing Gate',
                    'scope' => 'engineering',
                    'stage' => 'testing',
                    'metric_before' => 10.0,
                    'metric_after' => 25.0,
                ],
                [
                    // Same method after slugging -> duplicate, must not re-emit.
                    'method' => 'differential-testing-gate',
                    'scope' => 'engineering',
                    'stage' => 'testing',
                    'metric_delta' => 99.0,
                ],
            ],
        ]);

        $this->assertSame(1, $result['candidate_count']);
        $this->assertCount(1, $result['candidates']);
        $this->assertSame('differential_testing_gate', $result['candidates'][0]['method_candidate_id']);
        // The first (earlier) measurement seeds the candidate; the duplicate is discarded.
        $this->assertEqualsWithDelta(15.0, $result['candidates'][0]['expected_metric_delta'], 0.0001);
        $this->assertSame(['differential_testing_gate'], $result['rejected_duplicates']);
        // Both occurrences are measured outcomes, even though only one is emitted.
        $this->assertSame(2, $result['measured_outcome_count']);
    }

    public function testUnknownEngineeringStageFallsBackToUnspecifiedButStaysInScope(): void
    {
        $result = $this->builder->build([
            [
                'method' => 'context_pack_prewarm',
                'scope' => 'engineering',
                'stage' => 'galactic_navigation',
                'measured_value' => 6.0,
            ],
        ]);

        $candidate = $result['candidates'][0];

        // Unknown engineering stage token is not in the lexicon -> default stage.
        $this->assertSame('unspecified', $candidate['affected_engineering_stage']);
        $this->assertSame('engineering_only', $candidate['scope']);
        // Standalone measured_value is used as the delta.
        $this->assertEqualsWithDelta(6.0, $candidate['expected_metric_delta'], 0.0001);
    }

    public function testNonFiniteMetricIsNotARealMeasurementAndBlocks(): void
    {
        // A non-finite value (NAN, +/-INF) is not a real measurement: NAN would
        // poison the >= 0.0 direction test and make expected_metric_delta
        // un-encodable in the evidence envelope. It must be treated as absent so
        // the no-measurement rule fails closed (no candidate emitted).
        foreach ([NAN, INF, -INF] as $nonFinite) {
            $result = $this->builder->build([
                'outcomes' => [
                    [
                        'method' => 'non_finite_metric',
                        'scope' => 'engineering',
                        'stage' => 'review',
                        'metric_delta' => $nonFinite,
                    ],
                ],
            ]);

            $this->assertTrue($result['insufficient_evidence']);
            $this->assertSame(0, $result['candidate_count']);
            $this->assertSame([], $result['candidates']);
            $this->assertSame(0, $result['measured_outcome_count']);
            // The whole envelope stays JSON-encodable (no NAN/INF leak).
            $this->assertIsString(json_encode($result));
            $this->assertSame(JSON_ERROR_NONE, json_last_error());
        }
    }

    public function testNonFiniteBeforeAfterPairIsNotMeasured(): void
    {
        // after - before with a non-finite operand must not seed a candidate:
        // the pair is not a real measurement.
        $result = $this->builder->build([
            [
                'method' => 'non_finite_pair',
                'scope' => 'engineering',
                'metric_before' => 1.0,
                'metric_after' => INF,
            ],
        ]);

        $this->assertTrue($result['insufficient_evidence']);
        $this->assertSame(0, $result['candidate_count']);
        $this->assertSame(0, $result['measured_outcome_count']);
    }

    public function testOverflowingBeforeAfterDifferenceIsNotMeasuredAndStaysEncodable(): void
    {
        // Both operands are individually finite (±1e308), but their difference
        // overflows to +INF. An overflowing difference is not a real
        // measurement: it would poison the >= 0.0 direction test and make
        // expected_metric_delta un-encodable in the evidence envelope. It must
        // fail closed exactly like a single non-finite operand -> no candidate.
        // +overflow: 1e308 - (-1e308) = +INF ; -overflow: -1e308 - 1e308 = -INF.
        foreach ([[-1.0e308, 1.0e308], [1.0e308, -1.0e308]] as $pair) {
            [$before, $after] = $pair;

            $result = $this->builder->build([
                'outcomes' => [
                    [
                        'method' => 'overflow_pair',
                        'scope' => 'engineering',
                        'stage' => 'review',
                        'metric_before' => $before,
                        'metric_after' => $after,
                    ],
                ],
            ]);

            $this->assertTrue($result['insufficient_evidence']);
            $this->assertSame(0, $result['candidate_count']);
            $this->assertSame([], $result['candidates']);
            $this->assertSame(0, $result['measured_outcome_count']);
            // The whole envelope stays JSON-encodable (no INF leak).
            $this->assertIsString(json_encode($result));
            $this->assertSame(JSON_ERROR_NONE, json_last_error());
        }
    }

    public function testNumericStringMethodIdsSortAsStringsNotNumbers(): void
    {
        // method_candidate_id is a slug (list<string> identity). When ids are
        // pure numeric strings, the rejected_duplicates / rejected_non_engineering
        // lists must use the SAME string ordering as the candidate list — never
        // a numeric coercion. Slug-equal duplicates ("10","2","100") each appear
        // once as a candidate and once as a rejected duplicate.
        $result = $this->builder->build([
            'outcomes' => [
                ['method' => '10', 'scope' => 'engineering', 'metric_delta' => 1.0],
                ['method' => '10', 'scope' => 'engineering', 'metric_delta' => 1.0],
                ['method' => '2', 'scope' => 'engineering', 'metric_delta' => 1.0],
                ['method' => '2', 'scope' => 'engineering', 'metric_delta' => 1.0],
                ['method' => '100', 'scope' => 'engineering', 'metric_delta' => 1.0],
                ['method' => '100', 'scope' => 'engineering', 'metric_delta' => 1.0],
            ],
        ]);

        $candidateIds = array_map(
            static fn (array $candidate): string => $candidate['method_candidate_id'],
            $result['candidates']
        );

        // String order ("10","100","2"), NOT numeric order ("2","10","100").
        $this->assertSame(['10', '100', '2'], $candidateIds);
        $this->assertSame(['10', '100', '2'], $result['rejected_duplicates']);
        // The two ordered lists agree (both string-sorted over the same ids).
        $this->assertSame($candidateIds, $result['rejected_duplicates']);
    }

    public function testCandidatesAreSortedDeterministicallyAndIdempotent(): void
    {
        $outcomes = [
            'outcomes' => [
                [
                    'method' => 'zeta_method',
                    'scope' => 'engineering',
                    'stage' => 'merge',
                    'metric_delta' => 3.0,
                ],
                [
                    'method' => 'alpha_method',
                    'scope' => 'engineering',
                    'stage' => 'spec',
                    'metric_before' => 5.0,
                    'metric_after' => 8.0,
                ],
            ],
        ];

        $first = $this->builder->build($outcomes);
        $second = $this->builder->build($outcomes);

        $ids = array_map(
            static fn (array $candidate): string => $candidate['method_candidate_id'],
            $first['candidates']
        );

        // Deterministic ascending order by method_candidate_id.
        $this->assertSame(['alpha_method', 'zeta_method'], $ids);
        // Same input -> identical output (pure, no clock, no randomness).
        $this->assertSame($first, $second);
    }
}
