<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Models\AtlasDocsAuthorityGraph;
use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringDocLink;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasSoftwareTwinSnapshot;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationTruthService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDocsAuthorityGraphService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use SplFileInfo;

// Intentionally NOT final: this read-only predictive service is designed to be
// injected and wrapped (e.g. by the L2-O2 intent advisory), and downstream tests
// mock simulate() for determinism — mirroring the non-final, mockable convention
// of the other injected truth services (e.g. AtlasImplementationTruthService).
class AtlasSoftwareTwinRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.software_twin.v1';

    public const IMPACT_SCHEMA_VERSION = 'atlas.software_twin.impact.v1';

    public const IMPACT_GRAPHRAG_SCHEMA_VERSION = 'atlas.impact_graphrag.v1';

    public const CONTEXT_SCHEMA_VERSION = 'atlas.software_twin.context_envelope.v1';

    public const QUALITY_SCHEMA_VERSION = 'atlas.software_twin.quality_score.v1';

    public const SNAPSHOT_SCHEMA_VERSION = 'atlas.software_twin.snapshot.v1';

    public const PREDICTIVE_SCHEMA_VERSION = 'atlas.software_twin.predictive.v1';

    /**
     * Minimum authority-graph confidence for a located owner/overlap to count as
     * authoritative. Below this (e.g. a broad keyword_fallback) a proposed owner is
     * treated as unresolved => needs_owner_review, and a weak capability match is
     * NOT counted as an overlap (avoids both a false-clean owner and a false-positive
     * duplication on a vague keyword).
     */
    private const OWNER_CONFIDENCE_FLOOR = 80;

    private const IMPACT_GRAPH_LIMITS = [
        'target_symbols' => 12,
        'modules' => 8,
        'doc_links' => 12,
        'entrypoints' => 12,
        'tests' => 12,
        'causal_paths' => 12,
    ];

    /**
     * @var array<int,string>
     */
    private const CORE_TARGETS = [
        'acir' => 'app/Services/Engineering/EngineeringCodeIntelligenceService.php',
        'acrui' => 'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php',
        'adrs' => 'app/Services/Engineering/AtlasDocumentationRealitySystemService.php',
        'aurc' => 'app/Services/Engineering/AtlasUniversalRealityCartographyService.php',
        'astr' => 'app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php',
        'aveor' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php',
        'aver' => 'app/Services/Ai/VerifiedExecution/AtlasVerifiedExecutionRuntimeService.php',
        'aemor' => 'app/Services/Ai/Aemor/AtlasAemorRuntimeService.php',
    ];

    public function __construct(
        private readonly AtlasCodeRealityUsageIntelligenceService $codeReality,
        private readonly AtlasImplementationTruthService $implementationTruth,
        private readonly AtlasDocsAuthorityGraphService $authorityGraph,
        private readonly CanonicalDocsFrontmatterParser $frontmatter,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function twin(string $target = ''): array
    {
        $target = trim($target);
        $targetPath = $target !== '' ? $this->resolveTarget($target) : null;
        $targetReality = $targetPath !== null
            ? $this->codeReality->classify($targetPath)
            : null;

        $nodes = $this->coreNodes();
        if ($targetPath !== null && ! collect($nodes)->contains(fn (array $node): bool => $node['path'] === $targetPath)) {
            $nodes[] = $this->node('target', $targetPath);
        }

        $edges = $this->edges($nodes);
        $quality = $this->qualityFrom($nodes, $edges);
        $blockers = $this->blockers($nodes, $quality);

        return $this->envelope([
            'action' => 'twin',
            'target' => $target,
            'target_path' => $targetPath,
            'target_reality' => $this->compactReality($targetReality),
            'software_twin' => [
                'schema_version' => self::SCHEMA_VERSION,
                'mode' => 'read_only_living_system_twin',
                'node_count' => count($nodes),
                'edge_count' => count($edges),
                'nodes' => $nodes,
                'edges' => $edges,
                'runtime_lenses' => [
                    'code_intelligence' => 'EngineeringCodeIntelligenceService',
                    'operational_reality' => 'AtlasCodeRealityUsageIntelligenceService',
                    'documentation_reality' => 'AtlasDocumentationRealitySystemService',
                    'human_cartography' => 'AtlasUniversalRealityCartographyService',
                    'verified_execution' => 'AtlasVerifiedExecutionRuntimeService',
                    'outcome_learning' => 'AtlasAemorRuntimeService',
                ],
            ],
            'quality_score' => $quality,
            'blockers' => $blockers,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function impact(string $target): array
    {
        $target = trim($target);
        $targetPath = $this->resolveTarget($target);
        $reality = $targetPath !== null ? $this->codeReality->usageMap($targetPath) : $this->codeReality->classify($target);
        $classification = (string) ($reality['classification'] ?? 'unknown_requires_audit');
        $reachability = (array) data_get($reality, 'usage_map.reachability', data_get($reality, 'evidence.reachability', []));
        $edges = (array) data_get($reachability, 'edges', []);
        $ownerDocs = (array) data_get($reality, 'usage_map.owner_docs', data_get($reality, 'evidence.owner_docs', []));
        $tests = (array) data_get($reality, 'usage_map.tests', data_get($reality, 'evidence.tests', []));
        $impactGraphRag = $this->impactGraphRag($target, $targetPath, $reachability, $ownerDocs, $tests);
        $ownerDocs = $this->mergedUniqueStrings(
            $ownerDocs,
            (array) data_get($impactGraphRag, 'selected_context.owner_docs', []),
        );
        $tests = $this->mergedUniqueStrings(
            $tests,
            (array) data_get($impactGraphRag, 'selected_context.required_tests', []),
        );
        $riskLevel = $this->riskLevel($classification, $reachability, $tests, $ownerDocs);

        return $this->envelope([
            'schema_version' => self::IMPACT_SCHEMA_VERSION,
            'action' => 'impact',
            'target' => $target,
            'target_path' => $targetPath,
            'classification' => $classification,
            'impact' => [
                'risk_level' => $riskLevel,
                'reachable' => data_get($reachability, 'status') === 'reachable',
                'reachability_confidence' => data_get($reachability, 'confidence', 'none'),
                'affected_edges' => array_slice($edges, 0, 20),
                'owner_docs' => array_slice($ownerDocs, 0, 12),
                'required_tests' => array_slice($tests, 0, 12),
                'impact_graphrag' => $impactGraphRag,
                'required_gates' => [
                    'php artisan atlas:code-reality reachability --target="'.$target.'" --json',
                    'php artisan atlas:software-twin impact --target="'.$target.'" --json',
                    'php artisan atlas:verified-evolution proof-plan --target="'.$target.'" --objective="<objective>" --json',
                    'php artisan atlas:aver:certify --json --strict',
                ],
            ],
            'blockers' => $targetPath === null ? [['reason' => 'target_not_found', 'target' => $target]] : [],
        ]);
    }

    /**
     * Build a bounded, provider-safe Impact GraphRAG read model over the existing
     * Code Intelligence graph. This is intentionally not a heavy graph/embedding
     * engine in Laravel; it selects compact causal context from indexed symbols,
     * modules and doc links so verified evolution can reason about blast radius
     * without flooding the provider.
     *
     * @param  array<string,mixed>  $reachability
     * @param  array<int,string>  $ownerDocs
     * @param  array<int,string>  $tests
     * @return array<string,mixed>
     */
    public function impactGraphRag(string $target, ?string $targetPath, array $reachability, array $ownerDocs, array $tests): array
    {
        return $this->impactGraph()->impactGraphRag($target, $targetPath, $reachability, $ownerDocs, $tests);
    }

    public function targetSymbols(string $target, ?string $targetPath): array
    {
        return $this->impactGraph()->targetSymbols($target, $targetPath);
    }

    public function impactModules(array $moduleIds): array
    {
        return $this->impactGraph()->impactModules($moduleIds);
    }

    public function impactDocLinks(array $moduleIds, array $symbolIds, ?string $targetPath): array
    {
        return $this->impactGraph()->impactDocLinks($moduleIds, $symbolIds, $targetPath);
    }

    public function docLinkImpactRank(array $link, array $symbolIds, ?string $targetPath): int
    {
        return $this->impactGraph()->docLinkImpactRank($link, $symbolIds, $targetPath);
    }

    public function impactSymbols(array $moduleIds, array $excludeSymbolIds, array $types, ?string $targetPath): array
    {
        return $this->impactGraph()->impactSymbols($moduleIds, $excludeSymbolIds, $types, $targetPath);
    }

    public function impactCausalPaths(string $target, array $modules, array $docLinks, array $entrypoints, array $tests): array
    {
        return $this->impactGraph()->impactCausalPaths($target, $modules, $docLinks, $entrypoints, $tests);
    }

    public function impactGraphConfidence(array $targetSymbols, array $modules, array $docLinks, array $tests, array $entrypoints, array $reachability): array
    {
        return $this->impactGraph()->impactGraphConfidence($targetSymbols, $modules, $docLinks, $tests, $entrypoints, $reachability);
    }

    public function symbolImpactPayload(AtlasEngineeringCodeSymbol $symbol): array
    {
        return $this->impactGraph()->symbolImpactPayload($symbol);
    }

    private function impactGraph(): AtlasSoftwareTwinImpactGraph
    {
        return $this->impactGraphInstance ??= new AtlasSoftwareTwinImpactGraph(
            self::IMPACT_GRAPH_LIMITS,
            fn (array $items, string $key): array => $this->pluckUnique($items, $key),
            fn (array ...$items): array => $this->mergedUniqueStrings(...$items),
            fn (): bool => $this->codeGraphTablesReady(),
            fn (array $modules, string $key): array => $this->moduleRelatedStrings($modules, $key),
            fn (string $path): string => $this->pathDirectory($path),
        );
    }


    private function codeGraphTablesReady(): bool
    {
        return DatabaseTableAvailability::has('atlas_engineering_code_symbols')
            && DatabaseTableAvailability::has('atlas_engineering_code_modules')
            && DatabaseTableAvailability::has('atlas_engineering_doc_links');
    }


    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,string>
     */
    private function pluckUnique(array $rows, string $key): array
    {
        return $this->mergedUniqueStrings(array_map(
            static fn (array $row): string => (string) ($row[$key] ?? ''),
            $rows,
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $modules
     * @return array<int,string>
     */
    private function moduleRelatedStrings(array $modules, string $key): array
    {
        return $this->mergedUniqueStrings(...array_map(
            static fn (array $module): array => (array) ($module[$key] ?? []),
            $modules,
        ));
    }

    private function pathDirectory(string $path): ?string
    {
        $directory = trim(str_replace('\\', '/', dirname($path)), '.');

        return $directory === '' ? null : $directory.'/';
    }

    /**
     * P1 — Anticipatory Reality. Predict the immune outcome of a PROPOSED,
     * not-yet-written artifact BEFORE the write, so the AI decides with foresight.
     * Pure read-only: reuses the same primitives impact() uses on existing targets
     * (code reality, authority graph, implementation-truth drift, indexed symbols)
     * but applied to a hypothetical artifact that does not exist yet. It predicts,
     * it never authorizes the write — the real change still passes the gates.
     *
     * @param  array<string,mixed>  $proposed  {kind, slug?, graph_id?, owner?, capabilities?, governs?, implementation_state?, evidence_refs?, symbol_name?, target?}
     * @return array<string,mixed>
     */
    public function simulate(array $proposed): array
    {
        $kind = strtolower(trim((string) ($proposed['kind'] ?? 'doc')));
        if (! in_array($kind, ['doc', 'symbol'], true)) {
            $kind = 'doc';
        }

        try {
            $wouldDuplicate = $kind === 'symbol'
                ? $this->predictSymbolDuplication($proposed)
                : $this->predictDocDuplication($proposed);
            $degraded = (bool) ($wouldDuplicate['degraded'] ?? false);

            $drift = $kind === 'doc' ? $this->predictDrift($proposed) : null;
            $wouldDrift = is_array($drift) && ($drift['drift'] ?? false) === true;

            $owner = $kind === 'doc' ? $this->predictOwner($proposed) : null;
            $blastRadius = $this->predictBlastRadius($proposed);

            $needsOwnerReview = $kind === 'doc' && ! $this->ownerResolved($owner);

            $verdict = $this->predictVerdict($wouldDuplicate, $wouldDrift, $needsOwnerReview, $degraded);
            $blockers = $this->predictBlockers($kind, $wouldDuplicate, $drift, $needsOwnerReview, $degraded);
        } catch (\Throwable $e) {
            // Fail-SAFE: a predictive gate must never answer "clean" on an internal
            // error. Hand the proposal to a human instead.
            return $this->envelope([
                'schema_version' => self::PREDICTIVE_SCHEMA_VERSION,
                'action' => 'simulate',
                'proposed' => ['kind' => $kind],
                'prediction' => ['verdict' => 'needs_review', 'error' => true],
                'mode' => 'pre_write_anticipatory_prediction',
                'blockers' => [['reason' => 'predicted_simulation_error', 'detail' => class_basename($e)]],
            ]);
        }

        return $this->envelope([
            'schema_version' => self::PREDICTIVE_SCHEMA_VERSION,
            'action' => 'simulate',
            'proposed' => [
                'kind' => $kind,
                'slug' => isset($proposed['slug']) ? (string) $proposed['slug'] : null,
                'graph_id' => isset($proposed['graph_id']) ? (string) $proposed['graph_id'] : null,
                'symbol_name' => isset($proposed['symbol_name']) ? (string) $proposed['symbol_name'] : null,
                'owner' => isset($proposed['owner']) ? (string) $proposed['owner'] : null,
                'implementation_state' => isset($proposed['implementation_state']) ? (string) $proposed['implementation_state'] : null,
                'capabilities' => $this->mergedUniqueStrings((array) ($proposed['capabilities'] ?? [])),
                'governs' => $this->mergedUniqueStrings((array) ($proposed['governs'] ?? [])),
            ],
            'prediction' => [
                'verdict' => $verdict,
                'would_duplicate' => $wouldDuplicate,
                'would_drift' => $wouldDrift,
                'drift_detail' => $drift === null ? null : $this->compactDrift($drift),
                'owner' => $owner,
                'blast_radius' => $blastRadius,
                'degraded' => $degraded,
            ],
            'mode' => 'pre_write_anticipatory_prediction',
            'blockers' => $blockers,
        ]);
    }

    /**
     * Predict duplication for a PROPOSED doc: a graph_id collision against an
     * existing canonical doc, plus capability/governs overlap with an existing
     * owner doc (via the authority graph). Read-only over the live corpus + graph.
     *
     * @param  array<string,mixed>  $proposed
     * @return array<string,mixed>
     */
    public function predictDocDuplication(array $proposed): array
    {
        return $this->simulationPredictor()->predictDocDuplication($proposed);
    }

    public function predictSymbolDuplication(array $proposed): array
    {
        return $this->simulationPredictor()->predictSymbolDuplication($proposed);
    }

    public function predictDrift(array $proposed): ?array
    {
        return $this->simulationPredictor()->predictDrift($proposed);
    }

    public function predictOwner(array $proposed): ?array
    {
        return $this->simulationPredictor()->predictOwner($proposed);
    }

    public function predictBlastRadius(array $proposed): array
    {
        return $this->simulationPredictor()->predictBlastRadius($proposed);
    }

    public function predictVerdict(array $duplicate, bool $wouldDrift, bool $needsOwnerReview, bool $degraded): string
    {
        return $this->simulationPredictor()->predictVerdict($duplicate, $wouldDrift, $needsOwnerReview, $degraded);
    }

    public function predictBlockers(string $kind, array $duplicate, ?array $drift, bool $needsOwnerReview, bool $degraded): array
    {
        return $this->simulationPredictor()->predictBlockers($kind, $duplicate, $drift, $needsOwnerReview, $degraded);
    }

    public function graphIdCollisions(string $graphId, string $slug): array
    {
        return $this->simulationPredictor()->graphIdCollisions($graphId, $slug);
    }

    public function locateBest(string $needle): array
    {
        return $this->simulationPredictor()->locateBest($needle);
    }

    private function simulationPredictor(): AtlasSoftwareTwinSimulationPredictor
    {
        return $this->simulationPredictorInstance ??= (function (): AtlasSoftwareTwinSimulationPredictor {
            $predictor = new AtlasSoftwareTwinSimulationPredictor(
                $this->implementationTruth,
                $this->authorityGraph,
                $this->frontmatter,
                fn (array ...$items): array => $this->mergedUniqueStrings(...$items),
            );
            $predictor->bindRuntimeHelpers(
                fn (string $target): ?string => $this->resolveTarget($target),
                fn (string $target): array => $this->impact($target),
            );

            return $predictor;
        })();
    }



    /**
     * @param  array<string,mixed>|null  $owner
     */
    private function ownerResolved(?array $owner): bool
    {
        return is_array($owner)
            && ($owner['resolved'] ?? false) === true
            && (int) ($owner['confidence'] ?? 0) >= self::OWNER_CONFIDENCE_FLOOR;
    }



    /**
     * @param  array<string,mixed>  $drift
     * @return array<string,mixed>
     */
    private function compactDrift(array $drift): array
    {
        return [
            'drift' => (bool) ($drift['drift'] ?? false),
            'claimed_state' => $drift['claimed_state'] ?? null,
            'computed_state' => $drift['computed_state'] ?? null,
            'unmet_evidence' => (array) ($drift['unmet_evidence'] ?? []),
            'resolved' => (array) ($drift['resolved'] ?? []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function contextEnvelope(string $task, string $target = ''): array
    {
        $impact = $target !== '' ? $this->impact($target) : null;
        $contextPack = $this->codeReality->contextPack($task);

        return $this->envelope([
            'schema_version' => self::CONTEXT_SCHEMA_VERSION,
            'action' => 'context-envelope',
            'task' => trim($task),
            'target' => trim($target),
            'provider_safe' => true,
            'minimal_sources' => $this->mergedUniqueStrings(
                (array) ($contextPack['minimal_sources'] ?? []),
                [
                    'docs/engineering-knowledge-base/code-intelligence.md',
                    'docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md',
                    'docs/engineering-knowledge-base/atlas-verified-execution-runtime.md',
                ],
                $impact !== null ? (array) data_get($impact, 'impact.owner_docs', []) : [],
            ),
            'required_tests' => $impact !== null ? (array) data_get($impact, 'impact.required_tests', []) : [],
            'required_gates' => $this->mergedUniqueStrings(
                (array) ($contextPack['required_commands'] ?? []),
                $impact !== null ? (array) data_get($impact, 'impact.required_gates', []) : [],
            ),
            'do_not_claim' => [
                'ASTR_complete_without_runtime_tests',
                'AVEOR_complete_without_boundary_and_proof_plan',
                'safe_to_edit_without_boundary_contract',
                'safe_to_delete_without_acrui_quarantine',
            ],
            'blockers' => [],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function qualityScore(): array
    {
        $nodes = $this->coreNodes();
        $edges = $this->edges($nodes);

        return $this->envelope([
            'schema_version' => self::QUALITY_SCHEMA_VERSION,
            'action' => 'quality-score',
            'quality_score' => $this->qualityFrom($nodes, $edges),
            'coverage' => [
                'node_count' => count($nodes),
                'edge_count' => count($edges),
                'core_targets' => array_keys(self::CORE_TARGETS),
            ],
            'blockers' => [],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(string $target = ''): array
    {
        $twin = $this->twin($target);
        $payload = [
            'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'status' => $twin['status'] ?? 'blocked',
            'target' => trim($target),
            'target_path' => $twin['target_path'] ?? null,
            'software_twin' => $twin['software_twin'] ?? [],
            'quality_score' => $twin['quality_score'] ?? [],
            'blockers' => $twin['blockers'] ?? [],
            'claim_policy' => $twin['claim_policy'] ?? [],
        ];
        $payload['snapshot_hash'] = MissionCanonicalHash::sha256($payload);

        if (DatabaseTableAvailability::has('atlas_software_twin_snapshots')) {
            $record = AtlasSoftwareTwinSnapshot::query()->create($payload);
            $payload['snapshot_id'] = (string) $record->id;
            $payload['writes'] = true;
        } else {
            $payload['snapshot_id'] = null;
            $payload['writes'] = false;
        }

        return $payload;
    }

    /**
     * Quality Foundry façade over existing Software Twin/AURG/ledger facts.
     * It freezes only provider-safe references; source payloads remain in their owners.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function freezeQualityFoundryFacts(array $input): array
    {
        $workspace = trim((string) ($input['workspace_id'] ?? ''));
        $baseCommit = trim((string) ($input['base_commit'] ?? ''));
        $consumer = trim((string) ($input['consumer'] ?? ''));
        $asOf = trim((string) ($input['as_of'] ?? ''));
        if ($workspace === '' || preg_match('/^[a-f0-9]{40,64}$/', $baseCommit) !== 1 || $consumer === '' || $asOf === '') {
            throw new InvalidArgumentException('software_twin_quality_snapshot_context_invalid');
        }
        $asOfTime = date_create_immutable($asOf);
        if ($asOfTime === false) {
            throw new InvalidArgumentException('software_twin_quality_snapshot_as_of_invalid');
        }
        $supported = ['code','contract','deploy_runtime','flag','incident','ownership','outcome','performance','security','docs','decision','concurrent_work','tool_provider'];
        $facts = [];
        $byId = [];
        foreach ((array) ($input['facts'] ?? []) as $fact) {
            if (! is_array($fact)) throw new InvalidArgumentException('software_twin_quality_snapshot_fact_invalid');
            $id = trim((string) ($fact['id'] ?? ''));
            if (($fact['workspace_id'] ?? null) !== $workspace) throw new InvalidArgumentException('software_twin_quality_snapshot_workspace_leak');
            if ($id === '' || ! in_array($fact['type'] ?? null, $supported, true) || trim((string) ($fact['source'] ?? '')) === '' || preg_match('/^[a-f0-9]{64}$/', (string) ($fact['hash'] ?? '')) !== 1) {
                throw new InvalidArgumentException('software_twin_quality_snapshot_fact_provenance_invalid');
            }
            if (! self::validTimestamp($fact['valid_from'] ?? null)
                || ! array_key_exists('valid_until', $fact)
                || ($fact['valid_until'] !== null && ! self::validTimestamp($fact['valid_until']))
                || ! self::validTimestamp($fact['observed_at'] ?? null)) {
                throw new InvalidArgumentException('software_twin_quality_snapshot_fact_temporal_provenance_invalid');
            }
            $status = (string) ($fact['status'] ?? 'unknown');
            if (! in_array($status, ['fresh','stale','unknown','conflicted'], true)) throw new InvalidArgumentException('software_twin_quality_snapshot_freshness_invalid');
            $validFrom = date_create_immutable((string) $fact['valid_from']);
            $validUntil = $fact['valid_until'] === null ? null : date_create_immutable((string) $fact['valid_until']);
            $observedAt = date_create_immutable((string) $fact['observed_at']);
            if ($validFrom > $asOfTime || $observedAt > $asOfTime) {
                $status = 'unknown';
            } elseif ($validUntil !== null && $validUntil < $asOfTime) {
                $status = 'stale';
            }
            $ref = ['id' => $id, 'type' => (string) $fact['type'], 'workspace_id' => $workspace, 'source' => (string) $fact['source'], 'hash' => (string) $fact['hash'], 'status' => $status,
                'valid_from' => $fact['valid_from'] ?? null, 'valid_until' => $fact['valid_until'] ?? null, 'observed_at' => $fact['observed_at'] ?? null];
            $byId[$id][] = $ref;
        }
        $conflicted = [];
        foreach ($byId as $id => $versions) {
            $hashes = array_values(array_unique(array_column($versions, 'hash')));
            if (count($hashes) > 1) { $conflicted[] = $id; foreach ($versions as &$version) $version['status'] = 'conflicted'; unset($version); }
            foreach ($versions as $version) $facts[] = $version;
        }
        usort($facts, static fn (array $a, array $b): int => [$a['id'], $a['hash']] <=> [$b['id'], $b['hash']]);
        $unknown = array_values(array_unique(array_column(array_filter($facts, static fn (array $f): bool => $f['status'] === 'unknown'), 'id')));
        $stale = array_values(array_unique(array_column(array_filter($facts, static fn (array $f): bool => $f['status'] === 'stale'), 'id')));
        $predictionCalibration = $input['prediction_calibration'] ?? null;
        if ($predictionCalibration !== null && ! is_array($predictionCalibration)) {
            throw new InvalidArgumentException('software_twin_quality_snapshot_prediction_calibration_invalid');
        }
        if (is_array($predictionCalibration) && ($predictionCalibration['claim_eligible'] ?? false) !== false) {
            throw new InvalidArgumentException('software_twin_quality_snapshot_prediction_calibration_claim_forbidden');
        }
        $unresolvedPredictions = $input['unresolved_predictions'] ?? [];
        if (! is_array($unresolvedPredictions)) {
            throw new InvalidArgumentException('software_twin_quality_snapshot_unresolved_predictions_invalid');
        }
        $freshnessPolicy = array_fill_keys($supported, ['requires_validity_window' => true, 'requires_observed_at' => true, 'unknown_on_future_or_missing' => true, 'stale_on_expiry' => true]);
        $payload = ['schema_version' => 'atlas.quality_foundry.software_twin_snapshot.v1', 'reference_envelope' => 'atlas.quality_foundry.reference.v1', 'workspace_id' => $workspace, 'base_commit' => $baseCommit, 'as_of' => $asOf, 'consumer' => $consumer, 'facts' => $facts, 'unknown' => $unknown, 'stale' => $stale, 'conflicted' => array_values(array_unique($conflicted)), 'freshness_policy' => $freshnessPolicy, 'prediction_calibration' => $predictionCalibration, 'unresolved_predictions' => array_values($unresolvedPredictions), 'claim_policy' => ['read_only' => true, 'claim_eligible' => false]];
        $payload['snapshot_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    private static function validTimestamp(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') return false;

        return date_create_immutable($value) !== false;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function coreNodes(): array
    {
        return array_map(fn (string $path, string $id): array => $this->node($id, $path), self::CORE_TARGETS, array_keys(self::CORE_TARGETS));
    }

    /**
     * @return array<string,mixed>
     */
    private function node(string $id, string $path): array
    {
        $reality = $this->codeReality->classify($path);

        return [
            'id' => $id,
            'path' => $path,
            'exists' => File::exists(base_path($path)),
            'classification' => $reality['classification'] ?? 'unknown_requires_audit',
            'reachability_status' => data_get($reality, 'evidence.reachability.status'),
            'reachability_confidence' => data_get($reality, 'evidence.reachability.confidence'),
            'owner_doc_count' => count((array) data_get($reality, 'evidence.owner_docs', [])),
            'test_count' => count((array) data_get($reality, 'evidence.tests', [])),
            'entrypoint_count' => count((array) data_get($reality, 'evidence.entrypoints', [])),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<int,array<string,string>>
     */
    private function edges(array $nodes): array
    {
        $ids = array_column($nodes, 'id');
        $edges = [
            ['from' => 'acir', 'to' => 'acrui', 'kind' => 'feeds_operational_reality'],
            ['from' => 'acrui', 'to' => 'astr', 'kind' => 'feeds_runtime_usage_truth'],
            ['from' => 'adrs', 'to' => 'astr', 'kind' => 'feeds_documentation_truth'],
            ['from' => 'aurc', 'to' => 'astr', 'kind' => 'projects_human_map'],
            ['from' => 'astr', 'to' => 'aver', 'kind' => 'informs_verified_execution'],
            ['from' => 'aver', 'to' => 'aemor', 'kind' => 'feeds_outcome_learning'],
        ];

        return array_values(array_filter(
            $edges,
            static fn (array $edge): bool => in_array($edge['from'], $ids, true) && in_array($edge['to'], $ids, true)
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @param  array<int,array<string,string>>  $edges
     * @return array<string,mixed>
     */
    private function qualityFrom(array $nodes, array $edges): array
    {
        $readyNodes = count(array_filter($nodes, static fn (array $node): bool => $node['exists'] && in_array($node['classification'], ['active_runtime', 'active_read_only', 'headless_available'], true)));
        $ownerNodes = count(array_filter($nodes, static fn (array $node): bool => ((int) $node['owner_doc_count']) > 0));
        $testedNodes = count(array_filter($nodes, static fn (array $node): bool => ((int) $node['test_count']) > 0));
        $total = max(count($nodes), 1);
        $score = (int) round((($readyNodes / $total) * 40) + (($ownerNodes / $total) * 25) + (($testedNodes / $total) * 25) + (min(count($edges), 6) / 6 * 10));

        return [
            'schema_version' => self::QUALITY_SCHEMA_VERSION,
            'score' => $score,
            'status' => $score >= 80 ? 'ready' : 'review',
            'ready_node_count' => $readyNodes,
            'owner_doc_node_count' => $ownerNodes,
            'tested_node_count' => $testedNodes,
            'edge_count' => count($edges),
            'quality_floor' => 80,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @param  array<string,mixed>  $quality
     * @return array<int,array<string,string>>
     */
    private function blockers(array $nodes, array $quality): array
    {
        $blockers = [];
        foreach ($nodes as $node) {
            if (! $node['exists']) {
                $blockers[] = ['reason' => 'core_twin_target_missing', 'path' => (string) $node['path']];
            }
        }
        if (($quality['status'] ?? null) !== 'ready') {
            $blockers[] = ['reason' => 'software_twin_quality_below_floor', 'score' => (string) ($quality['score'] ?? 0)];
        }

        return $blockers;
    }

    private function resolveTarget(string $target): ?string
    {
        if ($target === '') {
            return null;
        }
        if (File::exists(base_path($target))) {
            return $target;
        }
        foreach (self::CORE_TARGETS as $path) {
            if (str_contains($path, $target) || str_contains(class_basename($path), $target)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null  $reality
     * @return array<string,mixed>|null
     */
    private function compactReality(?array $reality): ?array
    {
        if ($reality === null) {
            return null;
        }

        return [
            'classification' => $reality['classification'] ?? null,
            'target_path' => $reality['target_path'] ?? null,
            'reachability_status' => data_get($reality, 'evidence.reachability.status'),
            'reachability_confidence' => data_get($reality, 'evidence.reachability.confidence'),
        ];
    }

    /**
     * @param  array<string,mixed>  $reachability
     * @param  array<int,string>  $tests
     * @param  array<int,string>  $ownerDocs
     */
    private function riskLevel(string $classification, array $reachability, array $tests, array $ownerDocs): string
    {
        if (! in_array($classification, ['active_runtime', 'active_read_only', 'headless_available'], true)) {
            return 'high';
        }
        if (($reachability['confidence'] ?? null) !== 'high') {
            return 'medium';
        }
        if ($tests === [] || $ownerDocs === []) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param  array<int,mixed>  ...$groups
     * @return array<int,string>
     */
    private function mergedUniqueStrings(array ...$groups): array
    {
        return EngineeringStringListNormalizer::uniqueNonEmptyStrings(array_merge(...$groups));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function envelope(array $payload): array
    {
        $base = array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => (($payload['blockers'] ?? []) === []) ? 'ready' : 'blocked',
            'writes' => false,
            'claim_policy' => [
                'read_only' => true,
                'providers_invoked' => false,
                'rivals_run' => false,
                'executes_commands' => false,
                'authorizes_mutation' => false,
                'software_twin_complete_claimed' => false,
            ],
        ], $payload);
        $hashPayload = $base;
        unset($hashPayload['certification_hash']);
        $base['certification_hash'] = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $base;
    }
}
