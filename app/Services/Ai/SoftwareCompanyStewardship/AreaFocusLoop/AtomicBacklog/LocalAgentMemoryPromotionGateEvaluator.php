<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

/**
 * Pure gate for promoting local-agent ingestion candidates into durable memory.
 *
 * It inspects eligibility, classification, lineage, confidence and secret-scan
 * signals carried on the candidate payload and returns a deterministic verdict.
 * It performs no persistence, no memory write and never invokes a secret scanner;
 * the secret-scan outcome is consumed as an already-computed signal.
 */
final class LocalAgentMemoryPromotionGateEvaluator
{
    private const SCHEMA_VERSION = 'atlas.local_agent.memory_promotion_gate.v1';

    private const CONFIDENCE_THRESHOLD = 0.70;

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function evaluate(array $candidate): array
    {
        $blockers = [];
        $requiredEvidence = [];

        if (! $this->isMemoryEligible($candidate)) {
            $blockers[] = 'memory_not_eligible';
            $requiredEvidence[] = 'memory_eligibility_proof';
        }

        if (! $this->hasClassification($candidate)) {
            $blockers[] = 'classification_missing';
            $requiredEvidence[] = 'classification_label';
        }

        if (! $this->hasLineage($candidate)) {
            $blockers[] = 'lineage_missing';
            $requiredEvidence[] = 'lineage_source_reference';
        }

        $confidence = $this->confidence($candidate);
        $deficit = $this->confidenceDeficit($confidence);

        if ($deficit > 0.0) {
            $blockers[] = 'confidence_below_threshold';
            $requiredEvidence[] = 'additional_corroborating_evidence';
        }

        if (! $this->secretScanPassed($candidate)) {
            $blockers[] = 'secret_scan_failed';
            $requiredEvidence[] = 'clean_secret_scan';
        }

        $promoteAllowed = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $promoteAllowed ? 'promote' : 'block',
            'promote_allowed' => $promoteAllowed,
            'blockers' => $blockers,
            'confidence' => $confidence,
            'confidence_threshold' => self::CONFIDENCE_THRESHOLD,
            'confidence_deficit' => $deficit,
            'required_evidence' => $requiredEvidence,
        ];
    }

    /** @param array<string,mixed> $candidate */
    private function isMemoryEligible(array $candidate): bool
    {
        return ($candidate['memory_eligible'] ?? false) === true;
    }

    /** @param array<string,mixed> $candidate */
    private function hasClassification(array $candidate): bool
    {
        $classification = $candidate['classification'] ?? null;

        return is_string($classification) && trim($classification) !== '';
    }

    /** @param array<string,mixed> $candidate */
    private function hasLineage(array $candidate): bool
    {
        $lineage = $candidate['lineage'] ?? null;

        if (is_string($lineage)) {
            return trim($lineage) !== '';
        }

        if (is_array($lineage)) {
            return $lineage !== [];
        }

        return false;
    }

    /** @param array<string,mixed> $candidate */
    private function secretScanPassed(array $candidate): bool
    {
        $scan = $candidate['secret_scan'] ?? null;

        if (is_array($scan)) {
            return ($scan['passed'] ?? false) === true
                && (int) ($scan['findings'] ?? 0) === 0;
        }

        if (is_string($scan)) {
            return $scan === 'pass';
        }

        return $scan === true;
    }

    /** @param array<string,mixed> $candidate */
    private function confidence(array $candidate): float
    {
        $value = $candidate['confidence'] ?? 0.0;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function confidenceDeficit(float $confidence): float
    {
        $deficit = self::CONFIDENCE_THRESHOLD - $confidence;

        return $deficit > 0.0 ? round($deficit, 4) : 0.0;
    }
}
