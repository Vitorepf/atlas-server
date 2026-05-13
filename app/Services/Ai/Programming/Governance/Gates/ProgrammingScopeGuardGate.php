<?php

namespace App\Services\Ai\Programming\Governance\Gates;

use App\Models\AtlasProgrammingWorkItem;
use Throwable;

/**
 * Scope guard: compares files declared in evidence receipts (and, when the
 * workspace is a git repo, the actual `git diff --name-only` output) against
 * the union of `allowed_files` declared across the work item's task contracts.
 *
 * Anything outside that allow-list — or matching the explicit `forbidden_files`
 * list — is a blocking failure. A task contract without `allowed_files` is a
 * configuration smell and is itself flagged.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md (Contrato 3)
 */
class ProgrammingScopeGuardGate implements ProgrammingGateContract
{
    public function name(): string
    {
        return 'scope-guard';
    }

    public function evaluate(AtlasProgrammingWorkItem $workItem): ProgrammingGateOutcome
    {
        $allowed = [];
        $forbidden = [];
        $tasksWithoutAllowed = 0;
        foreach ((array) $workItem->tasks_json as $task) {
            if (! is_array($task)) {
                continue;
            }
            $taskAllowed = array_values(array_filter((array) ($task['allowed_files'] ?? []), 'is_string'));
            $taskForbidden = array_values(array_filter((array) ($task['forbidden_files'] ?? []), 'is_string'));
            if ($taskAllowed === []) {
                $tasksWithoutAllowed++;
            }
            $allowed = array_merge($allowed, $taskAllowed);
            $forbidden = array_merge($forbidden, $taskForbidden);
        }
        $allowed = array_values(array_unique($allowed));
        $forbidden = array_values(array_unique($forbidden));

        if ($allowed === [] && $tasksWithoutAllowed === 0) {
            return ProgrammingGateOutcome::skipped(
                'no_task_contracts_attached_yet',
                ['allowed_count' => 0],
                blocking: false,
            );
        }
        if ($allowed === []) {
            return ProgrammingGateOutcome::failed(
                'task_contract_missing_allowed_files',
                ['tasks_without_allowed_files' => $tasksWithoutAllowed],
            );
        }

        $declaredFiles = $this->filesFromEvidence($workItem);
        $observedFiles = $this->filesFromGit($workItem);
        $allFiles = array_values(array_unique(array_merge($declaredFiles, $observedFiles)));

        $outOfScope = [];
        $forbiddenHits = [];
        foreach ($allFiles as $file) {
            if ($this->matchesAny($file, $forbidden)) {
                $forbiddenHits[] = $file;

                continue;
            }
            if (! $this->matchesAny($file, $allowed)) {
                $outOfScope[] = $file;
            }
        }

        if ($forbiddenHits !== [] || $outOfScope !== []) {
            return ProgrammingGateOutcome::failed(
                'files_outside_task_contract',
                [
                    'allowed_count' => count($allowed),
                    'forbidden_hits' => $forbiddenHits,
                    'out_of_scope' => $outOfScope,
                    'declared_files_count' => count($declaredFiles),
                    'observed_git_files_count' => count($observedFiles),
                ],
            );
        }

        return ProgrammingGateOutcome::passed([
            'allowed_count' => count($allowed),
            'declared_files_count' => count($declaredFiles),
            'observed_git_files_count' => count($observedFiles),
            'verified_files' => $allFiles,
        ]);
    }

    /**
     * @return list<string>
     */
    private function filesFromEvidence(AtlasProgrammingWorkItem $workItem): array
    {
        $files = [];
        foreach ((array) $workItem->evidence_refs_json as $receipt) {
            if (! is_array($receipt)) {
                continue;
            }
            foreach ((array) ($receipt['files'] ?? []) as $file) {
                if (is_string($file) && trim($file) !== '') {
                    $files[] = trim($file);
                }
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Only reads `git diff --name-only` when the work item declares an
     * explicit `workspace`. We never silently inspect base_path() — a dev
     * machine with unrelated unstaged work would poison every gate run.
     *
     * @return list<string>
     */
    private function filesFromGit(AtlasProgrammingWorkItem $workItem): array
    {
        $workspace = $workItem->workspace;
        if (! is_string($workspace) || trim($workspace) === '') {
            return [];
        }
        if (! is_dir($workspace) || ! is_dir($workspace.'/.git')) {
            return [];
        }

        try {
            $cmd = sprintf('git -C %s diff --name-only HEAD 2>/dev/null', escapeshellarg($workspace));
            $output = @shell_exec($cmd);
            if (! is_string($output)) {
                return [];
            }
            $lines = preg_split('/\r?\n/', $output, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_values(array_filter(
                array_map('trim', $lines),
                static fn (string $line): bool => $line !== '',
            ));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAny(string $file, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $file) {
                return true;
            }
            // Glob-style matching for `app/Foo/*.php`, `app/**`, etc.
            if (str_contains($pattern, '*') && fnmatch($pattern, $file, FNM_NOESCAPE)) {
                return true;
            }
            // Treat trailing slash patterns as directory prefix: `app/Foo/`.
            if (str_ends_with($pattern, '/') && str_starts_with($file, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
