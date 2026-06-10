<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S160 — L10R1GenerativeEngineeringCertificationService (block: L10 Generative Engineering Guard).
 *
 * Read-only certification of L10-R1 ("generative engineering": inventing
 * paradigms/abstractions outside the known space, validated by outcome). It
 * COMPOSES upstream R1-line evidence into a deterministic verdict; it NEVER
 * promotes a level, NEVER mutates runtime, NEVER invents a paradigm and NEVER
 * hides a blocker. The DoD is the contract: "R1 certifies validated novelty; it
 * does not invent by itself" — this certifier reads what the loop already
 * produced and retained, it does not produce.
 *
 * Per the canonical map the precondition order is inviolable: the convergence
 * BOUND (R3) and operator sovereignty precede any R1/R2/R4 capability claim
 * ("bound + soberania antes de capacidade"). So this certifier is fail-closed and
 * leads with the R3 certificate, then validated novelty, then the absolute
 * invariant:
 *
 * Verdict rules (ordered, safety-first):
 *   1. the R3 bounded-recursion certificate must be present AND certified — a
 *      missing or non-certified R3 certificate emits `r3_certificate_missing`
 *      (generativity on top of unbounded recursion is the runaway scenario);
 *   2. at least one supplied paradigm must be a genuinely VALIDATED NOVEL
 *      paradigm — validated by outcome, novelty CONFIRMED (not unknown, not a
 *      renamed existing pattern), invariant-safe and with a strictly positive
 *      retained delta; if none qualify, `no_validated_paradigm` fires;
 *   3. no supplied paradigm may report an invariant violation — the L10
 *      invariant (operator is the sole source of engineering ends; nothing
 *      bypasses gate or sovereignty) is absolute, so a single violation emits
 *      `invariant_violation`, even if other paradigms validated.
 *   r1_certified=true ONLY when none of the three blockers fire.
 *
 * Bounds honoured exactly:
 *   - `validated_paradigm_count` is the number of DISTINCT paradigm ids that meet
 *     EVERY validation gate (validated + novel_confirmed + invariant_safe +
 *     retained_delta > 0); duplicate ids never inflate it and it can never exceed
 *     the number of distinct supplied paradigm ids;
 *   - `novelty_status` is the aggregate over validated paradigms: `novel_confirmed`
 *     when at least one validated novel paradigm exists, otherwise `unknown_not_new`
 *     — it is NEVER `novel_confirmed` without a validated novel paradigm behind it;
 *   - `retained_delta` is the conservative retained floor: the MINIMUM retained
 *     delta among the validated novel paradigms (the improvement that held for all
 *     of them), and 0.0 when none qualify — it is never reported positive without
 *     a validated paradigm to back it.
 *
 * Pure: every returned field is computed from the method inputs via the rules
 * above. No I/O, DB, Eloquent, facade, provider, git, filesystem, clock or
 * randomness. Identical inputs always yield an identical certification.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l10-generative-engineering-map.md
 */
final class L10R1GenerativeEngineeringCertificationService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l10.r1_generative_engineering_certification.v1';

    /** L10 phase this service certifies. */
    public const PHASE = 'L10-R1';

    public const STATUS_CERTIFIED = 'l10_r1_certified';

    public const STATUS_BLOCKED = 'blocked_not_l10_r1';

    /** Aggregate novelty verdict: a validated novel paradigm exists. */
    public const NOVELTY_CONFIRMED = 'novel_confirmed';

    /** Aggregate novelty verdict: nothing rose to confirmed novelty. */
    public const NOVELTY_UNKNOWN = 'unknown_not_new';

    /** Per-paradigm novelty status that counts as genuine, confirmed novelty. */
    private const PARADIGM_NOVELTY_CONFIRMED = 'novel_confirmed';

    /** Blocker emitted when the R3 bounded-recursion certificate is absent or not certified. */
    public const BLOCKER_R3_CERTIFICATE_MISSING = 'r3_certificate_missing';

    /** Blocker emitted when no supplied paradigm is a validated novel paradigm. */
    public const BLOCKER_NO_VALIDATED_PARADIGM = 'no_validated_paradigm';

    /** Blocker emitted when any supplied paradigm reports an invariant violation. */
    public const BLOCKER_INVARIANT_VIOLATION = 'invariant_violation';

    /**
     * Certify L10-R1 by composing the R3 certificate with the validated
     * generative-paradigm evidence.
     *
     * Recognised `$inputs`:
     *   - `r3_certificate`: bool, or array carrying `r3_certified` / `certified`
     *     (bool, true => the bounded-recursion certificate is in force). Anything
     *     else is fail-closed (treated as missing).
     *   - `paradigms`: list of generative-paradigm evidence envelopes. Each item
     *     is an array carrying a `paradigm_id` / `id` (non-empty string) and the
     *     validation evidence:
     *       - `validated` / `retained` (bool) — outcome-validated and retained;
     *       - `novelty_status` (string) — `novel_confirmed` is genuine novelty;
     *         `unknown_not_new` / `known_existing` / absent are NOT novelty;
     *       - `invariant_safe` (bool, default false) — no invariant violated;
     *         alternatively an explicit `invariant_violation === true` marks a
     *         violation;
     *       - `retained_delta` (number) — retained outcome improvement; must be
     *         strictly positive for the paradigm to count as validated novelty.
     *     Bare-string items name a paradigm id with no validation evidence (never
     *     a validated paradigm). Blank/duplicate ids collapse.
     *
     * @param  array<string,mixed>  $inputs
     * @return array{
     *     schema_version:string,
     *     phase:string,
     *     r1_certified:bool,
     *     r3_certificate_present:bool,
     *     supplied_paradigm_count:int,
     *     validated_paradigm_count:int,
     *     validated_paradigm_ids:list<string>,
     *     novelty_status:string,
     *     retained_delta:float,
     *     invariant_safe:bool,
     *     read_only:bool,
     *     status:string,
     *     blockers:list<string>
     * }
     */
    public function certify(array $inputs): array
    {
        $r3Present = $this->r3CertificatePresent($inputs['r3_certificate'] ?? null);

        $paradigms = $this->paradigmEnvelopes($inputs['paradigms'] ?? null);
        $suppliedIds = $this->distinctParadigmIds($paradigms);

        // A paradigm counts as validated novelty only when EVERY gate holds:
        // outcome-validated AND novelty confirmed AND invariant-safe AND a
        // strictly positive retained delta. Walking distinct ids keeps the count
        // bounded by the supplied set and lets duplicates collapse onto the first
        // qualifying envelope.
        $validatedIds = [];
        $validatedDeltas = [];
        foreach ($suppliedIds as $id) {
            $envelope = $this->firstValidatedEnvelopeForId($paradigms, $id);
            if ($envelope === null) {
                continue;
            }

            $validatedIds[] = $id;
            $validatedDeltas[] = $this->retainedDelta($envelope);
        }
        $validatedCount = count($validatedIds);

        // The retained floor: the improvement that held for ALL validated novel
        // paradigms (the minimum), never a peak; 0.0 when none qualify so a
        // positive delta is never reported without a paradigm behind it.
        $retainedDelta = $validatedDeltas === [] ? 0.0 : min($validatedDeltas);

        // The L10 invariant is absolute: a single supplied paradigm reporting a
        // violation poisons the whole certification, regardless of validation.
        $invariantSafe = ! $this->anyInvariantViolation($paradigms);

        $noveltyStatus = $validatedCount > 0 ? self::NOVELTY_CONFIRMED : self::NOVELTY_UNKNOWN;

        // Verdict, safety-first: R3 bound -> validated novelty -> absolute invariant.
        $blockers = [];

        if (! $r3Present) {
            $blockers[] = self::BLOCKER_R3_CERTIFICATE_MISSING;
        }

        if ($validatedCount === 0) {
            $blockers[] = self::BLOCKER_NO_VALIDATED_PARADIGM;
        }

        if (! $invariantSafe) {
            $blockers[] = self::BLOCKER_INVARIANT_VIOLATION;
        }

        $certified = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => self::PHASE,
            'r1_certified' => $certified,
            'r3_certificate_present' => $r3Present,
            'supplied_paradigm_count' => count($suppliedIds),
            'validated_paradigm_count' => $validatedCount,
            'validated_paradigm_ids' => $validatedIds,
            'novelty_status' => $noveltyStatus,
            'retained_delta' => $retainedDelta,
            'invariant_safe' => $invariantSafe,
            // The DoD invariant: R1 certifies, it never invents or mutates runtime.
            'read_only' => true,
            'status' => $certified ? self::STATUS_CERTIFIED : self::STATUS_BLOCKED,
            'blockers' => $blockers,
        ];
    }

    /**
     * The R3 certificate is present only when it explicitly certifies. A bool input
     * is the fast path; an array reads `r3_certified` / `certified`; anything else
     * is fail-closed (a generative claim never rides on an absent bound).
     */
    private function r3CertificatePresent(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (! is_array($raw)) {
            return false;
        }

        foreach (['r3_certified', 'certified'] as $flag) {
            if (array_key_exists($flag, $raw)) {
                return $raw[$flag] === true;
            }
        }

        return false;
    }

    /**
     * Normalise the supplied paradigms into a list of evidence envelopes. A
     * bare-string item becomes an envelope carrying only its id (no validation
     * evidence). Non-array, non-string items are dropped.
     *
     * @return list<array<string,mixed>>
     */
    private function paradigmEnvelopes(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $envelopes = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $envelopes[] = $item;

                continue;
            }

            $id = $this->stringId($item);
            if ($id !== '') {
                $envelopes[] = ['paradigm_id' => $id];
            }
        }

        return $envelopes;
    }

    /**
     * Distinct, order-preserving, non-empty paradigm ids across the supplied
     * envelopes. Honours a list<string> contract: ids must be non-empty strings
     * (int keys or non-string values never coerce into the set).
     *
     * @param  list<array<string,mixed>>  $paradigms
     * @return list<string>
     */
    private function distinctParadigmIds(array $paradigms): array
    {
        $ids = [];
        foreach ($paradigms as $envelope) {
            $id = $this->paradigmId($envelope);
            if ($id !== '' && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The first envelope declaring the given paradigm id that is itself a validated
     * novel paradigm, or null when none qualifies. Duplicates therefore collapse
     * onto the first QUALIFYING envelope: an earlier, unvalidated envelope sharing
     * the id never shadows a later validated one (a genuinely validated paradigm is
     * not dropped just because an unvalidated draft for the same id came first).
     * Every per-paradigm gate still applies individually.
     *
     * @param  list<array<string,mixed>>  $paradigms
     * @return array<string,mixed>|null
     */
    private function firstValidatedEnvelopeForId(array $paradigms, string $id): ?array
    {
        foreach ($paradigms as $envelope) {
            if ($this->paradigmId($envelope) === $id && $this->isValidatedNovelParadigm($envelope)) {
                return $envelope;
            }
        }

        return null;
    }

    /**
     * A paradigm is validated novelty only when every gate holds: outcome
     * validated/retained AND novelty CONFIRMED AND invariant-safe AND a strictly
     * positive retained delta. Any unmet gate disqualifies it.
     *
     * @param  array<string,mixed>  $envelope
     */
    private function isValidatedNovelParadigm(array $envelope): bool
    {
        if (! $this->isValidated($envelope)) {
            return false;
        }

        if ($this->noveltyStatusOf($envelope) !== self::PARADIGM_NOVELTY_CONFIRMED) {
            return false;
        }

        if (! $this->isInvariantSafe($envelope)) {
            return false;
        }

        return $this->retainedDelta($envelope) > 0.0;
    }

    /**
     * The paradigm is outcome-validated and retained. Either `validated` or
     * `retained` asserting true counts; absence is fail-closed (not validated).
     *
     * @param  array<string,mixed>  $envelope
     */
    private function isValidated(array $envelope): bool
    {
        return ($envelope['validated'] ?? false) === true
            || ($envelope['retained'] ?? false) === true;
    }

    /**
     * The per-paradigm novelty status, trimmed. Only `novel_confirmed` is genuine
     * novelty; `unknown_not_new`, `known_existing` or absent are not.
     *
     * @param  array<string,mixed>  $envelope
     */
    private function noveltyStatusOf(array $envelope): string
    {
        $status = $envelope['novelty_status'] ?? null;

        return is_string($status) ? trim($status) : '';
    }

    /**
     * The paradigm reports no invariant violation. An explicit
     * `invariant_violation === true` always means unsafe; otherwise
     * `invariant_safe === true` is required (fail-closed: absence is unsafe).
     *
     * @param  array<string,mixed>  $envelope
     */
    private function isInvariantSafe(array $envelope): bool
    {
        if (($envelope['invariant_violation'] ?? false) === true) {
            return false;
        }

        return ($envelope['invariant_safe'] ?? false) === true;
    }

    /**
     * Any supplied paradigm reporting an invariant violation. A violation is an
     * explicit `invariant_violation === true`, or `invariant_safe === false` stated
     * explicitly. A paradigm that simply omits both signals is NOT counted as a
     * violation here (it merely fails the per-paradigm safety gate above), so the
     * absolute-invariant blocker fires only on a real, stated violation.
     *
     * @param  list<array<string,mixed>>  $paradigms
     */
    private function anyInvariantViolation(array $paradigms): bool
    {
        foreach ($paradigms as $envelope) {
            if (($envelope['invariant_violation'] ?? false) === true) {
                return true;
            }

            if (array_key_exists('invariant_safe', $envelope) && $envelope['invariant_safe'] === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retained outcome improvement for a paradigm. Accepts an explicit
     * `retained_delta` or a `retained_metrics.retained_delta` nesting, mirroring
     * the L9 measured-or-reverted reader. Defaults to a negative sentinel so an
     * absent retained signal fails closed (never counts as positive retention).
     *
     * @param  array<string,mixed>  $envelope
     */
    private function retainedDelta(array $envelope): float
    {
        if (array_key_exists('retained_delta', $envelope)) {
            return AreaFocusScalarNormalizer::payloadFiniteFloat($envelope, 'retained_delta', -1.0);
        }

        $metrics = $envelope['retained_metrics'] ?? null;
        if (is_array($metrics)) {
            return AreaFocusScalarNormalizer::payloadFiniteFloat($metrics, 'retained_delta', -1.0);
        }

        return -1.0;
    }

    /**
     * Resolve a single paradigm id from an envelope: `paradigm_id` or `id`.
     *
     * @param  array<string,mixed>  $envelope
     */
    private function paradigmId(array $envelope): string
    {
        return $this->stringId($envelope['paradigm_id'] ?? ($envelope['id'] ?? null));
    }

    /**
     * Coerce a candidate id into a trimmed non-empty string, or '' when it is not a
     * usable string id. Non-string scalars are rejected (a list<string> contract is
     * never satisfied by an int/float/bool coerced to text).
     */
    private function stringId(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
