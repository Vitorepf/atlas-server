<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Models\AtlasDocsAuthorityGraph;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasSoftwareTwinSnapshot;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Ai\Aaeos\AtlasDocsAuthorityGraphService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Support\Facades\File;
use SplFileInfo;

// Intentionally NOT final: this read-only predictive service is designed to be
// injected and wrapped (e.g. by the L2-O2 intent advisory), and downstream tests
// mock simulate() for determinism — mirroring the non-final, mockable convention
// of the other injected truth services (e.g. AtlasAaeosImplementationTruthService).
class AtlasSoftwareTwinRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.software_twin.v1';

    public const IMPACT_SCHEMA_VERSION = 'atlas.software_twin.impact.v1';

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
        private readonly AtlasAaeosImplementationTruthService $implementationTruth,
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
                'capabilities' => array_values(array_map('strval', (array) ($proposed['capabilities'] ?? []))),
                'governs' => array_values(array_map('strval', (array) ($proposed['governs'] ?? []))),
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
    private function predictDocDuplication(array $proposed): array
    {
        $graphId = trim((string) ($proposed['graph_id'] ?? ''));
        $slug = trim((string) ($proposed['slug'] ?? ''));
        $collisions = $this->graphIdCollisions($graphId, $slug);

        $needles = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            array_merge((array) ($proposed['capabilities'] ?? []), (array) ($proposed['governs'] ?? [])),
        ))));

        // Capability/governs overlap needs the authority-graph read model. If it is
        // unbuilt we cannot prove "no overlap" — fail SAFE (degraded) rather than
        // report a false clean.
        $graphReady = DatabaseTableAvailability::has('atlas_docs_authority_graph')
            && AtlasDocsAuthorityGraph::query()->limit(1)->exists();
        $degraded = $needles !== [] && ! $graphReady;

        $overlap = [];
        if ($graphReady) {
            // Scan ALL needles (no positional cap): a colliding capability must
            // never be skipped just because it was declared late in the list.
            foreach ($needles as $needle) {
                if ($needle === '') {
                    continue;
                }
                $located = $this->locateBest($needle);
                // Only an AUTHORITATIVE match counts (a broad keyword_fallback is too
                // weak to assert duplication); below the floor is neither an overlap
                // nor a false clean — just not a confident duplicate.
                if (($located['resolved'] ?? false) !== true
                    || (int) ($located['confidence'] ?? 0) < self::OWNER_CONFIDENCE_FLOOR) {
                    continue;
                }
                // A proposed doc whose OWN id is the resolved owner is not an overlap
                // with a *different* doc — skip self/identity matches by graph_id.
                $ownerId = (string) ($located['owner_doc_id'] ?? '');
                if ($graphId !== '' && strtolower($ownerId) === strtolower($graphId)) {
                    continue;
                }
                $overlap[] = [
                    'needle' => $needle,
                    'owner_doc_path' => (string) ($located['owner_doc_path'] ?? ''),
                    'owner_doc_id' => $ownerId !== '' ? $ownerId : null,
                    'owner_basis' => (string) ($located['owner_basis'] ?? ''),
                    'confidence' => (int) ($located['confidence'] ?? 0),
                ];
            }
        }

        $duplicate = $collisions !== [] || $overlap !== [];

        return [
            'kind' => 'doc',
            'duplicate' => $duplicate,
            'degraded' => $degraded,
            'reason' => $collisions !== []
                ? 'graph_id_collision'
                : ($overlap !== [] ? 'capability_or_governs_overlap' : ($degraded ? 'authority_graph_unavailable' : 'none')),
            'graph_id_collisions' => $collisions,
            'capability_overlap' => $overlap,
        ];
    }

    /**
     * Scan existing canonical docs' frontmatter graph_id values and return any
     * that equal the proposed graph_id (or slug) — a collision means a second doc
     * would claim an already-owned canonical id.
     *
     * @return array<int,array<string,string>>
     */
    private function graphIdCollisions(string $graphId, string $slug): array
    {
        $candidate = $graphId !== '' ? $graphId : $slug;
        if ($candidate === '') {
            return [];
        }
        $candidateLc = mb_strtolower($candidate);

        $root = base_path('docs/engineering-knowledge-base');
        if (! File::isDirectory($root)) {
            return [];
        }

        $collisions = [];
        foreach (File::allFiles($root) as $file) {
            /** @var SplFileInfo $file */
            if (strtolower($file->getExtension()) !== 'md'
                || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'archive'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $parsed = $this->frontmatter->parse((string) File::get($file->getPathname()));
            $fm = is_array($parsed['frontmatter'] ?? null) ? $parsed['frontmatter'] : [];
            $existingGraphId = trim((string) ($fm['graph_id'] ?? ''));
            $existingId = trim((string) ($fm['id'] ?? ''));
            if (mb_strtolower($existingGraphId) === $candidateLc || mb_strtolower($existingId) === $candidateLc) {
                $collisions[] = [
                    'graph_id' => $candidate,
                    'existing_doc' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()),
                ];
            }
        }

        return $collisions;
    }

    /**
     * Predict duplication for a PROPOSED symbol: existing indexed symbols with the
     * same or boundary-matching name (exact, \FQN suffix or ::method suffix),
     * mirroring how the evidence resolver decides a symbol "exists".
     *
     * @param  array<string,mixed>  $proposed
     * @return array<string,mixed>
     */
    private function predictSymbolDuplication(array $proposed): array
    {
        $name = trim((string) ($proposed['symbol_name'] ?? ''));
        if ($name === '') {
            return [
                'kind' => 'symbol',
                'duplicate' => false,
                'degraded' => false,
                'reason' => 'no_symbol_name_provided',
                'symbol_collisions' => [],
            ];
        }
        if (! DatabaseTableAvailability::has('atlas_engineering_code_symbols')) {
            // Fail-SAFE: the symbol index is a read model built by a separate sync
            // step. If it is absent we CANNOT prove the symbol is new, so we never
            // claim "clean" — the verdict degrades to needs_review.
            return [
                'kind' => 'symbol',
                'duplicate' => false,
                'degraded' => true,
                'reason' => 'symbol_index_unavailable',
                'symbol_collisions' => [],
            ];
        }

        // Fail-SAFE on an UNBUILT index: the table can exist (migrated) yet hold 0
        // rows (index-code is AWIS-gated and may never have run; the indexer only
        // upserts/archives, never truncates). An empty index cannot prove the
        // symbol is new, so degrade rather than answer clean.
        if (! AtlasEngineeringCodeSymbol::query()->limit(1)->exists()) {
            return [
                'kind' => 'symbol',
                'duplicate' => false,
                'degraded' => true,
                'reason' => 'symbol_index_empty',
                'symbol_collisions' => [],
            ];
        }

        // Do the boundary match (exact, \namespace-suffix, ::method-suffix) IN SQL
        // so a real collision beyond any arbitrary row cap is never missed, and use
        // ESCAPE '!' so a backslash in an FQN stays literal (pgsql otherwise treats
        // \ as its default LIKE escape and would silently drop FQN matches).
        $nameLc = mb_strtolower($name);
        $needle = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $nameLc);
        $candidates = AtlasEngineeringCodeSymbol::query()
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->whereIn('symbol_type', ['class', 'method', 'trait', 'interface', 'enum'])
            ->where(function ($query) use ($nameLc, $needle): void {
                $query->whereRaw('LOWER(symbol_name) = ?', [$nameLc])
                    ->orWhereRaw("LOWER(symbol_name) LIKE ? ESCAPE '!'", ['%\\'.$needle])
                    ->orWhereRaw("LOWER(symbol_name) LIKE ? ESCAPE '!'", ['%::'.$needle]);
            })
            ->limit(50)
            ->get(['symbol_name', 'symbol_type', 'file_path']);

        $collisions = [];
        foreach ($candidates as $symbol) {
            $collisions[] = [
                'symbol_name' => (string) $symbol->symbol_name,
                'symbol_type' => (string) $symbol->symbol_type,
                'file_path' => (string) $symbol->file_path,
            ];
        }

        return [
            'kind' => 'symbol',
            'duplicate' => $collisions !== [],
            'degraded' => false,
            'reason' => $collisions !== [] ? 'symbol_name_collision' : 'none',
            'symbol_collisions' => array_slice($collisions, 0, 12),
        ];
    }

    /**
     * Predict drift for a PROPOSED doc that declares an implementation_state:
     * reuse the implementation-truth drift verdict straight from the proposed
     * frontmatter (claiming partial/verified with empty/unresolvable evidence_refs
     * => would_drift).
     *
     * @param  array<string,mixed>  $proposed
     * @return array<string,mixed>|null
     */
    private function predictDrift(array $proposed): ?array
    {
        $state = trim((string) ($proposed['implementation_state'] ?? ''));
        if ($state === '') {
            return null;
        }

        return $this->implementationTruth->driftForFrontmatter($state, $proposed['evidence_refs'] ?? null);
    }

    /**
     * Predict the owner doc/area that governs the proposed capability/governs/owner
     * via the authority graph. Returns the first resolving needle's locate() result.
     *
     * @param  array<string,mixed>  $proposed
     * @return array<string,mixed>|null
     */
    private function predictOwner(array $proposed): ?array
    {
        $needles = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            array_merge(
                (array) ($proposed['governs'] ?? []),
                (array) ($proposed['capabilities'] ?? []),
                array_filter([(string) ($proposed['owner'] ?? '')]),
            ),
        ))));

        $weak = null;
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }
            $located = $this->locateBest($needle);
            if (($located['resolved'] ?? false) === true
                && (int) ($located['confidence'] ?? 0) >= self::OWNER_CONFIDENCE_FLOOR) {
                return $located;
            }
            if ($weak === null && ($located['resolved'] ?? false) === true) {
                $weak = $located; // a low-confidence match, kept only as context
            }
        }

        return $weak ?? (empty($needles) ? null : $this->locateBest($needles[0]));
    }

    /**
     * Locate an owner for a needle, trying the raw form plus snake_case and
     * kebab-case variants, returning the highest-confidence resolved result. This
     * catches a capability owned under a different separator form (e.g.
     * documentation-reality-scoring vs documentation_reality_scoring) that an exact
     * lookup would miss.
     *
     * @return array<string,mixed>
     */
    private function locateBest(string $needle): array
    {
        $variants = array_values(array_unique(array_filter([
            $needle,
            strtolower(str_replace([' ', '-'], '_', $needle)),
            strtolower(str_replace([' ', '_'], '-', $needle)),
        ], static fn (string $v): bool => trim($v) !== '')));

        $best = ['resolved' => false, 'confidence' => 0];
        foreach ($variants as $variant) {
            $located = $this->authorityGraph->locate($variant, 3);
            if (($located['resolved'] ?? false) === true
                && (int) ($located['confidence'] ?? 0) > (int) ($best['confidence'] ?? 0)) {
                $best = $located;
            }
        }

        return $best;
    }

    /**
     * If the proposal names/extends an existing target, reuse the impact()
     * reachability/edges as the blast radius; else empty (a brand-new artifact
     * touches nothing yet).
     *
     * @param  array<string,mixed>  $proposed
     * @return array<string,mixed>
     */
    private function predictBlastRadius(array $proposed): array
    {
        $target = trim((string) ($proposed['target'] ?? ($proposed['extends'] ?? '')));
        if ($target === '' || $this->resolveTarget($target) === null) {
            return [
                'resolved' => false,
                'target' => $target !== '' ? $target : null,
                'reachable' => false,
                'affected_edges' => [],
                'owner_docs' => [],
            ];
        }

        $impact = $this->impact($target);

        return [
            'resolved' => true,
            'target' => $target,
            'target_path' => $impact['target_path'] ?? null,
            'risk_level' => data_get($impact, 'impact.risk_level'),
            'reachable' => (bool) data_get($impact, 'impact.reachable', false),
            'affected_edges' => (array) data_get($impact, 'impact.affected_edges', []),
            'owner_docs' => (array) data_get($impact, 'impact.owner_docs', []),
        ];
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
     * @param  array<string,mixed>  $duplicate
     */
    private function predictVerdict(array $duplicate, bool $wouldDrift, bool $needsOwnerReview, bool $degraded): string
    {
        if (($duplicate['duplicate'] ?? false) === true) {
            return 'would_duplicate';
        }
        if ($wouldDrift) {
            return 'would_drift';
        }
        if ($degraded) {
            // A duplication check could not run (index/graph unavailable). Never
            // claim clean on a blind check — fail safe to a human.
            return 'needs_review';
        }
        if ($needsOwnerReview) {
            return 'needs_owner_review';
        }

        return 'clean';
    }

    /**
     * @param  array<string,mixed>  $duplicate
     * @param  array<string,mixed>|null  $drift
     * @return array<int,array<string,mixed>>
     */
    private function predictBlockers(string $kind, array $duplicate, ?array $drift, bool $needsOwnerReview, bool $degraded): array
    {
        $blockers = [];
        if (($duplicate['duplicate'] ?? false) === true) {
            $blockers[] = [
                'reason' => 'predicted_duplicate_'.$kind,
                'detail' => (string) ($duplicate['reason'] ?? 'overlap'),
            ];
        }
        if ($degraded) {
            $blockers[] = [
                'reason' => 'predicted_check_degraded',
                'detail' => (string) ($duplicate['reason'] ?? 'index_or_graph_unavailable'),
            ];
        }
        if (is_array($drift) && ($drift['drift'] ?? false) === true) {
            $blockers[] = [
                'reason' => 'predicted_implementation_state_over_claim',
                'detail' => 'claimed_'.(string) ($drift['claimed_state'] ?? 'unknown').'_computes_'.(string) ($drift['computed_state'] ?? 'spec'),
            ];
        }
        if ($needsOwnerReview) {
            $blockers[] = [
                'reason' => 'predicted_missing_or_ambiguous_owner',
                'detail' => 'no_authority_graph_owner_for_proposed_capability_or_governs',
            ];
        }

        return $blockers;
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
            'minimal_sources' => array_values(array_unique(array_merge(
                (array) ($contextPack['minimal_sources'] ?? []),
                [
                    'docs/engineering-knowledge-base/code-intelligence.md',
                    'docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md',
                    'docs/engineering-knowledge-base/atlas-verified-execution-runtime.md',
                ],
                $impact !== null ? (array) data_get($impact, 'impact.owner_docs', []) : [],
            ))),
            'required_tests' => $impact !== null ? (array) data_get($impact, 'impact.required_tests', []) : [],
            'required_gates' => array_values(array_unique(array_merge(
                (array) ($contextPack['required_commands'] ?? []),
                $impact !== null ? (array) data_get($impact, 'impact.required_gates', []) : [],
            ))),
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
