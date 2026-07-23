<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Policy\AtlasDomainProfileRegistry;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class StrategicDecisionDomainContractMigrationTest extends TestCase
{
    public function test_strategic_decision_domain_contract_is_seeded(): void
    {
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_04_020000_create_ai_domain_and_flow_profiles_tables.php',
            '--force' => true,
        ]);

        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_06_130000_seed_strategic_decision_domain_contract.php',
            '--force' => true,
        ]);

        $profile = app(AtlasDomainProfileRegistry::class)->resolve('strategic_decision.regret_tracking');

        $this->assertSame('database', $profile['source']);
        $this->assertSame('strategic_decision', $profile['domain_id']);
        $this->assertSame('strategic_decision.regret_tracking', $profile['flow_id']);
        $this->assertSame('AtlasStrategicDecisionOrchestrator', data_get($profile, 'flow_profile.orchestrator'));
        $this->assertSame('StrategicDecisionRegretRuntime', data_get($profile, 'flow_profile.runtime'));
        $this->assertSame('review_only', data_get($profile, 'flow_profile.gate_policy.autonomy_ceiling'));
        $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.commitment_execution_allowed'));
        $this->assertTrue((bool) data_get($profile, 'flow_profile.execution_policy.requires_rivals_strategy_case'));
    }
}
