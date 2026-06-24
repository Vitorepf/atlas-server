<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Merge;

/**
 * WAVE-14 · AUTO-MERGE HARDENING — the auto-merge entry that is GATED by {@see AtlasLoopAutoMergePreFlightGate}.
 * Before any merge command runs, the pre-flight gate re-resolves main HEAD and refuses a STALE branch
 * (base_sha != main HEAD) with reason=main_moved. The merge executor is only ever invoked once the gate
 * ALLOWS — so a stale loop branch can never overwrite shared main.
 *
 * Anchored on the loop-cycle git contract (commit → merge-to-main → new-branch-from-FRESH-main). The actual
 * merge is delegated to an injectable executor (default: a no-op placeholder for this wave-14 brick) so the
 * gating is provable without a real git merge.
 */
final class AtlasLoopAutoMergeService
{
    public function __construct(private readonly AtlasLoopAutoMergePreFlightGate $preFlight) {}

    /**
     * Attempt an auto-merge for $proposal, gated by the pre-flight check. The merge executor is invoked ONLY
     * when the gate allows; on refusal it is never called and main is byte-identical.
     *
     * @param  array{base_sha?:string, branch?:string}  $proposal
     * @param  null|callable(array<string,mixed>, string):array<string,mixed>  $merger  the real merge action (injectable for tests)
     * @return array{merged:bool, reason:?string, preflight:array<string,mixed>, merge_result:?array<string,mixed>}
     */
    public function autoMerge(array $proposal, string $repoRoot, ?callable $merger = null): array
    {
        $baseSha = trim((string) ($proposal['base_sha'] ?? ''));
        $preflight = $this->preFlight->check($baseSha, $repoRoot);

        if (($preflight['allow'] ?? false) !== true) {
            // Fail-closed: the merge executor is NEVER invoked when the gate refuses.
            return [
                'merged' => false,
                'reason' => (string) ($preflight['reason'] ?? 'preflight_refused'),
                'preflight' => $preflight,
                'merge_result' => null,
            ];
        }

        $merger ??= fn (array $p, string $root): array => ['status' => 'merge_executor_default_noop'];
        $mergeResult = $merger($proposal, $repoRoot);

        return [
            'merged' => true,
            'reason' => null,
            'preflight' => $preflight,
            'merge_result' => $mergeResult,
        ];
    }
}
