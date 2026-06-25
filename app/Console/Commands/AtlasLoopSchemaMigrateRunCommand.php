<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Migration\Runner\AtlasLoopSchemaMigrationDryRunReporter;
use App\Services\Ai\AutonomousEvolution\Migration\Runner\AtlasLoopSchemaMigrationExecutableRunner;
use App\Services\Ai\AutonomousEvolution\Migration\Runner\AtlasLoopSchemaMigrationExecutionReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Migration\Runner\AtlasLoopSchemaMigrationRollback;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator-visible surface for schema migration runs. Thin CLI; every decision lives in the four
 * services it wires: dry-run reporter, executable runner, rollback, execution receipt ledger.
 *
 * Fail-closed on master switch for apply/rollback (exit 2, stderr "master_off"). dry/history are
 * read-only and always permitted.
 */
final class AtlasLoopSchemaMigrateRunCommand extends Command
{
    protected $signature = 'atlas:loop:migrate:run {action : dry|apply|rollback|history} {--step=} {--checkpoint=} {--limit=20}';

    protected $description = 'Runs/inspects schema migrations through the dry-run reporter, executable runner, rollback and receipt ledger.';

    public const STEP_RESOLVER_BINDING = 'atlas.loop.migration.step_resolver';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'history' => $this->emitHistory(),
            'dry' => $this->emitDryRun(),
            'apply' => $this->emitApply(),
            'rollback' => $this->emitRollback(),
            default => $this->emitFailure(['error' => 'unknown_action:'.$action], 1),
        };
    }

    private function emitHistory(): int
    {
        $limit = (int) $this->option('limit');
        $ledger = $this->ledger();
        try {
            $rows = $ledger->tail($limit > 0 ? $limit : 20);
        } catch (Throwable $e) {
            return $this->emitFailure(['error' => 'history_read_failed', 'detail' => $e->getMessage()], 1);
        }

        $this->emit([
            'action' => 'history',
            'limit' => $limit,
            'count' => count($rows),
            'receipts' => $rows,
        ]);

        return 0;
    }

    private function emitDryRun(): int
    {
        $stepId = (string) $this->option('step');
        if ($stepId === '') {
            return $this->emitFailure(['error' => 'step_required'], 1);
        }
        $step = $this->resolveStep($stepId);
        if ($step === null) {
            return $this->emitFailure(['error' => 'unknown_step:'.$stepId], 1);
        }
        try {
            $report = $this->dryRunReporter()->dryRun($step);
        } catch (Throwable $e) {
            return $this->emitFailure(['error' => 'dry_run_failed', 'detail' => $e->getMessage()], 1);
        }
        $this->emit([
            'action' => 'dry',
            'step_id' => $stepId,
            'report' => method_exists($report, 'toArray') ? $report->toArray() : (array) $report,
        ]);

        return 0;
    }

    private function emitApply(): int
    {
        if (! $this->masterEnabled()) {
            return $this->refusedMasterOff('apply');
        }
        $stepId = (string) $this->option('step');
        if ($stepId === '') {
            return $this->emitFailure(['error' => 'step_required'], 1);
        }
        $step = $this->resolveStep($stepId);
        if ($step === null) {
            return $this->emitFailure(['error' => 'unknown_step:'.$stepId], 1);
        }

        $startedAt = gmdate('c');
        try {
            $outcome = $this->executableRunner()->run($step);
        } catch (Throwable $e) {
            return $this->emitFailure(['error' => 'apply_failed', 'detail' => $e->getMessage()], 1);
        }
        $finishedAt = gmdate('c');

        $receipt = $this->composeReceipt(
            action: 'apply',
            stepId: (string) ($outcome['step_id'] ?? $stepId),
            checkpointId: (string) ($outcome['checkpoint_id'] ?? ''),
            status: (string) ($outcome['status'] ?? 'unknown'),
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            facts: is_array($outcome['facts'] ?? null) ? $outcome['facts'] : [],
        );
        $this->ledger()->append($receipt);

        $this->emit(['action' => 'apply', 'receipt' => $receipt, 'outcome' => $outcome]);

        return 0;
    }

    private function emitRollback(): int
    {
        if (! $this->masterEnabled()) {
            return $this->refusedMasterOff('rollback');
        }
        $checkpointId = (string) $this->option('checkpoint');
        if ($checkpointId === '') {
            return $this->emitFailure(['error' => 'checkpoint_required'], 1);
        }
        $startedAt = gmdate('c');
        try {
            $receiptObj = $this->rollback()->rollback($checkpointId);
        } catch (Throwable $e) {
            return $this->emitFailure(['error' => 'rollback_failed', 'detail' => $e->getMessage()], 1);
        }
        $finishedAt = gmdate('c');

        $arr = method_exists($receiptObj, 'toArray') ? $receiptObj->toArray() : [];
        $receipt = $this->composeReceipt(
            action: 'rollback',
            stepId: (string) ($arr['step_id'] ?? ''),
            checkpointId: $checkpointId,
            status: (string) ($arr['status'] ?? 'unknown'),
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            facts: $arr,
        );
        $this->ledger()->append($receipt);

        $this->emit(['action' => 'rollback', 'receipt' => $receipt]);

        return 0;
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function composeReceipt(
        string $action,
        string $stepId,
        string $checkpointId,
        string $status,
        string $startedAt,
        string $finishedAt,
        array $facts,
    ): array {
        $bytes = json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
        $manifestSha = hash('sha256', $bytes);

        return [
            'receipt_id' => 'receipt-'.substr(hash('sha256', $action.'|'.$stepId.'|'.$checkpointId.'|'.$startedAt.'|'.$finishedAt), 0, 16),
            'step_id' => $stepId,
            'action' => $action,
            'checkpoint_id' => $checkpointId,
            'pre_sha256_manifest' => $action === 'apply' ? '' : $manifestSha,
            'post_sha256_manifest' => $manifestSha,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'status' => $status,
            'facts' => $facts,
        ];
    }

    private function refusedMasterOff(string $action): int
    {
        $output = $this->output;
        if ($output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln('master_off');
        } else {
            // BufferedOutput / non-console: surface refusal on the single buffer so tests still observe it.
            $this->line('master_off');
        }
        $this->emit(['action' => $action, 'status' => 'refused', 'reason' => 'master_off']);

        return 2;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        ksort($payload);
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emitFailure(array $payload, int $code): int
    {
        $this->emit($payload);

        return $code;
    }

    private function masterEnabled(): bool
    {
        return (bool) config('atlas.loop.master_enabled', false);
    }

    private function resolveStep(string $stepId): ?object
    {
        if (! $this->getLaravel()->bound(self::STEP_RESOLVER_BINDING)) {
            return null;
        }
        $resolver = $this->getLaravel()->make(self::STEP_RESOLVER_BINDING);
        if (! is_callable($resolver)) {
            return null;
        }
        $result = $resolver($stepId);

        return is_object($result) ? $result : null;
    }

    private function ledger(): AtlasLoopSchemaMigrationExecutionReceiptLedger
    {
        return $this->getLaravel()->make(AtlasLoopSchemaMigrationExecutionReceiptLedger::class);
    }

    private function dryRunReporter(): AtlasLoopSchemaMigrationDryRunReporter
    {
        return $this->getLaravel()->make(AtlasLoopSchemaMigrationDryRunReporter::class);
    }

    private function executableRunner(): AtlasLoopSchemaMigrationExecutableRunner
    {
        return $this->getLaravel()->make(AtlasLoopSchemaMigrationExecutableRunner::class);
    }

    private function rollback(): AtlasLoopSchemaMigrationRollback
    {
        return $this->getLaravel()->make(AtlasLoopSchemaMigrationRollback::class);
    }
}
