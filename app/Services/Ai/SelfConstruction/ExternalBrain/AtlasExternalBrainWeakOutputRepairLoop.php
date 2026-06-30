<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure repair loop. Classifies weak model-generated task proposals and either
 * produces deterministic repair instructions (fixable classes) or refuses them
 * with an explicit reason (non-fixable classes).
 *
 * Classification order (first match wins):
 *   1. unrecoverable   — poisoned, give_back, or completely empty objective
 *   2. duplicate_target — proposal flags is_duplicate or has a dedup_conflict
 *   3. low_value        — shallow_duplication / template_farming / fake_confidence weakness
 *   4. fixable_missing_evidence — no runnable acceptance criteria or empty required_evidence
 *   5. fixable_scope_shape      — over_broad_scope / missing_code_search / weak_acceptance
 *
 * AC4: output always includes repaired_candidate, repair_steps,
 *      refusal_reason, and next_scaffold_constraint.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainWeakOutputRepairLoop
{
    public const SCHEMA = 'atlas.external_brain.weak_output_repair_loop.v1';

    public const CLASS_FIXABLE_MISSING_EVIDENCE = 'fixable_missing_evidence';
    public const CLASS_FIXABLE_SCOPE_SHAPE      = 'fixable_scope_shape';
    public const CLASS_DUPLICATE_TARGET         = 'duplicate_target';
    public const CLASS_LOW_VALUE                = 'low_value';
    public const CLASS_UNRECOVERABLE            = 'unrecoverable';

    public const LOW_VALUE_WEAKNESSES = ['shallow_duplication', 'template_farming', 'fake_confidence'];

    private const RUNNABLE_MARKERS = ['phpunit', 'artisan', 'vendor/bin', './vendor', 'pest', '--filter'];

    /**
     * @param  array{
     *   proposal?: array<string,mixed>,
     *   weakness_labels?: list<string>,
     *   is_poison?: bool,
     *   is_give_back?: bool,
     *   is_duplicate?: bool,
     *   dedup_conflict?: string,
     * }  $input
     * @return array{schema:string, failure_class:string, repaired_candidate:array<string,mixed>|null, repair_steps:list<string>, refusal_reason:string|null, next_scaffold_constraint:string}
     */
    public function repair(array $input): array
    {
        $proposal       = (array) ($input['proposal'] ?? []);
        $weaknesses     = (array) ($input['weakness_labels'] ?? []);
        $isPoison       = (bool) ($input['is_poison']    ?? false);
        $isGiveBack     = (bool) ($input['is_give_back'] ?? false);
        $isDuplicate    = (bool) ($input['is_duplicate'] ?? false);
        $dedupConflict  = (string) ($input['dedup_conflict'] ?? '');
        $objective      = trim((string) ($proposal['objective'] ?? ''));

        // 1. Unrecoverable
        if ($isPoison || $isGiveBack || $objective === '') {
            return $this->refused(
                self::CLASS_UNRECOVERABLE,
                $isPoison    ? 'proposal_flagged_as_poison'
                    : ($isGiveBack ? 'proposal_flagged_as_give_back'
                    : 'empty_objective_cannot_repair'),
                'reject_poison_and_empty_objectives_before_origination',
            );
        }

        // 2. Duplicate target
        if ($isDuplicate || $dedupConflict !== '') {
            $reason = $dedupConflict !== '' ? "dedup_conflict:{$dedupConflict}" : 'marked_as_duplicate';
            return $this->refused(
                self::CLASS_DUPLICATE_TARGET,
                $reason,
                'enforce_dedup_check_before_enqueue_with_tighter_semantic_scope',
            );
        }

        // 3. Low value
        $lowValueHits = array_intersect($weaknesses, self::LOW_VALUE_WEAKNESSES);
        if ($lowValueHits !== []) {
            return $this->refused(
                self::CLASS_LOW_VALUE,
                'weakness:'.implode('+', array_values($lowValueHits)),
                'require_exponential_value_justification_in_scaffold_prompt',
            );
        }

        // 4. Fixable: missing evidence
        if ($this->isMissingEvidence($proposal)) {
            $steps = $this->missingEvidenceSteps($proposal);
            return $this->fixable(
                self::CLASS_FIXABLE_MISSING_EVIDENCE,
                $this->applyEvidenceRepairs($proposal, $steps),
                $steps,
                'add_runnable_acceptance_criterion_and_required_evidence_to_scaffold_template',
            );
        }

        // 5. Fixable: scope shape
        $scopeSteps = $this->scopeShapeSteps($proposal, $weaknesses);
        return $this->fixable(
            self::CLASS_FIXABLE_SCOPE_SHAPE,
            $this->applyScopeRepairs($proposal, $scopeSteps),
            $scopeSteps,
            'narrow_allowed_files_and_add_code_search_evidence_in_scaffold_prompt',
        );
    }

    private function isMissingEvidence(array $proposal): bool
    {
        $acceptance = (array) ($proposal['acceptance_criteria'] ?? []);
        $evidence   = (array) ($proposal['required_evidence']   ?? []);

        if ($evidence === []) {
            return true;
        }

        // All acceptance criteria are non-runnable
        if ($acceptance !== [] && $this->allNonRunnable($acceptance)) {
            return true;
        }

        return false;
    }

    /** @return list<string> */
    private function missingEvidenceSteps(array $proposal): array
    {
        $steps = [];
        if ((array) ($proposal['required_evidence'] ?? []) === []) {
            $steps[] = 'add required_evidence: ["tests_or_gates_result", "implementation_notes"]';
        }
        if ($this->allNonRunnable((array) ($proposal['acceptance_criteria'] ?? []))) {
            $steps[] = 'add runnable acceptance criterion referencing phpunit or artisan test command';
        }

        return $steps !== [] ? $steps : ['add required_evidence and runnable acceptance criterion'];
    }

    /** @return list<string> */
    private function scopeShapeSteps(array $proposal, array $weaknesses): array
    {
        $steps = [];

        if (in_array('over_broad_scope', $weaknesses, true)) {
            $steps[] = 'narrow allowed_files to only the files required by the objective';
        }
        if (in_array('missing_code_search', $weaknesses, true)) {
            $steps[] = 'add code search evidence: reference existing symbols in scope_in';
        }
        if (in_array('weak_acceptance', $weaknesses, true)) {
            $steps[] = 'replace vague acceptance criteria with deterministic, verifiable assertions';
        }

        $allFiles = (array) ($proposal['allowed_files'] ?? []);
        if (count($allFiles) > 5 && ! in_array('over_broad_scope', $weaknesses, true)) {
            $steps[] = 'narrow allowed_files: proposal references >5 files, tighten scope';
        }

        return $steps !== [] ? $steps : ['review and tighten proposal scope and acceptance criteria'];
    }

    private function applyEvidenceRepairs(array $proposal, array $steps): array
    {
        if ((array) ($proposal['required_evidence'] ?? []) === []) {
            $proposal['required_evidence'] = ['tests_or_gates_result', 'implementation_notes'];
        }

        if ($this->allNonRunnable((array) ($proposal['acceptance_criteria'] ?? []))) {
            $proposal['acceptance_criteria'][] = 'Runnable: ./vendor/bin/phpunit must pass with green output.';
        }

        return $proposal;
    }

    private function applyScopeRepairs(array $proposal, array $steps): array
    {
        // Mark repaired — scope changes are suggestions, not structural mutations
        $proposal['_repair_applied'] = true;

        return $proposal;
    }

    private function allNonRunnable(array $criteria): bool
    {
        if ($criteria === []) {
            return false;
        }

        foreach ($criteria as $criterion) {
            foreach (self::RUNNABLE_MARKERS as $marker) {
                if (str_contains(strtolower((string) $criterion), $marker)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function refused(string $class, string $reason, string $constraint): array
    {
        return [
            'schema'                   => self::SCHEMA,
            'failure_class'            => $class,
            'repaired_candidate'       => null,
            'repair_steps'             => [],
            'refusal_reason'           => $reason,
            'next_scaffold_constraint' => $constraint,
        ];
    }

    private function fixable(string $class, array $candidate, array $steps, string $constraint): array
    {
        return [
            'schema'                   => self::SCHEMA,
            'failure_class'            => $class,
            'repaired_candidate'       => $candidate,
            'repair_steps'             => $steps,
            'refusal_reason'           => null,
            'next_scaffold_constraint' => $constraint,
        ];
    }
}
