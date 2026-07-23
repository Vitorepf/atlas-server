<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Living Architecture Graph (L1) — pure, deterministic validator for the
 * node-update and Definition-of-Done rules of the Living Architecture Graph
 * contract.
 *
 * The doc governs L1: the live Obsidian realization of the Atlas System Graph,
 * one markdown note per node, with status, dependencies, unlocks, evidence and
 * next actions. It is explicitly a navigation/decision surface, NOT the source
 * of executable truth, so this service never mutates a Vault file, never runs a
 * provider and never promotes status. It only classifies a candidate node (or a
 * candidate write, or a whole graph) against the documented rules and emits
 * blocking reasons.
 *
 * Documented rules enforced (one method per rule surface):
 *
 *   - "Status Meaning In Living Graph" (8-status closed set) + the "Node Update
 *     Rules" bullet "must add a next action unless the node is implemented,
 *     obsolete or archived". => `implemented`, `obsolete`, `archive` are the
 *     TERMINAL statuses; everything else is non-terminal and owes a next action.
 *
 *   - "must prefer `planned` when status is uncertain" + "`planned` or `future`
 *     status instead of pretending unknown work is done". => an absent / empty /
 *     unknown status is coerced to `planned`, never silently dropped.
 *
 *   - "must not mark a node `implemented` without evidence" + "evidence links
 *     where implementation already exists" (capability 9). => a node claiming
 *     `implemented` with no acceptable evidence is rejected.
 *
 *   - "must not use chat memory as canonical evidence" + "must link back to repo
 *     docs, tests, commands or code paths". => evidence sourced from chat memory
 *     is not acceptable; only repo doc / test / command / code-path evidence is.
 *
 *   - "frontmatter with type, status, owner, parent and canonical doc"
 *     (capability 7) + Definition Of Done "all nodes have type, status, parent,
 *     canonical doc and next action". => the required frontmatter keys are
 *     mandatory on every node.
 *
 *   - "First Build Scope" allowed write paths + the "Forbidden in this L1 build"
 *     list (changing app code, migrations, providers, deleting Vault notes).
 *     => a write outside the Vault graph folder, or that touches app code /
 *     migrations / providers, or deletes an existing note, is rejected.
 *
 *   - "Definition Of Done" required-node set (every top-level system, the
 *     Self-Construction OS and Self-Programming OS, Agent Control Plane, Work
 *     Splitter, Scope Validator and AI Implementation Packet) + "no node claims
 *     implementation without evidence" + "another AI can open the Vault graph and
 *     understand where to work next" (every non-terminal node has a next action).
 *
 * @see docs/engineering-knowledge-base/system-graph/living-architecture-graph-contract.md
 */
final class AtlasLivingArchitectureGraphContractService
{
    /** Stable evidence schema id this validator emits. */
    public const SCHEMA = 'atlas.living_architecture_graph.l1.v1';

    /** Verdicts (closed set). */
    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';

    /**
     * The 8 documented statuses from "Status Meaning In Living Graph" (in doc
     * order). This is the closed set; any other token is "uncertain".
     *
     * @var list<string>
     */
    public const NODE_STATUSES = [
        'active',
        'building',
        'planned',
        'future',
        'blocked',
        'implemented',
        'obsolete',
        'archive',
    ];

    /**
     * Terminal statuses: the "Node Update Rules" say a next action is required
     * "unless the node is implemented, obsolete or archived". Those three are the
     * only statuses that do NOT owe a next action.
     *
     * @var list<string>
     */
    public const TERMINAL_STATUSES = [
        'implemented',
        'obsolete',
        'archive',
    ];

    /**
     * The status an uncertain / unknown / empty status is coerced to. The doc:
     * "must prefer `planned` when status is uncertain".
     */
    public const UNCERTAIN_FALLBACK = 'planned';

    /**
     * Required frontmatter keys. From capability 7 ("frontmatter with type,
     * status, owner, parent and canonical doc") unioned with the Definition Of
     * Done line ("all nodes have type, status, parent, canonical doc and next
     * action"). `next_actions` is handled by the terminal rule, not here.
     *
     * @var list<string>
     */
    public const REQUIRED_FRONTMATTER = [
        'type',
        'status',
        'owner',
        'parent',
        'canonical_doc',
    ];

    /**
     * Acceptable evidence source kinds. The doc: "must link back to repo docs,
     * tests, commands or code paths". Chat memory is deliberately excluded:
     * "must not use chat memory as canonical evidence".
     *
     * @var list<string>
     */
    public const ACCEPTABLE_EVIDENCE_KINDS = [
        'doc',
        'test',
        'command',
        'code',
    ];

    /** Evidence kind that is explicitly NOT canonical. */
    public const FORBIDDEN_EVIDENCE_KIND = 'chat_memory';

    /**
     * Allowed write-scope path prefixes for the L1 build ("First Build Scope").
     * Only the Vault system-graph folder, its root note and the module template.
     *
     * @var list<string>
     */
    public const ALLOWED_WRITE_PREFIXES = [
        'AtlasVault/00-constituicao/atlas-system-graph/',
        'AtlasVault/00-constituicao/atlas-system-graph.md',
        'AtlasVault/_templates/atlas-system-graph-module-template.md',
    ];

    /**
     * Path fragments that mark a forbidden write target ("Forbidden in this L1
     * build": changing app code, migrations, providers).
     *
     * @var array<string,string>  fragment => violation code
     */
    private const FORBIDDEN_WRITE_FRAGMENTS = [
        'app/' => 'changes_app_code',
        'database/migrations/' => 'changes_migrations',
        'ServiceProvider' => 'changes_providers',
        'app/Providers/' => 'changes_providers',
    ];

    /**
     * The node ids the Definition Of Done requires to exist (the explicit
     * bullets: Self-Construction OS, Self-Programming OS, Agent Control Plane,
     * Work Splitter, Scope Validator, AI Implementation Packet). The set of
     * "top-level systems" is supplied at call time, since the catalog owns it.
     *
     * @var list<string>
     */
    public const DEFINITION_OF_DONE_REQUIRED_NODES = [
        'self-construction-os',
        'self-programming-os',
        'agent-control-plane',
        'work-splitter',
        'scope-validator',
        'ai-implementation-packet',
    ];

    /**
     * Resolve a candidate status into a canonical one, applying the "prefer
     * `planned` when uncertain" rule. An empty / unknown token never passes
     * through silently — it is coerced and the coercion is reported.
     *
     * @return array<string,mixed>
     */
    public function resolveStatus(?string $status): array
    {
        $raw = is_string($status) ? strtolower(trim($status)) : '';
        $known = $raw !== '' && in_array($raw, self::NODE_STATUSES, true);

        $resolved = $known ? $raw : self::UNCERTAIN_FALLBACK;

        return [
            'input' => $status,
            'known' => $known,
            'coerced' => ! $known,
            'resolved_status' => $resolved,
            'is_terminal' => in_array($resolved, self::TERMINAL_STATUSES, true),
            // The doc forbids "pretending unknown work is done": an uncertain
            // status may NEVER resolve to implemented.
            'requires_next_action' => ! in_array($resolved, self::TERMINAL_STATUSES, true),
        ];
    }

    /**
     * Classify a single evidence reference. Acceptable only when it carries a
     * non-empty repo reference (path/command) AND its kind is one of doc / test /
     * command / code. A `chat_memory` kind is rejected outright.
     *
     * @param array<string,mixed> $evidence  expects: kind, ref
     * @return array<string,mixed>
     */
    public function classifyEvidence(array $evidence): array
    {
        $kind = is_string($evidence['kind'] ?? null) ? strtolower(trim((string) $evidence['kind'])) : '';
        $ref = is_string($evidence['ref'] ?? null) ? trim((string) $evidence['ref']) : '';

        $reasons = [];

        if ($kind === self::FORBIDDEN_EVIDENCE_KIND) {
            // "must not use chat memory as canonical evidence"
            $reasons[] = 'chat_memory is not canonical evidence';
        } elseif (! in_array($kind, self::ACCEPTABLE_EVIDENCE_KINDS, true)) {
            $reasons[] = "evidence kind '{$kind}' is not one of doc/test/command/code";
        }

        if ($ref === '') {
            // "must link back to repo docs, tests, commands or code paths"
            $reasons[] = 'evidence has no repo reference (doc/test/command/code path)';
        }

        $acceptable = $reasons === [];

        return [
            'kind' => $kind,
            'ref' => $ref,
            'acceptable' => $acceptable,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Does a node carry at least one acceptable evidence reference?
     *
     * @param list<array<string,mixed>> $evidence
     * @return array<string,mixed>
     */
    public function hasAcceptableEvidence(array $evidence): array
    {
        $accepted = [];
        $rejected = [];
        foreach ($evidence as $item) {
            if (! is_array($item)) {
                continue;
            }
            $c = $this->classifyEvidence($item);
            if ($c['acceptable']) {
                $accepted[] = $c['ref'];
            } else {
                $rejected[] = $c;
            }
        }

        return [
            'has_acceptable' => $accepted !== [],
            'accepted_refs' => $accepted,
            'rejected' => $rejected,
        ];
    }

    /**
     * Validate a single Living Graph node against the Node Update Rules,
     * required frontmatter and the Minimum Node Body status/evidence/next-action
     * obligations.
     *
     * Node shape:
     *   id            : string
     *   frontmatter   : array<string,mixed>   (type/status/owner/parent/canonical_doc/...)
     *   status        : string|null           (may also live in frontmatter)
     *   next_actions  : list<string>|string|null
     *   evidence      : list<array{kind,ref}>
     *
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    public function validateNode(array $node): array
    {
        $reasons = [];

        $id = is_string($node['id'] ?? null) ? trim((string) $node['id']) : '';
        if ($id === '') {
            $reasons[] = 'node has no id';
        }

        $frontmatter = is_array($node['frontmatter'] ?? null) ? $node['frontmatter'] : [];

        // Status may be a top-level field or live in frontmatter; top-level wins.
        $rawStatus = $node['status'] ?? ($frontmatter['status'] ?? null);
        $statusInfo = $this->resolveStatus(is_string($rawStatus) ? $rawStatus : null);
        $status = $statusInfo['resolved_status'];

        // --- Required frontmatter (capability 7 + Definition Of Done) ---------
        $missingFrontmatter = [];
        foreach (self::REQUIRED_FRONTMATTER as $key) {
            // `status` is satisfied if it resolves (top-level or frontmatter);
            // the others must be present and non-empty in frontmatter.
            if ($key === 'status') {
                continue;
            }
            if (! $this->present($frontmatter, $key)) {
                $missingFrontmatter[] = $key;
            }
        }
        if ($missingFrontmatter !== []) {
            $reasons[] = 'missing required frontmatter: ' . implode(', ', $missingFrontmatter);
        }

        // --- Next action obligation -------------------------------------------
        // "must add a next action unless the node is implemented, obsolete or
        // archived" (terminal statuses).
        $nextActions = $this->normalizeList($node['next_actions'] ?? null);
        $hasNextAction = $nextActions !== [];
        if ($statusInfo['requires_next_action'] && ! $hasNextAction) {
            $reasons[] = "non-terminal node (status '{$status}') has no next action";
        }

        // --- Evidence obligation for `implemented` ----------------------------
        // "must not mark a node `implemented` without evidence."
        $evidence = is_array($node['evidence'] ?? null) ? array_values($node['evidence']) : [];
        $evidenceInfo = $this->hasAcceptableEvidence($evidence);
        if ($status === 'implemented' && ! $evidenceInfo['has_acceptable']) {
            $reasons[] = 'status is implemented but no acceptable repo evidence is linked';
        }

        $valid = $reasons === [];

        return [
            'id' => $id,
            'status' => $status,
            'status_coerced' => $statusInfo['coerced'],
            'is_terminal' => $statusInfo['is_terminal'],
            'missing_frontmatter' => $missingFrontmatter,
            'has_next_action' => $hasNextAction,
            'has_acceptable_evidence' => $evidenceInfo['has_acceptable'],
            'valid' => $valid,
            'verdict' => $valid ? self::STATUS_PASS : self::STATUS_FAIL,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Validate a candidate write against the L1 "First Build Scope" / "Forbidden"
     * list. A write is permitted only when its path sits under an allowed Vault
     * prefix, it touches no app code / migration / provider, and it is not a
     * delete of an existing note.
     *
     * @param array<string,mixed> $write  expects: path, operation ('create'|'update'|'delete')
     * @return array<string,mixed>
     */
    public function checkWriteScope(array $write): array
    {
        $path = is_string($write['path'] ?? null) ? trim((string) $write['path']) : '';
        $operation = is_string($write['operation'] ?? null) ? strtolower(trim((string) $write['operation'])) : 'update';

        $reasons = [];

        if ($path === '') {
            $reasons[] = 'write has no path';
        }

        // Forbidden fragments first — these are hard "no" regardless of prefix.
        $violations = [];
        foreach (self::FORBIDDEN_WRITE_FRAGMENTS as $fragment => $code) {
            if ($path !== '' && str_contains($path, $fragment)) {
                $violations[$code] = true;
            }
        }
        foreach (array_keys($violations) as $code) {
            $reasons[] = "forbidden L1 write target: {$code}";
        }

        // Must sit under an allowed Vault prefix.
        $inAllowedScope = false;
        if ($path !== '') {
            foreach (self::ALLOWED_WRITE_PREFIXES as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    $inAllowedScope = true;
                    break;
                }
            }
        }
        if ($path !== '' && ! $inAllowedScope) {
            $reasons[] = 'write path is outside the allowed L1 Vault scope';
        }

        // "deleting existing Vault notes" is forbidden in this L1 build.
        if ($operation === 'delete') {
            $reasons[] = 'deleting Vault notes is forbidden in the L1 build';
        }

        $permitted = $reasons === [];

        return [
            'path' => $path,
            'operation' => $operation,
            'in_allowed_scope' => $inAllowedScope,
            'forbidden_targets' => array_keys($violations),
            'permitted' => $permitted,
            'verdict' => $permitted ? self::STATUS_PASS : self::STATUS_FAIL,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Evaluate a whole candidate graph against the Definition Of Done.
     *
     * Graph shape:
     *   top_level_systems : list<string>  (catalog-owned set of system node ids)
     *   nodes             : list<node>    (each as accepted by validateNode)
     *
     * The graph is "done" only when: every required node id is present, every
     * node passes validateNode, and no node claims `implemented` without
     * evidence (already enforced per-node, re-surfaced as an aggregate).
     *
     * @param array<string,mixed> $graph
     * @return array<string,mixed>
     */
    public function checkDefinitionOfDone(array $graph): array
    {
        $nodes = is_array($graph['nodes'] ?? null) ? array_values($graph['nodes']) : [];
        $topLevel = $this->normalizeList($graph['top_level_systems'] ?? null);

        $presentIds = [];
        $nodeResults = [];
        $invalidNodes = [];
        $unevidencedImplemented = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $r = $this->validateNode($node);
            $nodeResults[] = $r;
            if ($r['id'] !== '') {
                $presentIds[] = $r['id'];
            }
            if (! $r['valid']) {
                $invalidNodes[] = $r['id'];
            }
            if ($r['status'] === 'implemented' && ! $r['has_acceptable_evidence']) {
                $unevidencedImplemented[] = $r['id'];
            }
        }
        $presentIds = array_values(array_unique($presentIds));

        // Required = explicit DoD nodes UNION the catalog-supplied top-level
        // systems ("every top-level system has a node").
        $required = array_values(array_unique(array_merge(
            self::DEFINITION_OF_DONE_REQUIRED_NODES,
            $topLevel,
        )));
        $missingNodes = array_values(array_diff($required, $presentIds));

        $reasons = [];
        if ($missingNodes !== []) {
            $reasons[] = 'missing required nodes: ' . implode(', ', $missingNodes);
        }
        if ($invalidNodes !== []) {
            $reasons[] = 'nodes failing validation: ' . implode(', ', array_filter($invalidNodes));
        }
        if ($unevidencedImplemented !== []) {
            // "no node claims implementation without evidence"
            $reasons[] = 'nodes claiming implemented without evidence: ' . implode(', ', array_filter($unevidencedImplemented));
        }

        $done = $reasons === [];

        return [
            'schema' => self::SCHEMA,
            'status' => $done ? self::STATUS_PASS : self::STATUS_FAIL,
            'required_nodes' => $required,
            'present_nodes' => $presentIds,
            'missing_nodes' => $missingNodes,
            'invalid_nodes' => array_values(array_filter($invalidNodes)),
            'unevidenced_implemented' => array_values(array_filter($unevidencedImplemented)),
            'node_count' => count($nodeResults),
            'definition_of_done_met' => $done,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Normalize a next-actions / list field into a clean list<string> of
     * non-empty trimmed entries.
     *
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeList($value): array
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? [] : [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $item = trim($item);
                if ($item !== '') {
                    $out[] = $item;
                }
            }
        }

        return $out;
    }

    /**
     * A frontmatter field is present when its key exists and the value is
     * non-empty (null / "" / [] count as absent).
     *
     * @param array<string,mixed> $bag
     */
    private function present(array $bag, string $field): bool
    {
        if (! array_key_exists($field, $bag)) {
            return false;
        }
        $value = $bag[$field];
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }
}
