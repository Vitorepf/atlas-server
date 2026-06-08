<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopProposalOutOfProcessVerifier;
use Illuminate\Console\Command;

/**
 * Re-proves persisted unified-loop proposals outside the original loop run.
 *
 * It reads proposals.jsonl, creates a clean git worktree per proposal, reruns
 * the frozen verifier in a child process, proves revert-to-RED, and writes the
 * independent verdict as JSONL. Propose-only: it never applies to main.
 */
final class AtlasLoopVerifyProposalsCommand extends Command
{
    protected $signature = 'atlas:loop:verify-proposals
        {--run= : Unified loop run id (default: most recent)}
        {--proposals= : Explicit proposals.jsonl path}
        {--repo= : Repo root to verify against (default: base_path)}
        {--limit=0 : Max proposals to process this invocation (0 = all)}
        {--refuters=0 : Provider refuters requested; deterministic replay runs even when 0}
        {--refuter-provider= : Provider key to use for future adversarial refuters}
        {--force : Rebuild independent verdict files instead of skipping existing hashes}
        {--json : Print machine-readable summary}';

    protected $description = 'Independently re-prove unified-loop proposals in a clean worktree. Writes independently_verified.jsonl and refuted.jsonl.';

    public function handle(AtlasLoopProposalOutOfProcessVerifier $verifier): int
    {
        $root = storage_path('atlas/loop/unified');
        $runId = trim((string) $this->option('run'));
        $runDir = $runId !== '' ? $root.'/'.$runId : $this->latestRunDir($root);
        if ($runDir === null || ! is_dir($runDir)) {
            $this->error('No unified loop run found'.($runId !== '' ? ' for '.$runId : '').'.');

            return self::FAILURE;
        }

        $proposals = trim((string) $this->option('proposals'));
        if ($proposals === '') {
            $proposals = $runDir.'/proposals.jsonl';
        }
        $repo = rtrim(trim((string) $this->option('repo')) ?: base_path(), '/');

        $summary = $verifier->verifyFile($repo, $runDir, $proposals, [
            'limit' => (int) $this->option('limit'),
            'refuters' => (int) $this->option('refuters'),
            'refuter_provider' => trim((string) $this->option('refuter-provider')) ?: null,
            'force' => (bool) $this->option('force'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($summary['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Independent proposal verification</>', basename($runDir));
        $this->components->twoColumnDetail('Processed', (string) ($summary['processed'] ?? 0));
        $this->components->twoColumnDetail('Independently verified', '<fg=green>'.($summary['independently_verified'] ?? 0).'</>');
        $this->components->twoColumnDetail('Refuted', '<fg=yellow>'.($summary['refuted'] ?? 0).'</>');
        $this->components->twoColumnDetail('Skipped existing', (string) ($summary['skipped_existing'] ?? 0));
        $this->components->twoColumnDetail('Provider refuters executed', (string) ($summary['provider_refuters_executed'] ?? 0));
        $this->line('  <fg=gray>files: '.$runDir.'/{independently_verified.jsonl, refuted.jsonl, independent_verification_summary.json}</>');

        return ($summary['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    private function latestRunDir(string $root): ?string
    {
        if (! is_dir($root)) {
            return null;
        }
        $dirs = glob($root.'/run-*', GLOB_ONLYDIR) ?: [];
        if ($dirs === []) {
            return null;
        }
        usort($dirs, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $dirs[0];
    }
}
