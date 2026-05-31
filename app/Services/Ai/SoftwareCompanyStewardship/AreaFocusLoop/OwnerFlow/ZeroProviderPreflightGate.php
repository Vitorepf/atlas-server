<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

/**
 * FASE 2 — zero-provider pre-flight admission gate.
 *
 * A cheap, pure (no I/O, no provider spend) gate evaluated in the AP-786
 * owner-flow cycle BEFORE the atlas_dev owner command (senior-loop /
 * minimax-worker) is ever invoked. Its only job is to decide, for free, whether
 * a slice is qualified enough to be worth a token-spending provider cycle.
 *
 * A cycle is admitted only when ALL of these hold:
 *   1. allowed_files resolved        — at least one concrete allowed file;
 *   2. runnable validation command   — at least one non-empty validation cmd;
 *   3. risk <= R3                     — risk tier within the autonomous ceiling;
 *   4. deps satisfied                — no unmet declared dependencies;
 *   5. scope <= N files / 1 layer    — allowed files within the file budget AND
 *                                       confined to a single architectural layer.
 *
 * When any condition fails the gate returns an honest CHEAP SKIP: no provider is
 * invoked, and the cycle is explicitly marked as NOT token-spending so the loop
 * metric `merges / token-spending-cycles` stays truthful. The gate never weakens
 * any downstream gate — it only refuses to spend tokens on an unqualified slice.
 */
final class ZeroProviderPreflightGate
{
    public const SCHEMA = 'atlas.software_company_stewardship.zero_provider_preflight_gate.v1';

    /** Maximum allowed files for a single autonomous owner-runtime slice. */
    public const MAX_SCOPE_FILES = 5;

    /** Highest risk tier admitted for autonomous owner-runtime execution. */
    public const MAX_RISK_TIER = 3;

    /** Relaxed file budget for an OPERATOR-AUTHORIZED injected plan slice (the operator
     *  approved the build-plan and the slice declares its own allowed_files). A real value
     *  slice often edits a service + its config flag + tests together. */
    public const MAX_SCOPE_FILES_AUTHORIZED = 8;

    /** Layers an operator-authorized injected slice may span. Generic (unauthorized)
     *  findings stay at 1 layer; the merge governor — not this cheap efficiency gate — is
     *  the real safety authority (validation-green + changed⊆allowed + bounded packet). */
    public const MAX_LAYERS_AUTHORIZED = 3;

    public const REASON_NO_ALLOWED_FILES = 'preflight_no_allowed_files';

    public const REASON_NO_VALIDATION_COMMAND = 'preflight_no_runnable_validation_command';

    public const REASON_RISK_ABOVE_CEILING = 'preflight_risk_above_r3';

    public const REASON_DEPS_UNSATISFIED = 'preflight_dependencies_unsatisfied';

    public const REASON_SCOPE_TOO_LARGE = 'preflight_scope_exceeds_file_budget';

    public const REASON_SCOPE_MULTIPLE_LAYERS = 'preflight_scope_spans_multiple_layers';

    /** A "write a test for an EXISTING class" finding whose subject is not autonomously
     *  testable in one shot (has constructor dependencies or is large/branchy). Empirically
     *  both providers spend a full provider call and then fail to produce a passing test for
     *  such subjects, which is exactly the provider-spent-then-failed waste the >=96% useful-
     *  output bar forbids. Skipped BEFORE any provider spend; routed to operator/enrichment. */
    public const REASON_TEST_SUBJECT_NOT_AUTONOMOUSLY_TESTABLE = 'preflight_test_subject_not_autonomously_testable';

    public const REASON_PRIOR_NON_RETRYABLE_FAILURE_PATTERN = 'preflight_prior_non_retryable_failure_pattern';

    public const REASON_LARGE_EXISTING_RUNTIME_SURFACE_NEEDS_NARROWER_SLICE = 'preflight_large_existing_runtime_surface_needs_narrower_slice';

    /** Max constructor dependencies a test-authoring subject may have and still be admitted
     *  for an autonomous single-shot test. The proven-deliverable subjects were pure (0 deps);
     *  the proven-failed subject had a constructor dependency + domain logic. */
    public const MAX_AUTONOMOUS_TEST_SUBJECT_CONSTRUCTOR_DEPS = 0;

    /** Max subject LOC for an autonomous single-shot test. */
    public const MAX_AUTONOMOUS_TEST_SUBJECT_LOC = 200;

    /** Existing product files above this size are too broad for an unanchored provider mutation. */
    public const MAX_AUTONOMOUS_EXISTING_RUNTIME_SURFACE_LOC = 1500;

    public const PRIOR_FAILURE_PATTERN_BLOCK_THRESHOLD = 2;

    /**
     * Evaluate the gate. Pure: never invokes a provider, never shells out.
     *
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     * @param  array<string,mixed>  $finding
     * @return array{
     *     schema_version:string,
     *     admitted:bool,
     *     token_spending_cycle:bool,
     *     blockers:list<string>,
     *     risk_tier:int,
     *     scope_file_count:int,
     *     scope_layers:list<string>,
     *     deps_unsatisfied:list<string>,
     *     evaluated_at:string
     * }
     */
    public function evaluate(array $allowedFiles, array $validationCommands, array $finding): array
    {
        $allowedFiles = $this->stringList($allowedFiles);
        $validationCommands = $this->stringList($validationCommands);

        $blockers = [];

        if ($allowedFiles === []) {
            $blockers[] = self::REASON_NO_ALLOWED_FILES;
        }

        if (! $this->hasRunnableValidationCommand($validationCommands)) {
            $blockers[] = self::REASON_NO_VALIDATION_COMMAND;
        }

        $riskTier = $this->riskTier($finding);
        if ($riskTier > self::MAX_RISK_TIER) {
            $blockers[] = self::REASON_RISK_ABOVE_CEILING;
        }

        $depsUnsatisfied = $this->unsatisfiedDependencies($finding);
        if ($depsUnsatisfied !== []) {
            $blockers[] = self::REASON_DEPS_UNSATISFIED;
        }

        // An OPERATOR-AUTHORIZED injected plan slice (operator approved the build-plan and
        // the slice declares its own allowed_files) may legitimately span a few layers and a
        // slightly larger bounded file set — a real value slice often edits a service, its
        // config flag and tests together. This relaxes ONLY this cheap pre-flight efficiency
        // gate; the merge governor downstream still enforces validation-green, changed⊆allowed
        // and the bounded-packet ceiling as the real safety authority, so nothing is weakened.
        $authorized = ($finding['auto_execution_allowed'] ?? false) === true
            && ($finding['operator_review_required'] ?? true) === false;
        $maxFiles = $authorized ? self::MAX_SCOPE_FILES_AUTHORIZED : self::MAX_SCOPE_FILES;
        $maxLayers = $authorized ? self::MAX_LAYERS_AUTHORIZED : 1;

        $fileCount = count($allowedFiles);
        if ($fileCount > $maxFiles) {
            $blockers[] = self::REASON_SCOPE_TOO_LARGE;
        }

        $layers = $this->layers($allowedFiles);
        if (count($layers) > $maxLayers) {
            $blockers[] = self::REASON_SCOPE_MULTIPLE_LAYERS;
        }

        // Pre-spend deliverability gate for "write a test for an EXISTING class" findings.
        // Both providers reliably create a NEW class + its test from a spec (they control both
        // sides), but reliably FAIL to author a passing test for an arbitrary existing class
        // that carries constructor dependencies or non-trivial logic — they spend a full
        // provider call and produce a failing or empty diff. Skip those BEFORE spend so the
        // loop only burns the provider where it tends to deliver. The caller computes the
        // subject signal (it has worktree file access); this stays a pure decision.
        if ($this->testSubjectNotAutonomouslyTestable($finding)) {
            $blockers[] = self::REASON_TEST_SUBJECT_NOT_AUTONOMOUSLY_TESTABLE;
        }
        if ($this->knownNonRetryableFailurePattern($finding)) {
            $blockers[] = self::REASON_PRIOR_NON_RETRYABLE_FAILURE_PATTERN;
        }
        if ($this->largeExistingRuntimeSurfaceNeedsNarrowerSlice($finding)) {
            $blockers[] = self::REASON_LARGE_EXISTING_RUNTIME_SURFACE_NEEDS_NARROWER_SLICE;
        }

        $admitted = $blockers === [];

        return [
            'schema_version' => self::SCHEMA,
            'admitted' => $admitted,
            // A skipped cycle never reaches a provider, so it must NOT count
            // toward token-spending cycles in the merges/token-spending metric.
            'token_spending_cycle' => $admitted,
            'blockers' => array_values($blockers),
            'risk_tier' => $riskTier,
            'scope_file_count' => $fileCount,
            'scope_layers' => array_values($layers),
            'operator_authorized' => $authorized,
            'deps_unsatisfied' => $depsUnsatisfied,
            'evaluated_at' => gmdate('c'),
        ];
    }

    /**
     * Decide, from the caller-computed subject signal, whether a test-authoring finding
     * targets a subject the loop cannot autonomously test in one shot. Pure: reads only the
     * signal the caller attached to the finding (the file read happens in the executor, which
     * owns the worktree). Returns false (admit) when the finding is not test-authoring or the
     * subject does not exist yet (a brand-new class created WITH its test is the proven-
     * deliverable path and must NOT be blocked here).
     *
     * @param  array<string,mixed>  $finding
     */
    private function testSubjectNotAutonomouslyTestable(array $finding): bool
    {
        $signal = $finding['preflight_test_authoring'] ?? null;
        if (! is_array($signal)) {
            return false;
        }
        if (($signal['is_test_authoring'] ?? false) !== true) {
            return false;
        }
        // A new class created together with its test (subject does not pre-exist) is the
        // proven-deliverable case — never blocked by this gate.
        if (($signal['subject_exists'] ?? false) !== true) {
            return false;
        }
        $deps = max(0, (int) ($signal['subject_constructor_deps'] ?? 0));
        $loc = max(0, (int) ($signal['subject_loc'] ?? 0));

        return $deps > self::MAX_AUTONOMOUS_TEST_SUBJECT_CONSTRUCTOR_DEPS
            || $loc > self::MAX_AUTONOMOUS_TEST_SUBJECT_LOC;
    }

    /**
     * Repair learning is allowed to save tokens, never to weaken quality. If a task class has
     * repeatedly produced non-retryable delivery/provider-diff failures, the next cycle must
     * stop before provider spend and wait for a narrower slice or operator repair.
     *
     * @param  array<string,mixed>  $finding
     */
    private function knownNonRetryableFailurePattern(array $finding): bool
    {
        $repairLearning = $finding['repair_learning'] ?? null;
        if (! is_array($repairLearning)) {
            return false;
        }

        return self::repairLearningShowsNonRetryableFailurePattern($repairLearning);
    }

    /**
     * @param  array<string,mixed>  $repairLearning
     */
    public static function repairLearningShowsNonRetryableFailurePattern(array $repairLearning): bool
    {
        $occurrences = max(0, (int) ($repairLearning['prior_blocked_occurrences'] ?? 0));
        if ($occurrences < self::PRIOR_FAILURE_PATTERN_BLOCK_THRESHOLD) {
            return false;
        }
        $priorBlockers = array_values(array_unique(array_merge(
            self::stringListStatic($repairLearning['top_prior_blockers'] ?? []),
            self::stringListStatic($repairLearning['all_prior_blockers'] ?? []),
            self::detailBlockers($repairLearning['detail'] ?? []),
        )));
        if ($priorBlockers === []) {
            return false;
        }
        $nonRetryableSignals = [
            'delivery_not_final_scaffold_or_mock',
            'owner_runtime_delivery_not_final_scaffold_or_mock',
            'provider_diff_quality_gate_failed',
            'owner_runtime_provider_diff_quality_gate_failed',
            'large_product_diff_without_test_update',
            'owner_runtime_large_product_diff_without_test_update',
            'large_product_deletion_without_test_update',
            'owner_runtime_large_product_deletion_without_test_update',
            'large_single_file_deletion_without_test_update',
            'owner_runtime_large_single_file_deletion_without_test_update',
            'deletion_heavy_product_diff_without_test_update',
            'owner_runtime_deletion_heavy_product_diff_without_test_update',
            'minimax_no_code_extracted',
            'owner_runtime_minimax_no_code_extracted',
        ];

        return array_intersect($priorBlockers, $nonRetryableSignals) !== [];
    }

    /**
     * Large existing runtime classes are the main provider-waste trap: the model can
     * touch an allowed file, yet rewrite/delete too much or skip the focused test.
     * Admit only when the caller provides a structured surgical anchor, or when the
     * surface is small enough for a single autonomous provider mutation.
     *
     * @param  array<string,mixed>  $finding
     */
    private function largeExistingRuntimeSurfaceNeedsNarrowerSlice(array $finding): bool
    {
        $signal = $finding['preflight_runtime_surface'] ?? null;
        if (! is_array($signal)) {
            return false;
        }
        if (($signal['is_runtime_mutation'] ?? false) !== true) {
            return false;
        }
        if (($signal['explicit_narrow_anchor'] ?? false) === true) {
            return false;
        }

        $maxLoc = max(0, (int) ($signal['max_existing_product_loc'] ?? 0));

        return $maxLoc > self::MAX_AUTONOMOUS_EXISTING_RUNTIME_SURFACE_LOC;
    }

    /**
     * @return list<string>
     */
    private static function detailBlockers(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }
            $blocker = trim((string) ($row['blocker'] ?? ''));
            if ($blocker !== '') {
                $out[] = $blocker;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private static function stringListStatic(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * A runnable validation command exists when the slice declares at least one
     * non-empty command the owner runtime can execute to prove the change. The
     * gate refuses a slice with NO declared validation (the diff could never be
     * verified, so spending a provider call on it is waste); it does not second-
     * guess which command — that is the senior-loop / verification gate's job.
     *
     * @param  list<string>  $validationCommands
     */
    private function hasRunnableValidationCommand(array $validationCommands): bool
    {
        return $validationCommands !== [];
    }

    /**
     * Resolve the risk tier as an integer 0..5. Accepts the canonical R-tier
     * notation (`R3`, `r3`, `R3+`) and the worded scale (low/medium/high/...).
     * Unknown values default to the ceiling (R3) so they are admitted rather
     * than silently blocked, while explicit high tiers (R4/R5) are refused.
     *
     * @param  array<string,mixed>  $finding
     */
    private function riskTier(array $finding): int
    {
        $raw = $finding['risk_tier'] ?? $finding['risk_level'] ?? $finding['risk'] ?? $finding['risk_band'] ?? null;
        if (is_int($raw)) {
            return max(0, min(5, $raw));
        }
        if (! is_string($raw)) {
            return self::MAX_RISK_TIER;
        }
        $value = strtolower(trim($raw));
        if ($value === '') {
            return self::MAX_RISK_TIER;
        }
        if (preg_match('/^r([0-5])/', $value, $m) === 1) {
            return (int) $m[1];
        }

        return match ($value) {
            'trivial', 'none' => 0,
            'very_low', 'minimal' => 1,
            'low' => 2,
            'medium', 'moderate' => 3,
            'high' => 4,
            'very_high', 'critical', 'severe' => 5,
            default => self::MAX_RISK_TIER,
        };
    }

    /**
     * Declared dependencies that are not yet satisfied. A dependency is a string
     * id; satisfied ids come from the finding's `satisfied_dependencies` (or
     * `deps_satisfied`) list. Any declared dep not present there is unsatisfied.
     *
     * @param  array<string,mixed>  $finding
     * @return list<string>
     */
    private function unsatisfiedDependencies(array $finding): array
    {
        $declared = $this->stringList($finding['dependencies'] ?? $finding['depends_on'] ?? []);
        if ($declared === []) {
            return [];
        }
        $satisfied = $this->stringList($finding['satisfied_dependencies'] ?? $finding['deps_satisfied'] ?? []);

        return array_values(array_filter(
            $declared,
            static fn (string $dep): bool => ! in_array($dep, $satisfied, true),
        ));
    }

    /**
     * Distinct production-code layers touched by the slice. "1 layer" means the
     * production files all share one layer (e.g. app/Services) so the slice does
     * not straddle unrelated subsystems in a single provider call. Accompanying
     * test files (the TDD mirror) are NOT counted as a separate layer — a code
     * file plus its *Test.php is one logical slice, not two layers.
     *
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function layers(array $allowedFiles): array
    {
        $layers = [];
        foreach ($allowedFiles as $file) {
            $layer = $this->layerOf($file);
            if ($layer === null) {
                continue; // test mirror — pairs with code, not its own layer.
            }
            $layers[$layer] = true;
        }

        // A test-only slice is still a single (test) layer.
        if ($layers === [] && $allowedFiles !== []) {
            return ['tests'];
        }

        return array_keys($layers);
    }

    /**
     * Production layer of a file, keyed by its first two path segments (e.g.
     * app/Services, app/Console are distinct). Returns null for test files so
     * they never inflate the layer count of a code+test slice.
     */
    private function layerOf(string $file): ?string
    {
        $normalized = ltrim(str_replace('\\', '/', trim($file)), '/');
        $segments = array_values(array_filter(explode('/', $normalized), static fn (string $s): bool => $s !== ''));
        if ($segments === []) {
            return 'root';
        }
        $first = $segments[0];
        if (in_array($first, ['tests', 'test'], true) || str_ends_with($normalized, 'Test.php')) {
            return null;
        }
        if (isset($segments[1])) {
            return $first.'/'.$segments[1];
        }

        return $first;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }
}
