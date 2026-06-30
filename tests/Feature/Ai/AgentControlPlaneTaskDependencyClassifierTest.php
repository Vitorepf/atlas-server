<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\TaskQueue\AgentControlPlaneTaskDependencyClassifier;
use Closure;
use PHPUnit\Framework\TestCase;

final class AgentControlPlaneTaskDependencyClassifierTest extends TestCase
{
    private array $cache = [];

    protected function setUp(): void
    {
        $this->cache = [];
    }

    private function classify(array $candidate, array $nodes = []): string
    {
        $loader = $this->loader($nodes);
        return AgentControlPlaneTaskDependencyClassifier::classifyDependencies($loader, $candidate, $this->cache);
    }

    private function loader(array $nodes): Closure
    {
        return fn(string $id): ?array => $nodes[$id] ?? null;
    }

    private function candidate(string $id, array $deps = []): array
    {
        return ['task_packet_id' => $id, 'metadata' => ['depends_on' => $deps]];
    }

    private function node(string $status, array $deps = []): array
    {
        return ['status' => $status, 'metadata' => ['depends_on' => $deps]];
    }

    // ── AC2: completed_dry_run / cancelled / absent / cyclic → met ───────────

    public function test_completed_dry_run_dependency_is_met(): void
    {
        $r = $this->classify(
            $this->candidate('root', ['dep-a']),
            ['dep-a' => $this->node('completed_dry_run')],
        );

        $this->assertSame('met', $r);
    }

    public function test_cancelled_dependency_is_met(): void
    {
        $r = $this->classify(
            $this->candidate('root', ['dep-a']),
            ['dep-a' => $this->node('cancelled')],
        );

        $this->assertSame('met', $r);
    }

    public function test_absent_dependency_fails_open_as_met(): void
    {
        $r = $this->classify(
            $this->candidate('root', ['dep-missing']),
            [],
        );

        $this->assertSame('met', $r);
    }

    public function test_cyclic_dependency_fails_open_as_met(): void
    {
        // root → dep-b → root (cycle)
        $r = $this->classify(
            $this->candidate('root', ['dep-b']),
            ['dep-b' => $this->node('pending', ['root'])],
        );

        $this->assertSame('met', $r);
    }

    public function test_no_dependencies_is_met(): void
    {
        $r = $this->classify($this->candidate('root'));

        $this->assertSame('met', $r);
    }

    // ── AC3: blocked dependency → blocked (when no inflight present) ──────────

    public function test_blocked_dependency_returns_blocked(): void
    {
        $r = $this->classify(
            $this->candidate('root', ['dep-x']),
            ['dep-x' => $this->node('blocked')],
        );

        $this->assertSame('blocked', $r);
    }

    public function test_blocked_with_completed_still_returns_blocked(): void
    {
        $r = $this->classify(
            $this->candidate('root', ['dep-ok', 'dep-blocked']),
            [
                'dep-ok'      => $this->node('completed_dry_run'),
                'dep-blocked' => $this->node('blocked'),
            ],
        );

        $this->assertSame('blocked', $r);
    }

    // ── AC4: inflight takes precedence over blocked; cache used ──────────────

    public function test_inflight_dependency_returns_inflight(): void
    {
        $r = $this->classify(
            $this->candidate('root', ['dep-live']),
            ['dep-live' => $this->node('accepted')],
        );

        $this->assertSame('inflight', $r);
    }

    public function test_inflight_takes_precedence_over_blocked(): void
    {
        $r = $this->classify(
            $this->candidate('root', ['dep-blocked', 'dep-live']),
            [
                'dep-blocked' => $this->node('blocked'),
                'dep-live'    => $this->node('in_progress'),
            ],
        );

        $this->assertSame('inflight', $r);
    }

    public function test_cache_is_populated_on_first_load(): void
    {
        $calls = 0;
        $loader = function (string $id) use (&$calls): ?array {
            $calls++;
            return ['status' => 'blocked', 'metadata' => ['depends_on' => []]];
        };

        AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $loader,
            $this->candidate('root', ['dep-a']),
            $this->cache,
        );
        // Second classify reuses cache — loader not called again
        AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $loader,
            $this->candidate('root', ['dep-a']),
            $this->cache,
        );

        $this->assertSame(1, $calls, 'nodeLoader should be called only once per unique id across calls sharing the same cache');
    }
}
