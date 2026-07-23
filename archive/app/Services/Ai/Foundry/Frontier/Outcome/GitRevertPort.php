<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Outcome;

/**
 * Foundry AP-E · real-or-blocked git revert port (I5).
 *
 * On non-improvement the materializer reverts via `git revert --no-edit`
 * (NEVER `git reset --hard`) and runs the rollback verify_cmd. With no real
 * merged finding the live binding BLOCKS honestly. Tests use labelled Fake*
 * impls only — a real `git revert` is NEVER run in a test or proof.
 */
interface GitRevertPort
{
    /**
     * Revert the given merge commit. Implementations MUST use `git revert
     * --no-edit` and MUST NOT use `git reset --hard`.
     *
     * @return array{reverted:bool,revert_commit_hash:string,verify_passed:bool,detail:string}
     */
    public function revert(string $mergeHash, string $verifyCmd): array;
}
