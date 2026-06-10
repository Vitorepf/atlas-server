<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S156 — L10R2LongHorizonStrategyCertificationService (block: L10 Generative
 * Engineering Guard, phase R2).
 *
 * Read-only certification of the L10-R2 arrival criterion ("estrategia de
 * engenharia de longo horizonte"). The L10 generative-engineering map states the
 * criterion exactly (atlas-aaeos-l10-generative-engineering-map.md line 141, and
 * the checklist at line 217):
 *
 *   "existe um telos de engenharia plurianual curado pelo operador que o sistema
 *    persegue e auto-corrige com evidencia, sem deriva de fins."
 *
 * So R2 is real ONLY when three things hold together:
 *   1. a multi-year engineering telos exists AND was curated by the operator
 *      (sovereignty: the system proposes direction, the operator curates the ends
 *      — line 140: "o telos de engenharia e proposto pelo sistema mas curado e
 *      aprovado pelo operador");
 *   2. the system pursues that telos and self-corrects toward it WITH EVIDENCE
 *      (each step measured-or-reverted — line 140/217);
 *   3. there is NO drift of ends without operator approval ("sem deriva de fins":
 *      the system executes the strategy, it never chooses or silently moves the
 *      final ends — line 138/162).
 *
 * This certifier is honesty-first and fail-closed. It is read-only: it never
 * promotes a level, never mutates state, never executes a correction, never
 * reverts, never changes the telos and never hides a blocker. Every returned field
 * is COMPUTED from the supplied evidence (the curated-telos record, the correction
 * packets and the telos-pursuit events), never canned.
 *
 * Correction packets mirror the sibling R2 planner (S155,
 * L10TelosExecutionCorrectionPlanner): a packet is a real, evidence-backed
 * correction toward the telos ONLY when it carries measured-or-reverted evidence
 * AND does not change the final ends. A packet that would change the ends is not a
 * legitimate correction — long-horizon strategy self-corrects evidence, not values.
 *
 * Drift detection follows the load-bearing invariant of the L10 map (line 160-162):
 * objectives, values and ends stay with the operator forever. An ends-drift event
 * is one where the final ends moved (`drifts_ends`/`changes_final_ends`/
 * `value_drift`/`ends_changed` === true) WITHOUT explicit operator approval
 * (`operator_approved`/`operator_curated`/`approved` !== true). Such drift forfeits
 * the whole certification.
 *
 * Verdict rules, applied in the canonical enumerated order (the order the slice row
 * lists them):
 *   1. no curated telos                 -> `no_curated_telos`
 *      (there is no operator-curated multi-year telos to pursue);
 *   2. value drift                      -> `value_drift`
 *      (the ends drifted without operator approval — sovereignty breach);
 *   3. no evidence of correction        -> `no_correction_evidence`
 *      (the telos is not actually pursued and self-corrected with evidence).
 *   r2_certified=true ONLY when none of the three blockers fire.
 *
 * Pure: every returned field is computed from the method inputs via the rules
 * above. No I/O, DB, Eloquent, facade, provider, git, filesystem, clock or
 * randomness. Identical inputs always yield an identical certification.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l10-generative-engineering-map.md
 */
final class L10R2LongHorizonStrategyCertificationService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l10.r2_long_horizon_strategy_certification.v1';

    /** L10 phase this service certifies. */
    public const PHASE = 'L10-R2';

    public const STATUS_CERTIFIED = 'r2_certified';

    public const STATUS_BLOCKED = 'blocked_not_r2';

    /** Blocker when no operator-curated multi-year engineering telos is present. */
    public const BLOCKER_NO_CURATED_TELOS = 'no_curated_telos';

    /** Blocker when the engineering ends drifted without operator approval. */
    public const BLOCKER_VALUE_DRIFT = 'value_drift';

    /** Blocker when there is no evidence-backed correction toward the telos. */
    public const BLOCKER_NO_CORRECTION_EVIDENCE = 'no_correction_evidence';

    /**
     * Minimum horizon, in years, for a telos to count as "plurianual" (multi-year).
     * The L10 map fixes the criterion as a multi-year engineering telos; a sub-annual
     * horizon is tactical, not long-horizon strategy.
     */
    private const MIN_HORIZON_YEARS = 1.0;

    /**
     * Certify L10-R2 from long-horizon-strategy evidence.
     *
     * Recognised `$inputs`:
     *   - curated_telos | telos: array<string,mixed> — the curated multi-year
     *     engineering telos. It counts as curated ONLY when it carries a non-empty
     *     id (`curated_telos_id` / `telos_id` / `id`), was operator-curated
     *     (`operator_curated` / `curated` / `operator_approved` === true, or a
     *     non-empty `operator_receipt_id`) and spans at least MIN_HORIZON_YEARS
     *     (`horizon_years`, defaulting to 0 when absent). A bare non-empty string is
     *     also accepted as a telos id, but a bare string alone is NOT operator-curated
     *     unless `operator_curated`/`operator_receipt_id` is also supplied at top
     *     level. Fail-closed: absent / not-curated / sub-annual => no curated telos.
     *   - corrections | correction_packets: list of evidence-based correction
     *     packets toward the telos. A packet COUNTS as a correction only when it is an
     *     array carrying measured-or-reverted evidence — a non-empty `evidence_ref` /
     *     `evidence_refs`, or `measured === true` / `measured_or_reverted === true` —
     *     AND it does not change the final ends (`changes_ends` / `changes_final_ends`
     *     / `drifts_ends` !== true). A packet without evidence, or one that would move
     *     the ends, is not a legitimate correction.
     *   - drift_events | telos_pursuit_events | corrections: scanned for ends-drift.
     *     An event is ends-drift when `drifts_ends` / `changes_final_ends` /
     *     `value_drift` / `ends_changed` === true; it is drift WITHOUT operator
     *     approval when it also lacks `operator_approved` / `operator_curated` /
     *     `approved` === true. The count of such unapproved ends-drift events is
     *     surfaced and, when > 0, blocks. (The correction packets are themselves
     *     scanned for drift, so a packet that both lacks evidence and moves the ends
     *     is correctly counted as drift and excluded from corrections.)
     *
     * @param  array<string,mixed>  $inputs
     * @return array{
     *     schema_version:string,
     *     phase:string,
     *     r2_certified:bool,
     *     status:string,
     *     curated_telos_id:string,
     *     curated_telos_present:bool,
     *     horizon_years:float,
     *     correction_count:int,
     *     drift_without_operator_approval_count:int,
     *     blockers:list<string>
     * }
     */
    public function certify(array $inputs): array
    {
        $telos = $this->telosRecord($inputs);

        $curatedTelosId = $this->curatedTelosId($telos);
        $rawHorizonYears = $this->rawHorizonYears($telos);
        $horizonYears = $this->horizonYears($telos);
        $curatedTelosPresent = $this->isCuratedTelos($inputs, $telos, $curatedTelosId, $rawHorizonYears);

        $correctionCount = $this->correctionCount($inputs);
        $driftCount = $this->driftWithoutOperatorApprovalCount($inputs);

        // Blocker order follows the slice row's enumerated order: no curated telos,
        // then value drift, then no evidence of correction. No blocker is hidden.
        $blockers = [];

        if (! $curatedTelosPresent) {
            $blockers[] = self::BLOCKER_NO_CURATED_TELOS;
        }

        if ($driftCount > 0) {
            $blockers[] = self::BLOCKER_VALUE_DRIFT;
        }

        if ($correctionCount === 0) {
            $blockers[] = self::BLOCKER_NO_CORRECTION_EVIDENCE;
        }

        $certified = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => self::PHASE,
            'r2_certified' => $certified,
            'status' => $certified ? self::STATUS_CERTIFIED : self::STATUS_BLOCKED,
            // Only surface the id once the telos is actually operator-curated and
            // multi-year; an un-curated / sub-annual telos certifies nothing.
            'curated_telos_id' => $curatedTelosPresent ? $curatedTelosId : '',
            'curated_telos_present' => $curatedTelosPresent,
            'horizon_years' => $horizonYears,
            'correction_count' => $correctionCount,
            'drift_without_operator_approval_count' => $driftCount,
            'blockers' => $blockers,
        ];
    }

    /**
     * The telos record, taken from the first present of `curated_telos` / `telos`.
     * A bare string id is wrapped so its id is still readable; any other scalar is
     * dropped to an empty record.
     *
     * @param  array<string,mixed>  $inputs
     * @return array<string,mixed>
     */
    private function telosRecord(array $inputs): array
    {
        foreach (['curated_telos', 'telos'] as $key) {
            $value = $inputs[$key] ?? null;
            if (is_array($value)) {
                return $value;
            }
            if (is_string($value) && trim($value) !== '') {
                return ['curated_telos_id' => trim($value)];
            }
        }

        return [];
    }

    /**
     * The telos id, from `curated_telos_id` / `telos_id` / `id`. Honours a string
     * contract: only a non-empty trimmed string id counts (int keys or non-string
     * scalars never coerce into an id).
     *
     * @param  array<string,mixed>  $telos
     */
    private function curatedTelosId(array $telos): string
    {
        return AreaFocusScalarNormalizer::payloadString($telos, ['curated_telos_id', 'telos_id', 'id'], '');
    }

    /**
     * The telos horizon in years, from `horizon_years`. Non-numeric or non-finite
     * values default to 0.0 (fail-closed: an unstated horizon is not multi-year).
     * Rounded to a stable 4 decimals for deterministic SURFACED output only; the
     * multi-year gate compares the raw value (see rawHorizonYears) so a sub-annual
     * horizon can never be rounded up across the MIN_HORIZON_YEARS boundary.
     *
     * @param  array<string,mixed>  $telos
     */
    private function horizonYears(array $telos): float
    {
        return round($this->rawHorizonYears($telos), 4);
    }

    /**
     * The raw (unrounded) telos horizon in years, used for the multi-year gate.
     * Non-numeric or non-finite values default to 0.0 (fail-closed). Comparing the
     * raw value keeps the gate honest: a value strictly below MIN_HORIZON_YEARS
     * (e.g. 0.99999) stays sub-annual and is rejected, even though it would round
     * to 1.0 for the surfaced field.
     *
     * @param  array<string,mixed>  $telos
     */
    private function rawHorizonYears(array $telos): float
    {
        $value = $telos['horizon_years'] ?? null;

        return AreaFocusScalarNormalizer::finiteNumberOrZero($value);
    }

    /**
     * Whether an operator-curated multi-year engineering telos is present. Requires
     * a non-empty id, operator curation and a horizon of at least MIN_HORIZON_YEARS.
     * Operator curation is asserted on the telos record (`operator_curated` /
     * `curated` / `operator_approved` === true, or a non-empty `operator_receipt_id`)
     * or at the top level (`operator_curated` / `operator_receipt_id`). Fail-closed.
     *
     * @param  array<string,mixed>  $inputs
     * @param  array<string,mixed>  $telos
     * @param  float  $rawHorizonYears  the unrounded horizon, so a sub-annual value
     *                                  cannot round up across MIN_HORIZON_YEARS
     */
    private function isCuratedTelos(array $inputs, array $telos, string $curatedTelosId, float $rawHorizonYears): bool
    {
        if ($curatedTelosId === '') {
            return false;
        }

        if ($rawHorizonYears < self::MIN_HORIZON_YEARS) {
            return false;
        }

        return $this->operatorCurated($telos) || $this->operatorCurated($inputs);
    }

    /**
     * Whether a payload asserts explicit operator curation: a boolean curation flag
     * set true, or a non-empty operator receipt id. Fail-closed: absent => false.
     *
     * @param  array<string,mixed>  $payload
     */
    private function operatorCurated(array $payload): bool
    {
        foreach (['operator_curated', 'curated', 'operator_approved'] as $flag) {
            if (($payload[$flag] ?? null) === true) {
                return true;
            }
        }

        return AreaFocusScalarNormalizer::payloadString($payload, ['operator_receipt_id'], '') !== '';
    }

    /**
     * Count of legitimate, evidence-backed correction packets toward the telos. A
     * packet counts only when it is an array carrying measured-or-reverted evidence
     * AND does not change the final ends. Mirrors L10TelosExecutionCorrectionPlanner
     * (S155): corrections move strategy on evidence, never the ends.
     *
     * @param  array<string,mixed>  $inputs
     */
    private function correctionCount(array $inputs): int
    {
        $count = 0;
        foreach ($this->correctionPackets($inputs) as $packet) {
            if (! is_array($packet)) {
                continue;
            }

            if ($this->changesEnds($packet)) {
                // A packet that moves the ends is drift, not a correction.
                continue;
            }

            if ($this->hasCorrectionEvidence($packet)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Count of ends-drift events that lack explicit operator approval. Scans both the
     * dedicated drift / pursuit event lists AND the correction packets (a packet that
     * moves the ends is itself drift). The same underlying event is never double
     * counted because the lists are distinct inputs; each item is judged on its own.
     *
     * @param  array<string,mixed>  $inputs
     */
    private function driftWithoutOperatorApprovalCount(array $inputs): int
    {
        $count = 0;
        foreach ($this->driftCandidates($inputs) as $event) {
            if (! is_array($event)) {
                continue;
            }

            if ($this->changesEnds($event) && ! $this->operatorApprovedEvent($event)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The correction packets, from the first present of `corrections` /
     * `correction_packets`. Non-array items are dropped downstream.
     *
     * @param  array<string,mixed>  $inputs
     * @return list<mixed>
     */
    private function correctionPackets(array $inputs): array
    {
        foreach (['corrections', 'correction_packets'] as $key) {
            $value = $inputs[$key] ?? null;
            if (is_array($value)) {
                return array_values($value);
            }
        }

        return [];
    }

    /**
     * The events scanned for ends-drift: the dedicated `drift_events` /
     * `telos_pursuit_events` lists, plus the correction packets (a packet that moves
     * the ends counts as drift). Returns a flat list of candidate items.
     *
     * @param  array<string,mixed>  $inputs
     * @return list<mixed>
     */
    private function driftCandidates(array $inputs): array
    {
        $candidates = [];

        foreach (['drift_events', 'telos_pursuit_events'] as $key) {
            $value = $inputs[$key] ?? null;
            if (is_array($value)) {
                foreach (array_values($value) as $item) {
                    $candidates[] = $item;
                }
            }
        }

        foreach ($this->correctionPackets($inputs) as $packet) {
            $candidates[] = $packet;
        }

        return $candidates;
    }

    /**
     * Whether an event/packet would change the final engineering ends.
     *
     * @param  array<string,mixed>  $payload
     */
    private function changesEnds(array $payload): bool
    {
        foreach (['drifts_ends', 'changes_final_ends', 'changes_ends', 'value_drift', 'ends_changed'] as $flag) {
            if (($payload[$flag] ?? null) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an ends-drift event was explicitly approved/curated by the operator.
     * Fail-closed: absent => not approved (so unapproved drift is counted).
     *
     * @param  array<string,mixed>  $payload
     */
    private function operatorApprovedEvent(array $payload): bool
    {
        foreach (['operator_approved', 'operator_curated', 'approved'] as $flag) {
            if (($payload[$flag] ?? null) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a correction packet carries measured-or-reverted evidence: a non-empty
     * `evidence_ref`, a non-empty `evidence_refs` list, or an explicit `measured` /
     * `measured_or_reverted` === true. Fail-closed: no evidence => not a correction.
     *
     * @param  array<string,mixed>  $packet
     */
    private function hasCorrectionEvidence(array $packet): bool
    {
        if (($packet['measured'] ?? null) === true || ($packet['measured_or_reverted'] ?? null) === true) {
            return true;
        }

        if (AreaFocusScalarNormalizer::payloadString($packet, ['evidence_ref'], '') !== '') {
            return true;
        }

        $refs = $packet['evidence_refs'] ?? null;
        if (is_array($refs)) {
            foreach ($refs as $ref) {
                if (is_string($ref) && trim($ref) !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
