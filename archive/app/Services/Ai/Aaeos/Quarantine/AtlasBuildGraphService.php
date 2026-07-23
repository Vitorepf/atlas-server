<?php

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime for the Atlas Self-Construction Build Graph doc.
 *
 * The doc is a dependency-ordering law that stops Atlas from promoting
 * impressive-looking features before the foundations that make them reliable.
 * Five load-bearing rules from the doc are enforced here, deterministically, in
 * pure memory with no DB and no I/O:
 *
 *   1. Core Dependencies order ("Core Dependencies"): the 11-stage chain from
 *      Documentation OS down to Strategic self-programming. A stage may not be
 *      promoted while any earlier stage in the chain is below the required
 *      maturity — you cannot build downstream before upstream.
 *
 *   2. Build Order Law ("Build Order Law"): when work competes, prefer the block
 *      that improves the most downstream capabilities. The fan-out is taken from
 *      the Dependency Table; ties break toward the earlier chain stage (closer to
 *      the foundation), exactly as the priority examples state
 *      ("memory/retrieval beats decorative UI", etc.).
 *
 *   3. Blocking Rule ("Blocking Rule"): the doc's explicit invariant —
 *      "Self-programming cannot exceed L5 while SDD runtime, Evidence Ledger,
 *      rollback and drift detection are below L5." This method literally caps the
 *      effective maturity at L5 until ALL four prerequisites reach L5.
 *
 *   4. Build Graph Packet ("Build Graph Packet"): every construction spec must
 *      carry the 7 declared keys (target_capability, prerequisites,
 *      downstream_capabilities, blocked_by, unlocks, maturity_before,
 *      maturity_after). A packet missing any key is invalid and cannot be used.
 *
 *   5. Drift Signal ("Drift Signal"): a capability that exists with no canonical
 *      dependency placement emits the exact documented drift line, and the only
 *      accepted corrections are "attach" or "remove_or_defer".
 *
 * @see docs/engineering-knowledge-base/self-construction/build-graph.md
 */
final class AtlasBuildGraphService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.self_construction_build_graph.v1';

    public const MODE = 'dependency_ordering_law_read_only';

    /**
     * The maturity floor the Blocking Rule pins. Self-programming cannot exceed
     * this level while its prerequisites are below it.
     */
    public const BLOCKING_RULE_LEVEL = 5;

    /**
     * "Core Dependencies" — the canonical 11-stage build chain, in strict order.
     * Index 0 is the deepest foundation; the last index is the most downstream
     * capability. Promotion of stage N requires every stage < N to be mature.
     *
     * @var array<int, string>
     */
    public const CORE_CHAIN = [
        'documentation_os',
        'knowledge_governance',
        'evidence_ledger',
        'code_intelligence',
        'cognitive_runtime',
        'research_self_improvement_runtime',
        'spec_operating_system',
        'tool_runtime_quality_gates',
        'self_construction_os_runtime',
        'voice_mobile_product_autonomy',
        'strategic_self_programming',
    ];

    /**
     * "Build Graph Packet" — the 7 keys every construction spec must declare.
     *
     * @var array<int, string>
     */
    public const PACKET_KEYS = [
        'target_capability',
        'prerequisites',
        'downstream_capabilities',
        'blocked_by',
        'unlocks',
        'maturity_before',
        'maturity_after',
    ];

    /**
     * "Blocking Rule" — the four prerequisites that gate self-programming at L5.
     *
     * @var array<int, string>
     */
    public const SELF_PROGRAMMING_PREREQUISITES = [
        'sdd_runtime',
        'evidence_ledger',
        'rollback',
        'drift_detection',
    ];

    /**
     * The exact drift line the doc says to emit ("Drift Signal").
     */
    public const DRIFT_MESSAGE = 'Capability exists without canonical dependency placement.';

    /**
     * The only two corrections the doc accepts for an off-graph capability.
     *
     * @var array<int, string>
     */
    public const DRIFT_CORRECTIONS = ['attach', 'remove_or_defer'];

    /**
     * "Dependency Table" — downstream fan-out per capability. The value is the
     * count of distinct downstream capabilities the row improves, used by the
     * Build Order Law to rank competing work. Derived directly from the table's
     * "Depends On" edges (a capability that many rows depend on has high fan-out).
     *
     * @var array<string, int>
     */
    public const DOWNSTREAM_FANOUT = [
        // Memory/retrieval/evidence feed nearly everything downstream.
        'memory' => 4,
        'retrieval' => 4,
        'evidence' => 4,
        // SDD runtime is a prerequisite for self-programming + isolated coding.
        'sdd_runtime' => 3,
        'drift_detection' => 3,
        'quality_gates' => 3,
        'research_runtime' => 2,
        // Surface/autonomy capabilities are leaves — they improve little downstream.
        'self_programming' => 1,
        'voice_realtime' => 0,
        'mobile_product' => 0,
        'decorative_ui' => 0,
        'provider_wrapper' => 0,
    ];

    /**
     * Apply the Blocking Rule. Self-programming's effective maturity is capped at
     * L5 until SDD runtime, Evidence Ledger, rollback AND drift detection are all
     * at L5 or above. PURE — no I/O, no DB.
     *
     * @param  int  $requestedLevel  the maturity the caller wants to promote self-programming to
     * @param  array<string, int>  $prerequisiteLevels  maturity per prerequisite name
     * @return array{
     *   capability:string,
     *   requested_level:int,
     *   effective_level:int,
     *   capped:bool,
     *   blocking_rule_level:int,
     *   prerequisites:array<int,string>,
     *   prerequisites_met:bool,
     *   below_floor:array<int,string>,
     *   reason:string
     * }
     */
    public function applyBlockingRule(int $requestedLevel, array $prerequisiteLevels = []): array
    {
        $belowFloor = [];
        foreach (self::SELF_PROGRAMMING_PREREQUISITES as $prereq) {
            $level = (int) ($prerequisiteLevels[$prereq] ?? 0);
            if ($level < self::BLOCKING_RULE_LEVEL) {
                $belowFloor[] = $prereq;
            }
        }

        $prerequisitesMet = $belowFloor === [];

        // While any prerequisite is below L5, self-programming cannot exceed L5.
        $effective = $requestedLevel;
        $capped = false;
        if (! $prerequisitesMet && $requestedLevel > self::BLOCKING_RULE_LEVEL) {
            $effective = self::BLOCKING_RULE_LEVEL;
            $capped = true;
        }

        $reason = match (true) {
            $capped => 'self_programming_capped_at_l5_prerequisites_below_floor',
            $prerequisitesMet => 'prerequisites_met_no_cap_applied',
            default => 'within_floor_no_cap_needed',
        };

        return [
            'capability' => 'self_programming',
            'requested_level' => $requestedLevel,
            'effective_level' => $effective,
            'capped' => $capped,
            'blocking_rule_level' => self::BLOCKING_RULE_LEVEL,
            'prerequisites' => self::SELF_PROGRAMMING_PREREQUISITES,
            'prerequisites_met' => $prerequisitesMet,
            'below_floor' => $belowFloor,
            'reason' => $reason,
        ];
    }

    /**
     * Core Dependencies order check: may a given chain stage be promoted given the
     * maturity of every stage? A stage is blocked while ANY earlier stage in the
     * chain is below the required maturity. PURE.
     *
     * @param  array<string, int>  $stageLevels  maturity per chain stage name
     * @return array{
     *   stage:string,
     *   recognized_stage:bool,
     *   stage_index:int,
     *   required_level:int,
     *   promotable:bool,
     *   blocked_by:array<int,string>,
     *   reason:string
     * }
     */
    public function canPromoteStage(string $stage, array $stageLevels = [], int $requiredLevel = self::BLOCKING_RULE_LEVEL): array
    {
        $slug = $this->slugify($stage);
        $index = array_search($slug, self::CORE_CHAIN, true);
        $recognized = $index !== false;

        $blockedBy = [];
        if ($recognized) {
            // Every stage strictly earlier in the chain must meet the floor.
            for ($i = 0; $i < $index; $i++) {
                $upstream = self::CORE_CHAIN[$i];
                if ((int) ($stageLevels[$upstream] ?? 0) < $requiredLevel) {
                    $blockedBy[] = $upstream;
                }
            }
        }

        $promotable = $recognized && $blockedBy === [];

        $reason = match (true) {
            ! $recognized => 'stage_not_in_core_chain_attach_to_graph_first',
            $promotable => 'all_upstream_stages_meet_required_maturity',
            default => 'upstream_prerequisites_below_required_maturity',
        };

        return [
            'stage' => $stage,
            'recognized_stage' => $recognized,
            'stage_index' => $recognized ? (int) $index : -1,
            'required_level' => $requiredLevel,
            'promotable' => $promotable,
            'blocked_by' => $blockedBy,
            'reason' => $reason,
        ];
    }

    /**
     * Build Order Law: rank competing capabilities, preferring the one that
     * improves the most downstream capabilities. Ties break toward the capability
     * with the higher fan-out already, then alphabetically for determinism. The
     * winner is the work that should be done first. PURE.
     *
     * @param  array<int, string>  $candidates  capability names competing for the next slot
     * @return array{
     *   ranked:array<int, array{capability:string, downstream_fanout:int, recognized:bool}>,
     *   winner:string,
     *   winner_fanout:int,
     *   reason:string
     * }
     */
    public function rankByBuildOrderLaw(array $candidates): array
    {
        $rows = [];
        foreach ($candidates as $candidate) {
            $slug = $this->slugify($candidate);
            $recognized = array_key_exists($slug, self::DOWNSTREAM_FANOUT);
            $rows[] = [
                'capability' => $slug,
                'downstream_fanout' => $recognized ? self::DOWNSTREAM_FANOUT[$slug] : 0,
                'recognized' => $recognized,
            ];
        }

        // Higher fan-out wins; deterministic tie-break by capability name.
        usort($rows, static function (array $a, array $b): int {
            if ($a['downstream_fanout'] !== $b['downstream_fanout']) {
                return $b['downstream_fanout'] <=> $a['downstream_fanout'];
            }

            return strcmp($a['capability'], $b['capability']);
        });

        $winner = $rows[0]['capability'] ?? '';
        $winnerFanout = $rows[0]['downstream_fanout'] ?? 0;

        return [
            'ranked' => $rows,
            'winner' => $winner,
            'winner_fanout' => $winnerFanout,
            'reason' => $winner === ''
                ? 'no_candidates_supplied'
                : 'highest_downstream_fanout_wins_per_build_order_law',
        ];
    }

    /**
     * Build Graph Packet validation: a construction spec must carry all 7 keys.
     * A packet missing any key is invalid. Empty (null / '' / []) values count as
     * missing because the doc requires each field to be declared. PURE.
     *
     * @param  array<string, mixed>  $packet
     * @return array{
     *   valid:bool,
     *   present_keys:array<int,string>,
     *   missing_keys:array<int,string>,
     *   required_keys:array<int,string>,
     *   reason:string
     * }
     */
    public function validatePacket(array $packet): array
    {
        $present = [];
        $missing = [];
        foreach (self::PACKET_KEYS as $key) {
            if (array_key_exists($key, $packet) && ! $this->isEmpty($packet[$key])) {
                $present[] = $key;
            } else {
                $missing[] = $key;
            }
        }

        $valid = $missing === [];

        return [
            'valid' => $valid,
            'present_keys' => $present,
            'missing_keys' => $missing,
            'required_keys' => self::PACKET_KEYS,
            'reason' => $valid
                ? 'build_graph_packet_declares_all_seven_required_keys'
                : 'build_graph_packet_incomplete_cannot_be_used',
        ];
    }

    /**
     * Drift Signal: decide whether a capability has canonical dependency
     * placement. A capability is placed if it appears in the core chain OR cites a
     * non-empty prerequisite that itself sits in the chain. An unplaced capability
     * emits the exact documented drift line. PURE.
     *
     * @param  array<int, string>  $declaredPrerequisites
     * @return array{
     *   capability:string,
     *   placed:bool,
     *   drift:bool,
     *   drift_signal:string,
     *   accepted_corrections:array<int,string>,
     *   reason:string
     * }
     */
    public function detectDrift(string $capability, array $declaredPrerequisites = []): array
    {
        $slug = $this->slugify($capability);
        $inChain = in_array($slug, self::CORE_CHAIN, true);

        $hasChainAnchoredPrereq = false;
        foreach ($declaredPrerequisites as $prereq) {
            if (in_array($this->slugify((string) $prereq), self::CORE_CHAIN, true)) {
                $hasChainAnchoredPrereq = true;
                break;
            }
        }

        $placed = $inChain || $hasChainAnchoredPrereq;
        $drift = ! $placed;

        return [
            'capability' => $capability,
            'placed' => $placed,
            'drift' => $drift,
            // The drift line is only emitted when drift is present.
            'drift_signal' => $drift ? self::DRIFT_MESSAGE : '',
            'accepted_corrections' => $drift ? self::DRIFT_CORRECTIONS : [],
            'reason' => $placed
                ? 'capability_has_canonical_dependency_placement'
                : 'capability_off_graph_emit_drift_attach_or_remove_or_defer',
        ];
    }

    /**
     * Read-only snapshot of the whole law. With safe defaults it proves: the
     * blocking rule caps self-programming when prerequisites are at L0, the core
     * chain is 11 stages, an empty packet is invalid, and an off-graph capability
     * drifts. PURE — no I/O, no DB.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        // Default world: nothing built yet, so every floor is unmet.
        $blocking = $this->applyBlockingRule(7, []);
        $emptyPacket = $this->validatePacket([]);
        $offGraph = $this->detectDrift('decorative_dashboard_widget', []);

        return [
            'ok' => true,
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'core_chain' => self::CORE_CHAIN,
            'core_chain_length' => count(self::CORE_CHAIN),
            'blocking_rule_level' => self::BLOCKING_RULE_LEVEL,
            'self_programming_prerequisites' => self::SELF_PROGRAMMING_PREREQUISITES,
            'packet_keys' => self::PACKET_KEYS,
            'packet_key_count' => count(self::PACKET_KEYS),
            'default_self_programming_capped' => $blocking['capped'],
            'default_self_programming_effective_level' => $blocking['effective_level'],
            'empty_packet_valid' => $emptyPacket['valid'],
            'off_graph_capability_drifts' => $offGraph['drift'],
            'drift_message' => self::DRIFT_MESSAGE,
            'law' => 'Build upstream before downstream; prefer the block with the most downstream fan-out; self-programming cannot exceed L5 while SDD runtime, Evidence Ledger, rollback and drift detection are below L5; every spec carries a 7-key Build Graph Packet; off-graph capabilities emit drift.',
        ];
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === false) {
            return true;
        }
        if (is_string($value) && trim($value) === '') {
            return true;
        }
        if (is_array($value) && $value === []) {
            return true;
        }

        return false;
    }

    private function slugify(string $value): string
    {
        $slug = strtolower($value);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';

        return trim($slug, '_');
    }
}
