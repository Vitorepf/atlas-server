<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use Illuminate\Support\Facades\Storage;
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
}
