<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\ReentrySafety\AtlasLoopCycleCheckpointReader;
use App\Services\Ai\AutonomousEvolution\ReentrySafety\AtlasLoopCycleCheckpointWriter;
use App\Services\Ai\AutonomousEvolution\ReentrySafety\AtlasLoopCycleIdempotencyGuard;
use App\Services\Ai\AutonomousEvolution\ReentrySafety\AtlasLoopCycleReentryReceiptLedger;
use Illuminate\Console\Command;

/**
 * Operator-facing surface for the cycle re-entry safety primitives.
 *
 *   atlas:loop:cycle:reentry recover    --cycle=<id> [--json]
 *   atlas:loop:cycle:reentry history    --cycle=<id> [--json]
 *   atlas:loop:cycle:reentry checkpoint --cycle=<id> --phase=<p> --base-sha=<sha> --confirm [--json]
 *
 * recover / history are READ-ONLY (no side effects). checkpoint is FLAG-GATED — it
 * refuses to write unless --confirm is passed, so automation cannot trigger it accidentally.
 */
final class AtlasLoopCycleReentryCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:loop:cycle:reentry {action : recover|history|checkpoint}
        {--cycle= : cycle id}
        {--phase= : phase name (checkpoint)}
        {--base-sha= : base commit sha (checkpoint)}
        {--confirm : required to write a checkpoint}
        {--json : emit machine-readable JSON}';

    protected $description = 'Operator surface for the loop cycle re-entry safety primitives.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'recover' => $this->recover(),
            'history' => $this->history(),
            'checkpoint' => $this->checkpoint(),
            default => $this->failWith('unknown_action:'.$action.' (expected one of recover|history|checkpoint)'),
        };
    }

    private function recover(): int
    {
        $cycle = (string) ($this->option('cycle') ?? '');
        if ($cycle === '') {
            return $this->failWith('cycle_option_missing');
        }
        $reader = app(AtlasLoopCycleCheckpointReader::class);
        $fact = $reader->recoveryState($cycle);

        $payload = [
            'cycle_id' => $fact->cycleId,
            'last_completed_phase' => $fact->lastCompletedPhase,
            'next_phase_to_run' => $fact->nextPhaseToRun,
            'base_commit_sha' => $fact->baseCommitSha,
            'merged_sha' => $fact->mergedSha,
            'torn_tail' => $fact->tornTail,
            'emitted_receipt_ids' => $fact->emittedReceiptIds,
            'held_task_claim_ids' => $fact->heldTaskClaimIds,
        ];

        // Optional: classify the standard side-effect kinds against the recovered checkpoint.
        try {
            $guard = app(AtlasLoopCycleIdempotencyGuard::class);
            $payload['idempotency_classifications'] = [
                'merge_to_main' => $guard->classify($cycle, AtlasLoopCycleIdempotencyGuard::KIND_MERGE_TO_MAIN, (string) $fact->baseCommitSha),
                'emit_receipt' => $guard->classify($cycle, AtlasLoopCycleIdempotencyGuard::KIND_EMIT_RECEIPT, 'probe'),
                'claim_task' => $guard->classify($cycle, AtlasLoopCycleIdempotencyGuard::KIND_CLAIM_TASK, 'probe'),
            ];
        } catch (\Throwable) {
            $payload['idempotency_classifications'] = null;
        }

        $this->emit($payload);

        return self::EXIT_OK;
    }

    private function history(): int
    {
        $cycle = (string) ($this->option('cycle') ?? '');
        if ($cycle === '') {
            return $this->failWith('cycle_option_missing');
        }
        $ledger = app(AtlasLoopCycleReentryReceiptLedger::class);
        $rows = $ledger->query($cycle);

        $this->emit(['cycle_id' => $cycle, 'rows' => $rows]);
        if (! $this->option('json')) {
            foreach ($rows as $row) {
                $this->line(sprintf(
                    'phase=%s kind=%s key=%s decision=%s ts=%s',
                    (string) ($row['phase'] ?? '?'),
                    (string) ($row['kind'] ?? '?'),
                    (string) ($row['key'] ?? '?'),
                    (string) ($row['decision'] ?? '?'),
                    (string) ($row['recorded_at_unix'] ?? ($row['ts'] ?? '?')),
                ));
            }
        }

        return self::EXIT_OK;
    }

    private function checkpoint(): int
    {
        if (! (bool) $this->option('confirm')) {
            return $this->failWith('checkpoint_refused: --confirm flag required to write');
        }
        $cycle = (string) ($this->option('cycle') ?? '');
        $phase = (string) ($this->option('phase') ?? '');
        $baseSha = (string) ($this->option('base-sha') ?? '');
        if ($cycle === '' || $phase === '' || $baseSha === '') {
            return $this->failWith('checkpoint_missing_required_options: --cycle, --phase, --base-sha');
        }

        $writer = app(AtlasLoopCycleCheckpointWriter::class);
        $facts = [
            'commit_sha_base' => $baseSha,
            'merged_sha' => null,
            'emitted_receipt_ids' => [],
            'held_task_claim_ids' => [],
        ];
        try {
            $verdict = $writer->recordPhase($cycle, $phase, $facts);
        } catch (\Throwable $e) {
            return $this->failWith('checkpoint_write_failed:'.$e->getMessage());
        }
        $this->emit(['cycle_id' => $cycle, 'phase' => $phase, 'verdict' => $verdict]);

        return self::EXIT_OK;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            foreach ($payload as $k => $v) {
                $this->line($k.': '.(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
            }
        }
    }

    private function failWith(string $reason): int
    {
        $this->line($reason);

        return self::EXIT_USAGE;
    }
}
