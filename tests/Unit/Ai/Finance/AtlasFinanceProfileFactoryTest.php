<?php

namespace Tests\Unit\Ai\Finance;

use App\Services\Ai\Finance\AtlasFinanceDomainContract;
use App\Services\Ai\Finance\AtlasFinanceProfileFactory;
use Tests\TestCase;

class AtlasFinanceProfileFactoryTest extends TestCase
{
    public function test_domain_profile_is_enterprise_finance_contract(): void
    {
        $profile = app(AtlasFinanceProfileFactory::class)->domainProfile(now());

        $this->assertSame('finance', $profile['id']);
        $this->assertSame('finance.market_research', $profile['default_flow']);
        $this->assertSame('AtlasFinanceOrchestrator', $profile['orchestrator']);
        $this->assertSame('low', $profile['autonomy_default']);
        $this->assertFalse($profile['background_allowed']);
        $this->assertSame('analysis_review_only', data_get($profile, 'gate_policy.autonomy_ceiling'));
        $this->assertFalse((bool) data_get($profile, 'tool_policy.broker_api_access'));
        $this->assertFalse((bool) data_get($profile, 'tool_policy.order_entry'));
    }

    public function test_flow_profiles_match_domain_contract(): void
    {
        $contract = app(AtlasFinanceDomainContract::class);
        $profiles = app(AtlasFinanceProfileFactory::class)->flowProfiles();

        $this->assertSame(array_keys($contract->flowDefinitions()), collect($profiles)->pluck('id')->all());

        foreach ($profiles as $profile) {
            $definition = $contract->flowDefinitions()[$profile['id']];

            $this->assertSame('finance', $profile['domain_id']);
            $this->assertSame('AtlasFinanceOrchestrator', $profile['orchestrator']);
            $this->assertSame($definition['runtime'], $profile['runtime']);
            $this->assertSame('low', $profile['autonomy']);
            $this->assertFalse($profile['background_allowed']);
            $this->assertSame($definition['required_gates'], data_get($profile, 'gate_policy.required'));
            $this->assertSame($definition['required_evidence'], data_get($profile, 'execution_policy.required_evidence'));
            $this->assertFalse((bool) data_get($profile, 'execution_policy.market_execution_allowed'));
            $this->assertFalse((bool) data_get($profile, 'execution_policy.order_generation_allowed'));
        }
    }

    public function test_forge_profile_requires_human_approval(): void
    {
        $forge = collect(app(AtlasFinanceProfileFactory::class)->flowProfiles())
            ->firstWhere('id', 'finance.forge');

        $this->assertSame('FinanceForgeRuntime', $forge['runtime']);
        $this->assertTrue((bool) data_get($forge, 'execution_policy.requires_human_approval'));
        $this->assertTrue((bool) data_get($forge, 'metadata.requires_human_approval'));
        $this->assertContains('operator_approval', data_get($forge, 'gate_policy.required'));
    }
}
