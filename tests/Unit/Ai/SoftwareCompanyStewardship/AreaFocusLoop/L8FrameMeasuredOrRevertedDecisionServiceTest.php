<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8FrameMeasuredOrRevertedDecisionService;
use PHPUnit\Framework\TestCase;

final class L8FrameMeasuredOrRevertedDecisionServiceTest extends TestCase
{
    private L8FrameMeasuredOrRevertedDecisionService $service;

    protected function setUp(): void
    {
        $this->service = new L8FrameMeasuredOrRevertedDecisionService();
    }

    public function testDecideReturnsKeepRevertArchiveReasonsAndEvidenceRefs(): void
    {
        $result = $this->service->decide([
            'evidence_refs' => ['replay:run-7', 'obra:42'],
            'regression_count' => 0,
            'dm_dt' => 0.18,
            'p5_divergence_detected' => false,
        ]);

        $this->assertSame('atlas.aaeos.l8.frame_measured_or_reverted_decision.v1', $result['schema_version']);
        $this->assertArrayHasKey('keep', $result);
        $this->assertArrayHasKey('revert_required', $result);
        $this->assertArrayHasKey('archive_required', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertArrayHasKey('evidence_refs', $result);

        $this->assertIsBool($result['keep']);
        $this->assertIsBool($result['revert_required']);
        $this->assertIsBool($result['archive_required']);
        $this->assertIsArray($result['reasons']);
        $this->assertSame(['replay:run-7', 'obra:42'], $result['evidence_refs']);
    }

    public function testRegressionCountAboveZeroReturnsRevertRequired(): void
    {
        $result = $this->service->decide([
            'evidence_refs' => ['replay:run-9'],
            'regression_count' => 3,
            'dm_dt' => 0.40,
            'p5_divergence_detected' => false,
        ]);

        $this->assertSame('revert_required', $result['decision']);
        $this->assertTrue($result['revert_required']);
        $this->assertFalse($result['keep']);
        $this->assertFalse($result['archive_required']);
        $this->assertSame(['regression_observed'], $result['reasons']);
        $this->assertSame(3, $result['regression_count']);
    }

    public function testPositiveDmDtWithoutP5DivergenceReturnsKeepCandidate(): void
    {
        $result = $this->service->decide([
            'evidence_refs' => ['replay:run-11', 'obra:101'],
            'regression_count' => 0,
            'dm_dt' => 0.07,
            'p5_divergence_detected' => false,
        ]);

        $this->assertSame('keep_candidate', $result['decision']);
        $this->assertTrue($result['keep']);
        $this->assertFalse($result['revert_required']);
        $this->assertFalse($result['archive_required']);
        $this->assertSame(['composite_lift_positive'], $result['reasons']);
        $this->assertEqualsWithDelta(0.07, $result['dm_dt'], 1e-9);
    }

    public function testMissingEvidenceReturnsUnknownBlocked(): void
    {
        $result = $this->service->decide([
            'regression_count' => 0,
            'dm_dt' => 0.50,
            'p5_divergence_detected' => false,
        ]);

        $this->assertSame('unknown_blocked', $result['decision']);
        $this->assertFalse($result['keep']);
        $this->assertFalse($result['revert_required']);
        $this->assertFalse($result['archive_required']);
        $this->assertSame(['missing_evidence'], $result['reasons']);
        $this->assertSame([], $result['evidence_refs']);
    }

    public function testEmptyEvidenceRefsListIsTreatedAsMissingEvidence(): void
    {
        $result = $this->service->decide([
            'evidence_refs' => [],
            'regression_count' => 0,
            'dm_dt' => 0.25,
        ]);

        $this->assertSame('unknown_blocked', $result['decision']);
        $this->assertSame(['missing_evidence'], $result['reasons']);
    }

    public function testPositiveDmDtWithP5DivergenceRevertsInsteadOfKeeping(): void
    {
        $result = $this->service->decide([
            'evidence_refs' => ['replay:run-13'],
            'regression_count' => 0,
            'dm_dt' => 0.30,
            'p5_divergence_detected' => true,
        ]);

        $this->assertSame('revert_required', $result['decision']);
        $this->assertTrue($result['revert_required']);
        $this->assertFalse($result['keep']);
        $this->assertSame(['p5_divergence_detected'], $result['reasons']);
        $this->assertTrue($result['p5_divergence_detected']);
    }

    public function testNonPositiveDmDtWithoutRegressionOrDivergenceArchives(): void
    {
        $result = $this->service->decide([
            'evidence_refs' => ['replay:run-21'],
            'regression_count' => 0,
            'dm_dt' => 0.0,
            'p5_divergence_detected' => false,
        ]);

        $this->assertSame('archive_required', $result['decision']);
        $this->assertTrue($result['archive_required']);
        $this->assertFalse($result['keep']);
        $this->assertFalse($result['revert_required']);
        $this->assertSame(['no_measured_lift'], $result['reasons']);
    }

    public function testNegativeDmDtArchivesWhenNoRegressionOrDivergence(): void
    {
        $result = $this->service->decide([
            'evidence_refs' => ['replay:run-22'],
            'regression_count' => 0,
            'dm_dt' => -0.12,
            'p5_divergence_detected' => false,
        ]);

        $this->assertSame('archive_required', $result['decision']);
        $this->assertTrue($result['archive_required']);
        $this->assertSame(['no_measured_lift'], $result['reasons']);
        $this->assertEqualsWithDelta(-0.12, $result['dm_dt'], 1e-9);
    }

    public function testRegressionTakesPriorityOverP5DivergenceAndPositiveLift(): void
    {
        $result = $this->service->decide([
            'evidence_refs' => ['replay:run-30'],
            'regression_count' => 1,
            'dm_dt' => 0.90,
            'p5_divergence_detected' => true,
        ]);

        $this->assertSame('revert_required', $result['decision']);
        $this->assertSame(['regression_observed'], $result['reasons']);
        $this->assertSame(1, $result['regression_count']);
    }

    public function testNegativeRegressionCountIsClampedToZero(): void
    {
        $result = $this->service->decide([
            'evidence_refs' => ['replay:run-31'],
            'regression_count' => -5,
            'dm_dt' => 0.10,
            'p5_divergence_detected' => false,
        ]);

        $this->assertSame(0, $result['regression_count']);
        $this->assertSame('keep_candidate', $result['decision']);
    }

    public function testRegressionCountReadFromNestedRegressionMetrics(): void
    {
        $result = $this->service->decide([
            'evidence_refs' => ['replay:run-32'],
            'regression_metrics' => ['regression_count' => 2],
            'dm_dt' => 0.45,
        ]);

        $this->assertSame(2, $result['regression_count']);
        $this->assertSame('revert_required', $result['decision']);
        $this->assertSame(['regression_observed'], $result['reasons']);
    }

    public function testP5DivergenceReadFromNestedSignalGamingFlag(): void
    {
        $result = $this->service->decide([
            'evidence_refs' => ['replay:run-33'],
            'regression_count' => 0,
            'dm_dt' => 0.22,
            'p5_divergence' => ['gaming_detected' => true],
        ]);

        $this->assertTrue($result['p5_divergence_detected']);
        $this->assertSame('revert_required', $result['decision']);
        $this->assertSame(['p5_divergence_detected'], $result['reasons']);
    }

    public function testEvidenceRefsAreNormalisedToStringList(): void
    {
        $result = $this->service->decide([
            'evidence_refs' => ['replay:run-40', 7, '', 'obra:9', null, false],
            'regression_count' => 0,
            'dm_dt' => 0.05,
            'p5_divergence_detected' => false,
        ]);

        $this->assertSame(['replay:run-40', 'obra:9'], $result['evidence_refs']);
        foreach ($result['evidence_refs'] as $ref) {
            $this->assertIsString($ref);
        }
    }

    public function testNumericStringSignalsAreCoerced(): void
    {
        $result = $this->service->decide([
            'evidence_refs' => ['replay:run-41'],
            'regression_count' => '0',
            'dm_dt' => '0.33',
            'p5_divergence_detected' => false,
        ]);

        $this->assertSame(0, $result['regression_count']);
        $this->assertEqualsWithDelta(0.33, $result['dm_dt'], 1e-9);
        $this->assertSame('keep_candidate', $result['decision']);
    }

    public function testExactlyOneOfKeepRevertArchiveIsTrueWhenEvidencePresent(): void
    {
        $cases = [
            ['evidence_refs' => ['e:1'], 'regression_count' => 0, 'dm_dt' => 0.5, 'p5_divergence_detected' => false],
            ['evidence_refs' => ['e:2'], 'regression_count' => 4, 'dm_dt' => 0.5, 'p5_divergence_detected' => false],
            ['evidence_refs' => ['e:3'], 'regression_count' => 0, 'dm_dt' => 0.0, 'p5_divergence_detected' => false],
            ['evidence_refs' => ['e:4'], 'regression_count' => 0, 'dm_dt' => 0.5, 'p5_divergence_detected' => true],
        ];

        foreach ($cases as $case) {
            $result = $this->service->decide($case);
            $trueCount = (int) $result['keep'] + (int) $result['revert_required'] + (int) $result['archive_required'];
            $this->assertSame(1, $trueCount);
        }
    }

    public function testDecisionIsDeterministicForIdenticalInput(): void
    {
        $result = [
            'evidence_refs' => ['replay:run-50', 'obra:7'],
            'regression_count' => 0,
            'dm_dt' => 0.14,
            'p5_divergence_detected' => false,
        ];

        $first = $this->service->decide($result);
        $second = $this->service->decide($result);

        $this->assertSame($first, $second);
    }
}
