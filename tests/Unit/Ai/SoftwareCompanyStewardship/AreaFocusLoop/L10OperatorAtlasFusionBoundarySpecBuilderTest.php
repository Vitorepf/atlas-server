<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10OperatorAtlasFusionBoundarySpecBuilder;
use PHPUnit\Framework\TestCase;

final class L10OperatorAtlasFusionBoundarySpecBuilderTest extends TestCase
{
    private L10OperatorAtlasFusionBoundarySpecBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new L10OperatorAtlasFusionBoundarySpecBuilder();
    }

    /**
     * @return array{q1: array<string, mixed>, sovereignty: array<string, mixed>}
     */
    private function validInputs(): array
    {
        return [
            'q1' => [
                'q1_certified' => true,
                'mode' => 'amplify',
                'replaces_operator_agency' => false,
                'operator_detach_available' => true,
                'operator_override_available' => true,
            ],
            'sovereignty' => [
                'ends_owned_by_operator' => true,
                'operator_id' => 'vitor',
                'audit_channels' => ['decision_receipt_ledger'],
            ],
        ];
    }

    public function testValidFusionBoundaryPreservesAgencyAndIsReversible(): void
    {
        $inputs = $this->validInputs();

        $result = $this->builder->build($inputs['q1'], $inputs['sovereignty']);

        $this->assertSame('atlas.aaeos.l10.fusion_boundary_spec.v1', $result['schema_version']);
        $this->assertSame('fusion_boundary:vitor', $result['fusion_boundary_id']);
        $this->assertTrue($result['reversible']);
        $this->assertTrue($result['operator_agency_preserved']);
        $this->assertSame([], $result['blockers']);
    }

    public function testAuditChannelsAlwaysIncludeLoadBearingReversibilitySurfaces(): void
    {
        $inputs = $this->validInputs();

        $result = $this->builder->build($inputs['q1'], $inputs['sovereignty']);

        // Baseline channels are always present, operator-declared channel is appended and de-duplicated.
        $this->assertSame(
            [
                'operator_override',
                'reversible_detach',
                'sovereignty_of_ends',
                'decision_receipt_ledger',
            ],
            $result['audit_channels'],
        );

        // Honour list<string>: sequential int keys from 0, every value a string.
        $this->assertSame(
            range(0, count($result['audit_channels']) - 1),
            array_keys($result['audit_channels']),
        );
        foreach ($result['audit_channels'] as $channel) {
            $this->assertIsString($channel);
        }
    }

    public function testWhitespaceOnlyDeclaredChannelsDoNotLeakIntoTheCleanList(): void
    {
        // The audit_channels contract is a clean list<string>: blank/whitespace-only
        // declared channels are not real channels and must not leak into the output,
        // and a surrounding-whitespace channel is normalised (trimmed) before de-dup.
        $inputs = $this->validInputs();
        $sovereignty = $inputs['sovereignty'];
        $sovereignty['audit_channels'] = ['   ', "\t", "\n", ' decision_receipt_ledger ', 'extra_channel'];

        $result = $this->builder->build($inputs['q1'], $sovereignty);

        $this->assertSame(
            [
                'operator_override',
                'reversible_detach',
                'sovereignty_of_ends',
                'decision_receipt_ledger',
                'extra_channel',
            ],
            $result['audit_channels'],
        );

        $this->assertSame(
            range(0, count($result['audit_channels']) - 1),
            array_keys($result['audit_channels']),
        );
        foreach ($result['audit_channels'] as $channel) {
            $this->assertNotSame('', trim($channel));
        }
    }

    public function testMissingSovereigntyBlocks(): void
    {
        $inputs = $this->validInputs();

        $result = $this->builder->build($inputs['q1'], [
            'operator_id' => 'vitor',
        ]);

        $this->assertContains('sovereignty_evidence_missing', $result['blockers']);
        $this->assertFalse($result['operator_agency_preserved']);
        $this->assertFalse($result['reversible']);
    }

    public function testReplacementOfOperatorAgencyRejects(): void
    {
        $inputs = $this->validInputs();
        $q1 = $inputs['q1'];
        $q1['mode'] = 'replace';
        $q1['replaces_operator_agency'] = true;

        $result = $this->builder->build($q1, $inputs['sovereignty']);

        $this->assertContains('replacement_of_operator_agency_rejected', $result['blockers']);
        $this->assertFalse($result['operator_agency_preserved']);
        $this->assertFalse($result['reversible']);
    }

    public function testRemovingDetachOrOverrideRejectsAsAgencyReplacement(): void
    {
        $inputs = $this->validInputs();
        $q1 = $inputs['q1'];
        $q1['operator_detach_available'] = false;
        $q1['operator_override_available'] = false;

        $result = $this->builder->build($q1, $inputs['sovereignty']);

        $this->assertContains('replacement_of_operator_agency_rejected', $result['blockers']);
        $this->assertFalse($result['operator_agency_preserved']);
    }

    public function testSystemAsSourceOfEndsRejectsAsAgencyReplacement(): void
    {
        $inputs = $this->validInputs();
        $sovereignty = $inputs['sovereignty'];
        $sovereignty['system_as_source_of_ends'] = true;

        $result = $this->builder->build($inputs['q1'], $sovereignty);

        $this->assertContains('replacement_of_operator_agency_rejected', $result['blockers']);
        $this->assertFalse($result['operator_agency_preserved']);
    }

    public function testNoRuntimeCouplingOccurs(): void
    {
        $inputs = $this->validInputs();

        $result = $this->builder->build($inputs['q1'], $inputs['sovereignty']);

        // Design-only spec: it never couples to a runtime, even when the boundary is fully valid.
        $this->assertFalse($result['runtime_coupling']);
        $this->assertArrayNotHasKey('coupling_active', $result);
        $this->assertArrayNotHasKey('session_activated', $result);
    }

    public function testR4BoundaryPreservesAgencyBeforeAnyFusionClaim(): void
    {
        // DoD: agency preservation is a precondition; without it the boundary is neither
        // reversible nor agency-preserving and a blocker is raised, so no fusion claim can stand.
        $inputs = $this->validInputs();
        $q1 = $inputs['q1'];
        $q1['mode'] = 'replace';

        $rejected = $this->builder->build($q1, $inputs['sovereignty']);
        $this->assertFalse($rejected['operator_agency_preserved']);
        $this->assertFalse($rejected['reversible']);
        $this->assertNotSame([], $rejected['blockers']);

        $preserved = $this->builder->build($inputs['q1'], $inputs['sovereignty']);
        $this->assertTrue($preserved['operator_agency_preserved']);
        $this->assertTrue($preserved['reversible']);
    }

    public function testBoundaryIdDerivesFromOperatorFingerprintAndGeneralises(): void
    {
        // Anti-scaffold: a different operator_id must yield a different, computed id.
        $inputs = $this->validInputs();
        $sovereignty = $inputs['sovereignty'];
        $sovereignty['operator_id'] = 'operator-99';

        $result = $this->builder->build($inputs['q1'], $sovereignty);

        $this->assertSame('fusion_boundary:operator-99', $result['fusion_boundary_id']);
    }

    public function testMultipleBlockersAccumulateInOrder(): void
    {
        $q1 = [
            'mode' => 'replace',
            // no q1 precursor evidence either
        ];

        $result = $this->builder->build($q1, []);

        $this->assertSame(
            [
                'sovereignty_evidence_missing',
                'replacement_of_operator_agency_rejected',
                'q1_amplification_precursor_missing',
            ],
            $result['blockers'],
        );
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $inputs = $this->validInputs();

        $first = $this->builder->build($inputs['q1'], $inputs['sovereignty']);
        $second = $this->builder->build($inputs['q1'], $inputs['sovereignty']);

        $this->assertSame($first, $second);
    }

    public function testSchemaVersionIsStableEvenWhenBoundaryIsRejected(): void
    {
        // The schema_version contract holds on every path, not only the happy path.
        $rejected = $this->builder->build([], []);

        $this->assertSame('atlas.aaeos.l10.fusion_boundary_spec.v1', $rejected['schema_version']);
        $this->assertNotSame([], $rejected['blockers']);
    }

    public function testRuntimeCouplingIsUnconditionallyFalseEvenWhenRejected(): void
    {
        // "no runtime coupling occurs" is unconditional: a rejected boundary must never
        // flip runtime_coupling on, and must never leak an activation field.
        $inputs = $this->validInputs();
        $q1 = $inputs['q1'];
        $q1['mode'] = 'replace';

        $rejected = $this->builder->build($q1, $inputs['sovereignty']);

        $this->assertContains('replacement_of_operator_agency_rejected', $rejected['blockers']);
        $this->assertFalse($rejected['runtime_coupling']);
        $this->assertArrayNotHasKey('coupling_active', $rejected);
        $this->assertArrayNotHasKey('session_activated', $rejected);
    }
}
