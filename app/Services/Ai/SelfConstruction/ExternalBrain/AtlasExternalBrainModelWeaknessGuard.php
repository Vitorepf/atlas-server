<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Guards against common smaller-model failure modes before a task candidate enters the queue.
 *
 * DETECTED WEAKNESSES (highest severity first):
 *   shallow_duplication   [high]   — a candidate allowed_file already appears in queued_targets
 *   template_farming      [high]   — acceptance criteria are all vague "must run" clones with no
 *                                    behavior-specific assertion
 *   missing_code_search   [medium] — no grep/file-reference evidence in acceptance criteria
 *   weak_acceptance       [medium] — acceptance criterion contains weak/vague wording
 *   over_broad_scope      [medium] — allowed_files count exceeds MAX_FILES_PER_TASK
 *   fake_confidence       [low]    — objective or acceptance criteria use confidence-signalling
 *                                    phrases that substitute for proof
 *
 * BLOCKED UNTIL FIXED: any high-severity finding sets blocked_until_fixed=true.
 *
 * INPUT:
 *   candidate: {
 *     task_id:              string
 *     objective?:           string
 *     allowed_files?:       list<string>
 *     acceptance_criteria?: list<string>
 *   }
 *   queued_targets?:        list<string>  (files already claimed by queued tasks)
 *   done_targets?:          list<string>  (files from completed tasks)
 *   max_files_per_task?:    int           (default 5)
 *
 * OUTPUT:
 *   { schema, passed, weakness_findings, repair_steps, blocked_until_fixed }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainModelWeaknessGuard
{
    public const SCHEMA = 'atlas.external_brain.model_weakness_guard.v1';

    public const WEAKNESS_SHALLOW_DUPLICATION = 'shallow_duplication';
    public const WEAKNESS_TEMPLATE_FARMING    = 'template_farming';
    public const WEAKNESS_MISSING_CODE_SEARCH = 'missing_code_search';
    public const WEAKNESS_WEAK_ACCEPTANCE     = 'weak_acceptance';
    public const WEAKNESS_OVER_BROAD_SCOPE    = 'over_broad_scope';
    public const WEAKNESS_FAKE_CONFIDENCE     = 'fake_confidence';

    public const SEVERITY_HIGH   = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW    = 'low';

    private const DEFAULT_MAX_FILES = 5;

    private const WEAK_ACCEPTANCE_PHRASES = [
        'should work', 'should be valid', 'it works', 'should be fine',
        'it is correct', 'should be correct', 'must pass', 'should pass',
    ];

    private const FAKE_CONFIDENCE_PHRASES = [
        'ensure that', 'verify that', 'confirm that', 'guarantee',
        'it is guaranteed', 'make sure', 'should ensure',
    ];

    // Proof indicators: acceptance criteria that reference these are considered evidenced.
    private const GREP_PROOF_INDICATORS = [
        '.php:', 'grep', 'file:', 'artisan test ', 'phpunit', '::class',
        'vendor/bin/', '->',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function guard(array $input): array
    {
        $candidate   = is_array($input['candidate'] ?? null) ? $input['candidate'] : [];
        $queuedTargets = array_map('strtolower', is_array($input['queued_targets'] ?? null) ? $input['queued_targets'] : []);
        $doneTargets   = array_map('strtolower', is_array($input['done_targets'] ?? null) ? $input['done_targets'] : []);
        $maxFiles      = max(1, (int) ($input['max_files_per_task'] ?? self::DEFAULT_MAX_FILES));

        $allowedFiles      = is_array($candidate['allowed_files'] ?? null) ? $candidate['allowed_files'] : [];
        $objective         = strtolower((string) ($candidate['objective'] ?? ''));
        $acceptanceCriteria = is_array($candidate['acceptance_criteria'] ?? null) ? $candidate['acceptance_criteria'] : [];

        $findings    = [];
        $repairSteps = [];

        // 1. Shallow duplication (high).
        $conflictingFiles = array_filter(
            $allowedFiles,
            static fn (string $f): bool =>
                in_array(strtolower($f), $queuedTargets, true)
                || in_array(strtolower($f), $doneTargets, true),
        );
        if ($conflictingFiles !== []) {
            $findings[]    = $this->finding(
                self::WEAKNESS_SHALLOW_DUPLICATION,
                'One or more allowed_files are already claimed by a queued or completed task: ' . implode(', ', $conflictingFiles),
                self::SEVERITY_HIGH,
            );
            $repairSteps[] = 'Remove conflicting files from allowed_files, or give_back this candidate and let the queue drain first.';
        }

        // 2. Template farming (high): all acceptance criteria match a generic "must Y" pattern
        //    with no behavior-specific assertion (no file reference, no method name, no assertion word).
        if ($this->isTemplateFarming($acceptanceCriteria)) {
            $findings[]    = $this->finding(
                self::WEAKNESS_TEMPLATE_FARMING,
                'All acceptance criteria are generic one-liners with no behavior-specific content. This looks like a renamed-class template.',
                self::SEVERITY_HIGH,
            );
            $repairSteps[] = 'Rewrite each acceptance criterion to describe a concrete, verifiable behavior (e.g., "The X::compute() method must return Y when Z").';
        }

        // 3. Missing code search (medium): no grep/proof indicator in any acceptance criterion.
        $allCriteriaText = strtolower(implode(' ', $acceptanceCriteria));
        $hasProofIndicator = false;
        foreach (self::GREP_PROOF_INDICATORS as $indicator) {
            if (str_contains($allCriteriaText, $indicator)) {
                $hasProofIndicator = true;
                break;
            }
        }
        if (! $hasProofIndicator && $allowedFiles !== []) {
            $findings[]    = $this->finding(
                self::WEAKNESS_MISSING_CODE_SEARCH,
                'No grep/file evidence found in acceptance criteria. The model may not have searched the codebase before proposing this task.',
                self::SEVERITY_MEDIUM,
            );
            $repairSteps[] = 'Add at least one acceptance criterion that cites a specific file:line, class method, or artisan/phpunit command that proves the work is needed.';
        }

        // 4. Weak acceptance (medium).
        $weakCriteria = array_filter(
            $acceptanceCriteria,
            function (string $criterion): bool {
                $lower = strtolower($criterion);
                foreach (self::WEAK_ACCEPTANCE_PHRASES as $phrase) {
                    if (str_contains($lower, $phrase)) {
                        return true;
                    }
                }

                return false;
            },
        );
        if ($weakCriteria !== []) {
            $findings[]    = $this->finding(
                self::WEAKNESS_WEAK_ACCEPTANCE,
                'One or more acceptance criteria contain vague wording: ' . implode('; ', $weakCriteria),
                self::SEVERITY_MEDIUM,
            );
            $repairSteps[] = 'Replace vague phrases ("should work", "must pass") with measurable assertions tied to specific inputs and outputs.';
        }

        // 5. Over-broad scope (medium).
        if (count($allowedFiles) > $maxFiles) {
            $findings[]    = $this->finding(
                self::WEAKNESS_OVER_BROAD_SCOPE,
                'allowed_files count (' . count($allowedFiles) . ') exceeds the per-task maximum of ' . $maxFiles . '.',
                self::SEVERITY_MEDIUM,
            );
            $repairSteps[] = 'Split this task into smaller packets, each touching at most ' . $maxFiles . ' files.';
        }

        // 6. Fake confidence (low).
        $fullText = $objective . ' ' . $allCriteriaText;
        $fakeConfidencePhrases = [];
        foreach (self::FAKE_CONFIDENCE_PHRASES as $phrase) {
            if (str_contains($fullText, $phrase)) {
                $fakeConfidencePhrases[] = $phrase;
            }
        }
        if ($fakeConfidencePhrases !== []) {
            $findings[]    = $this->finding(
                self::WEAKNESS_FAKE_CONFIDENCE,
                'Confidence-signalling phrases found (substitute for proof): ' . implode(', ', $fakeConfidencePhrases),
                self::SEVERITY_LOW,
            );
            $repairSteps[] = 'Replace "ensure/verify/confirm/guarantee" with concrete evidence: a test assertion, a CLI command output, or a file:line reference.';
        }

        $passed           = $findings === [];
        $blockedUntilFixed = ! $passed && array_reduce(
            $findings,
            static fn (bool $carry, array $f): bool => $carry || $f['severity'] === self::SEVERITY_HIGH,
            false,
        );

        return [
            'schema'             => self::SCHEMA,
            'passed'             => $passed,
            'weakness_findings'  => $findings,
            'repair_steps'       => $repairSteps,
            'blocked_until_fixed' => $blockedUntilFixed,
        ];
    }

    /** @param list<string> $criteria */
    private function isTemplateFarming(array $criteria): bool
    {
        if (count($criteria) < 2) {
            return false;
        }

        $behaviorWords = ['return', 'emit', 'throw', 'calculate', 'compute', 'filter',
                          'sort', 'parse', 'assert', 'schema', '::', '()', '->'];

        foreach ($criteria as $criterion) {
            $lower = strtolower($criterion);
            foreach ($behaviorWords as $word) {
                if (str_contains($lower, $word)) {
                    return false; // at least one concrete criterion → not farming
                }
            }
        }

        return true;
    }

    /** @return array<string,string> */
    private function finding(string $id, string $description, string $severity): array
    {
        return [
            'weakness_id' => $id,
            'description' => $description,
            'severity'    => $severity,
        ];
    }
}
