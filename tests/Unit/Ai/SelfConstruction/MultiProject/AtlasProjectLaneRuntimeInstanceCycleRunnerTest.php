<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneRuntimeInstanceCycleRunner;
use Tests\TestCase;

final class AtlasProjectLaneRuntimeInstanceCycleRunnerTest extends TestCase
{
    private function laneInstance(): array
    {
        return [
            'lane_id' => 'lane-x',
            'project_id' => 'demo',
            'queue_namespace' => 'demo.x',
            'allowed_roots' => ['projects/demo/src'],
        ];
    }

    private function readyFacts(array $overrides = []): array
    {
        return array_replace([
            'daemon_state' => ['status' => 'planned'],
            'heartbeat_event' => ['type' => 'heartbeat', 'now_at' => '2026-06-25T05:30:00+00:00'],
            'planned_actions' => [[
                'kind' => 'native_lane_tick',
                'lane_id' => 'lane-x',
                'queue_namespace' => 'demo.x',
                'write_roots' => ['projects/demo/src/foo.php'],
            ]],
        ], $overrides);
    }

    public function test_dry_run_does_not_invoke_callback(): void
    {
        $called = 0;
        $runner = new AtlasProjectLaneRuntimeInstanceCycleRunner;
        $out = $runner->run($this->laneInstance(), $this->readyFacts(), [
            'action_callbacks' => ['native_lane_tick' => function () use (&$called) { $called++; }],
        ]);

        $this->assertSame(AtlasProjectLaneRuntimeInstanceCycleRunner::SCHEMA, $out['schema_version']);
        $this->assertTrue($out['dry_run']);
        $this->assertSame(0, $called);
        $this->assertSame([], $out['applied_actions']);
        $this->assertSame('lane-x', $out['lane_id']);
    }

    public function test_apply_invokes_only_namespace_and_root_scoped_callbacks(): void
    {
        $invoked = [];
        $runner = new AtlasProjectLaneRuntimeInstanceCycleRunner;
        $out = $runner->run($this->laneInstance(), $this->readyFacts(), [
            'apply' => true,
            'action_callbacks' => [
                'native_lane_tick' => function (array $a) use (&$invoked): array {
                    $invoked[] = $a;
                    return ['ok' => true];
                },
            ],
        ]);

        $this->assertFalse($out['dry_run']);
        $this->assertCount(1, $invoked);
        $this->assertCount(1, $out['applied_actions']);
        $this->assertCount(1, $out['lane_receipts']);
    }

    public function test_callback_failure_is_isolated_into_blocked_actions(): void
    {
        $runner = new AtlasProjectLaneRuntimeInstanceCycleRunner;
        $out = $runner->run($this->laneInstance(), $this->readyFacts(), [
            'apply' => true,
            'action_callbacks' => [
                'native_lane_tick' => static function (): array {
                    throw new \RuntimeException('lane boom');
                },
            ],
        ]);

        $this->assertSame([], $out['applied_actions']);
        $this->assertCount(1, $out['blocked_actions']);
        $this->assertSame('lane boom', $out['blocked_actions'][0]['error']);
    }

    public function test_cross_lane_action_is_refused(): void
    {
        $runner = new AtlasProjectLaneRuntimeInstanceCycleRunner;
        $out = $runner->run($this->laneInstance(), $this->readyFacts(['planned_actions' => [[
            'kind' => 'native_lane_tick',
            'lane_id' => 'other-lane',
            'queue_namespace' => 'demo.x',
            'write_roots' => ['projects/demo/src/foo.php'],
        ]]]), ['apply' => true, 'action_callbacks' => ['native_lane_tick' => static fn (): array => ['ok' => true]]]);

        $this->assertSame([], $out['applied_actions']);
        $this->assertContains('cross_lane_action:other-lane', array_column($out['withheld_actions'], 'reason'));
    }

    public function test_write_root_outside_allowed_is_refused(): void
    {
        $runner = new AtlasProjectLaneRuntimeInstanceCycleRunner;
        $out = $runner->run($this->laneInstance(), $this->readyFacts(['planned_actions' => [[
            'kind' => 'native_lane_tick',
            'lane_id' => 'lane-x',
            'queue_namespace' => 'demo.x',
            'write_roots' => ['other-project/secrets'],
        ]]]), ['apply' => true, 'action_callbacks' => ['native_lane_tick' => static fn (): array => ['ok' => true]]]);

        $this->assertSame([], $out['applied_actions']);
        $this->assertNotEmpty(array_filter($out['withheld_actions'], static fn (array $w): bool => str_starts_with($w['reason'], 'write_root_outside_lane:')));
    }

    public function test_dry_run_records_withheld_dry_run_actions_deterministically(): void
    {
        $runner = new AtlasProjectLaneRuntimeInstanceCycleRunner;
        $outA = $runner->run($this->laneInstance(), $this->readyFacts(), []);
        $outB = $runner->run($this->laneInstance(), $this->readyFacts(), []);

        $this->assertTrue($outA['dry_run']);
        $this->assertCount(1, $outA['withheld_actions']);
        $this->assertSame('dry_run', $outA['withheld_actions'][0]['reason']);
        $this->assertSame('native_lane_tick', $outA['withheld_actions'][0]['kind']);
        $this->assertSame($outA['withheld_actions'], $outB['withheld_actions'], 'withheld_actions must be deterministic');
    }

    public function test_apply_lane_receipt_has_required_fields(): void
    {
        $runner = new AtlasProjectLaneRuntimeInstanceCycleRunner;
        $out = $runner->run($this->laneInstance(), $this->readyFacts(), [
            'apply' => true,
            'action_callbacks' => ['native_lane_tick' => static fn (array $a): array => ['done' => true]],
        ]);

        $this->assertCount(1, $out['lane_receipts']);
        $receipt = $out['lane_receipts'][0];
        $this->assertSame('lane-x', $receipt['lane_id']);
        $this->assertSame('demo', $receipt['project_id']);
        $this->assertSame(64, strlen($receipt['action_hash']));
        $this->assertSame(64, strlen($receipt['result_hash']));
        $this->assertArrayHasKey('daemon_status', $receipt);
    }

    public function test_missing_namespace_action_is_withheld(): void
    {
        $runner = new AtlasProjectLaneRuntimeInstanceCycleRunner;
        $out = $runner->run($this->laneInstance(), $this->readyFacts(['planned_actions' => [[
            'kind' => 'native_lane_tick',
            'lane_id' => 'lane-x',
            'queue_namespace' => '',
            'write_roots' => ['projects/demo/src/bar.php'],
        ]]]), ['apply' => true, 'action_callbacks' => ['native_lane_tick' => static fn (): array => ['ok' => true]]]);

        $this->assertSame([], $out['applied_actions']);
        $reasons = array_column($out['withheld_actions'], 'reason');
        $this->assertTrue(
            (bool) array_filter($reasons, static fn (string $r): bool => str_starts_with($r, 'namespace_mismatch')),
            'missing namespace must produce namespace_mismatch reason',
        );
    }

    public function test_callback_exception_does_not_abort_sibling_allowed_actions(): void
    {
        $runner = new AtlasProjectLaneRuntimeInstanceCycleRunner;
        $facts = $this->readyFacts(['planned_actions' => [
            ['kind' => 'native_lane_tick', 'lane_id' => 'lane-x', 'queue_namespace' => 'demo.x', 'write_roots' => ['projects/demo/src/a.php']],
            ['kind' => 'native_lane_tick', 'lane_id' => 'lane-x', 'queue_namespace' => 'demo.x', 'write_roots' => ['projects/demo/src/b.php']],
        ]]);
        $call = 0;
        $out = $runner->run($this->laneInstance(), $facts, [
            'apply' => true,
            'action_callbacks' => [
                'native_lane_tick' => function () use (&$call): array {
                    $call++;
                    if ($call === 1) {
                        throw new \RuntimeException('first-action-boom');
                    }
                    return ['ok' => true];
                },
            ],
        ]);

        $this->assertCount(1, $out['blocked_actions'], 'first action must be blocked');
        $this->assertCount(1, $out['applied_actions'], 'second action must still apply');
        $this->assertSame('first-action-boom', $out['blocked_actions'][0]['error']);
    }

    public function test_refused_action_kinds_never_fire(): void
    {
        $touched = false;
        $runner = new AtlasProjectLaneRuntimeInstanceCycleRunner;
        $out = $runner->run($this->laneInstance(), $this->readyFacts(['planned_actions' => [
            ['kind' => 'git', 'lane_id' => 'lane-x', 'queue_namespace' => 'demo.x', 'write_roots' => ['projects/demo/src/x']],
            ['kind' => 'operator_action', 'lane_id' => 'lane-x', 'queue_namespace' => 'demo.x'],
            ['kind' => 'human_action', 'lane_id' => 'lane-x', 'queue_namespace' => 'demo.x'],
            ['kind' => 'external_provider_call', 'lane_id' => 'lane-x', 'queue_namespace' => 'demo.x'],
            ['kind' => 'claude_code', 'lane_id' => 'lane-x', 'queue_namespace' => 'demo.x'],
            ['kind' => 'codex', 'lane_id' => 'lane-x', 'queue_namespace' => 'demo.x'],
            ['kind' => 'cursor', 'lane_id' => 'lane-x', 'queue_namespace' => 'demo.x'],
            ['kind' => 'network', 'lane_id' => 'lane-x', 'queue_namespace' => 'demo.x'],
            ['kind' => 'unrestricted_shell', 'lane_id' => 'lane-x', 'queue_namespace' => 'demo.x'],
        ]]), ['apply' => true, 'action_callbacks' => [
            'git' => function () use (&$touched) { $touched = true; },
            'operator_action' => function () use (&$touched) { $touched = true; },
        ]]);

        $this->assertFalse($touched);
        $this->assertSame([], $out['applied_actions']);
        $kinds = array_column($out['withheld_actions'], 'kind');
        foreach (AtlasProjectLaneRuntimeInstanceCycleRunner::REFUSED_ACTION_KINDS as $r) {
            $this->assertContains($r, $kinds);
        }
    }
}
