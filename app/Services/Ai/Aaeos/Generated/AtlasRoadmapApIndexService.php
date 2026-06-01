<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use InvalidArgumentException;

/**
 * Atlas AI Kernel Roadmap AP Index — runtime.
 *
 * Turns the kernel roadmap INDEX doc into deterministic, pure decision logic.
 * The doc is explicitly "an implementation map, not a historical transcript",
 * and its own decisions are: kernel work advances as APs (not new monolithic
 * sections), and "Each AP must declare contracts, services, migrations, tests,
 * events and docs updated." So this service does NOT invent a parallel roadmap
 * engine — it pins the three concrete contracts the doc states, over plain
 * typed arrays, with no side effects, no database and no tokens spent:
 *
 *   1. PHASE MAP. The doc enumerates a closed, ordered set of phases 0..12,
 *      each with a single documented purpose (phase 0 "Promote docs and
 *      establish canonical governance" ... phase 12 "Self-Evolution Curator").
 *      {@see phaseMap()} exposes the ordered map and {@see resolvePhase()}
 *      routes a phase number to its purpose or flags it as out of range.
 *
 *   2. AP FAMILY ROUTING. The doc lists active AP families with explicit
 *      AP-ID ranges (Retrieval and Open Brain = AP-100..AP-105; Learning
 *      proposals and inbox = AP-106..AP-125; Architecture operations =
 *      AP-126..AP-133; Decision receipt replay = AP-134..AP-140; Ledger
 *      projections = AP-141..AP-145; Provider performance = AP-146..AP-147
 *      plus the AP-99 family; Documentation governance = AP-173..AP-177;
 *      Agent workflow governance = AP-200 family; plus singletons AP-684,
 *      AP-686, AP-687, AP-161). {@see routeAp()} deterministically maps an
 *      AP number to the family that owns it, or reports it as unmapped so it
 *      is placed via the canonical index rather than guessed.
 *
 *   3. AP ACCEPTANCE GATE. The doc's "AP Acceptance" section is a hard
 *      checklist: "An AP is not done until" all seven conditions hold
 *      (contract documented; migration/model/service when needed; events and
 *      read models declared; CLI/API/MCP surfaces aligned when applicable;
 *      tests include happy path AND guard path; docs-health + architecture
 *      validation run; knowledge sync/index refreshed). {@see evaluateAcceptance()}
 *      caps "done" on the full conjunction and lists every unmet condition.
 *
 * The frontmatter forbidden_changes invariant ("Declarar runtime, maturidade
 * ou prontidao sem evidencia verificavel e gates verdes") is enforced as part
 * of the acceptance gate: an AP marked done without the docs-health /
 * architecture-validation gate green and without refreshed knowledge is
 * demoted to "not_done" with an explicit evidence reason.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/kernel/roadmap-ap-index.md
 */
final class AtlasRoadmapApIndexService
{
    /**
     * The closed, ordered Phase Map (phases 0..12) exactly as the doc states.
     *
     * Key is the integer phase; value is the documented purpose verbatim.
     *
     * @var array<int,string>
     */
    public const PHASE_MAP = [
        0 => 'Promote docs and establish canonical governance',
        1 => 'Operation Envelope and Kernel Pipeline scaffold',
        2 => 'Deterministic Decision Receipt v2 and replay',
        3 => 'Evidence Ledger append-only and projections',
        4 => 'Capability Registry and surface parity',
        5 => 'Domain Manifest and SDK',
        6 => 'Surface Adapter Contract',
        7 => 'Provider Driver Contract',
        8 => 'Failure Domains and handlers',
        9 => 'SLO telemetry and cost',
        10 => 'Domain expansion',
        11 => 'Multi-tenancy hardening',
        12 => 'Self-Evolution Curator',
    ];

    /**
     * Active AP families with their inclusive AP-ID coverage, as documented.
     *
     * Each entry: key => [label, ranges, singles]. "ranges" is a list of
     * [low, high] inclusive AP-number intervals; "singles" is a list of
     * individual AP numbers the doc calls out by name. Order matters only for
     * stable listing; routing checks every family and the first owning match
     * wins (the documented ranges do not overlap).
     *
     * @var array<string,array{label:string,ranges:list<array{0:int,1:int}>,singles:list<int>}>
     */
    public const AP_FAMILIES = [
        'retrieval_and_open_brain' => [
            'label' => 'Retrieval and Open Brain',
            'ranges' => [[100, 105]],
            'singles' => [],
        ],
        'learning_proposals_and_inbox' => [
            'label' => 'Learning proposals and inbox',
            'ranges' => [[106, 125]],
            'singles' => [],
        ],
        'architecture_operations' => [
            'label' => 'Architecture operations',
            'ranges' => [[126, 133]],
            'singles' => [],
        ],
        'decision_receipt_replay' => [
            'label' => 'Decision receipt replay',
            'ranges' => [[134, 140]],
            'singles' => [],
        ],
        'ledger_projections' => [
            'label' => 'Ledger projections',
            'ranges' => [[141, 145]],
            'singles' => [],
        ],
        'provider_performance' => [
            'label' => 'Provider performance',
            // AP-146 to AP-147 and the AP-99 family.
            'ranges' => [[146, 147]],
            'singles' => [99],
        ],
        'documentation_governance' => [
            'label' => 'Documentation governance',
            'ranges' => [[173, 177]],
            'singles' => [],
        ],
        'agent_workflow_governance' => [
            'label' => 'Agent workflow governance',
            // AP-200 family.
            'ranges' => [[200, 200]],
            'singles' => [],
        ],
        'external_graph_candidates' => [
            'label' => 'External graph candidates',
            'ranges' => [],
            'singles' => [684],
        ],
        'voice_runtime_boundaries' => [
            'label' => 'Voice runtime boundaries',
            'ranges' => [],
            'singles' => [686, 687],
        ],
        'recurring_agent_behavior_schedule' => [
            'label' => 'Recurring agent behavior schedule',
            'ranges' => [],
            'singles' => [161],
        ],
    ];

    /**
     * The seven AP Acceptance conditions, in documented order. Key is the
     * machine condition flag; value is the human-readable requirement.
     *
     * An AP "is not done until" every one of these holds.
     *
     * @var array<string,string>
     */
    public const ACCEPTANCE_CONDITIONS = [
        'contract_documented' => 'contract is documented',
        'artifact_exists_when_needed' => 'migration/model/service exists when needed',
        'events_and_read_models_declared' => 'events and read models are declared',
        'surfaces_aligned_when_applicable' => 'CLI/API/MCP surfaces are aligned when applicable',
        'tests_happy_and_guard_path' => 'tests include happy path and guard path',
        'gates_run' => 'docs-health and architecture validation are run',
        'knowledge_refreshed' => 'Knowledge sync/index is refreshed',
    ];

    /** Lowest defined phase number. */
    public const PHASE_MIN = 0;

    /** Highest defined phase number. */
    public const PHASE_MAX = 12;

    /**
     * Return the ordered Phase Map as a list of typed rows.
     *
     * @return array{count:int,min:int,max:int,phases:list<array{phase:int,purpose:string}>}
     */
    public function phaseMap(): array
    {
        $phases = [];
        foreach (self::PHASE_MAP as $phase => $purpose) {
            $phases[] = ['phase' => $phase, 'purpose' => $purpose];
        }

        return [
            'count' => count($phases),
            'min' => self::PHASE_MIN,
            'max' => self::PHASE_MAX,
            'phases' => $phases,
        ];
    }

    /**
     * Resolve a phase number to its documented purpose.
     *
     * @return array{resolved:bool,phase:int,purpose:?string,reason:?string}
     */
    public function resolvePhase(int $phase): array
    {
        if (! array_key_exists($phase, self::PHASE_MAP)) {
            return [
                'resolved' => false,
                'phase' => $phase,
                'purpose' => null,
                'reason' => 'phase_out_of_range_0_to_12',
            ];
        }

        return [
            'resolved' => true,
            'phase' => $phase,
            'purpose' => self::PHASE_MAP[$phase],
            'reason' => null,
        ];
    }

    /**
     * Route an AP number to the active family that owns it.
     *
     * The AP number may be given as an int (99) or a string ("AP-99", "ap99",
     * "99"). Unmapped numbers are NOT guessed — they are reported so the AP is
     * placed via the canonical index.
     *
     * @param int|string $ap
     *
     * @return array{resolved:bool,ap:int,family:?string,label:?string,reason:?string}
     */
    public function routeAp(int|string $ap): array
    {
        $number = $this->normalizeApNumber($ap);

        foreach (self::AP_FAMILIES as $family => $spec) {
            foreach ($spec['singles'] as $single) {
                if ($number === $single) {
                    return $this->ownedBy($number, $family, $spec['label']);
                }
            }
            foreach ($spec['ranges'] as [$low, $high]) {
                if ($number >= $low && $number <= $high) {
                    return $this->ownedBy($number, $family, $spec['label']);
                }
            }
        }

        return [
            'resolved' => false,
            'ap' => $number,
            'family' => null,
            'label' => null,
            'reason' => 'ap_not_in_active_families_place_via_canonical_index',
        ];
    }

    /**
     * Evaluate an AP against the seven-condition Acceptance gate.
     *
     * The supplied $signals is a flag map keyed by the ACCEPTANCE_CONDITIONS
     * keys. Any missing key is treated as false (unmet). "done" is the full
     * conjunction of all seven conditions.
     *
     * forbidden_changes enforcement: if the AP claims done while the quality
     * gate (gates_run) is not green OR knowledge has not been refreshed, the
     * verdict is demoted to not_done with an explicit evidence reason — an AP
     * may never declare readiness without verifiable evidence and green gates.
     *
     * @param array<string,bool> $signals
     *
     * @return array{
     *   done:bool,
     *   verdict:string,
     *   met:list<string>,
     *   unmet:list<string>,
     *   met_count:int,
     *   total:int,
     *   reason:?string
     * }
     */
    public function evaluateAcceptance(array $signals): array
    {
        $met = [];
        $unmet = [];

        foreach (self::ACCEPTANCE_CONDITIONS as $key => $_label) {
            if (($signals[$key] ?? false) === true) {
                $met[] = $key;
            } else {
                $unmet[] = $key;
            }
        }

        $total = count(self::ACCEPTANCE_CONDITIONS);
        $allMet = $unmet === [];

        // Evidence/green-gate invariant from frontmatter forbidden_changes:
        // readiness cannot be declared without the gate green and knowledge
        // refreshed, even if every other box is ticked.
        $evidenceProven = ($signals['gates_run'] ?? false) === true
            && ($signals['knowledge_refreshed'] ?? false) === true;

        if ($allMet && $evidenceProven) {
            return [
                'done' => true,
                'verdict' => 'done',
                'met' => $met,
                'unmet' => $unmet,
                'met_count' => count($met),
                'total' => $total,
                'reason' => null,
            ];
        }

        $reason = $allMet
            ? 'readiness_requires_verifiable_evidence_and_green_gates'
            : 'ap_not_done_unmet_acceptance_conditions';

        return [
            'done' => false,
            'verdict' => 'not_done',
            'met' => $met,
            'unmet' => $unmet,
            'met_count' => count($met),
            'total' => $total,
            'reason' => $reason,
        ];
    }

    /**
     * Normalize an AP identifier ("AP-99", "ap 99", "99", 99) to its integer.
     *
     * @param int|string $ap
     */
    private function normalizeApNumber(int|string $ap): int
    {
        if (is_int($ap)) {
            return $ap;
        }

        if (preg_match('/(\d+)/', $ap, $m) === 1) {
            return (int) $m[1];
        }

        throw new InvalidArgumentException('AP identifier has no number: ' . $ap);
    }

    /**
     * @return array{resolved:true,ap:int,family:string,label:string,reason:null}
     */
    private function ownedBy(int $number, string $family, string $label): array
    {
        return [
            'resolved' => true,
            'ap' => $number,
            'family' => $family,
            'label' => $label,
            'reason' => null,
        ];
    }
}
