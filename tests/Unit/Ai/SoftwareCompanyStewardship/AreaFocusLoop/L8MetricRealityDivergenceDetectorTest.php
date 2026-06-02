<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8MetricRealityDivergenceDetector;
use PHPUnit\Framework\TestCase;

final class L8MetricRealityDivergenceDetectorTest extends TestCase
{
    private L8MetricRealityDivergenceDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new L8MetricRealityDivergenceDetector();
    }

    public function testDetectReturnsCanonicalSchemaVersion(): void
    {
        $result = $this->detector->detect(
            [
                'useful_cycle_rate' => ['before' => 0.50, 'after' => 0.51],
            ],
            [
                'useful_cycle_rate' => [
                    'before' => 0.50,
                    'after' => 0.51,
                    'independent_source' => 'merged_pr_ledger',
                ],
            ],
        );

        $this->assertSame('atlas.aaeos.l8.metric_reality_divergence.v1', $result['schema_version']);
    }

    public function testMetricUpAnchorFlatFlagsDivergence(): void
    {
        $result = $this->detector->detect(
            [
                'trust_ledger_score' => ['before' => 0.60, 'after' => 0.92],
            ],
            [
                'trust_ledger_score' => [
                    'before' => 0.60,
                    'after' => 0.605,
                    'independent_source' => 'external_reality_audit',
                ],
            ],
        );

        $this->assertTrue($result['divergence_detected']);
        $this->assertSame('divergence_blocked', $result['verdict']);
        $this->assertSame(['trust_ledger_score'], $result['divergent_metrics']);
        $this->assertSame([], $result['unanchored_metrics']);
        $this->assertSame([], $result['aligned_metrics']);
        $this->assertSame('divergence', $result['per_metric']['trust_ledger_score']['status']);
    }

    public function testAnchorMissingReturnsUnknownBlockedNotPass(): void
    {
        $result = $this->detector->detect(
            [
                'provider_honesty_rate' => ['before' => 0.70, 'after' => 0.99],
            ],
            [],
        );

        $this->assertSame('unknown_blocked', $result['verdict']);
        $this->assertFalse($result['divergence_detected']);
        $this->assertNotSame('pass', $result['verdict']);
        $this->assertSame(['provider_honesty_rate'], $result['unanchored_metrics']);
        $this->assertSame([], $result['divergent_metrics']);
        $this->assertFalse($result['per_metric']['provider_honesty_rate']['anchored']);
        $this->assertSame('unanchored', $result['per_metric']['provider_honesty_rate']['status']);
    }

    public function testStableMetricAndAnchorReturnsPass(): void
    {
        $result = $this->detector->detect(
            [
                'retained_evolution_rate' => ['before' => 0.80, 'after' => 0.80],
            ],
            [
                'retained_evolution_rate' => [
                    'before' => 0.80,
                    'after' => 0.80,
                    'independent_source' => 'survived_merge_followup',
                ],
            ],
        );

        $this->assertSame('pass', $result['verdict']);
        $this->assertFalse($result['divergence_detected']);
        $this->assertSame([], $result['divergent_metrics']);
        $this->assertSame([], $result['unanchored_metrics']);
        $this->assertSame(['retained_evolution_rate'], $result['aligned_metrics']);
        $this->assertSame('aligned', $result['per_metric']['retained_evolution_rate']['status']);
    }

    public function testMetricAndAnchorRisingTogetherPasses(): void
    {
        $result = $this->detector->detect(
            [
                'dm_dt' => ['before' => 0.10, 'after' => 0.40],
            ],
            [
                'dm_dt' => [
                    'before' => 0.10,
                    'after' => 0.38,
                    'independent_source' => 'compounding_audit_fold',
                ],
            ],
        );

        $this->assertSame('pass', $result['verdict']);
        $this->assertFalse($result['divergence_detected']);
        $this->assertSame(['dm_dt'], $result['aligned_metrics']);
        // Real computed deltas, not canned values.
        $this->assertEqualsWithDelta(0.30, $result['per_metric']['dm_dt']['metric_delta'], 1e-9);
        $this->assertEqualsWithDelta(0.28, $result['per_metric']['dm_dt']['anchor_delta'], 1e-9);
    }

    public function testOpposingDirectionsFlagsDivergence(): void
    {
        // Metric climbs but the independent anchor actually falls: reality contradicts.
        $result = $this->detector->detect(
            [
                'useful_cycle_rate' => ['before' => 0.40, 'after' => 0.70],
            ],
            [
                'useful_cycle_rate' => [
                    'before' => 0.40,
                    'after' => 0.25,
                    'independent_source' => 'merged_pr_ledger',
                ],
            ],
        );

        $this->assertTrue($result['divergence_detected']);
        $this->assertSame('divergence_blocked', $result['verdict']);
        $this->assertSame(['useful_cycle_rate'], $result['divergent_metrics']);
    }

    public function testDivergenceWinsOverMissingAnchorAcrossMetrics(): void
    {
        // One gamed metric, one unanchored metric, one clean metric:
        // fail-closed priority means the divergence verdict wins.
        $result = $this->detector->detect(
            [
                'trust_ledger_score' => ['before' => 0.50, 'after' => 0.95],
                'provider_honesty_rate' => ['before' => 0.60, 'after' => 0.90],
                'retained_evolution_rate' => ['before' => 0.70, 'after' => 0.71],
            ],
            [
                'trust_ledger_score' => [
                    'before' => 0.50,
                    'after' => 0.50,
                    'independent_source' => 'external_reality_audit',
                ],
                // provider_honesty_rate intentionally has no anchor.
                'retained_evolution_rate' => [
                    'before' => 0.70,
                    'after' => 0.71,
                    'independent_source' => 'survived_merge_followup',
                ],
            ],
        );

        $this->assertTrue($result['divergence_detected']);
        $this->assertSame('divergence_blocked', $result['verdict']);
        $this->assertSame(['trust_ledger_score'], $result['divergent_metrics']);
        $this->assertSame(['provider_honesty_rate'], $result['unanchored_metrics']);
        $this->assertSame(['retained_evolution_rate'], $result['aligned_metrics']);
        $this->assertSame(3, $result['evaluated_metric_count']);
    }

    public function testSourcelessAnchorIsTreatedAsMissingAndFailsClosed(): void
    {
        // An anchor without an independent_source is self-reportable, so it
        // is not a real anchor: the metric stays unverifiable (fail-closed).
        $result = $this->detector->detect(
            [
                'dm_dt' => ['before' => 0.20, 'after' => 0.55],
            ],
            [
                'dm_dt' => ['before' => 0.20, 'after' => 0.55],
            ],
        );

        $this->assertSame('unknown_blocked', $result['verdict']);
        $this->assertFalse($result['divergence_detected']);
        $this->assertSame(['dm_dt'], $result['unanchored_metrics']);
        $this->assertFalse($result['per_metric']['dm_dt']['anchored']);
    }

    public function testEmptyInputFailsClosedToUnknownBlocked(): void
    {
        $result = $this->detector->detect([], []);

        $this->assertSame('unknown_blocked', $result['verdict']);
        $this->assertFalse($result['divergence_detected']);
        $this->assertSame(0, $result['evaluated_metric_count']);
        $this->assertSame([], $result['divergent_metrics']);
        $this->assertSame([], $result['unanchored_metrics']);
        $this->assertSame([], $result['aligned_metrics']);
    }

    public function testMetricListContractsAreZeroIndexedStringLists(): void
    {
        // Integer-like metric keys must still surface as a list<string>,
        // never an int-keyed map (honours the declared list<string> contract).
        $result = $this->detector->detect(
            [
                7 => ['before' => 0.10, 'after' => 0.90],
            ],
            [],
        );

        $this->assertSame(['7'], $result['unanchored_metrics']);
        $this->assertSame([0], array_keys($result['unanchored_metrics']));
        $this->assertSame('7', $result['unanchored_metrics'][0]);
        $this->assertArrayHasKey('7', $result['per_metric']);
    }

    public function testNonFiniteMetricReadingFailsClosedAndStaysJsonEncodable(): void
    {
        // A NAN self-reported metric reading (e.g. an upstream 0/0 rate) against a
        // well-formed flat anchor is the canonical poisoned-number case. Under the
        // fail-closed contract it must NOT be flattened to aligned/pass: a reading
        // that cannot be corroborated is divergence. The emitted metric_delta must
        // stay a finite float so the whole result remains JSON-encodable (a leaked
        // NAN would make the evidence/receipt payload un-encodable).
        $nanResult = $this->detector->detect(
            [
                'useful_cycle_rate' => ['before' => 0.50, 'after' => NAN],
            ],
            [
                'useful_cycle_rate' => [
                    'before' => 0.50,
                    'after' => 0.50,
                    'independent_source' => 'evidence_ledger',
                ],
            ],
        );

        $this->assertSame('divergence_blocked', $nanResult['verdict']);
        $this->assertTrue($nanResult['divergence_detected']);
        $this->assertNotSame('pass', $nanResult['verdict']);
        $this->assertSame('divergence', $nanResult['per_metric']['useful_cycle_rate']['status']);
        $this->assertSame(['useful_cycle_rate'], $nanResult['divergent_metrics']);
        $this->assertTrue(is_finite($nanResult['per_metric']['useful_cycle_rate']['metric_delta']));
        $this->assertFalse(is_nan($nanResult['per_metric']['useful_cycle_rate']['metric_delta']));
        $this->assertNotFalse(json_encode($nanResult), 'detect() result must stay JSON-encodable');

        // An overflowed INF metric reading is the same poisoned-number family and
        // must likewise stay blocked with a finite, encodable emitted delta.
        $infResult = $this->detector->detect(
            [
                'useful_cycle_rate' => ['before' => 0.50, 'after' => INF],
            ],
            [
                'useful_cycle_rate' => [
                    'before' => 0.50,
                    'after' => 0.50,
                    'independent_source' => 'evidence_ledger',
                ],
            ],
        );

        $this->assertSame('divergence_blocked', $infResult['verdict']);
        $this->assertTrue(is_finite($infResult['per_metric']['useful_cycle_rate']['metric_delta']));
        $this->assertNotFalse(json_encode($infResult), 'detect() result must stay JSON-encodable');
    }

    public function testResultIsDeterministicForIdenticalInput(): void
    {
        $metrics = [
            'useful_cycle_rate' => ['before' => 0.30, 'after' => 0.62],
        ];
        $anchors = [
            'useful_cycle_rate' => [
                'before' => 0.30,
                'after' => 0.31,
                'independent_source' => 'merged_pr_ledger',
            ],
        ];

        $first = $this->detector->detect($metrics, $anchors);
        $second = $this->detector->detect($metrics, $anchors);

        $this->assertSame($first, $second);
    }
}
