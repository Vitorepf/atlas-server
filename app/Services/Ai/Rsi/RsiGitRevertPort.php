<?php

declare(strict_types=1);

namespace App\Services\Ai\Rsi;

/**
 * Governed RSI · Part 3 · real-or-blocked git revert port for the META loop.
 *
 * When a HUMAN-APPROVED self-improvement to a loop component MERGED but did NOT
 * provably raise the target component's value-per-token, the meta materializer
 * reverts that self-improvement via `git revert --no-edit` (NEVER
 * `git reset --hard`). With no real merge hash or repo root the live binding
 * BLOCKS honestly (reverted=false) — it NEVER fabricates a revert and a real
 * `git revert` is NEVER run inside a test or a proof (tests use labelled Fake*
 * impls only).
 */
interface RsiGitRevertPort
{
    /**
     * Revert the given merged self-improvement commit on the repo's main branch.
     * Implementations MUST use `git revert --no-edit` and MUST NOT use
     * `git reset --hard`.
     *
     * @return array{reverted:bool,revert_commit_hash:string,detail:string}
     */
    public function revert(string $repoRoot, string $mergeHash): array;
}
