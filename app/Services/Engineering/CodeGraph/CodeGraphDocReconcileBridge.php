<?php

namespace App\Services\Engineering\CodeGraph;

/**
 * Doc <-> code-graph reconciliation bridge for the Atlas Code Graph (AP-811/AP-812 M-3).
 *
 * This is a BRIDGE, not a rebuild. The doc<->code reconciliation capability
 * already lives in {@see \App\Services\Engineering\EngineeringCodeIntelligenceService}
 * (it persists `atlas_engineering_doc_links` rows and computes their drift —
 * missing_target / stale_target_hash — against the filesystem). What that
 * service does NOT do is cross-check those doc claims against the *real
 * code-graph edges* produced by {@see CodeGraphEdgeResolver}. This bridge fills
 * exactly that seam and nothing else: it compares the two read models and emits
 * REVIEW PROPOSALS where they disagree.
 *
 * Two drift directions are detected (both surfaced as type `doc_drift_vs_graph`):
 *   (a) doc_link_unsupported_by_graph — a doc_link claims a relation to a code
 *       target, but no code-graph edge touches that target. The docs assert a
 *       coupling the graph cannot corroborate (doc may be stale / aspirational).
 *   (b) strong_edge_absent_from_docs — a strong, EXTRACTED code edge exists
 *       (a real import / dependency the resolver proved) but neither of its
 *       endpoints is covered by any doc_link. The graph knows a real relation
 *       the docs never documented.
 *
 * Hard governance invariant (asserted by the test): every proposal carries
 *   promotion_allowed === false  AND  requires_review === true
 * There is no parameter, branch or code path that can flip these. The graph and
 * the doc_links are READ MODELS — they FEED a reviewer, they never DECIDE.
 * Pure transform: no DB, no IO, no provider, no clock — same family as
 * {@see CodeGraphEdgeResolver}, {@see CodeGraphAnalytics} and
 * {@see CodeGraphCompoundingBridge}. It NEVER writes a doc_link, NEVER mutates
 * an edge, NEVER reconciles anything itself — it only proposes.
 *
 * Output proposal shape (mirrors the proposal-only fields Atlas memory/review
 * governance already understands):
 *   {
 *     type: 'doc_drift_vs_graph',
 *     direction: 'doc_link_unsupported_by_graph' | 'strong_edge_absent_from_docs',
 *     detail: string,             // human-readable description of the disagreement
 *     promotion_allowed: false,   // INVARIANT — never auto-promote / never auto-reconcile
 *     requires_review: true,      // INVARIANT — always human-gated
 *     metadata: {...},            // structured signal (the offending row, restated invariant)
 *   }
 */
class CodeGraphDocReconcileBridge
{
    public const SCHEMA = 'atlas.code_graph.doc_reconcile_bridge.v1';

    public const TYPE = 'doc_drift_vs_graph';

    /** A doc_link claims a code relation the graph has no edge for. */
    public const DIRECTION_DOC_UNSUPPORTED = 'doc_link_unsupported_by_graph';

    /** A strong (EXTRACTED) code edge exists that no doc_link covers. */
    public const DIRECTION_EDGE_UNDOCUMENTED = 'strong_edge_absent_from_docs';

    /**
     * link_types that do not assert a code-target relation worth corroborating
     * against the edge graph. Capability links match on a slug substring, not a
     * concrete file/symbol edge, so an absent edge is not evidence of drift.
     */
    private const NON_TARGET_LINK_TYPES = ['module_capability'];

    /**
     * Compare real code-graph edges against persisted doc_links and return drift
     * proposals. Proposal-only: promotion_allowed is hard-false and
     * requires_review is hard-true on every returned proposal.
     *
     * @param  array<int,array<string,mixed>>  $codeEdges  resolved code-graph edges
     *   (CodeGraphEdgeResolver::resolve()['edges']): each
     *   {from_node_id, to_node_id, edge_type, confidence, confidence_score, metadata}
     * @param  array<int,array<string,mixed>>  $docLinks  persisted doc_link rows
     *   (atlas_engineering_doc_links): each {id, status?, canonical_path, target_path,
     *   target_hash?, link_type?, indexed_at?}
     * @param  int  $maxPerDirection  cap per drift direction so a large drift set
     *   cannot flood the review queue
     * @return array<int,array{type:string,direction:string,detail:string,promotion_allowed:false,requires_review:true,metadata:array<string,mixed>}>
     */
    public function proposeReconciliations(array $codeEdges, array $docLinks, int $maxPerDirection = 50): array
    {
        $cap = max(1, $maxPerDirection);

        // Index every node touched by ANY edge, and every node touched by a
        // STRONG (EXTRACTED) edge specifically. Doc targets are matched against
        // these to decide corroboration.
        $nodesTouchedByAnyEdge = [];
        $strongEdges = [];
        foreach ($codeEdges as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $from = $this->stringOrNull($edge['from_node_id'] ?? null);
            $to = $this->stringOrNull($edge['to_node_id'] ?? null);
            if ($from === null || $to === null) {
                continue;
            }
            $nodesTouchedByAnyEdge[$from] = true;
            $nodesTouchedByAnyEdge[$to] = true;

            if ($this->isStrong($edge)) {
                $strongEdges[] = [
                    'from_node_id' => $from,
                    'to_node_id' => $to,
                    'edge_type' => $this->edgeType($edge['edge_type'] ?? null),
                    'confidence' => CodeGraphEdgeResolver::CONFIDENCE_EXTRACTED,
                ];
            }
        }

        // Set of code targets that DO appear in the docs (by normalized path),
        // used to decide whether a strong edge endpoint is documented. Only
        // concrete target links count — capability links are slug-substring
        // matches, not a documented relation to a specific code endpoint, so
        // they must not be treated as "documenting" an edge (symmetry with the
        // direction-(a) gate, which ignores them too).
        $documentedTargets = [];
        foreach ($docLinks as $link) {
            if (! is_array($link)) {
                continue;
            }
            $linkType = $this->stringOrNull($link['link_type'] ?? null);
            if ($linkType !== null && in_array($linkType, self::NON_TARGET_LINK_TYPES, true)) {
                continue;
            }
            $target = $this->normalizePath($link['target_path'] ?? null);
            if ($target !== null) {
                $documentedTargets[$target] = true;
            }
        }

        $proposals = [];

        // Direction (a): doc_links whose claimed relation has no supporting edge.
        $unsupportedCount = 0;
        foreach ($docLinks as $link) {
            if ($unsupportedCount >= $cap) {
                break;
            }
            $proposal = $this->docUnsupportedProposal($link, $nodesTouchedByAnyEdge);
            if ($proposal !== null) {
                $proposals[] = $proposal;
                $unsupportedCount++;
            }
        }

        // Direction (b): strong code edges absent from docs.
        $undocumentedCount = 0;
        foreach ($strongEdges as $edge) {
            if ($undocumentedCount >= $cap) {
                break;
            }
            $proposal = $this->edgeUndocumentedProposal($edge, $documentedTargets);
            if ($proposal !== null) {
                $proposals[] = $proposal;
                $undocumentedCount++;
            }
        }

        return $proposals;
    }

    /**
     * A doc_link claims a code target, but no code-graph edge touches a node that
     * corresponds to that target -> the doc claim is uncorroborated by the graph.
     *
     * @param  array<string,bool>  $nodesTouchedByAnyEdge
     * @return array{type:string,direction:string,detail:string,promotion_allowed:false,requires_review:true,metadata:array<string,mixed>}|null
     */
    private function docUnsupportedProposal(mixed $link, array $nodesTouchedByAnyEdge): ?array
    {
        if (! is_array($link)) {
            return null;
        }

        $linkType = $this->stringOrNull($link['link_type'] ?? null);
        if ($linkType !== null && in_array($linkType, self::NON_TARGET_LINK_TYPES, true)) {
            // Capability links don't assert a concrete code edge; absence proves nothing.
            return null;
        }

        $target = $this->normalizePath($link['target_path'] ?? null);
        if ($target === null) {
            // No code target claimed -> nothing for the graph to corroborate.
            return null;
        }

        // The graph corroborates the doc claim if ANY edge endpoint resolves to
        // this target path (node ids embed the path, e.g. "node:app/Services/X").
        if ($this->targetIsCorroborated($target, array_keys($nodesTouchedByAnyEdge))) {
            return null;
        }

        $canonical = $this->stringOrNull($link['canonical_path'] ?? null) ?? '(unknown doc)';
        $detail = sprintf(
            'Doc "%s" claims a %s relation to code target "%s", but no code-graph edge touches it; the doc claim is uncorroborated by the resolved graph (possibly stale or aspirational).',
            $canonical,
            $linkType ?? 'documented',
            $target,
        );

        return $this->proposal(
            direction: self::DIRECTION_DOC_UNSUPPORTED,
            detail: $detail,
            signal: array_filter([
                'doc_link_id' => $link['id'] ?? null,
                'canonical_path' => $canonical,
                'target_path' => $target,
                'link_type' => $linkType,
                'status' => $this->stringOrNull($link['status'] ?? null),
                'supporting_edge_count' => 0,
            ], static fn (mixed $v): bool => $v !== null),
        );
    }

    /**
     * A strong (EXTRACTED) edge exists whose endpoints are not covered by any
     * doc_link -> a real relation the docs never documented.
     *
     * @param  array{from_node_id:string,to_node_id:string,edge_type:string,confidence:string}  $edge
     * @param  array<string,bool>  $documentedTargets
     * @return array{type:string,direction:string,detail:string,promotion_allowed:false,requires_review:true,metadata:array<string,mixed>}|null
     */
    private function edgeUndocumentedProposal(array $edge, array $documentedTargets): ?array
    {
        $documentedTargetPaths = array_keys($documentedTargets);

        $fromDocumented = $this->nodeIsDocumented($edge['from_node_id'], $documentedTargetPaths);
        $toDocumented = $this->nodeIsDocumented($edge['to_node_id'], $documentedTargetPaths);

        // Only flag a strong edge as undocumented when NEITHER endpoint is
        // covered by docs — if one side is documented, the relation is at least
        // partially traceable and is not graph-only drift.
        if ($fromDocumented || $toDocumented) {
            return null;
        }

        $detail = sprintf(
            'Strong (EXTRACTED) code edge %s -%s-> %s has no doc_link covering either endpoint; a real, proven code relation is undocumented in the canonical docs.',
            $edge['from_node_id'],
            $edge['edge_type'],
            $edge['to_node_id'],
        );

        return $this->proposal(
            direction: self::DIRECTION_EDGE_UNDOCUMENTED,
            detail: $detail,
            signal: [
                'from_node_id' => $edge['from_node_id'],
                'to_node_id' => $edge['to_node_id'],
                'edge_type' => $edge['edge_type'],
                'confidence' => $edge['confidence'],
                'documented_endpoints' => 0,
            ],
        );
    }

    /**
     * Single construction point for the proposal shape. promotion_allowed and
     * requires_review are written as literals here and nowhere else, so the
     * proposal-only invariant cannot be parameterised away.
     *
     * @param  array<string,mixed>  $signal
     * @return array{type:string,direction:string,detail:string,promotion_allowed:false,requires_review:true,metadata:array<string,mixed>}
     */
    private function proposal(string $direction, string $detail, array $signal): array
    {
        return [
            'type' => self::TYPE,
            'direction' => $direction,
            'detail' => $detail,
            // INVARIANT: this bridge proposes, it never reconciles. The graph and
            // the doc_links are read models that FEED review, they never DECIDE.
            'promotion_allowed' => false,
            'requires_review' => true,
            'metadata' => [
                'bridge' => self::SCHEMA,
                'direction' => $direction,
                'signal' => $signal,
                // Restated inside metadata so any consumer reading only the
                // metadata blob still sees the proposal-only governance.
                'promotion_allowed' => false,
                'requires_review' => true,
            ],
        ];
    }

    /**
     * An edge is "strong" when its confidence is the resolver's EXTRACTED label
     * (a proven import/dependency), accepting either the explicit `confidence`
     * field or a metadata fallback.
     */
    private function isStrong(array $edge): bool
    {
        $confidence = $this->stringOrNull($edge['confidence'] ?? null)
            ?? $this->stringOrNull(($edge['metadata']['confidence'] ?? null));

        return $confidence !== null
            && strtoupper($confidence) === CodeGraphEdgeResolver::CONFIDENCE_EXTRACTED;
    }

    /**
     * A node id is considered to correspond to a documented target when the
     * target path appears inside the node id (node ids embed the module/file
     * path, e.g. "node:app/Services/Ai/Router"). Matched as a path-segment
     * suffix to avoid spurious substring hits.
     *
     * @param  array<int,string>  $documentedTargetPaths
     */
    private function nodeIsDocumented(string $nodeId, array $documentedTargetPaths): bool
    {
        return $this->targetIsCorroborated($nodeId, $documentedTargetPaths)
            // also catch the inverse: a documented target whose path the node sits under
            || $this->pathTouchesAny($nodeId, $documentedTargetPaths);
    }

    /**
     * Does any of the given node ids embed this target path? Used both for "is a
     * doc target backed by an edge" and "is an edge endpoint documented".
     *
     * @param  array<int,string>  $nodeIds
     */
    private function targetIsCorroborated(string $target, array $nodeIds): bool
    {
        $needle = $this->pathCore($target);
        if ($needle === '') {
            return false;
        }
        foreach ($nodeIds as $nodeId) {
            $hay = $this->pathCore($nodeId);
            if ($hay === '') {
                continue;
            }
            if ($this->pathMatches($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,string>  $targets
     */
    private function pathTouchesAny(string $nodeId, array $targets): bool
    {
        $hay = $this->pathCore($nodeId);
        if ($hay === '') {
            return false;
        }
        foreach ($targets as $target) {
            $needle = $this->pathCore($target);
            if ($needle !== '' && $this->pathMatches($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Path correspondence: equal, or one is a path-segment-aligned suffix of the
     * other. "app/services/x" matches "node:app/services/x" and
     * "app/services/x/y" but never the unrelated "app/services/xother".
     */
    private function pathMatches(string $hay, string $needle): bool
    {
        if ($hay === $needle) {
            return true;
        }
        if (str_ends_with($hay, '/'.$needle) || str_ends_with($needle, '/'.$hay)) {
            return true;
        }
        if (str_starts_with($hay, $needle.'/') || str_starts_with($needle, $hay.'/')) {
            return true;
        }

        return false;
    }

    /**
     * Reduce a node id or path to a comparable lowercase path core: drop a
     * leading "node:" scheme, a file extension, and any "::symbol" suffix, and
     * normalise separators.
     */
    private function pathCore(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (str_starts_with($value, 'node:')) {
            $value = substr($value, 5);
        }
        // Drop a "::symbol" or "@symbol" suffix some node ids carry.
        $value = preg_replace('/(::|@).*$/', '', $value) ?? $value;
        $value = str_replace('\\', '/', $value);
        $value = strtolower(trim($value, '/ '));
        // Drop a trailing file extension so "x.php" matches "x".
        $value = preg_replace('/\.[a-z0-9]+$/', '', $value) ?? $value;

        return $value;
    }

    private function normalizePath(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = str_replace('\\', '/', trim($value));
        $trimmed = trim($trimmed, '/ ');

        return $trimmed === '' ? null : $trimmed;
    }

    private function edgeType(mixed $value): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : 'depends_on';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
