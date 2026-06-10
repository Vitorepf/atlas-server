<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S150 — L10 Generative Engineering Guard / recursive depth-limit gate (R3).
 *
 * Recursive self-improvement (improving the mechanism that improves the
 * mechanism...) is only permitted UP TO the depth where a convergence bound has
 * been formally PROVEN. L10 map line 151 / R3 doctrine: "a recursao so e
 * permitida ATE a profundidade onde o bound de convergencia e provado ...
 * demote automatico e parada dura diante de qualquer sinal de divergencia ou
 * gaming" and the arrival criterion "a recursao roda exatamente ate esse limite,
 * nunca alem." Self-improvement governance ladder: autopromotion only for the
 * bounded, proven, reversible band.
 *
 * This gate reads a recursion request together with the verified convergence
 * proof envelope (produced by the R3 proof verifier) and decides whether the
 * request may run, what the proven ceiling is, whether a hard stop is required,
 * and why it was blocked.
 *
 * Ordered rules, mirroring the slice acceptance (fail-closed first):
 *  1. No verified proof -> max_allowed_depth = 0 (the bound defaults to zero
 *     when the proof is absent or unverified; nothing recursive is allowed).
 *  2. requested_depth above the proven bound -> block (never run beyond the
 *     proven ceiling) and hard-stop the runaway request.
 *  3. Unknown recursion class (the request's recursion class is not enumerated
 *     by the verified proof) -> block; the bound only covers the classes it
 *     actually proved.
 *  4. Any divergence or gaming signal -> block and require a hard stop
 *     (parada dura), even within the proven bound.
 *
 * Pure and read-only: every returned field is computed from the two method
 * inputs via real rules (clamping, set membership against the proof-covered
 * recursion-class set, deterministic slugging, integer comparison). It never
 * runs recursion, never mutates state, and performs no I/O, DB, facade, clock or
 * randomness — it only judges a request, it never executes one.
 */
final class L10RecursiveDepthLimitGate
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l10.recursive_depth_limit_gate.v1';

    /**
     * The bound that holds when no verified convergence proof exists: recursion
     * defaults to zero depth (R3: build the bound before any recursion expands).
     */
    private const DEFAULT_MAX_ALLOWED_DEPTH = 0;

    /**
     * Emitted when the convergence proof is absent or not verified. Without a
     * proven bound the gate fails closed at depth zero.
     */
    private const BLOCKER_NO_VERIFIED_PROOF = 'no_verified_proof';

    /**
     * Emitted when the requested recursion depth exceeds the proven bound. The
     * recursion runs exactly up to the proven bound, never beyond.
     */
    private const BLOCKER_REQUESTED_DEPTH_ABOVE_BOUND = 'requested_depth_above_bound';

    /**
     * Emitted when the request's recursion class is not one the proof actually
     * covered. A bound only authorises the recursion classes it proved.
     */
    private const BLOCKER_UNKNOWN_RECURSION_CLASS = 'unknown_recursion_class';

    /**
     * Emitted when the proof envelope reports a divergence or metric-gaming
     * signal: the gate demands an immediate hard stop (parada dura).
     */
    private const BLOCKER_DIVERGENCE_OR_GAMING_SIGNAL = 'divergence_or_gaming_signal';

    /**
     * Decide whether a recursive self-improvement request may run within the
     * verified convergence bound.
     *
     * @param  array<string, mixed>  $request  the recursion request (requested_depth, recursion_class)
     * @param  array<string, mixed>  $proof  the verified convergence proof envelope
     * @return array{
     *     schema_version: string,
     *     allowed: bool,
     *     requested_depth: int,
     *     max_allowed_depth: int,
     *     hard_stop: bool,
     *     blockers: list<string>
     * }
     */
    public function decide(array $request, array $proof): array
    {
        $requestedDepth = $this->requestedDepth($request);
        $proofVerified = $this->isProofVerified($proof);

        // Rule 1: no verified proof -> the proven bound is zero.
        $maxAllowedDepth = $proofVerified
            ? $this->maxProvenDepth($proof)
            : self::DEFAULT_MAX_ALLOWED_DEPTH;

        $recursionClass = $this->recursionClass($request);
        $coveredClasses = $this->coveredRecursionClasses($proof);
        $classCovered = $recursionClass !== '' && in_array($recursionClass, $coveredClasses, true);

        $depthAboveBound = $requestedDepth > $maxAllowedDepth;
        $divergenceOrGaming = $this->hasDivergenceOrGamingSignal($proof);

        $blockers = [];

        // Blockers follow the acceptance order: proof -> bound -> class -> divergence.
        if (! $proofVerified) {
            $blockers[] = self::BLOCKER_NO_VERIFIED_PROOF;
        }

        // Rule 2: requested depth above the proven bound blocks.
        if ($depthAboveBound) {
            $blockers[] = self::BLOCKER_REQUESTED_DEPTH_ABOVE_BOUND;
        }

        // Rule 3: an unknown / unproven recursion class blocks.
        if (! $classCovered) {
            $blockers[] = self::BLOCKER_UNKNOWN_RECURSION_CLASS;
        }

        // Rule 4: any divergence or gaming signal blocks and forces a hard stop.
        if ($divergenceOrGaming) {
            $blockers[] = self::BLOCKER_DIVERGENCE_OR_GAMING_SIGNAL;
        }

        // Hard stop is required when recursion would run beyond the proven bound
        // or when a divergence/gaming signal is observed (parada dura).
        $hardStop = $depthAboveBound || $divergenceOrGaming;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'allowed' => $blockers === [],
            'requested_depth' => $requestedDepth,
            'max_allowed_depth' => $maxAllowedDepth,
            'hard_stop' => $hardStop,
            'blockers' => array_values($blockers),
        ];
    }

    /**
     * The requested recursion depth, clamped to a non-negative integer. A
     * missing or negative request cannot ask for "less than no recursion", so it
     * normalises to zero.
     *
     * @param  array<string, mixed>  $request
     */
    private function requestedDepth(array $request): int
    {
        return max(0, AreaFocusScalarNormalizer::payloadSaturatingInt($request, 'requested_depth', 0));
    }

    /**
     * The convergence proof is verified only when its envelope explicitly says
     * so. Fail-closed: anything other than a literal true is unverified.
     *
     * @param  array<string, mixed>  $proof
     */
    private function isProofVerified(array $proof): bool
    {
        return ($proof['verified'] ?? false) === true
            && ($proof['proof_status'] ?? 'verified') !== 'not_verified';
    }

    /**
     * The maximum depth the proof actually proved, clamped to a non-negative
     * integer. Accepts max_proven_depth or proven_depth; defaults to zero so an
     * absent/garbled bound cannot authorise recursion.
     *
     * @param  array<string, mixed>  $proof
     */
    private function maxProvenDepth(array $proof): int
    {
        if (array_key_exists('max_proven_depth', $proof)) {
            return max(0, AreaFocusScalarNormalizer::payloadSaturatingInt($proof, 'max_proven_depth', 0));
        }

        return max(0, AreaFocusScalarNormalizer::payloadSaturatingInt($proof, 'proven_depth', 0));
    }

    /**
     * The request's recursion class, slugged, or '' when none is declared.
     *
     * @param  array<string, mixed>  $request
     */
    private function recursionClass(array $request): string
    {
        foreach (['recursion_class', 'recursion_kind', 'class'] as $key) {
            $value = $request[$key] ?? null;

            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                continue;
            }

            $slug = AreaFocusSlugNormalizer::lowerSnakeToken((string) $value);

            if ($slug !== '') {
                return $slug;
            }
        }

        return '';
    }

    /**
     * The recursion classes the proof envelope actually covers, slugged. Accepts
     * covered_recursion_classes or covered_classes. An empty set means the proof
     * covers nothing, so every class is unknown and blocks.
     *
     * @param  array<string, mixed>  $proof
     * @return list<string>
     */
    private function coveredRecursionClasses(array $proof): array
    {
        $raw = $proof['covered_recursion_classes'] ?? $proof['covered_classes'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $classes = [];
        foreach ($raw as $item) {
            if (! is_string($item) && ! is_int($item) && ! is_float($item)) {
                continue;
            }

            $slug = AreaFocusSlugNormalizer::lowerSnakeToken((string) $item);

            if ($slug !== '' && ! in_array($slug, $classes, true)) {
                $classes[] = $slug;
            }
        }

        return $classes;
    }

    /**
     * True when the proof envelope reports a divergence or metric-gaming signal.
     *
     * @param  array<string, mixed>  $proof
     */
    private function hasDivergenceOrGamingSignal(array $proof): bool
    {
        foreach (['divergence_detected', 'gaming_detected', 'hard_stop_required'] as $key) {
            if (($proof[$key] ?? false) === true) {
                return true;
            }
        }

        return false;
    }
}
