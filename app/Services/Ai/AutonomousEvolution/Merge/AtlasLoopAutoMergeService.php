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
        private readonly ?AtlasLoopAutoMergeReverseAuditor $reverseAuditor = null,
        private readonly ?AtlasLoopAutoMergeReceiptLedger $receiptLedger = null,
        private readonly ?AtlasLoopAutoMergeStalenessRefuser $stalenessRefuser = null,
    ) {}

    /**
     * Build + write one receipt covering the just-decided outcome. Every return path of {@see autoMerge()}
     * calls this exactly once before returning, so the ledger reflects every allow / refuse / merge / rollback.
     *
     * @param  array<string,mixed>  $preflight
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>|null  $reverseAudit
     */
    private function recordReceipt(
        array $preflight,
        array $proposal,
        ?string $conflictVerdict,
        ?array $reverseAudit,
        string $outcome,
        ?string $headShaAfter,
    ): void {
        if ($this->receiptLedger === null) {
            return;
        }
        $this->receiptLedger->record([
            'proposal_id' => (string) ($proposal['proposal_id'] ?? ($proposal['branch'] ?? '')),
            'base_sha' => (string) ($proposal['base_sha'] ?? ''),
            'head_sha_before' => $preflight['head_sha'] ?? null,
            'head_sha_after' => $headShaAfter,
            'gate_verdicts' => [
                'preflight' => (string) ($preflight['allow'] ?? false ? 'allow' : ($preflight['reason'] ?? 'refuse')),
                'conflict' => $conflictVerdict,
                'reverse' => $reverseAudit === null ? null : (string) ($reverseAudit['verdict'] ?? ''),
            ],
            'outcome' => $outcome,
        ]);
    }

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

        // §W14-05 STALENESS — cheapest fail-fast, runs BEFORE PreFlightGate/ConflictDetector/ReverseAuditor.
        // A base SHA more than N commits behind main is refused immediately (FACT: commits_behind via rev-list).
        if ($this->stalenessRefuser !== null) {
            $staleness = $this->stalenessRefuser->check($baseSha, $repoRoot);
            if (($staleness['allow'] ?? false) !== true) {
                return [
                    'merged' => false,
                    'reason' => (string) ($staleness['reason'] ?? 'stale_base'),
                    'preflight' => null,
                    'staleness' => $staleness,
                    'conflict_report' => null,
                    'merge_result' => null,
                ];
            }
        }

        $preflight = $this->preFlight->check($baseSha, $repoRoot);

        if (($preflight['allow'] ?? false) !== true) {
            // Fail-closed: the merge executor is NEVER invoked when the pre-flight gate refuses.
            $this->recordReceipt($preflight, $proposal, null, null, AtlasLoopAutoMergeReceiptLedger::OUTCOME_ALLOW_REFUSED_PREFLIGHT, $preflight['head_sha'] ?? null);

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
                $this->recordReceipt($preflight, $proposal, 'conflict', null, AtlasLoopAutoMergeReceiptLedger::OUTCOME_REFUSED_CONFLICT, $preflight['head_sha'] ?? null);

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

        $reverseAudit = null;
        if ($this->reverseAuditor !== null) {
            $preMergeSha = (string) ($preflight['head_sha'] ?? '');
            $mergeSha = (string) ($mergeResult['merge_sha'] ?? '');
            $reverseAudit = $this->reverseAuditor->audit($repoRoot, $preMergeSha, $mergeSha);
        }

        $merged = true;
        $reason = null;
        $outcome = AtlasLoopAutoMergeReceiptLedger::OUTCOME_MERGED;
        if ($reverseAudit !== null) {
            $verdict = (string) ($reverseAudit['verdict'] ?? '');
            if ($verdict === AtlasLoopAutoMergeReverseAuditor::VERDICT_ROLLED_BACK) {
                $merged = false;
                $reason = 'reverse_audit_rolled_back';
                $outcome = AtlasLoopAutoMergeReceiptLedger::OUTCOME_ROLLED_BACK;
            } elseif ($verdict === AtlasLoopAutoMergeReverseAuditor::VERDICT_REVERT_FAILED) {
                $merged = false;
                $reason = 'reverse_audit_revert_failed';
                $outcome = AtlasLoopAutoMergeReceiptLedger::OUTCOME_REVERT_FAILED;
            }
        }

        $headShaAfter = $reverseAudit['post_merge_sha'] ?? ($mergeResult['merge_sha'] ?? ($preflight['head_sha'] ?? null));
        $this->recordReceipt($preflight, $proposal, 'clean', $reverseAudit, $outcome, $headShaAfter === null ? null : (string) $headShaAfter);

        return [
            'merged' => $merged,
            'reason' => $reason,
            'preflight' => $preflight,
            'conflict_report' => $conflictReportArray,
            'merge_result' => $mergeResult,
            'reverse_audit' => $reverseAudit,
        ];
    }
}
