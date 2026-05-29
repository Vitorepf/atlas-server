<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Support\AtlasSecurity;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Real PHP-tier runner. CRITICAL design notes (from adversarial review):
 *  - Runs PHPStan from the MAIN repo root (the sandbox worktree is gitignored
 *    vendor-free; the binary + autoloader live in the main vendor/), analyzing
 *    the worktree copies of the changed files by ABSOLUTE path. PHPStan resolves
 *    new interdependent classes because they are all in the analyzed set.
 *  - Skips paths that do not exist on disk (handles git-porcelain quoting/renames
 *    and deletions safely: a path it cannot read is skipped, never a spurious block).
 *  - Per-worktree tmpDir so concurrent cycles never corrupt a shared result cache.
 *  - A timeout is reported distinctly (status=timeout) — "could not verify", not
 *    "found errors" — so the caller fails closed without mislabeling.
 */
final class ShellCyclePhpTierRunner implements CyclePhpTierRunner
{
    public function run(string $tool, string $repoRoot, string $worktree, array $files): array
    {
        if ($tool !== 'phpstan') {
            return $this->result($tool, '', 'crashed', 255, 'unknown_tool:'.$tool, 0);
        }

        $root = rtrim($repoRoot !== '' ? $repoRoot : $worktree, '/');
        $wt = rtrim($worktree !== '' ? $worktree : $repoRoot, '/');

        // Only analyze files that actually exist in the worktree (skip-not-block).
        $abs = [];
        foreach ($files as $rel) {
            $rel = ltrim((string) $rel, '/');
            if ($rel === '') {
                continue;
            }
            $path = $wt.'/'.$rel;
            if (is_file($path) && is_readable($path)) {
                $abs[] = $path;
            }
        }
        if ($abs === []) {
            return $this->result($tool, '', 'nothing_to_analyze', 0, '', 0);
        }

        $escaped = implode(' ', array_map(static fn (string $f): string => escapeshellarg($f), $abs));
        // tmpDir/result-cache location is set in phpstan.neon (no --tmp-dir CLI
        // flag in PHPStan 2.x). The loop runs one cycle at a time (lock per
        // area/focus), so the shared result cache is safe.
        $command = 'vendor/bin/phpstan analyse --configuration=phpstan.neon'
            .' --memory-limit=1G --no-progress --no-interaction --error-format=raw '.$escaped;

        $process = Process::fromShellCommandline($command, $root, AtlasSecurity::processEnv(profile: 'tool'));
        $process->setTimeout(180);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return $this->result($tool, $command, 'timeout', 124, 'phpstan analysis timed out after 180s (could not verify)', count($abs));
        }

        $exit = (int) ($process->getExitCode() ?? 255);
        $output = substr(
            AtlasSecurity::redactString(trim($process->getOutput()."\n".$process->getErrorOutput())),
            0,
            2000,
        );

        // PHPStan: 0 = clean, 1 = analysis errors found (block), other = crash (fail-closed).
        $status = match (true) {
            $exit === 0 => 'passed',
            $exit === 1 => 'failed',
            default => 'crashed',
        };

        return $this->result($tool, $command, $status, $exit, $output, count($abs));
    }

    /**
     * @return array{tool:string,command:string,status:string,exit_code:int,output:string,analyzed:int}
     */
    private function result(string $tool, string $command, string $status, int $exit, string $output, int $analyzed): array
    {
        return [
            'tool' => $tool,
            'command' => $command,
            'status' => $status,
            'exit_code' => $exit,
            'output' => $output,
            'analyzed' => $analyzed,
        ];
    }
}
