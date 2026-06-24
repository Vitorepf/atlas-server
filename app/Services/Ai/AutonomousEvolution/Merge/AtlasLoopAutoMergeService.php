<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Merge;

/**
 * WAVE-14 · AUTO-MERGE HARDENING — the auto-merge entry that is GATED by two fail-closed checks before the
 * merge executor is ever invoked:
 *   1. {@see AtlasLoopAutoMergePreFlightGate}                  : re-resolves main HEAD; refuses a STALE branch.
 *   2. {@see AtlasLoopAutoMergeConflictDetector} (when wired)  : 3-way merge-tree probe; refuses on conflict
 *      so the loop never overwrites a sibling worker's WIP on shared main. The detector emits FACTs only
 *      ({@see AtlasLoopAutoMergeConflictReport}); the only decision drawn here is `clean ? proceed : refuse`.
 *
 * Anchored on the loop-cycle git contract (commit → merge-to-main → new-branch-from-FRESH-main). The actual
 * merge is delegated to an injectable executor (default: a no-op placeholder for this wave-14 brick) so the
 * gating is provable without a real git merge.
 */
final class AtlasLoopAutoMergeService
{
    public function __construct(
        private readonly AtlasLoopAutoMergePreFlightGate $preFlight,
        private readonly ?AtlasLoopAutoMergeConflictDetector $conflictDetector = null,
    ) {}

    /**
     * Attempt an auto-merge for $proposal, gated by the pre-flight check AND (when wired) the conflict
     * detector. The merge executor is invoked ONLY when both gates allow; on refusal it is never called and
     * main is byte-identical.
     *
     * @param  array{base_sha?:string, branch?:string}  $proposal
     * @param  null|callable(array<string,mixed>, string):array<string,mixed>  $merger  the real merge action (injectable for tests)
     * @return array{merged:bool, reason:?string, preflight:array<string,mixed>, conflict_report:?array<string,mixed>, merge_result:?array<string,mixed>}
     */
    public function autoMerge(array $proposal, string $repoRoot, ?callable $merger = null): array
    {
        $baseSha = trim((string) ($proposal['base_sha'] ?? ''));
        $preflight = $this->preFlight->check($baseSha, $repoRoot);

        if (($preflight['allow'] ?? false) !== true) {
            // Fail-closed: the merge executor is NEVER invoked when the pre-flight gate refuses.
            return [
                'merged' => false,
                'reason' => (string) ($preflight['reason'] ?? 'preflight_refused'),
                'preflight' => $preflight,
                'conflict_report' => null,
                'merge_result' => null,
            ];
        }

        $conflictReportArray = null;
        if ($this->conflictDetector !== null) {
            $branchRef = trim((string) ($proposal['branch'] ?? ''));
            $mainSha = (string) ($preflight['head_sha'] ?? '');
            $report = $this->conflictDetector->detect($repoRoot, $mainSha, $branchRef);
            $conflictReportArray = $report->toArray();
            if (! $report->clean) {
                // Fail-closed: a non-clean conflict report refuses the merge with reason=conflict.
                return [
                    'merged' => false,
                    'reason' => 'conflict',
                    'preflight' => $preflight,
                    'conflict_report' => $conflictReportArray,
                    'merge_result' => null,
                ];
            }
        }

        $merger ??= fn (array $p, string $root): array => ['status' => 'merge_executor_default_noop'];
        $mergeResult = $merger($proposal, $repoRoot);

        return [
            'merged' => true,
            'reason' => null,
            'preflight' => $preflight,
            'conflict_report' => $conflictReportArray,
            'merge_result' => $mergeResult,
        ];
    }
}
