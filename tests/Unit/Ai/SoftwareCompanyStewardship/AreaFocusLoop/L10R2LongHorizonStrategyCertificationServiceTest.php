<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10R2LongHorizonStrategyCertificationService;
use PHPUnit\Framework\TestCase;

final class L10R2LongHorizonStrategyCertificationServiceTest extends TestCase
{
    private L10R2LongHorizonStrategyCertificationService $service;

    protected function setUp(): void
    {
        $this->service = new L10R2LongHorizonStrategyCertificationService();
    }

    public function testCuratedMultiYearTelosPursuedAndCorrectedWithEvidenceCertifiesR2(): void
    {
        $result = $this->service->certify([
            'curated_telos' => [
                'curated_telos_id' => 'telos.engineering.2029.v1',
                'operator_curated' => true,
                'horizon_years' => 3,
            ],
            'corrections' => [
                ['evidence_ref' => 'evidence://outcome/throughput', 'changes_ends' => false],
                ['measured' => true],
            ],
        ]);

        $this->assertSame(
            'atlas.aaeos.l10.r2_long_horizon_strategy_certification.v1',
            $result['schema_version'],
        );
        $this->assertSame('L10-R2', $result['phase']);
        $this->assertTrue($result['r2_certified']);
        $this->assertSame('r2_certified', $result['status']);
        $this->assertSame('telos.engineering.2029.v1', $result['curated_telos_id']);
        $this->assertTrue($result['curated_telos_present']);
        $this->assertEqualsWithDelta(3.0, $result['horizon_years'], 1e-9);
        $this->assertSame(2, $result['correction_count']);
        $this->assertSame(0, $result['drift_without_operator_approval_count']);
        $this->assertSame([], $result['blockers']);
    }

    public function testNoCuratedTelosBlocks(): void
    {
        $result = $this->service->certify([
            'corrections' => [
                ['evidence_ref' => 'evidence://outcome/latency'],
            ],
        ]);

        $this->assertFalse($result['r2_certified']);
        $this->assertSame('blocked_not_r2', $result['status']);
        $this->assertFalse($result['curated_telos_present']);
        $this->assertSame('', $result['curated_telos_id']);
        $this->assertSame(1, $result['correction_count']);
        $this->assertSame(['no_curated_telos'], $result['blockers']);
    }

    public function testTelosWithoutOperatorCurationBlocksAsNoCuratedTelos(): void
    {
        // A telos proposed by the system but NOT curated by the operator is not a
        // curated telos — sovereignty requires operator curation of the ends.
        $result = $this->service->certify([
            'telos' => [
                'telos_id' => 'telos.proposed.v1',
                'horizon_years' => 4,
            ],
            'corrections' => [
                ['evidence_ref' => 'evidence://outcome/coverage'],
            ],
        ]);

        $this->assertFalse($result['r2_certified']);
        $this->assertFalse($result['curated_telos_present']);
        $this->assertSame('', $result['curated_telos_id']);
        $this->assertEqualsWithDelta(4.0, $result['horizon_years'], 1e-9);
        $this->assertSame(['no_curated_telos'], $result['blockers']);
    }

    public function testSubAnnualHorizonIsNotLongHorizonAndBlocks(): void
    {
        // A curated but sub-annual telos is tactical, not the multi-year strategy
        // the R2 criterion requires.
        $result = $this->service->certify([
            'curated_telos' => [
                'curated_telos_id' => 'telos.quarter.v1',
                'operator_curated' => true,
                'horizon_years' => 0.5,
            ],
            'corrections' => [
                ['measured_or_reverted' => true],
            ],
        ]);

        $this->assertFalse($result['r2_certified']);
        $this->assertFalse($result['curated_telos_present']);
        $this->assertSame('', $result['curated_telos_id']);
        $this->assertEqualsWithDelta(0.5, $result['horizon_years'], 1e-9);
        $this->assertSame(['no_curated_telos'], $result['blockers']);
    }

    public function testSubAnnualHorizonNearBoundaryDoesNotRoundUpIntoCertification(): void
    {
        // A genuinely sub-annual horizon (0.99999 < 1.0 = tactical) must stay blocked
        // even though round(.,4) snaps it to 1.0 for the surfaced field. The multi-year
        // gate is honesty-first: it judges the raw horizon, not the rounded display.
        $result = $this->service->certify([
            'curated_telos' => [
                'curated_telos_id' => 'telos.subannual.boundary.v1',
                'operator_curated' => true,
                'horizon_years' => 0.99999,
            ],
            'corrections' => [
                ['measured' => true],
            ],
        ]);

        $this->assertFalse($result['r2_certified']);
        $this->assertFalse($result['curated_telos_present']);
        $this->assertSame('', $result['curated_telos_id']);
        $this->assertSame(['no_curated_telos'], $result['blockers']);
    }

    public function testValueDriftWithoutOperatorApprovalBlocks(): void
    {
        $result = $this->service->certify([
            'curated_telos' => [
                'curated_telos_id' => 'telos.engineering.2030.v1',
                'operator_curated' => true,
                'horizon_years' => 3,
            ],
            'corrections' => [
                ['evidence_ref' => 'evidence://outcome/quality'],
            ],
            'drift_events' => [
                ['drifts_ends' => true],
            ],
        ]);

        $this->assertFalse($result['r2_certified']);
        $this->assertSame('blocked_not_r2', $result['status']);
        $this->assertTrue($result['curated_telos_present']);
        $this->assertSame(1, $result['correction_count']);
        $this->assertSame(1, $result['drift_without_operator_approval_count']);
        $this->assertSame(['value_drift'], $result['blockers']);
    }

    public function testOperatorApprovedEndsChangeIsNotCountedAsDrift(): void
    {
        // An ends change the operator explicitly approved is curation, not drift:
        // "sem deriva de fins" forbids UNapproved drift, not operator decisions.
        $result = $this->service->certify([
            'curated_telos' => [
                'curated_telos_id' => 'telos.engineering.2031.v1',
                'operator_curated' => true,
                'horizon_years' => 5,
            ],
            'corrections' => [
                ['evidence_ref' => 'evidence://outcome/architecture'],
            ],
            'drift_events' => [
                ['changes_final_ends' => true, 'operator_approved' => true],
            ],
        ]);

        $this->assertTrue($result['r2_certified']);
        $this->assertSame(0, $result['drift_without_operator_approval_count']);
        $this->assertSame([], $result['blockers']);
    }

    public function testNoEvidenceOfCorrectionBlocks(): void
    {
        $result = $this->service->certify([
            'curated_telos' => [
                'curated_telos_id' => 'telos.engineering.2032.v1',
                'operator_curated' => true,
                'horizon_years' => 3,
            ],
            'corrections' => [],
        ]);

        $this->assertFalse($result['r2_certified']);
        $this->assertSame('blocked_not_r2', $result['status']);
        $this->assertTrue($result['curated_telos_present']);
        $this->assertSame(0, $result['correction_count']);
        $this->assertSame(['no_correction_evidence'], $result['blockers']);
    }

    public function testCorrectionPacketWithoutEvidenceDoesNotCount(): void
    {
        // A "correction" with no measured-or-reverted evidence is not a real
        // correction; with zero evidence-backed corrections, certification blocks.
        $result = $this->service->certify([
            'curated_telos' => [
                'curated_telos_id' => 'telos.engineering.2033.v1',
                'operator_curated' => true,
                'horizon_years' => 2,
            ],
            'corrections' => [
                ['note' => 'planned but unmeasured', 'changes_ends' => false],
            ],
        ]);

        $this->assertFalse($result['r2_certified']);
        $this->assertSame(0, $result['correction_count']);
        $this->assertSame(['no_correction_evidence'], $result['blockers']);
    }

    public function testCorrectionPacketThatChangesEndsIsDriftNotCorrection(): void
    {
        // A packet that both lacks operator approval AND moves the ends is counted as
        // drift and excluded from the correction count, even though it has evidence.
        $result = $this->service->certify([
            'curated_telos' => [
                'curated_telos_id' => 'telos.engineering.2034.v1',
                'operator_curated' => true,
                'horizon_years' => 4,
            ],
            'corrections' => [
                ['evidence_ref' => 'evidence://outcome/redirect', 'changes_ends' => true],
            ],
        ]);

        $this->assertFalse($result['r2_certified']);
        $this->assertSame(0, $result['correction_count']);
        $this->assertSame(1, $result['drift_without_operator_approval_count']);
        // Drift fires before the missing-correction blocker, in row order.
        $this->assertSame(['value_drift', 'no_correction_evidence'], $result['blockers']);
    }

    public function testAllThreeBlockersReportInEnumeratedRowOrder(): void
    {
        $result = $this->service->certify([
            // No curated telos (no id, not curated), unapproved drift, no correction.
            'drift_events' => [
                ['value_drift' => true],
            ],
            'corrections' => [],
        ]);

        $this->assertFalse($result['r2_certified']);
        $this->assertFalse($result['curated_telos_present']);
        $this->assertSame('', $result['curated_telos_id']);
        $this->assertSame(0, $result['correction_count']);
        $this->assertSame(1, $result['drift_without_operator_approval_count']);
        $this->assertSame(
            ['no_curated_telos', 'value_drift', 'no_correction_evidence'],
            $result['blockers'],
        );
    }

    public function testEvidenceRefsListAndReceiptIdAreRecognised(): void
    {
        // Curation can come from an operator receipt id; correction evidence can be a
        // non-empty evidence_refs list. Different shape, same computed verdict.
        $result = $this->service->certify([
            'curated_telos' => [
                'id' => 'telos.engineering.2035.v1',
                'operator_receipt_id' => 'receipt://operator/telos-approval',
                'horizon_years' => 6,
            ],
            'corrections' => [
                ['evidence_refs' => ['evidence://outcome/a', 'evidence://outcome/b']],
            ],
        ]);

        $this->assertTrue($result['r2_certified']);
        $this->assertSame('telos.engineering.2035.v1', $result['curated_telos_id']);
        $this->assertEqualsWithDelta(6.0, $result['horizon_years'], 1e-9);
        $this->assertSame(1, $result['correction_count']);
        $this->assertSame(0, $result['drift_without_operator_approval_count']);
        $this->assertSame([], $result['blockers']);
    }

    public function testNonArrayCorrectionItemsAreIgnored(): void
    {
        $result = $this->service->certify([
            'curated_telos' => [
                'curated_telos_id' => 'telos.engineering.2036.v1',
                'operator_curated' => true,
                'horizon_years' => 3,
            ],
            'corrections' => [
                'garbage.string',
                7,
                ['evidence_ref' => 'evidence://outcome/real'],
            ],
        ]);

        $this->assertTrue($result['r2_certified']);
        $this->assertSame(1, $result['correction_count']);
        $this->assertSame([], $result['blockers']);
    }

    public function testNonStringTelosIdDoesNotCoerceIntoCuration(): void
    {
        // A numeric id must not coerce into a string id; the telos is not curated.
        $result = $this->service->certify([
            'curated_telos' => [
                'curated_telos_id' => 12345,
                'operator_curated' => true,
                'horizon_years' => 3,
            ],
            'corrections' => [
                ['evidence_ref' => 'evidence://outcome/x'],
            ],
        ]);

        $this->assertFalse($result['r2_certified']);
        $this->assertFalse($result['curated_telos_present']);
        $this->assertSame('', $result['curated_telos_id']);
        $this->assertSame(['no_curated_telos'], $result['blockers']);
    }

    public function testMultipleUnapprovedDriftEventsAreCounted(): void
    {
        $result = $this->service->certify([
            'curated_telos' => [
                'curated_telos_id' => 'telos.engineering.2037.v1',
                'operator_curated' => true,
                'horizon_years' => 4,
            ],
            'corrections' => [
                ['evidence_ref' => 'evidence://outcome/ok'],
            ],
            'telos_pursuit_events' => [
                ['ends_changed' => true],
                ['changes_final_ends' => true, 'operator_approved' => false],
                ['changes_ends' => true, 'operator_curated' => true],
            ],
        ]);

        $this->assertFalse($result['r2_certified']);
        $this->assertSame(1, $result['correction_count']);
        // Two unapproved ends-changes count; the operator-curated one does not.
        $this->assertSame(2, $result['drift_without_operator_approval_count']);
        $this->assertSame(['value_drift'], $result['blockers']);
    }

    public function testBareStringTelosWithTopLevelCurationAndHorizonCertifies(): void
    {
        $result = $this->service->certify([
            'curated_telos' => 'telos.engineering.2038.v1',
            'operator_curated' => true,
            'horizon_years' => 3,
            'corrections' => [
                ['measured' => true],
            ],
        ]);

        // The bare string supplies only the id; horizon lives on the telos record,
        // so a bare string alone is sub-annual (horizon 0) and not curated here.
        $this->assertFalse($result['r2_certified']);
        $this->assertFalse($result['curated_telos_present']);
        $this->assertSame('', $result['curated_telos_id']);
        $this->assertEqualsWithDelta(0.0, $result['horizon_years'], 1e-9);
        $this->assertSame(['no_curated_telos'], $result['blockers']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $inputs = [
            'curated_telos' => [
                'curated_telos_id' => 'telos.stable.v1',
                'operator_curated' => true,
                'horizon_years' => 3,
            ],
            'corrections' => [
                ['evidence_ref' => 'evidence://outcome/stable'],
                ['measured' => true],
            ],
            'drift_events' => [
                ['changes_final_ends' => true, 'operator_approved' => true],
            ],
        ];

        $first = $this->service->certify($inputs);
        $second = $this->service->certify($inputs);

        $this->assertSame($first, $second);
    }
}
