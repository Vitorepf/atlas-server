<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Models\AtlasDocsAuthorityGraph;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Ai\Aaeos\AtlasDocsAuthorityGraphService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use App\Services\Engineering\EngineeringStringListNormalizer;
use App\Support\DatabaseTableAvailability;
use Closure;
use Illuminate\Support\Facades\File;

/**
 * SIMULATE/PREDICTION concern, extracted from the god-class
 * {@see AtlasSoftwareTwinRuntimeService}.
 *
 * Owns every simulate/prediction method: predictDocDuplication,
 * predictSymbolDuplication, predictDrift, predictOwner,
 * predictBlastRadius, predictVerdict, predictBlockers, graphIdCollisions
 * and locateBest.
 *
 * Services that STAY in the runtime are passed in as constructor
 * dependencies (implementationTruth, authorityGraph, frontmatter).
 * Capabilities that STAY (mergedUniqueStrings) are passed in as Closures —
 * the SAME closure-binding pattern used by AtlasLoopRefillerSupplyLaneCoordinator.
 */
class AtlasSoftwareTwinSimulationPredictor
{
    private const OWNER_CONFIDENCE_FLOOR = 70;

    /**
     * @param  Closure(array<mixed>): array<int,string>  $mergedUniqueStrings
     */
    public function __construct(
        private readonly AtlasAaeosImplementationTruthService $implementationTruth,
        private readonly AtlasDocsAuthorityGraphService $authorityGraph,
        private readonly CanonicalDocsFrontmatterParser $frontmatter,
        private readonly Closure $mergedUniqueStrings,
    ) {}

    public function predictDocDuplication(array $proposed): array
    {
        $graphId = trim((string) ($proposed['graph_id'] ?? ''));
        $slug = trim((string) ($proposed['slug'] ?? ''));
        $collisions = $this->graphIdCollisions($graphId, $slug);

        $needles = ($this->mergedUniqueStrings)(
            (array) ($proposed['capabilities'] ?? []),
            (array) ($proposed['governs'] ?? []),
        );

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

    public function predictSymbolDuplication(array $proposed): array
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

    public function predictDrift(array $proposed): ?array
    {
        $state = trim((string) ($proposed['implementation_state'] ?? ''));
        if ($state === '') {
            return null;
        }

        return $this->implementationTruth->driftForFrontmatter($state, $proposed['evidence_refs'] ?? null);
    }

    public function predictOwner(array $proposed): ?array
    {
        $needles = ($this->mergedUniqueStrings)(
            (array) ($proposed['governs'] ?? []),
            (array) ($proposed['capabilities'] ?? []),
            [(string) ($proposed['owner'] ?? '')],
        );

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

    public function predictBlastRadius(array $proposed): array
    {
        $target = trim((string) ($proposed['target'] ?? ($proposed['extends'] ?? '')));
        $impact = $target !== '' ? $this->impactForTarget($target) : null;

        if ($target === '' || $impact === null) {
            return [
                'resolved' => false,
                'target' => $target !== '' ? $target : null,
                'reachable' => false,
                'affected_edges' => [],
                'owner_docs' => [],
            ];
        }

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
     * Resolve a target via the runtime's resolveTarget() and forward to its impact()
     * — both stay on the runtime, the predictor delegates via these closures
     * so the runtime stays the single owner of code-reality + impact.
     */
    private ?Closure $resolveTargetClosure = null;
    private ?Closure $impactClosure = null;

    public function bindRuntimeHelpers(Closure $resolveTarget, Closure $impact): void
    {
        $this->resolveTargetClosure = $resolveTarget;
        $this->impactClosure = $impact;
    }

    private function impactForTarget(string $target): ?array
    {
        if ($this->resolveTargetClosure === null || $this->impactClosure === null) {
            return null;
        }
        $targetPath = ($this->resolveTargetClosure)($target);
        if ($targetPath === null) {
            return null;
        }

        return ($this->impactClosure)($target);
    }

    public function predictVerdict(array $duplicate, bool $wouldDrift, bool $needsOwnerReview, bool $degraded): string
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

    public function predictBlockers(string $kind, array $duplicate, ?array $drift, bool $needsOwnerReview, bool $degraded): array
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

    public function graphIdCollisions(string $graphId, string $slug): array
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

    public function locateBest(string $needle): array
    {
        $variants = EngineeringStringListNormalizer::uniqueNonEmptyStrings([
            $needle,
            strtolower(str_replace([' ', '-'], '_', $needle)),
            strtolower(str_replace([' ', '_'], '-', $needle)),
        ]);

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
}