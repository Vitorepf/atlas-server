<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DeepFinding;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusPathNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusScalarNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;

/**
 * Factory backlog quality section (AP-790 / factory_max) — extracted verbatim
 * from AreaFocusDeepFindingEngineService by the GOD-DEBULK split. It filters
 * low-ROI findings and enriches the survivors for factory_max autonomous
 * execution: leverage/readiness/risk scoring, interface-only false-positive
 * suppression, allowed-files/tests-required resolution and factory acceptance.
 *
 * Read-only: like the façade it NEVER writes code/docs and only classifies
 * already-built findings. Shared taxonomy constants live on the façade (public);
 * the three FACTORY_* constants below are owned by this section. Shared leaf
 * helpers (isCodePath/pathExists) come from DeepFindingSupport.
 */
class FactoryBacklogQualitySection
{
    public function __construct(
        private readonly DeepFindingSupport $deepFindingSupport,
    ) {}

    /** @var list<string> */
    private const FACTORY_REJECTED_ORIGIN_TYPES = [
        'docs_stale',
        'focus_owner_doc_missing',
        'missing_evidence',
        // Canonical Doc Backlog Miner findings are a READ-ONLY finding source:
        // they MUST stay operator-review-required and never auto-execute, so the
        // factory backlog quality gate always rejects them for auto-execution.
        AreaFocusDeepFindingEngineService::ORIGIN_TYPE_DOC_NEXT_ACTION,
        AreaFocusDeepFindingEngineService::ORIGIN_TYPE_DOC_ALLOWED_CHANGE,
    ];

    /** @var list<string> */
    private const FACTORY_RUNTIME_PREFIXES = [
        'app/Services/Ai/AgenticEngineeringOs/',
        'app/Services/Ai/AtlasDecide/',
        'app/Services/Ai/AgenticWorkcell/',
        'app/Services/Ai/AtlasForge/',
        'app/Services/Ai/Cartography/',
        'app/Services/Ai/Cognition/',
        'app/Services/Ai/Compounding/',
        'app/Services/Ai/Context/',
        'app/Services/Ai/LongHorizon/',
        'app/Services/Ai/Programming/',
        'app/Services/Ai/ProgrammingRuntime/',
        'app/Services/Ai/Product/',
        'app/Services/Ai/Provider/',
        'app/Services/Ai/Reality/',
        'app/Services/Ai/RealitySandbox/',
        'app/Services/Ai/StrategicReality/',
        'app/Services/Ai/VerifiedExecution/',
        'app/Services/Ai/VerifiedContextExecution/',
        'app/Services/Ai/Kernel/',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/',
        'app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/',
    ];

    /** @var list<string> */
    private const FACTORY_LEVERAGE_TERMS = [
        'ap786', 'ap790', 'autonomous', 'evolution', 'sandbox', 'materializer',
        'merge', 'governor', 'owner_runtime', 'senior_loop', 'provider', 'cursor',
        'dev_forge', 'priority', 'deep_finding', 'reliable24h', 'inbox', 'read_model',
        'evidence', 'worktree', 'stewardship', 'atlas_dev', 'forge', 'handoff',
        'decide', 'topology', 'quality_bar', 'cross_department', 'choreography',
        'mission_control', 'learning', 'compounding', 'self_construction', 'replay',
        'universal_gates', 'architect',
    ];

    /**
     * Filter low-ROI findings and enrich survivors for factory_max execution.
     *
     * @param  list<array<string,mixed>>  $findings
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    public function applyFactoryBacklogQuality(array $findings, bool $autonomousDocExec = false): array
    {
        $accepted = [];
        $rejections = [];
        foreach ($findings as $finding) {
            $assessment = $this->assessFactoryCandidate($finding, $autonomousDocExec);
            if (($assessment['rejection_reason'] ?? '') !== '') {
                $rejections[] = [
                    'finding_id' => (string) ($finding['finding_id'] ?? ''),
                    'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
                    'title' => (string) ($finding['title'] ?? ''),
                    'rejection_reason' => (string) $assessment['rejection_reason'],
                    'roi_score' => (int) ($assessment['roi_score'] ?? 0),
                    'execution_readiness_score' => (int) ($assessment['execution_readiness_score'] ?? 0),
                    'factory_leverage_score' => (int) ($assessment['factory_leverage_score'] ?? 0),
                    'risk_penalty' => (int) ($assessment['risk_penalty'] ?? 0),
                ];

                continue;
            }
            $accepted[] = $this->enrichFactoryExecutableFinding($finding, $assessment);
        }

        return [$accepted, $rejections];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function assessFactoryCandidate(array $finding, bool $autonomousDocExec = false): array
    {
        $originType = strtolower((string) ($finding['origin_type'] ?? ''));
        $kind = strtolower((string) ($finding['kind'] ?? ''));
        $allowedFiles = $this->resolveAllowedFilesForFinding($finding);
        $testsRequired = $this->resolveTestsRequiredForFinding($finding, $allowedFiles);
        $leverage = $this->factoryLeverageScore($finding, $allowedFiles);
        $readiness = $this->executionReadinessScore($finding, $allowedFiles, $testsRequired);
        $riskPenalty = $this->factoryRiskPenalty($finding, $allowedFiles);
        $roi = $this->clampScore((int) round(($leverage * 0.45) + ($readiness * 0.45) - ($riskPenalty * 0.35)));

        // POINT 3: when the operator authorized autonomous doc-backlog execution, a doc-mined
        // finding that resolved REAL code allowed_files (not docs-only) is legitimate factory
        // work — it must NOT be auto-rejected just for its doc origin_type. The remaining
        // quality gates below (docs-only paths, no-verifiable-test, missing runtime source,
        // factory-runtime-touch) STILL run, so quality is never weakened — only the blanket
        // origin veto is lifted for authorized, code-scoped directives.
        $authorizedDocCode = $autonomousDocExec
            && in_array($originType, [AreaFocusDeepFindingEngineService::ORIGIN_TYPE_DOC_NEXT_ACTION, AreaFocusDeepFindingEngineService::ORIGIN_TYPE_DOC_ALLOWED_CHANGE], true)
            && $kind !== AreaFocusDeepFindingEngineService::KIND_DOC
            && ! $this->allDocsOnlyPaths($allowedFiles)
            && $allowedFiles !== [];

        $rejection = '';
        if (! $authorizedDocCode && (in_array($originType, self::FACTORY_REJECTED_ORIGIN_TYPES, true) || $kind === AreaFocusDeepFindingEngineService::KIND_DOC)) {
            $rejection = 'factory_backlog_rejects_docs_or_low_leverage_evidence';
        } elseif ($this->allDocsOnlyPaths($allowedFiles)) {
            $rejection = 'factory_backlog_rejects_docs_only';
        } elseif ($this->isInterfaceOnlyFalsePositive($finding, $allowedFiles)) {
            $rejection = 'factory_backlog_rejects_interface_only_false_positive';
        } elseif ($originType === 'missing_test' && $this->isAlreadyCoveredByTest($finding, $testsRequired)) {
            $rejection = 'factory_backlog_rejects_already_covered_by_test';
        } elseif ($testsRequired === [] && ! in_array($originType, ['handoff_executor_wiring_gap', 'provider_routing_risk', 'execution_bottleneck'], true)) {
            $rejection = 'factory_backlog_rejects_no_verifiable_test';
        } elseif (! $this->hasExistingRuntimeSource($finding)) {
            $rejection = 'factory_backlog_rejects_missing_runtime_source';
        } elseif (! $this->touchesFactoryRuntime($allowedFiles)) {
            $rejection = 'factory_backlog_requires_factory_runtime_or_test_impact';
        }

        return [
            'rejection_reason' => $rejection,
            'roi_score' => $roi,
            'execution_readiness_score' => $readiness,
            'factory_leverage_score' => $leverage,
            'risk_penalty' => $riskPenalty,
            'allowed_files' => $allowedFiles,
            'tests_required' => $testsRequired,
        ];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $assessment
     * @return array<string,mixed>
     */
    private function enrichFactoryExecutableFinding(array $finding, array $assessment): array
    {
        $allowedFiles = (array) ($assessment['allowed_files'] ?? []);
        $testsRequired = (array) ($assessment['tests_required'] ?? []);
        $owner = $this->normalizeFactoryOwner($finding, $allowedFiles);
        $severity = $this->normalizeFactorySeverity($finding, $owner);
        $title = trim((string) ($finding['title'] ?? ''));

        $finding['owner_candidate'] = $owner;
        $finding['severity'] = $severity;
        $finding['allowed_files'] = $allowedFiles;
        $finding['tests_required'] = $testsRequired;
        $finding['factory_execution_ready'] = true;
        $finding['auto_execution_allowed'] = true;
        $finding['operator_review_required'] = false;
        $finding['autonomous_execution_reason'] = 'factory_backlog_quality_accepted';
        $finding['roi_score'] = (int) ($assessment['roi_score'] ?? 0);
        $finding['execution_readiness_score'] = (int) ($assessment['execution_readiness_score'] ?? 0);
        $finding['factory_leverage_score'] = (int) ($assessment['factory_leverage_score'] ?? 0);
        $finding['risk_penalty'] = (int) ($assessment['risk_penalty'] ?? 0);
        $finding['rejection_reason'] = '';
        $finding['factory_priority_score'] = (int) ($finding['roi_score'] ?? 0) * 10
            + (AreaFocusDeepFindingEngineService::SEVERITY_RANK[$severity] ?? 0) * 5
            + (($finding['in_focus'] ?? false) ? 40 : 0);
        if ((string) ($finding['origin'] ?? '') === 'strategic_multiplier_backlog') {
            $order = max(1, (int) ($finding['multiplier_order'] ?? 999));
            $finding['factory_priority_score'] = 20000 - ($order * 100) + (int) ($finding['roi_score'] ?? 0);
        }
        if (($finding['terminal_backlog_replenishment'] ?? false) === true) {
            $finding['factory_priority_score'] = (int) ($finding['factory_priority_score'] ?? 0) + 5000;
        }
        $finding['acceptance'] = $this->factoryAcceptance($title, $allowedFiles, $testsRequired);
        $finding['proposed_next_action'] = $this->factoryPatchNextAction($title, $allowedFiles, $testsRequired);

        $specSeed = is_array($finding['spec_seed'] ?? null) ? $finding['spec_seed'] : [];
        $specSeed['tests_required'] = $testsRequired;
        $specSeed['acceptance'] = $finding['acceptance'];
        $specSeed['route_hint_owner'] = $owner;
        $specSeed['proposal_only'] = false;
        $specSeed['operator_review_required'] = false;
        $finding['spec_seed'] = $specSeed;

        return $finding;
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return list<string>
     */
    public function resolveAllowedFilesForFinding(array $finding): array
    {
        if (is_array($finding['allowed_files'] ?? null) && $finding['allowed_files'] !== []) {
            return AreaFocusStringListNormalizer::coercedStringValues($finding['allowed_files']);
        }

        $files = array_merge(
            AreaFocusStringListNormalizer::stringifiedNonEmptyValues($finding['affected_files'] ?? []),
            array_values(array_filter(AreaFocusStringListNormalizer::stringifiedNonEmptyValues($finding['affected_paths'] ?? []), $this->deepFindingSupport->isCodePath(...))),
        );
        foreach (AreaFocusStringListNormalizer::stringifiedNonEmptyValues($finding['evidence_refs'] ?? []) as $ref) {
            if (str_starts_with($ref, 'impl:')) {
                $files[] = substr($ref, 5);
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues(array_filter(
            $files,
            static fn (string $f): bool => $f !== '' && ! str_starts_with($f, 'docs/'),
        ));
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function resolveTestsRequiredForFinding(array $finding, array $allowedFiles): array
    {
        if (is_array($finding['tests_required'] ?? null) && $finding['tests_required'] !== []) {
            return AreaFocusStringListNormalizer::stringifiedNonEmptyValues($finding['tests_required']);
        }
        $fromSeed = AreaFocusStringListNormalizer::stringifiedNonEmptyValues(data_get($finding, 'spec_seed.tests_required', []));
        if ($fromSeed !== []) {
            return $fromSeed;
        }

        $tests = [];
        foreach (AreaFocusStringListNormalizer::stringifiedNonEmptyValues($finding['evidence_refs'] ?? []) as $ref) {
            if (! str_starts_with($ref, 'expected_test:')) {
                continue;
            }
            $basename = trim(substr($ref, strlen('expected_test:')));
            $path = $this->expectedTestPath($basename, $allowedFiles);
            if ($path !== '') {
                $tests[] = $path;
            }
        }
        if ($tests === [] && $allowedFiles !== []) {
            $class = basename($allowedFiles[0], '.php');
            if ($class !== '') {
                $path = $this->expectedTestPath($class.'Test.php', $allowedFiles);
                if ($path !== '') {
                    $tests[] = $path;
                }
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($tests);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     */
    private function factoryLeverageScore(array $finding, array $allowedFiles): int
    {
        $haystack = strtolower(implode(' ', [
            (string) ($finding['title'] ?? ''),
            (string) ($finding['detail'] ?? ''),
            (string) ($finding['origin_type'] ?? ''),
            implode(' ', $allowedFiles),
        ]));
        $score = 28;
        foreach (self::FACTORY_LEVERAGE_TERMS as $term) {
            if ($term !== '' && str_contains($haystack, $term)) {
                $score += 8;
            }
        }
        if ((string) ($finding['origin'] ?? '') === 'factory_max_seed') {
            $score += 24;
        }
        if ((string) ($finding['origin'] ?? '') === 'factory_runtime_coverage_sweep') {
            $score += 20;
        }
        if ((string) ($finding['origin'] ?? '') === 'atlas_dev_factory_runtime_bottleneck_scan') {
            $score += 22;
        }
        if (($finding['terminal_backlog_replenishment'] ?? false) === true) {
            $score += 30;
        }
        if (in_array((string) ($finding['origin_type'] ?? ''), ['missing_test', 'handoff_executor_wiring_gap', 'provider_routing_risk', 'execution_bottleneck'], true)) {
            $score += 16;
        }
        if ($this->touchesFactoryRuntime($allowedFiles)) {
            $score += 12;
        }

        return $this->clampScore($score);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $testsRequired
     */
    private function executionReadinessScore(array $finding, array $allowedFiles, array $testsRequired): int
    {
        $score = 10;
        if ($allowedFiles !== []) {
            $score += 28;
        }
        if ($testsRequired !== []) {
            $score += 28;
        }
        if ($this->hasExistingRuntimeSource($finding)) {
            $score += 22;
        }
        if (AreaFocusStringListNormalizer::stringifiedNonEmptyValues(data_get($finding, 'spec_seed.acceptance', [])) !== [] || is_array($finding['acceptance'] ?? null)) {
            $score += 12;
        }

        return $this->clampScore($score);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     */
    private function factoryRiskPenalty(array $finding, array $allowedFiles): int
    {
        $penalty = 0;
        $owner = (string) ($finding['owner_candidate'] ?? '');
        if ($owner === AreaFocusDeepFindingEngineService::OWNER_FORGE && in_array((string) ($finding['kind'] ?? ''), [AreaFocusDeepFindingEngineService::KIND_TEST, AreaFocusDeepFindingEngineService::KIND_BUG], true)) {
            $penalty += 18;
        }
        if (strtolower((string) ($finding['severity'] ?? '')) === 'critical' && (string) ($finding['kind'] ?? '') === AreaFocusDeepFindingEngineService::KIND_TEST) {
            $penalty += 12;
        }
        foreach ($allowedFiles as $file) {
            if (str_starts_with($file, 'routes/') || str_starts_with($file, 'config/')) {
                $penalty += 8;
            }
        }

        return $this->clampScore($penalty);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     */
    private function normalizeFactoryOwner(array $finding, array $allowedFiles): string
    {
        $owner = (string) ($finding['owner_candidate'] ?? AreaFocusDeepFindingEngineService::OWNER_ATLAS_DEV);
        $kind = (string) ($finding['kind'] ?? '');
        if ($owner === AreaFocusDeepFindingEngineService::OWNER_FORGE && in_array($kind, [AreaFocusDeepFindingEngineService::KIND_TEST, AreaFocusDeepFindingEngineService::KIND_BUG], true) && count($allowedFiles) <= 3) {
            return AreaFocusDeepFindingEngineService::OWNER_ATLAS_DEV;
        }
        if ($owner === AreaFocusDeepFindingEngineService::OWNER_SELF_DIRECTED_EVOLUTION && $this->touchesFactoryRuntime($allowedFiles)) {
            return AreaFocusDeepFindingEngineService::OWNER_ATLAS_DEV;
        }

        return $owner;
    }

    /**
     * @param  array<string,mixed>  $finding
     */
    private function normalizeFactorySeverity(array $finding, string $owner): string
    {
        $severity = AreaFocusScalarNormalizer::severityOrMedium((string) ($finding['severity'] ?? 'medium'));
        if ($owner === AreaFocusDeepFindingEngineService::OWNER_ATLAS_DEV && (string) ($finding['kind'] ?? '') === AreaFocusDeepFindingEngineService::KIND_TEST && ($severity === 'critical' || $severity === 'high')) {
            return 'medium';
        }

        return $severity;
    }

    /**
     * @param  array<string,mixed>  $finding
     */
    public function isInterfaceOnlyFalsePositive(array $finding, array $allowedFiles): bool
    {
        if (strtolower((string) ($finding['origin_type'] ?? '')) !== 'missing_test') {
            return false;
        }
        foreach ($allowedFiles as $file) {
            if (! str_starts_with($file, 'app/') || ! str_ends_with($file, '.php')) {
                continue;
            }
            if (! $this->isPhpInterfaceFile($file)) {
                continue;
            }
            if ($this->hasSiblingImplementationTestCoverage($file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $testsRequired
     */
    private function isAlreadyCoveredByTest(array $finding, array $testsRequired): bool
    {
        foreach ($testsRequired as $testPath) {
            if ($this->deepFindingSupport->pathExists($testPath)) {
                return true;
            }
        }
        if (strtolower((string) ($finding['origin_type'] ?? '')) !== 'missing_test') {
            return false;
        }
        foreach (AreaFocusStringListNormalizer::stringifiedNonEmptyValues($finding['affected_files'] ?? []) as $file) {
            $basename = basename($file, '.php').'Test.php';
            $expected = $this->expectedTestPath($basename, [$file]);
            if ($expected !== '' && $this->deepFindingSupport->pathExists($expected)) {
                return true;
            }
        }

        return false;
    }

    private function isPhpInterfaceFile(string $relativePath): bool
    {
        $absolute = AreaFocusPathNormalizer::absoluteFromBasePath($relativePath);
        if (! is_file($absolute)) {
            return false;
        }
        $head = (string) file_get_contents($absolute, false, null, 0, 4096);

        return preg_match('/\binterface\s+[A-Za-z_][A-Za-z0-9_]*/', $head) === 1
            && preg_match('/\bclass\s+[A-Za-z_][A-Za-z0-9_]*/', $head) !== 1;
    }

    private function hasSiblingImplementationTestCoverage(string $interfacePath): bool
    {
        if ($this->hasSameDirectoryImplementationTestCoverage($interfacePath)) {
            return true;
        }

        return $this->hasStewardshipImplementationTestCoverage(basename($interfacePath, '.php'));
    }

    private function hasSameDirectoryImplementationTestCoverage(string $interfacePath): bool
    {
        $dir = dirname($interfacePath);
        $testDir = $this->expectedTestPath('XTest.php', [$interfacePath]);
        $testDir = $testDir !== '' ? dirname($testDir) : '';
        if ($testDir === '' || ! is_dir(AreaFocusPathNormalizer::absoluteFromBasePath($testDir))) {
            return false;
        }
        foreach (scandir(AreaFocusPathNormalizer::absoluteFromBasePath($dir)) ?: [] as $entry) {
            if (! str_ends_with($entry, '.php') || $entry === basename($interfacePath)) {
                continue;
            }
            $candidate = $dir.'/'.$entry;
            if ($this->isPhpInterfaceFile($candidate)) {
                continue;
            }
            $class = basename($entry, '.php');
            if ($class === '' || str_contains(strtolower($class), 'interface')) {
                continue;
            }
            $testPath = $testDir.'/'.basename($candidate, '.php').'Test.php';
            if ($this->deepFindingSupport->pathExists($testPath)) {
                return true;
            }
        }

        return false;
    }

    private function hasStewardshipImplementationTestCoverage(string $interfaceShortName): bool
    {
        if ($interfaceShortName === '') {
            return false;
        }

        foreach ($this->stewardshipImplementationPathsForInterface($interfaceShortName) as $implementationPath) {
            $testPath = $this->expectedTestPath(basename($implementationPath, '.php').'Test.php', [$implementationPath]);
            if ($testPath !== '' && $this->deepFindingSupport->pathExists($testPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function stewardshipImplementationPathsForInterface(string $interfaceShortName): array
    {
        $root = AreaFocusPathNormalizer::absoluteFromBasePath(AreaFocusDeepFindingEngineService::STEWARDSHIP_ROOT);
        if (! is_dir($root)) {
            return [];
        }

        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        $base = rtrim((string) (function_exists('base_path') ? base_path() : getcwd()), '/').'/';
        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }
            $absolute = $fileInfo->getPathname();
            if (str_ends_with($absolute, '/'.$interfaceShortName.'.php')) {
                continue;
            }
            $head = (string) file_get_contents($absolute, false, null, 0, 8192);
            if (! $this->phpFileImplementsInterface($head, $interfaceShortName)) {
                continue;
            }
            $relative = ltrim(str_replace($base, '', $absolute), '/');
            if ($relative !== '') {
                $paths[] = $relative;
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($paths);
    }

    private function phpFileImplementsInterface(string $head, string $interfaceShortName): bool
    {
        if (! str_contains($head, 'implements')) {
            return false;
        }

        return preg_match('/\bimplements\s+[^;{]*(?:\\\\)?'.$interfaceShortName.'\b/', $head) === 1;
    }

    /** @param array<string,mixed> $finding */
    private function hasExistingRuntimeSource(array $finding): bool
    {
        foreach (AreaFocusStringListNormalizer::stringifiedNonEmptyValues($finding['affected_files'] ?? []) as $file) {
            if (str_starts_with($file, 'app/') && $this->deepFindingSupport->pathExists($file)) {
                return true;
            }
        }
        foreach ($this->resolveAllowedFilesForFinding($finding) as $file) {
            if (str_starts_with($file, 'app/') && $this->deepFindingSupport->pathExists($file)) {
                return true;
            }
        }

        return (string) ($finding['origin'] ?? '') === 'factory_max_seed';
    }

    /** @param list<string> $files */
    private function allDocsOnlyPaths(array $files): bool
    {
        if ($files === []) {
            return true;
        }

        foreach ($files as $file) {
            if (! str_starts_with($file, 'docs/') && ! str_ends_with($file, '.md')) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $files */
    private function touchesFactoryRuntime(array $files): bool
    {
        foreach ($files as $file) {
            if ($this->factoryRuntimeFile($file)) {
                return true;
            }
            if (str_starts_with($file, 'tests/') && (
                str_contains($file, '/SoftwareCompanyStewardship/')
                || str_contains($file, '/Programming/')
                || str_contains($file, '/AtlasForge/')
                || str_contains($file, '/AgenticEngineeringOs/')
            )) {
                return true;
            }
        }

        return false;
    }

    public function factoryRuntimeFile(string $file): bool
    {
        foreach (self::FACTORY_RUNTIME_PREFIXES as $prefix) {
            if (str_starts_with($file, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function factoryAcceptance(string $title, array $allowedFiles, array $testsRequired): array
    {
        $lines = [];
        if ($title !== '') {
            $lines[] = 'Given the selected factory finding, the patch implements: '.$title.'.';
        }
        if ($allowedFiles !== []) {
            $lines[] = 'The diff stays inside allowed_files and changes runtime and/or focused tests, not documentation-only scope.';
        }
        if ($testsRequired !== []) {
            $lines[] = 'Focused verification passes: php artisan test '.$testsRequired[0].'.';
        }

        return $lines;
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $testsRequired
     */
    private function factoryPatchNextAction(string $title, array $allowedFiles, array $testsRequired): string
    {
        $runtime = $allowedFiles[0] ?? 'selected runtime';
        $test = $testsRequired[0] ?? 'focused test';
        $label = $title !== '' ? $title : 'factory runtime improvement';

        return sprintf(
            'Implement "%s" with a minimal code patch in %s (not docs-only). Prove the change with: php artisan test %s.',
            $label,
            $runtime,
            $test,
        );
    }

    /**
     * @param  list<string>  $affectedFiles
     */
    public function expectedTestPath(string $basename, array $affectedFiles): string
    {
        if ($basename === '') {
            return '';
        }
        $source = $affectedFiles[0] ?? '';
        if (str_starts_with($source, 'app/Services/Ai/NightShift/')) {
            return 'tests/Unit/Ai/NightShift/'.$basename;
        }
        if (str_starts_with($source, 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/')) {
            $tail = substr($source, strlen('app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/'));
            $dir = trim(dirname($tail), '.');

            return 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/'
                .($dir !== '' ? $dir.'/' : '')
                .$basename;
        }
        if (str_starts_with($source, 'app/Services/Ai/')) {
            $tail = substr($source, strlen('app/Services/Ai/'));
            $dir = trim(dirname($tail), '.');

            return 'tests/Unit/Ai/'.($dir !== '' ? $dir.'/' : '').$basename;
        }

        return 'tests/Unit/'.$basename;
    }

    private function clampScore(int $value): int
    {
        return max(0, min(100, $value));
    }
}
