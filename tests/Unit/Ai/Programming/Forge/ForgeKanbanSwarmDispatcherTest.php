<?php

namespace Tests\Unit\Ai\Programming\Forge;

use App\Services\Ai\Hermes\Kanban\HermesKanbanSwarmService;
use App\Services\Ai\Programming\Forge\ForgeKanbanSwarmDispatcher;
use Tests\TestCase;

/**
 * Proves the Forge → Kanban consumer call-path: triple fail-closed gating (policy
 * + dispatch_for_forge + confirm), correct Forge-packet→kanban-spec mapping, and
 * delegation to the governed kanban service — with the kanban service MOCKED, so
 * no board is created and no worker spawns.
 */
class ForgeKanbanSwarmDispatcherTest extends TestCase
{
    private const PACKETS = [
        ['objective' => 'build the parser', 'role_slot' => 'coder'],
        ['title' => 'write tests', 'role' => 'tester'],
        ['objective' => 'no role packet'],
    ];

    private function enableForge(): void
    {
        config([
            'atlas.ai.providers.hermes_cli.kanban.policy' => 'atlas_adapter',
            'atlas.ai.providers.hermes_cli.kanban.dispatch_for_forge' => true,
        ]);
    }

    public function test_blocked_when_policy_off(): void
    {
        config(['atlas.ai.providers.hermes_cli.kanban.dispatch_for_forge' => true]); // policy still off
        $kanban = $this->createMock(HermesKanbanSwarmService::class);
        $kanban->expects($this->never())->method('run');

        $result = (new ForgeKanbanSwarmDispatcher($kanban))->dispatch('ship feature', self::PACKETS, ['confirm' => true]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('kanban_policy_off', $result['blocked_reason']);
        $this->assertFalse($result['dispatched']);
        $this->assertSame('atlas.forge.kanban_dispatch.v1', $result['schema_version']);
    }

    public function test_blocked_when_forge_dispatch_disabled(): void
    {
        config(['atlas.ai.providers.hermes_cli.kanban.policy' => 'atlas_adapter']); // dispatch_for_forge off
        $kanban = $this->createMock(HermesKanbanSwarmService::class);
        $kanban->expects($this->never())->method('run');

        $result = (new ForgeKanbanSwarmDispatcher($kanban))->dispatch('ship feature', self::PACKETS, ['confirm' => true]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('forge_dispatch_disabled', $result['blocked_reason']);
    }

    public function test_blocked_without_confirm(): void
    {
        $this->enableForge();
        $kanban = $this->createMock(HermesKanbanSwarmService::class);
        $kanban->expects($this->never())->method('run');

        $result = (new ForgeKanbanSwarmDispatcher($kanban))->dispatch('ship feature', self::PACKETS, ['confirm' => false]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('confirm_required', $result['blocked_reason']);
    }

    public function test_maps_packets_to_spec_and_delegates(): void
    {
        $this->enableForge();
        $captured = null;
        $kanban = $this->createMock(HermesKanbanSwarmService::class);
        $kanban->expects($this->once())->method('run')->willReturnCallback(
            function (array $spec, bool $confirm) use (&$captured): array {
                $captured = ['spec' => $spec, 'confirm' => $confirm];

                return ['aggregate_status' => 'all_completed', 'ran' => true, 'reconciliation' => ['done' => 3]];
            },
        );

        $result = (new ForgeKanbanSwarmDispatcher($kanban))->dispatch('ship the feature', self::PACKETS, [
            'confirm' => true,
            'permission_mode' => 'write',
            'mission_id' => 'm-7',
        ]);

        // delegation + confirm passed through
        $this->assertTrue($captured['confirm']);
        // goal mapped
        $this->assertSame('ship the feature', $captured['spec']['goal']);
        // packets → workers (objective|title + role_slot|role, default 'worker')
        $this->assertCount(3, $captured['spec']['workers']);
        $this->assertSame(['profile' => 'coder', 'title' => 'build the parser', 'skills' => []], $captured['spec']['workers'][0]);
        $this->assertSame(['profile' => 'tester', 'title' => 'write tests', 'skills' => []], $captured['spec']['workers'][1]);
        $this->assertSame('worker', $captured['spec']['workers'][2]['profile']);
        // verifier/synthesizer from config defaults; permission mode honoured
        $this->assertSame('verifier', $captured['spec']['verifier']);
        $this->assertSame('synthesizer', $captured['spec']['synthesizer']);
        $this->assertSame('write', $captured['spec']['permission_mode']);
        $this->assertSame('m-7', $captured['spec']['mission_id']);

        // result wraps the kanban run + seals
        $this->assertSame('all_completed', $result['status']);
        $this->assertTrue($result['dispatched']);
        $this->assertSame(3, $result['worker_count']);
        $this->assertSame(3, data_get($result, 'kanban_run.reconciliation.done'));
        $this->assertArrayHasKey('receipt_hash', $result);
        $this->assertSame(hash('sha256', 'ship the feature'), $result['goal_hash']);
    }

    public function test_preview_is_pure_and_never_dispatches(): void
    {
        $this->enableForge();
        $kanban = $this->createMock(HermesKanbanSwarmService::class);
        $kanban->expects($this->never())->method('run');
        $kanban->expects($this->once())->method('previewArgv')->willReturn([
            'plan' => ['board_slug' => 'atlas-mission-abc'],
            'swarm_argv' => ['kanban', 'swarm', 'masked(len=5,sha256=deadbeef0000)'],
            'dispatch_dry_run_argv' => ['kanban', 'dispatch', '--dry-run'],
        ]);

        $preview = (new ForgeKanbanSwarmDispatcher($kanban))->preview('ship', self::PACKETS, ['confirm' => true]);

        $this->assertTrue($preview['forge_dispatch_allowed']);
        $this->assertNull($preview['forge_block_reason']);
        $this->assertSame(3, $preview['worker_count']);
        $this->assertStringContainsString('masked(', implode(' ', $preview['preview']['swarm_argv']));
    }
}
