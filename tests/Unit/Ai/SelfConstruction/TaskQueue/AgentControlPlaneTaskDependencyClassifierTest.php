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

    public function test_classify_absent_dependency_is_blocked(): void
    {
        $cache = [];
        $result = AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $this->makeLoader([]),
            ['task_packet_id' => 'tp1', 'metadata' => ['depends_on' => ['nonexistent_dep']]],
            $cache,
        );
        self::assertSame('blocked', $result);
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

    public function test_classify_cancelled_dependency_is_blocked(): void
    {
        $cache = [];
        $result = AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $this->makeLoader(['dep1' => ['status' => 'cancelled', 'metadata' => []]]),
            ['task_packet_id' => 'tp1', 'metadata' => ['depends_on' => ['dep1']]],
            $cache,
        );
        self::assertSame('blocked', $result);
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

    public function test_classify_cycle_returns_blocked(): void
    {
        $cache = [];
        $records = ['dep1' => ['status' => 'claimable', 'metadata' => ['depends_on' => ['tp1']]]];
        $result = AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $this->makeLoader($records),
            ['task_packet_id' => 'tp1', 'metadata' => ['depends_on' => ['dep1']]],
            $cache,
        );
        self::assertSame('blocked', $result);
    }

    public function test_classify_ignores_non_string_dependencies_but_blocks_a_missing_string_dependency(): void
    {
        $cache = [];
        $result = AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $this->makeLoader([]),
            ['task_packet_id' => 'tp1', 'metadata' => ['depends_on' => [null, 42, 'string_dep']]],
            $cache,
        );
        self::assertSame('blocked', $result);
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

    public function test_classify_deeper_transitive_cycle_returns_blocked(): void
    {
        // tp1 → A → B → C → tp1 (depth-4 cycle): no valid prerequisite order may be served.
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
        self::assertSame('blocked', $result, 'deep cycle must fail closed');
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

    public function test_satisfied_states_constant_contains_only_completed_state(): void
    {
        self::assertContains('completed_dry_run', AgentControlPlaneTaskDependencyClassifier::DEPENDENCY_SATISFIED_STATES);
        self::assertNotContains('cancelled', AgentControlPlaneTaskDependencyClassifier::DEPENDENCY_SATISFIED_STATES);
    }

    public function test_dead_states_constant_contains_blocked(): void
    {
        self::assertContains('blocked', AgentControlPlaneTaskDependencyClassifier::DEPENDENCY_DEAD_STATES);
        self::assertContains('cancelled', AgentControlPlaneTaskDependencyClassifier::DEPENDENCY_DEAD_STATES);
    }

    // ── AC: classify() verdicts ──────────────────────────────────────────────

    private function runnableCandidate(array $overrides = []): array
    {
        return array_replace([
            'task_packet_id' => 'tp1',
            'metadata' => ['depends_on' => []],
            'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'acceptance_criteria' => ['phpunit tests/Unit/FooTest.php exits 0'],
        ], $overrides);
    }

    public function test_classify_ready_when_deps_met_scope_sufficient_acceptance_runnable(): void
    {
        $cache = [];
        $result = AgentControlPlaneTaskDependencyClassifier::classify(
            $this->makeLoader([]),
            $this->runnableCandidate(),
            $cache,
        );

        self::assertSame('ready', $result['verdict']);
        self::assertSame('dependencies_met_scope_sufficient_acceptance_runnable', $result['reason']);
    }

    public function test_classify_waiting_when_upstream_inflight(): void
    {
        $cache = [];
        $candidate = $this->runnableCandidate(['metadata' => ['depends_on' => ['dep1']]]);
        $result = AgentControlPlaneTaskDependencyClassifier::classify(
            $this->makeLoader(['dep1' => ['status' => 'claimable', 'metadata' => []]]),
            $candidate,
            $cache,
        );

        self::assertSame('waiting', $result['verdict']);
        self::assertSame('upstream_dependency_inflight', $result['reason']);
    }

    public function test_classify_blocked_when_upstream_dead(): void
    {
        $cache = [];
        $candidate = $this->runnableCandidate(['metadata' => ['depends_on' => ['dep1']]]);
        $result = AgentControlPlaneTaskDependencyClassifier::classify(
            $this->makeLoader(['dep1' => ['status' => 'blocked', 'metadata' => []]]),
            $candidate,
            $cache,
        );

        self::assertSame('blocked', $result['verdict']);
        self::assertSame('upstream_dependency_blocked', $result['reason']);
    }

    public function test_classify_blocked_when_allowed_files_insufficient(): void
    {
        $cache = [];
        // No allowed_files at all — acceptance cannot be verified against any file.
        $candidate = $this->runnableCandidate([
            'allowed_files' => [],
        ]);
        $result = AgentControlPlaneTaskDependencyClassifier::classify(
            $this->makeLoader([]),
            $candidate,
            $cache,
        );

        self::assertSame('blocked', $result['verdict']);
        self::assertSame('allowed_files_insufficient_for_acceptance', $result['reason']);
    }

    public function test_classify_blocked_when_no_runnable_acceptance(): void
    {
        $cache = [];
        $candidate = $this->runnableCandidate([
            'acceptance_criteria' => ['code looks good'],
        ]);
        $result = AgentControlPlaneTaskDependencyClassifier::classify(
            $this->makeLoader([]),
            $candidate,
            $cache,
        );

        self::assertSame('blocked', $result['verdict']);
    }

    public function test_classify_poison_for_test_only_packet(): void
    {
        $cache = [];
        $candidate = $this->runnableCandidate([
            'allowed_files' => ['tests/Unit/FooTest.php', 'tests/Feature/FooTest.php'],
        ]);
        $result = AgentControlPlaneTaskDependencyClassifier::classify(
            $this->makeLoader([]),
            $candidate,
            $cache,
        );

        self::assertSame('poison', $result['verdict']);
        self::assertSame('test_only_packet_no_implementation', $result['reason']);
    }

    public function test_classify_poison_for_forbidden_self_target(): void
    {
        $cache = [];
        $candidate = $this->runnableCandidate([
            'packet_quality' => [
                'facts' => ['forbidden_self_targets' => ['app/HotScope.php']],
                'deficiencies' => [],
            ],
        ]);
        $result = AgentControlPlaneTaskDependencyClassifier::classify(
            $this->makeLoader([]),
            $candidate,
            $cache,
        );

        self::assertSame('poison', $result['verdict']);
        self::assertSame('forbidden_self_target', $result['reason']);
    }

    public function test_classify_poison_for_forbidden_self_target_deficiency(): void
    {
        $cache = [];
        $candidate = $this->runnableCandidate([
            'packet_quality' => [
                'facts' => [],
                'deficiencies' => ['forbidden_self_target'],
            ],
        ]);
        $result = AgentControlPlaneTaskDependencyClassifier::classify(
            $this->makeLoader([]),
            $candidate,
            $cache,
        );

        self::assertSame('poison', $result['verdict']);
    }

    public function test_classify_poison_for_impossible_acceptance(): void
    {
        $cache = [];
        $candidate = $this->runnableCandidate([
            'acceptance_criteria' => ['phpunit must pass AND fail simultaneously'],
        ]);
        $result = AgentControlPlaneTaskDependencyClassifier::classify(
            $this->makeLoader([]),
            $candidate,
            $cache,
        );

        self::assertSame('poison', $result['verdict']);
        self::assertSame('impossible_or_contradictory_acceptance', $result['reason']);
    }

    public function test_classify_poison_for_contradictory_acceptance_deficiency(): void
    {
        $cache = [];
        $candidate = $this->runnableCandidate([
            'packet_quality' => [
                'facts' => [],
                'deficiencies' => ['contradictory_acceptance'],
            ],
        ]);
        $result = AgentControlPlaneTaskDependencyClassifier::classify(
            $this->makeLoader([]),
            $candidate,
            $cache,
        );

        self::assertSame('poison', $result['verdict']);
    }

    public function test_classify_operator_only_for_human_action_requirement(): void
    {
        $cache = [];
        $candidate = $this->runnableCandidate([
            'metadata' => ['depends_on' => [], 'requires_human_review' => true],
        ]);
        $result = AgentControlPlaneTaskDependencyClassifier::classify(
            $this->makeLoader([]),
            $candidate,
            $cache,
        );

        self::assertSame('operator_only', $result['verdict']);
        self::assertSame('requires_human_action', $result['reason']);
    }

    public function test_classify_operator_only_in_objective(): void
    {
        $cache = [];
        $candidate = $this->runnableCandidate([
            'objective' => 'This task requires manual_approval from the operator.',
        ]);
        $result = AgentControlPlaneTaskDependencyClassifier::classify(
            $this->makeLoader([]),
            $candidate,
            $cache,
        );

        self::assertSame('operator_only', $result['verdict']);
    }

    public function test_classify_operator_only_takes_precedence_over_poison(): void
    {
        $cache = [];
        $candidate = $this->runnableCandidate([
            'metadata' => ['depends_on' => [], 'requires_human' => true],
            'packet_quality' => [
                'facts' => ['forbidden_self_targets' => ['app/Hot.php']],
                'deficiencies' => [],
            ],
        ]);
        $result = AgentControlPlaneTaskDependencyClassifier::classify(
            $this->makeLoader([]),
            $candidate,
            $cache,
        );

        self::assertSame('operator_only', $result['verdict']);
    }

    public function test_classify_poison_takes_precedence_over_blocked(): void
    {
        $cache = [];
        $candidate = $this->runnableCandidate([
            'metadata' => ['depends_on' => ['dead_dep']],
            'packet_quality' => [
                'facts' => ['forbidden_self_targets' => ['app/Hot.php']],
                'deficiencies' => [],
            ],
        ]);
        $result = AgentControlPlaneTaskDependencyClassifier::classify(
            $this->makeLoader(['dead_dep' => ['status' => 'blocked', 'metadata' => []]]),
            $candidate,
            $cache,
        );

        self::assertSame('poison', $result['verdict']);
    }

    public function test_classify_ready_when_upstream_completed(): void
    {
        $cache = [];
        $candidate = $this->runnableCandidate(['metadata' => ['depends_on' => ['dep1']]]);
        $result = AgentControlPlaneTaskDependencyClassifier::classify(
            $this->makeLoader(['dep1' => ['status' => 'completed_dry_run', 'metadata' => []]]),
            $candidate,
            $cache,
        );

        self::assertSame('ready', $result['verdict']);
    }
}
