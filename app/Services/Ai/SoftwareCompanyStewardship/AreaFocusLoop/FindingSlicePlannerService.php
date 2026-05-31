<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Software Company Stewardship Stack · Area Focus Loop ·
 * Finding Slice Planner (AP-796, runtime of AP-794).
 *
 * The 24h loop must optimize for the software factory becoming more powerful,
 * not for churn. The recurring failure mode is that the highest-leverage
 * findings are too broad for a single provider call, so a broad
 * self-referential finding ("make the factory better") reaches a provider and
 * produces a timeout, no patch or an accidental edit.
 *
 * AP-796 makes decomposition a blocking gate. Given one finding plus mode /
 * scope_profile (and optional context), it produces an
 * {@see self::PLAN_SCHEMA} that either:
 *
 *   - decomposes the finding into one or more bounded
 *     {@see self::SLICE_SCHEMA} executable slices (decomposition_status =
 *     sliced); or
 *   - blocks honestly with the precise reason no honest slice exists
 *     (decomposition_status = blocked / operator_review_required).
 *
 * Hard invariants (this is a pure planner, NOT a runtime):
 *   - NEVER calls a provider, NEVER opens a branch/worktree, NEVER runs git,
 *     NEVER merges and NEVER mutates anything outside the returned plan.
 *   - NEVER reads the filesystem; it plans purely from the finding + context.
 *   - Deterministic: identical input yields an identical plan_hash.
 *
 * AP-794 is the architecture contract; this service is its runtime. It reuses
 * the AP-748 finding shape and AP-786 owner/allowed-file vocabulary; it does not
 * re-implement finding discovery, priority, owner runtime, provider routing,
 * merge governance, judge, repair or multi-agent lanes.
 */
final class FindingSlicePlannerService
{
    public const PLAN_SCHEMA = 'atlas.stewardship.finding_slice_plan.v1';

    public const SLICE_SCHEMA = 'atlas.stewardship.executable_slice.v1';

    public const STATUS_SLICED = 'sliced';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_OPERATOR_REVIEW = 'operator_review_required';

    public const SCOPE_BALANCED = 'balanced';

    public const SCOPE_FACTORY_MAX = 'factory_max';

    public const MODE_DRY_RUN = 'dry_run';

    public const MODE_RECORD = 'record';

    /** Canonical executable-slice owners. */
    public const OWNER_ATLAS_DEV = 'atlas_dev';

    public const OWNER_FORGE = 'forge';

    public const OWNER_STEWARDSHIP = 'stewardship';

    public const OWNER_MEMORY = 'memory';

    /** Canonical blockers. */
    public const BLOCKER_OPERATOR_OR_ARCHITECT_SPEC_REQUIRED = 'operator_or_architect_spec_required';

    public const BLOCKER_OWNER_RUNTIME_NOT_READY = 'owner_runtime_not_ready';

    public const BLOCKER_VALIDATION_COMMAND_MISSING = 'validation_command_missing';

    public const BLOCKER_ALLOWED_FILES_TOO_BROAD = 'allowed_files_too_broad';

    public const BLOCKER_PROVIDER_FIT_UNKNOWN = 'provider_fit_unknown';

    public const BLOCKER_EVIDENCE_OBLIGATIONS_MISSING = 'evidence_obligations_missing';

    public const BLOCKER_FACTORY_MAX_DOCS_ONLY_CHURN = 'factory_max_docs_only_churn';

    /** Expected diff shapes. */
    public const SHAPE_TEST_ONLY = 'test_only';

    public const SHAPE_SERVICE_ONLY = 'service_only';

    public const SHAPE_SERVICE_AND_TEST = 'service_and_test';

    public const SHAPE_DOCS_AND_TEST = 'docs_and_test';

    public const SHAPE_DOCS_ONLY = 'docs_only';

    /** Merge policies. */
    public const MERGE_AUTO_ELIGIBLE = 'auto_merge_eligible';

    public const MERGE_REVIEW_REQUIRED = 'review_required';

    public const MERGE_NEVER_AUTO = 'never_auto_merge';

    /** A single slice may never authorize more than this many files. */
    public const MAX_FILES_PER_SLICE = 4;

    /** A plan may never emit more than this many slices. */
    public const MAX_SLICES = 8;

    /** Default bounded runtime budget for a slice (seconds). */
    public const DEFAULT_MAX_RUNTIME_SECONDS = 900;

    /** @var list<string> Paths a slice must never touch. */
    private const FORBIDDEN_FILES = ['.env', 'storage/secrets', 'config/secrets', 'vendor/', 'node_modules/'];

    /**
     * Generic "improve everything" prompts that can never be sliced
     * autonomously. Matched case-insensitively against the finding objective.
     *
     * @var list<string>
     */
    /**
     * Leading verbs that mark a finding as a large strategic "build a capability"
     * roadmap item which must be semantically decomposed into ordered steps.
     *
     * @var list<string>
     */
    private const STRATEGIC_CAPABILITY_VERBS = [
        'introduce', 'implement', 'wire', 'materialize', 'build', 'establish',
        'enable', 'close', 'strengthen', 'route', 'unify', 'expose', 'give', 'generate',
        'dispatch', 'measure', 'evaluate', 'consolidate', 'require', 'let',
        'harden', 'repair', 'improve', 'tune', 'expand', 'suppress', 'reduce',
    ];

    private const GENERIC_OBJECTIVE_PATTERNS = [
        'make atlas better',
        'make the factory better',
        'make the software factory better',
        'improve atlas',
        'improve the factory',
        'improve the software factory',
        'make everything better',
        'improve everything',
        'make it better',
        'be better',
        'general improvements',
        'overall improvement',
    ];

    /**
     * Phrases that justify a docs-only change inside factory_max because it
     * unblocks runtime/certification or stops future agents from lying.
     *
     * @var list<string>
     */
    private const DOCS_RUNTIME_UNLOCK_PATTERNS = [
        'unblock',
        'unlock',
        'certification',
        'certify',
        'runtime',
        'stops future agents from lying',
        'stop future agents from lying',
        'prevents future agents from lying',
        'governance correction',
        'contract drift',
    ];

    /**
     * Plan one finding into bounded executable slices, or block honestly.
     *
     * @param  array{finding?:array<string,mixed>,mode?:string,scope_profile?:string,context?:array<string,mixed>}  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $finding = is_array($input['finding'] ?? null) ? $input['finding'] : [];
        $mode = $this->mode((string) ($input['mode'] ?? self::MODE_DRY_RUN));
        $scopeProfile = $this->scopeProfile((string) ($input['scope_profile'] ?? self::SCOPE_BALANCED));
        $context = is_array($input['context'] ?? null) ? $input['context'] : [];

        $normalized = $this->normalizeFinding($finding, $context);
        $sourceReceipts = $this->sourceReceipts($normalized, $context);

        $decision = $this->decompose($normalized, $scopeProfile, $context);

        return $this->finalizePlan($normalized, $scopeProfile, $mode, $decision, $sourceReceipts);
    }

    /**
     * @param  array<string,mixed>  $normalized
     * @param  array<string,mixed>  $context
     * @return array{status:string,blockers:list<string>,slices:list<array<string,mixed>>}
     */
    private function decompose(array $normalized, string $scopeProfile, array $context): array
    {
        $objective = $normalized['objective_text'];
        $sourceFiles = $normalized['source_files'];
        $testFiles = $normalized['test_files'];
        $docFiles = $normalized['doc_files'];
        $hasConcreteCode = $sourceFiles !== [] || $testFiles !== [];

        // 1. A generic "make Atlas better" prompt with no concrete target is not
        // executable work; it requires an operator/architect spec.
        if ($this->isGenericObjective($objective) && ! $hasConcreteCode) {
            return $this->blocked([self::BLOCKER_OPERATOR_OR_ARCHITECT_SPEC_REQUIRED]);
        }

        // 2. factory_max rejects docs-only churn unless it unblocks runtime or
        // certification.
        if ($docFiles !== [] && ! $hasConcreteCode) {
            if ($scopeProfile === self::SCOPE_FACTORY_MAX && ! $this->unblocksRuntimeOrCertification($normalized)) {
                return $this->blocked([self::BLOCKER_FACTORY_MAX_DOCS_ONLY_CHURN]);
            }

            return $this->buildSlices([$this->docsTargetGroup($docFiles)], $normalized, $context, true);
        }

        // 3. No bounded file scope at all.
        if (! $hasConcreteCode && $docFiles === []) {
            return $this->blocked([self::BLOCKER_ALLOWED_FILES_TOO_BROAD]);
        }

        // AP-806: a large strategic "introduce/implement a capability" finding is
        // decomposed SEMANTICALLY into an ordered tree of small steps
        // (runtime-backed contract -> skeleton -> first behavior), each with its
        // own narrowed objective — not the whole finding restated. Only the first
        // small step is meant to run per cycle. Small/concrete findings keep the
        // existing file-group path.
        if ($this->isLargeStrategicFinding($normalized, $scopeProfile)) {
            $stepGroups = $this->semanticStepGroups($normalized, $sourceFiles, $testFiles);
            if ($stepGroups !== []) {
                return $this->buildSlices($stepGroups, $normalized, $context, false);
            }
        }

        $groups = $this->targetGroups($sourceFiles, $testFiles);

        return $this->buildSlices($groups, $normalized, $context, false);
    }

    /**
     * A large strategic finding ("Introduce/Implement/Wire a capability …") on
     * real source files cannot be completed in one provider shot; it must be
     * decomposed into ordered small steps. Routine missing-test/docs findings are
     * already small and keep the file-group path.
     *
     * @param  array<string,mixed>  $normalized
     */
    private function isLargeStrategicFinding(array $normalized, string $scopeProfile): bool
    {
        if ($scopeProfile !== self::SCOPE_FACTORY_MAX) {
            return false;
        }
        if (($normalized['source_files'] ?? []) === []) {
            return false;
        }
        if ((string) ($normalized['kind'] ?? '') === 'test') {
            return false;
        }
        if (in_array((string) ($normalized['origin_type'] ?? ''), ['missing_test'], true)) {
            return false;
        }

        $title = strtolower(trim((string) ($normalized['title'] ?? '')));
        foreach (self::STRATEGIC_CAPABILITY_VERBS as $verb) {
            if (str_starts_with($title, $verb.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decompose a strategic capability finding on its primary target into an
     * ordered dependency tree of small steps. Each step touches the same small
     * file scope but carries a DISTINCT, narrowed objective that explicitly
     * excludes the rest of the feature, so the provider can complete one step.
     *
     * @param  array<string,mixed>  $normalized
     * @param  list<string>  $sourceFiles
     * @param  list<string>  $testFiles
     * @return list<array{files:list<string>,docs:bool,step:array<string,mixed>}>
     */
    private function semanticStepGroups(array $normalized, array $sourceFiles, array $testFiles): array
    {
        $primary = $sourceFiles[0] ?? '';
        if ($primary === '') {
            return [];
        }
        $cap = $this->capabilityPhrase((string) ($normalized['title'] ?? ''));
        $title = (string) ($normalized['title'] ?? 'the finding');
        $test = $this->matchingTest($primary, $testFiles);
        $implementationFiles = $test !== '' ? [$primary, $test] : [$primary];
        $contractFiles = $this->semanticContractFiles($primary, $cap);
        if ($contractFiles === []) {
            $contractFiles = $implementationFiles;
        }

        $steps = [
            [
                'order' => 1,
                'kind' => 'contract',
                'shape' => self::SHAPE_SERVICE_AND_TEST,
                'objective' => sprintf(
                    'STEP 1 of 3 of the "%s" roadmap — do NOT implement the whole feature. Create or update ONLY the minimal PSR-4 data contract for "%s" in %s. Do NOT define the contract class inside %s. Then wire exactly one default/entry method in %s that consumes or returns that contract. Add focused tests for the contract default and the runtime wiring. This step is invalid if the diff only changes *Contract.php or reflection-only tests; changed_files must include %s and a focused runtime test. No real transformation logic yet.',
                    $title,
                    $cap,
                    $contractFiles[0] ?? $primary,
                    $primary,
                    $primary,
                    $primary,
                ),
            ],
            [
                'order' => 2,
                'kind' => 'skeleton',
                'depends_on' => 1,
                'shape' => self::SHAPE_SERVICE_AND_TEST,
                'objective' => sprintf(
                    'STEP 2 of 3 of the "%s" roadmap — depends on step 1, do NOT implement the full behavior. In %s add ONLY one entry method for "%s" that validates its input and returns the empty/default contract from step 1, plus a unit test asserting the empty-input path returns the default. No real transformation logic yet.',
                    $title, $primary, $cap,
                ),
            ],
            [
                'order' => 3,
                'kind' => 'first_behavior',
                'depends_on' => 2,
                'shape' => self::SHAPE_SERVICE_AND_TEST,
                'objective' => sprintf(
                    'STEP 3 of 3 of the "%s" roadmap — depends on step 2, implement ONLY the first rule. In %s make the entry method handle the single simplest "%s" case so one concrete well-defined input produces one concrete output, plus a unit test for exactly that case. Leave every remaining rule for future steps.',
                    $title, $primary, $cap,
                ),
            ],
        ];

        $groups = [];
        foreach ($steps as $step) {
            $files = (int) $step['order'] === 1
                ? array_values(array_unique(array_merge($contractFiles, $implementationFiles)))
                : $implementationFiles;

            $groups[] = [
                'files' => $files,
                'docs' => false,
                'step' => $step,
            ];
        }

        return $groups;
    }

    /**
     * Strategic contract steps must authorize the PSR-4 file that will contain the
     * value object. Without this, providers are cornered into putting a new class
     * inside the service under test, which focused tests may miss but the workcell
     * judge correctly rejects.
     *
     * @return list<string>
     */
    private function semanticContractFiles(string $primary, string $capability): array
    {
        if (! str_starts_with($primary, 'app/') || ! str_ends_with($primary, '.php')) {
            return [];
        }

        $class = $this->semanticContractClassName($capability);
        if ($class === '') {
            return [];
        }

        $source = rtrim(dirname($primary), '.').'/'.$class.'.php';
        $test = $this->expectedTestPath($class.'Test.php', [$source]);

        return array_values(array_filter(array_unique([$source, $test]), fn (string $file): bool => ! $this->isBroadPath($file) && ! $this->isForbidden($file)));
    }

    private function semanticContractClassName(string $capability): string
    {
        $normalized = trim((string) preg_replace('/[^A-Za-z0-9]+/', ' ', str_replace(['-', '_'], ' ', $capability)));
        if ($normalized === '') {
            return '';
        }

        $words = array_values(array_filter(explode(' ', $normalized), static fn (string $word): bool => $word !== ''));
        if ($words === []) {
            return '';
        }

        $last = count($words) - 1;
        $lowerLast = strtolower($words[$last]);
        if (str_ends_with($lowerLast, 'ies') && strlen($lowerLast) > 3) {
            $words[$last] = substr($words[$last], 0, -3).'y';
        } elseif (str_ends_with($lowerLast, 's') && ! str_ends_with($lowerLast, 'ss') && strlen($lowerLast) > 3) {
            $words[$last] = substr($words[$last], 0, -1);
        }

        $class = implode('', array_map(
            static fn (string $word): string => ucfirst(strtolower($word)),
            $words,
        ));

        if (! preg_match('/(Contract|Slice|Packet|Plan|Spec|Schema)$/', $class)) {
            $class .= 'Contract';
        }

        return $class;
    }

    /**
     * Extract the human capability noun phrase from a finding title by dropping a
     * leading action verb and any trailing prepositional clause. e.g.
     * "Introduce Reality Compiler slices for intent-to-system execution" ->
     * "Reality Compiler slices".
     */
    private function capabilityPhrase(string $title): string
    {
        $t = trim($title);
        $t = (string) preg_replace('/^(introduce|implement|add|wire|materialize|build|create|establish|make|enable|close|strengthen|route|unify|expose|give|generate|run|dispatch|measure|evaluate|let|require|consolidate|attach|surface|harden)\s+/i', '', $t);
        $parts = preg_split('/\s+(for|into|in|of|to|across|before|after|on|with|so|that|when|as)\s+/i', $t, 2);
        $t = trim($parts[0] ?? $t);

        return $t !== '' ? $t : 'this capability';
    }

    /**
     * Group target files into bounded per-target slices. A broad finding with
     * several concrete source files is decomposed into one slice per source
     * file (each paired with its matching test); a narrow finding yields one
     * slice. Test-only findings yield one slice per test.
     *
     * @param  list<string>  $sourceFiles
     * @param  list<string>  $testFiles
     * @return list<array{files:list<string>,docs:bool}>
     */
    private function targetGroups(array $sourceFiles, array $testFiles): array
    {
        $remainingTests = $testFiles;
        $groups = [];

        foreach ($sourceFiles as $source) {
            $match = $this->matchingTest($source, $remainingTests);
            $files = [$source];
            if ($match !== '') {
                $files[] = $match;
                $remainingTests = array_values(array_filter($remainingTests, static fn (string $t): bool => $t !== $match));
            }
            $groups[] = ['files' => array_values(array_slice(array_unique($files), 0, self::MAX_FILES_PER_SLICE)), 'docs' => false];
        }

        // Test-only finding (missing_test where affected_files are the tests).
        if ($sourceFiles === []) {
            foreach ($remainingTests as $test) {
                $groups[] = ['files' => [$test], 'docs' => false];
            }
        } elseif ($remainingTests !== []) {
            // Unmatched tests attach to the first source slice (kept bounded).
            $first = $groups[0]['files'];
            $groups[0]['files'] = array_values(array_slice(array_unique(array_merge($first, $remainingTests)), 0, self::MAX_FILES_PER_SLICE));
        }

        return array_values(array_slice($groups, 0, self::MAX_SLICES));
    }

    /**
     * @param  list<string>  $docFiles
     * @return array{files:list<string>,docs:bool}
     */
    private function docsTargetGroup(array $docFiles): array
    {
        return ['files' => array_values(array_slice($docFiles, 0, self::MAX_FILES_PER_SLICE)), 'docs' => true];
    }

    /**
     * Turn target groups into validated executable slices. Each group that
     * fails the quality bar contributes its blocker reasons; a plan is `sliced`
     * only when at least one slice passes the bar.
     *
     * @param  list<array{files:list<string>,docs:bool}>  $groups
     * @param  array<string,mixed>  $normalized
     * @param  array<string,mixed>  $context
     * @return array{status:string,blockers:list<string>,slices:list<array<string,mixed>>}
     */
    private function buildSlices(array $groups, array $normalized, array $context, bool $docsOnlyFinding): array
    {
        $slices = [];
        $blockers = [];
        $sequence = 0;

        foreach ($groups as $group) {
            $sequence++;
            $built = $this->buildSlice($group, $normalized, $context, $sequence, $docsOnlyFinding);
            if ($built['ok']) {
                $slices[] = $built['slice'];

                continue;
            }
            foreach ($built['blockers'] as $blocker) {
                $blockers[] = $blocker;
            }
        }

        if ($slices === []) {
            return $this->blocked($blockers !== [] ? $blockers : [self::BLOCKER_ALLOWED_FILES_TOO_BROAD]);
        }

        // Re-sequence kept slices so sequence numbers stay dense and ordered.
        foreach ($slices as $index => $slice) {
            $slices[$index]['sequence'] = $index + 1;
            $slices[$index]['slice_id'] = $this->sliceId($normalized['finding_hash'], $index + 1, $slice['allowed_files']);
        }

        return ['status' => self::STATUS_SLICED, 'blockers' => [], 'slices' => $slices];
    }

    /**
     * @param  array{files:list<string>,docs:bool}  $group
     * @param  array<string,mixed>  $normalized
     * @param  array<string,mixed>  $context
     * @return array{ok:bool,slice:array<string,mixed>,blockers:list<string>}
     */
    private function buildSlice(array $group, array $normalized, array $context, int $sequence, bool $docsOnlyFinding): array
    {
        $allowedFiles = $this->boundedAllowedFiles($group['files']);
        if ($allowedFiles === []) {
            return ['ok' => false, 'slice' => [], 'blockers' => [self::BLOCKER_ALLOWED_FILES_TOO_BROAD]];
        }

        // AP-806 semantic step: when present, the step carries its own narrowed
        // objective + shape so the slice is a small ordered sub-task, not the
        // whole finding restated.
        $step = is_array($group['step'] ?? null) ? $group['step'] : null;
        $isDocs = $group['docs'];
        $sliceTests = $this->sliceValidationTests($allowedFiles, $normalized);
        $validationCommands = $this->validationCommands($sliceTests, $isDocs, $context);
        if (! $this->hasFocusedValidation($validationCommands, $isDocs)) {
            return ['ok' => false, 'slice' => [], 'blockers' => [self::BLOCKER_VALIDATION_COMMAND_MISSING]];
        }

        $owner = $this->resolveOwner($normalized['owner_candidate'], $isDocs);
        if ($owner === '') {
            return ['ok' => false, 'slice' => [], 'blockers' => [self::BLOCKER_PROVIDER_FIT_UNKNOWN]];
        }
        if (! $this->ownerRuntimeReady($owner, $context)) {
            return ['ok' => false, 'slice' => [], 'blockers' => [self::BLOCKER_OWNER_RUNTIME_NOT_READY]];
        }

        $providerFit = $this->providerFit($owner);
        if ($providerFit === null) {
            return ['ok' => false, 'slice' => [], 'blockers' => [self::BLOCKER_PROVIDER_FIT_UNKNOWN]];
        }

        $evidenceObligations = $this->evidenceObligations($normalized, $allowedFiles, $sliceTests, $isDocs);
        if ($evidenceObligations === []) {
            return ['ok' => false, 'slice' => [], 'blockers' => [self::BLOCKER_EVIDENCE_OBLIGATIONS_MISSING]];
        }

        $shape = $step !== null ? (string) $step['shape'] : $this->expectedDiffShape($allowedFiles, $isDocs);
        $riskLevel = $this->riskLevel($normalized['severity']);
        $mergePolicy = $this->mergePolicy($owner, $shape, $riskLevel);

        $slice = [
            'schema_version' => self::SLICE_SCHEMA,
            'slice_id' => $this->sliceId($normalized['finding_hash'], $sequence, $allowedFiles),
            'sequence' => $sequence,
            'owner' => $owner,
            'risk_level' => $riskLevel,
            'objective' => $step !== null ? (string) $step['objective'] : $this->sliceObjective($normalized, $allowedFiles, $isDocs),
            'decomposition' => $step !== null ? 'semantic_step:'.(string) $step['kind'] : 'file_group',
            'depends_on_sequence' => $step['depends_on'] ?? null,
            'allowed_files' => $allowedFiles,
            'forbidden_files' => self::FORBIDDEN_FILES,
            'expected_diff_shape' => $shape,
            'validation_commands' => $validationCommands,
            'evidence_obligations' => $evidenceObligations,
            'provider_fit' => $providerFit,
            'max_runtime_seconds' => self::DEFAULT_MAX_RUNTIME_SECONDS,
            'retry_policy' => [
                'max_retries' => 1,
                'transient_blockers' => ['provider_timeout', 'owner_runtime_routing_not_executable'],
                'permanent_blockers' => ['provider_scope_violation', 'validation_failed'],
            ],
            'merge_policy' => $mergePolicy,
            'success_condition' => $this->successCondition($sliceTests, $shape, $allowedFiles),
        ];

        return ['ok' => true, 'slice' => $slice, 'blockers' => []];
    }

    // ---------- normalization ----------

    /**
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function normalizeFinding(array $finding, array $context): array
    {
        $title = trim((string) ($finding['title'] ?? ''));
        $detail = trim((string) ($finding['detail'] ?? ''));
        $why = trim((string) ($finding['why_it_matters'] ?? ''));
        $nextAction = trim((string) ($finding['proposed_next_action'] ?? ''));

        $pool = $this->filePool($finding, $context);
        $sourceFiles = array_values(array_filter($pool, fn (string $f): bool => $this->isSourceFile($f)));
        $testFiles = array_values(array_filter($pool, fn (string $f): bool => $this->isTestFile($f)));
        $docFiles = array_values(array_filter($pool, fn (string $f): bool => $this->isDocFile($f)));

        return [
            'finding_id' => trim((string) ($finding['finding_id'] ?? '')),
            'finding_hash' => trim((string) ($finding['finding_hash'] ?? '')),
            'title' => $title,
            'detail' => $detail,
            'why_it_matters' => $why,
            'proposed_next_action' => $nextAction,
            'kind' => strtolower(trim((string) ($finding['kind'] ?? ''))),
            'origin_type' => strtolower(trim((string) ($finding['origin_type'] ?? ''))),
            'severity' => strtolower(trim((string) ($finding['severity'] ?? 'medium'))),
            'owner_candidate' => strtolower(trim((string) ($finding['owner_candidate'] ?? data_get($finding, 'spec_seed.route_hint_owner', '')))),
            'factory_value_score' => $this->intOrNull($finding['priority_score'] ?? null),
            'evidence_refs' => $this->stringList($finding['evidence_refs'] ?? []),
            'tests_required' => $this->stringList(data_get($finding, 'spec_seed.tests_required', [])),
            'spec_candidate_id' => trim((string) data_get($finding, 'spec_seed.candidate_id', '')),
            'objective_text' => trim(implode(' ', array_filter([$title, $detail, $why, $nextAction]))),
            'source_files' => $sourceFiles,
            'test_files' => $testFiles,
            'doc_files' => $docFiles,
        ];
    }

    /**
     * The file pool prefers a caller-supplied allowed-file set (so AP-786 and
     * the planner agree on scope); otherwise it derives from the finding.
     *
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $context
     * @return list<string>
     */
    private function filePool(array $finding, array $context): array
    {
        $contextAllowed = $this->stringList($context['allowed_files'] ?? []);
        if ($contextAllowed !== []) {
            return $this->cleanFiles($contextAllowed);
        }

        $affectedFiles = $this->stringList($finding['affected_files'] ?? []);
        $files = array_merge(
            $affectedFiles,
            $this->stringList($finding['affected_docs'] ?? []),
            $this->stringList(data_get($finding, 'spec_seed.tests_required', [])),
        );

        foreach ($this->stringList($finding['evidence_refs'] ?? []) as $ref) {
            if (str_starts_with($ref, 'expected_test:')) {
                $basename = trim(substr($ref, strlen('expected_test:')));
                $testPath = $this->expectedTestPath($basename, $affectedFiles);
                if ($testPath !== '') {
                    $files[] = $testPath;
                }
            }
            if (str_starts_with($ref, 'impl:')) {
                $files[] = trim(substr($ref, strlen('impl:')));
            }
        }

        return $this->cleanFiles($files);
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function cleanFiles(array $files): array
    {
        $clean = [];
        foreach ($files as $file) {
            $normalized = $this->normalizePath($file);
            if ($normalized === '' || $this->isForbidden($normalized) || $this->isBroadPath($normalized)) {
                continue;
            }
            $clean[] = $normalized;
        }
        sort($clean);

        return array_values(array_unique($clean));
    }

    // ---------- slice helpers ----------

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function boundedAllowedFiles(array $files): array
    {
        $bounded = [];
        foreach ($files as $file) {
            if ($this->isBroadPath($file) || $this->isForbidden($file)) {
                continue;
            }
            $bounded[] = $file;
        }
        $bounded = array_values(array_unique($bounded));
        if (count($bounded) > self::MAX_FILES_PER_SLICE) {
            return [];
        }

        return $bounded;
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  array<string,mixed>  $normalized
     * @return list<string>
     */
    private function sliceValidationTests(array $allowedFiles, array $normalized): array
    {
        $tests = [];
        foreach ($allowedFiles as $file) {
            if ($this->isTestFile($file)) {
                $tests[] = $file;
            }
        }
        foreach ($normalized['tests_required'] as $test) {
            $candidate = $this->normalizePath($test);
            if ($this->isTestFile($candidate)) {
                $tests[] = $candidate;
            }
        }
        // Derive a test for each source file in scope when none is explicit.
        if ($tests === []) {
            foreach ($allowedFiles as $file) {
                if ($this->isSourceFile($file)) {
                    $derived = $this->expectedTestPath($this->testBasenameFor($file), [$file]);
                    if ($derived !== '' && in_array($derived, $allowedFiles, true)) {
                        $tests[] = $derived;
                    }
                }
            }
        }

        return array_values(array_unique($tests));
    }

    /**
     * @param  list<string>  $tests
     * @param  array<string,mixed>  $context
     * @return list<string>
     */
    private function validationCommands(array $tests, bool $isDocs, array $context): array
    {
        $commands = [];
        foreach ($this->stringList($context['validation_commands'] ?? []) as $command) {
            $commands[] = $command;
        }
        foreach ($tests as $test) {
            $commands[] = 'php artisan test '.$test;
        }
        if ($isDocs) {
            $commands[] = 'php artisan atlas:engineering:knowledge docs-health --json';
        }
        $commands[] = 'git diff --check';

        return array_values(array_slice(array_unique($commands), 0, 5));
    }

    /**
     * A code/service/test slice needs a real focused test; `git diff --check`
     * alone is never sufficient. A docs slice is satisfied by a docs-health run.
     *
     * @param  list<string>  $commands
     */
    private function hasFocusedValidation(array $commands, bool $isDocs): bool
    {
        foreach ($commands as $command) {
            if (str_starts_with($command, 'php artisan test ')) {
                return true;
            }
            if ($isDocs && str_contains($command, 'docs-health')) {
                return true;
            }
        }

        return false;
    }

    private function resolveOwner(string $ownerCandidate, bool $isDocs): string
    {
        if ($isDocs) {
            return self::OWNER_STEWARDSHIP;
        }

        return match ($ownerCandidate) {
            'forge' => self::OWNER_FORGE,
            'atlas_dev', 'aaeos', 'evidence', 'product_mode' => self::OWNER_ATLAS_DEV,
            'self_directed_evolution' => self::OWNER_STEWARDSHIP,
            'memory' => self::OWNER_MEMORY,
            default => '',
        };
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function ownerRuntimeReady(string $owner, array $context): bool
    {
        if ($owner !== self::OWNER_FORGE) {
            return true;
        }

        return $this->hasForgeAuthority($context);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function providerFit(string $owner): ?array
    {
        return match ($owner) {
            self::OWNER_ATLAS_DEV => [
                'owner_runtime' => self::OWNER_ATLAS_DEV,
                'provider_role' => 'implementer',
                'preferred_provider' => 'cursor_cli',
                'preferred_model_family' => 'composer-2.5-fast',
                'source' => 'atlas_decide',
            ],
            self::OWNER_FORGE => [
                'owner_runtime' => self::OWNER_FORGE,
                'provider_role' => 'forge_builder',
                'dispatch' => 'forge_runtime_dispatch',
                'source' => 'atlas_decide',
            ],
            self::OWNER_STEWARDSHIP => [
                'owner_runtime' => self::OWNER_STEWARDSHIP,
                'provider_role' => 'doc_steward',
                'preferred_provider' => 'cursor_cli',
                'preferred_model_family' => 'composer-2.5-fast',
                'source' => 'atlas_decide',
            ],
            self::OWNER_MEMORY => [
                'owner_runtime' => self::OWNER_MEMORY,
                'provider_role' => 'memory_curator',
                'source' => 'atlas_decide',
            ],
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $normalized
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $tests
     * @return list<string>
     */
    private function evidenceObligations(array $normalized, array $allowedFiles, array $tests, bool $isDocs): array
    {
        // Completion cannot be certified without a stable finding identity that
        // ties the slice result back to the source finding.
        if ($normalized['finding_id'] === '' && $normalized['finding_hash'] === '') {
            return [];
        }

        $obligations = [];
        if ($normalized['finding_id'] !== '') {
            $obligations[] = 'finding_id:'.$normalized['finding_id'];
        } else {
            $obligations[] = 'finding_hash:'.$normalized['finding_hash'];
        }
        if ($normalized['spec_candidate_id'] !== '') {
            $obligations[] = 'spec_seed:'.$normalized['spec_candidate_id'];
        }
        foreach ($tests as $test) {
            $obligations[] = 'test_pass:'.$test;
        }
        if ($isDocs) {
            $obligations[] = 'docs_health_pass';
        }
        $obligations[] = 'git_diff_within_allowed_files';
        $obligations[] = 'inbox_item_emitted_before_merge';
        $obligations[] = 'decision_receipt_recorded';

        return array_values(array_unique($obligations));
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function expectedDiffShape(array $allowedFiles, bool $isDocs): string
    {
        $hasSource = false;
        $hasTest = false;
        $hasDoc = false;
        foreach ($allowedFiles as $file) {
            if ($this->isTestFile($file)) {
                $hasTest = true;
            } elseif ($this->isDocFile($file)) {
                $hasDoc = true;
            } elseif ($this->isSourceFile($file)) {
                $hasSource = true;
            }
        }

        if ($isDocs || ($hasDoc && ! $hasSource && ! $hasTest)) {
            return self::SHAPE_DOCS_ONLY;
        }
        if ($hasDoc && $hasTest && ! $hasSource) {
            return self::SHAPE_DOCS_AND_TEST;
        }
        if ($hasSource && $hasTest) {
            return self::SHAPE_SERVICE_AND_TEST;
        }
        if ($hasTest && ! $hasSource) {
            return self::SHAPE_TEST_ONLY;
        }

        return self::SHAPE_SERVICE_ONLY;
    }

    private function riskLevel(string $severity): string
    {
        return match ($severity) {
            'critical' => 'critical',
            'high' => 'high',
            'low' => 'low',
            default => 'medium',
        };
    }

    private function mergePolicy(string $owner, string $shape, string $riskLevel): string
    {
        if ($owner === self::OWNER_FORGE || $riskLevel === 'critical') {
            return self::MERGE_NEVER_AUTO;
        }
        if ($shape === self::SHAPE_TEST_ONLY) {
            return self::MERGE_AUTO_ELIGIBLE;
        }

        return self::MERGE_REVIEW_REQUIRED;
    }

    /**
     * @param  array<string,mixed>  $normalized
     * @param  list<string>  $allowedFiles
     */
    private function sliceObjective(array $normalized, array $allowedFiles, bool $isDocs): string
    {
        $target = $allowedFiles[0] ?? 'the selected scope';
        $title = $normalized['title'] !== '' ? $normalized['title'] : 'the selected finding';
        if ($isDocs) {
            return sprintf('Correct canonical docs for "%s", scoped to %s, to unblock runtime/certification.', $title, $target);
        }

        return sprintf('Implement the bounded slice of "%s" by changing %s within allowed_files.', $title, $target);
    }

    /**
     * @param  list<string>  $tests
     * @param  list<string>  $allowedFiles
     */
    private function successCondition(array $tests, string $shape, array $allowedFiles): string
    {
        if ($tests !== []) {
            return sprintf('Diff stays within allowed_files (%d) and `php artisan test %s` passes.', count($allowedFiles), $tests[0]);
        }
        if ($shape === self::SHAPE_DOCS_ONLY) {
            return sprintf('Diff stays within allowed_files (%d) and docs-health passes.', count($allowedFiles));
        }

        return sprintf('Diff stays within allowed_files (%d) and focused validation passes.', count($allowedFiles));
    }

    // ---------- predicates ----------

    private function isGenericObjective(string $objective): bool
    {
        $needle = strtolower($objective);
        if (trim($needle) === '') {
            return true;
        }
        foreach (self::GENERIC_OBJECTIVE_PATTERNS as $pattern) {
            if (str_contains($needle, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $normalized
     */
    private function unblocksRuntimeOrCertification(array $normalized): bool
    {
        $text = strtolower(implode(' ', [
            $normalized['objective_text'],
            implode(' ', $normalized['evidence_refs']),
        ]));
        foreach (self::DOCS_RUNTIME_UNLOCK_PATTERNS as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function hasForgeAuthority(array $context): bool
    {
        $forge = $context['forge_authority'] ?? null;
        if (is_bool($forge)) {
            return $forge;
        }
        if (is_array($forge)) {
            return (bool) ($forge['ready'] ?? false)
                || (string) ($forge['status'] ?? '') === 'ready'
                || (string) ($forge['status'] ?? '') === 'live';
        }

        return false;
    }

    private function isSourceFile(string $file): bool
    {
        if ($this->isTestFile($file) || $this->isDocFile($file)) {
            return false;
        }

        return str_starts_with($file, 'app/')
            || str_starts_with($file, 'routes/')
            || str_starts_with($file, 'config/')
            || str_starts_with($file, 'database/')
            || str_starts_with($file, 'resources/')
            || str_starts_with($file, 'bootstrap/');
    }

    private function isTestFile(string $file): bool
    {
        return str_starts_with($file, 'tests/') || str_ends_with($file, 'Test.php');
    }

    private function isDocFile(string $file): bool
    {
        return str_starts_with($file, 'docs/') || str_ends_with($file, '.md');
    }

    /**
     * A path is "broad" (unsafe for autonomous scope) when it is a glob, a
     * directory, or has no file extension.
     */
    private function isBroadPath(string $file): bool
    {
        if ($file === '' || str_contains($file, '*') || str_contains($file, '?')) {
            return true;
        }
        if (str_ends_with($file, '/')) {
            return true;
        }

        return ! str_contains(basename($file), '.');
    }

    private function isForbidden(string $path): bool
    {
        foreach (self::FORBIDDEN_FILES as $forbidden) {
            if (str_starts_with($path, rtrim($forbidden, '/'))) {
                return true;
            }
        }

        return false;
    }

    // ---------- path derivation ----------

    /**
     * @param  list<string>  $tests
     */
    private function matchingTest(string $source, array $tests): string
    {
        $expected = $this->expectedTestPath($this->testBasenameFor($source), [$source]);
        foreach ($tests as $test) {
            if ($test === $expected) {
                return $test;
            }
        }
        $base = $this->testBasenameFor($source);
        foreach ($tests as $test) {
            if (basename($test) === $base) {
                return $test;
            }
        }

        return '';
    }

    private function testBasenameFor(string $source): string
    {
        $name = basename($source);
        if (str_ends_with($name, '.php')) {
            return substr($name, 0, -4).'Test.php';
        }

        return $name.'Test.php';
    }

    /**
     * Mirror AP-786 expected-test path derivation so planner slices align with
     * the owner-runtime scope.
     *
     * @param  list<string>  $affectedFiles
     */
    private function expectedTestPath(string $basename, array $affectedFiles): string
    {
        $basename = trim($basename);
        if ($basename === '') {
            return '';
        }
        $source = $affectedFiles[0] ?? '';
        if (str_starts_with($source, 'app/Services/Ai/NightShift/')) {
            return 'tests/Unit/Ai/NightShift/'.$basename;
        }
        if (str_starts_with($source, 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/')) {
            return 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/'.$basename;
        }
        if (str_starts_with($source, 'app/Services/Ai/')) {
            $tail = substr($source, strlen('app/Services/Ai/'));
            $dir = trim(dirname($tail), '.');

            return 'tests/Unit/Ai/'.($dir !== '' ? $dir.'/' : '').$basename;
        }

        return 'tests/Unit/'.$basename;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('/\s+/', '', $path) ?? $path;

        return ltrim($path, '/');
    }

    // ---------- assembly ----------

    /**
     * @param  list<string>  $blockers
     * @return array{status:string,blockers:list<string>,slices:list<array<string,mixed>>}
     */
    private function blocked(array $blockers): array
    {
        $unique = array_values(array_unique($blockers));
        $status = $unique === [self::BLOCKER_OPERATOR_OR_ARCHITECT_SPEC_REQUIRED]
            ? self::STATUS_OPERATOR_REVIEW
            : self::STATUS_BLOCKED;

        return ['status' => $status, 'blockers' => $unique, 'slices' => []];
    }

    /**
     * @param  array<string,mixed>  $normalized
     * @param  array{status:string,blockers:list<string>,slices:list<array<string,mixed>>}  $decision
     * @param  list<string>  $sourceReceipts
     * @return array<string,mixed>
     */
    private function finalizePlan(array $normalized, string $scopeProfile, string $mode, array $decision, array $sourceReceipts): array
    {
        $plan = [
            'schema_version' => self::PLAN_SCHEMA,
            'finding_id' => $normalized['finding_id'],
            'finding_summary' => $normalized['title'] !== '' ? $normalized['title'] : $normalized['detail'],
            'finding_kind' => $normalized['kind'] !== '' ? $normalized['kind'] : ($normalized['origin_type'] !== '' ? $normalized['origin_type'] : 'unknown'),
            'factory_value_score' => $normalized['factory_value_score'],
            'scope_profile' => $scopeProfile,
            'mode' => $mode,
            'decomposition_status' => $decision['status'],
            'blockers' => $decision['blockers'],
            'slices' => $decision['slices'],
            'source_receipts' => $sourceReceipts,
        ];
        $plan['plan_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->hashablePlan($plan));

        return $plan;
    }

    /**
     * Canonical, order-stable projection used for the deterministic plan_hash.
     *
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function hashablePlan(array $plan): array
    {
        $slices = [];
        foreach ($plan['slices'] as $slice) {
            $slices[] = [
                'slice_id' => $slice['slice_id'],
                'sequence' => $slice['sequence'],
                'owner' => $slice['owner'],
                'allowed_files' => $slice['allowed_files'],
                'expected_diff_shape' => $slice['expected_diff_shape'],
                'validation_commands' => $slice['validation_commands'],
                'merge_policy' => $slice['merge_policy'],
            ];
        }

        return [
            'finding_id' => $plan['finding_id'],
            'finding_kind' => $plan['finding_kind'],
            'scope_profile' => $plan['scope_profile'],
            'decomposition_status' => $plan['decomposition_status'],
            'blockers' => $plan['blockers'],
            'slices' => $slices,
        ];
    }

    /**
     * @param  array<string,mixed>  $normalized
     * @param  array<string,mixed>  $context
     * @return list<string>
     */
    private function sourceReceipts(array $normalized, array $context): array
    {
        $receipts = [];
        if ($normalized['finding_hash'] !== '') {
            $receipts[] = 'finding:'.$normalized['finding_hash'];
        } elseif ($normalized['finding_id'] !== '') {
            $receipts[] = 'finding:'.$normalized['finding_id'];
        }
        if ($normalized['spec_candidate_id'] !== '') {
            $receipts[] = 'spec_seed:'.$normalized['spec_candidate_id'];
        }
        foreach ($this->stringList($context['source_receipts'] ?? []) as $receipt) {
            $receipts[] = $receipt;
        }

        return array_values(array_unique($receipts));
    }

    private function sliceId(string $findingHash, int $sequence, array $allowedFiles): string
    {
        $seed = $findingHash !== '' ? $findingHash : 'no_finding_hash';

        return 'slice_'.substr(MissionCanonicalHash::sha256([$seed, $sequence, $allowedFiles]), 0, 16);
    }

    // ---------- input normalization ----------

    private function mode(string $value): string
    {
        return strtolower(trim($value)) === self::MODE_RECORD ? self::MODE_RECORD : self::MODE_DRY_RUN;
    }

    private function scopeProfile(string $value): string
    {
        return strtolower(trim($value)) === self::SCOPE_FACTORY_MAX ? self::SCOPE_FACTORY_MAX : self::SCOPE_BALANCED;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
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
