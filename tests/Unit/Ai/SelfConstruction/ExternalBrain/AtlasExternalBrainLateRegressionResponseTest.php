<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\Compounding\CausalLearningPromotion;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLateRegressionResponse;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainLateRegressionResponseTest extends TestCase
{
    public function test_late_regression_requests_rollback_negative_memory_repair_and_rivals_review(): void
    {
        $promotion = new CausalLearningPromotion(
            status: 'promoted', candidateHash: 'candidate-1', decisionHash: 'decision-1',
            scope: 'routing:checkout', activeVersion: 'route-v2', previousVersion: 'route-v1',
            rollbackVersion: 'route-v1', expiresAt: '2026-12-31T00:00:00Z',
            observationSchedule: ['0h' => 'capture', '24h' => 'check', '7d' => 'check', '30d' => 'check', '90d' => 'check', '150d' => 'check'],
            reason: 'causal_evidence_admitted',
        );

        $result = (new AtlasExternalBrainLateRegressionResponse)->respond($promotion, [
            'reason' => 'late_failure_rate_regression',
            'observed_outcome' => 'failure',
            'evidence_refs' => ['outcome:late-1', 'receipt:observe-1'],
        ]);

        $this->assertSame(AtlasExternalBrainLateRegressionResponse::SCHEMA, $result['schema']);
        $this->assertSame('route-v1', $result['route_rollback']['to_version']);
        $this->assertSame('negative', $result['negative_outcome_memory']['status']);
        $this->assertSame('task_fabric_proposal', $result['repair_proposal']['destination']);
        $this->assertTrue($result['rivals_revocation_request']['required']);
        $this->assertFalse($result['rivals_revocation_request']['claim_mutated_here']);
    }

    public function test_response_is_deterministic_and_filters_blank_evidence_refs(): void
    {
        $promotion = new CausalLearningPromotion(
            status: 'promoted', candidateHash: 'candidate-1', decisionHash: 'decision-1',
            scope: 'routing:checkout', activeVersion: 'route-v2', previousVersion: 'route-v1',
            rollbackVersion: 'route-v1', expiresAt: '2026-12-31T00:00:00Z',
            observationSchedule: ['0h' => 'capture', '24h' => 'check', '7d' => 'check', '30d' => 'check', '90d' => 'check', '150d' => 'check'],
            reason: 'causal_evidence_admitted',
        );
        $service = new AtlasExternalBrainLateRegressionResponse;
        $input = ['reason' => '', 'evidence_refs' => ['', 'outcome:1']];

        $this->assertSame($service->respond($promotion, $input), $service->respond($promotion, $input));
        $this->assertSame(['outcome:1'], $service->respond($promotion, $input)['negative_outcome_memory']['evidence_refs']);
    }
}
