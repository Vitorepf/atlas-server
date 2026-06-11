<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeBaseline;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeContractView;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeObserved;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopePreExistingChange;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeViolation;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevPathPatternMatcher;

/**
 * Compares observed diff against the LightTaskContract scope contract and
 * emits a ScopeGuardReceipt. Pure: no I/O, no clock, no shell.
 *
 * Rules (mirror contracts doc 6.1):
 *   - allowed_files match → OK;
 *   - forbidden_files match → status=failed (KIND_FORBIDDEN_TOUCH);
 *   - watched_files match → status=needs_review (KIND_WATCHED_TOUCH);
 *   - file outside allowed and watched and forbidden → KIND_UNEXPECTED_TOUCH
 *     (needs_review);
 *   - count(changed_files) > max_files_changed → status=failed
 *     (KIND_EXCEEDED_MAX_FILES);
 *   - pre-existing user changes must be preserved; any preserved=false →
 *     status=failed (KIND_PRE_EXISTING_CHANGE + invariant 5).
 *
 * Pattern matching reuses the same glob/prefix/exact convention used by
 * ProgrammingScopeGuardGate.
 */
final class ScopeGuard
{
    public function check(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        DiffParseResult $diffResult,
        ?WorktreeBaseline $baseline = null,
    ): ScopeGuardReceipt {
        $baseline ??= WorktreeBaseline::clean();

        $changedFiles = $diffResult->hasPatch() ? $diffResult->changedFiles : [];

        $fileDiffs = $this->buildFileDiffs($changedFiles, $diffResult);

        $observed = new ScopeObserved(
            gitDiffHash: $diffResult->diffHash(),
            changedFiles: $changedFiles,
            changedFilesCount: count($changedFiles),
            fileDiffs: $fileDiffs,
        );

        $scopeContract = new ScopeContractView(
            allowedFiles: $taskContract->allowedFiles,
            watchedFiles: $taskContract->watchedFiles,
            forbiddenFiles: $taskContract->forbiddenFiles,
            expectedMaxFiles: $taskContract->maxFilesChanged,
        );

        $violations = [];

        // 1. forbidden_files (strongest, fails immediately even if also allowed)
        foreach ($changedFiles as $path) {
            if (AtlasDevPathPatternMatcher::matchesAny($path, $taskContract->forbiddenFiles)) {
                $violations[] = new ScopeViolation(
                    kind: ScopeViolation::KIND_FORBIDDEN_TOUCH,
                    path: $path,
                    detail: "diff touches forbidden path '{$path}'.",
                );
            }
        }

        // 2. watched + unexpected (only when not already forbidden)
        foreach ($changedFiles as $path) {
            if (AtlasDevPathPatternMatcher::matchesAny($path, $taskContract->forbiddenFiles)) {
                continue;
            }
            if (AtlasDevPathPatternMatcher::matchesAny($path, $taskContract->watchedFiles)) {
                $violations[] = new ScopeViolation(
                    kind: ScopeViolation::KIND_WATCHED_TOUCH,
                    path: $path,
                    detail: "diff touches watched path '{$path}' — operator review required.",
                );

                continue;
            }
            if (! AtlasDevPathPatternMatcher::matchesAny($path, $taskContract->allowedFiles)) {
                $violations[] = new ScopeViolation(
                    kind: ScopeViolation::KIND_UNEXPECTED_TOUCH,
                    path: $path,
                    detail: "diff touches '{$path}' which is not in allowed_files/watched_files/forbidden_files.",
                );
            }
        }

        // 3. max files exceeded
        $maxFiles = $taskContract->maxFilesChanged;
        if ($maxFiles > 0 && count($changedFiles) > $maxFiles) {
            $violations[] = new ScopeViolation(
                kind: ScopeViolation::KIND_EXCEEDED_MAX_FILES,
                path: null,
                detail: 'changed_files_count='.count($changedFiles).' exceeds max_files_changed='.$maxFiles.'.',
            );
        }

        // 4. pre-existing changes preservation
        $preExisting = $baseline->preExistingChanges;
        $diffPaths = array_flip($changedFiles);
        $finalPreExisting = [];
        foreach ($preExisting as $change) {
            // If the path appears in the diff and the upstream caller marked
            // preserved=false, surface the violation. If the caller marked
            // preserved=true we trust it (callers diff old vs new content).
            $preserved = $change->preserved;
            $finalPreExisting[] = new ScopePreExistingChange(
                path: $change->path,
                preserved: $preserved,
            );
            if (! $preserved) {
                $violations[] = new ScopeViolation(
                    kind: ScopeViolation::KIND_PRE_EXISTING_CHANGE,
                    path: $change->path,
                    detail: "pre-existing user change at '{$change->path}' was NOT preserved.",
                );

                continue;
            }
            // Even when preserved, if the diff also touches the path the
            // operator should review (could collide with their work).
            if (isset($diffPaths[$change->path])) {
                $violations[] = new ScopeViolation(
                    kind: ScopeViolation::KIND_PRE_EXISTING_CHANGE,
                    path: $change->path,
                    detail: "diff overlaps pre-existing user change at '{$change->path}'; preserved=true but operator review recommended.",
                );
            }
        }

        [$status, $reason] = $this->classifyStatus($violations);

        return ScopeGuardReceipt::issue(
            runId: $envelope->runId,
            taskContractHash: $taskContract->taskContractHash !== ''
                ? $taskContract->taskContractHash
                : $taskContract->hash(),
            baseline: new ScopeBaseline(
                gitStatusBefore: $baseline->gitStatusBefore,
                gitDiffBeforeHash: $baseline->gitDiffBeforeHash,
            ),
            observed: $observed,
            scopeContract: $scopeContract,
            violations: $violations,
            status: $status,
            statusReason: $reason,
            userPreExistingChanges: $finalPreExisting,
        );
    }

    /**
     * @param  list<string>  $changedFiles
     * @return list<ScopeFileDiff>
     */
    private function buildFileDiffs(array $changedFiles, DiffParseResult $diffResult): array
    {
        if (! $diffResult->hasPatch()) {
            return [];
        }
        $diffs = [];
        $diff = (string) $diffResult->diff;
        foreach ($changedFiles as $path) {
            $stats = $this->countAddedRemoved($diff, $path);
            $diffs[] = new ScopeFileDiff(
                path: $path,
                added: $stats['added'],
                removed: $stats['removed'],
                fileHashAfter: hash('sha256', $path.'|'.$stats['added'].'|'.$stats['removed']),
            );
        }

        return $diffs;
    }

    /**
     * @return array{added: int, removed: int}
     */
    private function countAddedRemoved(string $diff, string $path): array
    {
        // Naive but bounded: count lines starting with + / - within hunks for the
        // path. We don't have the actual file content (the diff itself), so the
        // hash uses path + counts as a stand-in deterministic surrogate.
        $added = 0;
        $removed = 0;
        $inTarget = false;
        foreach (preg_split('/\n/', $diff) ?: [] as $line) {
            if (preg_match('/^\+\+\+\s+(?:b\/)?(\S+)/', $line, $m)) {
                $inTarget = ($m[1] === $path);

                continue;
            }
            if (! $inTarget) {
                continue;
            }
            if (str_starts_with($line, '+') && ! str_starts_with($line, '++')) {
                $added++;
            } elseif (str_starts_with($line, '-') && ! str_starts_with($line, '--')) {
                $removed++;
            }
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * @param  list<ScopeViolation>  $violations
     * @return array{0: string, 1: string}
     */
    private function classifyStatus(array $violations): array
    {
        if ($violations === []) {
            return [ScopeGuardReceipt::STATUS_PASSED, 'no violations observed.'];
        }

        $hasFailing = false;
        $hasUnpreserved = false;
        foreach ($violations as $v) {
            if (in_array($v->kind, ScopeViolation::FAILING_KINDS, true)) {
                $hasFailing = true;
            }
            if ($v->kind === ScopeViolation::KIND_PRE_EXISTING_CHANGE
                && str_contains($v->detail, 'NOT preserved')) {
                $hasUnpreserved = true;
            }
        }

        if ($hasFailing || $hasUnpreserved) {
            return [ScopeGuardReceipt::STATUS_FAILED, 'diff violates scope contract: '.$this->summary($violations)];
        }

        return [ScopeGuardReceipt::STATUS_NEEDS_REVIEW, 'diff requires operator review: '.$this->summary($violations)];
    }

    /**
     * @param  list<ScopeViolation>  $violations
     */
    private function summary(array $violations): string
    {
        return implode(' | ', array_map(
            static fn (ScopeViolation $v): string => $v->kind.'@'.($v->path ?? '-'),
            $violations,
        ));
    }
}
