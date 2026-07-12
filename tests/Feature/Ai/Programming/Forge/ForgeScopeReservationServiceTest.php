<?php

namespace Tests\Feature\Ai\Programming\Forge;

use App\Services\Ai\Programming\Forge\ForgeScopeReservationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ForgeScopeReservationServiceTest extends TestCase
{
    private ForgeScopeReservationService $reservations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerMigration()->up();
        $this->ledgerScopeMigration()->up();
        $this->reservationMigration()->up();
        $this->reservations = app(ForgeScopeReservationService::class);
        Carbon::setTestNow('2026-07-11 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->reservationMigration()->down();
        $this->ledgerMigration()->down();
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

    public function test_reaper_expires_active_lease_once_and_preserves_fencing_history(): void
    {
        $first = $this->acquire('run-a', 'worker-a', 'token-a', 'idem-a', 30);
        Carbon::setTestNow('2026-07-11 12:01:00');

        $reaped = $this->reservations->reapExpired();
        $secondPass = $this->reservations->reapExpired();

        $this->assertSame(1, $reaped['reaped_count']);
        $this->assertSame(0, $secondPass['reaped_count']);
        $this->assertSame('expired', $this->reservations->reconstruct($first['reservation']['id'])['state']);
        $this->assertNull(DB::table('atlas_task_scope_reservations')->where('id', $first['reservation']['id'])->value('active_scope_key'));

        $takeover = $this->acquire('run-b', 'worker-b', 'token-b', 'idem-b');
        $this->assertSame($first['reservation']['fencing_token'] + 1, $takeover['reservation']['fencing_token']);
        $this->assertSame(3, DB::table('atlas_ledger_events')->where('scope_type', 'forge_reservation')->count());
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
        $replay = $this->acquire('run-a', 'worker-a', 'token-a', 'idem-a');

        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['reservation'], $replay['reservation']);
        $this->assertSame(1, DB::table('atlas_task_scope_reservations')->count());
    }

    public function test_idempotency_replay_refuses_changed_contract(): void
    {
        $this->acquire('run-a', 'worker-a', 'token-a', 'idem-a');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('idempotency contract mismatch');
        $this->acquire('changed-run', 'worker-a', 'token-a', 'idem-a');
    }

    public function test_ledger_receipt_is_visible_only_after_outer_transaction_commits(): void
    {
        $before = DB::table('atlas_ledger_events')->where('scope_type', 'forge_reservation')->count();

        DB::transaction(function () use ($before): void {
            $this->acquire('run-ledger', 'worker-a', 'token-a', 'idem-ledger');
            $this->assertSame(
                $before,
                DB::table('atlas_ledger_events')->where('scope_type', 'forge_reservation')->count(),
            );
        });

        $this->assertSame(
            $before + 1,
            DB::table('atlas_ledger_events')->where('scope_type', 'forge_reservation')->count(),
        );
    }

    public function test_disabled_and_drain_postures_block_new_acquisition_but_drain_allows_replay(): void
    {
        config()->set('atlas.forge.scope_reservations.posture', 'enforce');
        $original = $this->acquire('run-a', 'worker-a', 'token-a', 'idem-a');

        config()->set('atlas.forge.scope_reservations.posture', 'drain');
        $replay = $this->acquire('run-a', 'worker-a', 'token-a', 'idem-a');
        $blockedDrain = $this->acquire('run-b', 'worker-b', 'token-b', 'idem-b');
        config()->set('atlas.forge.scope_reservations.posture', 'disabled');
        $blockedDisabled = $this->acquire('run-c', 'worker-c', 'token-c', 'idem-c');

        $this->assertTrue($replay['replayed']);
        $this->assertSame($original['reservation'], $replay['reservation']);
        $this->assertFalse($blockedDrain['acquired']);
        $this->assertFalse($blockedDisabled['acquired']);
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

    public function test_conflict_recovery_uses_postgresql_safe_non_throwing_insert(): void
    {
        $source = file_get_contents(app_path('Services/Ai/Programming/Forge/ForgeScopeReservationService.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('insertOrIgnore', $source);
        $this->assertStringNotContainsString('QueryException', $source);
    }

    public function test_conflict_insert_path_returns_durable_winner_without_exception_recovery(): void
    {
        $injected = false;
        $winnerId = '11111111-1111-4111-8111-111111111111';
        DB::listen(function ($query) use (&$injected, $winnerId): void {
            if ($injected || ! str_contains($query->sql, 'max("fencing_token")')) {
                return;
            }
            $injected = true;
            $now = Carbon::now();
            $scopeKey = hash('sha256', 'app/Services/Ai/Programming/Forge');
            DB::table('atlas_task_scope_reservations')->insert([
                'id' => $winnerId,
                'run_id' => 'racing-run',
                'canonical_scope_key' => $scopeKey,
                'scope_path' => 'app/Services/Ai/Programming/Forge',
                'active_scope_key' => $scopeKey,
                'mode' => 'real',
                'lease_owner' => 'racing-worker',
                'lease_token' => 'racing-token',
                'authority_hash' => str_repeat('f', 64),
                'state' => 'active',
                'fencing_token' => 1,
                'baseline_hash' => str_repeat('e', 64),
                'idempotency_key' => 'racing-idem',
                'lease_expires_at' => $now->copy()->addMinute(),
                'released_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $result = $this->acquire('run-a', 'worker-a', 'token-a', 'idem-a');

        $this->assertTrue($injected);
        $this->assertFalse($result['acquired']);
        $this->assertSame($winnerId, $result['reservation']['id']);
    }

    public function test_reservation_migration_is_reversible_on_an_isolated_database(): void
    {
        $connection = 'forge_reservation_migration_'.bin2hex(random_bytes(4));
        $database = storage_path('framework/testing/'.$connection.'.sqlite');
        @mkdir(\dirname($database), 0775, true);
        @unlink($database);
        touch($database);
        config()->set('database.connections.'.$connection, [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $originalConnection = DB::getDefaultConnection();
        DB::setDefaultConnection($connection);
        DB::purge($connection);

        try {
            $migration = $this->reservationMigration();
            $migration->up();
            $this->assertTrue(Schema::connection($connection)->hasTable('atlas_task_scope_reservations'));

            $migration->down();
            $this->assertFalse(Schema::connection($connection)->hasTable('atlas_task_scope_reservations'));
        } finally {
            DB::disconnect($connection);
            DB::setDefaultConnection($originalConnection);
            DB::purge($connection);
            @unlink($database);
        }
    }

    public function test_two_independent_processes_leave_exactly_one_active_scope_owner(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the real concurrent acquire proof');
        }

        $directory = storage_path('framework/testing/forge-reservation-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0777, true);
        $database = $directory.'/concurrency.sqlite';
        touch($database);
        config()->set('database.connections.forge_concurrency', [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
            'journal_mode' => 'WAL',
        ]);
        $originalConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('forge_concurrency');
        DB::purge('forge_concurrency');
        $this->ledgerMigration()->up();
        $this->ledgerScopeMigration()->up();
        $this->reservationMigration()->up();

        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge('forge_concurrency');
                DB::setDefaultConnection('forge_concurrency');
                DB::statement('PRAGMA busy_timeout = 5000');
                touch($directory.'/ready-'.$index);
                $deadline = microtime(true) + 5;
                while (! file_exists($directory.'/go') && microtime(true) < $deadline) {
                    usleep(1000);
                }
                try {
                    $result = app(ForgeScopeReservationService::class)->acquire(
                        'run-'.$index,
                        'app/Services/Ai/Programming/Forge',
                        'real',
                        'worker-'.$index,
                        'token-'.$index,
                        str_repeat((string) ($index + 1), 64),
                        str_repeat('c', 64),
                        'idem-'.$index,
                        60,
                    );
                    file_put_contents($directory.'/result-'.$index, json_encode($result, JSON_THROW_ON_ERROR));
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($directory.'/result-'.$index, json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
                    exit(1);
                }
            }
            $children[] = $pid;
        }

        $deadline = microtime(true) + 5;
        while ((! file_exists($directory.'/ready-0') || ! file_exists($directory.'/ready-1')) && microtime(true) < $deadline) {
            usleep(1000);
        }
        touch($directory.'/go');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::purge('forge_concurrency');
        DB::setDefaultConnection('forge_concurrency');
        $results = [
            json_decode((string) file_get_contents($directory.'/result-0'), true, flags: JSON_THROW_ON_ERROR),
            json_decode((string) file_get_contents($directory.'/result-1'), true, flags: JSON_THROW_ON_ERROR),
        ];
        $this->assertCount(1, array_filter($results, static fn (array $result): bool => ($result['acquired'] ?? false) === true));
        $this->assertSame(1, DB::table('atlas_task_scope_reservations')->whereNotNull('active_scope_key')->count());

        DB::purge('forge_concurrency');
        DB::setDefaultConnection($originalConnection);
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }

    public function test_postgresql_concurrent_acquire_profile_is_safe_and_opt_in(): void
    {
        $environment = array_map(
            static fn (string $key): string => (string) getenv($key),
            ['ATLAS_TEST_PG_HOST', 'ATLAS_TEST_PG_PORT', 'ATLAS_TEST_PG_DATABASE', 'ATLAS_TEST_PG_USERNAME', 'ATLAS_TEST_PG_PASSWORD'],
        );
        if (in_array('', $environment, true)) {
            $this->markTestSkipped('ephemeral PostgreSQL proof requires explicit ATLAS_TEST_PG_* variables');
        }
        [$host, $port, $database, $username, $password] = array_values($environment);
        if (! str_starts_with($database, 'atlas_test_')) {
            $this->markTestSkipped('ATLAS_TEST_PG_DATABASE must start with atlas_test_');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for PostgreSQL concurrency proof');
        }

        config()->set('database.connections.forge_concurrency_pgsql', [
            'driver' => 'pgsql',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ]);
        $originalConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('forge_concurrency_pgsql');
        DB::purge('forge_concurrency_pgsql');
        $this->reservationMigration()->down();
        $this->reservationMigration()->up();

        $directory = storage_path('framework/testing/forge-pg-reservation-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0777, true);
        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge('forge_concurrency_pgsql');
                DB::setDefaultConnection('forge_concurrency_pgsql');
                touch($directory.'/ready-'.$index);
                while (! file_exists($directory.'/go')) {
                    usleep(1000);
                }
                try {
                    $result = app(ForgeScopeReservationService::class)->acquire(
                        'pg-run-'.$index, 'app/Services/Ai/Programming/Forge', 'real',
                        'pg-worker-'.$index, 'pg-token-'.$index, str_repeat((string) ($index + 1), 64),
                        str_repeat('d', 64), 'pg-idem-'.$index, 60,
                    );
                    file_put_contents($directory.'/result-'.$index, json_encode($result, JSON_THROW_ON_ERROR));
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($directory.'/result-'.$index, json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        while (! file_exists($directory.'/ready-0') || ! file_exists($directory.'/ready-1')) {
            usleep(1000);
        }
        touch($directory.'/go');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::purge('forge_concurrency_pgsql');
        DB::setDefaultConnection('forge_concurrency_pgsql');
        $results = [
            json_decode((string) file_get_contents($directory.'/result-0'), true, flags: JSON_THROW_ON_ERROR),
            json_decode((string) file_get_contents($directory.'/result-1'), true, flags: JSON_THROW_ON_ERROR),
        ];
        $this->assertCount(1, array_filter($results, static fn (array $result): bool => ($result['acquired'] ?? false) === true));
        $this->assertSame(1, DB::table('atlas_task_scope_reservations')->whereNotNull('active_scope_key')->count());

        $this->reservationMigration()->down();
        DB::purge('forge_concurrency_pgsql');
        DB::setDefaultConnection($originalConnection);
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
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

    private function ledgerMigration(): object
    {
        return require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php');
    }

    private function ledgerScopeMigration(): object
    {
        return require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php');
    }
}
