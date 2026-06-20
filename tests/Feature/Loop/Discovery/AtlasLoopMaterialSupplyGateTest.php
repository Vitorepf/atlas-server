<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use ReflectionMethod;
use Tests\TestCase;

/**
 * D2 — the MATERIAL-SUPPLY GATE classifier. The driver only governs the rédea; the per-target refactor
 * lanes can still synthesize behaviour-preserving PROXY refactors. This gate (at the enqueue chokepoint)
 * drops a refactor task that carries NO material proof, using the SAME rule the honest scorecard uses —
 * so the loop never GRINDS proxy. These prove the classifier on realistic payloads (no synthetic flag).
 */
final class AtlasLoopMaterialSupplyGateTest extends TestCase
{
    private function isProxy(array $payload): bool
    {
        $refiller = app(AtlasLoopQueueRefiller::class);
        $m = new ReflectionMethod(AtlasLoopQueueRefiller::class, 'isProxyRefactorTask');
        $m->setAccessible(true);
        $task = new AtlasLoopTask();
        $task->payload = $payload;

        return (bool) $m->invoke($refiller, $task);
    }

    private function isCoverage(array $payload): bool
    {
        $refiller = app(AtlasLoopQueueRefiller::class);
        $m = new ReflectionMethod(AtlasLoopQueueRefiller::class, 'taskIsCoverage');
        $m->setAccessible(true);
        $task = new AtlasLoopTask();
        $task->payload = $payload;

        return (bool) $m->invoke($refiller, $task);
    }

    public function test_a_bare_behaviour_preserving_refactor_is_proxy(): void
    {
        // a refactor with a runnable command but NO material proof — exactly what slipped through live.
        $this->assertTrue($this->isProxy([
            'objective_kind' => 'refactor_reduce_complexity',
            'acceptance' => ['commands' => ["./vendor/bin/phpunit 'tests/.../FooTest.php'"]],
        ]));
        $this->assertTrue($this->isProxy(['objective_kind' => 'refactor_extract_class']));
    }

    public function test_a_material_refactor_is_not_proxy(): void
    {
        // revert_recheck (behaviour proof)
        $this->assertFalse($this->isProxy(['objective_kind' => 'refactor_x', 'revert_recheck' => true]));
        // red_required
        $this->assertFalse($this->isProxy(['objective_kind' => 'refactor_x', 'acceptance' => ['red_required' => true]]));
        // the governed self-improvement triple (the live extract_class correctly classified self_improvement)
        $this->assertFalse($this->isProxy([
            'objective_kind' => 'refactor_extract_class',
            'is_self_improvement' => true,
            'acceptance' => ['complexity_proof' => true, 'quality_bar_gate' => true],
        ]));
    }

    public function test_complexity_proof_alone_is_still_proxy_aligned_with_the_scorecard(): void
    {
        // The honest scorecard classifies a refactor with ONLY complexity_proof (no behaviour proof, no
        // governed self-improvement triple) as PROXY. The supply gate MUST agree, or a proxy reaches the
        // queue while the scorecard refuses it — the exact gate↔ruler disagreement the live AFTER run exposed.
        $this->assertTrue($this->isProxy(['objective_kind' => 'refactor_extract_class', 'acceptance' => ['complexity_proof' => true]]));
        // self-improvement marker WITHOUT the full triple (no quality_bar) is also still proxy.
        $this->assertTrue($this->isProxy([
            'objective_kind' => 'refactor_extract_class',
            'is_self_improvement' => true,
            'acceptance' => ['complexity_proof' => true],
        ]));
    }

    public function test_non_refactor_kinds_are_never_proxy(): void
    {
        $this->assertFalse($this->isProxy(['objective_kind' => 'bug_fix']));
        $this->assertFalse($this->isProxy(['objective_kind' => 'feature']));
        $this->assertFalse($this->isProxy(['objective_kind' => 'characterization_test']));
        $this->assertFalse($this->isProxy([])); // no kind => not a proxy refactor
    }

    public function test_coverage_detection(): void
    {
        $this->assertTrue($this->isCoverage(['objective_kind' => 'characterization_test']));
        $this->assertFalse($this->isCoverage(['objective_kind' => 'refactor_reduce_complexity']));
        $this->assertFalse($this->isCoverage(['objective_kind' => 'bug_fix']));
    }
}
