<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Aael\Parallel\AtlasAaelParallelExecutionReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Aael\Parallel\AtlasAaelParallelLockManager;
use App\Services\Ai\AutonomousEvolution\Aael\Parallel\AtlasAaelParallelStepScheduler;
use App\Services\Ai\AutonomousEvolution\Aael\Parallel\LockHandle;
use Illuminate\Console\Command;

/**
 * Operator surface for the AAEL parallel sub-system (W1220).
 *   atlas:aael:parallel schedule --input=<dag.json> [--json]
 *   atlas:aael:parallel lock     [--show] [--acquire=<step> --write-set=<csv>] [--release=<lock-id>] [--confirm] [--json]
 *   atlas:aael:parallel history  --day=YYYY-MM-DD [--json]
 *
 * Observability-only by default: --acquire/--release require --confirm; otherwise they are
 * dry-runs that print what WOULD happen. Master-switch-respecting:
 *   config('atlas.aael.parallel.cli_enabled') = false (default) ⇒ every action exits 0 with a
 *   single-line refusal and no side effects.
 */
final class AtlasAaelParallelCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:aael:parallel {action : schedule|lock|history}
        {--input= : path to steps DAG JSON (schedule)}
        {--acquire= : step id to acquire a lock for}
        {--write-set= : comma-separated paths (acquire)}
        {--release= : lock_id to release}
        {--day= : YYYY-MM-DD (history; default today UTC)}
        {--confirm}
        {--json}';

    protected $description = 'AAEL parallel CLI: schedule | lock | history (observability-only by default).';

    public function handle(
        AtlasAaelParallelStepScheduler $scheduler,
        AtlasAaelParallelLockManager $lockManager,
        AtlasAaelParallelExecutionReceiptLedger $ledger,
    ): int {
        if (! $this->cliEnabled()) {
            $this->getOutput()->writeln('atlas:aael:parallel disabled: set config atlas.aael.parallel.cli_enabled=true to enable');

            return self::EXIT_OK;
        }
        $action = (string) $this->argument('action');

        return match ($action) {
            'schedule' => $this->schedule($scheduler),
            'lock' => $this->lock($lockManager),
            'history' => $this->history($ledger),
            default => $this->refuse('unknown_action:'.$action),
        };
    }

    private function schedule(AtlasAaelParallelStepScheduler $scheduler): int
    {
        $input = (string) ($this->option('input') ?? '');
        if ($input === '' || ! is_file($input)) {
            return $this->refuse('schedule_requires_input_file');
        }
        $raw = (string) file_get_contents($input);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->refuse('input_not_valid_json');
        }
        $steps = is_array($decoded['steps'] ?? null) ? $decoded['steps'] : $decoded;

        try {
            $plan = $scheduler->plan(array_values($steps));
        } catch (\Throwable $e) {
            return $this->refuse('schedule_failed:'.$e->getMessage());
        }
        $this->emit($plan);

        return self::EXIT_OK;
    }

    private function lock(AtlasAaelParallelLockManager $lockManager): int
    {
        $acquire = (string) ($this->option('acquire') ?? '');
        $release = (string) ($this->option('release') ?? '');
        $writeSet = (string) ($this->option('write-set') ?? '');
        $confirm = (bool) $this->option('confirm');

        if ($acquire === '' && $release === '') {
            $this->emit(['held' => $this->readHeldLocks($lockManager)]);

            return self::EXIT_OK;
        }

        if ($acquire !== '' && $release === '') {
            $paths = array_values(array_filter(array_map('trim', explode(',', $writeSet)), static fn (string $p): bool => $p !== ''));
            if ($paths === []) {
                return $this->refuse('acquire_requires_write_set');
            }
            if (! $confirm) {
                $this->emit([
                    'dry_run' => true,
                    'would_acquire' => ['step_id' => $acquire, 'write_set' => $paths],
                ]);

                return self::EXIT_OK;
            }
            $handle = $lockManager->acquire($acquire, $paths);
            if ($handle === null) {
                $this->emit(['acquired' => false, 'reason' => 'overlap_or_io_error']);

                return self::EXIT_USAGE;
            }
            $this->emit([
                'acquired' => true,
                'lock_id' => $handle->lockId,
                'step_id' => $handle->stepId,
                'write_set' => $handle->writeSet,
            ]);

            return self::EXIT_OK;
        }

        // release path
        if (! $confirm) {
            $this->emit(['dry_run' => true, 'would_release' => $release]);

            return self::EXIT_OK;
        }
        $reconstructed = $this->reconstructLockHandle($lockManager, $release);
        if ($reconstructed === null) {
            return $this->refuse('lock_not_found:'.$release);
        }
        $lockManager->release($reconstructed);
        $this->emit(['released' => $release]);

        return self::EXIT_OK;
    }

    private function history(AtlasAaelParallelExecutionReceiptLedger $ledger): int
    {
        $day = (string) ($this->option('day') ?? '');
        if ($day === '') {
            $day = gmdate('Y-m-d');
        }
        $rows = [];
        foreach ($ledger->read($day) as $row) {
            $rows[] = $row;
        }
        $this->emit($rows);

        return self::EXIT_OK;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readHeldLocks(AtlasAaelParallelLockManager $lockManager): array
    {
        $path = $this->lockLedgerPath($lockManager);
        if ($path === '' || ! is_file($path)) {
            return [];
        }
        $contents = (string) file_get_contents($path);
        $state = json_decode($contents, true);
        if (! is_array($state) || ! is_array($state['locks'] ?? null)) {
            return [];
        }

        return array_values(array_filter($state['locks'], 'is_array'));
    }

    private function reconstructLockHandle(AtlasAaelParallelLockManager $lockManager, string $lockId): ?LockHandle
    {
        foreach ($this->readHeldLocks($lockManager) as $row) {
            if ((string) ($row['lock_id'] ?? '') === $lockId) {
                return new LockHandle(
                    lockId: (string) ($row['lock_id'] ?? ''),
                    stepId: (string) ($row['step_id'] ?? ''),
                    writeSet: array_values(array_map('strval', (array) ($row['write_set'] ?? []))),
                    acquiredAtUnix: (int) ($row['acquired_at_unix'] ?? 0),
                );
            }
        }

        return null;
    }

    private function lockLedgerPath(AtlasAaelParallelLockManager $lockManager): string
    {
        try {
            $ref = new \ReflectionClass($lockManager);
            $prop = $ref->getProperty('ledgerPath');
            $prop->setAccessible(true);

            return (string) $prop->getValue($lockManager);
        } catch (\Throwable) {
            return '';
        }
    }

    private function cliEnabled(): bool
    {
        if (! function_exists('config')) {
            return false;
        }

        return (bool) config('atlas.aael.parallel.cli_enabled', false);
    }

    /**
     * @param  array<int|string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json') || true) {
            $this->getOutput()->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
    }

    private function refuse(string $reason): int
    {
        $this->getOutput()->writeln($reason);

        return self::EXIT_USAGE;
    }
}
