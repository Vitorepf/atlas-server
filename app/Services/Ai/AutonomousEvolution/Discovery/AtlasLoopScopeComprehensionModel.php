<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * §5.6 · LAYER 1 — the grounded scope-comprehension MODEL (a pure DESCRIPTIVE artifact).
 *
 * This is the loop's real understanding of its OWN scope (`app/Services/Ai/AutonomousEvolution/`):
 * what components exist, who calls whom, which are built-but-unwired ORPHANS, which are structural
 * CLONES, which are pétreo/FORBIDDEN, and which capabilities the canonical docs DEMAND but no symbol
 * provides. It is the substrate a FRONTIER model reasons over to originate the highest-leverage
 * evolution — replacing the old keyword-overlap "comprehension".
 *
 * THE ANTI-GOODHART INVARIANT (load-bearing — the operator was burned by proxy optimization): this
 * model emits FACTS-AT-SNAPSHOT-T — SETS and per-path descriptive FIELDS — and **NEVER a scalar rank**.
 * It is consumed by NOTHING in the ranking math (AtlasLoopLeverageScorer / strategic_impact stay
 * untouched). The instant a structural role (orphan/clone/...) were encoded as a number the ranker
 * climbs, it would be the cyclomatic proxy reborn one level up. So: no aggregate, no score, no
 * importance ordering here. WHICH grounded fact is the highest-leverage evolution is the frontier
 * model's (honestly model-bound) judgment — never this model's.
 *
 * Provenance is explicit: structural facts (orphans/edges/clones/forbidden) come from non-gameable
 * oracles (FQCN caller grep, normalized-body clone hash, the harness guard); doc-derived facts
 * (`docPurposes`, `docStatedGaps`) are tagged writable-untrusted-prose and are NEVER a scored field.
 */
final class AtlasLoopScopeComprehensionModel
{
    public const SCHEMA_VERSION = 'atlas.loop.scope_comprehension.v1';

    /** Provenance tag for the doc-derived prose facts — untrusted, never scored. */
    public const PROVENANCE_WRITABLE_PROSE = 'writable_untrusted_prose';

    /**
     * @param  list<array{rel_path:string, fqcn:string, public_methods:list<string>, is_orphan:bool, is_forbidden:bool, clone_cluster_id:?string}>  $inventory  sorted by rel_path
     * @param  array<string, list<string>>  $edges  rel_path => sorted production caller rel paths (measured; an entry with [] is a confirmed orphan)
     * @param  list<string>  $orphans  fqcns of inventoried classes with ZERO production callers (sorted)
     * @param  list<array{cluster_id:string, clone_hash:string, members:list<array{path:string, symbol:string}>}>  $cloneClusters  sorted by cluster_id
     * @param  list<string>  $forbidden  rel_paths flagged pétreo/forbidden (sorted)
     * @param  array<string, string>  $docPurposes  fqcn => first-docblock-sentence (provenance: writable-untrusted-prose)
     * @param  list<string>  $docStatedGaps  capability symbol-names the canonical docs name but NO inventoried symbol provides (sorted)
     * @param  string  $snapshotId  deterministic hash of the STRUCTURAL facts only (prose-independent)
     */
    public function __construct(
        public readonly array $inventory,
        public readonly array $edges,
        public readonly array $orphans,
        public readonly array $cloneClusters,
        public readonly array $forbidden,
        public readonly array $docPurposes,
        public readonly array $docStatedGaps,
        public readonly string $snapshotId,
    ) {
    }

    /** Is the class at this FQCN a confirmed orphan (built-but-unwired)? */
    public function isOrphan(string $fqcn): bool
    {
        return in_array(ltrim($fqcn, '\\'), $this->orphans, true);
    }

    /** The clone-cluster id this path belongs to, or null. */
    public function cloneClusterIdFor(string $relPath): ?string
    {
        $relPath = ltrim($relPath, '/');
        foreach ($this->cloneClusters as $cluster) {
            foreach ($cluster['members'] as $m) {
                if (ltrim((string) $m['path'], '/') === $relPath) {
                    return (string) $cluster['cluster_id'];
                }
            }
        }

        return null;
    }

    /** The measured production caller rel-paths for a scope path ([] = confirmed orphan; null = unmeasured). */
    public function callerPathsFor(string $relPath): ?array
    {
        $relPath = ltrim($relPath, '/');

        return $this->edges[$relPath] ?? null;
    }

    /**
     * The per-PATH descriptive enrichment the producer attaches to a candidate packet (Layer 2 input).
     * Descriptive ONLY — these keys feed NO ranking math.
     *
     * @return array{is_orphan:bool, clone_cluster_id:?string, wired_caller_paths:list<string>, doc_purpose:?string}
     */
    public function descriptorFor(string $relPath): array
    {
        $relPath = ltrim($relPath, '/');
        $fqcn = $this->fqcnForPath($relPath);
        $callers = $this->edges[$relPath] ?? [];

        return [
            'is_orphan' => $fqcn !== null && $this->isOrphan($fqcn),
            'clone_cluster_id' => $this->cloneClusterIdFor($relPath),
            'wired_caller_paths' => array_values($callers),
            'doc_purpose' => $fqcn !== null ? ($this->docPurposes[$fqcn] ?? null) : null,
        ];
    }

    public function fqcnForPath(string $relPath): ?string
    {
        $relPath = ltrim($relPath, '/');
        foreach ($this->inventory as $item) {
            if (ltrim((string) $item['rel_path'], '/') === $relPath) {
                return (string) $item['fqcn'];
            }
        }

        return null;
    }

    /**
     * PART 2 · B1 — rehydrate a model from its {@see toArray} serialization (the P1-B read-model round-trip).
     * The inverse is byte-identical: `fromArray($m->toArray())->toArray() === $m->toArray()` (the provenance
     * constants are re-emitted by toArray, so they survive the round-trip). No structural fact is re-derived
     * here — it is pure deserialization of facts already proven by the builder.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            inventory: array_values((array) ($data['inventory'] ?? [])),
            edges: (array) ($data['edges'] ?? []),
            orphans: array_values((array) ($data['orphans'] ?? [])),
            cloneClusters: array_values((array) ($data['clone_clusters'] ?? [])),
            forbidden: array_values((array) ($data['forbidden'] ?? [])),
            docPurposes: (array) ($data['doc_purposes'] ?? []),
            docStatedGaps: array_values((array) ($data['doc_stated_gaps'] ?? [])),
            snapshotId: (string) ($data['snapshot_id'] ?? ''),
        );
    }

    /** Deterministic serialization (for equality / determinism proofs). */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'snapshot_id' => $this->snapshotId,
            'inventory' => $this->inventory,
            'edges' => $this->edges,
            'orphans' => $this->orphans,
            'clone_clusters' => $this->cloneClusters,
            'forbidden' => $this->forbidden,
            'doc_purposes' => $this->docPurposes,
            'doc_purposes_provenance' => self::PROVENANCE_WRITABLE_PROSE,
            'doc_stated_gaps' => $this->docStatedGaps,
            'doc_stated_gaps_provenance' => self::PROVENANCE_WRITABLE_PROSE,
        ];
    }

    /**
     * The STRUCTURAL projection only (orphans/edges/clones/forbidden/inventory-without-prose). Two models
     * with identical structure but different docblocks have an identical structural projection — the proof
     * that doc prose can never launder into a structural fact.
     */
    public function structuralProjection(): array
    {
        $inventoryStructural = array_map(static fn (array $i): array => [
            'rel_path' => $i['rel_path'],
            'fqcn' => $i['fqcn'],
            'public_methods' => $i['public_methods'],
            'is_orphan' => $i['is_orphan'],
            'is_forbidden' => $i['is_forbidden'],
            'clone_cluster_id' => $i['clone_cluster_id'],
        ], $this->inventory);

        return [
            'snapshot_id' => $this->snapshotId,
            'inventory' => $inventoryStructural,
            'edges' => $this->edges,
            'orphans' => $this->orphans,
            'clone_clusters' => $this->cloneClusters,
            'forbidden' => $this->forbidden,
            'doc_stated_gaps' => $this->docStatedGaps,
        ];
    }
}
