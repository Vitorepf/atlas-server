<?php

namespace Tests\Feature\Ai\Programming\Forge;

use App\Services\Ai\Programming\Forge\ForgeScopeReservationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ForgeScopeReservationServiceTest extends TestCase
{
    private ForgeScopeReservationService $reservations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reservationMigration()->up();
        $this->reservations = app(ForgeScopeReservationService::class);
        Carbon::setTestNow('2026-07-11 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->reservationMigration()->down();
        parent::tearDown();
    }

    public function test_competing_acquire_has_exactly_one_active_owner(): void
    {
        $first = $this->acquire('run-a', 'worker-a', 'token-a', 'idem-a');
        $second = $this->reservations->acquire(
            runId: 'run-b', scopePath: './app//Services/Ai/Programming/Forge', mode: 'real',
            leaseOwner: 'worker-b', leaseToken: 'token-b', authorityHash: str_repeat('b', 64),
            baselineHash: str_repeat('c', 64), idempotencyKey: 'idem-b', leaseSeconds: 60,
        );

        $this->assertTrue($first['acquired']);
        $this->assertFalse($second['acquired']);
        $this->assertSame($first['reservation']['id'], $second['reservation']['id']);
        $this->assertSame(1, DB::table('atlas_task_scope_reservations')->whereNotNull('active_scope_key')->count());
    }

    public function test_stale_renewal_has_zero_effects(): void
    {
        $receipt = $this->acquire('run-a', 'worker-a', 'token-a', 'idem-a');
        $before = $receipt['reservation']['lease_expires_at'];

        $renewed = $this->reservations->renew(
            $receipt['reservation']['id'], 'worker-a', 'stale-token',
            $receipt['reservation']['fencing_token'], 120,
        );

        $this->assertFalse($renewed['renewed']);
        $this->assertSame($before, $this->reservations->reconstruct($receipt['reservation']['id'])['lease_expires_at']);
    }

    public function test_expiry_allows_takeover_with_monotonic_fence(): void
    {
        $first = $this->acquire('run-a', 'worker-a', 'token-a', 'idem-a', 30);
        Carbon::setTestNow('2026-07-11 12:01:00');
        $second = $this->acquire('run-b', 'worker-b', 'token-b', 'idem-b');

        $this->assertTrue($second['acquired']);
        $this->assertSame($first['reservation']['fencing_token'] + 1, $second['reservation']['fencing_token']);
        $this->assertSame('expired', $this->reservations->reconstruct($first['reservation']['id'])['state']);
    }

    public function test_old_worker_cannot_release_or_settle_after_takeover(): void
    {
        $first = $this->acquire('run-a', 'worker-a', 'token-a', 'idem-a', 30);
        Carbon::setTestNow('2026-07-11 12:01:00');
        $second = $this->acquire('run-b', 'worker-b', 'token-b', 'idem-b');

        $released = $this->reservations->release(
            $first['reservation']['id'], 'worker-a', 'token-a', $first['reservation']['fencing_token'], 'settled'
        );

        $this->assertFalse($released['released']);
        $this->assertSame('active', $this->reservations->reconstruct($second['reservation']['id'])['state']);
    }

    public function test_same_idempotency_key_returns_original_durable_identity(): void
    {
        $first = $this->acquire('run-a', 'worker-a', 'token-a', 'idem-a');
        $replay = $this->acquire('changed-run', 'changed-worker', 'changed-token', 'idem-a');

        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['reservation'], $replay['reservation']);
        $this->assertSame(1, DB::table('atlas_task_scope_reservations')->count());
    }

    public function test_crash_replay_reconstructs_exact_persisted_owner_and_state(): void
    {
        $receipt = $this->acquire('run-a', 'worker-a', 'token-a', 'idem-a');
        $freshService = app(ForgeScopeReservationService::class);

        $this->assertSame($receipt['reservation'], $freshService->reconstruct($receipt['reservation']['id']));
        $this->assertSame($receipt['reservation'], $freshService->acquire(
            'run-a', 'app/Services/Ai/Programming/Forge', 'real', 'worker-a', 'token-a',
            str_repeat('a', 64), str_repeat('c', 64), 'idem-a', 60,
        )['reservation']);
    }

    /** @return array<string,mixed> */
    private function acquire(string $runId, string $owner, string $token, string $idempotencyKey, int $leaseSeconds = 60): array
    {
        return $this->reservations->acquire(
            runId: $runId, scopePath: 'app/Services/Ai/Programming/Forge', mode: 'real',
            leaseOwner: $owner, leaseToken: $token, authorityHash: str_repeat('a', 64),
            baselineHash: str_repeat('c', 64), idempotencyKey: $idempotencyKey, leaseSeconds: $leaseSeconds,
        );
    }

    private function reservationMigration(): object
    {
        return require database_path('migrations/2026_07_11_130000_create_atlas_task_scope_reservations_table.php');
    }
}
