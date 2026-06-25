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
