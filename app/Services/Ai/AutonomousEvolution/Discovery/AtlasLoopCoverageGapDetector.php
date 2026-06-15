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
     * Cosmetic mutation operators (mirror of AtlasLoopMutationAdequacyGateService::COSMETIC_OPERATORS).
     * A surviving COSMETIC mutant is NOT an actionable coverage gap — flipping a string literal does
     * not change behavior, and the decision-aware gate is meant to SKIP (not reject) it. Only a
     * surviving DECISION mutant marks logic a characterization test must pin.
     */
    private const COSMETIC_OPERATORS = [
        'string_literal' => true,
        'return_string_literal' => true,
    ];

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

        $gaps = [];
        $seen = [];
        foreach ($reports as $report) {
            if (! is_array($report) || ($report['certified'] ?? null) !== false) {
                continue;
            }
            // Only the mutation-adequacy coverage gap — NOT every rejection (a decisions-gate or
            // cross-file refusal is a different problem the test lane cannot fix).
            $reasons = is_array($report['reasons'] ?? null) ? $report['reasons'] : [];
            if (! in_array('mutation_adequacy_gate:mutation_survived', $reasons, true)) {
                continue;
            }
            if (data_get($report, 'mutation_adequacy_gate.status') !== 'mutation_survived') {
                continue;
            }

            $mutants = data_get($report, 'mutation_adequacy_gate.mutants');
            if (! is_array($mutants)) {
                continue;
            }
            foreach ($mutants as $mutant) {
                if (! is_array($mutant) || ($mutant['survived'] ?? null) !== true) {
                    continue;
                }
                $file = (string) ($mutant['file'] ?? '');
                $operator = (string) ($mutant['operator'] ?? '');
                $mutationId = (string) ($mutant['mutation_id'] ?? '');
                // Never emit a partial gap — a downstream test task with no target/mutant is unactionable.
                if ($file === '' || $operator === '' || $mutationId === '') {
                    continue;
                }
                // A surviving COSMETIC mutant is not a behavior gap — skip (the decision-aware gate
                // should not even reject on it).
                if (isset(self::COSMETIC_OPERATORS[$operator])) {
                    continue;
                }
                $sibling = $this->siblingTestFromCommands($mutant['command_results'] ?? null);
                // Drop self-contained-synth FIXTURE noise (src/* targets, generated siblings) and the
                // meaningless case of "refactoring a test file" — none of these is a real refactor of
                // production code a characterization test should chase.
                if (! $this->isActionableTarget($file, $sibling)) {
                    continue;
                }
                $key = $file.'|'.$mutationId;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $gaps[] = [
                    'target_file' => $file,
                    'decision_operator' => $operator,
                    'mutation_id' => $mutationId,
                    'mutant_hash' => (string) ($mutant['mutant_hash'] ?? ''),
                    'sibling_test' => $this->siblingTestFromCommands($mutant['command_results'] ?? null),
                ];
            }
        }

        return $gaps;
    }

    /**
     * Is this a real refactor of production code worth a characterization test? Excludes the
     * self-contained-synth FIXTURE workspace (src/* targets + generated `atlas_generated_*` siblings)
     * and the meaningless "refactor of a test file" case.
     */
    private function isActionableTarget(string $file, ?string $sibling): bool
    {
        $norm = str_replace('\\', '/', $file);
        if (str_ends_with($norm, 'Test.php')) {
            return false; // refactoring a test file is not a production-code refactor
        }
        if (str_starts_with($norm, 'src/')) {
            return false; // self-contained materialized fixture, not a real repo file
        }
        if ($sibling !== null && str_contains(str_replace('\\', '/', $sibling), 'atlas_generated_')) {
            return false; // generated fixture test, not a real sibling to strengthen
        }

        return true;
    }

    /**
     * The sibling test the gate actually ran (the one that FAILED to kill the mutant) — the file a
     * characterization test must strengthen. Parsed from the recorded phpunit invocation.
     *
     * @param  mixed  $commandResults
     */
    private function siblingTestFromCommands($commandResults): ?string
    {
        if (! is_array($commandResults)) {
            return null;
        }
        foreach ($commandResults as $cr) {
            $cmd = is_array($cr) ? (string) ($cr['command'] ?? '') : '';
            if (preg_match('#(tests/[^\s\'"]+\.php)#', $cmd, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
