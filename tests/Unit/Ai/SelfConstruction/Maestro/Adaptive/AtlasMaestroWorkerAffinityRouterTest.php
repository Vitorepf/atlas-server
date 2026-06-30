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

    // ── routePacket(): lane affinity ───────────────────────────────────────────

    public function test_route_packet_prefers_worker_with_proven_lane_affinity(): void
    {
        // claude-1 has lane:final-brain affinity; codex-1 has only task_class success.
        for ($i = 0; $i < 5; $i++) {
            $this->ledger->record('success', 'claude-1', 'lane:final-brain');
        }
        for ($i = 0; $i < 10; $i++) {
            $this->ledger->record('success', 'codex-1', 'wiring');
        }

        $out = $this->router()->routePacket(
            ['task_class' => 'wiring', 'lane' => 'final-brain', 'allowed_files' => []],
            ['claude-1', 'codex-1'],
        );

        $this->assertSame(AtlasMaestroWorkerAffinityRouter::ROUTED, $out['status']);
        $this->assertSame('claude-1', $out['worker'], 'worker with lane affinity must win over higher task_class success');
        $this->assertSame('lane:final-brain', $out['routing_key']);
    }

    public function test_route_packet_falls_back_to_task_class_when_no_lane_evidence(): void
    {
        // No lane evidence; codex-1 has task_class success.
        for ($i = 0; $i < 4; $i++) {
            $this->ledger->record('success', 'codex-1', 'wiring');
        }

        $out = $this->router()->routePacket(
            ['task_class' => 'wiring', 'lane' => 'final-brain', 'allowed_files' => []],
            ['codex-1'],
        );

        $this->assertSame(AtlasMaestroWorkerAffinityRouter::ROUTED, $out['status']);
        $this->assertSame('codex-1', $out['worker']);
        $this->assertSame('wiring', $out['routing_key']);
    }

    // ── routePacket(): allowed_files conflict guard ───────────────────────────

    public function test_route_packet_refuses_worker_with_conflicting_active_claim(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->ledger->record('success', 'claude-1', 'lane:final-brain');
        }

        $conflictFile = 'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasFoo.php';

        $out = $this->router()->routePacket(
            ['task_class' => 'wiring', 'lane' => 'final-brain', 'allowed_files' => [$conflictFile]],
            ['claude-1'],
            [['worker_id' => 'claude-1', 'claimed_files' => [$conflictFile]]],
        );

        $this->assertSame(AtlasMaestroWorkerAffinityRouter::ROUTE_CONFLICT, $out['status']);
        $this->assertContains('claude-1', $out['conflict_workers']);
    }

    public function test_route_packet_excludes_conflicting_worker_but_routes_to_clean_worker(): void
    {
        $conflictFile = 'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasFoo.php';

        // claude-1 has a conflict; codex-1 does not.
        for ($i = 0; $i < 4; $i++) {
            $this->ledger->record('success', 'codex-1', 'wiring');
        }

        $out = $this->router()->routePacket(
            ['task_class' => 'wiring', 'allowed_files' => [$conflictFile]],
            ['claude-1', 'codex-1'],
            [['worker_id' => 'claude-1', 'claimed_files' => [$conflictFile]]],
        );

        $this->assertSame(AtlasMaestroWorkerAffinityRouter::ROUTED, $out['status']);
        $this->assertSame('codex-1', $out['worker'], 'conflicting worker must be excluded; clean worker routed');
        $this->assertContains('claude-1', $out['conflict_workers']);
    }

    public function test_route_packet_route_conflict_when_all_workers_have_conflict(): void
    {
        $conflictFile = 'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasBar.php';

        $out = $this->router()->routePacket(
            ['task_class' => 'wiring', 'allowed_files' => [$conflictFile]],
            ['claude-1', 'codex-1'],
            [
                ['worker_id' => 'claude-1', 'claimed_files' => [$conflictFile]],
                ['worker_id' => 'codex-1',  'claimed_files' => [$conflictFile]],
            ],
        );

        $this->assertSame(AtlasMaestroWorkerAffinityRouter::ROUTE_CONFLICT, $out['status']);
        $this->assertCount(2, $out['conflict_workers']);
    }

    public function test_route_packet_no_conflict_when_claimed_files_do_not_overlap(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->ledger->record('success', 'claude-1', 'wiring');
        }

        $out = $this->router()->routePacket(
            ['task_class' => 'wiring', 'allowed_files' => ['app/Services/Foo.php']],
            ['claude-1'],
            [['worker_id' => 'claude-1', 'claimed_files' => ['app/Services/Bar.php']]],
        );

        $this->assertSame(AtlasMaestroWorkerAffinityRouter::ROUTED, $out['status']);
        $this->assertSame('claude-1', $out['worker']);
        $this->assertSame([], $out['conflict_workers']);
    }

    // ── risk tier routing ──────────────────────────────────────────────────────

    public function test_route_packet_considers_risk_tier_before_task_class(): void
    {
        // codex-1 has risk:high affinity; claude-1 only has task_class success.
        for ($i = 0; $i < 4; $i++) {
            $this->ledger->record('success', 'codex-1', 'risk:high');
        }
        for ($i = 0; $i < 10; $i++) {
            $this->ledger->record('success', 'claude-1', 'wiring');
        }

        $out = $this->router()->routePacket(
            ['task_class' => 'wiring', 'risk_level' => 'high', 'allowed_files' => []],
            ['claude-1', 'codex-1'],
        );

        $this->assertSame(AtlasMaestroWorkerAffinityRouter::ROUTED, $out['status']);
        $this->assertSame('codex-1', $out['worker'], 'risk tier affinity must beat higher task_class success');
        $this->assertSame('risk:high', $out['routing_key']);
    }

    // ── task family routing ────────────────────────────────────────────────────

    public function test_route_packet_considers_task_family_before_task_class(): void
    {
        // claude-1 has family:native affinity; codex-1 only has task_class success.
        for ($i = 0; $i < 4; $i++) {
            $this->ledger->record('success', 'claude-1', 'family:native');
        }
        for ($i = 0; $i < 10; $i++) {
            $this->ledger->record('success', 'codex-1', 'wiring');
        }

        $out = $this->router()->routePacket(
            ['task_class' => 'wiring', 'task_family' => 'native', 'allowed_files' => []],
            ['claude-1', 'codex-1'],
        );

        $this->assertSame(AtlasMaestroWorkerAffinityRouter::ROUTED, $out['status']);
        $this->assertSame('claude-1', $out['worker'], 'task family affinity must beat higher task_class success');
        $this->assertSame('family:native', $out['routing_key']);
    }

    // ── active claim pressure ──────────────────────────────────────────────────

    public function test_route_packet_abstains_when_all_workers_overloaded(): void
    {
        config(['atlas.maestro.adaptive.max_active_claims' => 2]);

        // Each worker has 2 active claims → at the threshold → overloaded.
        $out = $this->router()->routePacket(
            ['task_class' => 'wiring', 'allowed_files' => ['app/X.php']],
            ['claude-1', 'codex-1'],
            [
                ['worker_id' => 'claude-1', 'claimed_files' => ['app/A.php']],
                ['worker_id' => 'claude-1', 'claimed_files' => ['app/B.php']],
                ['worker_id' => 'codex-1',  'claimed_files' => ['app/C.php']],
                ['worker_id' => 'codex-1',  'claimed_files' => ['app/D.php']],
            ],
        );

        $this->assertSame(AtlasMaestroWorkerAffinityRouter::ROUTE_ABSTAIN, $out['status']);
        $this->assertSame('all_eligible_workers_overloaded', $out['reason']);
        $this->assertContains('claude-1', $out['overloaded_workers']);
        $this->assertContains('codex-1',  $out['overloaded_workers']);
    }

    public function test_route_packet_routes_to_clean_worker_when_other_is_overloaded(): void
    {
        config(['atlas.maestro.adaptive.max_active_claims' => 2]);

        // codex-1 has 4 task_class successes; claude-1 is overloaded.
        for ($i = 0; $i < 4; $i++) {
            $this->ledger->record('success', 'codex-1', 'wiring');
        }

        $out = $this->router()->routePacket(
            ['task_class' => 'wiring', 'allowed_files' => ['app/X.php']],
            ['claude-1', 'codex-1'],
            [
                ['worker_id' => 'claude-1', 'claimed_files' => ['app/A.php']],
                ['worker_id' => 'claude-1', 'claimed_files' => ['app/B.php']],
            ],
        );

        $this->assertSame(AtlasMaestroWorkerAffinityRouter::ROUTED, $out['status']);
        $this->assertSame('codex-1', $out['worker'], 'overloaded worker must be excluded; clean worker routed');
        $this->assertContains('claude-1', $out['overloaded_workers']);
    }
}
