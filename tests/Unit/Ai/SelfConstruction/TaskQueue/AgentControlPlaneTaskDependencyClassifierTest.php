<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQueue;

use App\Services\Ai\SelfConstruction\TaskQueue\AgentControlPlaneTaskDependencyClassifier;
use Closure;
use Tests\TestCase;

class AgentControlPlaneTaskDependencyClassifierTest extends TestCase
{
    private function makeLoader(array $records): Closure
    {
        return function (string $id) use ($records): ?array {
            return $records[$id] ?? null;
        };
    }

    public function test_classify_no_dependencies_is_met(): void
    {
        $cache = [];
        $result = AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $this->makeLoader([]),
            ['task_packet_id' => 'tp1', 'metadata' => []],
            $cache,
        );
        self::assertSame('met', $result);
    }

    public function test_classify_absent_dependency_is_satisfied(): void
    {
        $cache = [];
        $result = AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $this->makeLoader([]),
            ['task_packet_id' => 'tp1', 'metadata' => ['depends_on' => ['nonexistent_dep']]],
            $cache,
        );
        self::assertSame('met', $result);
    }

    public function test_classify_completed_dependency_is_met(): void
    {
        $cache = [];
        $result = AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $this->makeLoader(['dep1' => ['status' => 'completed_dry_run', 'metadata' => []]]),
            ['task_packet_id' => 'tp1', 'metadata' => ['depends_on' => ['dep1']]],
            $cache,
        );
        self::assertSame('met', $result);
    }

    public function test_classify_cancelled_dependency_is_satisfied(): void
    {
        $cache = [];
        $result = AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $this->makeLoader(['dep1' => ['status' => 'cancelled', 'metadata' => []]]),
            ['task_packet_id' => 'tp1', 'metadata' => ['depends_on' => ['dep1']]],
            $cache,
        );
        self::assertSame('met', $result);
    }

    public function test_classify_blocked_dependency_returns_blocked(): void
    {
        $cache = [];
        $result = AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $this->makeLoader(['dep1' => ['status' => 'blocked', 'metadata' => []]]),
            ['task_packet_id' => 'tp1', 'metadata' => ['depends_on' => ['dep1']]],
            $cache,
        );
        self::assertSame('blocked', $result);
    }

    public function test_classify_inflight_dependency_returns_inflight(): void
    {
        $cache = [];
        $result = AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $this->makeLoader(['dep1' => ['status' => 'claimable', 'metadata' => []]]),
            ['task_packet_id' => 'tp1', 'metadata' => ['depends_on' => ['dep1']]],
            $cache,
        );
        self::assertSame('inflight', $result);
    }

    public function test_classify_inflight_takes_precedence_over_blocked(): void
    {
        $cache = [];
        $records = [
            'dep1' => ['status' => 'claimable', 'metadata' => []],
            'dep2' => ['status' => 'blocked', 'metadata' => []],
        ];
        $result = AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $this->makeLoader($records),
            ['task_packet_id' => 'tp1', 'metadata' => ['depends_on' => ['dep1', 'dep2']]],
            $cache,
        );
        self::assertSame('inflight', $result);
    }

    public function test_classify_cycle_returns_met(): void
    {
        $cache = [];
        $records = ['dep1' => ['status' => 'claimable', 'metadata' => ['depends_on' => ['tp1']]]];
        $result = AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $this->makeLoader($records),
            ['task_packet_id' => 'tp1', 'metadata' => ['depends_on' => ['dep1']]],
            $cache,
        );
        self::assertSame('met', $result);
    }

    public function test_classify_ignores_non_string_dependencies(): void
    {
        $cache = [];
        $result = AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $this->makeLoader([]),
            ['task_packet_id' => 'tp1', 'metadata' => ['depends_on' => [null, 42, 'string_dep']]],
            $cache,
        );
        self::assertSame('met', $result);
    }

    public function test_dependency_node_returns_null_for_absent(): void
    {
        $cache = [];
        $result = AgentControlPlaneTaskDependencyClassifier::dependencyNode($this->makeLoader([]), 'absent', $cache);
        self::assertNull($result);
        self::assertArrayHasKey('absent', $cache);
    }

    public function test_dependency_node_caches_result(): void
    {
        $callCount = 0;
        $loader = function (string $id) use (&$callCount): ?array {
            $callCount++;
            return ['status' => 'completed_dry_run', 'metadata' => []];
        };
        $cache = [];
        AgentControlPlaneTaskDependencyClassifier::dependencyNode($loader, 'tp1', $cache);
        AgentControlPlaneTaskDependencyClassifier::dependencyNode($loader, 'tp1', $cache);
        self::assertSame(1, $callCount);
    }

    public function test_dependency_node_extracts_status_and_depends_on(): void
    {
        $cache = [];
        $records = ['tp1' => ['status' => 'claimed', 'metadata' => ['depends_on' => ['x', 'y']]]];
        $result = AgentControlPlaneTaskDependencyClassifier::dependencyNode($this->makeLoader($records), 'tp1', $cache);
        self::assertSame('claimed', $result['status']);
        self::assertSame(['x', 'y'], $result['depends_on']);
    }

    public function test_dependency_node_ignores_non_string_deps(): void
    {
        $cache = [];
        $records = ['tp1' => ['status' => 'claimed', 'metadata' => ['depends_on' => ['x', null, 42, 'y']]]];
        $result = AgentControlPlaneTaskDependencyClassifier::dependencyNode($this->makeLoader($records), 'tp1', $cache);
        self::assertSame(['x', 'y'], $result['depends_on']);
    }

    public function test_dependency_reaches_direct_match(): void
    {
        $cache = [];
        $records = ['from' => ['status' => 'queued', 'metadata' => ['depends_on' => ['target']]]];
        self::assertTrue(AgentControlPlaneTaskDependencyClassifier::dependencyReaches(
            $this->makeLoader($records),
            'from',
            'target',
            $cache,
            [],
        ));
    }

    public function test_dependency_reaches_transitive(): void
    {
        $cache = [];
        $records = [
            'a' => ['status' => 'queued', 'metadata' => ['depends_on' => ['b']]],
            'b' => ['status' => 'queued', 'metadata' => ['depends_on' => ['c']]],
            'c' => ['status' => 'queued', 'metadata' => ['depends_on' => ['target']]],
        ];
        self::assertTrue(AgentControlPlaneTaskDependencyClassifier::dependencyReaches(
            $this->makeLoader($records),
            'a',
            'target',
            $cache,
            [],
        ));
    }

    public function test_dependency_reaches_returns_false_when_no_path(): void
    {
        $cache = [];
        $records = [
            'a' => ['status' => 'queued', 'metadata' => ['depends_on' => ['b']]],
            'b' => ['status' => 'queued', 'metadata' => []],
        ];
        self::assertFalse(AgentControlPlaneTaskDependencyClassifier::dependencyReaches(
            $this->makeLoader($records),
            'a',
            'missing',
            $cache,
            [],
        ));
    }

    public function test_dependency_reaches_term_on_cycle(): void
    {
        $cache = [];
        $records = [
            'a' => ['status' => 'queued', 'metadata' => ['depends_on' => ['b']]],
            'b' => ['status' => 'queued', 'metadata' => ['depends_on' => ['a']]],
        ];
        $result = AgentControlPlaneTaskDependencyClassifier::dependencyReaches(
            $this->makeLoader($records),
            'a',
            'target',
            $cache,
            [],
        );
        self::assertFalse($result);
    }

    public function test_dependency_reaches_returns_false_for_absent_node(): void
    {
        $cache = [];
        self::assertFalse(AgentControlPlaneTaskDependencyClassifier::dependencyReaches(
            $this->makeLoader([]),
            'absent',
            'target',
            $cache,
            [],
        ));
    }

    public function test_classify_deeper_transitive_cycle_returns_met(): void
    {
        // tp1 → A → B → C → tp1 (depth-4 cycle): must fail-open to met, not deadlock.
        $cache = [];
        $records = [
            'A' => ['status' => 'claimable', 'metadata' => ['depends_on' => ['B']]],
            'B' => ['status' => 'claimable', 'metadata' => ['depends_on' => ['C']]],
            'C' => ['status' => 'claimable', 'metadata' => ['depends_on' => ['tp1']]],
        ];
        $result = AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $this->makeLoader($records),
            ['task_packet_id' => 'tp1', 'metadata' => ['depends_on' => ['A']]],
            $cache,
        );
        self::assertSame('met', $result, 'deep cycle must fail-open to met');
    }

    public function test_classify_duplicate_dependency_ids_loads_each_once(): void
    {
        // depends_on: ['dep1', 'dep1'] — loader must be called exactly once (cache hit on second).
        $callCount = 0;
        $loader = function (string $id) use (&$callCount): ?array {
            $callCount++;
            return ['status' => 'completed_dry_run', 'metadata' => []];
        };
        $cache = [];
        $result = AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $loader,
            ['task_packet_id' => 'tp1', 'metadata' => ['depends_on' => ['dep1', 'dep1']]],
            $cache,
        );
        self::assertSame('met', $result);
        self::assertSame(1, $callCount, 'duplicate dep id must hit cache on second occurrence — loader called exactly once');
    }

    public function test_classify_classify_path_uses_cache_across_multiple_deps(): void
    {
        // Two deps share one transitive dep — that transitive dep must be loaded once only.
        $callCount = 0;
        $loader = function (string $id) use (&$callCount): ?array {
            $callCount++;

            return match ($id) {
                'A' => ['status' => 'claimable', 'metadata' => ['depends_on' => ['shared']]],
                'B' => ['status' => 'claimable', 'metadata' => ['depends_on' => ['shared']]],
                'shared' => ['status' => 'completed_dry_run', 'metadata' => []],
                default => null,
            };
        };
        $cache = [];
        $result = AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $loader,
            ['task_packet_id' => 'tp1', 'metadata' => ['depends_on' => ['A', 'B']]],
            $cache,
        );
        // A is claimable but A → shared (completed) → cycle check loads 'shared'; B → shared hits cache
        self::assertSame(3, $callCount, 'A, B, shared each loaded once; cache prevents a second shared load');
        self::assertSame('inflight', $result, 'A and B are claimable so result is inflight');
    }

    public function test_satisfied_states_constant_contains_expected(): void
    {
        self::assertContains('completed_dry_run', AgentControlPlaneTaskDependencyClassifier::DEPENDENCY_SATISFIED_STATES);
        self::assertContains('cancelled', AgentControlPlaneTaskDependencyClassifier::DEPENDENCY_SATISFIED_STATES);
    }

    public function test_dead_states_constant_contains_blocked(): void
    {
        self::assertContains('blocked', AgentControlPlaneTaskDependencyClassifier::DEPENDENCY_DEAD_STATES);
    }
}
