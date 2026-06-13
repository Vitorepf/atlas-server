<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S96 — L7PromotionExecutor (block: L7 Runtime Completion).
 *
 * Applies the L6 -> L7 autonomy promotion ONLY when the built promotion request
 * (atlas.autonomy.promotion_request.v1, produced by L7PromotionRequestBuilder) is
 * validated and carries operator_signature, architect_signature, Trust Ledger
 * score >= 0.95, invariant_breach_count = 0 and a rollback window.
 *
 * Sovereign-guarded: the promotion EFFECT requires operator + architect signatures.
 * Missing any signature yields applied=false with blocker `needs_human_signature`
 * and NO state change (current_level stays at the request's from_level). The Product
 * Mode notification is emitted as an evidence field on the receipt only on
 * applied=true.
 *
 * Pure decision logic: every returned field is computed from the request input via
 * ordered rules. No I/O, clock, randomness, persistence or side effects.
 *
 * @see docs/engineering-knowledge-base/atlas-autonomy-ladder-promotion-runbook.md
 * @see docs/engineering-knowledge-base/atlas-aaeos-l7-convergence-roadmap.md
 */
final class L7PromotionExecutor
{
    private const SCHEMA_VERSION = 'atlas.autonomy.promotion.executed.v1';

    private const FROM_LEVEL = 'L6';

    private const TO_LEVEL = 'L7';

    private const TRUST_THRESHOLD = 0.95;

    private const MAX_INVARIANT_BREACH = 0;

    private const PRODUCT_MODE_NOTIFICATION = 'l7_promotion_applied_operator_notice';

    /**
     * @var list<string>
     */
    private const REQUIRED_SIGNATURES = ['operator_signature', 'architect_signature'];

    /**
     * Execute the L6 -> L7 promotion request.
     *
     * @param  array<string,mixed>  $request
     * @return array{
     *     schema_version: string,
     *     applied: bool,
     *     current_level: string,
     *     required_signatures: list<string>,
     *     blockers: list<string>,
     *     promotion_receipt: array<string,mixed>
     * }
     */
    public function execute(array $request): array
    {
        $fromLevel = $this->levelValue($request, 'from_level', self::FROM_LEVEL);
        $toLevel = $this->levelValue($request, 'to_level', self::TO_LEVEL);

        $operatorSigned = $this->signaturePresent($request, 'operator_signature');
        $architectSigned = $this->signaturePresent($request, 'architect_signature');
        $trustScore = AreaFocusScalarNormalizer::clampUnit($this->floatValue($request, 'trust_ledger_score'));
        $invariantBreachCount = $this->nonNegativeInt($request, 'invariant_breach_count');
        $rollbackWindowSeconds = $this->positiveInt($request, 'rollback_window_seconds');
        $requestValidated = $this->isValidatedRequest($request);

        $blockers = [];

        if (! $requestValidated) {
            $blockers[] = 'invalid_request';
        }

        if ($fromLevel !== self::FROM_LEVEL || $toLevel !== self::TO_LEVEL) {
            $blockers[] = 'not_l6_to_l7';
        }

        if (! $operatorSigned || ! $architectSigned) {
            $blockers[] = 'needs_human_signature';
        }

        if ($trustScore < self::TRUST_THRESHOLD) {
            $blockers[] = 'trust_below_threshold';
        }

        if ($invariantBreachCount > self::MAX_INVARIANT_BREACH) {
            $blockers[] = 'invariant_breach';
        }

        if ($rollbackWindowSeconds === null) {
            $blockers[] = 'rollback_window_missing';
        }

        $applied = $blockers === [];

        // No state change unless applied: current_level stays at from_level (L6).
        $currentLevel = $applied ? $toLevel : $fromLevel;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'applied' => $applied,
            'current_level' => $currentLevel,
            'required_signatures' => self::REQUIRED_SIGNATURES,
            'blockers' => $blockers,
            'promotion_receipt' => $this->buildReceipt(
                $applied,
                $fromLevel,
                $toLevel,
                $currentLevel,
                $operatorSigned,
                $architectSigned,
                $trustScore,
                $invariantBreachCount,
                $rollbackWindowSeconds,
                $blockers,
            ),
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function buildReceipt(
        bool $applied,
        string $fromLevel,
        string $toLevel,
        string $currentLevel,
        bool $operatorSigned,
        bool $architectSigned,
        float $trustScore,
        int $invariantBreachCount,
        ?int $rollbackWindowSeconds,
        array $blockers,
    ): array {
        $receipt = [
            'schema_version' => self::SCHEMA_VERSION,
            'applied' => $applied,
            'from_level' => $fromLevel,
            'to_level' => $toLevel,
            'current_level' => $currentLevel,
            'operator_signature_present' => $operatorSigned,
            'architect_signature_present' => $architectSigned,
            'trust_ledger_score' => $trustScore,
            'invariant_breach_count' => $invariantBreachCount,
            'rollback_window_seconds' => $rollbackWindowSeconds,
            'blockers' => $blockers,
        ];

        if ($applied) {
            $receipt['product_mode_notification'] = self::PRODUCT_MODE_NOTIFICATION;
        }

        return $receipt;
    }

    /**
     * Resolve a level token for the sovereign `not_l6_to_l7` rung guard.
     *
     * A genuinely absent key (or explicit null) defaults so the receipt always
     * names the L6 -> L7 rung. But a key that is PRESENT with a non-string value
     * is a malformed level and must NOT be silently coerced to the expected
     * default: an integer `from_level => 5` carries the concrete meaning "L5"
     * (a wrong source rung) and quietly mapping it to the default `L6` would fail
     * OPEN — promoting from the wrong rung past the guard. Such a present-but-
     * non-string level returns a non-matching sentinel so the guard blocks.
     *
     * @param  array<string,mixed>  $request
     */
    private function levelValue(array $request, string $key, string $default): string
    {
        if (! array_key_exists($key, $request) || $request[$key] === null) {
            return $default;
        }

        $value = $request[$key];

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? $default : strtoupper($trimmed);
        }

        // Present but non-string: malformed level. Fail closed — return a token
        // that cannot equal FROM_LEVEL/TO_LEVEL so the rung guard blocks.
        return '__INVALID_LEVEL__';
    }

    /**
     * A signature slot counts as present only when it is a non-empty string token.
     *
     * @param  array<string,mixed>  $request
     */
    private function signaturePresent(array $request, string $key): bool
    {
        $value = $request[$key] ?? null;

        return is_string($value) && trim($value) !== '';
    }

    /**
     * @param  array<string,mixed>  $request
     */
    private function isValidatedRequest(array $request): bool
    {
        foreach (['request_validated', 'validated'] as $flag) {
            if (($request[$flag] ?? null) === true) {
                return true;
            }
        }

        $status = $request['validation_status'] ?? null;

        return is_string($status) && strtolower(trim($status)) === 'validated';
    }

    /**
     * @param  array<string,mixed>  $request
     */
    private function floatValue(array $request, string $key): float
    {
        $value = $request[$key] ?? 0.0;

        if (is_int($value)) {
            return (float) $value;
        }

        // Non-finite floats (NaN, ±INF) are not a real trust score: NaN slips
        // past both the unit clamp and the `< threshold` gate (every NaN
        // comparison is false), which would fail OPEN and promote on garbage.
        // Fail closed to 0.0 so the sovereign trust gate blocks.
        if (is_float($value) && is_finite($value)) {
            return $value;
        }

        return 0.0;
    }

    /**
     * Resolve the invariant breach count for the sovereign breach gate.
     *
     * Fail-closed, mirroring positiveInt/floatValue: a genuinely absent key means
     * "no breach recorded" (0). A present value is parsed when it is an int, float
     * or numeric string. A present-but-unparseable value is NOT silently read as
     * zero breaches — it counts as a breach (1) so the gate blocks rather than
     * accidentally promoting on malformed input. Negative counts cannot represent
     * real breaches and are floored to 0.
     *
     * @param  array<string,mixed>  $request
     */
    private function nonNegativeInt(array $request, string $key): int
    {
        if (! array_key_exists($key, $request)) {
            return 0;
        }

        $value = $request[$key];

        if (is_int($value)) {
            return $value < 0 ? 0 : $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            $float = (float) $value;

            // A non-finite count (NaN, ±INF) is a present-but-unparseable breach
            // signal, not zero breaches: fail closed to a breach so the gate blocks.
            if (! is_finite($float)) {
                return 1;
            }

            // A huge finite float would WRAP to a negative int on direct cast
            // (and emit a runtime Warning from this pure kernel), then be floored
            // to 0 — silently dropping a breach signal and failing OPEN. Saturate
            // into the representable int range before casting so a large breach
            // count stays large (and blocks) rather than wrapping to "no breach".
            if ($float >= (float) PHP_INT_MAX) {
                return PHP_INT_MAX;
            }

            if ($float <= (float) PHP_INT_MIN) {
                return 0;
            }

            $parsed = (int) $float;

            return $parsed < 0 ? 0 : $parsed;
        }

        // Present but unparseable: fail-closed — treat as a breach.
        return 1;
    }

    /**
     * A rollback window is required: only a strictly positive integer second-count
     * satisfies it. Anything else returns null so the executor blocks.
     *
     * @param  array<string,mixed>  $request
     */
    private function positiveInt(array $request, string $key): ?int
    {
        $value = $request[$key] ?? null;

        if (is_string($value) && is_numeric($value)) {
            $float = (float) $value;

            if (! is_finite($float) || floor($float) !== $float) {
                return null;
            }

            $value = (int) $value;
        }

        if (! is_int($value) || $value <= 0) {
            return null;
        }

        return $value;
    }
}
