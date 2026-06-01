<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\LocalAgentMemoryPromotionGateEvaluator;
use PHPUnit\Framework\TestCase;

final class LocalAgentMemoryPromotionGateEvaluatorTest extends TestCase
{
    private LocalAgentMemoryPromotionGateEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new LocalAgentMemoryPromotionGateEvaluator();
    }

    public function testEligibleClassifiedLineageAndCleanScanPermitsPromotion(): void
    {
        $result = $this->evaluator->evaluate([
            'memory_eligible' => true,
            'classification' => 'internal',
            'lineage' => ['source' => 'local_agent_run_42'],
            'confidence' => 0.91,
            'secret_scan' => ['passed' => true, 'findings' => 0],
        ]);

        $this->assertSame('atlas.local_agent.memory_promotion_gate.v1', $result['schema_version']);
        $this->assertSame('promote', $result['verdict']);
        $this->assertTrue($result['promote_allowed']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['required_evidence']);
        $this->assertSame(0.0, $result['confidence_deficit']);
    }

    public function testMemoryEligibleFalseBlocksPromotion(): void
    {
        $result = $this->evaluator->evaluate([
            'memory_eligible' => false,
            'classification' => 'internal',
            'lineage' => ['source' => 'local_agent_run_42'],
            'confidence' => 0.91,
            'secret_scan' => ['passed' => true, 'findings' => 0],
        ]);

        $this->assertSame('block', $result['verdict']);
        $this->assertFalse($result['promote_allowed']);
        $this->assertSame(['memory_not_eligible'], $result['blockers']);
        $this->assertContains('memory_eligibility_proof', $result['required_evidence']);
    }

    public function testMissingClassificationBlocksPromotion(): void
    {
        $result = $this->evaluator->evaluate([
            'memory_eligible' => true,
            'lineage' => ['source' => 'local_agent_run_42'],
            'confidence' => 0.91,
            'secret_scan' => ['passed' => true, 'findings' => 0],
        ]);

        $this->assertSame('block', $result['verdict']);
        $this->assertFalse($result['promote_allowed']);
        $this->assertSame(['classification_missing'], $result['blockers']);
        $this->assertContains('classification_label', $result['required_evidence']);
    }

    public function testFailedSecretScanBlocksPromotion(): void
    {
        $result = $this->evaluator->evaluate([
            'memory_eligible' => true,
            'classification' => 'internal',
            'lineage' => ['source' => 'local_agent_run_42'],
            'confidence' => 0.91,
            'secret_scan' => ['passed' => false, 'findings' => 2],
        ]);

        $this->assertSame('block', $result['verdict']);
        $this->assertFalse($result['promote_allowed']);
        $this->assertSame(['secret_scan_failed'], $result['blockers']);
        $this->assertContains('clean_secret_scan', $result['required_evidence']);
    }

    public function testConfidenceBelowThresholdBlocksWithNumericDeficit(): void
    {
        $result = $this->evaluator->evaluate([
            'memory_eligible' => true,
            'classification' => 'internal',
            'lineage' => ['source' => 'local_agent_run_42'],
            'confidence' => 0.55,
            'secret_scan' => ['passed' => true, 'findings' => 0],
        ]);

        $this->assertSame('block', $result['verdict']);
        $this->assertFalse($result['promote_allowed']);
        $this->assertSame(['confidence_below_threshold'], $result['blockers']);
        $this->assertSame(0.70, $result['confidence_threshold']);
        $this->assertSame(0.15, $result['confidence_deficit']);
    }

    public function testMultipleFailingSignalsAreReportedInRuleOrder(): void
    {
        $result = $this->evaluator->evaluate([
            'memory_eligible' => false,
            'confidence' => 0.40,
            'secret_scan' => ['passed' => false, 'findings' => 1],
        ]);

        $this->assertSame('block', $result['verdict']);
        $this->assertFalse($result['promote_allowed']);
        $this->assertSame(
            [
                'memory_not_eligible',
                'classification_missing',
                'lineage_missing',
                'confidence_below_threshold',
                'secret_scan_failed',
            ],
            $result['blockers'],
        );
        $this->assertSame(0.30, $result['confidence_deficit']);
    }

    public function testConfidenceExactlyAtThresholdHasNoDeficit(): void
    {
        $result = $this->evaluator->evaluate([
            'memory_eligible' => true,
            'classification' => 'public',
            'lineage' => 'doc://lineage/ref',
            'confidence' => 0.70,
            'secret_scan' => 'pass',
        ]);

        $this->assertSame(0.0, $result['confidence_deficit']);
        $this->assertNotContains('confidence_below_threshold', $result['blockers']);
        $this->assertTrue($result['promote_allowed']);
    }

    public function testIdenticalCandidateProducesIdenticalVerdict(): void
    {
        $candidate = [
            'memory_eligible' => true,
            'classification' => 'internal',
            'lineage' => ['source' => 'local_agent_run_42'],
            'confidence' => 0.88,
            'secret_scan' => ['passed' => true, 'findings' => 0],
        ];

        $first = $this->evaluator->evaluate($candidate);
        $second = $this->evaluator->evaluate($candidate);

        $this->assertSame($first, $second);
    }
}
