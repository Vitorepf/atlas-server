<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas System Graph — Node Catalog And Build Contract (build packet).
 *
 * This doc is the *build plan* an AI session follows to materialize the Atlas
 * System Graph nodes in the Vault. It is NOT the L0 taxonomy (closed
 * vocabularies — that is `atlas-system-graph.md` / AtlasSystemGraphService) and
 * NOT the L1 write rules (per-node evidence / update — that is
 * `living-architecture-graph-contract.md` /
 * AtlasLivingArchitectureGraphContractService). What is unique to THIS doc, and
 * therefore enforced here and nowhere else, is the concrete *catalog*: the named
 * nodes with their expected parent and expected status, the build-order gating,
 * the Self-Programming `future` status law, the required-frontmatter shape and
 * the core-dependency edge set.
 *
 * Pure, deterministic, no DB / no I/O / no provider / no status promotion. Every
 * method classifies caller-supplied data against the documented catalog and
 * emits blocking reasons; it never writes a Vault note.
 *
 * Documented rules enforced (one method surface per rule):
 *
 *   R1. "Top-Level / Sovereign / Evolution / Kernel / Obras / Programming Nodes"
 *       tables + "AI Instructions" ("link every node to at least one parent").
 *       A candidate node whose id is in the catalog must declare the catalog's
 *       parent and the catalog's status; a mismatch is drift. A node not in the
 *       catalog must still declare a non-empty parent ("link every node to at
 *       least one parent").
 *
 *   R2. "AI Instructions" — "use `future` for Self-Programming nodes not
 *       started". The 5 Self-Programming Future nodes MUST be `future`; claiming
 *       any other status for them is a violation. And "use `planned` when
 *       uncertain": an unknown / empty status resolves to `planned`, never to a
 *       done-like status.
 *
 *   R3. "Build Order" (10 steps) + the decision "Top-level systems and active
 *       evolution modules must be created before deep leaf nodes." A node may
 *       not be built before its prerequisite build step is complete; the method
 *       returns the blocking step. (The folder/root come first; then systems;
 *       then evolution; then kernel; then obras; then support.)
 *
 *   R4. "Required Frontmatter" (the 14-key block) + "Validation Checklist"
 *       ("Every node has graph_id, type, status, parent and canonical_doc" and
 *       "No node has empty next_actions unless status is implemented or
 *       archive"). A node missing a required key, or a non-terminal node with no
 *       next_actions, is invalid.
 *
 *   R5. "Core Dependencies" table — the canonical edge set (depends_on / unlocks
 *       / feeds / governs). A proposed edge is accepted only if it appears in
 *       the documented set; an edge that contradicts it (or is absent) is
 *       flagged.
 *
 * @see docs/engineering-knowledge-base/system-graph/node-catalog-and-build-contract.md
 */
final class AtlasNodeCatalogAndBuildContractService
{
    /** Stable evidence schema id this validator emits. */
    public const SCHEMA = 'atlas.system_graph.node_catalog_build_contract.v1';

    /** Read-only build-packet validator; never writes, runs or promotes. */
    public const MODE = 'node_catalog_build_contract_validator_read_only';

    public const STATUS_PASS = 'pass';

    public const STATUS_FAIL = 'fail';

    /**
     * "AI Instructions" / "Validation Checklist": the statuses that do NOT owe a
     * next action. The checklist says "No node has empty next_actions unless
     * status is `implemented` or `archive`."
     *
     * @var list<string>
     */
    public const TERMINAL_STATUSES = ['implemented', 'archive'];

    /**
     * The status an uncertain / unknown / empty status coerces to. Doc:
     * "use `planned` when uncertain".
     */
    public const UNCERTAIN_STATUS = 'planned';

    /**
     * The status every not-started Self-Programming node must carry. Doc:
     * "use `future` for Self-Programming nodes not started".
     */
    public const SELF_PROGRAMMING_STATUS = 'future';

    /**
     * "Required Frontmatter" — the keys the doc declares mandatory in the
     * frontmatter block. The Validation Checklist hard-requires a subset of
     * these on EVERY node (graph_id, type, status, parent, canonical_doc); the
     * rest are part of the declared template shape.
     *
     * @var list<string>
     */
    public const REQUIRED_FRONTMATTER_KEYS = [
        'graph_id', 'type', 'status', 'owner', 'parent',
        'canonical_doc', 'repo_paths', 'tags',
    ];

    /**
     * The Validation Checklist's hard requirement: "Every node has `graph_id`,
     * `type`, `status`, `parent` and `canonical_doc`." These five may never be
     * absent regardless of node kind.
     *
     * @var list<string>
     */
    public const MANDATORY_NODE_KEYS = ['graph_id', 'type', 'status', 'parent', 'canonical_doc'];

    /**
     * The catalog, materialized from the doc's node tables. Each entry maps a
     * stable node name to its documented {parent, status, type}. Keys are the
     * exact node names from the tables.
     *
     * Source tables: "Top-Level Nodes", "Sovereign Nodes", "Evolution Nodes",
     * "Self-Programming Future Nodes", "Kernel Nodes", "Obras Nodes",
     * "Programming / Forge Nodes".
     *
     * @var array<string, array{parent: string, status: string, type: string}>
     */
    public const CATALOG = [
        // Top-Level Nodes
        'Atlas' => ['parent' => 'none', 'status' => 'active', 'type' => 'system'],
        'Atlas Sovereign System' => ['parent' => 'Atlas', 'status' => 'active', 'type' => 'system'],
        'Atlas Evolution System' => ['parent' => 'Atlas', 'status' => 'active', 'type' => 'system'],
        'Atlas AI Kernel System' => ['parent' => 'Atlas', 'status' => 'active', 'type' => 'system'],
        'Atlas Obras System' => ['parent' => 'Atlas', 'status' => 'active', 'type' => 'system'],
        'Atlas Programming Forge System' => ['parent' => 'Atlas', 'status' => 'active', 'type' => 'system'],
        'Atlas Memory Vault System' => ['parent' => 'Atlas', 'status' => 'active', 'type' => 'system'],
        'Atlas Evidence System' => ['parent' => 'Atlas', 'status' => 'active', 'type' => 'system'],
        'Atlas Documentation Operating System' => ['parent' => 'Atlas', 'status' => 'active', 'type' => 'system'],
        'Atlas Governance Policy System' => ['parent' => 'Atlas', 'status' => 'active', 'type' => 'system'],
        'Atlas Domain Systems' => ['parent' => 'Atlas', 'status' => 'active', 'type' => 'system'],
        'Atlas Runtime Capability System' => ['parent' => 'Atlas', 'status' => 'active', 'type' => 'system'],
        'Atlas Product Surface System' => ['parent' => 'Atlas', 'status' => 'active', 'type' => 'system'],

        // Sovereign Nodes
        'Strategic Constitution' => ['parent' => 'Atlas Sovereign System', 'status' => 'active', 'type' => 'module'],
        'Operator Model' => ['parent' => 'Atlas Sovereign System', 'status' => 'planned', 'type' => 'module'],
        'Autonomy Graph' => ['parent' => 'Atlas Sovereign System', 'status' => 'planned', 'type' => 'module'],
        'Constraint System' => ['parent' => 'Atlas Sovereign System', 'status' => 'active', 'type' => 'module'],
        'Capital Stack' => ['parent' => 'Atlas Sovereign System', 'status' => 'planned', 'type' => 'module'],
        'Life Business Flywheel' => ['parent' => 'Atlas Sovereign System', 'status' => 'planned', 'type' => 'module'],

        // Evolution Nodes
        'Atlas Self-Construction OS' => ['parent' => 'Atlas Evolution System', 'status' => 'building', 'type' => 'program'],
        'Atlas Self-Programming OS' => ['parent' => 'Atlas Evolution System', 'status' => 'future', 'type' => 'program'],
        'Learning Self-Improvement' => ['parent' => 'Atlas Evolution System', 'status' => 'active', 'type' => 'module'],
        'Atlas Agent Control Plane' => ['parent' => 'Atlas Self-Construction OS', 'status' => 'building', 'type' => 'module'],
        'Work Splitter' => ['parent' => 'Atlas Self-Construction OS', 'status' => 'building', 'type' => 'module'],
        'Scope Validator' => ['parent' => 'Atlas Self-Construction OS', 'status' => 'building', 'type' => 'module'],
        'AI Implementation Packet' => ['parent' => 'Atlas Self-Construction OS', 'status' => 'building', 'type' => 'module'],
        'Multi-Session Readiness Gate' => ['parent' => 'Atlas Self-Construction OS', 'status' => 'building', 'type' => 'module'],

        // Self-Programming Future Nodes
        'Self-Modification Policy' => ['parent' => 'Atlas Self-Programming OS', 'status' => 'future', 'type' => 'module'],
        'Architecture Mutation Engine' => ['parent' => 'Atlas Self-Programming OS', 'status' => 'future', 'type' => 'module'],
        'Proposal Simulator' => ['parent' => 'Atlas Self-Programming OS', 'status' => 'future', 'type' => 'module'],
        'Self-Programming Decision Receipts' => ['parent' => 'Atlas Self-Programming OS', 'status' => 'future', 'type' => 'module'],
        'Autonomous Repair Loop' => ['parent' => 'Atlas Self-Programming OS', 'status' => 'future', 'type' => 'module'],

        // Kernel Nodes
        'Surface Plane' => ['parent' => 'Atlas AI Kernel System', 'status' => 'active', 'type' => 'module'],
        'Surface Adapter' => ['parent' => 'Atlas AI Kernel System', 'status' => 'active', 'type' => 'module'],
        'Atlas Input' => ['parent' => 'Atlas AI Kernel System', 'status' => 'active', 'type' => 'module'],
        'Operation Envelope' => ['parent' => 'Atlas AI Kernel System', 'status' => 'active', 'type' => 'module'],
        'Intent Routing' => ['parent' => 'Atlas AI Kernel System', 'status' => 'active', 'type' => 'module'],
        'Business Context' => ['parent' => 'Atlas AI Kernel System', 'status' => 'active', 'type' => 'module'],
        'Domain Profile Flow' => ['parent' => 'Atlas AI Kernel System', 'status' => 'active', 'type' => 'module'],
        'Context Builder' => ['parent' => 'Atlas AI Kernel System', 'status' => 'active', 'type' => 'module'],
        'Policy Profile' => ['parent' => 'Atlas AI Kernel System', 'status' => 'active', 'type' => 'module'],
        'Atlas Decide' => ['parent' => 'Atlas AI Kernel System', 'status' => 'active', 'type' => 'module'],
        'Decision Receipt' => ['parent' => 'Atlas AI Kernel System', 'status' => 'active', 'type' => 'module'],
        'Runtime Executor' => ['parent' => 'Atlas AI Kernel System', 'status' => 'active', 'type' => 'module'],
        'Quality Gates' => ['parent' => 'Atlas AI Kernel System', 'status' => 'active', 'type' => 'module'],
        'Repair Escalation' => ['parent' => 'Atlas AI Kernel System', 'status' => 'active', 'type' => 'module'],
        'Evidence Ledger' => ['parent' => 'Atlas AI Kernel System', 'status' => 'active', 'type' => 'module'],
        'Learning Proposals' => ['parent' => 'Atlas AI Kernel System', 'status' => 'active', 'type' => 'module'],
        'Output Renderer' => ['parent' => 'Atlas AI Kernel System', 'status' => 'active', 'type' => 'module'],
    ];

    /**
     * The 5 named Self-Programming Future nodes. These MUST be `future` per the
     * "AI Instructions" rule, and so are guarded explicitly (R2).
     *
     * @var list<string>
     */
    public const SELF_PROGRAMMING_NODES = [
        'Atlas Self-Programming OS',
        'Self-Modification Policy',
        'Architecture Mutation Engine',
        'Proposal Simulator',
        'Self-Programming Decision Receipts',
        'Autonomous Repair Loop',
    ];

    /**
     * "Build Order" — the 10 ordered steps. Index = step number - 1. A node is
     * gated to the step that creates its tier; earlier steps must be complete
     * first. Doc decision: "Top-level systems and active evolution modules must
     * be created before deep leaf nodes."
     *
     * @var list<string>
     */
    public const BUILD_ORDER = [
        'create_graph_folder',           // 1
        'create_top_node_atlas',         // 2
        'create_top_level_systems',      // 3
        'create_active_evolution',       // 4
        'create_kernel_pipeline',        // 5
        'create_obras_levels',           // 6
        'create_support_systems',        // 7
        'add_dependencies_unlocks',      // 8
        'add_canonical_docs_repo_paths', // 9
        'run_link_check',                // 10
    ];

    /**
     * "Core Dependencies" — the canonical edge set, as {from, relation, to}.
     * An edge is accepted by validateEdge() only if it is exactly here.
     *
     * @var list<array{from: string, relation: string, to: string}>
     */
    public const CORE_DEPENDENCIES = [
        ['from' => 'Atlas Self-Programming OS', 'relation' => 'depends_on', 'to' => 'Atlas Self-Construction OS'],
        ['from' => 'Atlas Self-Construction OS', 'relation' => 'depends_on', 'to' => 'Atlas Documentation Operating System'],
        ['from' => 'Atlas Self-Construction OS', 'relation' => 'depends_on', 'to' => 'Atlas AI Kernel System'],
        ['from' => 'Atlas Agent Control Plane', 'relation' => 'depends_on', 'to' => 'AI Implementation Packet'],
        ['from' => 'Atlas Agent Control Plane', 'relation' => 'depends_on', 'to' => 'Scope Validator'],
        ['from' => 'Atlas Agent Control Plane', 'relation' => 'unlocks', 'to' => 'Two Codex Execution'],
        ['from' => 'Work Splitter', 'relation' => 'unlocks', 'to' => 'Multi Provider Parallel Execution'],
        ['from' => 'Obras Shared Workspace', 'relation' => 'feeds', 'to' => 'Forge Workspace'],
        ['from' => 'Forge Workspace', 'relation' => 'depends_on', 'to' => 'Atlas Agent Control Plane'],
        ['from' => 'Evidence Ledger', 'relation' => 'governs', 'to' => 'Work Products'],
        ['from' => 'Decision Receipt', 'relation' => 'governs', 'to' => 'Self-Programming Decision Receipts'],
    ];

    /**
     * R2 (status default): resolve a candidate status against the doc rules.
     *
     * - An unknown / empty / null status coerces to `planned` ("use `planned`
     *   when uncertain"). It is flagged coerced and, being non-terminal, still
     *   owes a next action.
     * - A known status passes through untouched.
     *
     * @return array{input: string, resolved_status: string, coerced: bool, terminal: bool, requires_next_action: bool}
     */
    public function resolveStatus(?string $status): array
    {
        $known = [
            'active', 'building', 'planned', 'future',
            'implemented', 'archive', 'obsolete', 'replaced',
        ];

        $token = strtolower(trim((string) $status));
        $coerced = $token === '' || ! in_array($token, $known, true);
        $resolved = $coerced ? self::UNCERTAIN_STATUS : $token;
        $terminal = in_array($resolved, self::TERMINAL_STATUSES, true);

        return [
            'input' => (string) $status,
            'resolved_status' => $resolved,
            'coerced' => $coerced,
            'terminal' => $terminal,
            'requires_next_action' => ! $terminal,
        ];
    }

    /**
     * R1 + R2: classify a single candidate node against the catalog.
     *
     * Checks, deterministically:
     *   - if the node id is in the catalog: declared parent must equal the
     *     catalog parent, and declared status must equal the catalog status
     *     (drift otherwise);
     *   - if the node is a Self-Programming node: status must be `future`;
     *   - if the node id is NOT in the catalog: it must still declare a
     *     non-empty parent ("link every node to at least one parent").
     *
     * @param array{name?: string, parent?: string, status?: string} $node
     * @return array{
     *     name: string, in_catalog: bool, conformant: bool,
     *     expected_parent: ?string, expected_status: ?string,
     *     drift: list<string>, verdict: string
     * }
     */
    public function classifyAgainstCatalog(array $node): array
    {
        $name = (string) ($node['name'] ?? '');
        $declaredParent = (string) ($node['parent'] ?? '');
        $declaredStatus = strtolower(trim((string) ($node['status'] ?? '')));

        $inCatalog = $name !== '' && array_key_exists($name, self::CATALOG);
        $drift = [];
        $expectedParent = null;
        $expectedStatus = null;

        if ($inCatalog) {
            $expectedParent = self::CATALOG[$name]['parent'];
            $expectedStatus = self::CATALOG[$name]['status'];

            if ($declaredParent !== $expectedParent) {
                $drift[] = sprintf(
                    'parent drift: declared "%s" but catalog requires "%s"',
                    $declaredParent !== '' ? $declaredParent : '(none)',
                    $expectedParent,
                );
            }
            if ($declaredStatus !== $expectedStatus) {
                $drift[] = sprintf(
                    'status drift: declared "%s" but catalog requires "%s"',
                    $declaredStatus !== '' ? $declaredStatus : '(none)',
                    $expectedStatus,
                );
            }
        } else {
            // "link every node to at least one parent"
            if ($declaredParent === '') {
                $drift[] = 'node is not in the catalog and declares no parent (every node needs at least one parent)';
            }
        }

        // R2: Self-Programming nodes must be `future`.
        if (in_array($name, self::SELF_PROGRAMMING_NODES, true) && $declaredStatus !== self::SELF_PROGRAMMING_STATUS) {
            $drift[] = sprintf(
                'self-programming node must be status "future" (not started); declared "%s"',
                $declaredStatus !== '' ? $declaredStatus : '(none)',
            );
        }

        $conformant = $drift === [];

        return [
            'name' => $name,
            'in_catalog' => $inCatalog,
            'conformant' => $conformant,
            'expected_parent' => $expectedParent,
            'expected_status' => $expectedStatus,
            'drift' => $drift,
            'verdict' => $conformant ? self::STATUS_PASS : self::STATUS_FAIL,
        ];
    }

    /**
     * R3: build-order gate. Given the node tier being built and the set of
     * already-completed build steps, decide whether the node may be built now.
     *
     * The tier maps to its creating step; that step and every earlier step must
     * be in $completedSteps. Doc decision: top-level systems and active
     * evolution come before deep leaf nodes.
     *
     * @param string $tier one of: top_node, top_level_system, evolution, kernel, obras, support, leaf
     * @param list<string> $completedSteps
     * @return array{tier: string, required_step: string, required_index: int, allowed: bool, blocking_step: ?string}
     */
    public function checkBuildOrder(string $tier, array $completedSteps): array
    {
        $tierStep = [
            'top_node' => 'create_top_node_atlas',
            'top_level_system' => 'create_top_level_systems',
            'evolution' => 'create_active_evolution',
            'kernel' => 'create_kernel_pipeline',
            'obras' => 'create_obras_levels',
            'support' => 'create_support_systems',
            // a deep leaf node may not exist before its system + evolution tiers;
            // the doc gates leaves behind the support-systems step at the latest.
            'leaf' => 'create_support_systems',
        ];

        $requiredStep = $tierStep[$tier] ?? 'create_top_node_atlas';
        $requiredIndex = (int) array_search($requiredStep, self::BUILD_ORDER, true);

        // Every step at or before the required index must be completed.
        $blockingStep = null;
        for ($i = 0; $i <= $requiredIndex; $i++) {
            $step = self::BUILD_ORDER[$i];
            if (! in_array($step, $completedSteps, true)) {
                $blockingStep = $step;
                break;
            }
        }

        return [
            'tier' => $tier,
            'required_step' => $requiredStep,
            'required_index' => $requiredIndex,
            'allowed' => $blockingStep === null,
            'blocking_step' => $blockingStep,
        ];
    }

    /**
     * R4: validate a node's frontmatter + next-action obligation.
     *
     * - Names any MANDATORY_NODE_KEYS that are missing/empty (Validation
     *   Checklist: graph_id, type, status, parent, canonical_doc).
     * - A non-terminal node (status not implemented/archive) with empty
     *   next_actions fails ("No node has empty next_actions unless status is
     *   implemented or archive").
     *
     * @param array{frontmatter?: array<string,mixed>, next_actions?: list<string>} $node
     * @return array{
     *     valid: bool, missing_frontmatter: list<string>,
     *     status: string, terminal: bool, needs_next_action: bool,
     *     blocking_reasons: list<string>, verdict: string
     * }
     */
    public function validateNodeFrontmatter(array $node): array
    {
        $fm = $node['frontmatter'] ?? [];
        $fm = is_array($fm) ? $fm : [];
        $missing = [];

        foreach (self::MANDATORY_NODE_KEYS as $key) {
            $value = $fm[$key] ?? null;
            $present = is_array($value) ? $value !== [] : trim((string) $value) !== '';
            if (! $present) {
                $missing[] = $key;
            }
        }

        $resolved = $this->resolveStatus(isset($fm['status']) ? (string) $fm['status'] : null);
        $status = $resolved['resolved_status'];
        $terminal = $resolved['terminal'];

        $nextActions = $node['next_actions'] ?? [];
        $nextActions = is_array($nextActions) ? array_values(array_filter(
            $nextActions,
            static fn ($a): bool => trim((string) $a) !== '',
        )) : [];

        $blocking = [];
        foreach ($missing as $key) {
            $blocking[] = sprintf('missing mandatory frontmatter key: %s', $key);
        }

        $needsNextAction = ! $terminal && $nextActions === [];
        if ($needsNextAction) {
            $blocking[] = sprintf(
                "non-terminal node (status '%s') has empty next_actions (only implemented/archive may be empty)",
                $status,
            );
        }

        $valid = $blocking === [];

        return [
            'valid' => $valid,
            'missing_frontmatter' => $missing,
            'status' => $status,
            'terminal' => $terminal,
            'needs_next_action' => $needsNextAction,
            'blocking_reasons' => $blocking,
            'verdict' => $valid ? self::STATUS_PASS : self::STATUS_FAIL,
        ];
    }

    /**
     * R5: accept or reject a proposed dependency edge against the documented
     * "Core Dependencies" set. An edge is recognized only if {from, relation,
     * to} matches exactly. A from+to pair that exists but with a different
     * relation is reported as a relation conflict.
     *
     * @param array{from?: string, relation?: string, to?: string} $edge
     * @return array{
     *     edge: array{from: string, relation: string, to: string},
     *     recognized: bool, relation_conflict: ?string, blocking_reasons: list<string>, verdict: string
     * }
     */
    public function validateEdge(array $edge): array
    {
        $from = (string) ($edge['from'] ?? '');
        $relation = (string) ($edge['relation'] ?? '');
        $to = (string) ($edge['to'] ?? '');

        $recognized = false;
        $relationConflict = null;

        foreach (self::CORE_DEPENDENCIES as $canon) {
            if ($canon['from'] === $from && $canon['to'] === $to) {
                if ($canon['relation'] === $relation) {
                    $recognized = true;
                    break;
                }
                $relationConflict = $canon['relation'];
            }
        }

        $blocking = [];
        if (! $recognized) {
            if ($relationConflict !== null) {
                $blocking[] = sprintf(
                    'relation conflict: documented edge "%s" -> "%s" is "%s", not "%s"',
                    $from,
                    $to,
                    $relationConflict,
                    $relation !== '' ? $relation : '(none)',
                );
            } else {
                $blocking[] = sprintf(
                    'edge not in Core Dependencies: "%s" -%s-> "%s"',
                    $from !== '' ? $from : '(none)',
                    $relation !== '' ? $relation : '?',
                    $to !== '' ? $to : '(none)',
                );
            }
        }

        return [
            'edge' => ['from' => $from, 'relation' => $relation, 'to' => $to],
            'recognized' => $recognized,
            'relation_conflict' => $relationConflict,
            'blocking_reasons' => $blocking,
            'verdict' => $recognized ? self::STATUS_PASS : self::STATUS_FAIL,
        ];
    }

    /**
     * Primary method: run the whole build-contract audit over a candidate build
     * packet (a set of nodes, optionally edges and completed build steps).
     * Aggregates R1/R2 (catalog conformance), R4 (frontmatter), and R5 (edges)
     * into a single pass|fail evidence document. Deterministic; no side effects.
     *
     * @param array{
     *     nodes?: list<array<string,mixed>>,
     *     edges?: list<array<string,mixed>>,
     *     completed_steps?: list<string>
     * } $packet
     * @return array<string,mixed>
     */
    public function auditBuildPacket(array $packet): array
    {
        $nodes = $packet['nodes'] ?? [];
        $nodes = is_array($nodes) ? $nodes : [];
        $edges = $packet['edges'] ?? [];
        $edges = is_array($edges) ? $edges : [];

        $nodeReports = [];
        $driftCount = 0;
        $invalidFrontmatterCount = 0;

        foreach ($nodes as $node) {
            $node = is_array($node) ? $node : [];
            $catalog = $this->classifyAgainstCatalog([
                'name' => (string) ($node['name'] ?? ''),
                'parent' => (string) ($node['parent'] ?? ''),
                'status' => (string) ($node['status'] ?? ''),
            ]);
            $frontmatter = $this->validateNodeFrontmatter($node);

            if (! $catalog['conformant']) {
                $driftCount++;
            }
            if (! $frontmatter['valid']) {
                $invalidFrontmatterCount++;
            }

            $nodeReports[] = [
                'name' => $catalog['name'],
                'catalog' => $catalog,
                'frontmatter' => $frontmatter,
                'node_ok' => $catalog['conformant'] && $frontmatter['valid'],
            ];
        }

        $edgeReports = [];
        $rejectedEdges = 0;
        foreach ($edges as $edge) {
            $edge = is_array($edge) ? $edge : [];
            $report = $this->validateEdge($edge);
            if (! $report['recognized']) {
                $rejectedEdges++;
            }
            $edgeReports[] = $report;
        }

        $pass = $driftCount === 0 && $invalidFrontmatterCount === 0 && $rejectedEdges === 0;

        return [
            'schema' => self::SCHEMA,
            'mode' => self::MODE,
            'status' => $pass ? self::STATUS_PASS : self::STATUS_FAIL,
            'counts' => [
                'nodes' => count($nodeReports),
                'catalog_drift' => $driftCount,
                'invalid_frontmatter' => $invalidFrontmatterCount,
                'edges' => count($edgeReports),
                'rejected_edges' => $rejectedEdges,
            ],
            'nodes' => $nodeReports,
            'edges' => $edgeReports,
        ];
    }
}
