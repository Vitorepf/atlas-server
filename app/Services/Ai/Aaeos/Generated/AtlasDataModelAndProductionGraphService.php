<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Obras — Data Model And Production Graph: pure, deterministic validator
 * for the documented Obras data model, its entity vocabulary, the per-entity
 * state machines and the production graph of relationships.
 *
 * The doc is the authoring boundary. This service turns its concrete contract
 * surfaces into runtime. Each method validates ONE documented surface and
 * returns a typed, blocking verdict. It is read-only: it classifies, lists the
 * blocking reasons and reports graph reachability; it never mutates Obra state,
 * invents an entity, accepts a status outside the closed set, or treats a
 * Markdown projection as the runtime source of truth.
 *
 * Documented contract surfaces this code enforces (one method per surface):
 *   - Core Entities: the canonical, closed set of 18 Obra tables. An unknown
 *       table name is rejected; a missing canonical table is reported.
 *       -> validateEntities()
 *   - Node state machine: the 8 documented node statuses, and the ordered
 *       lifecycle empty -> draft -> in construction -> ... -> published. A status
 *       outside the closed set is rejected; a backwards/skip-to-published jump is
 *       flagged (advance one step, branch only into the documented blocked states).
 *       -> validateNodeStatus()
 *   - Source registry state machine: the 6 documented source statuses
 *       (captured, read, fichada, approved, rejected, used) as a closed set.
 *       -> validateSourceStatus()
 *   - Decision lifecycle: active|revised|revoked as a closed set; a revoked
 *       decision can no longer be the active driver of an Obra. -> validateDecisionStatus()
 *   - Version ladder: the documented progression v0.1 idea -> v0.2 structure ->
 *       v0.3 draft -> v0.4 review -> v1.0 delivery -> v1.1 post-feedback, and the
 *       rule that v1.0 (delivery) requires at least one gate run.
 *       -> validateVersionProgression()
 *   - Production graph: the documented relationship edges and the core chain
 *       Source -> Evidence -> Claim -> Section -> Version -> Output. Reports
 *       whether a target node is reachable from a start node over the canonical
 *       edges. -> traceGraphPath()
 *   - Storage ownership: Postgres is the live state source; Markdown is a
 *       projection/export and must never be the sole runtime source of truth.
 *       -> validateStorageOwnership()
 *   - Evidence event vocabulary: the closed list of recordable evidence events.
 *       -> validateEvidenceEvent()
 *
 * Non-goals honoured: it does not run providers, does not write evidence, does
 * not re-implement the level-promotion / MVP-acceptance rules owned by
 * AtlasObrasContractsAndInvariantsService, and does not own the metrics thresholds.
 *
 * @see docs/engineering-knowledge-base/obras/data-model-and-production-graph.md
 */
final class AtlasDataModelAndProductionGraphService
{
    /** Stable evidence schema id this validator emits. */
    public const SCHEMA = 'atlas.obras.data_model_and_production_graph.v1';

    /** Verdicts (closed set). */
    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';

    /**
     * Core Entities — the canonical, closed set of Obra tables the doc enumerates.
     * Order preserved as documented.
     *
     * @var list<string>
     */
    public const CORE_ENTITIES = [
        'obras',
        'obra_nodes',
        'obra_notes',
        'obra_tasks',
        'obra_sources',
        'obra_claims',
        'obra_decisions',
        'obra_feedbacks',
        'obra_versions',
        'obra_outputs',
        'obra_gate_templates',
        'obra_gate_runs',
        'obra_evidence_events',
        'obra_ai_sessions',
        'obra_context_packs',
        'obra_assets',
        'obra_dependencies',
        'obra_metrics',
    ];

    /**
     * Node statuses — the documented closed lifecycle. The list is ordered: the
     * "main line" advances empty -> draft -> in construction -> in review ->
     * approved -> published, with needs_source / needs_decision as documented
     * blocked branches reachable from an in-construction node.
     *
     * @var list<string>
     */
    public const NODE_STATUSES = [
        'empty',
        'draft',
        'in construction',
        'needs source',
        'needs decision',
        'in review',
        'approved',
        'published',
    ];

    /**
     * The documented forward main line for nodes (excludes the blocked branches).
     * Advancing must move forward one step along this line; the two blocked
     * states are only reachable from "in construction" and resolve back to it.
     *
     * @var list<string>
     */
    public const NODE_MAIN_LINE = [
        'empty',
        'draft',
        'in construction',
        'in review',
        'approved',
        'published',
    ];

    /**
     * Blocked node states — documented states that pause the main line. They are
     * entered from "in construction" and, once resolved, return to it.
     *
     * @var list<string>
     */
    public const NODE_BLOCKED_STATES = [
        'needs source',
        'needs decision',
    ];

    /**
     * Source registry statuses — documented closed set.
     *
     * @var list<string>
     */
    public const SOURCE_STATUSES = [
        'captured',
        'read',
        'fichada',
        'approved',
        'rejected',
        'used',
    ];

    /**
     * Decision lifecycle statuses — documented closed set.
     *
     * @var list<string>
     */
    public const DECISION_STATUSES = [
        'active',
        'revised',
        'revoked',
    ];

    /**
     * Version ladder — the documented example progression. Maps the semantic
     * sequence to its documented milestone label, in order.
     *
     * @var array<string, string>
     */
    public const VERSION_LADDER = [
        'v0.1' => 'idea',
        'v0.2' => 'structure',
        'v0.3' => 'draft',
        'v0.4' => 'review',
        'v1.0' => 'delivery',
        'v1.1' => 'post-feedback',
    ];

    /** The documented delivery version that may not ship without a gate run. */
    public const DELIVERY_VERSION = 'v1.0';

    /**
     * Production graph edges — the documented relationships, as a directed
     * adjacency map. Node ids match the doc's wording (normalised to lower snake).
     * Includes the explicit relationship list AND the core chain
     * Source -> Evidence -> Claim -> Section -> Version -> Output.
     *
     * @var array<string, list<string>>
     */
    public const GRAPH_EDGES = [
        'obra' => ['node', 'asset', 'obra'],
        'node' => ['task', 'note', 'source', 'claim', 'section'],
        'source' => ['claim', 'evidence'],
        'evidence' => ['claim', 'event'],
        'claim' => ['section'],
        'section' => ['version'],
        'decision' => ['obra', 'task'],
        'feedback' => ['task'],
        'task' => ['change'],
        'change' => ['gate'],
        'gate' => ['version', 'approval'],
        'version' => ['output'],
        'output' => ['asset'],
        'asset' => ['obra', 'portfolio'],
    ];

    /**
     * Evidence event vocabulary — the documented closed list of recordable
     * evidence events (normalised to lower snake).
     *
     * @var list<string>
     */
    public const EVIDENCE_EVENTS = [
        'obra_created',
        'source_added',
        'source_approved',
        'decision_taken',
        'task_completed',
        'gate_executed',
        'gate_failed',
        'gate_approved',
        'version_published',
        'feedback_received',
        'output_generated',
        'ai_used',
        'model_used',
        'context_pack_used',
        'human_approval',
    ];

    /**
     * Entity-set validation. Passes only when every supplied table is a canonical
     * Obra entity (no unknown tables) AND every canonical entity is present.
     *
     * @param list<string> $tables
     * @return array{schema: string, surface: string, status: string, present: list<string>, missing: list<string>, unknown: list<string>, total_canonical: int, blocking_reasons: list<string>}
     */
    public function validateEntities(array $tables): array
    {
        $normalized = array_values(array_unique(array_map(
            static fn ($t): string => strtolower(trim((string) $t)),
            $tables,
        )));

        $present = [];
        $missing = [];
        foreach (self::CORE_ENTITIES as $entity) {
            if (in_array($entity, $normalized, true)) {
                $present[] = $entity;
            } else {
                $missing[] = $entity;
            }
        }

        $unknown = [];
        foreach ($normalized as $table) {
            if ($table !== '' && ! in_array($table, self::CORE_ENTITIES, true)) {
                $unknown[] = $table;
            }
        }

        $reasons = [];
        foreach ($missing as $entity) {
            $reasons[] = 'entity_missing: canonical Obra table not present: ' . $entity;
        }
        foreach ($unknown as $table) {
            $reasons[] = 'entity_unknown: not a canonical Obra table: ' . $table;
        }

        return [
            'schema' => self::SCHEMA,
            'surface' => 'core_entities',
            'status' => ($missing === [] && $unknown === []) ? self::STATUS_PASS : self::STATUS_FAIL,
            'present' => $present,
            'missing' => $missing,
            'unknown' => $unknown,
            'total_canonical' => count(self::CORE_ENTITIES),
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Node status-transition validation against the documented lifecycle.
     *
     * Rules enforced:
     *   - both statuses must belong to the closed node-status set;
     *   - a node may advance exactly one step forward on the main line;
     *   - from "in construction" a node may branch into a documented blocked state
     *     (needs source / needs decision); a blocked state may only return to
     *     "in construction";
     *   - jumping straight to "published" (skipping review/approval) is rejected;
     *   - staying on the same status is a no-op (allowed, not an advance).
     *
     * @return array{schema: string, surface: string, status: string, from: string, to: string, allowed: bool, kind: string, blocking_reasons: list<string>}
     */
    public function validateNodeStatus(string $from, string $to): array
    {
        $from = strtolower(trim($from));
        $to = strtolower(trim($to));

        $fromKnown = in_array($from, self::NODE_STATUSES, true);
        $toKnown = in_array($to, self::NODE_STATUSES, true);

        if (! $fromKnown || ! $toKnown) {
            $bad = [];
            if (! $fromKnown) {
                $bad[] = $from;
            }
            if (! $toKnown) {
                $bad[] = $to;
            }

            return $this->nodeVerdict($from, $to, false, 'unknown_status', [
                'node_status_unknown: status outside the closed node-status set: ' . implode(', ', $bad),
            ]);
        }

        if ($from === $to) {
            return $this->nodeVerdict($from, $to, true, 'no_op', []);
        }

        // Branch into a blocked state from in construction.
        if ($from === 'in construction' && in_array($to, self::NODE_BLOCKED_STATES, true)) {
            return $this->nodeVerdict($from, $to, true, 'branch_blocked', []);
        }

        // Resolve a blocked state back to in construction.
        if (in_array($from, self::NODE_BLOCKED_STATES, true) && $to === 'in construction') {
            return $this->nodeVerdict($from, $to, true, 'resolve_blocked', []);
        }

        // Otherwise both must be on the main line and advance exactly one step.
        $fromIdx = array_search($from, self::NODE_MAIN_LINE, true);
        $toIdx = array_search($to, self::NODE_MAIN_LINE, true);

        if ($fromIdx === false || $toIdx === false) {
            return $this->nodeVerdict($from, $to, false, 'off_main_line', [
                'node_transition_invalid: a blocked state may only resolve back to "in construction".',
            ]);
        }

        if ($toIdx === $fromIdx + 1) {
            return $this->nodeVerdict($from, $to, true, 'advance', []);
        }

        return $this->nodeVerdict($from, $to, false, 'invalid', [
            'node_transition_invalid: a node advances one step along the documented lifecycle; it cannot skip to "' . $to . '".',
        ]);
    }

    /**
     * @param list<string> $reasons
     * @return array{schema: string, surface: string, status: string, from: string, to: string, allowed: bool, kind: string, blocking_reasons: list<string>}
     */
    private function nodeVerdict(string $from, string $to, bool $allowed, string $kind, array $reasons): array
    {
        return [
            'schema' => self::SCHEMA,
            'surface' => 'node_status',
            'status' => $allowed ? self::STATUS_PASS : self::STATUS_FAIL,
            'from' => $from,
            'to' => $to,
            'allowed' => $allowed,
            'kind' => $kind,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Source status validation: passes only when the status is in the documented
     * closed set.
     *
     * @return array{schema: string, surface: string, status: string, value: string, known: bool, allowed_values: list<string>, blocking_reasons: list<string>}
     */
    public function validateSourceStatus(string $value): array
    {
        $value = strtolower(trim($value));
        $known = in_array($value, self::SOURCE_STATUSES, true);

        return [
            'schema' => self::SCHEMA,
            'surface' => 'source_status',
            'status' => $known ? self::STATUS_PASS : self::STATUS_FAIL,
            'value' => $value,
            'known' => $known,
            'allowed_values' => self::SOURCE_STATUSES,
            'blocking_reasons' => $known
                ? []
                : ['source_status_unknown: status outside the documented source registry set: ' . $value],
        ];
    }

    /**
     * Decision lifecycle validation. The status must be in the closed set, and a
     * revoked (or revised) decision may no longer be the *active driver* of an
     * Obra — only an active decision may currently change the Obra.
     *
     * @return array{schema: string, surface: string, status: string, value: string, known: bool, can_drive_obra: bool, allowed_values: list<string>, blocking_reasons: list<string>}
     */
    public function validateDecisionStatus(string $value): array
    {
        $value = strtolower(trim($value));
        $known = in_array($value, self::DECISION_STATUSES, true);
        $canDrive = $value === 'active';

        $reasons = [];
        if (! $known) {
            $reasons[] = 'decision_status_unknown: status outside the documented decision set: ' . $value;
        } elseif (! $canDrive) {
            $reasons[] = 'decision_not_active: a ' . $value . ' decision can no longer be the active driver of the Obra.';
        }

        return [
            'schema' => self::SCHEMA,
            'surface' => 'decision_status',
            'status' => $known ? self::STATUS_PASS : self::STATUS_FAIL,
            'value' => $value,
            'known' => $known,
            'can_drive_obra' => $canDrive,
            'allowed_values' => self::DECISION_STATUSES,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Version progression validation against the documented ladder.
     *
     * Rules enforced:
     *   - both versions must be on the documented ladder;
     *   - progression must move forward (a later milestone than the current one);
     *   - reaching the delivery version v1.0 requires at least one gate run
     *     (the doc says versions "must track ... gates run", and a Gate validates
     *     a Version before it becomes a delivery).
     *
     * @return array{schema: string, surface: string, status: string, from: string, to: string, from_milestone: string|null, to_milestone: string|null, advanced: bool, blocking_reasons: list<string>}
     */
    public function validateVersionProgression(string $from, string $to, bool $hasGateRun = false): array
    {
        $from = strtolower(trim($from));
        $to = strtolower(trim($to));

        $ladder = array_keys(self::VERSION_LADDER);
        $fromIdx = array_search($from, $ladder, true);
        $toIdx = array_search($to, $ladder, true);

        $reasons = [];
        if ($fromIdx === false) {
            $reasons[] = 'version_off_ladder: not a documented version milestone: ' . $from;
        }
        if ($toIdx === false) {
            $reasons[] = 'version_off_ladder: not a documented version milestone: ' . $to;
        }

        $advanced = false;
        if ($fromIdx !== false && $toIdx !== false) {
            if ($toIdx > $fromIdx) {
                $advanced = true;
            } else {
                $reasons[] = 'version_not_forward: progression must move forward on the documented ladder.';
            }

            if ($to === self::DELIVERY_VERSION && ! $hasGateRun) {
                $reasons[] = 'delivery_without_gate: ' . self::DELIVERY_VERSION . ' (delivery) requires at least one gate run.';
            }
        }

        $pass = $reasons === [];

        return [
            'schema' => self::SCHEMA,
            'surface' => 'version_progression',
            'status' => $pass ? self::STATUS_PASS : self::STATUS_FAIL,
            'from' => $from,
            'to' => $to,
            'from_milestone' => $fromIdx !== false ? self::VERSION_LADDER[$from] : null,
            'to_milestone' => $toIdx !== false ? self::VERSION_LADDER[$to] : null,
            'advanced' => $advanced && $pass,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Production graph reachability. Walks the documented directed edges from
     * $from and reports whether $to is reachable, plus one concrete path if so.
     *
     * Used to prove documented chains such as
     * source -> evidence -> claim -> section -> version -> output.
     *
     * @return array{schema: string, surface: string, status: string, from: string, to: string, reachable: bool, path: list<string>, blocking_reasons: list<string>}
     */
    public function traceGraphPath(string $from, string $to): array
    {
        $from = $this->normalizeNode($from);
        $to = $this->normalizeNode($to);

        $knownNodes = $this->graphNodes();
        $reasons = [];
        if (! in_array($from, $knownNodes, true)) {
            $reasons[] = 'graph_node_unknown: not a node in the documented production graph: ' . $from;
        }
        if (! in_array($to, $knownNodes, true)) {
            $reasons[] = 'graph_node_unknown: not a node in the documented production graph: ' . $to;
        }

        if ($reasons !== []) {
            return [
                'schema' => self::SCHEMA,
                'surface' => 'production_graph',
                'status' => self::STATUS_FAIL,
                'from' => $from,
                'to' => $to,
                'reachable' => false,
                'path' => [],
                'blocking_reasons' => $reasons,
            ];
        }

        $path = $this->bfsPath($from, $to);
        $reachable = $path !== [];

        return [
            'schema' => self::SCHEMA,
            'surface' => 'production_graph',
            'status' => $reachable ? self::STATUS_PASS : self::STATUS_FAIL,
            'from' => $from,
            'to' => $to,
            'reachable' => $reachable,
            'path' => $path,
            'blocking_reasons' => $reachable
                ? []
                : ['graph_unreachable: no documented edge path from "' . $from . '" to "' . $to . '".'],
        ];
    }

    /**
     * Storage ownership validation. Postgres must be the declared live state
     * source; Markdown may exist as a projection/export but must never be the
     * sole runtime source of truth.
     *
     * @param array{live_state_source?: string, markdown_is_sole_source?: bool} $declaration
     * @return array{schema: string, surface: string, status: string, live_state_source: string, postgres_is_live_source: bool, markdown_is_sole_source: bool, blocking_reasons: list<string>}
     */
    public function validateStorageOwnership(array $declaration): array
    {
        $liveSource = strtolower(trim((string) ($declaration['live_state_source'] ?? '')));
        $postgresIsLive = $liveSource === 'postgres' || $liveSource === 'pgsql' || $liveSource === 'postgresql';
        $markdownSole = ($declaration['markdown_is_sole_source'] ?? false) === true;

        $reasons = [];
        if (! $postgresIsLive) {
            $reasons[] = 'storage_live_source_not_postgres: Postgres must be the live structured state source.';
        }
        if ($markdownSole) {
            $reasons[] = 'storage_markdown_sole_source: Markdown is a projection/export and must never be the sole runtime source of truth.';
        }

        return [
            'schema' => self::SCHEMA,
            'surface' => 'storage_ownership',
            'status' => $reasons === [] ? self::STATUS_PASS : self::STATUS_FAIL,
            'live_state_source' => $liveSource,
            'postgres_is_live_source' => $postgresIsLive,
            'markdown_is_sole_source' => $markdownSole,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Evidence event validation: passes only when the event name is in the
     * documented closed vocabulary.
     *
     * @return array{schema: string, surface: string, status: string, value: string, known: bool, blocking_reasons: list<string>}
     */
    public function validateEvidenceEvent(string $value): array
    {
        $value = $this->normalizeNode($value);
        $known = in_array($value, self::EVIDENCE_EVENTS, true);

        return [
            'schema' => self::SCHEMA,
            'surface' => 'evidence_event',
            'status' => $known ? self::STATUS_PASS : self::STATUS_FAIL,
            'value' => $value,
            'known' => $known,
            'blocking_reasons' => $known
                ? []
                : ['evidence_event_unknown: not a documented evidence event: ' . $value],
        ];
    }

    /**
     * Whole-model audit: runs the entity set, storage ownership and the core
     * production chain (source -> output) over a candidate model declaration and
     * folds the per-surface verdicts into one pass|fail document.
     *
     * @param array{
     *     entities?: list<string>,
     *     storage?: array{live_state_source?: string, markdown_is_sole_source?: bool}
     * } $model
     * @return array{schema: string, status: string, surfaces: array<string, mixed>, blocking_reasons: list<string>}
     */
    public function audit(array $model): array
    {
        $surfaces = [
            'entities' => $this->validateEntities($model['entities'] ?? self::CORE_ENTITIES),
            'storage' => $this->validateStorageOwnership($model['storage'] ?? [
                'live_state_source' => 'postgres',
                'markdown_is_sole_source' => false,
            ]),
            'core_chain' => $this->traceGraphPath('source', 'output'),
        ];

        $reasons = [];
        $allPass = true;
        foreach ($surfaces as $surface) {
            if (($surface['status'] ?? self::STATUS_FAIL) !== self::STATUS_PASS) {
                $allPass = false;
            }
            foreach (($surface['blocking_reasons'] ?? []) as $reason) {
                $reasons[] = $reason;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'status' => $allPass ? self::STATUS_PASS : self::STATUS_FAIL,
            'surfaces' => $surfaces,
            'blocking_reasons' => $reasons,
        ];
    }

    /** Normalise a graph/event token to lower snake (spaces/dashes -> underscore). */
    private function normalizeNode(string $value): string
    {
        $value = strtolower(trim($value));

        return (string) preg_replace('/[\s\-]+/', '_', $value);
    }

    /**
     * All distinct nodes appearing in the documented edge map (sources + targets).
     *
     * @return list<string>
     */
    private function graphNodes(): array
    {
        $nodes = array_keys(self::GRAPH_EDGES);
        foreach (self::GRAPH_EDGES as $targets) {
            foreach ($targets as $target) {
                $nodes[] = $target;
            }
        }

        return array_values(array_unique($nodes));
    }

    /**
     * Breadth-first shortest path over the documented directed edges. Returns the
     * node sequence (inclusive of both ends) or [] when unreachable.
     *
     * @return list<string>
     */
    private function bfsPath(string $from, string $to): array
    {
        if ($from === $to) {
            return [$from];
        }

        /** @var array<string, string|null> $cameFrom */
        $cameFrom = [$from => null];
        $queue = [$from];

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach (self::GRAPH_EDGES[$current] ?? [] as $next) {
                if (array_key_exists($next, $cameFrom)) {
                    continue;
                }
                $cameFrom[$next] = $current;
                if ($next === $to) {
                    return $this->rebuildPath($cameFrom, $to);
                }
                $queue[] = $next;
            }
        }

        return [];
    }

    /**
     * @param array<string, string|null> $cameFrom
     * @return list<string>
     */
    private function rebuildPath(array $cameFrom, string $to): array
    {
        $path = [];
        $cursor = $to;
        while ($cursor !== null) {
            $path[] = $cursor;
            $cursor = $cameFrom[$cursor] ?? null;
        }

        return array_reverse($path);
    }
}
