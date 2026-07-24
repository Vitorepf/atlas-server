<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

/**
 * P3a / R103: consumer census for deletion/alignment candidates.
 *
 * Pure inventory + disk scan — **no mass delete**. P3b may delete only after
 * MASTER amendment lists paths and dual GateEvaluated approvals.
 */
final class AaeosDeletionConsumerCensus
{
    public const SCHEMA = 'atlas.aaeos.p3a.consumer_census.v1';

    /**
     * Families deleted in P3b under amendment p3b-trihygiene-aliases-delete-v1.
     *
     * @var list<string>
     */
    public const DELETED_IN_P3B = [
        'AaeosTriHygieneScorecardProjector',
        'AtlasTriHygieneScorecardCommand',
        'AaeosHygieneLegacyAliases',
    ];


    /**
     * Frozen production consumers discovered at P3a (sorted). Update only with
     * PHASE residual honesty when new consumers appear.
     *
     * @var array<string,list<string>>
     */
    public const FROZEN_PRODUCTION_CONSUMERS = [
        'PipelineRunExecutor_family' => [
            'app/Http/Controllers/AtlasDev/Support/CompactSddUnavailableException.php',
            'app/Services/Ai/Context/AtlasAucriOptimizationAuditService.php',
            'app/Services/Ai/Context/AtlasAucriRuntimeEnforcementService.php',
            'app/Services/Ai/EngineeringKernel/QualityFoundry/QualityFoundryLiveManifestService.php',
            'app/Services/Ai/EngineeringKernel/QualityFoundry/QualityFoundryMutationCoverageRunner.php',
            'app/Services/Ai/Governance/GovernanceConsultSkipCounter.php',
            'app/Services/Ai/Governance/ProviderGovernanceCoverageLedger.php',
            'app/Services/Ai/Programming/AtlasDev/Differential/CandidateDivergenceVerdict.php',
            'app/Services/Ai/Programming/AtlasDev/Differential/Shadow/ShadowDiffVerdict.php',
            'app/Services/Ai/Programming/AtlasDev/Gate/CompletionStateGate.php',
            'app/Services/Ai/Programming/AtlasDev/Mutation/MutationScoreVerdict.php',
            'app/Services/Ai/Programming/AtlasDev/Pipeline/SpecComposer.php',
            'app/Services/Ai/Programming/AtlasDev/Probe/IntentFalsificationProbe.php',
            'app/Services/Ai/Programming/AtlasDev/Regression/RegressionVerdict.php',
            'app/Services/Ai/Programming/AtlasDev/Runtime/AtlasDevDesktopCertificationService.php',
            'app/Services/Ai/Programming/AtlasForgeHermesCliInvocationDriver.php',
            'app/Services/Ai/Programming/HermesWorkspaceDefaults.php',
            'app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceExecutionBoundaryAuditService.php',
            'app/Services/Engineering/CodeRealityUsageIntelligence/CodeRealityDuplicateClassSection.php',
            'config/atlas.php',
            'config/atlas_dev.php',
            'scripts/rivals-atlas-dev-bridge.php',
        ],
        'AaeosOrgStateProjector' => [
            'app/Console/Commands/AtlasCliCockpitCommand.php',
            'app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php',
            'app/Services/Ai/CODEMAP.md',
        ],
        'AaeosCycleOutcomeRecorder' => [
            'app/Console/Commands/AtlasAaeosCycleCommand.php',
            'app/Console/Commands/AtlasAaeosRunCommand.php',
            'app/Services/Ai/CODEMAP.md',
        ],
        'AaeosScorecardProjector' => [
            'app/Console/Commands/AtlasAaeosCertifyCommand.php',
            'app/Console/Commands/AtlasAaeosScorecardCommand.php',
            'app/Console/Commands/AtlasCliCockpitCommand.php',
        ],
    ];

    /**
     * Owner (definition) paths — not counted as consumers.
     *
     * @var array<string,list<string>>
     */
    public const OWNERS = [
        'PipelineRunExecutor_family' => [
            'app/Http/Controllers/AtlasDev/Support/PipelineRunExecutor.php',
            'app/Http/Controllers/AtlasDev/Support/PipelineRun/BestOfNSection.php',
            'app/Http/Controllers/AtlasDev/Support/PipelineRun/DeterministicPatchSection.php',
            'app/Http/Controllers/AtlasDev/Support/PipelineRun/GovernanceSection.php',
            'app/Http/Controllers/AtlasDev/Support/PipelineRun/ProviderExecutionSection.php',
            'app/Http/Controllers/AtlasDev/Support/PipelineRun/ProviderResultSupport.php',
            'app/Http/Controllers/AtlasDev/Support/PipelineRun/RepairProjectionSection.php',
            'app/Http/Controllers/AtlasDev/Support/PipelineRun/ResolverSupport.php',
            'app/Http/Controllers/AtlasDev/Support/PipelineRun/WorkspaceGitSupport.php',
        ],
        'AaeosOrgStateProjector' => [
            'app/Services/Ai/Aaeos/Control/AaeosOrgStateProjector.php',
        ],
        'AaeosCycleOutcomeRecorder' => [
            'app/Services/Ai/Aaeos/Control/AaeosCycleOutcomeRecorder.php',
        ],
        'AaeosScorecardProjector' => [
            'app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php',
        ],
    ];

    /**
     * Needle patterns used when scanning production trees.
     *
     * @var array<string,list<string>>
     */
    public const SCAN_NEEDLES = [
        'PipelineRunExecutor_family' => [
            'PipelineRunExecutor',
            'AtlasDev\\Support\\PipelineRun\\',
            'Http/Controllers/AtlasDev/Support/PipelineRun',
        ],
        'AaeosOrgStateProjector' => ['AaeosOrgStateProjector'],
        'AaeosCycleOutcomeRecorder' => ['AaeosCycleOutcomeRecorder'],
        'AaeosScorecardProjector' => ['AaeosScorecardProjector'],
    ];

    /**
     * Full census report (frozen inventory + live scan delta + policy).
     *
     * @return array<string,mixed>
     */
    public static function report(?string $repoRoot = null): array
    {
        $root = $repoRoot ?? base_path();
        $families = [];
        $unknown = [];
        $missingOwners = [];

        foreach (self::FROZEN_PRODUCTION_CONSUMERS as $family => $frozen) {
            $owners = self::OWNERS[$family] ?? [];
            foreach ($owners as $owner) {
                if (! is_file($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $owner))) {
                    $missingOwners[] = $owner;
                }
            }

            $live = self::scanProductionConsumers($family, $root);
            $frozenSorted = array_values($frozen);
            sort($frozenSorted);
            $liveSorted = array_values($live);
            sort($liveSorted);

            $newOnDisk = array_values(array_diff($liveSorted, $frozenSorted));
            $missingFromDisk = array_values(array_diff($frozenSorted, $liveSorted));
            foreach ($newOnDisk as $path) {
                $unknown[] = ['family' => $family, 'path' => $path];
            }

            $prodCount = count($frozenSorted);
            $p3bCandidate = false;

            $families[$family] = [
                'owners' => $owners,
                'production_consumers_frozen' => $frozenSorted,
                'production_consumers_live' => $liveSorted,
                'production_consumer_count' => $prodCount,
                'new_on_disk_not_in_freeze' => $newOnDisk,
                'frozen_missing_on_disk' => $missingFromDisk,
                'delete_authorized_in_p3a' => false,
                'p3b_candidate_hint' => $p3bCandidate,
                'retain_reason' => self::retainReason($family, $prodCount),
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'phase' => 'P3a',
            'policy' => 'no_mass_delete',
            'mass_delete_forbidden' => true,
            'delete_authorized_in_p3a' => false,
            'p3b_requires_master_amendment' => true,
            'p3b_requires_dual_gate_evaluated' => true,
            'families' => $families,
            'unknown_production_consumers' => $unknown,
            'missing_owners' => $missingOwners,
            'freeze_matches_live' => $unknown === [] && $missingOwners === [],
            'pipeline_run_executor_retain' => true,
            'deleted_in_p3b' => self::DELETED_IN_P3B,
            'notes' => [
                'P3a is census-only. No production deletion in this phase.',
                'PipelineRunExecutor family retained until R103 port amendment.',
                'OrgState/OutcomeRecorder retain while production readers > 0.',
                'TriHygiene + HygieneLegacyAliases are P3b candidates after cold-process negative resolution.',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function scanProductionConsumers(string $family, ?string $repoRoot = null): array
    {
        $root = $repoRoot ?? base_path();
        $needles = self::SCAN_NEEDLES[$family] ?? [];
        if ($needles === []) {
            return [];
        }

        $owners = array_flip(self::OWNERS[$family] ?? []);
        $found = [];
        $trees = ['app', 'config', 'routes', 'bin', 'scripts', 'bootstrap'];

        // composer.json special-case for aliases files autoload.
        if ($family === 'AaeosHygieneLegacyAliases') {
            $composer = $root.DIRECTORY_SEPARATOR.'composer.json';
            if (is_file($composer) && str_contains((string) file_get_contents($composer), 'AaeosHygieneLegacyAliases')) {
                $found[] = 'composer.json';
            }
        }

        foreach ($trees as $tree) {
            $base = $root.DIRECTORY_SEPARATOR.$tree;
            if (! is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (! in_array($ext, ['php', 'md', 'json', 'mjs', 'js', 'yml', 'yaml', ''], true)) {
                    continue;
                }
                $absolute = $file->getPathname();
                $rel = ltrim(str_replace('\\', '/', substr($absolute, strlen($root))), '/');
                if (isset($owners[$rel])) {
                    continue;
                }
                // Skip vendor-like noise under app (none expected).
                if (str_contains($rel, '/vendor/')) {
                    continue;
                }
                // Census self-reference and tests are not production consumers.
                if ($rel === 'app/Services/Ai/Aaeos/Control/AaeosDeletionConsumerCensus.php'
                    || str_starts_with($rel, 'tests/')) {
                    continue;
                }
                $contents = (string) @file_get_contents($absolute);
                foreach ($needles as $needle) {
                    if (str_contains($contents, $needle)) {
                        $found[] = $rel;
                        break;
                    }
                }
            }
        }

        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    private static function retainReason(string $family, int $prodCount): string
    {
        return match ($family) {
            'PipelineRunExecutor_family' => 'R103 retain until full port amendment; '.$prodCount.' production consumers',
            'AaeosOrgStateProjector' => 'cockpit + scorecard production readers ('.$prodCount.')',
            'AaeosCycleOutcomeRecorder' => 'run/cycle command production readers ('.$prodCount.')',
            'AaeosScorecardProjector' => 'CLI scorecard/certify/cockpit production readers ('.$prodCount.')',
            default => 'retain pending amendment',
        };
    }
}
