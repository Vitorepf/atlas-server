<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\AtlasDecide\AtlasSwarmTopologyAutoComposerService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasSwarmTopologyAutoComposeCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'atlas.patamar4.swarm_topology_auto_composer.enabled' => true,
            'atlas.patamar4.swarm_topology_auto_composer.schedule_enabled' => true,
            'atlas.patamar4.swarm_topology_auto_composer.schedule_time' => '07:20',
            'atlas.patamar4.swarm_topology_auto_composer.min_task_types' => 2,
            'atlas.patamar4.swarm_topology_auto_composer.min_distinct_topologies' => 2,
            'atlas.patamar4.swarm_topology_auto_composer.min_convergence_rate' => 1.0,
            'atlas.patamar4.swarm_topology_auto_composer.forced_provider' => 'codex',
            'atlas.patamar4.swarm_topology_auto_composer.forced_model' => 'gpt-5.5',
        ]);
    }

    public function test_two_task_types_select_distinct_topologies_and_converge(): void
    {
        $payload = app(AtlasSwarmTopologyAutoComposerService::class)->evaluate([
            'fixture' => 'two-types',
        ]);

        $this->assertSame('swarm_topologies_converged', $payload['status']);
        $this->assertTrue($payload['certified']);
        $this->assertTrue($payload['completion_claim_allowed']);
        $this->assertSame(2, data_get($payload, 'summary.task_type_count'));
        $this->assertSame(2, data_get($payload, 'summary.topology_count'));
        $this->assertSame(2, data_get($payload, 'summary.measured_count'));
        $this->assertSame(2, data_get($payload, 'summary.converged_count'));
        $this->assertSame([], $payload['blockers']);

        $byTask = collect($payload['measurements'])->keyBy('task_category');
        $this->assertSame('debate', data_get($byTask->get('reasoning'), 'topology'));
        $this->assertSame('tournament', data_get($byTask->get('code_generation'), 'topology'));
        $this->assertSame(['codex'], data_get($byTask->get('reasoning'), 'winner_providers'));
        $this->assertSame(['codex'], data_get($byTask->get('code_generation'), 'winner_providers'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.shadow_only'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_tokens_spent'));
        $this->assertSame('none', data_get($payload, 'claim_policy.runtime_routing_effect'));
    }

    public function test_single_task_type_is_not_enough_even_when_nodes_converge(): void
    {
        $exit = Artisan::call('atlas:swarm:topology-auto-compose', [
            '--fixture' => 'single-type',
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('swarm_topology_convergence_blocked', $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertSame(1, data_get($payload, 'summary.task_type_count'));
        $this->assertSame(1, data_get($payload, 'summary.topology_count'));
        $this->assertContains('task_type_coverage_below_floor', $payload['blockers']);
        $this->assertContains('distinct_topologies_below_floor', $payload['blockers']);
    }

    public function test_command_writes_receipt_without_enabling_live_routing(): void
    {
        $receipt = storage_path('framework/testing/swarm-topology-auto-compose.json');
        File::delete($receipt);

        $exit = Artisan::call('atlas:swarm:topology-auto-compose', [
            '--fixture' => 'two-types',
            '--receipt' => $receipt,
            '--write-receipt' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertFileExists($receipt);
        $this->assertSame($receipt, $payload['receipt_path']);
        $this->assertSame('swarm_topologies_converged', $payload['status']);
        $this->assertSame('none', data_get($payload, 'claim_policy.topology_activation_effect'));
    }

    public function test_schedule_contains_daily_swarm_topology_auto_composer(): void
    {
        $exit = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('atlas:swarm:topology-auto-compose --fixture=two-types --write-receipt --json', $output);
    }
}
