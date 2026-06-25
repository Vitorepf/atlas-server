<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Introspection;

/**
 * Reports the BUILT / ARMED / BUILT_ONLY state of each canonical loop capability.
 *
 * Per-capability output:
 *   { state, class_fqcn, wiring_evidence, scanned_at }
 *
 * - BUILT       — at least one matching class exists in the codebase.
 * - ARMED       — class exists AND is referenced by an entrypoint (Console command, Service
 *                 Provider, config file, or live cycle service).
 * - BUILT_ONLY  — class exists but no live caller was found.
 * - missing     — no matching class was found at all.
 *
 * NO scalar coverage_percent / score / grade — just per-capability state strings.
 */
final class AtlasLoopSelfCapabilityCoverageReporter
{
    public const SCHEMA = 'atlas.loop.self_capability_coverage_facts.v1';

    public const STATE_MISSING = 'missing';

    public const STATE_BUILT_ONLY = 'BUILT_ONLY';

    public const STATE_BUILT = 'BUILT';

    public const STATE_ARMED = 'ARMED';

    /**
     * Canonical loop capability registry: phase names + a sampling of primitives that the
     * gap-map memo flagged.
     *
     * @var array<string, list<string>>  capability → list of class-name tokens to match
     */
    public const REGISTRY = [
        'orient' => ['Orient', 'ScopeOrientation', 'Orientation'],
        'comprehend' => ['Comprehension', 'SelfArchitectureScanner', 'SelfDependencyGraph'],
        'decide_leverage' => ['LeverageDecider', 'NextWorkDecider', 'StrategyCouncil'],
        'architect' => ['Architect', 'ArchitectureCouncil', 'DesignReview'],
        'decompose' => ['Decompose', 'PacketBuilder', 'TaskGraphMissingOrgan'],
        'implement' => ['NativeImplementation', 'NativePatchMaterializer', 'NativeWorker'],
        'certify' => ['Certif', 'VerificationCourt', 'FrozenJudge'],
        'close_to_main' => ['AutoMerge', 'MergeGovernor', 'ScopedCommitter'],
        'learn' => ['Learning', 'OutcomeLedger', 'LearningTransfer'],
        // Sampled primitives from the gap map.
        'refiller' => ['Refiller'],
        'frontier' => ['Frontier'],
        'origination' => ['Originat'],
        'wiring_audit' => ['Wiring', 'IntegrationProbe'],
    ];

    public function __construct(
        private readonly ?string $rootOverride = null,
        private readonly ?string $appRootOverride = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function scan(): array
    {
        $autonomousEvolutionRoot = $this->rootOverride ?? (function_exists('base_path') ? base_path('app/Services/Ai/AutonomousEvolution') : '');
        $appRoot = $this->appRootOverride ?? (function_exists('base_path') ? base_path('app') : dirname(__DIR__, 5).'/app');

        $autoFiles = is_dir($autonomousEvolutionRoot) ? $this->collectPhpFiles($autonomousEvolutionRoot) : [];
        $classIndex = $this->buildClassIndex($autoFiles);

        $entrypointRoots = [
            $appRoot.'/Console/Commands',
            $appRoot.'/Providers',
        ];
        $entrypointFiles = [];
        foreach ($entrypointRoots as $r) {
            if (is_dir($r)) {
                $entrypointFiles = array_merge($entrypointFiles, $this->collectPhpFiles($r));
            }
        }

        $scannedAt = gmdate('Y-m-d\TH:i:s\Z');
        $report = [];
        $foundBuiltOnly = false;
        foreach (self::REGISTRY as $capability => $tokens) {
            $matches = $this->classesMatchingAnyToken($classIndex, $tokens);
            if ($matches === []) {
                $report[$capability] = [
                    'state' => self::STATE_MISSING,
                    'class_fqcn' => null,
                    'wiring_evidence' => null,
                    'scanned_at' => $scannedAt,
                ];

                continue;
            }
            // Pick the alphabetically-first match for determinism.
            sort($matches, SORT_STRING);
            $candidate = $matches[0];
            $shortName = $this->shortName($candidate);
            $evidence = $this->findEntrypointEvidence($entrypointFiles, $shortName);
            $state = $evidence === null ? self::STATE_BUILT_ONLY : self::STATE_ARMED;
            if ($state === self::STATE_BUILT_ONLY) {
                $foundBuiltOnly = true;
            }
            $report[$capability] = [
                'state' => $state,
                'class_fqcn' => $candidate,
                'wiring_evidence' => $evidence,
                'scanned_at' => $scannedAt,
            ];
        }

        // Stable key order.
        ksort($report, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA,
            'capabilities' => $report,
            'has_built_only' => $foundBuiltOnly,
        ];
    }

    /**
     * @param  list<string>  $files
     * @return array<string, list<string>>  short class name → list of FQCNs
     */
    private function buildClassIndex(array $files): array
    {
        $index = [];
        foreach ($files as $file) {
            $src = (string) @file_get_contents($file);
            if ($src === '') {
                continue;
            }
            $ns = '';
            if (preg_match('/^\s*namespace\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*;/m', $src, $m) === 1) {
                $ns = $m[1];
            }
            if (preg_match_all('/^(?:final\s+|abstract\s+)?(?:final\s+|abstract\s+)?(?:readonly\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $src, $cm) > 0) {
                foreach ($cm[1] as $name) {
                    $fqcn = ($ns !== '' ? $ns.'\\' : '').$name;
                    $index[$name][] = $fqcn;
                }
            }
        }

        return $index;
    }

    /**
     * @param  array<string, list<string>>  $classIndex
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function classesMatchingAnyToken(array $classIndex, array $tokens): array
    {
        $hits = [];
        foreach ($classIndex as $shortName => $fqcns) {
            foreach ($tokens as $token) {
                if (str_contains($shortName, $token)) {
                    foreach ($fqcns as $fqcn) {
                        $hits[$fqcn] = true;
                    }
                    break;
                }
            }
        }

        return array_keys($hits);
    }

    /**
     * @param  list<string>  $entrypointFiles
     */
    private function findEntrypointEvidence(array $entrypointFiles, string $shortName): ?string
    {
        foreach ($entrypointFiles as $file) {
            $src = (string) @file_get_contents($file);
            if ($src === '') {
                continue;
            }
            $lines = explode("\n", $src);
            foreach ($lines as $i => $line) {
                if (str_contains($line, $shortName)) {
                    return $file.':'.($i + 1);
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function collectPhpFiles(string $root): array
    {
        $files = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
