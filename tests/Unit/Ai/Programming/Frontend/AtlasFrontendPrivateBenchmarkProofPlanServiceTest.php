<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendPrivateBenchmarkProofPlanService;
use Tests\TestCase;

class AtlasFrontendPrivateBenchmarkProofPlanServiceTest extends TestCase
{
    public function test_private_benchmark_plan_wraps_replay_proof_without_public_claims(): void
    {
        $payload = app(AtlasFrontendPrivateBenchmarkProofPlanService::class)->plan();

        $this->assertSame(AtlasFrontendPrivateBenchmarkProofPlanService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('private_competitive_benchmark_proof_and_improvement_loop', $payload['plan_type']);
        $this->assertContains($payload['status'], ['ready_for_private_execution', 'blocked']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.private_benchmark_for_internal_improvement_only'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.public_superiority_claims_disabled'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.may_claim_more_complete_than_impeccable'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.may_claim_more_complete_than_claude_design_plugin'));
        $this->assertSame('legacy_public_proof_plan', data_get($payload, 'legacy_runtime.runtime_alias'));
        $this->assertTrue((bool) data_get($payload, 'legacy_runtime.used_for_private_benchmark_projection_only'));
        $this->assertNotEmpty($payload['private_improvement_queue']);
        $this->assertStringNotContainsString('world_best', implode(' ', data_get($payload, 'warnings', [])));
        $this->assertStringNotContainsString('world_best', implode(' ', collect(data_get($payload, 'private_improvement_queue', []))->pluck('id')->all()));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['private_benchmark_plan_hash']);
    }
}
