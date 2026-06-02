<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\InvariantBreachDemoteMonitor;
use PHPUnit\Framework\TestCase;

/**
 * S94 contract tests for the InvariantBreachDemoteMonitor.
 *
 * The monitor is pure and deterministic: it derives every field from the
 * invariant records and ladder state with no IO. Doctrine under test:
 * "Invariant beats score; demote is automatic."
 */
final class InvariantBreachDemoteMonitorTest extends TestCase
{
    private InvariantBreachDemoteMonitor $monitor;

    protected function setUp(): void
    {
        $this->monitor = new InvariantBreachDemoteMonitor();
    }

    public function testSchemaVersionIsStable(): void
    {
        $result = $this->monitor->evaluate([], ['current_level' => 'L7']);

        $this->assertSame('atlas.loop.invariant_breach_demote_monitor.v1', $result['schema_version']);
    }

    public function testCleanInvariantsDoNotRequireDemote(): void
    {
        $result = $this->monitor->evaluate(
            [
                ['id' => 'local_first_sovereignty', 'breached' => false],
                ['id' => 'append_only_evidence_ledger', 'breached' => false],
                ['id' => 'no_reset_hard_on_self_evolution', 'status' => 'pass'],
            ],
            [
                'current_level' => 'L7',
                'trust_ledger_score' => 0.97,
            ],
        );

        $this->assertSame(0, $result['invariant_breach_count']);
        $this->assertSame([], $result['breached_invariants']);
        $this->assertFalse($result['demote_required']);
        $this->assertFalse($result['promotion_blocked']);
        $this->assertSame('L7', $result['current_level']);
        $this->assertSame('L7', $result['demote_to_level']);
        $this->assertSame([], $result['blockers']);
    }

    public function testSingleBreachBlocksPromotionAndDemotesFromL7(): void
    {
        $result = $this->monitor->evaluate(
            [
                ['id' => 'local_first_sovereignty', 'breached' => false],
                ['id' => 'append_only_evidence_ledger', 'breached' => true],
            ],
            [
                'current_level' => 'L7',
                'trust_ledger_score' => 0.60,
            ],
        );

        $this->assertSame(1, $result['invariant_breach_count']);
        $this->assertSame(['append_only_evidence_ledger'], $result['breached_invariants']);
        $this->assertTrue($result['demote_required']);
        $this->assertTrue($result['promotion_blocked']);
        $this->assertSame('L7', $result['current_level']);
        $this->assertSame('L6', $result['demote_to_level']);
        $this->assertSame(['sacred_invariant_breached:append_only_evidence_ledger'], $result['blockers']);
    }

    public function testBreachCountOneDemotesEvenWithTrustAtOrAboveGate(): void
    {
        $result = $this->monitor->evaluate(
            [
                ['id' => 'no_reset_hard_on_self_evolution', 'breached' => true],
            ],
            [
                'current_level' => 'L7',
                'trust_ledger_score' => 0.95,
            ],
        );

        // Invariant beats score: Trust at the 0.95 gate cannot suppress demote.
        $this->assertSame(1, $result['invariant_breach_count']);
        $this->assertTrue($result['trust_gate_satisfied']);
        $this->assertTrue($result['demote_required']);
        $this->assertTrue($result['promotion_blocked']);
        $this->assertSame('L6', $result['demote_to_level']);
    }

    public function testBreachDemotesEvenWhenTrustWellAboveGate(): void
    {
        $result = $this->monitor->evaluate(
            [
                ['id' => 'sensitive_class_never_leaves_machine', 'breached' => true],
            ],
            [
                'current_level' => 'L7',
                'trust_ledger_score' => 0.99,
            ],
        );

        $this->assertEqualsWithDelta(0.99, $result['trust_ledger_score'], 1.0e-9);
        $this->assertTrue($result['trust_gate_satisfied']);
        $this->assertTrue($result['demote_required']);
        $this->assertSame('L6', $result['demote_to_level']);
    }

    public function testMultipleBreachesAreCountedAndOrdered(): void
    {
        $result = $this->monitor->evaluate(
            [
                ['id' => 'alpha', 'breached' => true],
                ['id' => 'beta', 'breached' => false],
                ['id' => 'gamma', 'status' => 'violated'],
                ['id' => 'delta', 'ok' => false],
            ],
            [
                'current_level' => 'L7',
                'trust_ledger_score' => 0.98,
            ],
        );

        $this->assertSame(3, $result['invariant_breach_count']);
        $this->assertSame(['alpha', 'gamma', 'delta'], $result['breached_invariants']);
        $this->assertCount($result['invariant_breach_count'], $result['breached_invariants']);
        $this->assertTrue($result['demote_required']);
    }

    public function testBreachedInvariantsIsListOfStringsWithAssociativeInput(): void
    {
        // Associative (string-keyed) map with int-like names: the contract must
        // remain a sequential list<string>, never leaking int keys or types.
        $result = $this->monitor->evaluate(
            [
                'first' => ['name' => 100, 'breached' => true],
                'second' => ['name' => 'second_invariant', 'breached' => true],
            ],
            ['current_level' => 'L7'],
        );

        $this->assertSame(['100', 'second_invariant'], $result['breached_invariants']);
        $this->assertSame([0, 1], array_keys($result['breached_invariants']));

        foreach ($result['breached_invariants'] as $name) {
            $this->assertIsString($name);
        }
    }

    public function testDuplicateBreachedNamesAreDeduplicated(): void
    {
        $result = $this->monitor->evaluate(
            [
                ['id' => 'same_invariant', 'breached' => true],
                ['id' => 'same_invariant', 'breached' => true],
                ['id' => 'other_invariant', 'breached' => true],
            ],
            ['current_level' => 'L7'],
        );

        $this->assertSame(['same_invariant', 'other_invariant'], $result['breached_invariants']);
        $this->assertSame(2, $result['invariant_breach_count']);
    }

    public function testDemoteStepsExactlyOneLevelFromNonL7State(): void
    {
        $result = $this->monitor->evaluate(
            [
                ['id' => 'governed_revert_only', 'breached' => true],
            ],
            ['current_level' => 'L5'],
        );

        $this->assertTrue($result['demote_required']);
        $this->assertSame('L5', $result['current_level']);
        $this->assertSame('L4', $result['demote_to_level']);
    }

    public function testDemoteFloorsAtL0(): void
    {
        $result = $this->monitor->evaluate(
            [
                ['id' => 'floor_invariant', 'breached' => true],
            ],
            ['current_level' => 'L0'],
        );

        $this->assertTrue($result['demote_required']);
        $this->assertSame('L0', $result['demote_to_level']);
    }

    public function testIntegerLevelIsNormalisedToLPrefixedString(): void
    {
        $result = $this->monitor->evaluate(
            [
                ['id' => 'normalise_invariant', 'breached' => true],
            ],
            ['current_level' => 7],
        );

        $this->assertSame('L7', $result['current_level']);
        $this->assertSame('L6', $result['demote_to_level']);
    }

    public function testCleanStateAtTrustGateStillPromotable(): void
    {
        $result = $this->monitor->evaluate(
            [
                ['id' => 'clean_one', 'breached' => false],
                ['id' => 'clean_two', 'status' => 'ok'],
            ],
            [
                'current_level' => 'L7',
                'trust_ledger_score' => 0.95,
            ],
        );

        $this->assertSame(0, $result['invariant_breach_count']);
        $this->assertFalse($result['demote_required']);
        $this->assertFalse($result['promotion_blocked']);
        $this->assertTrue($result['trust_gate_satisfied']);
    }

    public function testTrustGateNotSatisfiedBelowThreshold(): void
    {
        $result = $this->monitor->evaluate(
            [],
            [
                'current_level' => 'L7',
                'trust_ledger_score' => 0.949,
            ],
        );

        $this->assertFalse($result['trust_gate_satisfied']);
        $this->assertFalse($result['demote_required']);
    }

    public function testNonArrayInvariantRecordsAreIgnored(): void
    {
        $result = $this->monitor->evaluate(
            [
                'garbage_string',
                42,
                ['id' => 'real_breach', 'breached' => true],
            ],
            ['current_level' => 'L7'],
        );

        $this->assertSame(1, $result['invariant_breach_count']);
        $this->assertSame(['real_breach'], $result['breached_invariants']);
    }

    public function testFailingStatusWithSurroundingWhitespaceStillBreaches(): void
    {
        // Fail-closed: a failing `status` carrying incidental casing/whitespace
        // (as emitted by a log/YAML source) must still be caught as a breach.
        // A padded "  FAILED  " must NOT silently evade the demote gate.
        $result = $this->monitor->evaluate(
            [
                ['id' => 'padded_violation', 'status' => '  FAILED  '],
                ['id' => 'clean_one', 'status' => '  pass  '],
                ['id' => 'newline_breach', 'status' => "breached\n"],
            ],
            [
                'current_level' => 'L7',
                'trust_ledger_score' => 0.99,
            ],
        );

        $this->assertSame(2, $result['invariant_breach_count']);
        $this->assertSame(['padded_violation', 'newline_breach'], $result['breached_invariants']);
        $this->assertTrue($result['demote_required']);
        $this->assertTrue($result['promotion_blocked']);
        $this->assertSame('L6', $result['demote_to_level']);
    }
}
