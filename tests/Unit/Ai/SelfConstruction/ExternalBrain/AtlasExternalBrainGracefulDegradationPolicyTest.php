<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGracefulDegradationPolicy;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainGracefulDegradationPolicyTest extends TestCase
{
    private AtlasExternalBrainGracefulDegradationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new AtlasExternalBrainGracefulDegradationPolicy();
    }

    // AC 2: degraded model capability increases scaffold and evidence requirements
    public function test_degraded_capability_raises_requirements(): void
    {
        $full = $this->policy->evaluate([
            'primary_provider_available' => true,
            'model_capability_score' => 1.0,
            'full_capability_score' => 1.0,
        ]);

        $degraded = $this->policy->evaluate([
            'primary_provider_available' => true,
            'model_capability_score' => 0.5,
            'full_capability_score' => 1.0,
        ]);

        $this->assertNotSame($full['scaffold_requirement'], $degraded['scaffold_requirement']);
        $this->assertNotSame($full['proof_requirement'], $degraded['proof_requirement']);
    }

    // AC 3: degradation never permits proxy tasks, vague acceptance or test-only packets
    public function test_degradation_prohibits_low_quality(): void
    {
        $result = $this->policy->evaluate([
            'primary_provider_available' => false,
            'fallback_provider_available' => true,
        ]);

        $this->assertContains('proxy_tasks', $result['prohibits']);
        $this->assertContains('vague_acceptance_criteria', $result['prohibits']);
        $this->assertContains('test_only_packets', $result['prohibits']);
    }

    // AC 4: provider outage recommends local/fallback before stopping
    public function test_provider_outage_recommends_fallback(): void
    {
        $result = $this->policy->evaluate([
            'primary_provider_available' => false,
            'fallback_provider_available' => true,
        ]);

        $this->assertSame('degraded', $result['level']);
        $this->assertStringContainsString('fallback', $result['recommendation']);
    }

    public function test_provider_outage_with_local_recommends_local(): void
    {
        $result = $this->policy->evaluate([
            'primary_provider_available' => false,
            'fallback_provider_available' => false,
            'local_client_available' => true,
        ]);

        $this->assertSame('local_fallback', $result['level']);
    }

    public function test_full_capability_standard_requirements(): void
    {
        $result = $this->policy->evaluate([
            'primary_provider_available' => true,
            'model_capability_score' => 1.0,
            'full_capability_score' => 1.0,
        ]);

        $this->assertSame('full_capability', $result['level']);
        $this->assertSame('standard_scaffold', $result['scaffold_requirement']);
    }

    public function test_no_fallback_at_all_pauses_origination(): void
    {
        $result = $this->policy->evaluate([
            'primary_provider_available' => false,
            'fallback_provider_available' => false,
            'local_client_available' => false,
        ]);

        $this->assertStringContainsString('pause', $result['recommendation']);
    }
}
