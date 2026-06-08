<?php

namespace Tests\Unit\Ai\Hermes\Kanban;

use App\Services\Ai\Hermes\Kanban\HermesKanbanCli;
use App\Services\Ai\Hermes\Kanban\HermesKanbanResult;
use App\Services\Ai\Hermes\Kanban\HermesKanbanSwarmService;
use Tests\TestCase;

/**
 * Proves the Atlas-governed Hermes Kanban swarm substrate: pure fail-closed
 * composition, masked argv preview, and the full live orchestration (board
 * create → swarm seed → bounded dispatch passes → reconcile → board delete) —
 * all against a FAKE CLI scripting the REAL observed `hermes kanban …` JSON
 * shapes, so there is zero spawn and zero token spend.
 */
class HermesKanbanSwarmServiceTest extends TestCase
{
    private function validSpec(array $overrides = []): array
    {
        return array_merge([
            'goal' => 'produce a short report',
            'workers' => [
                ['profile' => 'coder', 'title' => 'implement parser', 'skills' => ['file']],
                ['profile' => 'researcher', 'title' => 'gather sources'],
            ],
            'verifier' => 'verifier',
            'synthesizer' => 'synthesizer',
            'permission_mode' => 'write',
            'mission_id' => 'm-123',
        ], $overrides);
    }

    private function enablePolicy(): void
    {
        config([
            'atlas.ai.providers.hermes_cli.kanban.policy' => 'atlas_adapter',
            'atlas.ai.providers.hermes_cli.kanban.dispatch_poll_microseconds' => 0,
        ]);
    }

    public function test_compose_seals_plan_and_gates_on_policy(): void
    {
        // default off
        $off = (new HermesKanbanSwarmService(new FakeKanbanCli()))->compose($this->validSpec());
        $this->assertSame('atlas.hermes.kanban_swarm_plan.v1', $off['schema_version']);
        $this->assertTrue($off['structural_valid']);
        $this->assertFalse($off['dispatch_allowed_now']);
        $this->assertSame('kanban_policy_off', $off['blocked_reason']);
        $this->assertSame('atlas', $off['authority']);
        $this->assertFalse($off['hermes_kanban_can_decide']);
        $this->assertArrayHasKey('receipt_hash', $off);
        // goal is hashed, never raw
        $this->assertSame(hash('sha256', 'produce a short report'), $off['goal_hash']);

        $this->enablePolicy();
        $on = (new HermesKanbanSwarmService(new FakeKanbanCli()))->compose($this->validSpec());
        $this->assertTrue($on['dispatch_allowed_now']);
        $this->assertNull($on['blocked_reason']);
        $this->assertStringStartsWith('atlas-mission-', $on['board_slug']);
    }

    public function test_compose_fails_closed_on_missing_pieces(): void
    {
        $this->enablePolicy();
        $svc = new HermesKanbanSwarmService(new FakeKanbanCli());

        $this->assertSame('goal_missing', $svc->compose($this->validSpec(['goal' => '']))['blocked_reason']);
        $this->assertSame('workers_missing', $svc->compose($this->validSpec(['workers' => []]))['blocked_reason']);
        $this->assertSame('verifier_missing', $svc->compose($this->validSpec(['verifier' => null]))['blocked_reason']);
        $this->assertSame('synthesizer_missing', $svc->compose($this->validSpec(['synthesizer' => '']))['blocked_reason']);
    }

    public function test_compose_caps_workers_to_max(): void
    {
        $this->enablePolicy();
        config(['atlas.ai.providers.hermes_cli.kanban.max_workers' => 3]);
        $workers = [];
        for ($i = 0; $i < 12; $i++) {
            $workers[] = ['profile' => 'coder', 'title' => "task {$i}"];
        }
        $plan = (new HermesKanbanSwarmService(new FakeKanbanCli()))->compose($this->validSpec(['workers' => $workers]));
        $this->assertSame(3, $plan['worker_count']);
    }

    public function test_preview_argv_masks_goal_and_titles(): void
    {
        $this->enablePolicy();
        $preview = (new HermesKanbanSwarmService(new FakeKanbanCli()))->previewArgv($this->validSpec());

        $swarm = implode(' ', $preview['swarm_argv']);
        $this->assertStringContainsString('swarm', $swarm);
        $this->assertStringContainsString('masked(', $swarm);
        $this->assertStringNotContainsString('produce a short report', $swarm, 'raw goal must never appear');
        $this->assertStringNotContainsString('implement parser', $swarm, 'raw worker title must never appear');
        $this->assertStringContainsString('--verifier', $swarm);
        $this->assertStringContainsString('--synthesizer', $swarm);
        $this->assertStringContainsString('--idempotency-key', $swarm);
        $this->assertContains('--dry-run', $preview['dispatch_dry_run_argv']);
    }

    public function test_run_refuses_without_policy(): void
    {
        // policy off
        $cli = new FakeKanbanCli();
        $result = (new HermesKanbanSwarmService($cli))->run($this->validSpec(), true);
        $this->assertFalse($result['ran']);
        $this->assertSame('kanban_policy_off', $result['blocked_reason']);
        $this->assertSame([], $cli->calls, 'no CLI calls when policy is off');
    }

    public function test_run_refuses_without_confirm(): void
    {
        $this->enablePolicy();
        $cli = new FakeKanbanCli();
        $result = (new HermesKanbanSwarmService($cli))->run($this->validSpec(), false);
        $this->assertFalse($result['ran']);
        $this->assertSame('confirm_required', $result['blocked_reason']);
        $this->assertSame([], $cli->calls, 'no CLI calls without confirm');
    }

    public function test_run_full_orchestration_with_fake_cli(): void
    {
        $this->enablePolicy();
        $cli = new FakeKanbanCli([
            'swarm' => [$this->jsonResult(['root_id' => 't_root', 'worker_ids' => ['t_a', 't_b'], 'verifier_id' => 't_v', 'synthesizer_id' => 't_s'])],
            // pass 1 stats: still active → keep dispatching; pass 2: terminal (all done)
            'stats' => [
                $this->jsonResult(['by_status' => ['ready' => 2, 'todo' => 0, 'done' => 1]]),
                $this->jsonResult(['by_status' => ['done' => 4]]),
            ],
            'dispatch' => [$this->jsonResult(['spawned' => ['t_a', 't_b'], 'crashed' => []])],
        ]);

        $result = (new HermesKanbanSwarmService($cli))->run($this->validSpec(), true);

        $this->assertTrue($result['ran']);
        $this->assertSame('all_completed', $result['aggregate_status']);
        $this->assertSame(2, data_get($result, 'graph.worker_count'));
        $this->assertTrue(data_get($result, 'graph.root_id_present'));
        $this->assertSame(4, data_get($result, 'reconciliation.done'));
        $this->assertSame(2, data_get($result, 'reconciliation.dispatch_passes'), 'looped until stats went terminal');
        $this->assertArrayHasKey('receipt_hash', $result);

        // Atlas owns the board lifecycle: it created AND deleted the board.
        $keys = array_column($cli->calls, 'key');
        $this->assertContains('boards.create', $keys);
        $this->assertContains('swarm', $keys);
        $this->assertContains('dispatch', $keys);
        $this->assertContains('boards.rm', $keys, 'board is deleted after the run (no persistent Hermes board)');
    }

    public function test_run_fails_closed_when_swarm_seed_fails(): void
    {
        $this->enablePolicy();
        $cli = new FakeKanbanCli([
            'swarm' => [new HermesKanbanResult(false, 1, '', 'boom', [])],
        ]);

        $result = (new HermesKanbanSwarmService($cli))->run($this->validSpec(), true);

        $this->assertSame('failed', $result['aggregate_status']);
        $this->assertSame('swarm_seed_failed', $result['blocked_reason']);
        $this->assertContains('boards.rm', array_column($cli->calls, 'key'), 'board cleaned up even on seed failure');
    }

    public function test_run_stops_at_pass_cap_when_never_terminal(): void
    {
        $this->enablePolicy();
        config(['atlas.ai.providers.hermes_cli.kanban.max_dispatch_passes' => 3]);
        $cli = new FakeKanbanCli([
            'swarm' => [$this->jsonResult(['root_id' => 't_root', 'worker_ids' => ['t_a'], 'verifier_id' => 't_v', 'synthesizer_id' => 't_s'])],
            'stats' => [$this->jsonResult(['by_status' => ['ready' => 1]])], // always active → never terminal
            'dispatch' => [$this->jsonResult(['spawned' => []])],
        ]);

        $result = (new HermesKanbanSwarmService($cli))->run($this->validSpec(), true);

        $this->assertSame(3, data_get($result, 'reconciliation.dispatch_passes'));
        $this->assertTrue(data_get($result, 'reconciliation.pass_cap_hit'));
        $this->assertSame('incomplete', $result['aggregate_status']);
    }

    private function jsonResult(array $payload): HermesKanbanResult
    {
        return new HermesKanbanResult(true, 0, json_encode($payload), '', $payload);
    }
}

class FakeKanbanCli implements HermesKanbanCli
{
    /** @var array<int,array{key:string,args:array<int,string>,options:array<string,mixed>}> */
    public array $calls = [];

    /** @param array<string,array<int,HermesKanbanResult>> $queues */
    public function __construct(private array $queues = []) {}

    public function invoke(array $args, array $options = []): HermesKanbanResult
    {
        $key = $this->keyFor($args);
        $this->calls[] = ['key' => $key, 'args' => $args, 'options' => $options];

        if (! empty($this->queues[$key])) {
            // pop while more than one remains; keep the last as the steady-state reply
            return count($this->queues[$key]) > 1 ? array_shift($this->queues[$key]) : $this->queues[$key][0];
        }

        return new HermesKanbanResult(true, 0, '', '', []);
    }

    /** @param array<int,string> $args */
    private function keyFor(array $args): string
    {
        if (($args[0] ?? '') === 'boards') {
            return 'boards.'.($args[1] ?? '');
        }

        return (string) ($args[0] ?? '');
    }
}
