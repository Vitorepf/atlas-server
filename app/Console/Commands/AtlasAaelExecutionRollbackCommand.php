<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\Rollback\AtlasAaelExecutionPreImageSnapshotter;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Rollback\AtlasAaelExecutionRollbackExecutor;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Rollback\AtlasAaelExecutionRollbackReceiptLedger;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Console\Command;

/**
 * Operator surface for the AAEL execution-rollback subsystem.
 *   atlas:aael:rollback inspect --execution-id=<id> [--json]
 *   atlas:aael:rollback execute --execution-id=<id> [--reason=...] --confirm [--json]
 *   atlas:aael:rollback history --execution-id=<id> [--json]
 *
 * `execute` requires the Loop master switch ON (config('atlas.loop.master_enabled')).
 * Inspect/history remain available even with the master switch OFF.
 */
final class AtlasAaelExecutionRollbackCommand extends Command
{
    public const VALID_TRIGGER_REASONS = ['post_verify_failed', 'operator_request', 'partial_restore'];

    public const EXIT_OK = 0;

    public const EXIT_PARTIAL = 2;

    public const EXIT_REFUSED = 3;

    protected $signature = 'atlas:aael:rollback {action : inspect|execute|history}
        {--execution-id=}
        {--reason=operator_request}
        {--json}
        {--confirm}';

    protected $description = 'AAEL execution rollback operator surface (inspect|execute|history).';

    public function handle(AtlasAaelExecutionRollbackOperatorPort $port): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'inspect' => $this->inspect($port),
            'execute' => $this->executeRollback($port),
            'history' => $this->history($port),
            default => $this->refuse('unknown_action:'.$action),
        };
    }

    private function inspect(AtlasAaelExecutionRollbackOperatorPort $port): int
    {
        $executionId = (string) ($this->option('execution-id') ?? '');
        if ($executionId === '') {
            return $this->refuse('missing_execution_id');
        }
        try {
            $summary = $port->inspectManifest($executionId);
        } catch (\Throwable $e) {
            return $this->refuse('inspect_failed:'.$e->getMessage());
        }
        $this->emit($summary);

        return self::EXIT_OK;
    }

    private function executeRollback(AtlasAaelExecutionRollbackOperatorPort $port): int
    {
        $executionId = (string) ($this->option('execution-id') ?? '');
        $reason = (string) ($this->option('reason') ?? 'operator_request');

        // The real master switch parses .env directly (preferring
        // ATLAS_AUTONOMOS_MASTER_ENABLED) so an operator flip takes effect without
        // a config-cache clear. config('atlas.loop.master_enabled') resolves to
        // NULL — that key lives nowhere — so this refused on every run, master ON
        // or OFF. Fail-closed, so never unsafe; just permanently unusable.
        if (! AtlasLoopMasterSwitch::enabled()) {
            $this->getOutput()->writeln('master switch OFF: refusing execute');

            return self::EXIT_REFUSED;
        }
        if (! $this->option('confirm')) {
            $this->getOutput()->writeln('refused: --confirm is required to invoke rollback');

            return self::EXIT_REFUSED;
        }
        if ($executionId === '') {
            return $this->refuse('missing_execution_id');
        }
        if (! in_array($reason, self::VALID_TRIGGER_REASONS, true)) {
            return $this->refuse('invalid_reason:'.$reason);
        }

        try {
            $receipt = $port->executeRollback($executionId, $reason);
        } catch (\Throwable $e) {
            $this->getOutput()->writeln('execute_failed:'.$e->getMessage());

            return self::EXIT_PARTIAL;
        }
        $this->emit($receipt);

        return match ((string) ($receipt['status'] ?? '')) {
            'restored', 'already_restored' => self::EXIT_OK,
            'partial' => self::EXIT_PARTIAL,
            'refused' => self::EXIT_REFUSED,
            default => self::EXIT_PARTIAL,
        };
    }

    private function history(AtlasAaelExecutionRollbackOperatorPort $port): int
    {
        $executionId = (string) ($this->option('execution-id') ?? '');
        $rows = iterator_to_array($port->listReceipts($executionId === '' ? null : $executionId, 20), false);
        $this->emit($rows);

        return self::EXIT_OK;
    }

    /**
     * @param  array<int|string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->getOutput()->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
        foreach ($payload as $k => $v) {
            $this->getOutput()->writeln($k.': '.(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
        }
    }

    private function refuse(string $reason): int
    {
        $this->getOutput()->writeln($reason);

        return self::EXIT_REFUSED;
    }
}

/**
 * Wrapping port consumed by AtlasAaelExecutionRollbackCommand. Concrete services
 * (snapshotter / executor / ledger) are FINAL — the port lets tests swap them.
 */
interface AtlasAaelExecutionRollbackOperatorPort
{
    /**
     * @return array<string,mixed>
     */
    public function inspectManifest(string $executionId): array;

    /**
     * @return array<string,mixed>
     */
    public function executeRollback(string $executionId, string $reason): array;

    /**
     * @return iterable<int,array<string,mixed>>
     */
    public function listReceipts(?string $executionId, int $limit): iterable;
}

/**
 * Default port wiring: reads pre-image manifest from disk, invokes the rollback executor,
 * forwards the structured outcome to the rollback ledger.
 */
final class AtlasAaelExecutionRollbackDefaultOperatorPort implements AtlasAaelExecutionRollbackOperatorPort
{
    public function __construct(
        private readonly AtlasAaelExecutionPreImageSnapshotter $snapshotter,
        private readonly AtlasAaelExecutionRollbackExecutor $executor,
        private readonly AtlasAaelExecutionRollbackReceiptLedger $ledger,
    ) {}

    public function inspectManifest(string $executionId): array
    {
        $manifestPath = storage_path('atlas/aael/preimage/'.$executionId.'/manifest.json');
        if (! is_file($manifestPath)) {
            return [
                'execution_id' => $executionId,
                'manifest_path' => $manifestPath,
                'manifest_sha' => '',
                'target_count' => 0,
                'total_bytes' => 0,
                'targets' => [],
            ];
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $targets = (array) ($manifest['targets'] ?? []);
        $totalBytes = 0;
        $diff = [];
        foreach ($targets as $t) {
            $path = (string) ($t['path'] ?? '');
            $pre = (string) ($t['sha256'] ?? '');
            $abs = base_path($path);
            $currentSha = is_file($abs) ? (hash_file('sha256', $abs) ?: '') : '';
            $totalBytes += (int) ($t['bytes'] ?? 0);
            $diff[] = [
                'path' => $path,
                'pre_sha' => $pre,
                'current_sha' => $currentSha,
                'matches_pre' => ($pre === $currentSha),
            ];
        }

        return [
            'execution_id' => $executionId,
            'manifest_path' => $manifestPath,
            'manifest_sha' => (string) ($manifest['sha_of_shas'] ?? hash('sha256', '')),
            'target_count' => count($targets),
            'total_bytes' => $totalBytes,
            'targets' => $diff,
        ];
    }

    public function executeRollback(string $executionId, string $reason): array
    {
        $outcome = $this->executor->execute($executionId);
        $manifestSha = '';
        $manifestPath = (string) ($outcome['manifest_path'] ?? '');
        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            $manifestSha = (string) ($manifest['sha_of_shas'] ?? hash('sha256', ''));
        }
        $perTarget = [];
        $restoredCount = is_array($outcome['restored_paths'] ?? null) ? count($outcome['restored_paths']) : 0;

        $receipt = [
            'execution_id' => $executionId,
            'triggered_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'trigger_reason' => $reason,
            'manifest_sha' => $manifestSha,
            'target_count' => $restoredCount,
            'restored_count' => $restoredCount,
            'skipped_count' => 0,
            'status' => (string) ($outcome['status'] ?? 'partial'),
            'per_target' => $perTarget,
            'evidence_ids' => [],
        ];

        try {
            $this->ledger->append($receipt);
        } catch (\Throwable) {
            // Ledger schema violations leave the receipt unappended; the executor outcome is still surfaced.
        }

        return $receipt;
    }

    public function listReceipts(?string $executionId, int $limit): iterable
    {
        yield from $this->ledger->list($executionId, $limit);
    }
}
