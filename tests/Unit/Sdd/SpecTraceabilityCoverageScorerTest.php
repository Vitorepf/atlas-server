<?php

declare(strict_types=1);

namespace Tests\Unit\Sdd;

use App\Services\Ai\Programming\Sdd\SpecTraceabilityCoverageScorer;
use PHPUnit\Framework\TestCase;

final class SpecTraceabilityCoverageScorerTest extends TestCase
{
    private SpecTraceabilityCoverageScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new SpecTraceabilityCoverageScorer();
    }

    public function testSchemaVersionIsCanonical(): void
    {
        $result = $this->scorer->score([]);

        $this->assertSame('atlas.sdd.spec_traceability_coverage.v1', $result['schema_version']);
    }

    public function testThreeFullyTracedPlusOneCriticalWithoutEvidenceBlocksPromotion(): void
    {
        $result = $this->scorer->score([
            $this->fullyTraced('R1', critical: false),
            $this->fullyTraced('R2', critical: false),
            $this->fullyTraced('R3', critical: false),
            [
                'requirement_id' => 'R4',
                'critical' => true,
                'has_acceptance' => true,
                'has_task' => true,
                'has_test' => true,
                'has_evidence' => false,
            ],
        ]);

        $this->assertSame(4, $result['total']);
        $this->assertSame(3, $result['fully_covered']);
        $this->assertSame(0.75, $result['coverage_ratio']);
        $this->assertSame(1, $result['critical_total']);
        $this->assertSame(0, $result['critical_with_evidence']);

        $gapRow = $result['per_requirement'][3];
        $this->assertSame('R4', $gapRow['requirement_id']);
        $this->assertTrue($gapRow['critical']);
        $this->assertSame(3, $gapRow['chain_depth']);
        $this->assertFalse($gapRow['fully_covered']);
        $this->assertSame(['test_without_evidence'], $gapRow['broken_links']);

        $this->assertSame(1, $result['broken_links_summary']['test_without_evidence']);
        $this->assertSame(0, $result['broken_links_summary']['requirement_without_acceptance']);
        $this->assertSame(0, $result['broken_links_summary']['acceptance_without_test']);

        $this->assertFalse($result['enterprise_complete']);
    }

    public function testAllCriticalCarryingEvidenceMakesPromotionEnterpriseComplete(): void
    {
        $result = $this->scorer->score([
            $this->fullyTraced('R1', critical: true),
            $this->fullyTraced('R2', critical: true),
            $this->fullyTraced('R3', critical: false),
        ]);

        $this->assertSame(2, $result['critical_total']);
        $this->assertSame(3, $result['fully_covered']);
        $this->assertSame(2, $result['critical_with_evidence']);
        $this->assertSame(1.0, $result['coverage_ratio']);
        $this->assertTrue($result['enterprise_complete']);
    }

    public function testEmptyInputYieldsZeroRatioAndIncompletePromotion(): void
    {
        $result = $this->scorer->score([]);

        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['fully_covered']);
        $this->assertSame(0.0, $result['coverage_ratio']);
        $this->assertSame(0, $result['critical_total']);
        $this->assertFalse($result['enterprise_complete']);
        $this->assertSame([], $result['per_requirement']);
    }

    public function testRowWithoutAcceptanceBreaksAtRootRegardlessOfDownstreamFlags(): void
    {
        $result = $this->scorer->score([
            [
                'requirement_id' => 'R9',
                'critical' => false,
                'has_acceptance' => false,
                'has_task' => true,
                'has_test' => true,
                'has_evidence' => true,
            ],
        ]);

        $row = $result['per_requirement'][0];
        $this->assertContains('requirement_without_acceptance', $row['broken_links']);
        $this->assertSame(0, $row['chain_depth']);
        $this->assertFalse($row['fully_covered']);
        $this->assertSame(1, $result['broken_links_summary']['requirement_without_acceptance']);
    }

    public function testChainDepthStopsAtFirstGapInChainOrder(): void
    {
        $this->assertSame(0, $this->scorer->chainDepth([
            'has_acceptance' => false,
            'has_task' => true,
            'has_test' => true,
            'has_evidence' => true,
        ]));

        $this->assertSame(1, $this->scorer->chainDepth([
            'has_acceptance' => true,
            'has_task' => false,
            'has_test' => true,
            'has_evidence' => true,
        ]));

        $this->assertSame(2, $this->scorer->chainDepth([
            'has_acceptance' => true,
            'has_task' => true,
            'has_test' => false,
            'has_evidence' => true,
        ]));

        $this->assertSame(3, $this->scorer->chainDepth([
            'has_acceptance' => true,
            'has_task' => true,
            'has_test' => true,
            'has_evidence' => false,
        ]));

        $this->assertSame(4, $this->scorer->chainDepth([
            'has_acceptance' => true,
            'has_task' => true,
            'has_test' => true,
            'has_evidence' => true,
        ]));
    }

    public function testBrokenLinksForReportsEachIndependentGap(): void
    {
        $this->assertSame(
            ['acceptance_without_test'],
            $this->scorer->brokenLinksFor([
                'has_acceptance' => true,
                'has_task' => true,
                'has_test' => false,
                'has_evidence' => false,
            ]),
        );

        $this->assertSame(
            ['test_without_evidence'],
            $this->scorer->brokenLinksFor([
                'has_acceptance' => true,
                'has_task' => true,
                'has_test' => true,
                'has_evidence' => false,
            ]),
        );

        $this->assertSame(
            [],
            $this->scorer->brokenLinksFor([
                'has_acceptance' => true,
                'has_task' => true,
                'has_test' => true,
                'has_evidence' => true,
            ]),
        );
    }

    public function testAnswersToRequiredQuestionsAggregateOverAllRows(): void
    {
        $answers = $this->scorer->answersToRequiredQuestions([
            [
                'has_acceptance' => true,
                'has_task' => true,
                'has_test' => true,
                'has_evidence' => false,
            ],
            [
                'has_acceptance' => false,
                'has_task' => true,
                'has_test' => false,
                'has_evidence' => false,
            ],
        ]);

        $this->assertFalse($answers['requirement_has_proving_test']);
        $this->assertTrue($answers['acceptance_has_implementing_file_or_task']);
        $this->assertFalse($answers['evidence_proves_gate']);
        $this->assertTrue($answers['code_changed_without_spec']);
        $this->assertFalse($answers['spec_changed_without_test']);
        $this->assertTrue($answers['spec_changed_without_evidence']);
    }

    public function testRequiredQuestionsAllCleanWhenChainIsComplete(): void
    {
        $result = $this->scorer->score([
            $this->fullyTraced('R1', critical: true),
        ]);

        $questions = $result['required_questions'];
        $this->assertTrue($questions['requirement_has_proving_test']);
        $this->assertTrue($questions['acceptance_has_implementing_file_or_task']);
        $this->assertTrue($questions['evidence_proves_gate']);
        $this->assertFalse($questions['code_changed_without_spec']);
        $this->assertFalse($questions['spec_changed_without_test']);
        $this->assertFalse($questions['spec_changed_without_evidence']);
    }

    public function testCoverageRatioRoundsToFourDecimals(): void
    {
        $result = $this->scorer->score([
            $this->fullyTraced('R1', critical: false),
            $this->partial('R2'),
            $this->partial('R3'),
        ]);

        // 1 of 3 fully covered => round(0.3333..., 4) === 0.3333
        $this->assertSame(0.3333, $result['coverage_ratio']);
        $this->assertSame(1, $result['fully_covered']);
    }

    public function testDeterministicForIdenticalInput(): void
    {
        $rows = [
            $this->fullyTraced('R1', critical: true),
            $this->partial('R2'),
        ];

        $this->assertSame(
            $this->scorer->score($rows),
            $this->scorer->score($rows),
        );
    }

    /**
     * @return array{requirement_id: string, critical: bool, has_acceptance: bool, has_task: bool, has_test: bool, has_evidence: bool}
     */
    private function fullyTraced(string $id, bool $critical): array
    {
        return [
            'requirement_id' => $id,
            'critical' => $critical,
            'has_acceptance' => true,
            'has_task' => true,
            'has_test' => true,
            'has_evidence' => true,
        ];
    }

    /**
     * @return array{requirement_id: string, critical: bool, has_acceptance: bool, has_task: bool, has_test: bool, has_evidence: bool}
     */
    private function partial(string $id): array
    {
        return [
            'requirement_id' => $id,
            'critical' => false,
            'has_acceptance' => true,
            'has_task' => true,
            'has_test' => false,
            'has_evidence' => false,
        ];
    }
}
