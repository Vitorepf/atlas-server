<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMutationOperators;

final class AtlasLoopCoverageGapDetectorSupport
{
    /**
     * @param  array<int,mixed>  $reports
     * @return list<array{target_file:string, decision_operator:string, mutation_id:string, mutant_hash:string, sibling_test:?string}>
     */
    public function gapsFromReports(array $reports): array
    {
        $gaps = [];
        $seen = [];

        foreach ($reports as $report) {
            if (! $this->isBlockedCoverageGapReport($report)) {
                continue;
            }

            array_push($gaps, ...$this->gapsFromReport($report, $seen));
        }

        return $gaps;
    }

    /**
     * @param  mixed  $report
     */
    public function isBlockedCoverageGapReport($report): bool
    {
        if (! is_array($report) || ($report['certified'] ?? null) !== false) {
            return false;
        }

        // Only the mutation-adequacy coverage gap — NOT every rejection (a decisions-gate or
        // cross-file refusal is a different problem the test lane cannot fix).
        $reasons = is_array($report['reasons'] ?? null) ? $report['reasons'] : [];
        if (! in_array('mutation_adequacy_gate:mutation_survived', $reasons, true)) {
            return false;
        }

        return data_get($report, 'mutation_adequacy_gate.status') === 'mutation_survived';
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,bool>  $seen
     * @return list<array{target_file:string, decision_operator:string, mutation_id:string, mutant_hash:string, sibling_test:?string}>
     */
    public function gapsFromReport(array $report, array &$seen): array
    {
        $gaps = [];
        foreach ($this->mutantsFromReport($report) as $mutant) {
            $gap = $this->gapCandidateFromMutant($mutant);
            if ($gap === null || $this->seenGap($gap, $seen)) {
                continue;
            }

            $this->markGapSeen($gap, $seen);
            $gaps[] = $gap;
        }

        return $gaps;
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<int,mixed>
     */
    public function mutantsFromReport(array $report): array
    {
        $mutants = data_get($report, 'mutation_adequacy_gate.mutants');

        return is_array($mutants) ? $mutants : [];
    }

    /**
     * @param  mixed  $mutant
     * @return ?array{target_file:string, decision_operator:string, mutation_id:string, mutant_hash:string, sibling_test:?string}
     */
    public function gapCandidateFromMutant($mutant): ?array
    {
        if (! $this->isSurvivingMutant($mutant)) {
            return null;
        }

        $file = (string) ($mutant['file'] ?? '');
        $operator = (string) ($mutant['operator'] ?? '');
        $mutationId = (string) ($mutant['mutation_id'] ?? '');

        if (! $this->hasRequiredGapFields($file, $operator, $mutationId)) {
            return null;
        }

        $sibling = $this->siblingTestFromCommands($mutant['command_results'] ?? null);
        if (! $this->isActionableTarget($file, $sibling)) {
            return null;
        }

        return [
            'target_file' => $file,
            'decision_operator' => $operator,
            'mutation_id' => $mutationId,
            'mutant_hash' => (string) ($mutant['mutant_hash'] ?? ''),
            'sibling_test' => $sibling,
        ];
    }

    /**
     * @param  mixed  $mutant
     */
    public function isSurvivingMutant($mutant): bool
    {
        return is_array($mutant) && ($mutant['survived'] ?? null) === true;
    }

    public function hasRequiredGapFields(string $file, string $operator, string $mutationId): bool
    {
        // Never emit a partial gap — a downstream test task with no target/mutant is unactionable.
        if ($file === '' || $operator === '' || $mutationId === '') {
            return false;
        }

        // A surviving COSMETIC mutant is not a behavior gap — skip (the decision-aware gate
        // should not even reject on it). Single source: the shared operators class.
        return ! AtlasLoopMutationOperators::isCosmetic($operator);
    }

    /**
     * @param  array{target_file:string, mutation_id:string}  $gap
     * @param  array<string,bool>  $seen
     */
    public function seenGap(array $gap, array $seen): bool
    {
        return isset($seen[$this->gapKey($gap['target_file'], $gap['mutation_id'])]);
    }

    /**
     * @param  array{target_file:string, mutation_id:string}  $gap
     * @param  array<string,bool>  $seen
     */
    public function markGapSeen(array $gap, array &$seen): void
    {
        $seen[$this->gapKey($gap['target_file'], $gap['mutation_id'])] = true;
    }

    public function gapKey(string $file, string $mutationId): string
    {
        return $file.'|'.$mutationId;
    }

    /**
     * Is this a real refactor of production code worth a characterization test? Excludes the
     * self-contained-synth FIXTURE workspace (src/* targets + generated `atlas_generated_*` siblings)
     * and the meaningless "refactor of a test file" case.
     */
    public function isActionableTarget(string $file, ?string $sibling): bool
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
    public function siblingTestFromCommands($commandResults): ?string
    {
        if (! is_array($commandResults)) {
            return null;
        }
        foreach ($commandResults as $cr) {
            $cmd = is_array($cr) ? (string) ($cr['command'] ?? '') : '';
            if (preg_match('#(tests/[^\s\'\"]+\.php)#', $cmd, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
