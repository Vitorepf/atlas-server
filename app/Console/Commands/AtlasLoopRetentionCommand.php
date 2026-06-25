<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Retention\AtlasLoopSnapshotRetentionGc;
use App\Services\Ai\AutonomousEvolution\Retention\AtlasLoopSnapshotRetentionPolicy;
use App\Services\Ai\AutonomousEvolution\Retention\AtlasLoopSnapshotRetentionReceiptLedger;
use Illuminate\Console\Command;

/**
 * Operator-facing surface for the snapshot retention triad (policy + advisory GC + receipt ledger).
 *
 *   atlas:loop:retention policy   — print active policy parameters
 *   atlas:loop:retention inspect  — run scan() and print the advisory (table or --json)
 *   atlas:loop:retention advise   — run scan(), print the advisory AND append a receipt
 *
 * The command NEVER deletes. Any flag that suggests deletion (--apply, --delete, --prune) fails
 * with a clear "advisory only" message and exits non-zero.
 */
final class AtlasLoopRetentionCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:loop:retention {action : policy|inspect|advise}
        {--json : emit machine-readable JSON}
        {--apply : refused — this command is advisory only}
        {--delete : refused — this command is advisory only}
        {--prune : refused — this command is advisory only}';

    protected $description = 'Advisory-only retention surface: policy | inspect | advise. NEVER deletes.';

    public function handle(): int
    {
        if ($this->option('apply') || $this->option('delete') || $this->option('prune')) {
            $this->line('atlas:loop:retention is advisory only — deletion lives in a separate future opt-in packet.');

            return self::EXIT_USAGE;
        }

        $action = (string) $this->argument('action');
        $policy = $this->buildPolicy();

        return match ($action) {
            'policy' => $this->printPolicy($policy),
            'inspect' => $this->printAdvisory($policy, recordReceipt: false),
            'advise' => $this->printAdvisory($policy, recordReceipt: true),
            default => $this->failWith('unknown_action:'.$action),
        };
    }

    private function buildPolicy(): AtlasLoopSnapshotRetentionPolicy
    {
        $keepLastN = (int) (config('atlas.loop.retention.keep_last_n') ?? 10);
        $keepEveryMth = (int) (config('atlas.loop.retention.keep_every_mth') ?? 10);

        return new AtlasLoopSnapshotRetentionPolicy($keepLastN, $keepEveryMth);
    }

    private function printPolicy(AtlasLoopSnapshotRetentionPolicy $policy): int
    {
        $verdict = $policy->classify([]);
        $payload = [
            'schema' => 'atlas.loop.retention.policy_surface.v1',
            'keep_last_n' => (int) $verdict['keep_last_n'],
            'keep_every_mth' => (int) $verdict['keep_every_mth'],
        ];
        $this->emit($payload);

        return self::EXIT_OK;
    }

    private function printAdvisory(AtlasLoopSnapshotRetentionPolicy $policy, bool $recordReceipt): int
    {
        $snapshotDir = (string) (config('atlas.loop.retention.snapshot_dir') ?? storage_path('atlas/loop/snapshots'));
        $ledgerRoot = (string) (config('atlas.loop.retention.ledger_root') ?? storage_path('atlas/loop/retention/receipts'));
        $snapshotSource = function () use ($snapshotDir): array {
            if (! is_dir($snapshotDir)) {
                return [];
            }
            $files = glob($snapshotDir.'/*.json') ?: [];
            usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

            return array_map(static fn (string $f): string => basename($f, '.json'), $files);
        };
        $clock = static fn (): string => gmdate('Y-m-d\TH:i:s\Z');
        $gc = new AtlasLoopSnapshotRetentionGc($policy, $snapshotSource, $clock);
        $advisory = $gc->scan();

        if ($recordReceipt) {
            $ledger = new AtlasLoopSnapshotRetentionReceiptLedger($ledgerRoot);
            $ledger->record($advisory);
        }

        $this->emit($advisory);

        return self::EXIT_OK;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
        foreach ($payload as $k => $v) {
            $this->line($k.': '.(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
        }
    }

    private function failWith(string $reason): int
    {
        $this->line($reason);

        return self::EXIT_USAGE;
    }
}
