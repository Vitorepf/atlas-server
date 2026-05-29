<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

/**
 * Default {@see RepairValidationRunner}: runs the declared validation command
 * inside the AP-756 worktree via a local subprocess and returns its exit code.
 *
 * It is read-only with respect to merge/deploy — it only runs the operator-
 * declared validation command (e.g. `./vendor/bin/phpunit ...`) the same way
 * the senior loop would. A missing/non-directory worktree or empty command is
 * reported as `ran=false` so the executor treats it as "no validation gate"
 * rather than a silent pass.
 */
final class ShellRepairValidationRunner implements RepairValidationRunner
{
    /** Max captured validation output bytes kept on the result. */
    private const MAX_OUTPUT_BYTES = 16384;

    public function validate(string $worktree, string $command): array
    {
        $worktree = trim($worktree);
        $command = trim($command);
        if ($worktree === '' || ! is_dir($worktree) || $command === '') {
            return ['exit_code' => 0, 'output' => '', 'ran' => false];
        }

        $output = [];
        $exitCode = 0;
        $cwd = escapeshellarg($worktree);
        // The validation command is built by the executor from an allowlisted
        // validation_command and is shell-sanitized at the boundary; it is run
        // strictly inside the isolated worktree.
        exec('cd '.$cwd.' && '.$command.' 2>&1', $output, $exitCode);

        $joined = implode("\n", $output);
        if (strlen($joined) > self::MAX_OUTPUT_BYTES) {
            $joined = substr($joined, -self::MAX_OUTPUT_BYTES);
        }

        return [
            'exit_code' => (int) $exitCode,
            'output' => $joined,
            'ran' => true,
        ];
    }
}
