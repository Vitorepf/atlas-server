<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9DelegationBoundaryFromProofService;
use PHPUnit\Framework\TestCase;

final class L9DelegationBoundaryFromProofServiceTest extends TestCase
{
    private L9DelegationBoundaryFromProofService $service;

    protected function setUp(): void
    {
        $this->service = new L9DelegationBoundaryFromProofService();
    }

    public function testComputeReturnsAllRequiredFieldsWithSchema(): void
    {
        $result = $this->service->compute(
            [
                ['verified' => true, 'risk_level' => 'medium', 'proof_ref' => 'proof:merge_truth'],
            ],
            [
                ['decision_class' => 'auto_merge_low', 'risk_level' => 'low'],
                ['decision_class' => 'scope_change_high', 'risk_level' => 'high'],
            ],
        );

        $this->assertSame('atlas.aaeos.l9.delegation_boundary_from_proof.v1', $result['schema_version']);
        $this->assertSame(['auto_merge_low'], $result['allowed_decision_classes']);
        $this->assertSame(['scope_change_high'], $result['blocked_decision_classes']);
        $this->assertSame('medium', $result['max_risk_level']);
        $this->assertSame(['proof:merge_truth'], $result['proof_refs']);
    }

    public function testNoVerifiedProofYieldsEmptyAllowedClasses(): void
    {
        $result = $this->service->compute(
            [
                ['verified' => false, 'risk_level' => 'high', 'proof_ref' => 'proof:unverified'],
                ['risk_level' => 'critical', 'proof_ref' => 'proof:missing_flag'],
            ],
            [
                ['decision_class' => 'auto_merge_low', 'risk_level' => 'low'],
                ['decision_class' => 'auto_merge_medium', 'risk_level' => 'medium'],
            ],
        );

        $this->assertSame([], $result['allowed_decision_classes']);
        $this->assertSame(['auto_merge_low', 'auto_merge_medium'], $result['blocked_decision_classes']);
        $this->assertSame('none', $result['max_risk_level']);
        $this->assertSame([], $result['proof_refs']);
        $this->assertTrue($result['operator_override_required']);
    }

    public function testUnknownRiskClassBlocks(): void
    {
        $result = $this->service->compute(
            [
                ['verified' => true, 'risk_level' => 'critical', 'proof_ref' => 'proof:all_invariants'],
            ],
            [
                ['decision_class' => 'known_high', 'risk_level' => 'high'],
                ['decision_class' => 'mystery_class', 'risk_level' => 'galactic'],
                ['decision_class' => 'missing_risk_class'],
            ],
        );

        $this->assertSame(['known_high'], $result['allowed_decision_classes']);
        $this->assertSame(['missing_risk_class', 'mystery_class'], $result['blocked_decision_classes']);
        $this->assertSame('critical', $result['max_risk_level']);
        $this->assertTrue($result['operator_override_required']);
    }

    public function testOperatorOverrideRequiredAboveBoundary(): void
    {
        $result = $this->service->compute(
            [
                ['verified' => true, 'risk_level' => 'low', 'proof_ref' => 'proof:low_only'],
            ],
            [
                ['decision_class' => 'within_low', 'risk_level' => 'low'],
                ['decision_class' => 'above_high', 'risk_level' => 'high'],
            ],
        );

        $this->assertSame(['within_low'], $result['allowed_decision_classes']);
        $this->assertSame(['above_high'], $result['blocked_decision_classes']);
        $this->assertSame('low', $result['max_risk_level']);
        $this->assertTrue($result['operator_override_required']);
    }

    public function testWithinBoundaryRequestNeedsNoOverride(): void
    {
        $result = $this->service->compute(
            [
                ['verified' => true, 'risk_level' => 'high', 'proof_ref' => 'proof:high_boundary'],
            ],
            [
                ['decision_class' => 'low_class', 'risk_level' => 'low'],
                ['decision_class' => 'medium_class', 'risk_level' => 'medium'],
                ['decision_class' => 'high_class', 'risk_level' => 'high'],
            ],
        );

        $this->assertSame(['high_class', 'low_class', 'medium_class'], $result['allowed_decision_classes']);
        $this->assertSame([], $result['blocked_decision_classes']);
        $this->assertSame('high', $result['max_risk_level']);
        $this->assertFalse($result['operator_override_required']);
    }

    public function testMaxRiskLevelTakesHighestVerifiedProofRankAcrossDeduplicatedRefs(): void
    {
        $result = $this->service->compute(
            [
                ['verified' => true, 'risk_level' => 'low', 'proof_ref' => 'proof:b'],
                ['verified' => true, 'risk_level' => 'high', 'proof_ref' => 'proof:a'],
                ['verified' => true, 'risk_level' => 'medium', 'proof_ref' => 'proof:a'],
                ['verified' => false, 'risk_level' => 'critical', 'proof_ref' => 'proof:c'],
            ],
            [
                ['decision_class' => 'critical_class', 'risk_level' => 'critical'],
            ],
        );

        // Boundary is the highest VERIFIED rank (high), so a critical request is blocked.
        $this->assertSame('high', $result['max_risk_level']);
        $this->assertSame([], $result['allowed_decision_classes']);
        $this->assertSame(['critical_class'], $result['blocked_decision_classes']);
        // Only verified proofs contribute refs, deduplicated and sorted.
        $this->assertSame(['proof:a', 'proof:b'], $result['proof_refs']);
    }

    public function testDelegationBoundedByProofNotByConfidence(): void
    {
        // A request marked maximally confident is still blocked when proof only
        // covers a lower risk level: delegation is bounded by proof, never confidence.
        $result = $this->service->compute(
            [
                ['verified' => true, 'risk_level' => 'medium', 'proof_ref' => 'proof:medium'],
            ],
            [
                ['decision_class' => 'confident_critical', 'risk_level' => 'critical', 'confidence' => 1.0],
            ],
        );

        $this->assertSame([], $result['allowed_decision_classes']);
        $this->assertSame(['confident_critical'], $result['blocked_decision_classes']);
        $this->assertSame('medium', $result['max_risk_level']);
        $this->assertTrue($result['operator_override_required']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $proofs = [
            ['verified' => true, 'risk_level' => 'high', 'proof_ref' => 'proof:x'],
        ];
        $requested = [
            ['decision_class' => 'medium_class', 'risk_level' => 'medium'],
            ['decision_class' => 'critical_class', 'risk_level' => 'critical'],
        ];

        $first = $this->service->compute($proofs, $requested);
        $second = $this->service->compute($proofs, $requested);

        $this->assertSame($first, $second);
    }

    public function testBlockedClassIsNeverAlsoAllowedWhenSameClassRequestedAtConflictingRisk(): void
    {
        // The same decision_class is requested twice: once within the proven
        // boundary (low) and once above it (critical). Because delegation is
        // bounded by proof, the above-boundary request must poison the class:
        // it stays blocked and must NOT leak into the allowed set.
        $result = $this->service->compute(
            [
                ['verified' => true, 'risk_level' => 'medium', 'proof_ref' => 'proof:medium'],
            ],
            [
                ['decision_class' => 'mixed_class', 'risk_level' => 'low'],
                ['decision_class' => 'mixed_class', 'risk_level' => 'critical'],
            ],
        );

        $this->assertSame([], $result['allowed_decision_classes']);
        $this->assertSame(['mixed_class'], $result['blocked_decision_classes']);
        $this->assertNotContains('mixed_class', $result['allowed_decision_classes']);
        $this->assertTrue($result['operator_override_required']);
    }

    public function testEmptyInputsYieldEmptyBoundary(): void
    {
        $result = $this->service->compute([], []);

        $this->assertSame([], $result['allowed_decision_classes']);
        $this->assertSame([], $result['blocked_decision_classes']);
        $this->assertSame('none', $result['max_risk_level']);
        $this->assertSame([], $result['proof_refs']);
        $this->assertFalse($result['operator_override_required']);
    }

    public function testNumericStringClassesAndRefsStayStringListsSortedLexically(): void
    {
        // decision_class / proof_ref are list<string>, yet numeric-looking ids
        // ('100', '20', '3') would be coerced to int array keys and then ordered
        // numerically. The contract demands a stable lexicographic list<string>.
        $result = $this->service->compute(
            [
                ['verified' => true, 'risk_level' => 'critical', 'proof_ref' => '100'],
                ['verified' => true, 'risk_level' => 'critical', 'proof_ref' => '20'],
                ['verified' => true, 'risk_level' => 'critical', 'proof_ref' => '3'],
            ],
            [
                ['decision_class' => '100', 'risk_level' => 'low'],
                ['decision_class' => '20', 'risk_level' => 'low'],
                ['decision_class' => '3', 'risk_level' => 'low'],
            ],
        );

        // Lexicographic, NOT numeric: '100' < '20' < '3' as strings.
        $this->assertSame(['100', '20', '3'], $result['allowed_decision_classes']);
        $this->assertSame(['100', '20', '3'], $result['proof_refs']);

        // list<string> contract: every element is a string, keys re-indexed 0..n.
        $this->assertSame(array_values($result['allowed_decision_classes']), $result['allowed_decision_classes']);
        $this->assertSame(array_values($result['proof_refs']), $result['proof_refs']);
        foreach ([...$result['allowed_decision_classes'], ...$result['proof_refs']] as $value) {
            $this->assertIsString($value);
        }
    }
}
