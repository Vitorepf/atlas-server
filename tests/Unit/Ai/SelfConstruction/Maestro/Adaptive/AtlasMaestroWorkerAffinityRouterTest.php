<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Adaptive;

use App\Services\Ai\SelfConstruction\Maestro\Adaptive\AtlasMaestroWorkerAffinityRouter;
use App\Services\Ai\SelfConstruction\Maestro\Adaptive\AtlasMaestroWorkerBehaviorLedger;
use Tests\TestCase;

/**
 * Proves the Maestro worker-affinity router: highest-success selection above the floor, abstention below it,
 * the exact tie-break order, and the subset-of-eligible invariant — all over a real (seeded) behavior ledger.
 */
final class AtlasMaestroWorkerAffinityRouterTest extends TestCase
{
    private string $path;

    private AtlasMaestroWorkerBehaviorLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        config(['atlas.maestro.adaptive.behavior_ledger_enabled' => true]);
        $this->path = sys_get_temp_dir().'/atlas-maestro-aff-'.bin2hex(random_bytes(6)).'.jsonl';
        $this->ledger = new AtlasMaestroWorkerBehaviorLedger($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function seedWorker(string $client, string $class, int $success, int $giveBack = 0): void
    {
        for ($i = 0; $i < $success; $i++) {
            $this->ledger->record('success', $client, $class);
        }
        for ($i = 0; $i < $giveBack; $i++) {
            $this->ledger->record('give_back', $client, $class);
        }
    }

    private function router(): AtlasMaestroWorkerAffinityRouter
    {
        return new AtlasMaestroWorkerAffinityRouter($this->ledger);
    }

    public function test_picks_the_worker_with_the_highest_success_above_the_floor(): void
    {
        $this->seedWorker('claude-1', 'wiring', 5);
        $this->seedWorker('codex-1', 'wiring', 3);

        $out = $this->router()->route('wiring', ['claude-1', 'codex-1']);

        $this->assertSame(AtlasMaestroWorkerAffinityRouter::ROUTED, $out['status']);
        $this->assertSame('claude-1', $out['worker']);
        $this->assertSame(['claude-1', 'codex-1'], $out['ordered']);
    }

    public function test_abstains_below_the_min_success_floor(): void
    {
        // both have 2 successes; router_min_success default is 3 ⇒ boundary below the floor.
        $this->seedWorker('claude-1', 'wiring', 2);
        $this->seedWorker('codex-1', 'wiring', 2);

        $out = $this->router()->route('wiring', ['claude-1', 'codex-1']);

        $this->assertSame(AtlasMaestroWorkerAffinityRouter::ROUTE_ABSTAIN, $out['status']);
        $this->assertSame('insufficient_success_evidence', $out['reason']);
        $this->assertArrayNotHasKey('worker', $out);
    }

    public function test_tie_break_is_give_back_then_client_id(): void
    {
        // equal success (4) ⇒ lower give_back wins; codex-1 has 0, claude-1 has 2.
        $this->seedWorker('claude-1', 'wiring', 4, giveBack: 2);
        $this->seedWorker('codex-1', 'wiring', 4, giveBack: 0);

        $out = $this->router()->route('wiring', ['claude-1', 'codex-1']);

        $this->assertSame('codex-1', $out['worker'], 'tie on success ⇒ lower give_back wins');
        $this->assertSame(['codex-1', 'claude-1'], $out['ordered']);
    }

    public function test_output_is_a_subset_of_eligible_workers(): void
    {
        $this->seedWorker('claude-1', 'wiring', 5);
        $eligible = ['claude-1', 'codex-1'];

        $out = $this->router()->route('wiring', $eligible);

        $this->assertContains($out['worker'], $eligible, 'router never picks outside the eligible set');
        $this->assertEmpty(array_diff($out['ordered'], $eligible), 'ordered ⊆ eligible');
    }
}
