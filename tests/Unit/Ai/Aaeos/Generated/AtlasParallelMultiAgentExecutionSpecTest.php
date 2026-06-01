<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasParallelMultiAgentExecutionSpecService;
use Tests\TestCase;

/**
 * Pins the documented FIVE-invariant durable-parallelism rules.
 *
 * @see docs/engineering-knowledge-base/atlas-parallel-multi-agent-execution-spec.md
 */
class AtlasParallelMultiAgentExecutionSpecTest extends TestCase
{
    private function service(): AtlasParallelMultiAgentExecutionSpecService
    {
        return new AtlasParallelMultiAgentExecutionSpecService();
    }

    /**
     * @return array<string,mixed> a fully-compliant R4 run: 2 distinct agents,
     * 2 worktrees, 0 collision, gates green, architect approval.
     */
    private function compliantR4(): array
    {
        return [
            'ring' => 4,
            'parallelism_mode' => 'parallel_durable',
            'now' => 1000,
            'reservations' => [
                ['agent_id' => 'agent-a', 'scope' => ['paths' => ['app/A.php']], 'state' => 'active',
                    'lease' => ['acquired_at' => 900, 'ttl_seconds' => 1800, 'renew_at' => 1800]],
                ['agent_id' => 'agent-b', 'scope' => ['paths' => ['app/B.php']], 'state' => 'active',
                    'lease' => ['acquired_at' => 900, 'ttl_seconds' => 1800, 'renew_at' => 1800]],
            ],
            'worktrees' => [
                ['agent_id' => 'agent-a'],
                ['agent_id' => 'agent-b'],
            ],
            'collision_report' => ['collisions' => [], 'auto_resolvable' => true, 'blocking' => false],
            'gates_green' => true,
            'review' => ['reviewer' => ['kind' => 'architect_agent', 'id' => 'arch-1'], 'decision' => 'approve'],
        ];
    }

    /**
     * Doc Exemplos: "3 agentes paralelos em Obra R4: ... 0 collision, 3 review
     * approvals, 3 merges". A fully-compliant R4 run is parallel_safe and merges.
     */
    public function test_compliant_r4_run_is_parallel_safe_and_mergeable(): void
    {
        $r = $this->service()->assess($this->compliantR4());

        $this->assertTrue($r['parallel_safe']);
        $this->assertSame(AtlasParallelMultiAgentExecutionSpecService::DECISION_RUN, $r['decision']);
        $this->assertSame([], $r['missing_invariants']);
        $this->assertSame(5, $r['summary']['invariants_satisfied']);
        $this->assertSame(5, $r['summary']['invariants_total']);
        $this->assertTrue($r['merge_promotion']['merge_allowed']);
    }

    /**
     * Invariant #2: "Worktree-per-agent mandatorio para R3+". Same run at R3
     * with NO worktrees => worktree invariant fails, run blocked.
     */
    public function test_ring3_without_worktree_blocks(): void
    {
        $run = $this->compliantR4();
        $run['ring'] = 3;
        $run['worktrees'] = []; // mandatory at R3+, missing
        // R3 needs no human review, so drop it to isolate the worktree rule.
        unset($run['review']);

        $r = $this->service()->assess($run);

        $this->assertFalse($r['parallel_safe']);
        $this->assertContains('worktree_per_agent', $r['missing_invariants']);
        $codes = array_column($r['violations'], 'code');
        $this->assertContains('worktree_missing_r3_plus', $codes);
    }

    /**
     * Counter-check: at R2 a missing worktree is allowed ("R1-R2 podem rodar
     * single tree"). Same run at R2 with no worktrees stays safe.
     */
    public function test_ring2_without_worktree_is_allowed(): void
    {
        $run = $this->compliantR4();
        $run['ring'] = 2;
        $run['worktrees'] = [];
        unset($run['review']); // R2 < R4 => no review required

        $r = $this->service()->assess($run);

        $this->assertTrue($r['worktree_report']['ok']);
        $this->assertNotContains('worktree_per_agent', $r['missing_invariants']);
        $this->assertTrue($r['parallel_safe']);
    }

    /**
     * Invariant #5: "R4+ sempre review". A compliant R4 run missing the review
     * is blocked with merge_review_missing; merge not allowed.
     */
    public function test_ring4_without_review_blocks_merge(): void
    {
        $run = $this->compliantR4();
        unset($run['review']);

        $r = $this->service()->assess($run);

        $this->assertFalse($r['parallel_safe']);
        $this->assertContains('merge_review_promotion', $r['missing_invariants']);
        $this->assertFalse($r['merge_promotion']['merge_allowed']);
        $codes = array_column($r['merge_promotion']['violations'], 'code');
        $this->assertContains('merge_review_missing', $codes);
    }

    /**
     * Rule: "Cada agente recebe UMA reservation; subleasing proibido." Two
     * ACTIVE reservations for the same agent => agent_double_reservation fatal.
     */
    public function test_double_active_reservation_for_same_agent_is_fatal(): void
    {
        $res = [
            ['agent_id' => 'agent-x', 'scope' => ['paths' => ['app/A.php']], 'state' => 'active',
                'lease' => ['acquired_at' => 900, 'ttl_seconds' => 1800, 'renew_at' => 1800]],
            ['agent_id' => 'agent-x', 'scope' => ['paths' => ['app/B.php']], 'state' => 'active',
                'lease' => ['acquired_at' => 900, 'ttl_seconds' => 1800, 'renew_at' => 1800]],
        ];

        $report = $this->service()->assessReservations($res, 1800, 1000);

        $this->assertFalse($report['append_only_ok']);
        $codes = array_column($report['fatal'], 'code');
        $this->assertContains('agent_double_reservation', $codes);
        $this->assertSame(2, $report['active_per_agent']['agent-x']);
    }

    /**
     * Lease lifecycle: "TTL default 30min" (1800s). A lease acquired 1900s ago
     * with default TTL and no future renew is EXPIRED; a lease 1600s old (inside
     * the last 20% window) and not renewed is renew_required.
     */
    public function test_lease_ttl_expiry_and_renew_window(): void
    {
        $svc = $this->service();

        $expired = $svc->assessLease(
            ['state' => 'active', 'lease' => ['acquired_at' => 0, 'ttl_seconds' => 1800]],
            1800,
            1900 // age 1900 >= ttl 1800
        );
        $this->assertTrue($expired['expired']);
        $this->assertSame('expired', $expired['effective_state']);

        $renew = $svc->assessLease(
            ['state' => 'active', 'lease' => ['acquired_at' => 0, 'ttl_seconds' => 1800]],
            1800,
            1600 // remaining 200 <= 20% of 1800 (360), not renewed
        );
        $this->assertTrue($renew['renew_required']);
        $this->assertFalse($renew['expired']);

        // A future renew_at clears the renew flag.
        $renewed = $svc->assessLease(
            ['state' => 'active', 'lease' => ['acquired_at' => 0, 'ttl_seconds' => 1800, 'renew_at' => 9999]],
            1800,
            1600
        );
        $this->assertFalse($renewed['renew_required']);
    }

    /**
     * Append-only: a lease whose declared state is still 'active' but carries a
     * released_at timestamp is an illegal in-place mutation.
     */
    public function test_active_lease_with_released_at_is_illegal_transition(): void
    {
        $a = $this->service()->assessLease(
            ['state' => 'active', 'lease' => ['acquired_at' => 0, 'released_at' => '2026-06-01T00:00:00Z']],
            1800,
            10
        );

        $this->assertFalse($a['state_transition_legal']);
        $this->assertSame('released', $a['to_state']);
    }

    /**
     * Invariant #4: "Collision detectada bloqueia merge ate resolver." A path
     * collision that is not auto_resolvable is blocking; an empty report is not.
     */
    public function test_collision_blocks_and_empty_report_does_not(): void
    {
        $svc = $this->service();

        $blocking = $svc->assessCollision([
            'collisions' => [['kind' => 'path', 'ref' => 'app/A.php', 'agents' => ['a', 'b']]],
            'auto_resolvable' => false,
        ]);
        $this->assertTrue($blocking['blocking']);
        $this->assertSame(1, $blocking['by_kind']['path']);

        $clean = $svc->assessCollision(['collisions' => []]);
        $this->assertFalse($clean['blocking']);
        $this->assertFalse($clean['has_collision']);
    }

    /**
     * Full gate: a blocking collision sinks the collision invariant AND prevents
     * merge even on an otherwise-perfect R4 run.
     */
    public function test_blocking_collision_blocks_whole_run(): void
    {
        $run = $this->compliantR4();
        $run['collision_report'] = [
            'collisions' => [['kind' => 'migration', 'ref' => '2026_01_01_000000', 'agents' => ['agent-a', 'agent-b']]],
            'auto_resolvable' => false,
            'blocking' => true,
        ];

        $r = $this->service()->assess($run);

        $this->assertFalse($r['parallel_safe']);
        $this->assertContains('collision_guard', $r['missing_invariants']);
        $this->assertFalse($r['merge_promotion']['merge_allowed']);
    }

    /**
     * Invariant #1: a run with NO reservations declared can never be safe — the
     * durable ledger is mandatory before any parallelism.
     */
    public function test_no_reservations_means_ledger_missing(): void
    {
        $r = $this->service()->assess(['ring' => 1, 'reservations' => []]);

        $this->assertFalse($r['parallel_safe']);
        $this->assertContains('durable_reservation_ledger', $r['missing_invariants']);
        $codes = array_column($r['violations'], 'code');
        $this->assertContains('reservation_ledger_missing', $codes);
    }
}
