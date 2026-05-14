<?php

namespace App\Services\Ai\Programming\Sdd;

use App\Models\AtlasDecisionReceipt;
use App\Services\Ai\Programming\Sdd\Pipeline\ExecutionResult;

/**
 * Bounded repair loop. Tries to re-run the failed validation commands while
 * staying inside the receipt scope. Never widens the scope, never escalates
 * autonomy.
 */
class RepairLoop
{
    public function __construct(
        private readonly RuntimeExecutor $executor,
    ) {}

    /**
     * @param  callable(string):bool|null  $commandRunner
     */
    public function repair(
        AtlasDecisionReceipt $receipt,
        ExecutionResult $previous,
        ?callable $commandRunner = null,
        int $maxAttempts = 1,
    ): ExecutionResult {
        if ($previous->ok()) {
            return $previous;
        }
        if (! $receipt->isActive()) {
            return new ExecutionResult(
                status: 'rejected',
                issues: [['code' => 'receipt_inactive_during_repair']],
            );
        }

        $failedCommands = $this->extractFailedCommands($previous);
        if ($failedCommands === []) {
            return $previous;
        }

        $current = $previous;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $result = $this->executor->execute(
                receipt: $receipt,
                proposedWrites: [],
                proposedCommands: $failedCommands,
                evidenceRefs: array_merge($previous->evidenceRefs, ["repair-attempt:{$attempt}"]),
                commandRunner: $commandRunner,
            );
            $current = $result;
            if ($result->ok()) {
                return $result;
            }
            $failedCommands = $this->extractFailedCommands($result);
            if ($failedCommands === []) {
                break;
            }
        }

        return $current;
    }

    /**
     * @return list<string>
     */
    private function extractFailedCommands(ExecutionResult $result): array
    {
        $commands = [];
        foreach ($result->issues as $issue) {
            if (($issue['code'] ?? null) === 'command_failed' && is_string($issue['command'] ?? null)) {
                $commands[] = $issue['command'];
            }
        }

        return array_values(array_unique($commands));
    }
}
