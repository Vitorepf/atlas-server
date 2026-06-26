<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Support;

use Symfony\Component\Process\Process;

/**
 * ITEM8 — the cohesive stateless git helper the obra execution adapter needs to manage replay worktrees.
 *
 * Three methods (git / gitOutput / gitLines) are the only places the adapter shells out to `git -C <repo>`
 * during the obra proofing gates (aggregate-drop replay, full-certify net diff, node-interface contract,
 * changed-symbol census). Pure / stateless / 60s timeout / zero Laravel surface — extracted so the adapter
 * can split the cohesive probe logic out of its public signature without changing ANY caller-visible byte.
 *
 * Invariants (preserved verbatim from the god-class):
 *  - timeout 60s on every Process (matches the in-tree frozen-test default + the rest of the obra lane);
 *  - gitLines wraps gitOutput and trims/filter-empties (returns list<string>);
 *  - every method is safe to call against any path; failures are signalled by `false` / `null` / `[]`
 *    so the adapter's fail-OPEN guards stay byte-identical.
 */
class AtlasLoopObraGitWorktreeProbe
{
    /** @param  list<string>  $argv */
    public function git(string $repoRoot, array $argv): bool
    {
        $p = new Process(array_merge(['git', '-C', $repoRoot], $argv), null, null, null, 60.0);
        $p->run();

        return $p->isSuccessful();
    }

    /** @param  list<string>  $argv */
    public function gitOutput(string $repoRoot, array $argv): ?string
    {
        $p = new Process(array_merge(['git', '-C', $repoRoot], $argv), null, null, null, 60.0);
        $p->run();

        return $p->isSuccessful() ? $p->getOutput() : null;
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    public function gitLines(string $repoRoot, array $argv): array
    {
        $out = $this->gitOutput($repoRoot, $argv);
        if ($out === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $out) ?: []), static fn (string $l): bool => $l !== ''));
    }
}
