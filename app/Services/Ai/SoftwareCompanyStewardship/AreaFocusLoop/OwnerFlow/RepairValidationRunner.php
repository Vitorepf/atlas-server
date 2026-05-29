<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

/**
 * Pre-return validation seam for the AP-786 repair loop.
 *
 * After the repair agent produces a fix, {@see Ap786OwnerFlowExecutor} must
 * re-validate it inside the AP-756 worktree BEFORE claiming `repaired=true`.
 * This seam runs the declared validation command and returns the exit code so
 * the executor never trusts a repair result blindly. It is implemented by
 * {@see ShellRepairValidationRunner} in production and by a fake in tests so the
 * unit suite never shells out.
 */
interface RepairValidationRunner
{
    /**
     * Run a validation command inside the given worktree.
     *
     * @return array{exit_code:int,output:string,ran:bool}
     *                                                     - exit_code: process exit code (non-zero => validation failed)
     *                                                     - output:    captured combined stdout/stderr (truncated)
     *                                                     - ran:       whether a command was actually executed
     */
    public function validate(string $worktree, string $command): array;
}
