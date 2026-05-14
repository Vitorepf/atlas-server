<?php

namespace App\Services\Ai\Programming\Sdd;

use App\Models\AtlasDecisionReceipt;
use App\Services\Ai\Programming\Sdd\Pipeline\ExecutionResult;

/**
 * Receipt-bounded runtime executor.
 *
 * Hard law (plan-task-and-receipt-contract.md:205):
 *   "Runtime may edit only what the receipt allows."
 *
 * This service does not call providers directly — it acts as the gating
 * boundary. Provider integration callers must hand each proposed file write
 * through `tryWrite()` so the decision is recorded and refused when out of
 * scope.
 */
class RuntimeExecutor
{
    public function __construct(
        private readonly DecisionEngine $decisions,
    ) {}

    /**
     * Execute a batch of file writes and command runs against the receipt.
     *
     * @param  list<array{path:string,contents:string}>  $proposedWrites
     * @param  list<string>  $proposedCommands
     * @param  list<string>  $evidenceRefs
     * @param  callable(string):bool|null  $commandRunner  invoke a validation command, return true on success.
     */
    public function execute(
        AtlasDecisionReceipt $receipt,
        array $proposedWrites = [],
        array $proposedCommands = [],
        array $evidenceRefs = [],
        ?string $workspace = null,
        ?callable $commandRunner = null,
    ): ExecutionResult {
        if (! $receipt->isActive()) {
            return new ExecutionResult(
                status: 'rejected',
                issues: [['code' => 'receipt_inactive', 'detail' => 'Receipt is not active.']],
            );
        }

        $written = [];
        $rejected = [];
        $issues = [];

        $workspace = is_string($workspace) && $workspace !== '' && is_dir($workspace) ? rtrim($workspace, '/') : null;

        foreach ($proposedWrites as $write) {
            $path = (string) ($write['path'] ?? '');
            $contents = (string) ($write['contents'] ?? '');
            if ($path === '') {
                $rejected[] = '(empty path)';
                $issues[] = ['code' => 'empty_path'];

                continue;
            }
            if (! $this->decisions->authorizesFileWrite($receipt, $path)) {
                $rejected[] = $path;
                $issues[] = ['code' => 'out_of_scope_write', 'path' => $path];

                continue;
            }
            if ($workspace === null) {
                $written[] = $path; // Dry-run when no workspace declared.
                continue;
            }
            $absolute = $workspace.'/'.ltrim($path, '/');
            $dir = dirname($absolute);
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true)) {
                $issues[] = ['code' => 'mkdir_failed', 'path' => $dir];
                $rejected[] = $path;

                continue;
            }
            if (@file_put_contents($absolute, $contents) === false) {
                $issues[] = ['code' => 'write_failed', 'path' => $path];
                $rejected[] = $path;

                continue;
            }
            $written[] = $path;
        }

        $commandsExecuted = [];
        $allowsCommands = in_array('run_validation_commands', (array) $receipt->allowed_actions_json, true);
        foreach ($proposedCommands as $cmd) {
            if (! $allowsCommands) {
                $issues[] = ['code' => 'commands_not_allowed', 'command' => $cmd];

                continue;
            }
            if ($commandRunner === null) {
                $commandsExecuted[] = $cmd; // Recorded but not actually run.

                continue;
            }
            $ok = (bool) $commandRunner($cmd);
            $commandsExecuted[] = $cmd;
            if (! $ok) {
                $issues[] = ['code' => 'command_failed', 'command' => $cmd];
            }
        }

        $blockingIssues = array_values(array_filter(
            $issues,
            static fn (array $i): bool => in_array($i['code'] ?? '', ['command_failed', 'out_of_scope_write', 'write_failed'], true),
        ));

        $status = match (true) {
            $blockingIssues !== [] && $written === [] && $commandsExecuted === [] => 'rejected',
            $blockingIssues !== [] => 'partial',
            $written === [] && $commandsExecuted === [] => 'noop',
            default => 'ok',
        };

        $outputHash = hash('sha256', json_encode([$written, $commandsExecuted, $issues], JSON_UNESCAPED_SLASHES) ?: '');

        return new ExecutionResult(
            status: $status,
            writtenFiles: $written,
            rejectedFiles: $rejected,
            commandsExecuted: $commandsExecuted,
            evidenceRefs: $evidenceRefs,
            issues: $issues,
            outputHash: $outputHash,
        );
    }
}
