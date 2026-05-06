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
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'summary.ready_domains'));
        $this->assertGreaterThanOrEqual(0, data_get($payload, 'summary.scaffold_domains'));
        $this->assertSame(
            data_get($payload, 'summary.domains'),
            array_sum(data_get($payload, 'summary.onboarding_status_counts'))
        );

        $general = collect($payload['domains'])->firstWhere('id', 'general');

        $this->assertSame('general.answer', data_get($general, 'default_flow'));
        $this->assertSame('ready', data_get($general, 'onboarding.status'));
        $this->assertSame('implemented', data_get($general, 'orchestrator_maturity'));
        $this->assertSame(1, data_get($general, 'flow_count'));
        $this->assertSame([], data_get($general, 'onboarding.missing_phases'));

        $health = collect($payload['domains'])->firstWhere('id', 'health');

        $this->assertSame('ready', data_get($health, 'onboarding.status'));
        $this->assertSame('implemented', data_get($health, 'orchestrator_maturity'));
        $this->assertSame(4, data_get($health, 'flow_count'));
        $this->assertSame([], data_get($health, 'onboarding.missing_phases'));

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
        $this->assertGreaterThanOrEqual(10, data_get($selfImprovement, 'flow_count'));
        $this->assertSame([], data_get($selfImprovement, 'onboarding.missing_phases'));

        $marketing = collect($payload['domains'])->firstWhere('id', 'marketing');

        $this->assertSame('ready', data_get($marketing, 'onboarding.status'));
        $this->assertSame('implemented', data_get($marketing, 'orchestrator_maturity'));
        $this->assertSame(15, data_get($marketing, 'flow_count'));
        $this->assertSame([], data_get($marketing, 'onboarding.missing_phases'));

        $strategicDecision = collect($payload['domains'])->firstWhere('id', 'strategic_decision');

        $this->assertSame('strategic_decision.review', data_get($strategicDecision, 'default_flow'));
        $this->assertSame('ready', data_get($strategicDecision, 'onboarding.status'));
        $this->assertSame('implemented', data_get($strategicDecision, 'orchestrator_maturity'));
        $this->assertSame(6, data_get($strategicDecision, 'flow_count'));
        $this->assertSame([], data_get($strategicDecision, 'onboarding.missing_phases'));

        $finance = collect($payload['domains'])->firstWhere('id', 'finance');

        $this->assertSame('ready', data_get($finance, 'onboarding.status'));
        $this->assertSame(9, data_get($finance, 'onboarding.completed_count'));
        $this->assertSame(10, data_get($finance, 'flow_count'));
        $this->assertSame([], data_get($finance, 'onboarding.missing_phases'));

        $personalDevelopment = collect($payload['domains'])->firstWhere('id', 'personal_development');

        $this->assertSame('ready', data_get($personalDevelopment, 'onboarding.status'));
        $this->assertSame(9, data_get($personalDevelopment, 'onboarding.completed_count'));
        $this->assertSame(10, data_get($personalDevelopment, 'flow_count'));
        $this->assertSame([], data_get($personalDevelopment, 'onboarding.missing_phases'));

        $research = collect($payload['domains'])->firstWhere('id', 'research');

        $this->assertSame('ready', data_get($research, 'onboarding.status'));
        $this->assertSame('implemented', data_get($research, 'orchestrator_maturity'));
        $this->assertSame(2, data_get($research, 'flow_count'));
        $this->assertSame([], data_get($research, 'onboarding.missing_phases'));

        $writing = collect($payload['domains'])->firstWhere('id', 'writing');

        $this->assertSame('ready', data_get($writing, 'onboarding.status'));
        $this->assertSame('implemented', data_get($writing, 'orchestrator_maturity'));
        $this->assertSame(4, data_get($writing, 'flow_count'));
        $this->assertSame([], data_get($writing, 'onboarding.missing_phases'));

        $learning = collect($payload['domains'])->firstWhere('id', 'learning');

        $this->assertSame('ready', data_get($learning, 'onboarding.status'));
        $this->assertSame('implemented', data_get($learning, 'orchestrator_maturity'));
        $this->assertSame(4, data_get($learning, 'flow_count'));
        $this->assertSame([], data_get($learning, 'onboarding.missing_phases'));

        $qa = collect($payload['domains'])->firstWhere('id', 'qa');

        $this->assertSame('ready', data_get($qa, 'onboarding.status'));
        $this->assertSame('implemented', data_get($qa, 'orchestrator_maturity'));
        $this->assertSame(4, data_get($qa, 'flow_count'));
        $this->assertSame([], data_get($qa, 'onboarding.missing_phases'));

        $security = collect($payload['domains'])->firstWhere('id', 'security');

        $this->assertSame('ready', data_get($security, 'onboarding.status'));
        $this->assertSame('implemented', data_get($security, 'orchestrator_maturity'));
        $this->assertSame(4, data_get($security, 'flow_count'));
        $this->assertSame([], data_get($security, 'onboarding.missing_phases'));

        $operations = collect($payload['domains'])->firstWhere('id', 'operations');

        $this->assertSame('ready', data_get($operations, 'onboarding.status'));
        $this->assertSame('implemented', data_get($operations, 'orchestrator_maturity'));
        $this->assertSame(4, data_get($operations, 'flow_count'));
        $this->assertSame([], data_get($operations, 'onboarding.missing_phases'));

        $background = collect($payload['domains'])->firstWhere('id', 'background');

        $this->assertSame('ready', data_get($background, 'onboarding.status'));
        $this->assertSame('implemented', data_get($background, 'orchestrator_maturity'));
        $this->assertSame(4, data_get($background, 'flow_count'));
        $this->assertSame([], data_get($background, 'onboarding.missing_phases'));
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
        $this->assertGreaterThanOrEqual(4, data_get($payload, 'summary.orchestrators'));

        foreach ($payload['orchestrators'] as $orchestrator) {
            $this->assertSame('implemented', $orchestrator['maturity']);
            $this->assertTrue($orchestrator['implemented_contract']);
        }
    }

    public function test_domains_command_filters_domain_onboarding_status(): void
    {
        $exit = Artisan::call('atlas:ai:domains', [
            '--onboarding-status' => 'scaffold',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('scaffold', data_get($payload, 'filters.onboarding_status'));
        $this->assertGreaterThanOrEqual(0, data_get($payload, 'summary.domains'));
        $this->assertSame(data_get($payload, 'summary.domains'), data_get($payload, 'summary.scaffold_domains'));
        $this->assertSame(
            data_get($payload, 'summary.domains') > 0 ? ['scaffold' => data_get($payload, 'summary.domains')] : [],
            data_get($payload, 'summary.onboarding_status_counts')
        );

        foreach ($payload['domains'] as $domain) {
            $this->assertSame('scaffold', data_get($domain, 'onboarding.status'));
        }

        foreach ($payload['flows'] as $flow) {
            $this->assertContains($flow['domain_id'], collect($payload['domains'])->pluck('id')->all());
        }
    }
}
