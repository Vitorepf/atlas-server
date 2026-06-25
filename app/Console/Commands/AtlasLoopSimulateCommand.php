<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Simulation\AtlasLoopSimulationDryRunner;
use App\Services\Ai\AutonomousEvolution\Simulation\AtlasLoopSimulationReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Simulation\AtlasLoopSimulationSandboxBuilder;
use App\Services\Ai\AutonomousEvolution\Simulation\SandboxHandle;
use Illuminate\Console\Command;

\class_exists(AtlasLoopSimulationSandboxBuilder::class);
\class_exists(AtlasLoopSimulationDryRunner::class);

/**
 * Operator surface for the Simulation organ: Builder -> DryRunner -> Ledger.
 *   atlas:loop:simulate build
 *   atlas:loop:simulate dry-run --sandbox=<path> --diff=<file>
 *   atlas:loop:simulate history [--limit=N] [--since-sha=<sha>]
 *
 * FACT-only output, no scores. Never commits or pushes — dry-run console only.
 * MASTER OFF (config('atlas.loop.master_enabled')=false) fails closed with exit 0 + literal
 * "master switch OFF" message + zero side effects.
 */
final class AtlasLoopSimulateCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:loop:simulate {action : build|dry-run|history}
        {--diff= : path to unified-diff file (dry-run)}
        {--sandbox= : sandbox path (dry-run)}
        {--limit=20}
        {--since-sha= : optional chain anchor (history)}';

    protected $description = 'Loop Simulation CLI (build | dry-run | history) — operator dry-run console.';

    public function handle(
        AtlasLoopSimulationSandboxBuilder $builder,
        AtlasLoopSimulationDryRunner $runner,
        AtlasLoopSimulationReceiptLedger $ledger,
    ): int {
        if (! $this->masterEnabled()) {
            $this->getOutput()->writeln('master switch OFF');

            return self::EXIT_OK;
        }
        $action = (string) $this->argument('action');

        return match ($action) {
            'build' => $this->build($builder),
            'dry-run' => $this->dryRun($runner, $ledger),
            'history' => $this->history($ledger),
            default => $this->refuse('unknown_action:'.$action),
        };
    }

    private function build(AtlasLoopSimulationSandboxBuilder $builder): int
    {
        $sourceRoot = (string) (function_exists('config') ? config('atlas.loop.simulation.source_root', null) : null);
        if ($sourceRoot === '') {
            $sourceRoot = function_exists('base_path') ? base_path() : getcwd();
        }
        try {
            $handle = $builder->build((string) $sourceRoot, 'cli-'.bin2hex(random_bytes(4)));
        } catch (\Throwable $e) {
            return $this->refuse('build_failed:'.$e->getMessage());
        }
        $this->emit([
            'sandbox_path' => $handle->sandboxPath,
            'source_commit_sha' => $handle->sourceCommitSha,
            'content_checksum' => $handle->contentChecksum,
            'seed' => $handle->seed,
            'created_at' => $handle->createdAt,
        ]);

        return self::EXIT_OK;
    }

    private function dryRun(AtlasLoopSimulationDryRunner $runner, AtlasLoopSimulationReceiptLedger $ledger): int
    {
        $sandbox = (string) ($this->option('sandbox') ?? '');
        $diffPath = (string) ($this->option('diff') ?? '');
        if ($sandbox === '' || $diffPath === '' || ! is_dir($sandbox) || ! is_file($diffPath)) {
            return $this->refuse('dry_run_requires_existing_sandbox_and_diff');
        }
        $diff = (string) file_get_contents($diffPath);
        $handle = new SandboxHandle(
            sandboxPath: $sandbox,
            sourceCommitSha: 'cli',
            dirtyFiles: [],
            createdAt: gmdate('Y-m-d\TH:i:s\Z'),
            contentChecksum: str_repeat('0', 64),
            seed: 'cli',
        );
        $receipt = $runner->run($handle, $diff);
        $receiptId = $ledger->append($receipt);

        $this->emit([
            'receipt_id' => $receiptId,
            'patch_apply_exit_code' => $receipt->patchApplyExitCode,
            'frozen_test_exit_code' => $receipt->frozenTestExitCode,
            'changed_files_count' => count($receipt->changedFiles),
        ]);

        return self::EXIT_OK;
    }

    private function history(AtlasLoopSimulationReceiptLedger $ledger): int
    {
        $limit = max(1, (int) ($this->option('limit') ?? 20));
        $since = (string) ($this->option('since-sha') ?? '');
        $rows = $ledger->history($limit, $since === '' ? null : $since);
        $this->emit($rows);

        return self::EXIT_OK;
    }

    private function masterEnabled(): bool
    {
        if (! function_exists('config')) {
            return false;
        }

        return (bool) config('atlas.loop.master_enabled', false);
    }

    /**
     * @param  array<int|string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        $this->getOutput()->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function refuse(string $reason): int
    {
        $this->getOutput()->writeln($reason);

        return self::EXIT_USAGE;
    }
}
