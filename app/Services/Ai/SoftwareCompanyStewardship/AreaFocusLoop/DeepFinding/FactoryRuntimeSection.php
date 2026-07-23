<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DeepFinding;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusPathNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;

/**
 * Atlas Dev + factory runtime section — extracted verbatim from
 * AreaFocusDeepFindingEngineService by the GOD-DEBULK split. Two read-only
 * finding sources over the highest-leverage Dev/Forge runtimes: the bottleneck
 * scan (provider-routing risk, blocking execution, missing focused tests) and
 * the missing-test coverage sweep. Both share the runtime-file candidate filter,
 * so they live together. All finding construction routes through the shared
 * {@see DeepFindingFactory}; taxonomy constants live on the façade and are
 * referenced qualified.
 */
class FactoryRuntimeSection
{
    /** @var list<string> */
    private const FACTORY_RUNTIME_COVERAGE_ROOTS = [
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/',
        'app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/',
        'app/Services/Ai/Programming/',
        'app/Services/Ai/AgenticEngineeringOs/',
        'app/Services/Ai/AtlasDecide/',
    ];

    /**
     * Extra Dev/Forge runtime roots surfaced only after AP-790 terminal starvation
     * so factory_max scans can replenish executable candidates beyond the default
     * stewardship/programming sweep.
     *
     * @var list<string>
     */
    private const FACTORY_RUNTIME_COVERAGE_REPLENISHMENT_ROOTS = [
        'app/Services/Ai/ProgrammingRuntime/',
        'app/Services/Ai/AtlasForge/',
        'app/Services/Ai/AgenticWorkcell/',
        'app/Services/Ai/Provider/',
    ];

    /**
     * Highest-leverage Atlas Dev + factory runtimes for bottleneck discovery
     * (provider routing, blocking execution, missing focused tests).
     *
     * @var list<string>
     */
    private const ATLAS_DEV_FACTORY_BOTTLENECK_SOURCES = [
        'app/Services/Ai/Programming/AtlasDevRuntimeService.php',
        'app/Services/Ai/Programming/AtlasForgeRuntimeDispatchService.php',
        'app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php',
        'app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerSandboxRuntimeRunnerService.php',
        'app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeExecutionAdapterService.php',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeRouterService.php',
    ];

    /** @var list<string> */
    private const FACTORY_RUNTIME_COVERAGE_NAME_TERMS = [
        'Service', 'Runtime', 'Runner', 'Engine', 'Coordinator', 'Dispatcher',
        'Router', 'Evaluator', 'Builder', 'Adapter', 'Driver', 'Governor',
        'Guard', 'Projector', 'Bridge', 'Planner', 'Registry', 'Certification',
        'Policy', 'Store', 'Executor', 'Classifier', 'Orchestrator',
    ];

    /** @var list<string> */
    private const FACTORY_RUNTIME_COVERAGE_EXCLUDED_NAME_TERMS = [
        'Interface', 'Contract', 'Dto', 'DTO', 'Data', 'Enum', 'Exception', 'Trait',
        'Value',
    ];

    public function __construct(
        private readonly DeepFindingFactory $findingFactory,
        private readonly DeepFindingSupport $deepFindingSupport,
        private readonly FactoryBacklogQualitySection $factoryBacklogQualitySection,
    ) {}

    /**
     * Focus-scoped runtime bottleneck scan for Atlas Dev + factory execution paths.
     * Surfaces provider-routing risks, blocking execution patterns and missing focused
     * tests on the highest-leverage runtimes instead of doc-only drift.
     *
     * @param  array<string,mixed>  $focusConfig
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>}
     */
    public function checkAtlasDevFactoryRuntimeBottlenecks(string $areaId, string $focus, array $focusConfig, array $input): array
    {
        if (($input['skip_atlas_dev_factory_runtime_bottlenecks'] ?? false) === true) {
            return [[], ['available' => true, 'skipped' => true, 'watch_count' => 0, 'emitted_count' => 0]];
        }

        $overrides = is_array($input['atlas_dev_factory_bottleneck_signals'] ?? null)
            ? $input['atlas_dev_factory_bottleneck_signals']
            : [];

        $findings = [];
        $signalCounts = [
            'provider_routing_risk' => 0,
            'execution_bottleneck' => 0,
            'missing_test' => 0,
        ];

        foreach (self::ATLAS_DEV_FACTORY_BOTTLENECK_SOURCES as $source) {
            if (! $this->deepFindingSupport->pathExists($source)) {
                continue;
            }

            $signals = is_array($overrides[$source] ?? null)
                ? AreaFocusStringListNormalizer::coercedStringValues($overrides[$source])
                : $this->detectAtlasDevFactoryBottleneckSignals($source);

            foreach ($signals as $signal) {
                $finding = $this->makeAtlasDevFactoryBottleneckFinding(
                    $areaId,
                    $focus,
                    $focusConfig,
                    $source,
                    $signal,
                );
                if ($finding === null) {
                    continue;
                }
                $findings[] = $finding;
                if (array_key_exists($signal, $signalCounts)) {
                    $signalCounts[$signal]++;
                }
            }
        }

        return [$findings, [
            'available' => true,
            'skipped' => false,
            'watch_count' => count(self::ATLAS_DEV_FACTORY_BOTTLENECK_SOURCES),
            'emitted_count' => count($findings),
            'signal_counts' => $signalCounts,
            'discovery_mode' => $overrides !== [] ? 'override' : 'static_analysis',
        ]];
    }

    /**
     * @return list<string>
     */
    private function detectAtlasDevFactoryBottleneckSignals(string $source): array
    {
        $signals = [];
        if ($this->detectProviderRoutingRisk($source)) {
            $signals[] = 'provider_routing_risk';
        }
        if ($this->detectExecutionBottleneck($source)) {
            $signals[] = 'execution_bottleneck';
        }
        $test = $this->factoryBacklogQualitySection->expectedTestPath(basename($source, '.php').'Test.php', [$source]);
        if ($test !== '' && ! $this->deepFindingSupport->pathExists($test) && $this->isFactoryRuntimeCoverageCandidate($source)) {
            $signals[] = 'missing_test';
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($signals);
    }

    private function detectProviderRoutingRisk(string $source): bool
    {
        $content = $this->readSourceHead($source, 32768);
        if ($content === '') {
            return false;
        }

        $hasProviderSurface = preg_match(
            '/(?:provider-invoke|driverInvoke|PROVIDER_COMMANDS|atlas:forge:provider|cursor_cli|codex_cli|gemini_cli|AtlasForgeProviderInvocation|provider_invocation)/i',
            $content,
        ) === 1;
        $hasDecideGovernance = preg_match(
            '/(?:atlas_decide|chosen_by_atlas_decide|decision_receipt|AtlasDecide|live_atlas_decide|decision_receipt_id)/i',
            $content,
        ) === 1;

        return $hasProviderSurface && ! $hasDecideGovernance;
    }

    private function detectExecutionBottleneck(string $source): bool
    {
        $content = $this->readSourceHead($source, 49152);
        if ($content === '') {
            return false;
        }
        if (preg_match('/\b(?:sleep|usleep)\s*\(/', $content) !== 1) {
            return false;
        }

        return preg_match(
            '/(?:timeout|budget|max_wait|TIMEOUT|stop_reason|budget_stop|rate_limit|kill_switch|Process::)/i',
            $content,
        ) !== 1;
    }

    private function readSourceHead(string $source, int $maxBytes): string
    {
        if (! $this->deepFindingSupport->pathExists($source)) {
            return '';
        }

        return (string) file_get_contents(AreaFocusPathNormalizer::absoluteFromBasePath($source), false, null, 0, $maxBytes);
    }

    /**
     * @param  array<string,mixed>  $focusConfig
     * @return array<string,mixed>|null
     */
    private function makeAtlasDevFactoryBottleneckFinding(
        string $areaId,
        string $focus,
        array $focusConfig,
        string $source,
        string $signal,
    ): ?array {
        $class = basename($source, '.php');
        $test = $this->factoryBacklogQualitySection->expectedTestPath($class.'Test.php', [$source]);

        return match ($signal) {
            'provider_routing_risk' => $this->findingFactory->makeFinding([
                'area_id' => $areaId,
                'focus' => $focus,
                'origin' => 'atlas_dev_factory_runtime_bottleneck_scan',
                'origin_type' => 'provider_routing_risk',
                'source_ref' => 'atlas_dev_factory_runtime_bottleneck:provider_routing:'.$source,
                'title' => 'Provider routing risk · '.$class,
                'detail' => $class.' exposes provider/driver dispatch surfaces without Atlas Decide topology or Decision Receipt governance in the same runtime file.',
                'kind' => AreaFocusDeepFindingEngineService::KIND_RISK,
                'owner_candidate' => AreaFocusDeepFindingEngineService::OWNER_ATLAS_DEV,
                'severity' => 'high',
                'confidence' => 'high',
                'evidence_refs' => [
                    'atlas_dev_factory_runtime_bottleneck:provider_routing:'.$source,
                    'impl:'.$source,
                ],
                'affected_paths' => [$source],
                'why_it_matters' => 'Autonomous Atlas Dev and factory cycles can hardcode provider paths and stall when a driver fails; routing must stay policy-driven through Atlas Decide.',
                'proposed_next_action' => 'Route '.$source.' through Atlas Decide topology + Decision Receipt v2 before owner/provider execution and prove it in '.($test !== '' ? $test : 'focused tests').'.',
            ], $focusConfig),
            'execution_bottleneck' => $this->findingFactory->makeFinding([
                'area_id' => $areaId,
                'focus' => $focus,
                'origin' => 'atlas_dev_factory_runtime_bottleneck_scan',
                'origin_type' => 'execution_bottleneck',
                'source_ref' => 'atlas_dev_factory_runtime_bottleneck:execution:'.$source,
                'title' => 'Execution bottleneck · '.$class,
                'detail' => $class.' uses blocking sleep/usleep without an explicit timeout, budget or kill-switch guard in the same runtime file.',
                'kind' => AreaFocusDeepFindingEngineService::KIND_RUNTIME,
                'owner_candidate' => AreaFocusDeepFindingEngineService::OWNER_ATLAS_DEV,
                'severity' => 'medium',
                'confidence' => 'medium',
                'evidence_refs' => [
                    'atlas_dev_factory_runtime_bottleneck:execution:'.$source,
                    'impl:'.$source,
                ],
                'affected_paths' => [$source],
                'why_it_matters' => 'Factory and 24h loops can stall provider throughput when a hot path blocks without bounded waits or budget stop reasons.',
                'proposed_next_action' => 'Replace unbounded blocking in '.$source.' with timeout/budget-aware pacing and prove recovery in '.($test !== '' ? $test : 'focused tests').'.',
            ], $focusConfig),
            'missing_test' => $test === '' || $this->deepFindingSupport->pathExists($test)
                ? null
                : $this->findingFactory->makeFinding([
                    'area_id' => $areaId,
                    'focus' => $focus,
                    'origin' => 'atlas_dev_factory_runtime_bottleneck_scan',
                    'origin_type' => 'missing_test',
                    'source_ref' => 'atlas_dev_factory_runtime_bottleneck:missing_test:'.$source,
                    'title' => 'Factory runtime bottleneck · missing test for '.$class,
                    'detail' => $class.' is on the Atlas Dev/factory bottleneck watchlist without same-name focused regression coverage.',
                    'kind' => AreaFocusDeepFindingEngineService::KIND_TEST,
                    'owner_candidate' => AreaFocusDeepFindingEngineService::OWNER_ATLAS_DEV,
                    'severity' => 'medium',
                    'confidence' => 'high',
                    'evidence_refs' => [
                        'atlas_dev_factory_runtime_bottleneck:missing_test:'.$source,
                        'impl:'.$source,
                        'expected_test:'.basename($test),
                    ],
                    'affected_paths' => [$source],
                    'why_it_matters' => 'Runtime bottlenecks in Atlas Dev and the factory cannot be hardened safely when hot-path services lack focused tests.',
                    'proposed_next_action' => 'Add or harden '.$test.' for '.$source.' and prove it with php artisan test '.$test.'.',
                ], $focusConfig),
            default => null,
        };
    }

    /**
     * AP-790 needs a deep backlog, not a tiny curated list. This read-only sweep
     * turns existing high-leverage factory runtime classes without same-name
     * tests into executable missing-test findings. It deliberately excludes DTOs,
     * contracts, interfaces, traits and abstract classes so the loop does not
     * burn provider cycles on structural false positives.
     *
     * @param  array<string,mixed>  $focusConfig
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>}
     */
    public function checkFactoryRuntimeCoverage(string $areaId, string $focus, array $focusConfig, array $input): array
    {
        if (($input['skip_factory_runtime_coverage'] ?? false) === true) {
            return [[], ['available' => true, 'skipped' => true, 'candidate_count' => 0]];
        }

        $replenishmentActive = $this->terminalBacklogReplenishmentActive($input);
        $coverageRoots = $this->resolveFactoryRuntimeCoverageRoots($input);
        $files = is_array($input['factory_runtime_coverage_files'] ?? null)
            ? AreaFocusStringListNormalizer::stringifiedNonEmptyValues($input['factory_runtime_coverage_files'])
            : $this->discoverFactoryRuntimeCoverageFiles($coverageRoots);

        $findings = [];
        $skippedCovered = 0;
        $skippedNonRuntime = 0;
        foreach ($files as $file) {
            if (! $this->isFactoryRuntimeCoverageCandidate($file)) {
                $skippedNonRuntime++;

                continue;
            }
            $test = $this->factoryBacklogQualitySection->expectedTestPath(basename($file, '.php').'Test.php', [$file]);
            if ($test === '' || $this->deepFindingSupport->pathExists($test)) {
                $skippedCovered++;

                continue;
            }

            $class = basename($file, '.php');
            $finding = $this->findingFactory->makeFinding([
                'area_id' => $areaId,
                'focus' => $focus,
                'origin' => 'factory_runtime_coverage_sweep',
                'origin_type' => 'missing_test',
                'source_ref' => 'factory_runtime_coverage_sweep:'.$file,
                'title' => 'Missing test for '.$class,
                'detail' => $class.' is a factory-critical runtime class in the AAEOS / Atlas Dev / Forge flow without same-name focused coverage.',
                'kind' => AreaFocusDeepFindingEngineService::KIND_TEST,
                'owner_candidate' => AreaFocusDeepFindingEngineService::OWNER_ATLAS_DEV,
                'severity' => 'medium',
                'confidence' => 'high',
                'evidence_refs' => [
                    'factory_runtime_coverage_sweep:'.$file,
                    'impl:'.$file,
                    'expected_test:'.basename($test),
                ],
                'affected_paths' => [$file],
                'why_it_matters' => 'The autonomous software factory cannot run safely for many cycles if core Dev/Forge/stewardship runtimes lack focused regression coverage.',
                'proposed_next_action' => 'Add or harden '.$test.' for '.$file.' and prove it with php artisan test '.$test.'.',
            ], $focusConfig);
            if ($replenishmentActive && $this->factoryRuntimeCoverageFileFromReplenishmentRoot($file)) {
                $finding['terminal_backlog_replenishment'] = true;
                $finding['terminal_backlog_state_hash'] = trim((string) ($input['terminal_backlog_state_hash'] ?? ''));
            }
            $findings[] = $finding;
        }

        return [$findings, [
            'available' => true,
            'recursive_scan' => true,
            'discovery_mode' => is_array($input['factory_runtime_coverage_files'] ?? null) ? 'override' : 'recursive',
            'root_count' => count($coverageRoots),
            'terminal_backlog_replenishment' => $replenishmentActive,
            'terminal_backlog_state_hash' => trim((string) ($input['terminal_backlog_state_hash'] ?? '')),
            'terminal_backlog_rejection_reason_count' => count(array_values(array_filter(
                (array) ($input['terminal_backlog_rejection_reasons'] ?? []),
                'is_string',
            ))),
            'replenished_root_count' => $replenishmentActive ? count(self::FACTORY_RUNTIME_COVERAGE_REPLENISHMENT_ROOTS) : 0,
            'replenished_roots' => $replenishmentActive ? self::FACTORY_RUNTIME_COVERAGE_REPLENISHMENT_ROOTS : [],
            'candidate_count' => count($files),
            'nested_candidate_count' => $this->countNestedFactoryRuntimeCoverageFiles($files, $coverageRoots),
            'emitted_count' => count($findings),
            'executable_emitted_count' => count(array_filter(
                $findings,
                static fn (array $finding): bool => (string) ($finding['origin'] ?? '') === 'factory_runtime_coverage_sweep',
            )),
            'skipped_already_covered_count' => $skippedCovered,
            'skipped_non_runtime_count' => $skippedNonRuntime,
        ]];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<string>
     */
    private function resolveFactoryRuntimeCoverageRoots(array $input): array
    {
        $roots = self::FACTORY_RUNTIME_COVERAGE_ROOTS;
        if ($this->terminalBacklogReplenishmentActive($input)) {
            $roots = AreaFocusStringListNormalizer::uniqueMergedStringValues($roots, self::FACTORY_RUNTIME_COVERAGE_REPLENISHMENT_ROOTS);
        }

        return $roots;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function terminalBacklogReplenishmentActive(array $input): bool
    {
        $stateHash = trim((string) ($input['terminal_backlog_state_hash'] ?? ''));
        $reasons = AreaFocusStringListNormalizer::coercedStringValues($input['terminal_backlog_rejection_reasons'] ?? []);

        return $stateHash !== '' || $reasons !== [];
    }

    private function factoryRuntimeCoverageFileFromReplenishmentRoot(string $file): bool
    {
        foreach (self::FACTORY_RUNTIME_COVERAGE_REPLENISHMENT_ROOTS as $root) {
            if (str_starts_with($file, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $roots
     * @return list<string>
     */
    private function discoverFactoryRuntimeCoverageFiles(array $roots): array
    {
        $files = [];
        foreach ($roots as $root) {
            $absolute = AreaFocusPathNormalizer::absoluteFromBasePath($root);
            if (! is_dir($absolute)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $fileInfo) {
                if (! $fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                    continue;
                }
                $relative = AreaFocusPathNormalizer::repoRelativeFromBasePath($fileInfo->getPathname());
                if ($relative !== '') {
                    $files[] = $relative;
                }
            }
        }

        sort($files);

        return AreaFocusStringListNormalizer::uniqueStringValues($files);
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $roots
     */
    private function countNestedFactoryRuntimeCoverageFiles(array $files, array $roots): int
    {
        $nested = 0;
        foreach ($files as $file) {
            foreach ($roots as $root) {
                if (! str_starts_with($file, $root)) {
                    continue;
                }
                if (str_contains(substr($file, strlen($root)), '/')) {
                    $nested++;
                }
                break;
            }
        }

        return $nested;
    }

    public function isFactoryRuntimeCoverageCandidate(string $file): bool
    {
        if (! $this->factoryBacklogQualitySection->factoryRuntimeFile($file) || ! str_ends_with($file, '.php') || ! $this->deepFindingSupport->pathExists($file)) {
            return false;
        }

        $class = basename($file, '.php');
        foreach (self::FACTORY_RUNTIME_COVERAGE_EXCLUDED_NAME_TERMS as $term) {
            if ($term !== '' && str_contains($class, $term)) {
                return false;
            }
        }

        $matchesName = false;
        foreach (self::FACTORY_RUNTIME_COVERAGE_NAME_TERMS as $term) {
            if ($term !== '' && str_ends_with($class, $term)) {
                $matchesName = true;
                break;
            }
        }
        if (! $matchesName) {
            return false;
        }

        $head = (string) file_get_contents(AreaFocusPathNormalizer::absoluteFromBasePath($file), false, null, 0, 4096);
        if (preg_match('/\b(interface|trait)\s+[A-Za-z_][A-Za-z0-9_]*/', $head) === 1) {
            return false;
        }
        if (preg_match('/\babstract\s+class\s+[A-Za-z_][A-Za-z0-9_]*/', $head) === 1) {
            return false;
        }

        return preg_match('/\b(?:final\s+)?class\s+[A-Za-z_][A-Za-z0-9_]*/', $head) === 1;
    }
}
