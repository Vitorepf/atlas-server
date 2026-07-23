<?php

namespace Tests\Feature\Ai\Learning;

use App\Services\Ai\Policy\AtlasDomainProfileRegistry;
use App\Services\Ai\Domain\AtlasLearningOrchestrator;
use Tests\TestCase;

class LearningFailureReviewFlowIntegrationTest extends TestCase
{
    public function test_learning_failure_review_is_registered_in_domain_and_orchestrator(): void
    {
        $this->assertContains('learning.failure_review', app(AtlasLearningOrchestrator::class)->supportedFlows());

        $receipt = app(AtlasDomainProfileRegistry::class)->resolve('learning.failure_review');

        $this->assertSame('learning.failure_review', $receipt['flow_id']);
        $this->assertSame('LearningFailureReviewRuntime', data_get($receipt, 'flow_profile.runtime'));
        $this->assertContains('failure_signature_classified', data_get($receipt, 'flow_profile.gate_policy.required'));
    }
}
