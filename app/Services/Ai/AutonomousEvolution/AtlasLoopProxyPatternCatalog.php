<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * Frozen, append-only catalog of every NAMED proxy/Goodhart pattern the Loop refuses. Each entry is an
 * immutable struct {id, family, detector_fqn, fact_keys, canonical_example_ref, doc_anchor}.
 *
 * Families:
 *   refactor       — behavior-preserving edit that bites nothing (e.g. extract-method without coverage)
 *   cosmetic       — whitespace/comment/docblock-only edits
 *   metric_proxy   — proxy metric shrink (cyclomatic, line count) without bite-proof
 *   paraphrase     — semantic-equivalent atom restated, acceptance unchanged
 *   farm           — characterization test farmed to manufacture green
 *   self_serve     — self-edit of the loop's own judge/scope
 *
 * Contract:
 *   - all() is frozen — new entries are APPENDED in code, never removed/edited.
 *   - ids() is the SINGLE source of pattern_id strings used by AtlasLoopAntiGoodhartUnifiedRefusal.
 *   - byId(id) returns the entry or null. Entries are immutable (final readonly).
 */
final class AtlasLoopProxyPatternCatalog
{
    public const FAMILY_REFACTOR = 'refactor';

    public const FAMILY_COSMETIC = 'cosmetic';

    public const FAMILY_METRIC_PROXY = 'metric_proxy';

    public const FAMILY_PARAPHRASE = 'paraphrase';

    public const FAMILY_FARM = 'farm';

    public const FAMILY_SELF_SERVE = 'self_serve';

    public const VALID_FAMILIES = [
        self::FAMILY_REFACTOR,
        self::FAMILY_COSMETIC,
        self::FAMILY_METRIC_PROXY,
        self::FAMILY_PARAPHRASE,
        self::FAMILY_FARM,
        self::FAMILY_SELF_SERVE,
    ];

    /**
     * @return list<AtlasLoopProxyPatternCatalogEntry>
     */
    public function all(): array
    {
        return [
            new AtlasLoopProxyPatternCatalogEntry(
                id: 'behaviour-preserving-refactor',
                family: self::FAMILY_REFACTOR,
                detector_fqn: 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopRealWorkScorecardService',
                fact_keys: ['edit_kind', 'mutation_kills', 'characterization_diff'],
                canonical_example_ref: 'docs/loop-proxy-pattern-catalog.md#behaviour-preserving-refactor',
                doc_anchor: 'behaviour-preserving-refactor',
            ),
            new AtlasLoopProxyPatternCatalogEntry(
                id: 'whitespace-only',
                family: self::FAMILY_COSMETIC,
                detector_fqn: 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopRealWorkScorecardService',
                fact_keys: ['edit_kind', 'non_whitespace_delta_chars'],
                canonical_example_ref: 'docs/loop-proxy-pattern-catalog.md#whitespace-only',
                doc_anchor: 'whitespace-only',
            ),
            new AtlasLoopProxyPatternCatalogEntry(
                id: 'comment-only',
                family: self::FAMILY_COSMETIC,
                detector_fqn: 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopRealWorkScorecardService',
                fact_keys: ['edit_kind', 'non_comment_delta_chars'],
                canonical_example_ref: 'docs/loop-proxy-pattern-catalog.md#comment-only',
                doc_anchor: 'comment-only',
            ),
            new AtlasLoopProxyPatternCatalogEntry(
                id: 'cyclomatic-proxy',
                family: self::FAMILY_METRIC_PROXY,
                detector_fqn: 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopRealWorkScorecardService',
                fact_keys: ['metric_kind', 'metric_delta', 'mutation_kills'],
                canonical_example_ref: 'docs/loop-proxy-pattern-catalog.md#cyclomatic-proxy',
                doc_anchor: 'cyclomatic-proxy',
            ),
            new AtlasLoopProxyPatternCatalogEntry(
                id: 'characterization-test-farm',
                family: self::FAMILY_FARM,
                detector_fqn: 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopAntiFarmFloor',
                fact_keys: ['wired_proof', 'production_caller', 'characterization_diff'],
                canonical_example_ref: 'docs/loop-proxy-pattern-catalog.md#characterization-test-farm',
                doc_anchor: 'characterization-test-farm',
            ),
            new AtlasLoopProxyPatternCatalogEntry(
                id: 'self-edit-in-own-judge',
                family: self::FAMILY_SELF_SERVE,
                detector_fqn: 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopWorkspaceMaterializerSupport2',
                fact_keys: ['target_path', 'forbidden_core'],
                canonical_example_ref: 'docs/loop-proxy-pattern-catalog.md#self-edit-in-own-judge',
                doc_anchor: 'self-edit-in-own-judge',
            ),
            new AtlasLoopProxyPatternCatalogEntry(
                id: 'paraphrase-atom',
                family: self::FAMILY_PARAPHRASE,
                detector_fqn: 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopAtomParaphraseAudit',
                fact_keys: ['restated_objective', 'acceptance_unchanged'],
                canonical_example_ref: 'docs/loop-proxy-pattern-catalog.md#paraphrase-atom',
                doc_anchor: 'paraphrase-atom',
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(static fn (AtlasLoopProxyPatternCatalogEntry $e): string => $e->id, $this->all());
    }

    public function byId(string $id): ?AtlasLoopProxyPatternCatalogEntry
    {
        foreach ($this->all() as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }

        return null;
    }
}

/**
 * Immutable catalog entry — `final readonly` so any attempt to mutate a returned entry triggers a
 * TypeError at runtime (covered by a test below).
 */
final readonly class AtlasLoopProxyPatternCatalogEntry
{
    /**
     * @param  list<string>  $fact_keys
     */
    public function __construct(
        public string $id,
        public string $family,
        public string $detector_fqn,
        public array $fact_keys,
        public string $canonical_example_ref,
        public string $doc_anchor,
    ) {}
}
