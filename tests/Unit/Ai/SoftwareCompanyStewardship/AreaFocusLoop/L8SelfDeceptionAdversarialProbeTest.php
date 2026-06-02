<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8MetricRealityDivergenceDetector;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8SelfDeceptionAdversarialProbe;
use PHPUnit\Framework\TestCase;

final class L8SelfDeceptionAdversarialProbeTest extends TestCase
{
    private L8SelfDeceptionAdversarialProbe $probe;

    protected function setUp(): void
    {
        $this->probe = new L8SelfDeceptionAdversarialProbe();
    }

    public function testReturnsCanonicalSchemaWithAdversarialCasesAndExpectedBlockers(): void
    {
        $result = $this->probe->probe(
            ['useful_cycle_rate' => 0.40],
            ['useful_cycle_rate' => 0.40],
        );

        $this->assertSame('atlas.aaeos.l8.self_deception_probe.v1', $result['schema_version']);
        $this->assertArrayHasKey('adversarial_cases', $result);
        $this->assertArrayHasKey('expected_blockers', $result);
        $this->assertFalse($result['fail_closed']);
        $this->assertSame(1, $result['adversarial_case_count']);
        $this->assertSame(1, $result['case_count']);
        $this->assertCount(1, $result['adversarial_cases']);
        $this->assertSame(['metric_reality_divergence'], $result['expected_blockers']);
    }

    public function testGamingCasePushesReportedMetricUpWhileAnchorStaysFlat(): void
    {
        $result = $this->probe->probe(
            ['trust_ledger_score' => 0.50],
            ['trust_ledger_score' => 0.50],
        );

        $case = $result['adversarial_cases'][0];

        $this->assertSame('trust_ledger_score', $case['metric']);
        $this->assertSame('metric_up_anchor_flat', $case['pattern']);
        $this->assertSame(0.50, $case['reported_value']);
        // +0.10 absolute step (>= 10% relative) applied deterministically.
        $this->assertEqualsWithDelta(0.60, $case['gamed_value'], 1e-9);
        $this->assertSame(0.50, $case['anchor_value']);
        $this->assertEqualsWithDelta(0.10, $case['metric_movement'], 1e-9);
        $this->assertSame(0.0, $case['anchor_movement']);
        $this->assertTrue($case['should_block']);
        $this->assertFalse($case['fail_closed']);
        $this->assertTrue($result['gaming_detected']);
    }

    public function testGamingCaseCarriesS103CompatibleDivergencePayloadThatYieldsDivergenceDetected(): void
    {
        $result = $this->probe->probe(
            ['dm_dt' => 0.20],
            ['dm_dt' => 0.20],
        );

        $case = $result['adversarial_cases'][0];
        $payload = $case['divergence_payload'];

        // S103-compatible reading shape: detect(metrics, anchors) where each metric
        // is a {before, after} reading and each anchor is a
        // {before, after, independent_source} reading. The metric reading climbs
        // (before -> after); the anchor reading holds flat (before == after).
        $this->assertArrayHasKey('metrics', $payload);
        $this->assertArrayHasKey('anchors', $payload);
        $this->assertSame(['dm_dt'], array_keys($payload['metrics']));
        $this->assertSame(['dm_dt'], array_keys($payload['anchors']));
        $this->assertEqualsWithDelta(0.20, $payload['metrics']['dm_dt']['before'], 1e-9);
        $this->assertEqualsWithDelta(0.30, $payload['metrics']['dm_dt']['after'], 1e-9);
        $this->assertSame(0.20, $payload['anchors']['dm_dt']['before']);
        $this->assertSame(0.20, $payload['anchors']['dm_dt']['after']);
        $this->assertIsString($payload['anchors']['dm_dt']['independent_source']);
        $this->assertNotSame('', $payload['anchors']['dm_dt']['independent_source']);

        // Drive the REAL S103 L8MetricRealityDivergenceDetector with the probe
        // payload to PROVE the simulated gaming case is detected downstream, rather
        // than re-implementing the rule inline (which would be tautological).
        $detector = new L8MetricRealityDivergenceDetector();
        $detected = $detector->detect($payload['metrics'], $payload['anchors']);

        $this->assertTrue($detected['divergence_detected']);
        $this->assertSame('divergence_blocked', $detected['verdict']);
        $this->assertSame(['dm_dt'], $detected['divergent_metrics']);
        $this->assertSame('metric_reality_divergence', $case['expected_blocker']);
    }

    public function testEveryGamingCasePayloadIsDetectedByRealS103Detector(): void
    {
        // Generalisation guard against fixture-shaped payloads: every fabricated
        // case (including a metric outside the canonical S102 registry) must be
        // flagged as divergence by the real detector, proving the payload shape is
        // genuinely the one S103 consumes for any input.
        $result = $this->probe->probe(
            [
                'useful_cycle_rate' => 0.40,
                'provider_honesty_rate' => 0.70,
                'metric_not_in_any_fixture' => 12.5,
            ],
            [
                'useful_cycle_rate' => 0.40,
                'provider_honesty_rate' => 0.70,
                'metric_not_in_any_fixture' => 12.5,
            ],
        );

        $detector = new L8MetricRealityDivergenceDetector();

        $this->assertSame(3, $result['adversarial_case_count']);

        foreach ($result['adversarial_cases'] as $case) {
            $payload = $case['divergence_payload'];
            $detected = $detector->detect($payload['metrics'], $payload['anchors']);

            $this->assertTrue(
                $detected['divergence_detected'],
                'gaming case for '.$case['metric'].' must be detected by real S103',
            );
            $this->assertSame('divergence_blocked', $detected['verdict']);
            $this->assertSame([$case['metric']], $detected['divergent_metrics']);
        }
    }

    public function testFailClosedPayloadIsNotAFalseDivergenceUnderRealS103Detector(): void
    {
        // The malformed sentinel must NOT masquerade as a detected gaming case:
        // fed to the real detector it stays unknown_blocked, never divergence.
        $result = $this->probe->probe([], []);
        $payload = $result['adversarial_cases'][0]['divergence_payload'];

        $detector = new L8MetricRealityDivergenceDetector();
        $detected = $detector->detect($payload['metrics'], $payload['anchors']);

        $this->assertFalse($detected['divergence_detected']);
        $this->assertSame('unknown_blocked', $detected['verdict']);
    }

    public function testMalformedInputsProduceFailClosedCase(): void
    {
        $result = $this->probe->probe([], []);

        $this->assertTrue($result['fail_closed']);
        $this->assertFalse($result['gaming_detected']);
        $this->assertSame(1, $result['adversarial_case_count']);
        $this->assertCount(1, $result['adversarial_cases']);

        $case = $result['adversarial_cases'][0];
        $this->assertSame('fail_closed', $case['pattern']);
        $this->assertTrue($case['fail_closed']);
        $this->assertTrue($case['should_block']);
        $this->assertSame('malformed_probe_inputs', $case['expected_blocker']);
        $this->assertSame(['malformed_probe_inputs'], $result['expected_blockers']);
    }

    public function testNonNumericAndMismatchedEntriesCollapseToFailClosed(): void
    {
        // Metric value is non-numeric AND the only anchor key does not match any
        // numeric metric -> no shared optimizable metric -> fail closed.
        $result = $this->probe->probe(
            ['useful_cycle_rate' => 'high'],
            ['provider_honesty_rate' => 0.9],
        );

        $this->assertTrue($result['fail_closed']);
        $this->assertSame('fail_closed', $result['adversarial_cases'][0]['pattern']);
    }

    public function testGeneratesOneOrderedCasePerSharedMetric(): void
    {
        $result = $this->probe->probe(
            [
                'retained_evolution_rate' => 0.30,
                'useful_cycle_rate' => 0.40,
                'provider_honesty_rate' => 0.70,
                'only_reported' => 0.99,
            ],
            [
                'useful_cycle_rate' => 0.40,
                'retained_evolution_rate' => 0.30,
                'provider_honesty_rate' => 0.70,
                'only_anchored' => 0.10,
            ],
        );

        $this->assertSame(3, $result['adversarial_case_count']);

        $metrics = array_map(
            static fn (array $case): string => $case['metric'],
            $result['adversarial_cases'],
        );
        // Deterministic ascending order of shared metrics; unmatched keys excluded.
        $this->assertSame(
            ['provider_honesty_rate', 'retained_evolution_rate', 'useful_cycle_rate'],
            $metrics,
        );
        $this->assertSame(['metric_reality_divergence'], $result['expected_blockers']);
    }

    public function testDivergenceScoreNeverExceedsBoundForAnyInput(): void
    {
        $result = $this->probe->probe(
            [
                'useful_cycle_rate' => 1000.0,
                'dm_dt' => -5.0,
                'trust_ledger_score' => 0.0,
                // A finite-but-huge reported value: the upward gaming step
                // overflows gamed_value to INF, so divergence_score must NOT
                // collapse to INF/INF = NAN and breach the declared 0..1 bound.
                'overflow_metric' => PHP_FLOAT_MAX,
            ],
            [
                'useful_cycle_rate' => 1000.0,
                'dm_dt' => -5.0,
                'trust_ledger_score' => 0.0,
                'overflow_metric' => PHP_FLOAT_MAX,
            ],
        );

        foreach ($result['adversarial_cases'] as $case) {
            // NAN would silently pass < and > comparisons; assert it is excluded
            // outright so an overflowed score can never masquerade as in-bound.
            $this->assertFalse(is_nan($case['divergence_score']), 'divergence_score must never be NAN');
            $this->assertGreaterThanOrEqual(0.0, $case['divergence_score']);
            $this->assertLessThanOrEqual(1.0, $case['divergence_score']);
        }

        // Anchor held flat against an upward metric move => maximal divergence,
        // including the overflow case (metric ran away from a flat reality).
        $this->assertEqualsWithDelta(1.0, $result['adversarial_cases'][0]['divergence_score'], 1e-9);

        $overflowCase = null;
        foreach ($result['adversarial_cases'] as $case) {
            if ($case['metric'] === 'overflow_metric') {
                $overflowCase = $case;

                break;
            }
        }
        $this->assertNotNull($overflowCase, 'overflow metric must yield an adversarial case');
        $this->assertSame(1.0, $overflowCase['divergence_score']);
    }

    public function testGamingStepScalesRelativelyForLargeReportedValues(): void
    {
        // Generalisation guard: step is max(0.10, 10% of magnitude); for 1000 it is 100.
        $result = $this->probe->probe(
            ['useful_cycle_rate' => 1000.0],
            ['useful_cycle_rate' => 1000.0],
        );

        $case = $result['adversarial_cases'][0];
        $this->assertEqualsWithDelta(1100.0, $case['gamed_value'], 1e-6);
        $this->assertEqualsWithDelta(100.0, $case['metric_movement'], 1e-6);
        // Payload metric reading climbs from reported to gamed; the anchor reading
        // stays flat at reality on both before and after.
        $this->assertEqualsWithDelta(1000.0, $case['divergence_payload']['metrics']['useful_cycle_rate']['before'], 1e-6);
        $this->assertEqualsWithDelta(1100.0, $case['divergence_payload']['metrics']['useful_cycle_rate']['after'], 1e-6);
        $this->assertSame(1000.0, $case['divergence_payload']['anchors']['useful_cycle_rate']['before']);
        $this->assertSame(1000.0, $case['divergence_payload']['anchors']['useful_cycle_rate']['after']);
    }

    public function testNumericStringMetricKeysYieldRealAdversarialCasesNotFalseFailClosed(): void
    {
        // Numeric-string metric keys ("10", "2", "9") are coerced by PHP to int
        // array keys. They are still real, optimizable metrics and MUST produce
        // adversarial cases — never collapse to a false malformed/fail-closed
        // verdict, which would silently skip refuting the gain on those metrics.
        // Mirrors the S103 detector's list<string>-key contract guarantee.
        $result = $this->probe->probe(
            ['10' => 1.0, '9' => 1.0, '2' => 1.0],
            ['10' => 1.0, '9' => 1.0, '2' => 1.0],
        );

        $this->assertFalse($result['fail_closed']);
        $this->assertSame(3, $result['adversarial_case_count']);

        $metrics = array_map(
            static fn (array $case): string => $case['metric'],
            $result['adversarial_cases'],
        );
        // Deterministic SORT_STRING ordering of the coerced keys, surfaced as a
        // re-indexed list of strings (never int-coerced).
        $this->assertSame(['10', '2', '9'], $metrics);
        foreach ($metrics as $index => $metric) {
            $this->assertIsInt($index);
            $this->assertIsString($metric);
        }

        // The fabricated payload for each numeric-string-keyed metric must still be
        // detected as divergence by the real S103 detector (the S104 DoD).
        $detector = new L8MetricRealityDivergenceDetector();
        foreach ($result['adversarial_cases'] as $case) {
            $payload = $case['divergence_payload'];
            $detected = $detector->detect($payload['metrics'], $payload['anchors']);

            $this->assertTrue(
                $detected['divergence_detected'],
                'numeric-string-keyed gaming case for '.$case['metric'].' must be detected by real S103',
            );
            $this->assertSame([$case['metric']], $detected['divergent_metrics']);
        }
    }

    public function testExpectedBlockersIsListOfStrings(): void
    {
        $result = $this->probe->probe(
            ['useful_cycle_rate' => 0.40, 'dm_dt' => 0.20],
            ['useful_cycle_rate' => 0.40, 'dm_dt' => 0.20],
        );

        $blockers = $result['expected_blockers'];
        $this->assertSame(array_values($blockers), $blockers, 'expected_blockers must be a 0-indexed list');

        foreach ($blockers as $index => $blocker) {
            $this->assertIsInt($index);
            $this->assertIsString($blocker);
        }
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $metrics = ['useful_cycle_rate' => 0.42, 'provider_honesty_rate' => 0.81];
        $anchors = ['useful_cycle_rate' => 0.42, 'provider_honesty_rate' => 0.81];

        $first = $this->probe->probe($metrics, $anchors);
        $second = $this->probe->probe($metrics, $anchors);

        $this->assertSame($first, $second);
    }
}
