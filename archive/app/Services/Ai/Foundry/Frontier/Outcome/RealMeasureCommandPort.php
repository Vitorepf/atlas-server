<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Outcome;

/**
 * Foundry AP-E · REAL measurement port for the materializer's Outcome\MeasureCommandPort
 * contract (run(measureCmd, property)).
 *
 * AP-E INVIOLABLE RULE (I5 Measured-or-Reverted): a numeric is produced ONLY when
 * the proposal-bound success_metric measure_cmd ran for REAL against a resolvable
 * merged AFEF-origin worktree. With no real merged finding to measure, the worktree
 * does NOT resolve and this BLOCKS honestly (ran=false, exit_code=-1, empty stdout)
 * — it NEVER fabricates a numeric. The materializer treats ran=false / non-zero exit
 * as non-improvement and reverts. A non-read-only command is refused BEFORE any
 * process spawns. No real git revert is ever run here (revert is a separate port).
 *
 * Mirrors FoundryEvidenceVerifierService::gitCommitPresent: Symfony Process,
 * setTimeout(15.0), read-only allowlist. The merged-finding worktree is sourced
 * from the FOUNDRY_AFEF_WORKTREE env var; absent/non-dir => honest block.
 */
final class RealMeasureCommandPort implements MeasureCommandPort
{
    /** @var list<string> read-only command heads (mirror verifier allowlist) */
    private const READ_ONLY_ALLOWLIST = [
        'php', 'git', 'grep', 'rg', 'wc', 'cat', 'find', 'ls',
        'composer', 'phpunit', 'vendor/bin/phpunit', 'artisan',
    ];

    public function __construct(
        private readonly float $timeout = 15.0,
    ) {}

    /**
     * @return array{ran:bool,exit_code:int,stdout:string,stderr:string}
     */
    public function run(string $measureCmd, string $property): array
    {
        $cmd = trim($measureCmd);
        if ($cmd === '' || trim($property) === '') {
            return $this->blocked();
        }

        // Refuse a non-read-only command BEFORE spawning any process.
        if (! $this->isReadOnly($cmd)) {
            return $this->blocked();
        }

        // No real merged AFEF-origin finding => no resolvable worktree => honest block.
        $worktree = (string) (getenv('FOUNDRY_AFEF_WORKTREE') ?: '');
        if ($worktree === '' || ! is_dir($worktree)) {
            return $this->blocked();
        }

        if (! class_exists(\Symfony\Component\Process\Process::class)) {
            return $this->blocked();
        }

        $process = \Symfony\Component\Process\Process::fromShellCommandline($cmd, $worktree);
        $process->setTimeout($this->timeout);
        try {
            $process->run();
        } catch (\Throwable) {
            return $this->blocked();
        }

        return [
            'ran' => true,
            'exit_code' => (int) ($process->getExitCode() ?? -1),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    private function isReadOnly(string $cmd): bool
    {
        $forbidden = ['>', '>>', 'rm ', 'mv ', 'git revert', 'git reset', 'git checkout', 'git commit', 'git push', 'git add', 'tee '];
        foreach ($forbidden as $token) {
            if (str_contains($cmd, $token)) {
                return false;
            }
        }

        $head = explode(' ', $cmd, 2)[0];

        return in_array($head, self::READ_ONLY_ALLOWLIST, true);
    }

    /**
     * Canonical honest block shape. exit_code=-1 + empty stdout => the
     * materializer never reads a numeric and never consolidates (I5).
     *
     * @return array{ran:false,exit_code:int,stdout:string,stderr:string}
     */
    private function blocked(): array
    {
        return ['ran' => false, 'exit_code' => -1, 'stdout' => '', 'stderr' => 'no_real_merged_finding'];
    }
}
