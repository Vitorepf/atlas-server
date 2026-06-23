<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PART 2 · Lane A — the CLAIM-HARDENING suite (A1-A5): the concurrency floor that must hold BEFORE the Atlas
 * serves tasks to N clients. Each test reproduces a teardown must-fix (MF-06/16/05/07/12) and pins the fix.
 *
 * A1 (MF-06): the claim lock must be a REAL exclusive OS lock that FAILS CLOSED — never the old
 * `Storage::exists/put` check-then-act that ran the mutation unlocked after a 4s timeout and deleted a
 * foreign lock file in `finally`.
 */
final class AtlasAiSelfConstructionClaimHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @param list<string> $writeSet */
    private function scope(array $writeSet, array $readSet = []): array
    {
        return ['write_set' => $writeSet, 'read_set' => $readSet, 'scope_lock_plan_hash' => 'plan-hash'];
    }

    private function lockPath(): string
    {
        return Storage::disk('local')->path(AgentControlPlaneClaimLeaseRepository::LOCK_PATH);
    }

    public function test_a1_claim_fails_closed_while_the_lock_is_held_elsewhere(): void
    {
        // A short lock timeout so the test does not wait the full budget.
        $repo = new AgentControlPlaneClaimLeaseRepository(null, 0.3);

        $path = $this->lockPath();
        @mkdir(\dirname($path), 0775, true);
        $external = fopen($path, 'c');
        $this->assertIsResource($external);
        $this->assertTrue(flock($external, LOCK_EX | LOCK_NB), 'precondition: an external holder owns the exclusive lock');

        // FAIL-CLOSED: while another process holds the lock, the claim must NOT run the mutation unlocked.
        $res = $repo->claim('packet-1', 'agent-A', $this->scope(['app/X.php']), ['ttl_seconds' => 600]);
        $this->assertNotSame('ok', $res['status'] ?? null, 'claim must not succeed while the lock is held elsewhere');
        $this->assertSame('lock_timeout', $res['reason'] ?? null, 'contention => honest lock_timeout, never a silent unlocked run');

        flock($external, LOCK_UN);
        fclose($external);

        // The packet was NOT consumed by the blocked attempt: a fresh claim now succeeds.
        $ok = $repo->claim('packet-1', 'agent-A', $this->scope(['app/X.php']), ['ttl_seconds' => 600]);
        $this->assertSame('ok', $ok['status'] ?? null, 'after release the packet is still claimable — nothing was mutated under no lock');
    }

    public function test_a1_lock_is_released_after_the_critical_section_and_file_persists(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository(null, 0.3);
        $ok = $repo->claim('packet-real', 'agent-A', $this->scope(['app/Y.php']));
        $this->assertSame('ok', $ok['status'] ?? null);

        $path = $this->lockPath();
        $this->assertFileExists($path, 'the flock target file persists (the old code deleted it in finally)');

        // The lock is free once the critical section ends — only the repo`s own handle ever held it.
        $h = fopen($path, 'c');
        $this->assertTrue(flock($h, LOCK_EX | LOCK_NB), 'lock is released after the critical section');
        flock($h, LOCK_UN);
        fclose($h);
    }

    // --- A2 (MF-16): atomic select+reserve — compare-and-swap claimable->claimed + queue flock fail-closed ---

    private function enqueueClaimable(AgentControlPlaneTaskPacketQueueRepository $queue, string $id): void
    {
        $res = $queue->enqueue([
            'task_packet_id' => $id,
            'task_packet_hash' => hash('sha256', $id),
            'status' => 'planned', // planned => initial queue status 'claimable'
        ]);
        $this->assertSame('claimable', $res['record_status'] ?? null, 'precondition: packet enqueued as claimable');
    }

    private function queueLockPath(): string
    {
        return Storage::disk('local')->path(AgentControlPlaneTaskPacketQueueRepository::LOCK_PATH);
    }

    public function test_a2_compare_and_swap_status_serves_exactly_one_winner(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository(null, 0.3);
        $id = 'packet-cas-'.Str::uuid();
        $this->enqueueClaimable($queue, $id);

        $meta = ['lease_id' => 'lease-1', 'agent_id' => 'agent-A'];

        // First client wins the reservation.
        $first = $queue->compareAndSwapStatus($id, 'claimable', 'claimed', $meta);
        $this->assertTrue($first['swapped'] ?? false, 'first CAS claimable->claimed wins');
        $this->assertSame('claimed', $first['record_status'] ?? null);

        // Second client (stale selection of the same packet) loses — never a double reservation.
        $second = $queue->compareAndSwapStatus($id, 'claimable', 'claimed', ['lease_id' => 'lease-2', 'agent_id' => 'agent-B']);
        $this->assertFalse($second['swapped'] ?? true, 'second CAS on the same packet must NOT swap (one winner)');
        $this->assertSame('cas_status_mismatch', $second['reason'] ?? null);
    }

    public function test_a2_compare_and_swap_requires_claim_transition_metadata(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository(null, 0.3);
        $id = 'packet-cas-meta-'.Str::uuid();
        $this->enqueueClaimable($queue, $id);

        // claimed transition still requires lease_id + agent_id — the contract is not weakened by CAS.
        $res = $queue->compareAndSwapStatus($id, 'claimable', 'claimed', []);
        $this->assertFalse($res['swapped'] ?? true);
        $this->assertSame('transition_metadata_missing', $res['reason'] ?? null);
    }

    public function test_a2_queue_mutation_fails_closed_under_external_lock(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository(null, 0.3);

        $path = $this->queueLockPath();
        @mkdir(\dirname($path), 0775, true);
        $external = fopen($path, 'c');
        $this->assertTrue(flock($external, LOCK_EX | LOCK_NB), 'precondition: external holder owns the queue lock');

        // FAIL-CLOSED: while the queue lock is held, a mutation returns queue_lock_busy and does NOT write.
        $res = $queue->enqueue([
            'task_packet_id' => 'packet-blocked',
            'task_packet_hash' => hash('sha256', 'packet-blocked'),
            'status' => 'planned',
        ]);
        $this->assertSame('queue_lock_busy', $res['reason'] ?? null, 'queue mutation must fail closed under contention');

        flock($external, LOCK_UN);
        fclose($external);

        // After release, the same enqueue succeeds (nothing was half-written under contention).
        $ok = $queue->enqueue([
            'task_packet_id' => 'packet-blocked',
            'task_packet_hash' => hash('sha256', 'packet-blocked'),
            'status' => 'planned',
        ]);
        $this->assertSame('claimable', $ok['record_status'] ?? null);
    }

    // --- A3 (MF-05): the scheduled reaper command is wired and runs green ---------------------------------

    public function test_a3_reap_leases_command_runs_green(): void
    {
        // The command must exist (auto-discovered) and run without error even on an empty registry.
        $exit = Artisan::call('atlas:acp:reap-leases', ['--json' => true]);
        $this->assertSame(0, $exit, 'atlas:acp:reap-leases is wired and runs');
        $this->assertStringContainsString('"ok": true', Artisan::output());
    }
}
