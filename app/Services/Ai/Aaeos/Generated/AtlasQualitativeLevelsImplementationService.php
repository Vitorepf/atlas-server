<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Qualitative Levels Implementation Queue decider.
 *
 * Pure, deterministic runtime for the implementation-queue doc. The doc is a
 * focused queue tracking the build status of qualitative levels QL-0 .. QL-7,
 * plus one hard governance Rule that gates P4+ claims. This service turns both
 * the queue table and the Rule into enforceable logic that never lies.
 *
 * Two documented mechanisms are modelled:
 *
 *  1. The queue table (doc body): eight ordered items QL-0 .. QL-7, each with a
 *     documented status drawn from a fixed vocabulary — Done, Implemented,
 *     Implemented scaffold, Started, Future, Scaffold active. `queue()` returns
 *     the table verbatim and classifies every status into a normalized
 *     implementation_state (done | implemented | scaffold | started | future)
 *     so callers can reason about progress without re-parsing prose.
 *
 *  2. The Rule (doc "## Rule"): a P4+ qualitative-level claim requires the
 *     Evidence Ledger, scored comparative-strategy evidence and human agency
 *     gates; p4_promotion_readiness.status=ready is required before any P4
 *     claim; pending scheduled reviews are evidence of discipline, not proof of
 *     outcome. `evaluateP4Claim()` enforces exactly this: a claim at P4 or above
 *     is allowed ONLY when the promotion readiness status is literally "ready"
 *     AND all three required gates (evidence_ledger, comparative_strategy_evidence,
 *     human_agency) are present. A "pending" readiness status — even with a
 *     scheduled review attached — is explicitly NOT proof and is blocked. Claims
 *     below P4 do not require the gate.
 *
 * Evidence gate (doc frontmatter `decisions` + `forbidden_changes`):
 * qualitative level implementation stays proposal/gated until the Evidence Ledger
 * and scored comparative-strategy review prove benefit, and per
 * `forbidden_changes` no runtime/maturity/readiness may be declared without
 * verifiable evidence and green gates. Each gate flag must be an explicit, truthy
 * boolean — absent or falsey evidence never satisfies a gate, so a P4+ claim
 * cannot be inflated by silence.
 *
 * NEVER calls a provider. NEVER executes a strategy. NEVER promotes a level.
 * No database. Read/decide only.
 *
 * @see docs/engineering-knowledge-base/roadmap/qualitative-levels-implementation.md
 */
class AtlasQualitativeLevelsImplementationService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.qualitative_levels_implementation.v1';

    /** Doc "## Rule": the qualitative level at and above which the gate applies. */
    public const PROMOTION_GATE_LEVEL = 4;

    /**
     * Doc "## Rule": the readiness status that MUST hold before a P4+ claim.
     * Anything else (notably "pending") is blocked.
     */
    public const READY_STATUS = 'ready';

    /**
     * Doc "## Rule": the three named proof families a P4+ claim must carry.
     *
     * @var array<int,string>
     */
    public const REQUIRED_P4_GATES = [
        'evidence_ledger',
        'comparative_strategy_evidence',
        'human_agency',
    ];

    /**
     * Doc body queue table — QL-0 .. QL-7 with their documented status verbatim.
     *
     * @var array<int,array{id:string,title:string,status:string}>
     */
    public const QUEUE = [
        ['id' => 'QL-0', 'title' => 'Documentation promotion', 'status' => 'Done'],
        ['id' => 'QL-1', 'title' => 'Maturity read model', 'status' => 'Implemented'],
        ['id' => 'QL-2', 'title' => 'Comparative Strategy read model', 'status' => 'Implemented'],
        ['id' => 'QL-3', 'title' => 'Strategic Decision scaffold', 'status' => 'Implemented scaffold'],
        ['id' => 'QL-4', 'title' => 'Co-Strategist plan-only', 'status' => 'Started'],
        ['id' => 'QL-5', 'title' => 'Curator mutation classes', 'status' => 'Future'],
        ['id' => 'QL-6', 'title' => 'Presence and eclipse', 'status' => 'Started'],
        ['id' => 'QL-7', 'title' => 'Voice Realtime Surface', 'status' => 'Scaffold active'],
    ];

    /**
     * Normalize a documented status string into a stable implementation_state.
     * Returns 'unknown' for any status outside the doc's vocabulary so drift is
     * surfaced rather than silently coerced.
     *
     * @var array<string,string>
     */
    private const STATE_MAP = [
        'done' => 'done',
        'implemented' => 'implemented',
        'implemented scaffold' => 'scaffold',
        'scaffold active' => 'scaffold',
        'scaffold' => 'scaffold',
        'started' => 'started',
        'future' => 'future',
    ];

    /**
     * Return the implementation queue as a normalized read model.
     *
     * Every item carries its verbatim documented status plus a normalized
     * implementation_state, and the summary counts items by state. No item is
     * ever reported as more advanced than its documented status.
     *
     * @return array<string,mixed>
     */
    public function queue(): array
    {
        $items = [];
        $counts = ['done' => 0, 'implemented' => 0, 'scaffold' => 0, 'started' => 0, 'future' => 0, 'unknown' => 0];

        foreach (self::QUEUE as $row) {
            $state = $this->implementationState($row['status']);
            $counts[$state] = ($counts[$state] ?? 0) + 1;

            $items[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'status' => $row['status'],
                'implementation_state' => $state,
                // A scaffold/started/future item is explicitly not delivered runtime.
                'is_delivered' => in_array($state, ['done', 'implemented'], true),
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'item_count' => count($items),
            'items' => $items,
            'summary' => $counts,
            'rule' => $this->ruleStatement(),
        ];
    }

    /**
     * Map a documented status to its normalized implementation_state.
     */
    public function implementationState(string $status): string
    {
        return self::STATE_MAP[strtolower(trim($status))] ?? 'unknown';
    }

    /**
     * Doc "## Rule": evaluate whether a qualitative-level claim is allowed.
     *
     * A claim targeting level P(N) with N >= PROMOTION_GATE_LEVEL is allowed ONLY
     * when the promotion readiness status is exactly READY_STATUS and all three
     * REQUIRED_P4_GATES are present and truthy. A "pending" readiness status —
     * even when a scheduled review is supplied — is discipline, not proof, and is
     * blocked. Claims below the gate level are allowed and do not require gates.
     *
     * @param  array{
     *     level?:int|string,
     *     readiness_status?:string,
     *     gates?:array<string,bool>,
     *     scheduled_review?:bool
     * }  $claim
     * @return array<string,mixed>
     */
    public function evaluateP4Claim(array $claim): array
    {
        $level = $this->parseLevel($claim['level'] ?? null);
        $readiness = $this->stringOrNull($claim['readiness_status'] ?? null) ?? 'unknown';
        $gatesIn = is_array($claim['gates'] ?? null) ? $claim['gates'] : [];
        $hasScheduledReview = ($claim['scheduled_review'] ?? null) === true;

        $gateLevel = $level >= self::PROMOTION_GATE_LEVEL;

        // Per-gate presence: strictly-true only — absence or falsey never counts.
        $gateChecks = [];
        $missingGates = [];
        foreach (self::REQUIRED_P4_GATES as $gate) {
            $present = ($gatesIn[$gate] ?? null) === true;
            $gateChecks[$gate] = $present;
            if (! $present) {
                $missingGates[] = $gate;
            }
        }

        $readinessReady = $readiness === self::READY_STATUS;

        $reasons = [];

        if (! $gateLevel) {
            // Below P4 the gate does not apply; the claim is allowed as-is.
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'level' => $level,
                'gate_applies' => false,
                'allowed' => true,
                'readiness_status' => $readiness,
                'readiness_ready' => $readinessReady,
                'gate_checks' => $gateChecks,
                'missing_gates' => $missingGates,
                'scheduled_review_present' => $hasScheduledReview,
                'reasons' => $reasons,
                'rule' => $this->ruleStatement(),
            ];
        }

        if (! $readinessReady) {
            $reasons[] = 'p4_promotion_readiness_not_ready';
            // The doc is explicit: a scheduled review is discipline, not proof.
            if ($hasScheduledReview && $readiness === 'pending') {
                $reasons[] = 'pending_scheduled_review_is_discipline_not_proof';
            }
        }
        foreach ($missingGates as $gate) {
            $reasons[] = 'missing_'.$gate.'_gate';
        }

        $allowed = $readinessReady && $missingGates === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'level' => $level,
            'gate_applies' => true,
            'allowed' => $allowed,
            'readiness_status' => $readiness,
            'readiness_ready' => $readinessReady,
            'gate_checks' => $gateChecks,
            'missing_gates' => $missingGates,
            'scheduled_review_present' => $hasScheduledReview,
            'reasons' => $reasons,
            'rule' => $this->ruleStatement(),
        ];
    }

    /**
     * The doc's canonical Rule, surfaced verbatim for receipts and audit.
     *
     * @return array<string,mixed>
     */
    public function ruleStatement(): array
    {
        return [
            'id' => 'no_p4_plus_claim_without_evidence',
            'gate_level' => self::PROMOTION_GATE_LEVEL,
            'required_readiness_status' => self::READY_STATUS,
            'required_gates' => self::REQUIRED_P4_GATES,
            'statement' => 'No P4+ claim without Evidence Ledger, scored comparative-strategy evidence and '
                .'human agency gates; p4_promotion_readiness.status=ready is required before any P4 claim; '
                .'pending scheduled reviews are evidence of discipline, not proof of outcome.',
            'pending_review_is_proof' => false,
        ];
    }

    /**
     * Parse a level given as an int, "P4", "p4", or "4" into an int. Unknown or
     * malformed input is treated as 0 (well below the gate), never as P4+.
     */
    private function parseLevel(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/(\d+)/', $value, $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = strtolower(trim($value));

        return $trimmed === '' ? null : $trimmed;
    }
}
