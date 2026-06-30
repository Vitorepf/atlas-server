<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneRuntimeInstanceSoak;
use Tests\TestCase;

final class AtlasProjectLaneRuntimeInstanceSoakTest extends TestCase
{
    private function instanceA(): array
    {
        return [
            'lane_id' => 'lane-a',
            'project_id' => 'pa',
            'queue_namespace' => 'pa.ns',
            'allowed_roots' => ['projects/pa/src'],
        ];
    }

    private function instanceB(): array
    {
        return [
            'lane_id' => 'lane-b',
            'project_id' => 'pb',
            'queue_namespace' => 'pb.ns',
            'allowed_roots' => ['projects/pb/src'],
        ];
    }

    private function action(string $laneId, string $namespace, string $root, array $extras = []): array
    {
        return array_replace([
            'kind' => 'native_lane_tick',
            'lane_id' => $laneId,
            'queue_namespace' => $namespace,
            'write_roots' => [$root],
            'receipt_ref' => $laneId.'-rcpt-1',
            'knowledge_sync_ref' => $laneId.'-ks-1',
            'task_packet_id' => $laneId.'-pkt-1',
        ], $extras);
    }

    public function test_healthy_soak_passes_with_namespace_and_root_isolation(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceSoak)->run([
            $this->instanceA(),
            $this->instanceB(),
        ], [
            'scripts' => [
                'lane-a' => [['type' => 'tick', 'action' => $this->action('lane-a', 'pa.ns', 'projects/pa/src/x')]],
                'lane-b' => [['type' => 'tick', 'action' => $this->action('lane-b', 'pb.ns', 'projects/pb/src/y')]],
            ],
        ]);

        $this->assertTrue($verdict['passed']);
        $this->assertSame([], $verdict['leak_attempts']);
        $this->assertSame(1, $verdict['lane_results']['lane-a']['progress_count']);
        $this->assertSame(1, $verdict['lane_results']['lane-b']['progress_count']);
    }

    public function test_cross_lane_action_fails_soak(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceSoak)->run([
            $this->instanceA(),
            $this->instanceB(),
        ], [
            'scripts' => [
                'lane-a' => [['type' => 'tick', 'action' => $this->action('lane-b', 'pb.ns', 'projects/pb/src/leak')]],
                'lane-b' => [['type' => 'tick', 'action' => $this->action('lane-b', 'pb.ns', 'projects/pb/src/y')]],
            ],
        ]);

        $this->assertFalse($verdict['passed']);
        $kinds = array_column($verdict['leak_attempts'], 'kind');
        $this->assertContains('cross_lane_action', $kinds);
    }

    public function test_namespace_collision_at_setup_time_fails_soak(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceSoak)->run([
            $this->instanceA(),
            array_replace($this->instanceB(), ['queue_namespace' => 'pa.ns']),
        ], []);

        $this->assertFalse($verdict['passed']);
        $this->assertContains('namespace_collision', array_column($verdict['leak_attempts'], 'kind'));
    }

    public function test_one_lane_failure_does_not_stop_other_healthy_lane(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceSoak)->run([
            $this->instanceA(),
            $this->instanceB(),
        ], [
            'scripts' => [
                'lane-a' => [['type' => 'failure', 'reason' => 'verifier_red']],
                'lane-b' => [['type' => 'tick', 'action' => $this->action('lane-b', 'pb.ns', 'projects/pb/src/y')]],
            ],
        ]);

        $this->assertCount(1, $verdict['isolated_failures']);
        $this->assertSame(1, $verdict['lane_results']['lane-b']['progress_count']);
        $this->assertTrue($verdict['passed'], 'overall soak passes because healthy lane progressed');
    }

    public function test_per_lane_safety_stop_does_not_affect_other_lane(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceSoak)->run([
            $this->instanceA(),
            $this->instanceB(),
        ], [
            'scripts' => [
                'lane-a' => [['type' => 'safety_stop'], ['type' => 'tick', 'action' => $this->action('lane-a', 'pa.ns', 'projects/pa/src/x')]],
                'lane-b' => [['type' => 'tick', 'action' => $this->action('lane-b', 'pb.ns', 'projects/pb/src/y')]],
            ],
        ]);

        $this->assertTrue($verdict['lane_results']['lane-a']['safety_stop']);
        $this->assertSame(0, $verdict['lane_results']['lane-a']['progress_count']);
        $this->assertSame(1, $verdict['lane_results']['lane-b']['progress_count']);
        $this->assertTrue($verdict['passed']);
    }

    public function test_stale_heartbeat_skips_ticks_until_recovered(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceSoak)->run([
            $this->instanceA(),
        ], [
            'scripts' => [
                'lane-a' => [
                    ['type' => 'stale_heartbeat'],
                    ['type' => 'tick', 'action' => $this->action('lane-a', 'pa.ns', 'projects/pa/src/x')],
                    ['type' => 'heartbeat_recovered'],
                    ['type' => 'tick', 'action' => $this->action('lane-a', 'pa.ns', 'projects/pa/src/y')],
                ],
            ],
        ]);

        $this->assertSame(1, $verdict['lane_results']['lane-a']['progress_count']);
        $this->assertTrue($verdict['lane_results']['lane-a']['recovered']);
    }

    public function test_root_or_receipt_or_packet_id_leak_fails_soak(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceSoak)->run([
            $this->instanceA(),
            $this->instanceB(),
        ], [
            'scripts' => [
                'lane-a' => [[
                    'type' => 'tick',
                    'action' => $this->action('lane-a', 'pa.ns', 'projects/pb/src/leak'),
                ]],
                'lane-b' => [[
                    'type' => 'tick',
                    'action' => $this->action('lane-b', 'pb.ns', 'projects/pb/src/y', ['receipt_ref' => 'lane-a-rcpt']),
                ]],
            ],
        ]);

        $kinds = array_column($verdict['leak_attempts'], 'kind');
        $this->assertContains('root_leak', $kinds);
        $this->assertContains('receipt_ref_leak', $kinds);
        $this->assertFalse($verdict['passed']);
    }

    public function test_global_safety_stop_marks_passed_true_but_zero_progress(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceSoak)->run([
            $this->instanceA(),
            $this->instanceB(),
        ], [
            'global_safety_stop' => true,
            'scripts' => [
                'lane-a' => [['type' => 'tick', 'action' => $this->action('lane-a', 'pa.ns', 'projects/pa/src/x')]],
                'lane-b' => [['type' => 'tick', 'action' => $this->action('lane-b', 'pb.ns', 'projects/pb/src/y')]],
            ],
        ]);

        $this->assertTrue($verdict['passed'], 'global safety stop is a legitimate halt — soak still passes');
        $this->assertSame(0, $verdict['lane_results']['lane-a']['progress_count']);
        $this->assertSame(0, $verdict['lane_results']['lane-b']['progress_count']);
    }

    public function test_steady_state_dependency_in_action_is_recorded_as_violation(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceSoak)->run([
            $this->instanceA(),
            $this->instanceB(),
        ], [
            'scripts' => [
                'lane-a' => [[
                    'type' => 'tick',
                    'action' => $this->action('lane-a', 'pa.ns', 'projects/pa/src/x', ['steady_state_dependencies' => ['operator', 'external_provider']]),
                ]],
                'lane-b' => [['type' => 'tick', 'action' => $this->action('lane-b', 'pb.ns', 'projects/pb/src/y')]],
            ],
        ]);

        $this->assertNotEmpty($verdict['dependency_violations']);
        $deps = array_column($verdict['dependency_violations'], 'dependency');
        $this->assertContains('operator', $deps);
        $this->assertContains('external_provider', $deps);
        $this->assertFalse($verdict['passed']);
    }

    public function test_task_packet_id_from_sibling_lane_is_refused_as_leak(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceSoak)->run([
            $this->instanceA(),
            $this->instanceB(),
        ], [
            'scripts' => [
                'lane-a' => [[
                    'type' => 'tick',
                    'action' => $this->action('lane-a', 'pa.ns', 'projects/pa/src/x', ['task_packet_id' => 'lane-b-pkt-from-a']),
                ]],
                'lane-b' => [['type' => 'tick', 'action' => $this->action('lane-b', 'pb.ns', 'projects/pb/src/y')]],
            ],
        ]);

        $kinds = array_column($verdict['leak_attempts'], 'kind');
        $this->assertContains('task_packet_id_leak', $kinds);
        $this->assertFalse($verdict['passed']);
    }

    public function test_knowledge_sync_ref_from_sibling_lane_is_refused_as_leak(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceSoak)->run([
            $this->instanceA(),
            $this->instanceB(),
        ], [
            'scripts' => [
                'lane-a' => [[
                    'type' => 'tick',
                    'action' => $this->action('lane-a', 'pa.ns', 'projects/pa/src/x', ['knowledge_sync_ref' => 'lane-b-ks-cross']),
                ]],
                'lane-b' => [['type' => 'tick', 'action' => $this->action('lane-b', 'pb.ns', 'projects/pb/src/y')]],
            ],
        ]);

        $kinds = array_column($verdict['leak_attempts'], 'kind');
        $this->assertContains('knowledge_sync_ref_leak', $kinds);
        $this->assertFalse($verdict['passed']);
    }

    public function test_dependency_violations_are_deterministic(): void
    {
        $options = [
            'scripts' => [
                'lane-a' => [[
                    'type' => 'tick',
                    'action' => $this->action('lane-a', 'pa.ns', 'projects/pa/src/x', ['steady_state_dependencies' => ['claude_code', 'codex']]),
                ]],
            ],
        ];

        $a = (new AtlasProjectLaneRuntimeInstanceSoak)->run([$this->instanceA()], $options);
        $b = (new AtlasProjectLaneRuntimeInstanceSoak)->run([$this->instanceA()], $options);

        $this->assertFalse($a['passed']);
        $this->assertSame($a['dependency_violations'], $b['dependency_violations'], 'dependency_violations must be deterministic');
        $deps = array_column($a['dependency_violations'], 'dependency');
        $this->assertContains('claude_code', $deps);
        $this->assertContains('codex', $deps);
    }
}
