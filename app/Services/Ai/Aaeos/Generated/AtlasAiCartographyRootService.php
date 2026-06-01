<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI cartography-root parent-resolution / orphan validator.
 *
 * Pure, deterministic implementation of the contract declared by the canonical
 * root node doc `atlas-ai`. That doc is the visual root of the Atlas AI
 * subuniverse (Kernel, Mission Mode, Autonomous Intelligence OS, domains,
 * surfaces). Its quality gate is `cartography-orphan-count-zero` and its
 * observability signal is the cartography `orphan_count`.
 *
 * This service takes a flat set of graph nodes (each with a `graph_id`,
 * `graph_parent` and `status`) and computes, deterministically:
 *   - which active nodes are orphans (parent does not resolve to a known node),
 *   - whether the `atlas-ai` root itself is present,
 *   - any node that violates "Atlas AI nao representa o Atlas inteiro",
 *   - the orphan_count and the pass/fail of the `cartography-orphan-count-zero`
 *     gate.
 *
 * Documented rules enforced (from the doc "Regra", "Contratos", "Regras para
 * IA", forbidden_changes, quality_gates, observability_signals):
 *   R1. "Todo filho ativo precisa de graph_parent resolvivel" — an ACTIVE node
 *       whose graph_parent is not the id of a known node is an orphan.
 *   R2. "Se este node sumir, o grafo volta a ficar orfao" — if the `atlas-ai`
 *       root node is absent from the set, every active node parented to it is
 *       reported orphaned and the gate fails.
 *   R3. "Atlas AI nao representa o Atlas inteiro" / forbidden_changes — a child
 *       node may NOT claim the world root id (`atlas`) as its own graph_id while
 *       declaring `atlas-ai` semantics, nor may `atlas-ai` declare itself the
 *       parent of the world root.
 *   R4. A node may not be its own parent (graph_id == graph_parent never
 *       resolves into the tree -> orphan).
 *   R5. quality_gates `cartography-orphan-count-zero` — the gate passes only
 *       when orphan_count === 0 AND no root-identity violation exists.
 *
 * Inactive nodes (status !== active) are excluded from the orphan computation,
 * matching the doc which scopes the contract to "documento ativo".
 *
 * The service NEVER reads the filesystem, the database or a provider. It is a
 * pure decision function over the node set the caller supplies; callers decide
 * whether to block cartography publication on a failing gate.
 *
 * @see docs/engineering-knowledge-base/atlas-ai.md
 */
final class AtlasAiCartographyRootService
{
    /** Stable receipt schema id for the audit this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.aaeos.cartography_root.atlas_ai.v1';

    /** Canonical id of THIS root node (the subuniverse visual root). */
    public const ROOT_ID = 'atlas-ai';

    /** Canonical id of the world root that `atlas-ai` must never claim to be. */
    public const WORLD_ROOT_ID = 'atlas';

    /** The quality gate this doc declares. */
    public const GATE = 'cartography-orphan-count-zero';

    /**
     * Validate a flat set of cartography nodes against the Atlas AI root
     * contract and return the audit + gate decision.
     *
     * @param list<array<string,mixed>> $nodes
     *        each node: { id|graph_id: string, parent|graph_parent: ?string,
     *                     status: string (default active) }
     *
     * @return array<string,mixed> the audit + gate decision
     */
    public function audit(array $nodes): array
    {
        $normalized = $this->normalizeNodes($nodes);

        // Index of every KNOWN node id (active or not). A parent resolves if it
        // points at any declared node id — a doc can hang off an inactive
        // structural node, but the parent must at least exist.
        $knownIds = [];
        foreach ($normalized as $node) {
            $knownIds[$node['id']] = true;
        }

        $rootPresent = isset($knownIds[self::ROOT_ID]);

        $orphans = [];
        $rootIdentityViolations = [];

        foreach ($normalized as $node) {
            $id = $node['id'];
            $parent = $node['parent'];
            $active = $node['active'];

            // R3 — root-identity violation: a node claiming the world root id
            // while declaring atlas-ai as its parent collapses the subuniverse
            // into "the whole Atlas"; and the atlas-ai root may not declare the
            // world root as its parent's child in reverse (atlas-ai parenting
            // atlas). Both directions are forbidden_changes.
            if ($id === self::WORLD_ROOT_ID && $parent === self::ROOT_ID) {
                $rootIdentityViolations[] = [
                    'id' => $id,
                    'reason' => 'world_root_claims_atlas_ai_as_parent',
                ];
            }
            if ($id === self::ROOT_ID && $parent === self::WORLD_ROOT_ID) {
                // This is the EXPECTED, correct placement of the root itself
                // (atlas-ai sits below the world root). Not a violation.
                continue;
            }
            if ($id === self::ROOT_ID) {
                // The root node itself is the visual root; it is never an orphan
                // regardless of an absent/!atlas parent, but if it points at a
                // non-world, non-null parent that does not resolve we still flag
                // it below via the generic orphan rule.
                if ($parent !== null && $parent !== self::WORLD_ROOT_ID && ! isset($knownIds[$parent])) {
                    $orphans[] = [
                        'id' => $id,
                        'parent' => $parent,
                        'reason' => 'root_parent_unresolved',
                    ];
                }

                continue;
            }

            // The world root sits at the top of the tree: the doc places
            // atlas-ai BELOW `atlas`, so `atlas` itself legitimately has a null
            // parent and is never an orphan under this subuniverse contract.
            if ($id === self::WORLD_ROOT_ID && $parent === null) {
                continue;
            }

            // Only ACTIVE children are bound by the orphan contract (R1).
            if (! $active) {
                continue;
            }

            // R4 — a node cannot be its own parent.
            if ($parent !== null && $parent === $id) {
                $orphans[] = [
                    'id' => $id,
                    'parent' => $parent,
                    'reason' => 'self_parent',
                ];

                continue;
            }

            // An active child with no parent at all cannot hang in the tree.
            if ($parent === null) {
                $orphans[] = [
                    'id' => $id,
                    'parent' => null,
                    'reason' => 'missing_parent',
                ];

                continue;
            }

            // R2 — the specific case the doc calls out: a child parented to the
            // atlas-ai root while the root is absent from the set.
            if ($parent === self::ROOT_ID && ! $rootPresent) {
                $orphans[] = [
                    'id' => $id,
                    'parent' => $parent,
                    'reason' => 'atlas_ai_root_missing',
                ];

                continue;
            }

            // R1 — generic unresolved parent.
            if (! isset($knownIds[$parent])) {
                $orphans[] = [
                    'id' => $id,
                    'parent' => $parent,
                    'reason' => 'parent_unresolved',
                ];
            }
        }

        $orphanCount = count($orphans);
        $rootIdentityOk = $rootIdentityViolations === [];

        // R5 — gate passes only with zero orphans AND no root-identity breach.
        $gatePass = $orphanCount === 0 && $rootIdentityOk;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'gate' => self::GATE,
            'gate_pass' => $gatePass,
            'root_id' => self::ROOT_ID,
            'root_present' => $rootPresent,
            'node_count' => count($normalized),
            'orphan_count' => $orphanCount,
            'orphans' => $orphans,
            'root_identity_ok' => $rootIdentityOk,
            'root_identity_violations' => $rootIdentityViolations,
            'auditable' => true,
        ];
    }

    /**
     * Convenience predicate: does the supplied node set satisfy the
     * `cartography-orphan-count-zero` gate?
     *
     * @param list<array<string,mixed>> $nodes
     */
    public function gatePasses(array $nodes): bool
    {
        return $this->audit($nodes)['gate_pass'] === true;
    }

    /**
     * Predicate the doc's failure_mode targets: would this active child orphan
     * under the given known-id set? Pure helper for callers placing one doc.
     *
     * @param list<string> $knownIds
     */
    public function childResolves(string $parent, array $knownIds): bool
    {
        $parent = trim($parent);
        if ($parent === '') {
            return false;
        }

        return in_array($parent, array_map('trim', $knownIds), true);
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @return list<array{id:string,parent:?string,active:bool}>
     */
    private function normalizeNodes(array $nodes): array
    {
        $clean = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $id = $this->stringOrNull($node['id'] ?? $node['graph_id'] ?? null);
            if ($id === null) {
                // A node with no id cannot participate in resolution; skip it.
                continue;
            }

            $parent = $this->stringOrNull($node['parent'] ?? $node['graph_parent'] ?? null);

            $status = $node['status'] ?? $node['graph_status'] ?? 'active';
            $active = is_string($status)
                ? strtolower(trim($status)) === 'active'
                : true;

            $clean[] = [
                'id' => $id,
                'parent' => $parent,
                'active' => $active,
            ];
        }

        return array_values($clean);
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
