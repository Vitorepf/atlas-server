<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution;

/**
 * FACT-only report from {@see AtlasLoopAutopoieticConstitutionDriftDetector::detect()}: did anything edit the
 * constitutional registry source between two anchored points without a corresponding operator receipt? The
 * detector emits this report and never auto-heals — the operator (or a downstream audit pipeline) decides.
 *
 * NO score, NO ranking, NO percentage. The truth is binary (`drifted`) plus the evidence required to act
 * (both fingerprints, the last receipt id, and an optional diff_summary).
 */
final class DriftReport
{
    /**
     * @param  array<string,mixed>  $diffSummary  optional structured summary describing the divergence
     */
    public function __construct(
        public readonly bool $drifted,
        public readonly string $currentFingerprint,
        public readonly ?string $lastKnownFingerprint,
        public readonly ?string $lastOperatorReceiptId,
        public readonly ?string $lastVerifiedAt,
        public readonly array $diffSummary = [],
    ) {
    }

    /**
     * @return array{drifted:bool, current_fingerprint:string, last_known_fingerprint:?string, last_operator_receipt_id:?string, last_verified_at:?string, diff_summary:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'drifted' => $this->drifted,
            'current_fingerprint' => $this->currentFingerprint,
            'last_known_fingerprint' => $this->lastKnownFingerprint,
            'last_operator_receipt_id' => $this->lastOperatorReceiptId,
            'last_verified_at' => $this->lastVerifiedAt,
            'diff_summary' => $this->diffSummary,
        ];
    }
}
