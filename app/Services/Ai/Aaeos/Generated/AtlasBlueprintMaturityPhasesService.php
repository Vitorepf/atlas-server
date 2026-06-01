<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Engineering Blueprint Maturity Phases decider.
 *
 * Pure, deterministic gate that turns the maturity-phases doc into a contract.
 * The doc's canonical decision is explicit: "Engineering Blueprint maturity is
 * measured by executable project-to-memory flow, not by documentation alone."
 * This service enforces exactly that and never inflates a claim on bare
 * assertion. It answers three distinct, documented questions:
 *
 *  1. Seven Items (doc "Seven Items" table). Seven named blueprint items, each
 *     carrying an ordinal "Current state". The doc uses three states; this
 *     service treats them as an ordered scale plus the implicit top:
 *       partial(1) < base_implemented(2) < near_complete(3) < complete(4).
 *     The system's item-floor is the WEAKEST item, because the maturity of the
 *     whole is gated by its least mature item — one partial item keeps the
 *     blueprint short of "complete". Each item also lists remaining work; an
 *     item is only `complete` when its state is complete AND it carries no
 *     remaining work.
 *
 *  2. Phase ladder (doc "Phases" table). Nine ordered phases 0..8, each mapped
 *     to a goal (Phase 0 Canonical documentation ... Phase 8 Atlas-Bench and
 *     Memory Delta calibration). The doc frames these as a sequence, so the
 *     current frontier is the highest *contiguous* delivered phase: the first
 *     phase that is not delivered caps the frontier and is named as the next
 *     phase to work. A later phase delivered while an earlier one is missing
 *     does NOT skip the gap.
 *
 *  3. Final DoD (doc "Final DoD"). Seven named Definition-of-Done criteria. The
 *     system is "DONE" only when every DoD criterion holds, every one of the
 *     seven items is `complete`, and every phase 0..8 is delivered. Anything
 *     less is reported, per the doc's decision, as a strong operational base —
 *     not the final mature product.
 *
 * Evidence gate (doc frontmatter `forbidden_changes`): "Declarar runtime,
 * maturidade ou prontidao sem evidencia verificavel e gates verdes" is
 * forbidden. So a phase / criterion asserted delivered or met but carrying NO
 * verifiable evidence is treated as NOT delivered / NOT met. Maturity, phase
 * progress and DoD completion can never be claimed on a bare boolean.
 *
 * The service is pure: it consumes already-normalized item/phase/criterion
 * results and emits a verdict. It never runs a harness, reads a doc, calls a
 * provider, or touches a database.
 *
 * @see docs/engineering-knowledge-base/engineering-blueprint/maturity-phases.md
 */
final class AtlasBlueprintMaturityPhasesService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const SCHEMA_VERSION = 'atlas.aaeos.blueprint_maturity_phases.v1';

    /** The highest phase index on the doc's "Phases" ladder. */
    public const MAX_PHASE = 8;

    /**
     * Doc "Seven Items" — the seven blueprint items in doc-table order, each
     * seeded with its documented Current state. This is the baseline the doc
     * asserts today; callers may override per-item state with proven evidence.
     *
     * @var array<string,array{label:string,state:string}>
     */
    public const SEVEN_ITEMS = [
        'product_to_qa_pipeline' => [
            'label' => 'Product -> Blueprint -> Phase -> Task -> QA',
            'state' => self::STATE_BASE_IMPLEMENTED,
        ],
        'strong_task_contract' => [
            'label' => 'Strong task contract',
            'state' => self::STATE_NEAR_COMPLETE,
        ],
        'inventory_scenarios_wireframes' => [
            'label' => 'Inventory, scenarios and wireframes',
            'state' => self::STATE_BASE_IMPLEMENTED,
        ],
        'contingency_policy' => [
            'label' => 'Contingency policy',
            'state' => self::STATE_PARTIAL,
        ],
        'manual_qa_evidence' => [
            'label' => 'Manual QA evidence',
            'state' => self::STATE_BASE_IMPLEMENTED,
        ],
        'deep_review' => [
            'label' => 'Deep review',
            'state' => self::STATE_BASE_IMPLEMENTED,
        ],
        'postgres_review' => [
            'label' => 'Postgres review',
            'state' => self::STATE_BASE_IMPLEMENTED,
        ],
    ];

    /** Ordinal "Current state" scale (doc states + implicit top). */
    public const STATE_PARTIAL = 'partial';
    public const STATE_BASE_IMPLEMENTED = 'base_implemented';
    public const STATE_NEAR_COMPLETE = 'near_complete';
    public const STATE_COMPLETE = 'complete';

    /**
     * Ordinal rank of each state. Higher = more mature.
     *
     * @var array<string,int>
     */
    public const STATE_RANK = [
        self::STATE_PARTIAL => 1,
        self::STATE_BASE_IMPLEMENTED => 2,
        self::STATE_NEAR_COMPLETE => 3,
        self::STATE_COMPLETE => 4,
    ];

    /**
     * Doc "Phases" — the nine ordered phases 0..8 and their goals.
     *
     * @var array<int,string>
     */
    public const PHASES = [
        0 => 'Canonical documentation.',
        1 => 'Project Blueprint pipeline.',
        2 => 'Phase plan and task generation.',
        3 => 'Inventory, scenarios and wireframes.',
        4 => 'Professional manual QA.',
        5 => 'Professional deep review.',
        6 => 'Postgres engineering review.',
        7 => 'Final app product surface.',
        8 => 'Atlas-Bench and Memory Delta calibration.',
    ];

    /**
     * Doc "Final DoD" — the seven Definition-of-Done criteria in doc order.
     *
     * @var array<string,string>
     */
    public const DOD_CRITERIA = [
        'blueprint_lifecycle' => 'Project blueprint can be prepared, created, validated and frozen.',
        'strong_task_contracts' => 'Tasks are generated with strong contracts.',
        'auditable_evidence' => 'Harness runs produce auditable evidence.',
        'gates_enforceable' => 'QA, review and Postgres gates are visible and enforceable.',
        'surface_parity' => 'App, API and CLI expose the same operational truth.',
        'knowledge_index_current' => 'Knowledge sync and Code Intelligence index stay current.',
        'governed_memory_delta' => 'Memory Delta is proposed through governed Memory Core policy.',
    ];

    public const VERDICT_DONE = 'done';
    public const VERDICT_OPERATIONAL_BASE = 'operational_base';

    /**
     * Evaluate the "Seven Items" table.
     *
     * The whole's item-floor is the weakest item: maturity is gated by the least
     * mature item. Each item is `complete` only when its state is complete AND it
     * carries no remaining work; the doc decision ("measured by executable flow,
     * not documentation alone") forbids declaring an item done while real work
     * remains.
     *
     * @param array<string,mixed> $report
     *   items: array<string,mixed> keyed by SEVEN_ITEMS keys. Each value may be
     *          a string (the state, e.g. "complete") or an array {
     *            state: string,
     *            remaining_work: list<string>|string|null
     *          }. A missing item keeps its documented baseline state. An unknown
     *          / invalid state degrades to the documented baseline (never inflates).
     *
     * @return array{
     *   schema: string,
     *   total_items: int,
     *   complete_count: int,
     *   floor_state: string,
     *   floor_rank: int,
     *   floor_items: list<string>,
     *   all_complete: bool,
     *   items: array<string,array{label:string,state:string,rank:int,complete:bool,remaining_work:list<string>}>,
     *   incomplete_items: list<string>,
     *   reasons: list<string>,
     *   auditable: true
     * }
     */
    public function evaluateSevenItems(array $report): array
    {
        $input = is_array($report['items'] ?? null) ? $report['items'] : [];

        $items = [];
        $incomplete = [];
        $completeCount = 0;
        $floorRank = self::STATE_RANK[self::STATE_COMPLETE];
        $floorState = self::STATE_COMPLETE;

        foreach (self::SEVEN_ITEMS as $key => $meta) {
            $resolved = $this->resolveItem($meta, $input[$key] ?? null);

            $items[$key] = [
                'label' => $meta['label'],
                'state' => $resolved['state'],
                'rank' => $resolved['rank'],
                'complete' => $resolved['complete'],
                'remaining_work' => $resolved['remaining_work'],
            ];

            if ($resolved['complete']) {
                $completeCount++;
            } else {
                $incomplete[] = $key;
            }

            if ($resolved['rank'] < $floorRank) {
                $floorRank = $resolved['rank'];
                $floorState = $resolved['state'];
            }
        }

        $allComplete = $incomplete === [];

        $reasons = [];
        if ($allComplete) {
            $reasons[] = 'items:all_complete';
        } else {
            $reasons[] = 'items:floor_' . $floorState;
            foreach ($incomplete as $key) {
                $reasons[] = 'item_incomplete:' . $key;
            }
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'total_items' => count(self::SEVEN_ITEMS),
            'complete_count' => $completeCount,
            'floor_state' => $floorState,
            'floor_rank' => $floorRank,
            'floor_items' => $this->itemsAtRank($items, $floorRank),
            'all_complete' => $allComplete,
            'items' => $items,
            'incomplete_items' => array_values($incomplete),
            'reasons' => $reasons,
            'auditable' => true,
        ];
    }

    /**
     * Evaluate the phase ladder.
     *
     * The phases are sequential, so the frontier is the highest CONTIGUOUS
     * delivered phase from 0 upward. The first not-delivered phase caps the
     * frontier and is named as the next phase to work — a later delivered phase
     * never skips an earlier gap.
     *
     * @param array<string,mixed> $report
     *   phases: array<int,mixed> keyed by phase index 0..8. Each value may be a
     *           bool (legacy/simple) or an array {
     *             delivered: bool,
     *             evidence: list<string>|string|null
     *           }. A missing phase => not delivered. Evidence gate: a bare
     *           boolean true is NOT proof — only an evidenced delivered=true
     *           counts.
     *
     * @return array{
     *   schema: string,
     *   max_phase: int,
     *   total_phases: int,
     *   delivered_count: int,
     *   frontier_phase: int,
     *   frontier_goal: string,
     *   next_phase: ?int,
     *   next_goal: ?string,
     *   all_delivered: bool,
     *   phases: array<int,array{goal:string,delivered:bool}>,
     *   pending_phases: list<int>,
     *   reasons: list<string>,
     *   auditable: true
     * }
     */
    public function evaluatePhases(array $report): array
    {
        $input = is_array($report['phases'] ?? null) ? $report['phases'] : [];

        $phases = [];
        $pending = [];
        $deliveredCount = 0;
        $frontier = -1;
        $frontierBroken = false;

        foreach (array_keys(self::PHASES) as $index) {
            $delivered = $this->resolvePhase($input[$index] ?? null);
            $phases[$index] = [
                'goal' => self::PHASES[$index],
                'delivered' => $delivered,
            ];

            if ($delivered) {
                $deliveredCount++;
                if (! $frontierBroken) {
                    $frontier = $index;
                }
            } else {
                $pending[] = $index;
                $frontierBroken = true;
            }
        }

        $allDelivered = $pending === [];
        $nextPhase = $pending === [] ? null : $pending[0];

        $reasons = [];
        if ($allDelivered) {
            $reasons[] = 'phases:all_delivered';
        } else {
            $reasons[] = 'phases:frontier_' . $frontier;
            $reasons[] = 'phases:next_' . $nextPhase;
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'max_phase' => self::MAX_PHASE,
            'total_phases' => count(self::PHASES),
            'delivered_count' => $deliveredCount,
            'frontier_phase' => $frontier,
            'frontier_goal' => $frontier >= 0 ? self::PHASES[$frontier] : '',
            'next_phase' => $nextPhase,
            'next_goal' => $nextPhase === null ? null : self::PHASES[$nextPhase],
            'all_delivered' => $allDelivered,
            'phases' => $phases,
            'pending_phases' => array_values($pending),
            'reasons' => $reasons,
            'auditable' => true,
        ];
    }

    /**
     * Evaluate the "Final DoD" criteria.
     *
     * @param array<string,mixed> $report
     *   dod: array<string,mixed> keyed by DOD_CRITERIA keys. Each value may be a
     *        bool (legacy/simple) or an array {
     *          met: bool,
     *          evidence: list<string>|string|null
     *        }. A missing criterion => not met. Evidence gate: a bare boolean
     *        true is NOT proof.
     *
     * @return array{
     *   schema: string,
     *   total_criteria: int,
     *   met_count: int,
     *   all_met: bool,
     *   criteria: array<string,bool>,
     *   unmet_criteria: list<string>,
     *   reasons: list<string>,
     *   auditable: true
     * }
     */
    public function evaluateFinalDod(array $report): array
    {
        $input = is_array($report['dod'] ?? null) ? $report['dod'] : [];

        $criteria = [];
        $unmet = [];

        foreach (array_keys(self::DOD_CRITERIA) as $key) {
            $met = $this->resolveCriterion($input[$key] ?? null);
            $criteria[$key] = $met;
            if (! $met) {
                $unmet[] = $key;
            }
        }

        $allMet = $unmet === [];
        $metCount = count(self::DOD_CRITERIA) - count($unmet);

        $reasons = [];
        if ($allMet) {
            $reasons[] = 'dod:all_met';
        } else {
            foreach ($unmet as $key) {
                $reasons[] = 'dod_unmet:' . $key;
            }
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'total_criteria' => count(self::DOD_CRITERIA),
            'met_count' => $metCount,
            'all_met' => $allMet,
            'criteria' => $criteria,
            'unmet_criteria' => array_values($unmet),
            'reasons' => $reasons,
            'auditable' => true,
        ];
    }

    /**
     * Full assessment. The Engineering Blueprint System is "done" ONLY when:
     *  - every Final DoD criterion is met, AND
     *  - every one of the seven items is complete, AND
     *  - every phase 0..8 is delivered.
     * Otherwise it is a strong operational base, not the final mature product
     * (doc decision). This is the doc's executable-flow-over-documentation rule.
     *
     * @param array<string,mixed> $report { items: ..., phases: ..., dod: ... }
     * @return array<string,mixed>
     */
    public function assess(array $report): array
    {
        $items = $this->evaluateSevenItems($report);
        $phases = $this->evaluatePhases($report);
        $dod = $this->evaluateFinalDod($report);

        $isDone = $dod['all_met'] && $items['all_complete'] && $phases['all_delivered'];

        return [
            'schema' => self::SCHEMA_VERSION,
            'seven_items' => $items,
            'phases' => $phases,
            'final_dod' => $dod,
            'is_done' => $isDone,
            'verdict' => $isDone ? self::VERDICT_DONE : self::VERDICT_OPERATIONAL_BASE,
            'auditable' => true,
        ];
    }

    /**
     * Resolve one of the seven items to { state, rank, complete, remaining_work }.
     *
     * A string value is taken as the state. An array may carry state +
     * remaining_work. An unknown/invalid state degrades to the documented
     * baseline so the floor is never inflated by garbage input. An item is
     * `complete` only when state == complete AND no remaining work is listed.
     *
     * @param array{label:string,state:string} $meta
     * @param mixed $entry
     * @return array{state:string,rank:int,complete:bool,remaining_work:list<string>}
     */
    private function resolveItem(array $meta, mixed $entry): array
    {
        $state = $meta['state'];
        $remainingWork = [];

        if (is_string($entry)) {
            $state = $this->normalizeState($entry, $meta['state']);
        } elseif (is_array($entry)) {
            if (isset($entry['state']) && is_string($entry['state'])) {
                $state = $this->normalizeState($entry['state'], $meta['state']);
            }
            $remainingWork = $this->normalizeRefs($entry['remaining_work'] ?? null);
        }

        $rank = self::STATE_RANK[$state];
        $complete = $state === self::STATE_COMPLETE && $remainingWork === [];

        return [
            'state' => $state,
            'rank' => $rank,
            'complete' => $complete,
            'remaining_work' => $remainingWork,
        ];
    }

    /**
     * Normalize a claimed state to a known ordinal state. Unknown values fall
     * back to the documented baseline (never to a higher rank).
     */
    private function normalizeState(string $claimed, string $fallback): string
    {
        $key = strtolower(trim($claimed));

        return array_key_exists($key, self::STATE_RANK) ? $key : $fallback;
    }

    /**
     * Resolve one phase to a delivered boolean. Evidence gate: a bare boolean is
     * never proof; only an array with delivered=true AND verifiable evidence
     * counts as delivered.
     *
     * @param mixed $entry
     */
    private function resolvePhase(mixed $entry): bool
    {
        if (! is_array($entry)) {
            return false;
        }

        $delivered = (bool) ($entry['delivered'] ?? false);

        return $delivered && $this->hasEvidence($entry['evidence'] ?? null);
    }

    /**
     * Resolve one DoD criterion to a met boolean. Same evidence gate as phases.
     *
     * @param mixed $entry
     */
    private function resolveCriterion(mixed $entry): bool
    {
        if (! is_array($entry)) {
            return false;
        }

        $met = (bool) ($entry['met'] ?? false);

        return $met && $this->hasEvidence($entry['evidence'] ?? null);
    }

    /**
     * A phase/criterion carries verifiable evidence when it provides at least one
     * non-empty evidence ref (a path, command, receipt id or report).
     *
     * @param mixed $evidence
     */
    private function hasEvidence(mixed $evidence): bool
    {
        return $this->normalizeRefs($evidence) !== [];
    }

    /**
     * Normalize an evidence/remaining-work value to a clean list of non-empty
     * string refs.
     *
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeRefs(mixed $value): array
    {
        $out = [];

        if (is_string($value)) {
            if (trim($value) !== '') {
                $out[] = trim($value);
            }

            return $out;
        }

        if (is_array($value)) {
            foreach ($value as $ref) {
                if (is_string($ref) && trim($ref) !== '') {
                    $out[] = trim($ref);
                }
            }
        }

        return $out;
    }

    /**
     * Keys of the items whose rank equals the given rank (used to name the
     * floor items).
     *
     * @param array<string,array{rank:int}> $items
     * @return list<string>
     */
    private function itemsAtRank(array $items, int $rank): array
    {
        $out = [];
        foreach ($items as $key => $item) {
            if ($item['rank'] === $rank) {
                $out[] = $key;
            }
        }

        return $out;
    }
}
