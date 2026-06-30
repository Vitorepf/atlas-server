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
    public const WEAKNESS_CONTRADICTION_MISS  = 'contradiction_miss';
    public const WEAKNESS_PROXY_OUTPUT        = 'proxy_output';

    public const SEVERITY_HIGH   = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW    = 'low';

    public const DECISION_ALLOW            = 'allow';
    public const DECISION_ALLOW_SCAFFOLDED = 'allow_scaffolded';
    public const DECISION_ESCALATE         = 'escalate';
    public const DECISION_BLOCK            = 'block';

    private const DEFAULT_MAX_FILES = 5;

    /** A failure mode with a recovery rate at/above this is reliably scaffold-recoverable. */
    private const RELIABLE_RECOVERY_FLOOR = 0.70;

    private const PROXY_OUTPUT_PHRASES = [
        'lines of code', 'lines added', 'word count', 'percentage covered', 'coverage percentage',
        'number of files touched', 'file count',
    ];

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
        $modelTier     = (string) ($input['model_tier'] ?? 'unknown');
        // recovery_evidence: map of weakness_id => recovery_rate (0..1), or list<{weakness_id, recovery_rate}>.
        $recoveryEvidence = $this->normalizeRecoveryEvidence($input['recovery_evidence'] ?? null);

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

        // 7. Contradiction miss (high): a single criterion asserts and negates the same claim,
        //    or two criteria directly contradict each other.
        if ($this->hasContradiction($acceptanceCriteria)) {
            $findings[]    = $this->finding(
                self::WEAKNESS_CONTRADICTION_MISS,
                'Acceptance criteria contain a self-contradiction (assert and negate the same claim).',
                self::SEVERITY_HIGH,
            );
            $repairSteps[] = 'Resolve the contradictory acceptance criteria into a single, internally consistent requirement before this task is dispatched.';
        }

        // 8. Proxy output (medium): acceptance measures a proxy metric instead of real behavior.
        $proxyPhrases = [];
        foreach (self::PROXY_OUTPUT_PHRASES as $phrase) {
            if (str_contains($allCriteriaText, $phrase)) {
                $proxyPhrases[] = $phrase;
            }
        }
        if ($proxyPhrases !== []) {
            $findings[]    = $this->finding(
                self::WEAKNESS_PROXY_OUTPUT,
                'Acceptance criteria measure a proxy metric instead of real behavior: ' . implode(', ', $proxyPhrases),
                self::SEVERITY_MEDIUM,
            );
            $repairSteps[] = 'Replace proxy metrics (line/word/file counts, coverage percentage) with a concrete behavioral assertion or runnable test.';
        }

        $passed           = $findings === [];
        $hasHighFinding = array_reduce(
            $findings,
            static fn (bool $carry, array $f): bool => $carry || $f['severity'] === self::SEVERITY_HIGH,
            false,
        );

        $primaryWeakness = $this->primaryWeakness($findings);
        [$decision, $blockedUntilFixed, $escalationRationale] = $this->decide($passed, $hasHighFinding, $primaryWeakness, $recoveryEvidence);

        return [
            'schema'              => self::SCHEMA,
            'passed'              => $passed,
            'weakness_findings'   => $findings,
            'repair_steps'        => $repairSteps,
            'blocked_until_fixed' => $blockedUntilFixed,
            'model_tier'          => $modelTier,
            'weakness'            => $primaryWeakness,
            'decision'            => $decision,
            'escalation_rationale' => $escalationRationale,
        ];
    }

    /**
     * @param  list<array<string,string>>  $findings
     */
    private function primaryWeakness(array $findings): ?string
    {
        foreach ([self::SEVERITY_HIGH, self::SEVERITY_MEDIUM, self::SEVERITY_LOW] as $severity) {
            foreach ($findings as $finding) {
                if ($finding['severity'] === $severity) {
                    return $finding['weakness_id'];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string,float>  $recoveryEvidence
     * @return array{0:string,1:bool,2:string}
     */
    private function decide(bool $passed, bool $hasHighFinding, ?string $primaryWeakness, array $recoveryEvidence): array
    {
        if ($passed) {
            return [self::DECISION_ALLOW, false, 'No weaknesses detected; the candidate is safe to dispatch as-is.'];
        }

        $recoveryRate = $primaryWeakness !== null ? ($recoveryEvidence[$primaryWeakness] ?? 0.0) : 0.0;
        $reliablyRecoverable = $recoveryRate >= self::RELIABLE_RECOVERY_FLOOR;

        if ($hasHighFinding) {
            if ($reliablyRecoverable) {
                return [
                    self::DECISION_ALLOW_SCAFFOLDED,
                    false,
                    sprintf('Past evidence shows a %.0f%% scaffold recovery rate for "%s"; allowing scaffolded execution instead of an outright block.', $recoveryRate * 100, (string) $primaryWeakness),
                ];
            }

            return [
                self::DECISION_BLOCK,
                true,
                sprintf('High-severity weakness "%s" has no reliable scaffold recovery evidence (%.0f%% < %.0f%% floor); blocking until repaired.', (string) $primaryWeakness, $recoveryRate * 100, self::RELIABLE_RECOVERY_FLOOR * 100),
            ];
        }

        if ($reliablyRecoverable) {
            return [
                self::DECISION_ALLOW_SCAFFOLDED,
                false,
                sprintf('Medium/low-severity weakness "%s" has a reliable scaffold recovery rate (%.0f%%); allowing scaffolded execution.', (string) $primaryWeakness, $recoveryRate * 100),
            ];
        }

        return [
            self::DECISION_ESCALATE,
            false,
            sprintf('Weakness "%s" lacks reliable scaffold recovery evidence; escalating to a stronger model tier rather than dispatching this weak-model candidate unchanged.', (string) $primaryWeakness),
        ];
    }

    /** @param list<string> $criteria */
    private function hasContradiction(array $criteria): bool
    {
        foreach ($criteria as $criterion) {
            $lower = strtolower($criterion);
            if (str_contains($lower, 'must') && str_contains($lower, 'must not')) {
                return true;
            }
        }

        // Cross-criterion contradiction: one criterion is the literal negation of another
        // (same text with "not " inserted/removed around a shared verb).
        $negated = [];
        $core = [];
        foreach ($criteria as $i => $criterion) {
            $lower = strtolower($criterion);
            $negated[$i] = (bool) preg_match('/\b(not|never|doesn\'t|don\'t)\b/', $lower);
            $words = preg_split('/[^a-z0-9]+/', $lower) ?: [];
            $words = array_map(
                static fn (string $w): string => str_ends_with($w, 's') && strlen($w) > 4 ? substr($w, 0, -1) : $w,
                array_filter($words, static fn (string $w): bool => strlen($w) >= 4 && ! in_array($w, ['not', 'never', 'does', 'dont', 'doesnt'], true)),
            );
            $core[$i] = array_values(array_unique($words));
        }

        foreach ($core as $i => $wordsA) {
            foreach ($core as $j => $wordsB) {
                if ($i === $j || $negated[$i] === $negated[$j]) {
                    continue;
                }
                if ($wordsA === [] || $wordsB === []) {
                    continue;
                }
                $intersection = count(array_intersect($wordsA, $wordsB));
                $union = count(array_unique(array_merge($wordsA, $wordsB)));
                if ($union > 0 && ($intersection / $union) >= 0.8) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string,float>
     */
    private function normalizeRecoveryEvidence(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        // Already a weakness_id => rate map.
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            $out = [];
            foreach ($value as $weaknessId => $rate) {
                $out[(string) $weaknessId] = (float) $rate;
            }

            return $out;
        }

        // List of {weakness_id, recovery_rate}.
        $out = [];
        foreach ($value as $entry) {
            if (! is_array($entry) || ! isset($entry['weakness_id'])) {
                continue;
            }
            $out[(string) $entry['weakness_id']] = (float) ($entry['recovery_rate'] ?? 0.0);
        }

        return $out;
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
