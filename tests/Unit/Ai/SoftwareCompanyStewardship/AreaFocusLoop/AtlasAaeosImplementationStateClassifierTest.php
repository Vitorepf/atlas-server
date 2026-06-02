<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtlasAaeosImplementationStateClassifier;
use PHPUnit\Framework\TestCase;

final class AtlasAaeosImplementationStateClassifierTest extends TestCase
{
    public function testSchemaVersionAndCanonicalEvidencePointers(): void
    {
        $result = AtlasAaeosImplementationStateClassifier::fromArray([])->toArray();

        $this->assertSame(
            'atlas.software_company_stewardship.aaeos_implementation_state.v1',
            $result['schema_version'],
        );
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-agentic-engineering-os-implementation-reality.md',
            $result['implementation_reality_canonical'],
        );
        $this->assertSame(
            'atlas-agentic-engineering-os-implementation-reality.md:157',
            $result['evidence_ref'],
        );
    }

    public function testFullEvidenceWithNoBlockerIsRuntimeVerifiedAndNotLoopAdmissible(): void
    {
        $outputs = AtlasAaeosImplementationStateClassifier::fromArray([
            'has_code_path' => true,
            'has_command_or_route' => true,
            'has_green_test' => true,
            'has_receipt_or_ap_or_ledger' => true,
            'has_open_blocker' => false,
        ])->toArray()['outputs'];

        $this->assertSame('runtime_verified', $outputs['computed_state']);
        $this->assertFalse($outputs['loop_admissible']);
        $this->assertSame('admit_in_scope', $outputs['loop_verdict']);
        $this->assertTrue($outputs['can_be_called_ready']);
        $this->assertFalse($outputs['declared_vs_computed_drift']);
    }

    public function testPartialRuntimeWithOpenBlockerIsImplementedPartialAndLoopAdmissible(): void
    {
        $outputs = AtlasAaeosImplementationStateClassifier::fromArray([
            'has_code_path' => true,
            'has_green_test' => true,
            'has_open_blocker' => true,
        ])->toArray()['outputs'];

        $this->assertSame('implemented_partial', $outputs['computed_state']);
        $this->assertTrue($outputs['loop_admissible']);
        $this->assertSame('admit_with_caveats', $outputs['loop_verdict']);
        $this->assertFalse($outputs['can_be_called_ready']);
    }

    public function testDeclaredRuntimeVerifiedWithNoEvidenceDoesNotComputeRuntimeVerifiedAndFlagsDrift(): void
    {
        $outputs = AtlasAaeosImplementationStateClassifier::fromArray([
            'has_code_path' => false,
            'has_command_or_route' => false,
            'has_green_test' => false,
            'has_receipt_or_ap_or_ledger' => false,
            'has_open_blocker' => false,
            'declared_state' => 'runtime_verified',
        ])->toArray()['outputs'];

        $this->assertNotSame('runtime_verified', $outputs['computed_state']);
        $this->assertContains($outputs['computed_state'], ['spec_only', 'north_star']);
        $this->assertSame('spec_only', $outputs['computed_state']);
        $this->assertFalse($outputs['loop_admissible']);
        $this->assertTrue($outputs['declared_vs_computed_drift']);
    }

    public function testRuntimeProofWithOpenBlockerDegradesToImplementedPartial(): void
    {
        // Every proof signal present, but an open blocker forbids runtime_verified.
        $outputs = AtlasAaeosImplementationStateClassifier::fromArray([
            'has_code_path' => true,
            'has_command_or_route' => true,
            'has_green_test' => true,
            'has_receipt_or_ap_or_ledger' => true,
            'has_open_blocker' => true,
        ])->toArray()['outputs'];

        $this->assertSame('implemented_partial', $outputs['computed_state']);
        $this->assertTrue($outputs['loop_admissible']);
        $this->assertSame('admit_with_caveats', $outputs['loop_verdict']);
        $this->assertSame('partial_runtime_open_blocker', $outputs['reason_code']);
    }

    public function testIncompleteProofWithoutBlockerIsImplementedPartial(): void
    {
        // Code + command exist but no green test and no receipt: runtime exists with gaps.
        $outputs = AtlasAaeosImplementationStateClassifier::fromArray([
            'has_code_path' => true,
            'has_command_or_route' => true,
            'has_green_test' => false,
            'has_receipt_or_ap_or_ledger' => false,
            'has_open_blocker' => false,
        ])->toArray()['outputs'];

        $this->assertSame('implemented_partial', $outputs['computed_state']);
        $this->assertTrue($outputs['loop_admissible']);
        $this->assertSame('partial_runtime_incomplete_proof', $outputs['reason_code']);
    }

    public function testSingleReceiptSignalAloneIsImplementedPartial(): void
    {
        // Only a receipt/AP/ledger signal: still some runtime evidence, not spec_only.
        $outputs = AtlasAaeosImplementationStateClassifier::fromArray([
            'has_receipt_or_ap_or_ledger' => true,
        ])->toArray()['outputs'];

        $this->assertSame('implemented_partial', $outputs['computed_state']);
        $this->assertTrue($outputs['loop_admissible']);
    }

    public function testNoEvidenceWithoutDeclarationIsSpecOnlyAndRejected(): void
    {
        $outputs = AtlasAaeosImplementationStateClassifier::fromArray([])->toArray()['outputs'];

        $this->assertSame('spec_only', $outputs['computed_state']);
        $this->assertFalse($outputs['loop_admissible']);
        $this->assertFalse($outputs['can_be_called_ready']);
        $this->assertSame('reject', $outputs['loop_verdict']);
        $this->assertSame('no_runtime_spec_governs_future', $outputs['reason_code']);
        $this->assertFalse($outputs['declared_vs_computed_drift']);
    }

    public function testNorthStarDeclarationWithNoEvidenceComputesNorthStar(): void
    {
        $outputs = AtlasAaeosImplementationStateClassifier::fromArray([
            'declared_state' => 'north_star',
        ])->toArray()['outputs'];

        $this->assertSame('north_star', $outputs['computed_state']);
        $this->assertFalse($outputs['loop_admissible']);
        $this->assertSame('reject', $outputs['loop_verdict']);
        $this->assertSame('no_runtime_strategic_direction', $outputs['reason_code']);
        $this->assertFalse($outputs['declared_vs_computed_drift']);
    }

    public function testDeclaredStateMatchingComputedStateHasNoDrift(): void
    {
        $outputs = AtlasAaeosImplementationStateClassifier::fromArray([
            'has_code_path' => true,
            'has_command_or_route' => true,
            'has_green_test' => true,
            'has_receipt_or_ap_or_ledger' => true,
            'has_open_blocker' => false,
            'declared_state' => 'runtime_verified',
        ])->toArray()['outputs'];

        $this->assertSame('runtime_verified', $outputs['computed_state']);
        $this->assertFalse($outputs['declared_vs_computed_drift']);
    }

    public function testInputsAreEchoedFromSignals(): void
    {
        $inputs = AtlasAaeosImplementationStateClassifier::fromArray([
            'has_code_path' => true,
            'has_command_or_route' => false,
            'has_green_test' => true,
            'has_receipt_or_ap_or_ledger' => false,
            'has_open_blocker' => true,
            'declared_state' => 'implemented_partial',
        ])->toArray()['inputs'];

        $this->assertTrue($inputs['has_code_path']);
        $this->assertFalse($inputs['has_command_or_route']);
        $this->assertTrue($inputs['has_green_test']);
        $this->assertFalse($inputs['has_receipt_or_ap_or_ledger']);
        $this->assertTrue($inputs['has_open_blocker']);
        $this->assertSame('implemented_partial', $inputs['declared_state']);
    }

    public function testIdenticalSignalsAreDeterministic(): void
    {
        $signals = [
            'has_code_path' => true,
            'has_command_or_route' => true,
            'has_green_test' => false,
            'has_receipt_or_ap_or_ledger' => true,
            'has_open_blocker' => true,
            'declared_state' => 'runtime_verified',
        ];

        $first = AtlasAaeosImplementationStateClassifier::fromArray($signals)->toArray();
        $second = AtlasAaeosImplementationStateClassifier::fromArray($signals)->toArray();

        $this->assertSame($first, $second);
    }
}
