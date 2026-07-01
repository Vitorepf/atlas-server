<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Pure detector. Looks for HIDDEN poison patterns in a task packet that the upstream blocking_deficiencies
 * pipeline does NOT already catch. Emits a FACT per detected pattern; never mutates the packet or queue.
 *
 * Patterns:
 *   - removed_target_referenced_outside_acceptance : the objective text references a target path that was
 *     REMOVED from allowed_files (the classic "scope_repair" leftover where the brief still asks for it).
 *   - contradictory_acceptance                     : acceptance criteria mutually exclusive (e.g. one
 *     requires X and another forbids X, OR criteria require unavailable_dependency).
 *   - unavailable_dependency                       : required_evidence_kinds names a tool/class that the
 *     packet quality_facts marks unavailable=true.
 *   - duplicate_canonical_symbol_hint              : allowed_files contains two files that both target the
 *     SAME canonical class name (heuristic: same basename).
 *   - ambiguous_worker_instruction                 : objective contains both `do X` and `do not X` patterns
 *     or known-ambiguous markers (TBD, FIXME-ME, "if applicable").
 *   - permanent_autonomy_dependency_wording        : objective requires "operator approval", "human
 *     intervention", or "external provider" as a STEADY-STATE step (poisons autonomy).
 *
 * Output: {schema_version, found_patterns:list<{pattern_id, evidence}>, clean:bool,
 *   severity, confidence, recommended_action, safe_explanation}
 *
 * severity/recommended_action are derived from the single most severe matched pattern
 * (retire > quarantine > reshape > serve, in that fixed priority). confidence reflects how
 * sure the detector is in that recommendation — never a claim about the packet's overall
 * quality. safe_explanation is populated ONLY when clean=true, naming exactly which checks
 * ran and found nothing, so "safe" is never an unexplained default.
 */
final class AtlasTaskHiddenPoisonDetector
{
    public const SCHEMA = 'atlas.task_quality.hidden_poison.v1';

    public const PATTERN_REMOVED_TARGET = 'removed_target_referenced_outside_acceptance';

    public const PATTERN_CONTRADICTORY_ACCEPTANCE = 'contradictory_acceptance';

    public const PATTERN_UNAVAILABLE_DEPENDENCY = 'unavailable_dependency';

    public const PATTERN_DUPLICATE_CANONICAL_SYMBOL = 'duplicate_canonical_symbol_hint';

    public const PATTERN_AMBIGUOUS_INSTRUCTION = 'ambiguous_worker_instruction';

    public const PATTERN_PERMANENT_AUTONOMY_DEP = 'permanent_autonomy_dependency_wording';

    public const PATTERN_TEST_ONLY_ALLOWED_FILES = 'allowed_files_test_only_trap';

    public const PATTERN_SCHEMA_ONLY_ACCEPTANCE = 'acceptance_schema_only_no_behavior';

    public const PATTERN_REPEATED_FAILED_RESPEC_FAMILY = 'repeated_failed_respec_family_retirement_recommended';

    private const BLOCKED_FAMILY_STATUSES = ['blocked', 'quarantined'];

    private const REPEATED_FAILURE_THRESHOLD = 3;

    private const FIELD_RECOVERY_CONFIDENCE_THRESHOLD = 0.75;

    private const SCHEMA_ONLY_NEEDLES = ['exits 0', 'exit 0', 'exit code 0', 'schema_version', 'json schema', 'valid schema'];

    private const AUTONOMY_DEP_NEEDLES = [
        'operator approval',
        'operator must',
        'operator review',
        'operator oversees',
        'operator verifies',
        'human intervention',
        'human approves',
        'human review',
        'human must',
        'needs human',
        'requires human',
        'supervised by',
        'external provider call',
        'requires provider',
        'provider review',
        'provider approves',
        'manual review by operator',
        'pending approval',
        'await approval',
        'awaiting approval',
    ];

    private const AMBIGUOUS_NEEDLES = ['TBD', 'FIXME-ME', 'if applicable', 'maybe', '?'];

    private const NEGATION_MARKERS = ['must not', 'should not', 'cannot', 'will not', 'never', ' not ', 'without ', 'forbid', 'no '];

    private const TEXT_STOP_WORDS = ['the', 'this', 'that', 'with', 'from', 'will', 'have', 'must', 'should', 'every', 'also', 'each', 'some', 'when', 'than', 'then', 'them', 'they', 'into', 'onto', 'over', 'only', 'both', 'even', 'just', 'same', 'such', 'more', 'most', 'been', 'does', 'what', 'here', 'there', 'where', 'which', 'while', 'these', 'those', 'their'];

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_NONE = 'none';

    public const ACTION_SERVE = 'serve';

    public const ACTION_RESHAPE = 'reshape';

    public const ACTION_QUARANTINE = 'quarantine';

    public const ACTION_RETIRE = 'retire';

    /** @var array<string,string> pattern_id => severity */
    private const PATTERN_SEVERITY = [
        self::PATTERN_REPEATED_FAILED_RESPEC_FAMILY => self::SEVERITY_CRITICAL,
        self::PATTERN_PERMANENT_AUTONOMY_DEP => self::SEVERITY_CRITICAL,
        self::PATTERN_UNAVAILABLE_DEPENDENCY => self::SEVERITY_HIGH,
        self::PATTERN_CONTRADICTORY_ACCEPTANCE => self::SEVERITY_HIGH,
        self::PATTERN_TEST_ONLY_ALLOWED_FILES => self::SEVERITY_HIGH,
        self::PATTERN_SCHEMA_ONLY_ACCEPTANCE => self::SEVERITY_MEDIUM,
        self::PATTERN_DUPLICATE_CANONICAL_SYMBOL => self::SEVERITY_MEDIUM,
        self::PATTERN_REMOVED_TARGET => self::SEVERITY_MEDIUM,
        self::PATTERN_AMBIGUOUS_INSTRUCTION => self::SEVERITY_LOW,
    ];

    /** @var array<string,string> pattern_id => recommended_action */
    private const PATTERN_ACTION = [
        self::PATTERN_REPEATED_FAILED_RESPEC_FAMILY => self::ACTION_RETIRE,
        self::PATTERN_PERMANENT_AUTONOMY_DEP => self::ACTION_QUARANTINE,
        self::PATTERN_UNAVAILABLE_DEPENDENCY => self::ACTION_QUARANTINE,
        self::PATTERN_CONTRADICTORY_ACCEPTANCE => self::ACTION_QUARANTINE,
        self::PATTERN_TEST_ONLY_ALLOWED_FILES => self::ACTION_RESHAPE,
        self::PATTERN_SCHEMA_ONLY_ACCEPTANCE => self::ACTION_RESHAPE,
        self::PATTERN_DUPLICATE_CANONICAL_SYMBOL => self::ACTION_RESHAPE,
        self::PATTERN_REMOVED_TARGET => self::ACTION_RESHAPE,
        self::PATTERN_AMBIGUOUS_INSTRUCTION => self::ACTION_RESHAPE,
    ];

    private const SEVERITY_RANK = [
        self::SEVERITY_CRITICAL => 4,
        self::SEVERITY_HIGH => 3,
        self::SEVERITY_MEDIUM => 2,
        self::SEVERITY_LOW => 1,
        self::SEVERITY_NONE => 0,
    ];

    private const ACTION_RANK = [
        self::ACTION_RETIRE => 4,
        self::ACTION_QUARANTINE => 3,
        self::ACTION_RESHAPE => 2,
        self::ACTION_SERVE => 1,
    ];

    private const SEVERITY_CONFIDENCE = [
        self::SEVERITY_CRITICAL => 0.9,
        self::SEVERITY_HIGH => 0.8,
        self::SEVERITY_MEDIUM => 0.65,
        self::SEVERITY_LOW => 0.5,
        self::SEVERITY_NONE => 0.95,
    ];

    /**
     * @param  array<string,mixed>  $packet  {objective, acceptance_criteria, required_evidence_kinds,
     *                                         allowed_files, forbidden_files, quality_facts:{
     *                                             removed_targets?:list<string>, unavailable_deps?:list<string>,
     *                                             contradiction_pairs?:list<{a:string,b:string}>,
     *                                         }}
     * @return array<string,mixed>
     */
    public function detect(array $packet): array
    {
        $found = [];
        $objective = (string) ($packet['objective'] ?? '');
        $acceptance = array_values((array) ($packet['acceptance_criteria'] ?? []));
        $allowed = array_values((array) ($packet['allowed_files'] ?? []));
        $required = array_values((array) ($packet['required_evidence_kinds'] ?? []));
        $quality = (array) ($packet['quality_facts'] ?? []);

        $removedTargets = array_values((array) ($quality['removed_targets'] ?? []));
        foreach ($removedTargets as $t) {
            $t = (string) $t;
            if ($t === '') {
                continue;
            }
            if (! in_array($t, $allowed, true) && str_contains($objective, $t)) {
                $found[] = [
                    'pattern_id' => self::PATTERN_REMOVED_TARGET,
                    'evidence' => ['target' => $t, 'reason' => 'in_objective_text_but_outside_allowed_files'],
                ];
            }
        }

        // Scan objective for file-path tokens not in allowed_files (even without removed_targets).
        preg_match_all('#\b([a-zA-Z0-9_][a-zA-Z0-9_-]*/(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_][a-zA-Z0-9_.-]*\.[a-zA-Z]{2,5})\b#', $objective, $pathMatches);
        $alreadyReported = array_column(array_filter($found, fn ($f) => $f['pattern_id'] === self::PATTERN_REMOVED_TARGET), 'evidence');
        $alreadyReportedPaths = array_column($alreadyReported, 'target');
        foreach ($pathMatches[1] as $path) {
            if (! in_array($path, $allowed, true) && ! in_array($path, $alreadyReportedPaths, true)) {
                $found[] = [
                    'pattern_id' => self::PATTERN_REMOVED_TARGET,
                    'evidence' => ['target' => $path, 'reason' => 'referenced_in_objective_but_not_in_allowed_files'],
                ];
                $alreadyReportedPaths[] = $path;
            }
        }

        $contradictions = array_values((array) ($quality['contradiction_pairs'] ?? []));
        foreach ($contradictions as $pair) {
            if (! is_array($pair)) {
                continue;
            }
            $a = (string) ($pair['a'] ?? '');
            $b = (string) ($pair['b'] ?? '');
            if ($a !== '' && $b !== '') {
                $found[] = [
                    'pattern_id' => self::PATTERN_CONTRADICTORY_ACCEPTANCE,
                    'evidence' => ['a' => $a, 'b' => $b],
                ];
            }
        }

        // Textual contradiction scan — no precomputed quality_facts needed.
        // Pair (i, j): one has negation + other does not + they share a significant word → contradiction.
        $n = count($acceptance);
        $done = false;
        for ($i = 0; $i < $n && ! $done; $i++) {
            $aLow = strtolower((string) $acceptance[$i]);
            $aNeg = $this->hasNegation($aLow);
            $aWords = $this->significantWords($aLow);
            for ($j = $i + 1; $j < $n && ! $done; $j++) {
                $bLow = strtolower((string) $acceptance[$j]);
                $bNeg = $this->hasNegation($bLow);
                if ($aNeg === $bNeg) {
                    continue;
                }
                $bWords = $this->significantWords($bLow);
                if (array_intersect($aWords, $bWords) !== []) {
                    $found[] = [
                        'pattern_id' => self::PATTERN_CONTRADICTORY_ACCEPTANCE,
                        'evidence' => ['a' => (string) $acceptance[$i], 'b' => (string) $acceptance[$j], 'source' => 'textual_analysis'],
                    ];
                    $done = true;
                }
            }
        }

        $unavailable = array_values((array) ($quality['unavailable_deps'] ?? []));
        foreach (array_intersect($required, $unavailable) as $u) {
            $found[] = [
                'pattern_id' => self::PATTERN_UNAVAILABLE_DEPENDENCY,
                'evidence' => ['dependency' => $u],
            ];
        }

        $basenames = [];
        foreach ($allowed as $file) {
            $base = basename((string) $file);
            $basenames[$base] = ($basenames[$base] ?? 0) + 1;
        }
        foreach ($basenames as $base => $count) {
            if ($count >= 2) {
                $found[] = [
                    'pattern_id' => self::PATTERN_DUPLICATE_CANONICAL_SYMBOL,
                    'evidence' => ['basename' => $base, 'count' => $count],
                ];
            }
        }

        foreach (self::AMBIGUOUS_NEEDLES as $n) {
            if ($n !== '' && str_contains($objective, $n)) {
                $found[] = [
                    'pattern_id' => self::PATTERN_AMBIGUOUS_INSTRUCTION,
                    'evidence' => ['marker' => $n],
                ];
                break; // one ambiguous marker is enough to flag the packet
            }
        }
        if (preg_match('/\bdo not\b.*\bdo\b|\bdo\b.*\bdo not\b/i', $objective)) {
            $found[] = [
                'pattern_id' => self::PATTERN_AMBIGUOUS_INSTRUCTION,
                'evidence' => ['marker' => 'do_and_do_not_both_in_objective'],
            ];
        }

        $objLower = strtolower($objective);
        foreach (self::AUTONOMY_DEP_NEEDLES as $needle) {
            if (str_contains($objLower, $needle)) {
                $found[] = [
                    'pattern_id' => self::PATTERN_PERMANENT_AUTONOMY_DEP,
                    'evidence' => ['phrase' => $needle],
                ];
                break;
            }
        }

        if ($allowed !== [] && $this->allTestOnly($allowed)) {
            $found[] = [
                'pattern_id' => self::PATTERN_TEST_ONLY_ALLOWED_FILES,
                'evidence' => ['allowed_files' => $allowed],
            ];
        }

        if ($acceptance !== [] && $this->allSchemaOnly($acceptance)) {
            $found[] = [
                'pattern_id' => self::PATTERN_SCHEMA_ONLY_ACCEPTANCE,
                'evidence' => ['acceptance_criteria' => $acceptance],
            ];
        }

        $familyStatus = strtolower(trim((string) ($quality['family_status'] ?? '')));
        $failedRespecCount = max(0, (int) ($quality['failed_respec_count'] ?? 0));
        $giveBackCount = max(0, (int) ($quality['give_back_count'] ?? 0));
        $fieldRecoveryConfidence = (float) ($quality['field_recovery_confidence'] ?? 0.0);
        $hasRunnableAcceptance = (bool) ($quality['has_runnable_acceptance'] ?? false);

        $isBlockedFamily = in_array($familyStatus, self::BLOCKED_FAMILY_STATUSES, true);
        $repeatedFailures = $failedRespecCount >= self::REPEATED_FAILURE_THRESHOLD || $giveBackCount >= self::REPEATED_FAILURE_THRESHOLD;
        $isRecoverable = $fieldRecoveryConfidence >= self::FIELD_RECOVERY_CONFIDENCE_THRESHOLD && $hasRunnableAcceptance;

        if ($isBlockedFamily && $repeatedFailures && ! $isRecoverable) {
            $found[] = [
                'pattern_id' => self::PATTERN_REPEATED_FAILED_RESPEC_FAMILY,
                'evidence' => [
                    'family_status' => $familyStatus,
                    'failed_respec_count' => $failedRespecCount,
                    'give_back_count' => $giveBackCount,
                    'retirement_recommended' => true,
                ],
            ];
        }

        $clean = $found === [];
        [$severity, $recommendedAction] = $this->overallVerdict($found);
        $confidence = self::SEVERITY_CONFIDENCE[$severity] ?? 0.5;

        return [
            'schema_version' => self::SCHEMA,
            'found_patterns' => $found,
            'clean' => $clean,
            'severity' => $severity,
            'confidence' => $confidence,
            'recommended_action' => $recommendedAction,
            'safe_explanation' => $clean
                ? 'No poison patterns detected: objective references only allowed_files targets, acceptance criteria are non-contradictory and assert real behavior, required dependencies are available, allowed_files carry no duplicate canonical symbols, instructions are unambiguous, no permanent autonomy dependency wording is present, and family history shows no repeated-failure retirement signal — packet is safe to serve.'
                : null,
        ];
    }

    /**
     * Reduces the found patterns to a single (severity, recommended_action) pair using the
     * most severe matched pattern — retire > quarantine > reshape > serve. A packet with both a
     * "reshape" pattern and a "quarantine" pattern is never softened to reshape.
     *
     * @param  list<array{pattern_id:string,evidence:array<string,mixed>}>  $found
     * @return array{0:string,1:string}
     */
    private function overallVerdict(array $found): array
    {
        if ($found === []) {
            return [self::SEVERITY_NONE, self::ACTION_SERVE];
        }

        $severity = self::SEVERITY_NONE;
        $action = self::ACTION_SERVE;
        foreach ($found as $pattern) {
            $patternId = (string) ($pattern['pattern_id'] ?? '');
            $patternSeverity = self::PATTERN_SEVERITY[$patternId] ?? self::SEVERITY_LOW;
            $patternAction = self::PATTERN_ACTION[$patternId] ?? self::ACTION_RESHAPE;
            if ((self::SEVERITY_RANK[$patternSeverity] ?? 0) > (self::SEVERITY_RANK[$severity] ?? 0)) {
                $severity = $patternSeverity;
            }
            if ((self::ACTION_RANK[$patternAction] ?? 0) > (self::ACTION_RANK[$action] ?? 0)) {
                $action = $patternAction;
            }
        }

        return [$severity, $action];
    }

    private function hasNegation(string $lower): bool
    {
        foreach (self::NEGATION_MARKERS as $m) {
            if (str_contains($lower, $m)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function significantWords(string $text): array
    {
        preg_match_all('/[a-z]{4,}/', $text, $m);

        return array_values(array_diff($m[0], self::TEXT_STOP_WORDS));
    }

    /**
     * True when EVERY allowed_files entry is a test path — a worker handed only test files has
     * no implementation target to write the behavior the test checks (classic scope-repair trap).
     *
     * @param  list<mixed>  $files
     */
    private function allTestOnly(array $files): bool
    {
        foreach ($files as $file) {
            if (! $this->isTestPath((string) $file)) {
                return false;
            }
        }

        return true;
    }

    private function isTestPath(string $path): bool
    {
        return str_starts_with($path, 'tests/') || str_contains($path, '/tests/') || str_ends_with($path, 'Test.php');
    }

    /**
     * True when EVERY acceptance criterion only checks a runnable-gate exit code or schema
     * presence, never asserting actual computed behavior — the packet can pass while doing
     * nothing real.
     *
     * @param  list<mixed>  $criteria
     */
    private function allSchemaOnly(array $criteria): bool
    {
        foreach ($criteria as $criterion) {
            $low = strtolower((string) $criterion);
            $matches = false;
            foreach (self::SCHEMA_ONLY_NEEDLES as $needle) {
                if (str_contains($low, $needle)) {
                    $matches = true;
                    break;
                }
            }
            if (! $matches) {
                return false;
            }
        }

        return true;
    }
}
