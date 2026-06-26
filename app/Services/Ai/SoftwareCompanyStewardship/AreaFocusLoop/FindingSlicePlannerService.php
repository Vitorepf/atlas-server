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
    private ?FindingSliceBuilder $sliceBuilderInstance = null;

    private ?FindingSemanticStepGrouper $semanticStepGrouperInstance = null;

    public const PLAN_SCHEMA = 'atlas.stewardship.finding_slice_plan.v1';

    public const SLICE_SCHEMA = 'atlas.stewardship.executable_slice.v1';

    public const STATUS_SLICED = 'sliced';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_OPERATOR_REVIEW = 'operator_review_required';

    public const SCOPE_BALANCED = AreaFocusScopeProfileNormalizer::BALANCED;

    public const SCOPE_FACTORY_MAX = AreaFocusScopeProfileNormalizer::FACTORY_MAX;

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
        $scopeProfile = AreaFocusScopeProfileNormalizer::normalize($input['scope_profile'] ?? self::SCOPE_BALANCED);
        $context = is_array($input['context'] ?? null) ? $input['context'] : [];

        $normalized = $this->normalizeFinding($finding, $context);
        $sourceReceipts = $this->sourceReceipts($normalized, $context);

        $decision = $this->decompose($normalized, $scopeProfile, $context);

        return $this->finalizePlan($normalized, $scopeProfile, $mode, $decision, $sourceReceipts);
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
        $contextAllowed = AreaFocusStringListNormalizer::trimmedStrings($context['allowed_files'] ?? []);
        if ($contextAllowed !== []) {
            return $this->cleanFiles($contextAllowed);
        }

        $affectedFiles = AreaFocusStringListNormalizer::trimmedStrings($finding['affected_files'] ?? []);
        $files = array_merge(
            $affectedFiles,
            AreaFocusStringListNormalizer::trimmedStrings($finding['affected_docs'] ?? []),
            AreaFocusStringListNormalizer::trimmedStrings(data_get($finding, 'spec_seed.tests_required', [])),
        );

        foreach (AreaFocusStringListNormalizer::trimmedStrings($finding['evidence_refs'] ?? []) as $ref) {
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
            $normalized = AreaFocusPathNormalizer::repoRelativeNoWhitespace($file);
            if ($normalized === '' || $this->isForbidden($normalized) || $this->isBroadPath($normalized)) {
                continue;
            }
            $clean[] = $normalized;
        }
        sort($clean);

        return AreaFocusStringListNormalizer::uniqueStringValues($clean);
    }

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
            'evidence_refs' => AreaFocusStringListNormalizer::trimmedStrings($finding['evidence_refs'] ?? []),
            'tests_required' => AreaFocusStringListNormalizer::trimmedStrings(data_get($finding, 'spec_seed.tests_required', [])),
            'spec_candidate_id' => trim((string) data_get($finding, 'spec_seed.candidate_id', '')),
            'objective_text' => trim(implode(' ', array_filter([$title, $detail, $why, $nextAction]))),
            'source_files' => $sourceFiles,
            'test_files' => $testFiles,
            'doc_files' => $docFiles,
        ];
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
        if ($this->isFactoryMaxRuntimeImprovement($normalized)) {
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
     * Factory-max seeds are already curated as concrete runtime/test targets.
     * Sending them through the generic "contract first" semantic roadmap has
     * repeatedly produced post-provider contract-only diffs. For the 24h loop,
     * that is low-quality provider spend; these seeds must execute directly
     * against the runtime service and its focused test.
     *
     * @param  array<string,mixed>  $normalized
     */
    private function isFactoryMaxRuntimeImprovement(array $normalized): bool
    {
        $ids = strtolower(implode(' ', array_filter([
            (string) ($normalized['finding_id'] ?? ''),
            (string) ($normalized['spec_candidate_id'] ?? ''),
            (string) ($normalized['origin_type'] ?? ''),
        ])));

        return str_contains($ids, 'factory_max_');
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
        return $this->semanticStepGrouper()->semanticStepGroups($normalized, $sourceFiles, $testFiles);
    }

    private function runtimeSemanticStepGroups(string $title, string $primary, string $capability, array $implementationFiles): array
    {
        return $this->semanticStepGrouper()->runtimeSemanticStepGroups($title, $primary, $capability, $implementationFiles);
    }

    private function runtimeTargetMethod(string $stepKind, string $capability): string
    {
        return $this->semanticStepGrouper()->runtimeTargetMethod($stepKind, $capability);
    }

    private function runtimeSurgicalAnchor(string $primary, string $stepKind, string $capability): string
    {
        return $this->semanticStepGrouper()->runtimeSurgicalAnchor($primary, $stepKind, $capability);
    }

    private function semanticContractFiles(string $primary, string $capability): array
    {
        return $this->semanticStepGrouper()->semanticContractFiles($primary, $capability);
    }

    private function semanticContractClassName(string $capability): string
    {
        return $this->semanticStepGrouper()->semanticContractClassName($capability);
    }

    private function capabilityPhrase(string $title): string
    {
        return $this->semanticStepGrouper()->capabilityPhrase($title);
    }

    private function targetGroups(array $sourceFiles, array $testFiles): array
    {
        return $this->semanticStepGrouper()->targetGroups($sourceFiles, $testFiles);
    }

    private function docsTargetGroup(array $docFiles): array
    {
        return $this->semanticStepGrouper()->docsTargetGroup($docFiles);
    }

    private function semanticStepGrouper(): FindingSemanticStepGrouper
    {
        return $this->semanticStepGrouperInstance ??= new FindingSemanticStepGrouper(
            fn (array $normalized): bool => $this->isCanonicalAaeosRuntimeGap($normalized),
            fn (string $source, array $testFiles): string => $this->matchingTest($source, $testFiles),
            fn (string $basename, array $affectedFiles): string => $this->expectedTestPath($basename, $affectedFiles),
            fn (string $path): bool => $this->isForbidden($path),
            fn (string $file): bool => $this->isBroadPath($file),
            fn (string $file): bool => $this->isContractLikeSupportFile($file),
        );
    }

    /**
     * Canonical AAEOS backlog items are already anchored to an implementation
     * service and its focused test. Their bounded packets must harden that
     * runtime surface directly; a newly invented *Contract.php file is too easy
     * for providers to satisfy as inert scaffold and too weak for factory_max.
     *
     * @param  array<string,mixed>  $normalized
     */
    private function isCanonicalAaeosRuntimeGap(array $normalized): bool
    {
        $identity = strtolower(implode(' ', array_filter([
            (string) ($normalized['finding_id'] ?? ''),
            (string) ($normalized['spec_candidate_id'] ?? ''),
            (string) ($normalized['origin_type'] ?? ''),
        ])));

        return str_contains($identity, 'canonical_aaeos_')
            || str_contains($identity, 'runtime_gap');
    }


    private function isContractLikeSupportFile(string $file): bool
    {
        if (! $this->isSourceFile($file)) {
            return false;
        }

        return (bool) preg_match('/(Contract|Slice|Packet|Plan|Spec|Schema)\.php$/', basename($file));
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
        return $this->sliceBuilder()->buildSlices($groups, $normalized, $context, $docsOnlyFinding);
    }

    private function buildSlice(array $group, array $normalized, array $context, int $sequence, bool $docsOnlyFinding): array
    {
        return $this->sliceBuilder()->buildSlice($group, $normalized, $context, $sequence, $docsOnlyFinding);
    }

    private function sliceObjective(array $normalized, array $allowedFiles, bool $isDocs): string
    {
        return $this->sliceBuilder()->sliceObjective($normalized, $allowedFiles, $isDocs);
    }

    private function successCondition(array $tests, string $shape, array $allowedFiles): string
    {
        return $this->sliceBuilder()->successCondition($tests, $shape, $allowedFiles);
    }

    private function evidenceObligations(array $normalized, array $allowedFiles, array $tests, bool $isDocs): array
    {
        return $this->sliceBuilder()->evidenceObligations($normalized, $allowedFiles, $tests, $isDocs);
    }

    private function expectedDiffShape(array $allowedFiles, bool $isDocs): string
    {
        return $this->sliceBuilder()->expectedDiffShape($allowedFiles, $isDocs);
    }

    private function boundedAllowedFiles(array $files): array
    {
        return $this->sliceBuilder()->boundedAllowedFiles($files);
    }

    private function sliceValidationTests(array $allowedFiles, array $normalized): array
    {
        return $this->sliceBuilder()->sliceValidationTests($allowedFiles, $normalized);
    }

    private function validationCommands(array $tests, bool $isDocs, array $context): array
    {
        return $this->sliceBuilder()->validationCommands($tests, $isDocs, $context);
    }

    private function worktreeSafeValidationCommand(string $command): string
    {
        return $this->sliceBuilder()->worktreeSafeValidationCommand($command);
    }

    private function phpunitValidationCommand(string $test): string
    {
        return $this->sliceBuilder()->phpunitValidationCommand($test);
    }

    private function sliceBuilder(): FindingSliceBuilder
    {
        return $this->sliceBuilderInstance ??= new FindingSliceBuilder(
            self::FORBIDDEN_FILES,
            fn (array $commands, bool $isDocs): bool => $this->hasFocusedValidation($commands, $isDocs),
            fn (string $severity): string => $this->riskLevel($severity),
            fn (string $owner, string $shape, string $riskLevel): string => $this->mergePolicy($owner, $shape, $riskLevel),
            fn (string $ownerCandidate, bool $isDocs): string => $this->resolveOwner($ownerCandidate, $isDocs),
            fn (string $owner, array $context): bool => $this->ownerRuntimeReady($owner, $context),
            fn (string $owner): ?array => $this->providerFit($owner),
            fn (string $findingHash, int $sequence, array $allowedFiles): string => $this->sliceId($findingHash, $sequence, $allowedFiles),
            fn (array $blockers): array => $this->blocked($blockers),
            fn (string $file): bool => $this->isSourceFile($file),
            fn (string $file): bool => $this->isTestFile($file),
            fn (string $file): bool => $this->isDocFile($file),
            fn (string $file): bool => $this->isBroadPath($file),
            fn (string $path): bool => $this->isForbidden($path),
            fn (string $source): string => $this->testBasenameFor($source),
            fn (string $basename, array $affectedFiles): string => $this->expectedTestPath($basename, $affectedFiles),
        );
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
            if (str_starts_with($command, './vendor/bin/phpunit --configuration=phpunit.xml ')) {
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

    // ---------- assembly ----------

    /**
     * @param  list<string>  $blockers
     * @return array{status:string,blockers:list<string>,slices:list<array<string,mixed>>}
     */
    private function blocked(array $blockers): array
    {
        $unique = AreaFocusStringListNormalizer::uniqueStringValues($blockers);
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
        foreach (AreaFocusStringListNormalizer::trimmedStrings($context['source_receipts'] ?? []) as $receipt) {
            $receipts[] = $receipt;
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($receipts);
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
}
