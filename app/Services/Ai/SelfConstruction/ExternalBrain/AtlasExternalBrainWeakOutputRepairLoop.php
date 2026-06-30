<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure repair loop. Classifies weak model-generated task proposals and either
 * produces deterministic repair instructions (fixable classes) or refuses them
 * with an explicit reason (non-fixable classes).
 *
 * Classification order (first match wins):
 *   1. unrecoverable               — poisoned, give_back, or completely empty objective
 *   2. duplicate_target            — proposal flags is_duplicate or has a dedup_conflict
 *   3. low_value                   — shallow_duplication / template_farming / fake_confidence
 *   4. fixable_missing_evidence    — no runnable acceptance criteria or empty required_evidence
 *   5. fixable_missing_impl_file   — allowed_files empty or only test files
 *   6. fixable_scope_shape         — over_broad_scope / missing_code_search / weak_acceptance
 *
 * Repaired candidates always include required_evidence, runnable acceptance,
 * narrowed allowed_files (when over-broad), and repair_steps.
 *
 * Refused candidates include refusal_reason and next_scaffold_constraint.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainWeakOutputRepairLoop
{
    public const SCHEMA = 'atlas.external_brain.weak_output_repair_loop.v1';

    public const CLASS_FIXABLE_MISSING_EVIDENCE  = 'fixable_missing_evidence';
    public const CLASS_FIXABLE_MISSING_IMPL_FILE = 'fixable_missing_implementation_file';
    public const CLASS_FIXABLE_SCOPE_SHAPE       = 'fixable_scope_shape';
    public const CLASS_DUPLICATE_TARGET          = 'duplicate_target';
    public const CLASS_LOW_VALUE                 = 'low_value';
    public const CLASS_UNRECOVERABLE             = 'unrecoverable';

    public const LOW_VALUE_WEAKNESSES = ['shallow_duplication', 'template_farming', 'fake_confidence'];

    private const RUNNABLE_MARKERS    = ['phpunit', 'artisan', 'vendor/bin', './vendor', 'pest', '--filter'];
    private const MAX_ALLOWED_FILES   = 5;
    private const NARROW_TO_FILES     = 2;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function repair(array $input): array
    {
        $proposal      = (array) ($input['proposal']        ?? []);
        $weaknesses    = (array) ($input['weakness_labels'] ?? []);
        $isPoison      = (bool)  ($input['is_poison']       ?? false);
        $isGiveBack    = (bool)  ($input['is_give_back']    ?? false);
        $isDuplicate   = (bool)  ($input['is_duplicate']    ?? false);
        $dedupConflict = (string) ($input['dedup_conflict'] ?? '');
        $objective     = trim((string) ($proposal['objective'] ?? ''));

        // 1. Unrecoverable
        if ($isPoison || $isGiveBack || $objective === '') {
            return $this->refused(
                self::CLASS_UNRECOVERABLE,
                $isPoison   ? 'proposal_flagged_as_poison'
                    : ($isGiveBack ? 'proposal_flagged_as_give_back'
                    : 'empty_objective_cannot_repair'),
                'reject_poison_and_empty_objectives_before_origination',
            );
        }

        // 2. Duplicate target — never silently repair
        if ($isDuplicate || $dedupConflict !== '') {
            $reason = $dedupConflict !== '' ? "dedup_conflict:{$dedupConflict}" : 'marked_as_duplicate';
            return $this->refused(
                self::CLASS_DUPLICATE_TARGET,
                $reason,
                'enforce_dedup_check_before_enqueue_with_tighter_semantic_scope',
            );
        }

        // 3. Low value — never silently repair
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

        // 5. Fixable: missing implementation file
        if ($this->isMissingImplFile($proposal)) {
            $steps = $this->missingImplFileSteps($proposal);
            return $this->fixable(
                self::CLASS_FIXABLE_MISSING_IMPL_FILE,
                $this->applyImplFileRepairs($proposal),
                $steps,
                'always_include_at_least_one_implementation_file_in_allowed_files',
            );
        }

        // 6. Fixable: scope shape
        $scopeSteps = $this->scopeShapeSteps($proposal, $weaknesses);
        return $this->fixable(
            self::CLASS_FIXABLE_SCOPE_SHAPE,
            $this->applyScopeRepairs($proposal, $weaknesses),
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

        if ($acceptance !== [] && $this->allNonRunnable($acceptance)) {
            return true;
        }

        return false;
    }

    private function isMissingImplFile(array $proposal): bool
    {
        $files = (array) ($proposal['allowed_files'] ?? []);

        if ($files === []) {
            return true;
        }

        // All files are test files → no implementation file present
        foreach ($files as $file) {
            if (! $this->isTestFile((string) $file)) {
                return false;
            }
        }

        return true;
    }

    private function isTestFile(string $path): bool
    {
        return str_contains($path, 'Test.php')
            || str_contains($path, 'test.php')
            || str_starts_with($path, 'tests/');
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
    private function missingImplFileSteps(array $proposal): array
    {
        return [
            'add at least one implementation file (app/Services/... or app/Http/...) to allowed_files',
            'test files alone do not specify what to implement; pair each test with its production file',
        ];
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
        if (count($allFiles) > self::MAX_ALLOWED_FILES && ! in_array('over_broad_scope', $weaknesses, true)) {
            $steps[] = 'narrow allowed_files: proposal references >'.self::MAX_ALLOWED_FILES.' files, tighten scope';
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

    private function applyImplFileRepairs(array $proposal): array
    {
        $proposal['_suggested_implementation_file'] = 'app/Services/<DeriveFromObjective>.php';

        return $proposal;
    }

    private function applyScopeRepairs(array $proposal, array $weaknesses): array
    {
        if (in_array('over_broad_scope', $weaknesses, true)) {
            $files = (array) ($proposal['allowed_files'] ?? []);
            if (count($files) > self::NARROW_TO_FILES) {
                $proposal['allowed_files'] = array_slice($files, 0, self::NARROW_TO_FILES);
            }
        }

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
