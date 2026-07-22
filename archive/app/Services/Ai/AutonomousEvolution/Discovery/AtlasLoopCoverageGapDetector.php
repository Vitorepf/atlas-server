<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * Turns the buried mutation-survival evidence in a refactor grind RESULT into the precise,
 * actionable coverage gaps that BLOCKED certification: the (target file, surviving decision
 * mutant, sibling test) tuples where the mutation-adequacy gate refused to certify because the
 * existing test does NOT kill a mutation of a decision the refactor relocated.
 *
 * Why this exists (2026-06-15 diagnosis): refactor CONVERSION (~17% live) is gated by the target's
 * existing test coverage, and the gate is CORRECT — it will not certify a behavior-preserving claim
 * whose relocated logic is not behavior-pinned. The unlock is additive (raise coverage, never lower
 * the bar): generate the characterization test that kills the surviving mutant, then the refactor
 * re-certifies. This detector is that lane's input; standalone it answers "which refactors are
 * blocked by which missing test coverage" (read-only, side-effect-free).
 */
final class AtlasLoopCoverageGapDetector
{
    /**
     * @param  array<string,mixed>  $taskResult  the persisted grind result (atlas_loop_tasks.result)
     * @return list<array{target_file:string, decision_operator:string, mutation_id:string, mutant_hash:string, sibling_test:?string}>
     */
    public function gapsFromTaskResult(array $taskResult): array
    {
        $reports = data_get($taskResult, 'semantic_implementation_certification.reports');
        if (! is_array($reports)) {
            return [];
        }

        $support = new AtlasLoopCoverageGapDetectorSupport();

        return $support->gapsFromReports($reports);
    }
}
