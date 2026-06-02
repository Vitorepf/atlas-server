<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\TrustLedgerStabilityGate;
use PHPUnit\Framework\TestCase;

final class TrustLedgerStabilityGateTest extends TestCase
{
    private TrustLedgerStabilityGate $gate;

    protected function setUp(): void
    {
        $this->gate = new TrustLedgerStabilityGate();
    }

    public function testSchemaVersionAndThresholdAreCanonical(): void
    {
        $result = $this->gate->evaluate([]);

        $this->assertSame('atlas.trust_ledger.stability_gate.v1', $result['schema_version']);
        $this->assertSame(0.95, $result['threshold']);
    }

    public function testScoreAtThresholdOverStableWindowPasses(): void
    {
        $result = $this->gate->evaluate([
            ['kind' => 'cert_pass', 'weight' => 60.0, 'at' => '2026-03-01T00:00:00Z'],
            ['kind' => 'promotion', 'weight' => 35.0, 'at' => '2026-03-02T00:00:00Z'],
            ['kind' => 'drift_detected', 'weight' => 5.0, 'at' => '2026-03-03T00:00:00Z'],
        ]);

        // 95 success / 100 total = 0.95 exactly, no penalties.
        $this->assertEqualsWithDelta(0.95, $result['score'], 1.0e-9);
        $this->assertSame([], $result['penalties']);
        $this->assertTrue($result['stable']);
        $this->assertTrue($result['passed']);
    }

    public function testScoreJustBelowThresholdBlocksEvenWhenStable(): void
    {
        $result = $this->gate->evaluate([
            ['kind' => 'cert_pass', 'weight' => 900.0, 'at' => '2026-03-01T00:00:00Z'],
            ['kind' => 'promotion', 'weight' => 49.0, 'at' => '2026-03-02T00:00:00Z'],
            ['kind' => 'drift_detected', 'weight' => 51.0, 'at' => '2026-03-03T00:00:00Z'],
        ]);

        // 949 success / 1000 total = 0.949 -> below the 0.95 hard gate.
        $this->assertEqualsWithDelta(0.949, $result['score'], 1.0e-9);
        $this->assertTrue($result['stable']);
        $this->assertFalse($result['passed']);
    }

    public function testScoreAtThresholdStillBlocksWhenWindowIsNotStable(): void
    {
        // Score is a perfect 1.0 (signature_breach carries zero weight so it does
        // not move the ratio), but the breach destabilises the window. The hard
        // gate must still block: >= 0.95 passes ONLY when stable === true.
        $result = $this->gate->evaluate([
            ['kind' => 'cert_pass', 'weight' => 1.0, 'at' => '2026-03-01T00:00:00Z'],
            ['kind' => 'cert_pass', 'weight' => 1.0, 'at' => '2026-03-02T00:00:00Z'],
            ['kind' => 'cert_pass', 'weight' => 1.0, 'at' => '2026-03-03T00:00:00Z'],
            ['kind' => 'signature_breach', 'weight' => 0.0, 'at' => '2026-03-04T00:00:00Z'],
        ]);

        $this->assertGreaterThanOrEqual(0.95, $result['score']);
        $this->assertSame([], $result['penalties']);
        $this->assertFalse($result['stable']);
        $this->assertFalse($result['passed']);
    }

    public function testProviderInvokedWithoutReceiptPenalizesAndBlocks(): void
    {
        $result = $this->gate->evaluate([
            ['kind' => 'cert_pass', 'weight' => 100.0, 'at' => '2026-03-01T00:00:00Z'],
            ['kind' => 'cert_pass', 'weight' => 100.0, 'at' => '2026-03-02T00:00:00Z'],
            ['kind' => 'provider_invoked', 'weight' => 0.0, 'at' => '2026-03-03T00:00:00Z'],
        ]);

        $this->assertContains('provider_invoked_without_receipt', $result['penalties']);
        $this->assertFalse($result['stable']);
        $this->assertFalse($result['passed']);
    }

    public function testProviderInvokedWithReceiptDoesNotPenalize(): void
    {
        $result = $this->gate->evaluate([
            ['kind' => 'cert_pass', 'weight' => 100.0, 'at' => '2026-03-01T00:00:00Z'],
            ['kind' => 'cert_pass', 'weight' => 100.0, 'at' => '2026-03-02T00:00:00Z'],
            ['kind' => 'provider_invoked', 'weight' => 1.0, 'receipt' => 'sha256:abc', 'at' => '2026-03-03T00:00:00Z'],
        ]);

        $this->assertNotContains('provider_invoked_without_receipt', $result['penalties']);
        $this->assertSame([], $result['penalties']);
    }

    public function testFalseMergePenalizesAndBlocks(): void
    {
        $result = $this->gate->evaluate([
            ['kind' => 'cert_pass', 'weight' => 100.0, 'at' => '2026-03-01T00:00:00Z'],
            ['kind' => 'cert_pass', 'weight' => 100.0, 'at' => '2026-03-02T00:00:00Z'],
            ['kind' => 'merge', 'truthful' => false, 'weight' => 1.0, 'at' => '2026-03-03T00:00:00Z'],
        ]);

        $this->assertContains('false_merge', $result['penalties']);
        $this->assertFalse($result['stable']);
        $this->assertFalse($result['passed']);
    }

    public function testTruthfulMergeDoesNotPenalize(): void
    {
        $result = $this->gate->evaluate([
            ['kind' => 'cert_pass', 'weight' => 100.0, 'at' => '2026-03-01T00:00:00Z'],
            ['kind' => 'cert_pass', 'weight' => 100.0, 'at' => '2026-03-02T00:00:00Z'],
            ['kind' => 'merge', 'truthful' => true, 'weight' => 1.0, 'at' => '2026-03-03T00:00:00Z'],
        ]);

        $this->assertNotContains('false_merge', $result['penalties']);
        $this->assertSame([], $result['penalties']);
    }

    public function testEachPenaltyDeductsFixedTrustCost(): void
    {
        $result = $this->gate->evaluate([
            ['kind' => 'cert_pass', 'weight' => 100.0, 'at' => '2026-03-01T00:00:00Z'],
            ['kind' => 'provider_invoked', 'weight' => 0.0, 'at' => '2026-03-02T00:00:00Z'],
        ]);

        // base = 100/100 = 1.0; one penalty deducts 0.10 -> 0.90.
        $this->assertEqualsWithDelta(0.90, $result['score'], 1.0e-9);
        $this->assertSame(['provider_invoked_without_receipt'], $result['penalties']);
        $this->assertFalse($result['passed']);
    }

    public function testWindowStartAndEndAreMinAndMaxTimestamps(): void
    {
        $result = $this->gate->evaluate([
            ['kind' => 'cert_pass', 'weight' => 1.0, 'at' => '2026-03-05T00:00:00Z'],
            ['kind' => 'cert_pass', 'weight' => 1.0, 'at' => '2026-03-01T00:00:00Z'],
            ['kind' => 'cert_pass', 'weight' => 1.0, 'at' => '2026-03-09T00:00:00Z'],
        ]);

        $this->assertSame('2026-03-01T00:00:00Z', $result['window_start']);
        $this->assertSame('2026-03-09T00:00:00Z', $result['window_end']);
    }

    public function testPenaltiesIsOrderedListOfStrings(): void
    {
        $result = $this->gate->evaluate([
            ['kind' => 'provider_invoked', 'weight' => 0.0, 'at' => '2026-03-01T00:00:00Z'],
            ['kind' => 'merge', 'truthful' => false, 'weight' => 0.0, 'at' => '2026-03-02T00:00:00Z'],
        ]);

        $this->assertSame(
            ['provider_invoked_without_receipt', 'false_merge'],
            $result['penalties'],
        );
        $this->assertTrue(array_is_list($result['penalties']));
        foreach ($result['penalties'] as $penalty) {
            $this->assertIsString($penalty);
        }
    }

    public function testScoreNeverExceedsUpperBoundAndClampsAtZero(): void
    {
        // base would be 1.0, but eleven penalties drive the raw value to -0.10.
        $events = [['kind' => 'cert_pass', 'weight' => 100.0, 'at' => '2026-03-01T00:00:00Z']];
        for ($i = 0; $i < 11; ++$i) {
            $events[] = ['kind' => 'provider_invoked', 'weight' => 0.0, 'at' => '2026-03-02T00:00:00Z'];
        }

        $result = $this->gate->evaluate($events);

        $this->assertSame(0.0, $result['score']);
        $this->assertGreaterThanOrEqual(0.0, $result['score']);
        $this->assertLessThanOrEqual(1.0, $result['score']);
    }

    public function testOverflowingMassesKeepScoreWithinBoundAndFailClosed(): void
    {
        // BOUND GUARD: finite per-event weights can still sum past PHP_FLOAT_MAX to
        // INF, making the success/total ratio INF/INF = NAN. NAN escapes a naive
        // min/max clamp (every NAN comparison is false), which would leak a
        // non-[0,1] score and could slip past the >= 0.95 gate. The documented
        // inclusive 0..1 bound must hold, and a NAN ratio must fail closed.
        $events = [];
        for ($i = 0; $i < 8; ++$i) {
            $events[] = ['kind' => 'cert_pass', 'weight' => PHP_FLOAT_MAX, 'at' => '2026-03-01T00:00:00Z'];
        }
        for ($i = 0; $i < 8; ++$i) {
            $events[] = ['kind' => 'drift_detected', 'weight' => PHP_FLOAT_MAX, 'at' => '2026-03-02T00:00:00Z'];
        }
        $events[] = ['kind' => 'cert_pass', 'weight' => 1.0, 'at' => '2026-03-03T00:00:00Z'];

        $result = $this->gate->evaluate($events);

        $this->assertFalse(is_nan($result['score']), 'score must never be NAN');
        $this->assertGreaterThanOrEqual(0.0, $result['score']);
        $this->assertLessThanOrEqual(1.0, $result['score']);
        $this->assertSame(0.0, $result['score']);
        $this->assertFalse($result['passed']);
    }

    public function testEmptyWindowScoresZeroAndIsNotStable(): void
    {
        $result = $this->gate->evaluate([]);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('', $result['window_start']);
        $this->assertSame('', $result['window_end']);
        $this->assertSame([], $result['penalties']);
        $this->assertFalse($result['stable']);
        $this->assertFalse($result['passed']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $events = [
            ['kind' => 'cert_pass', 'weight' => 60.0, 'at' => '2026-03-01T00:00:00Z'],
            ['kind' => 'promotion', 'weight' => 35.0, 'at' => '2026-03-02T00:00:00Z'],
            ['kind' => 'drift_detected', 'weight' => 5.0, 'at' => '2026-03-03T00:00:00Z'],
        ];

        $this->assertSame($this->gate->evaluate($events), $this->gate->evaluate($events));
    }
}
