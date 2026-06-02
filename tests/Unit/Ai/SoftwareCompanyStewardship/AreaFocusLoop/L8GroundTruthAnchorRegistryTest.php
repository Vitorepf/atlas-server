<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8GroundTruthAnchorRegistry;
use PHPUnit\Framework\TestCase;

final class L8GroundTruthAnchorRegistryTest extends TestCase
{
    private L8GroundTruthAnchorRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new L8GroundTruthAnchorRegistry();
    }

    public function testAnchorsReturnsCanonicalSchemaVersion(): void
    {
        $result = $this->registry->anchors();

        $this->assertSame(
            'atlas.aaeos.l8.ground_truth_anchor_registry.v1',
            $result['schema_version'],
        );
    }

    public function testEveryOptimizedMetricHasIndependentImmutableNonSelfReportedAnchor(): void
    {
        $result = $this->registry->anchors();

        $this->assertNotSame([], $result['optimized_metrics']);

        foreach ($result['optimized_metrics'] as $anchor) {
            $this->assertArrayHasKey('optimized_metric', $anchor);
            $this->assertArrayHasKey('independent_source', $anchor);

            $this->assertIsString($anchor['optimized_metric']);
            $this->assertNotSame('', $anchor['optimized_metric']);

            $this->assertIsString($anchor['independent_source']);
            $this->assertNotSame('', $anchor['independent_source']);
            // The source must be genuinely independent of the optimized metric:
            // an anchor sourced from the metric itself would be self-reporting.
            $this->assertNotSame($anchor['optimized_metric'], $anchor['independent_source']);

            $this->assertTrue($anchor['immutable']);
            $this->assertTrue($anchor['cannot_be_self_reported']);
        }
    }

    public function testAnchorsCoverExactlyTheFiveCanonicalMetrics(): void
    {
        $result = $this->registry->anchors();

        $metrics = array_map(
            static fn (array $anchor): string => $anchor['optimized_metric'],
            $result['optimized_metrics'],
        );

        $expected = [
            'useful_cycle_rate',
            'trust_ledger_score',
            'dm_dt',
            'retained_evolution_rate',
            'provider_honesty_rate',
        ];

        $this->assertSame($expected, $metrics);
        $this->assertSame($expected, $this->registry->metricNames());
        $this->assertCount(5, $result['optimized_metrics']);
    }

    public function testEachCanonicalMetricResolvesToItsIndependentSource(): void
    {
        $this->assertSame('evidence_ledger', $this->registry->sourceFor('useful_cycle_rate'));
        $this->assertSame('trust_ledger_canonical', $this->registry->sourceFor('trust_ledger_score'));
        $this->assertSame('compounding_audit', $this->registry->sourceFor('dm_dt'));
        $this->assertSame(
            'measured_or_reverted_outcome_ledger',
            $this->registry->sourceFor('retained_evolution_rate'),
        );
        $this->assertSame('aemor_honesty_guard', $this->registry->sourceFor('provider_honesty_rate'));
        $this->assertNull($this->registry->sourceFor('self_declared_score'));
    }

    public function testCompleteCanonicalAnchorSetIsAdmitted(): void
    {
        $result = $this->registry->validate($this->registry->metricNames());

        $this->assertSame(
            'atlas.aaeos.l8.ground_truth_anchor_registry.v1',
            $result['schema_version'],
        );
        $this->assertTrue($result['admitted']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['duplicate_anchors']);
        $this->assertSame([], $result['missing_anchors']);
        $this->assertSame([], $result['unknown_anchors']);
    }

    public function testDuplicateAnchorBlocks(): void
    {
        $reported = [
            'useful_cycle_rate',
            'trust_ledger_score',
            'dm_dt',
            'retained_evolution_rate',
            'provider_honesty_rate',
            'dm_dt',
        ];

        $result = $this->registry->validate($reported);

        $this->assertFalse($result['admitted']);
        $this->assertContains('duplicate_anchor', $result['blockers']);
        $this->assertSame(['dm_dt'], $result['duplicate_anchors']);
    }

    public function testMissingAnchorBlocks(): void
    {
        $reported = [
            'useful_cycle_rate',
            'trust_ledger_score',
            'dm_dt',
            'retained_evolution_rate',
        ];

        $result = $this->registry->validate($reported);

        $this->assertFalse($result['admitted']);
        $this->assertContains('missing_anchor', $result['blockers']);
        $this->assertSame(['provider_honesty_rate'], $result['missing_anchors']);
    }

    public function testUnknownAnchorBlocks(): void
    {
        $reported = [
            'useful_cycle_rate',
            'trust_ledger_score',
            'dm_dt',
            'retained_evolution_rate',
            'provider_honesty_rate',
            'self_declared_score',
        ];

        $result = $this->registry->validate($reported);

        $this->assertFalse($result['admitted']);
        $this->assertContains('unknown_anchor', $result['blockers']);
        $this->assertSame(['self_declared_score'], $result['unknown_anchors']);
    }

    public function testDuplicateAndMissingAnchorsBlockSimultaneously(): void
    {
        // A report that both duplicates one canonical anchor and omits another
        // must surface BOTH blockers independently, not short-circuit on the
        // first — every fail-closed signal has to be reported.
        $reported = [
            'useful_cycle_rate',
            'useful_cycle_rate',
            'trust_ledger_score',
            'dm_dt',
            'retained_evolution_rate',
        ];

        $result = $this->registry->validate($reported);

        $this->assertFalse($result['admitted']);
        $this->assertSame(['duplicate_anchor', 'missing_anchor'], $result['blockers']);
        $this->assertSame(['useful_cycle_rate'], $result['duplicate_anchors']);
        $this->assertSame(['provider_honesty_rate'], $result['missing_anchors']);
        $this->assertSame([], $result['unknown_anchors']);
    }

    public function testAnchorsAreDeterministic(): void
    {
        $this->assertSame($this->registry->anchors(), $this->registry->anchors());
    }

    public function testMalformedNonStringAnchorEntryFailsClosedWithoutCrashing(): void
    {
        // The registry exists to reject anchors that could be self-introduced.
        // A malformed reported entry (an object that is not stringable, or a
        // nested array) must therefore fail closed as an unknown anchor — never
        // crash the gate with a TypeError and never leak a PHP "Array to string"
        // warning while emitting a meaningless token. Either failure mode would
        // let a malformed Goodhart-proofing payload break, rather than be
        // rejected by, the immutable registry.
        $reported = [
            'useful_cycle_rate',
            'trust_ledger_score',
            'dm_dt',
            'retained_evolution_rate',
            'provider_honesty_rate',
            new \stdClass(),
            ['nested' => 'anchor'],
        ];

        $result = $this->registry->validate($reported);

        $this->assertFalse($result['admitted']);
        $this->assertContains('unknown_anchor', $result['blockers']);
        $this->assertSame([], $result['missing_anchors']);
        $this->assertSame([], $result['duplicate_anchors']);

        // The unknown list stays a clean list<string>: no "Array" token, no
        // empty string, just the deterministic non-string sentinel (deduped).
        $this->assertSame(['<non_string_anchor>'], $result['unknown_anchors']);

        foreach ($result['unknown_anchors'] as $entry) {
            $this->assertIsString($entry);
        }
    }
}
