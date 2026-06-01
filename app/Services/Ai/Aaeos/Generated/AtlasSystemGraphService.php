<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas System Graph (L0) — pure, deterministic validator for the *taxonomy*
 * and Definition-of-Done of the root System Graph contract.
 *
 * This is the L0 root contract: it fixes the closed vocabularies (node types,
 * relationship types, status+color semantics), the maturity ladder (L0..L4),
 * the canonical top hierarchy that every System Graph must contain, the
 * authority ordering, and the Vault-projection rules. It is explicitly a
 * "navigation and reasoning surface", NOT the executable source of truth, so
 * this service never writes a Vault note, never runs a provider, never touches
 * the filesystem or DB, and never promotes a status. It only classifies the
 * caller-supplied vocabulary tokens, nodes, links and graphs against the
 * documented rules and emits blocking reasons.
 *
 * (The sibling L1 doc `living-architecture-graph-contract.md` — and its
 * AtlasLivingArchitectureGraphContractService — governs node *writes* and
 * per-node evidence/next-action rules. This L0 service governs the *shapes and
 * the closed sets* those writes must conform to, which the L1 service does not
 * enumerate.)
 *
 * Documented rules enforced (one method surface per rule):
 *
 *   R1. "Node Types" — every node has exactly one primary type drawn from the
 *       closed set of 9 {system, program, module, submodule, artifact,
 *       decision, evidence, risk, obsolete}. A node with no type, or a type
 *       outside the set, is invalid.
 *
 *   R2. "Relationship Types" — links use only the closed set of 10 relations
 *       {parent, contains, depends_on, unlocks, feeds, governs, implements,
 *       replaces, blocked_by, spin_off}. A `parent` link is single-valued: a
 *       node has at most one parent.
 *
 *   R3. "Status And Color Semantics" — exactly 8 statuses, each mapped to a
 *       fixed Obsidian tag and one of the 5 color buckets
 *       (green/blue/yellow/red/purple). The mapping is the doc's table, not a
 *       guess.
 *
 *   R4. "Maturity Levels" — the L0..L4 ladder is ordered and a maturity claim
 *       may not skip a rung: you cannot assert Ln while any rung below n is
 *       unmet. (e.g. L2 "validated against code/evidence" requires L1 real
 *       Obsidian nodes to exist first.)
 *
 *   R5. "Authority" — operational authority order is repo docs / Postgres /
 *       code / tests ABOVE the Vault/Obsidian graph ABOVE chat history. "No
 *       Vault graph node may override a canonical repo document." Chat history
 *       is "source material only" and can never be the canonical authority.
 *
 *   R6. "Canonical Top Hierarchy" + "Definition Of Done" — every one of the 12
 *       top-level Atlas systems must be present as a node; a graph missing any
 *       is not Done. The DoD also requires the AtlasVault root note path and the
 *       module template path to be the documented ones.
 *
 *   R7. "Vault Projection Rules" — a Vault projection must link each node back
 *       to a canonical repo doc, must not store secrets/credentials/raw provider
 *       context, and must mark speculative nodes as `future` or `planned` (never
 *       a "done" status). A Vault note may never be the only source for
 *       implementation.
 *
 * @see docs/engineering-knowledge-base/atlas-system-graph.md
 */
final class AtlasSystemGraphService
{
    /** Stable evidence schema id this validator emits. */
    public const SCHEMA = 'atlas.system_graph.l0.v1';

    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';

    /**
     * R1 — "Node Types": the closed set of 9 primary node types, in doc order.
     *
     * @var list<string>
     */
    public const NODE_TYPES = [
        'system',
        'program',
        'module',
        'submodule',
        'artifact',
        'decision',
        'evidence',
        'risk',
        'obsolete',
    ];

    /**
     * R2 — "Relationship Types": the closed set of 10 relations, in doc order.
     *
     * @var list<string>
     */
    public const RELATION_TYPES = [
        'parent',
        'contains',
        'depends_on',
        'unlocks',
        'feeds',
        'governs',
        'implements',
        'replaces',
        'blocked_by',
        'spin_off',
    ];

    /** Relations that may point to at most one target (single-valued). */
    public const SINGLE_VALUED_RELATIONS = ['parent'];

    /**
     * R3 — "Status And Color Semantics": status => [tag, color-bucket].
     * Exactly the doc's two tables fused. 8 statuses; 5 color buckets.
     *
     * @var array<string,array{tag:string,color:string}>
     */
    public const STATUS_SEMANTICS = [
        'active' => ['tag' => '#status/active', 'color' => 'green'],
        'building' => ['tag' => '#status/building', 'color' => 'yellow'],
        'planned' => ['tag' => '#status/planned', 'color' => 'yellow'],
        'future' => ['tag' => '#status/future', 'color' => 'purple'],
        'blocked' => ['tag' => '#status/blocked', 'color' => 'red'],
        'implemented' => ['tag' => '#status/implemented', 'color' => 'green'],
        'obsolete' => ['tag' => '#status/obsolete', 'color' => 'red'],
        'archive' => ['tag' => '#status/archive', 'color' => 'red'],
    ];

    /**
     * R7 — statuses that are valid for a *speculative* (not-yet-built) node.
     * The doc: "mark speculative nodes as `future` or `planned`".
     *
     * @var list<string>
     */
    public const SPECULATIVE_STATUSES = ['planned', 'future'];

    /**
     * R7 — statuses that assert the work already exists ("done"-like). A
     * speculative node may not carry any of these.
     *
     * @var list<string>
     */
    public const DONE_LIKE_STATUSES = ['active', 'implemented'];

    /**
     * R4 — "Maturity Levels": the ordered L0..L4 ladder.
     *
     * @var array<string,int>
     */
    public const MATURITY_LADDER = [
        'L0' => 0, // contract, hierarchy and schema
        'L1' => 1, // real Obsidian nodes with links/status
        'L2' => 2, // graph validated against code/evidence
        'L3' => 3, // graph recommends priorities and risks
        'L4' => 4, // graph supports governed self-modification
    ];

    /**
     * R5 — "Authority": operational authority tiers, highest first. A lower tier
     * may never override a higher tier. Chat is the lowest ("source material
     * only").
     *
     * @var array<string,int>
     */
    public const AUTHORITY_RANK = [
        'repo' => 3,   // repo docs / Postgres / code / tests
        'vault' => 2,  // AtlasVault / Obsidian graph
        'chat' => 1,   // chat history = source material only
    ];

    /**
     * R6 — "Canonical Top Hierarchy": the 12 top-level Atlas systems, in doc
     * order. Definition of Done requires every one to be a node.
     *
     * @var list<string>
     */
    public const CANONICAL_TOP_SYSTEMS = [
        'Atlas Sovereign System',
        'Atlas Evolution System',
        'Atlas AI Kernel System',
        'Atlas Obras System',
        'Atlas Programming / Forge System',
        'Atlas Memory / Vault System',
        'Atlas Evidence System',
        'Atlas Documentation Operating System',
        'Atlas Governance / Policy System',
        'Atlas Domain Systems',
        'Atlas Runtime / Capability System',
        'Atlas Product Surface System',
    ];

    /** R6 — the documented Vault projection paths (Definition of Done). */
    public const VAULT_ROOT_NOTE = 'AtlasVault/00-constituicao/atlas-system-graph.md';
    public const VAULT_MODULE_TEMPLATE = 'AtlasVault/_templates/atlas-system-graph-module-template.md';

    /**
     * R1 — validate a node's primary type against the closed set.
     *
     * @return array{type:string,valid:bool,reason:?string}
     */
    public function classifyNodeType(?string $type): array
    {
        $normalized = strtolower(trim((string) $type));

        if ($normalized === '') {
            return [
                'type' => '',
                'valid' => false,
                'reason' => 'node has no primary type; one of the 9 documented types is required',
            ];
        }

        if (!in_array($normalized, self::NODE_TYPES, true)) {
            return [
                'type' => $normalized,
                'valid' => false,
                'reason' => "type \"{$normalized}\" is not in the closed Node Types set",
            ];
        }

        return ['type' => $normalized, 'valid' => true, 'reason' => null];
    }

    /**
     * R2 — validate a relationship label against the closed set.
     *
     * @return array{relation:string,valid:bool,single_valued:bool,reason:?string}
     */
    public function classifyRelation(?string $relation): array
    {
        $normalized = strtolower(trim((string) $relation));

        if (!in_array($normalized, self::RELATION_TYPES, true)) {
            return [
                'relation' => $normalized,
                'valid' => false,
                'single_valued' => false,
                'reason' => "relation \"{$normalized}\" is not in the closed Relationship Types set",
            ];
        }

        return [
            'relation' => $normalized,
            'valid' => true,
            'single_valued' => in_array($normalized, self::SINGLE_VALUED_RELATIONS, true),
            'reason' => null,
        ];
    }

    /**
     * R3 — resolve a status to its documented Obsidian tag + color bucket.
     * An unknown status is NOT silently colored; it is flagged invalid so the
     * caller cannot invent a color.
     *
     * @return array{status:string,valid:bool,tag:?string,color:?string,reason:?string}
     */
    public function statusSemantics(?string $status): array
    {
        $normalized = strtolower(trim((string) $status));

        if (!array_key_exists($normalized, self::STATUS_SEMANTICS)) {
            return [
                'status' => $normalized,
                'valid' => false,
                'tag' => null,
                'color' => null,
                'reason' => "status \"{$normalized}\" is not one of the 8 documented statuses",
            ];
        }

        $meaning = self::STATUS_SEMANTICS[$normalized];

        return [
            'status' => $normalized,
            'valid' => true,
            'tag' => $meaning['tag'],
            'color' => $meaning['color'],
            'reason' => null,
        ];
    }

    /**
     * R4 — decide whether a maturity claim is admissible given which rungs are
     * already met. The ladder may not skip: claiming Ln requires every rung
     * below n to be met.
     *
     * @param string                $claim    e.g. "L2"
     * @param array<int,string>     $metRungs rungs already satisfied, e.g. ["L0","L1"]
     * @return array{claim:string,admissible:bool,missing_prerequisites:list<string>,reason:?string}
     */
    public function evaluateMaturityClaim(string $claim, array $metRungs): array
    {
        $claimKey = strtoupper(trim($claim));

        if (!array_key_exists($claimKey, self::MATURITY_LADDER)) {
            return [
                'claim' => $claimKey,
                'admissible' => false,
                'missing_prerequisites' => [],
                'reason' => "maturity \"{$claimKey}\" is not a rung on the documented L0..L4 ladder",
            ];
        }

        $met = [];
        foreach ($metRungs as $rung) {
            $met[strtoupper(trim((string) $rung))] = true;
        }

        $claimLevel = self::MATURITY_LADDER[$claimKey];
        $missing = [];
        foreach (self::MATURITY_LADDER as $rung => $level) {
            if ($level < $claimLevel && !isset($met[$rung])) {
                $missing[] = $rung;
            }
        }

        $admissible = $missing === [];

        return [
            'claim' => $claimKey,
            'admissible' => $admissible,
            'missing_prerequisites' => $missing,
            'reason' => $admissible
                ? null
                : 'maturity ladder may not skip rungs; lower rungs are unmet',
        ];
    }

    /**
     * R5 — enforce the authority ordering. Returns whether `claimedAuthority`
     * is allowed to override `incumbentAuthority`. A Vault node can never
     * override repo; chat can never override anything above it.
     *
     * @return array{claimed:string,incumbent:string,override_allowed:bool,reason:?string}
     */
    public function canOverride(string $claimedAuthority, string $incumbentAuthority): array
    {
        $claimed = strtolower(trim($claimedAuthority));
        $incumbent = strtolower(trim($incumbentAuthority));

        $claimedRank = self::AUTHORITY_RANK[$claimed] ?? null;
        $incumbentRank = self::AUTHORITY_RANK[$incumbent] ?? null;

        if ($claimedRank === null || $incumbentRank === null) {
            return [
                'claimed' => $claimed,
                'incumbent' => $incumbent,
                'override_allowed' => false,
                'reason' => 'unknown authority tier; tiers are repo > vault > chat',
            ];
        }

        // A source may override only something strictly lower in authority.
        $allowed = $claimedRank > $incumbentRank;

        return [
            'claimed' => $claimed,
            'incumbent' => $incumbent,
            'override_allowed' => $allowed,
            'reason' => $allowed
                ? null
                : "\"{$claimed}\" may not override \"{$incumbent}\"; repo docs are canonical",
        ];
    }

    /**
     * R1+R2+R3+R7 — validate a single graph node end to end against the L0
     * taxonomy: type in set, status in set, at most one parent, speculative
     * nodes not marked done-like, and (when a Vault projection) a canonical-doc
     * backlink present.
     *
     * Expected node shape:
     *   [
     *     'name'          => string,
     *     'type'          => string,
     *     'status'        => string,
     *     'parents'       => list<string>,   // resolved parent targets
     *     'speculative'   => bool,           // is this a not-yet-built node?
     *     'canonical_doc' => ?string,        // repo doc backlink (Vault rule)
     *     'is_vault_node' => bool,           // is this a Vault projection node?
     *   ]
     *
     * @param array<string,mixed> $node
     * @return array{valid:bool,verdict:string,blocking_reasons:list<string>}
     */
    public function validateNode(array $node): array
    {
        $reasons = [];

        $type = $this->classifyNodeType(isset($node['type']) ? (string) $node['type'] : null);
        if (!$type['valid']) {
            $reasons[] = (string) $type['reason'];
        }

        $status = $this->statusSemantics(isset($node['status']) ? (string) $node['status'] : null);
        if (!$status['valid']) {
            $reasons[] = (string) $status['reason'];
        }

        // R2 — single-valued parent.
        $parents = isset($node['parents']) && is_array($node['parents']) ? $node['parents'] : [];
        if (count($parents) > 1) {
            $reasons[] = 'node declares more than one parent; `parent` is single-valued';
        }

        // R7 — a speculative node must use a speculative status, never done-like.
        $speculative = (bool) ($node['speculative'] ?? false);
        $statusToken = strtolower(trim((string) ($node['status'] ?? '')));
        if ($speculative && in_array($statusToken, self::DONE_LIKE_STATUSES, true)) {
            $reasons[] = "speculative node may not claim done-like status \"{$statusToken}\"; use future or planned";
        }

        // R7 — a Vault projection node must link back to a canonical repo doc.
        $isVaultNode = (bool) ($node['is_vault_node'] ?? false);
        $canonicalDoc = trim((string) ($node['canonical_doc'] ?? ''));
        if ($isVaultNode && $canonicalDoc === '') {
            $reasons[] = 'Vault node has no canonical repo doc backlink; Vault must link back to repo docs';
        }

        $valid = $reasons === [];

        return [
            'valid' => $valid,
            'verdict' => $valid ? self::STATUS_PASS : self::STATUS_FAIL,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * R7 — scan a Vault projection payload for forbidden content. The doc:
     * "do not store secrets, credentials or raw provider context" and "never
     * let a Vault note become the only source for implementation".
     *
     * @param array<string,mixed> $projection
     *   ['nodes' => list<array>, 'has_repo_backlink' => bool, 'contains_secret' => bool,
     *    'contains_raw_provider_context' => bool]
     * @return array{valid:bool,blocking_reasons:list<string>}
     */
    public function validateVaultProjection(array $projection): array
    {
        $reasons = [];

        if ((bool) ($projection['contains_secret'] ?? false)) {
            $reasons[] = 'Vault projection contains secrets/credentials; forbidden';
        }

        if ((bool) ($projection['contains_raw_provider_context'] ?? false)) {
            $reasons[] = 'Vault projection contains raw provider context; forbidden';
        }

        // "never let a Vault note become the only source": there must be a repo
        // backlink so implementation is anchored in canonical docs.
        if (!((bool) ($projection['has_repo_backlink'] ?? false))) {
            $reasons[] = 'Vault projection is not linked back to any canonical repo doc';
        }

        return [
            'valid' => $reasons === [],
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * R6 — "Definition Of Done": evaluate a candidate System Graph against the
     * L0 DoD. Requires all 12 top-level systems present as nodes, every node to
     * pass {@see validateNode()}, and the documented Vault paths.
     *
     * @param array<string,mixed> $graph
     *   [
     *     'nodes'               => list<array>,  // each a validateNode() shape with 'name'
     *     'vault_root_note'     => ?string,
     *     'vault_module_template' => ?string,
     *   ]
     * @return array{
     *   schema:string,
     *   status:string,
     *   present_top_systems:list<string>,
     *   missing_top_systems:list<string>,
     *   invalid_nodes:list<array{name:string,blocking_reasons:list<string>}>,
     *   vault_paths_ok:bool,
     *   blocking_reasons:list<string>
     * }
     */
    public function checkDefinitionOfDone(array $graph): array
    {
        $nodes = isset($graph['nodes']) && is_array($graph['nodes']) ? $graph['nodes'] : [];

        // Index node names for top-hierarchy coverage.
        $names = [];
        foreach ($nodes as $node) {
            if (is_array($node) && isset($node['name'])) {
                $names[trim((string) $node['name'])] = true;
            }
        }

        $present = [];
        $missing = [];
        foreach (self::CANONICAL_TOP_SYSTEMS as $system) {
            if (isset($names[$system])) {
                $present[] = $system;
            } else {
                $missing[] = $system;
            }
        }

        // Every node must individually pass the taxonomy validation.
        $invalidNodes = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $result = $this->validateNode($node);
            if (!$result['valid']) {
                $invalidNodes[] = [
                    'name' => trim((string) ($node['name'] ?? '(unnamed)')),
                    'blocking_reasons' => $result['blocking_reasons'],
                ];
            }
        }

        $vaultRoot = trim((string) ($graph['vault_root_note'] ?? ''));
        $vaultTemplate = trim((string) ($graph['vault_module_template'] ?? ''));
        $vaultPathsOk = $vaultRoot === self::VAULT_ROOT_NOTE
            && $vaultTemplate === self::VAULT_MODULE_TEMPLATE;

        $reasons = [];
        if ($missing !== []) {
            $reasons[] = count($missing) . ' top-level system(s) missing from the graph';
        }
        if ($invalidNodes !== []) {
            $reasons[] = count($invalidNodes) . ' node(s) violate the L0 taxonomy';
        }
        if (!$vaultPathsOk) {
            $reasons[] = 'Vault projection paths do not match the documented locations';
        }

        return [
            'schema' => self::SCHEMA,
            'status' => $reasons === [] ? self::STATUS_PASS : self::STATUS_FAIL,
            'present_top_systems' => $present,
            'missing_top_systems' => $missing,
            'invalid_nodes' => $invalidNodes,
            'vault_paths_ok' => $vaultPathsOk,
            'blocking_reasons' => $reasons,
        ];
    }
}
