<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAiDomainsCommandTest extends TestCase
{
    public function test_domains_command_lists_executable_domain_contracts_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:domains', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertTrue(data_get($payload, 'validation.valid'));
        $this->assertGreaterThanOrEqual(8, data_get($payload, 'summary.domains'));
        $this->assertGreaterThanOrEqual(17, data_get($payload, 'summary.flows'));
        $this->assertGreaterThanOrEqual(2, data_get($payload, 'summary.implemented_orchestrators'));

        $programming = collect($payload['domains'])->firstWhere('id', 'programming');

        $this->assertSame('programming.dev', $programming['default_flow']);
        $this->assertSame('AtlasProgrammingOrchestrator', $programming['orchestrator']);
        $this->assertSame('implemented', $programming['orchestrator_maturity']);
        $this->assertSame('ready', data_get($programming, 'onboarding.status'));
        $this->assertSame(9, data_get($programming, 'onboarding.completed_count'));
        $this->assertContains('orchestrator', data_get($programming, 'onboarding.completed_phases'));
        $this->assertContains('runtime', data_get($programming, 'onboarding.completed_phases'));
        $this->assertContains('learning', data_get($programming, 'onboarding.completed_phases'));
        $this->assertSame([], data_get($programming, 'onboarding.missing_phases'));

        $selfImprovement = collect($payload['domains'])->firstWhere('id', 'self_improvement');

        $this->assertSame('ready', data_get($selfImprovement, 'onboarding.status'));
        $this->assertSame(9, data_get($selfImprovement, 'onboarding.completed_count'));
        $this->assertSame([], data_get($selfImprovement, 'onboarding.missing_phases'));

        $marketing = collect($payload['domains'])->firstWhere('id', 'marketing');

        $this->assertSame('ready', data_get($marketing, 'onboarding.status'));
        $this->assertSame(9, data_get($marketing, 'onboarding.completed_count'));
        $this->assertSame(15, data_get($marketing, 'flow_count'));
        $this->assertSame([], data_get($marketing, 'onboarding.missing_phases'));
    }

    public function test_domains_command_filters_flow_contract(): void
    {
        $exit = Artisan::call('atlas:ai:domains', [
            '--flow' => 'programming.repair',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('programming.repair', data_get($payload, 'filters.flow'));
        $this->assertSame(1, data_get($payload, 'summary.domains'));
        $this->assertSame(1, data_get($payload, 'summary.flows'));
        $this->assertSame('programming', data_get($payload, 'domains.0.id'));
        $this->assertSame('programming.repair', data_get($payload, 'flows.0.id'));
        $this->assertSame('dev_repair_executor', data_get($payload, 'flows.0.executor_preference'));
        $this->assertSame('implemented', data_get($payload, 'flows.0.orchestrator_maturity'));
    }

    public function test_domains_command_filters_orchestrator_maturity(): void
    {
        $exit = Artisan::call('atlas:ai:domains', [
            '--maturity' => 'implemented',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('implemented', data_get($payload, 'filters.maturity'));
        $this->assertGreaterThanOrEqual(2, data_get($payload, 'summary.orchestrators'));

        foreach ($payload['orchestrators'] as $orchestrator) {
            $this->assertSame('implemented', $orchestrator['maturity']);
            $this->assertTrue($orchestrator['implemented_contract']);
        }
    }
}
