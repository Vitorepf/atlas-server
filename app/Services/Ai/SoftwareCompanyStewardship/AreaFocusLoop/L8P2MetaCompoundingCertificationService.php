<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S117 — L8P2MetaCompoundingCertificationService (block: L8 Transcendence).
 *
 * Read-only certification of L8-P2 ("Metrica auto-evolutiva" / meta-compounding):
 * the arrival proof that the antifragility equation (N x M) contains at least one
 * multiplier factor the SYSTEM ITSELF discovered, whose weight was derived from a
 * MEASURED contribution, and that adding/re-deriving it actually pushed aggregate
 * dM/dt up — never down. It COMPOSES the S113-S116 meta-compounding chain
 * (candidate miner -> contribution attributor -> weight re-derivation -> adoption
 * gate) into a deterministic verdict. It never adopts a factor, never mutates the
 * equation and never hides a blocker: P2 is certified only from already-measured
 * evidence (atlas-aaeos-l8-transcendence-map.md, P2 criterio line 156 and the
 * transcendence checklist line 244).
 *
 * What makes a discovered factor QUALIFYING (all four must hold for one factor):
 *   1. system_discovered — the factor was mined by the system, not hand-authored
 *      (an operator-authored / pre-existing current factor is not a P2 discovery);
 *   2. measured contribution — a strictly positive measured contribution score
 *      (a weight can only be derived from real contribution, never from imagination);
 *   3. evidence-backed — at least one non-empty evidence ref (a factor with a claimed
 *      contribution but no evidence is FABRICATED and can never qualify);
 *   4. safe status — adopted (passed the S116 adoption gate) OR proposed (governed,
 *      awaiting operator/governor) — any rejected / fabricated status disqualifies it.
 *
 * Verdict rules (ordered, honesty-first — the same doctrine as the P5 and P1 certs):
 *   - zero discovered factors blocks (no_discovered_factors) — and nothing deeper is
 *     meaningful yet;
 *   - any factor whose weight changed without P5 immunity holding blocks
 *     (weight_change_without_p5) — safety precedes capability, a weight can never move
 *     on a metric that may be gamed;
 *   - any factor claiming a contribution with no evidence blocks
 *     (fabricated_factor_without_evidence) — a fabricated multiplier is exactly the
 *     Goodhart trap P5 guards against;
 *   - aggregate dM/dt must have risen after the re-derivation; a flat/negative delta
 *     blocks (dm_dt_not_improved) — meta-compounding that does not compound is not P2;
 *   - with all of the above clean, P2 is certified only when at least one QUALIFYING
 *     discovered factor exists (no_qualifying_discovered_factor otherwise).
 *
 * Pure: every returned field is computed from the method inputs via the rules
 * above. No I/O, DB, Eloquent, facade, provider, git, filesystem, clock or
 * randomness. Identical inputs always yield an identical certification.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l8-transcendence-map.md
 * @see app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/L8MetaCompoundingAdoptionGate.php
 */
final class L8P2MetaCompoundingCertificationService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.p2_meta_compounding_certification.v1';

    /** L8 phase this service certifies. */
    public const PHASE = 'L8-P2';

    public const STATUS_CERTIFIED = 'p2_certified';

    public const STATUS_BLOCKED = 'blocked_not_p2';

    /** Zero system-discovered factors in the supplied history. */
    public const BLOCKER_NO_DISCOVERED_FACTORS = 'no_discovered_factors';

    /** A factor whose weight changed while P5 self-deception immunity was not held. */
    public const BLOCKER_WEIGHT_CHANGE_WITHOUT_P5 = 'weight_change_without_p5';

    /** A factor claiming a contribution with no supporting evidence ref. */
    public const BLOCKER_FABRICATED_FACTOR_WITHOUT_EVIDENCE = 'fabricated_factor_without_evidence';

    /** Aggregate dM/dt did not rise after the re-derivation. */
    public const BLOCKER_DM_DT_NOT_IMPROVED = 'dm_dt_not_improved';

    /** No discovered factor satisfied every qualifying condition. */
    public const BLOCKER_NO_QUALIFYING_DISCOVERED_FACTOR = 'no_qualifying_discovered_factor';

    /**
     * Safe statuses for an adopted/proposed factor. "adopted" passed the S116
     * adoption gate; "proposed" is a governed proposal awaiting operator/governor.
     * Anything else (rejected/fabricated/reverted) is unsafe and disqualifies a
     * factor from counting toward P2.
     *
     * @var list<string>
     */
    private const SAFE_STATUSES = [
        'adopted',
        'proposed',
    ];

    /**
     * Certify L8-P2 by composing the S113-S116 meta-compounding evidence.
     *
     * Recognised `$inputs`:
     *   - discovered_factors: list<array<string,mixed>> — each record may carry:
     *       - factor_id | candidate_id (string) identifying the factor;
     *       - system_discovered=true (or discovered_by='system') marking it as a
     *         system discovery rather than a hand-authored current factor;
     *       - measured_contribution | contribution_score (int|float) — the measured
     *         contribution the weight was derived from (must be > 0 to qualify);
     *       - evidence_refs (list<string>) — non-empty evidence backing the
     *         contribution (a claimed contribution with none is fabricated);
     *       - status (string) — 'adopted' | 'proposed' | 'rejected' | ...;
     *       - weight_changed=true when this factor moved a weight in the equation;
     *       - p5_passed=true (or p5_evidence_ref present and p5_divergence_detected
     *         not true) asserting P5 immunity held for that weight change.
     *   - dm_dt_before | dm_dt_after (int|float) — aggregate dM/dt around the
     *     re-derivation; dm_dt_delta = after - before must be > 0. A precomputed
     *     dm_dt_delta may be supplied directly instead.
     *
     * @param  array<string,mixed>  $inputs
     * @return array{
     *     schema_version:string,
     *     phase:string,
     *     p2_certified:bool,
     *     status:string,
     *     discovered_factor_count:int,
     *     adopted_count:int,
     *     qualifying_factor_count:int,
     *     fabricated_factor_count:int,
     *     weight_change_without_p5_count:int,
     *     dm_dt_delta:float,
     *     blockers:list<string>
     * }
     */
    public function certify(array $inputs): array
    {
        $factors = $this->factorList($inputs['discovered_factors'] ?? null);
        $dmDtDelta = $this->dmDtDelta($inputs);

        $discoveredCount = 0;
        $adoptedCount = 0;
        $qualifyingCount = 0;
        $fabricatedCount = 0;
        $weightChangeWithoutP5Count = 0;

        foreach ($factors as $factor) {
            // Only factors the SYSTEM discovered count toward P2. A pre-existing
            // hand-authored current factor is not a P2 discovery and is ignored.
            if (! $this->isSystemDiscovered($factor)) {
                continue;
            }

            $discoveredCount++;

            $status = $this->status($factor);
            if ($status === 'adopted') {
                $adoptedCount++;
            }

            $hasContribution = $this->measuredContribution($factor) > 0.0;
            $hasEvidence = $this->hasEvidence($factor);

            // A claimed contribution with no evidence is a fabricated multiplier.
            if ($hasContribution && ! $hasEvidence) {
                $fabricatedCount++;
            }

            // A weight that moved while P5 immunity was not held is unsafe — safety
            // precedes capability, a weight can never move on a gameable metric.
            if ($this->weightChanged($factor) && ! $this->p5Passed($factor)) {
                $weightChangeWithoutP5Count++;
            }

            if ($this->isQualifying($factor, $hasContribution, $hasEvidence, $status)) {
                $qualifyingCount++;
            }
        }

        $blockers = $this->resolveBlockers(
            $discoveredCount,
            $weightChangeWithoutP5Count,
            $fabricatedCount,
            $dmDtDelta,
            $qualifyingCount,
        );

        $certified = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => self::PHASE,
            'p2_certified' => $certified,
            'status' => $certified ? self::STATUS_CERTIFIED : self::STATUS_BLOCKED,
            'discovered_factor_count' => $discoveredCount,
            'adopted_count' => $adoptedCount,
            'qualifying_factor_count' => $qualifyingCount,
            'fabricated_factor_count' => $fabricatedCount,
            'weight_change_without_p5_count' => $weightChangeWithoutP5Count,
            'dm_dt_delta' => $dmDtDelta,
            'blockers' => $blockers,
        ];
    }

    /**
     * Ordered, honesty-first blocker resolution. Zero discovered factors blocks
     * first (nothing deeper is meaningful). Then a weight change without P5 blocks
     * before any lift rule (safety precedes capability). Then a fabricated factor
     * blocks. Then a non-improving aggregate dM/dt blocks. Finally a clean history
     * with no qualifying discovered factor still blocks (P2 was not proven).
     *
     * @return list<string>
     */
    private function resolveBlockers(
        int $discoveredCount,
        int $weightChangeWithoutP5Count,
        int $fabricatedCount,
        float $dmDtDelta,
        int $qualifyingCount,
    ): array {
        if ($discoveredCount === 0) {
            // Zero discovered factors blocks — and nothing else is meaningful yet.
            return [self::BLOCKER_NO_DISCOVERED_FACTORS];
        }

        $blockers = [];

        if ($weightChangeWithoutP5Count > 0) {
            $blockers[] = self::BLOCKER_WEIGHT_CHANGE_WITHOUT_P5;
        }

        if ($fabricatedCount > 0) {
            $blockers[] = self::BLOCKER_FABRICATED_FACTOR_WITHOUT_EVIDENCE;
        }

        if ($dmDtDelta <= 0.0) {
            $blockers[] = self::BLOCKER_DM_DT_NOT_IMPROVED;
        }

        if ($qualifyingCount === 0) {
            $blockers[] = self::BLOCKER_NO_QUALIFYING_DISCOVERED_FACTOR;
        }

        return $blockers;
    }

    /**
     * A discovered factor qualifies for P2 only when it is system-discovered (the
     * caller already filtered for this), carries a strictly positive MEASURED
     * contribution, is backed by evidence, and holds a safe adopted/proposed status.
     *
     * @param  array<string,mixed>  $factor
     */
    private function isQualifying(array $factor, bool $hasContribution, bool $hasEvidence, string $status): bool
    {
        if (! $hasContribution || ! $hasEvidence) {
            return false;
        }

        if (! in_array($status, self::SAFE_STATUSES, true)) {
            return false;
        }

        // A qualifying factor that also moved a weight must have held P5 immunity.
        if ($this->weightChanged($factor) && ! $this->p5Passed($factor)) {
            return false;
        }

        return $this->measuredContribution($factor) > 0.0;
    }

    /**
     * Aggregate dM/dt delta around the re-derivation. A precomputed dm_dt_delta is
     * honoured directly; otherwise it is after - before. Defaults keep it at 0.0
     * (which does not improve) when no signal is supplied. The subtraction is kept
     * finite: two finite operands near ±PHP_FLOAT_MAX can still overflow to ±INF, and
     * an INF/NAN delta is not a real movement, so it collapses to 0.0 (no signal).
     * This preserves the declared `float` delta contract and keeps the cert
     * honesty-first: a +INF delta must never read as a real rise that certifies P2
     * (INF <= 0.0 is false), mirroring the S116 adoption gate's finiteDelta.
     *
     * @param  array<string,mixed>  $inputs
     */
    private function dmDtDelta(array $inputs): float
    {
        if (array_key_exists('dm_dt_delta', $inputs) && $this->isNumeric($inputs['dm_dt_delta'])) {
            return (float) $inputs['dm_dt_delta'];
        }

        $before = $this->isNumeric($inputs['dm_dt_before'] ?? null) ? (float) $inputs['dm_dt_before'] : 0.0;
        $after = $this->isNumeric($inputs['dm_dt_after'] ?? null) ? (float) $inputs['dm_dt_after'] : 0.0;

        $delta = $after - $before;

        return is_finite($delta) ? $delta : 0.0;
    }

    /**
     * @param  mixed  $value
     * @return list<array<string,mixed>>
     */
    private function factorList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * The factor was discovered by the system (mined from evidence) rather than
     * hand-authored. Accepts an explicit system_discovered flag or discovered_by
     * naming the system.
     *
     * @param  array<string,mixed>  $factor
     */
    private function isSystemDiscovered(array $factor): bool
    {
        if (($factor['system_discovered'] ?? false) === true) {
            return true;
        }

        $by = strtolower(trim((string) ($factor['discovered_by'] ?? $factor['origin'] ?? '')));

        return in_array($by, ['system', 'loop', 'self', 'atlas'], true);
    }

    /**
     * Normalised status string of a factor. Unknown/absent status is 'unknown',
     * which is never a safe status.
     *
     * @param  array<string,mixed>  $factor
     */
    private function status(array $factor): string
    {
        $raw = strtolower(trim((string) ($factor['status'] ?? '')));

        if ($raw !== '') {
            return $raw;
        }

        // Flag-driven fallbacks for callers that pass booleans instead of a status.
        if (($factor['adopted'] ?? false) === true) {
            return 'adopted';
        }
        if (($factor['proposed'] ?? false) === true) {
            return 'proposed';
        }

        return 'unknown';
    }

    /**
     * The measured contribution the weight was derived from. Mirrors the field
     * names used by the S114 attributor / S116 adoption gate.
     *
     * @param  array<string,mixed>  $factor
     */
    private function measuredContribution(array $factor): float
    {
        $value = $factor['measured_contribution']
            ?? $factor['contribution_score']
            ?? $factor['contribution']
            ?? null;

        return $this->isNumeric($value) ? (float) $value : 0.0;
    }

    /**
     * A factor is evidence-backed when it carries at least one non-empty evidence
     * ref. A claimed contribution with none is fabricated.
     *
     * @param  array<string,mixed>  $factor
     */
    private function hasEvidence(array $factor): bool
    {
        $refs = $factor['evidence_refs'] ?? $factor['source_refs'] ?? null;

        if (! is_array($refs)) {
            return false;
        }

        foreach ($refs as $ref) {
            if (is_string($ref) && trim($ref) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The factor moved a weight in the equation (re-derivation actually changed a
     * weight rather than merely proposing one with no change).
     *
     * @param  array<string,mixed>  $factor
     */
    private function weightChanged(array $factor): bool
    {
        return ($factor['weight_changed'] ?? false) === true
            || ($factor['weight_change'] ?? false) === true;
    }

    /**
     * P5 self-deception immunity held for this factor's weight change. Accepts an
     * explicit p5_passed flag, or a present P5 evidence ref with no detected
     * divergence/gaming (mirrors the S116 adoption gate's P5 reasoning). Fail-closed:
     * absent evidence never passes.
     *
     * @param  array<string,mixed>  $factor
     */
    private function p5Passed(array $factor): bool
    {
        if (array_key_exists('p5_passed', $factor)) {
            return $factor['p5_passed'] === true;
        }

        $ref = $factor['p5_evidence_ref'] ?? null;
        $hasRef = is_string($ref) && trim($ref) !== '';

        if (! $hasRef) {
            return false;
        }

        $divergence = ($factor['p5_divergence_detected'] ?? false) === true
            || ($factor['gaming_detected'] ?? false) === true;

        return ! $divergence;
    }

    /**
     * @param  mixed  $value
     */
    private function isNumeric($value): bool
    {
        if (is_int($value)) {
            return true;
        }

        // A non-finite (INF/NAN) float is not a real measurement: it must NOT count as a
        // numeric signal. Otherwise a NaN dm_dt_delta would skip the dm_dt_not_improved
        // blocker (NAN <= 0.0 is false) and falsely certify P2 — an honesty-first breach.
        return is_float($value) && is_finite($value);
    }
}
