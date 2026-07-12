<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\EngineeringKernel\KernelEvidenceAuthority;

/**
 * MULTV-10 — the enforce SEAM the ASI-10 flip is designed to LIGHT UP.
 *
 * Today `atlas:land` runs ZERO tests by construction and the AVCEL layer is in
 * shadow. This gate is the default-OFF check that, once the ASI-10 flag flips
 * (owned exclusively by the operator via ELEV-26s), requires every AUTONOMOUS
 * land to carry a sealed verification receipt (produced by MULTV-01) whose
 * derived tier meets or exceeds the risk-tier required for the change (MULTV-02).
 *
 * This SLICE NEVER FLIPS — it only builds the seam. The one flip belongs to
 * ASI-10 alone (1 flip per family per window; ROL-01 rollback trigger pre-declared).
 *
 * Pétreas of the seam:
 *   - Operator port intact: `atlas:land` (commitAuthority='operator') is EXEMPT.
 *     The gate refuses to touch the operator path even when the flag is ON.
 *   - Default-OFF: when the flag is OFF, the gate is a fail-open no-op —
 *     every commit path is byte-identical to before.
 *   - Sealed receipts only: an autonomous land under flag ON MUST carry a
 *     receipt whose `evidence_bundle.authority` verifies against
 *     {@see KernelEvidenceAuthority::verifyOutcome}. Unsealed = refuse.
 *   - Tier ≥ required: the receipt declares `tier` (T1/T2/T3); the required tier
 *     comes from the change context (MULTV-02) or, until MULTV-02 lands, from
 *     `atlas.multv.autonomous_land_verification_required_tier` (default T1).
 *   - Mechanical reasons only: `verification_receipt_missing`,
 *     `verification_receipt_seal_invalid`, `verification_receipt_tier_below_risk`.
 *
 * Anti-inatividade herdada do ASI-10 (aceite pós-flip): ≥1 bloqueio real OU zero
 * bloqueios com ≥N landings verificados passando (denominador exposto). This
 * class does not compute those metrics — it only produces the mechanical
 * verdict that the digest counts.
 */
final class AtlasAutonomousLandVerificationGate
{
    public const REASON_MISSING = 'verification_receipt_missing';

    public const REASON_SEAL_INVALID = 'verification_receipt_seal_invalid';

    public const REASON_TIER_BELOW_RISK = 'verification_receipt_tier_below_risk';

    private const TIER_RANK = ['T1' => 1, 'T2' => 2, 'T3' => 3];

    public function __construct(
        private readonly ?KernelEvidenceAuthority $authority = null,
    ) {}

    /**
     * Read the ASI-10 promotion flag. The seam engages only when this flag is ON.
     * NEVER flipped by this slice — the flip belongs to ASI-10 alone via ELEV-26s.
     */
    public function isEngaged(): bool
    {
        return (bool) config('atlas.multv.autonomous_land_verification_enabled', false);
    }

    /**
     * Evaluate the verification receipt for an autonomous land. Returns:
     *   ['allowed'=>true]                                — proceed
     *   ['allowed'=>false, 'reason'=>REASON_*, ...]      — refuse with mechanical cause
     *
     * @param  string  $commitAuthority  'autonomous' | 'operator'
     * @param  array<string,mixed>|null  $verification  the same verification payload the committer already receives
     * @return array{allowed:bool, reason?:string, receipt_tier?:string, required_tier?:string}
     */
    public function evaluate(string $commitAuthority, ?array $verification): array
    {
        // Operator port intact — pétreo. The operator hand-drives; this gate never
        // touches that path even when the ASI-10 flag is ON.
        if ($commitAuthority === 'operator') {
            return ['allowed' => true];
        }
        // Default-OFF: no seam engaged, byte-identical to legacy path.
        if (! $this->isEngaged()) {
            return ['allowed' => true];
        }

        $receipt = is_array($verification) ? ($verification['verification_receipt'] ?? null) : null;
        if (! is_array($receipt) || $receipt === []) {
            return [
                'allowed' => false,
                'reason' => self::REASON_MISSING,
                'required_tier' => $this->requiredTier(),
            ];
        }

        $authority = $this->authority ?? app(KernelEvidenceAuthority::class);
        if (! $authority->verifyOutcome($receipt)) {
            return [
                'allowed' => false,
                'reason' => self::REASON_SEAL_INVALID,
                'required_tier' => $this->requiredTier(),
            ];
        }

        $receiptTier = $this->normalizeTier($receipt['tier'] ?? null);
        $required = $this->requiredTier();
        if ($this->rank($receiptTier) < $this->rank($required)) {
            return [
                'allowed' => false,
                'reason' => self::REASON_TIER_BELOW_RISK,
                'receipt_tier' => $receiptTier,
                'required_tier' => $required,
            ];
        }

        return [
            'allowed' => true,
            'receipt_tier' => $receiptTier,
            'required_tier' => $required,
        ];
    }

    private function requiredTier(): string
    {
        return $this->normalizeTier(config('atlas.multv.autonomous_land_verification_required_tier', 'T1'));
    }

    private function normalizeTier(mixed $tier): string
    {
        if (! is_string($tier)) {
            return 'T1';
        }
        $upper = strtoupper(trim($tier));

        return isset(self::TIER_RANK[$upper]) ? $upper : 'T1';
    }

    private function rank(string $tier): int
    {
        return self::TIER_RANK[$tier] ?? 1;
    }
}
