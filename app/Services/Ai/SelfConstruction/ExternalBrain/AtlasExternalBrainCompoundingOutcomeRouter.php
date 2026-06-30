<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure router. Classifies a completed muscle outcome into a compounding bucket.
 *
 * Buckets (from strongest to weakest signal):
 *   unlocks_new_capability — new capability with wired callers AND integration proof.
 *   compounds_existing     — extends existing capability with downstream proof.
 *   low_compounding        — cosmetic wrappers, schema-only counters, unproven claims (AC3).
 *   maintenance            — doc updates, formatting, comment-only changes.
 *
 * AC2 rule: compounding buckets require EXPLICIT downstream evidence:
 *   - integration: / e2e: / wired_callers: / downstream: prefix in evidence_refs, OR
 *   - wired_callers_count > 0, OR
 *   - downstream_services_enabled > 0.
 *   Without any of these → low_compounding regardless of claimed impact.
 *
 * AC3 rule: → low_compounding when:
 *   - is_cosmetic === true
 *   - outcome_type in COSMETIC_TYPES (cosmetic_wrapper, naming_refactor, style_fix)
 *   - outcome_type === schema_change AND wired_callers_count === 0
 *   - No downstream evidence (unit tests only, no integration refs)
 *
 * Priority: maintenance → cosmetic → schema-only → no-downstream-proof → compounding tiers.
 *
 * NO process execution, NO filesystem, NO providers. DETERMINISTIC.
 */
final class AtlasExternalBrainCompoundingOutcomeRouter
{
    public const SCHEMA = 'atlas.external_brain.compounding_outcome_router.v1';

    public const BUCKET_UNLOCKS_NEW  = 'unlocks_new_capability';
    public const BUCKET_COMPOUNDS    = 'compounds_existing';
    public const BUCKET_LOW          = 'low_compounding';
    public const BUCKET_MAINTENANCE  = 'maintenance';

    private const MAINTENANCE_TYPES = ['doc_update', 'documentation', 'formatting', 'whitespace', 'comment'];

    private const COSMETIC_TYPES    = ['cosmetic_wrapper', 'naming_refactor', 'style_fix'];

    private const NEW_CAPABILITY_TYPES = ['new_service', 'new_capability', 'new_organ'];

    private const INTEGRATION_PREFIXES = ['integration:', 'e2e:', 'wired_callers:', 'downstream:'];

    /**
     * @param  array<string,mixed>  $outcome
     * @return array<string,mixed>
     */
    public function route(array $outcome): array
    {
        $isCosmetic         = (bool) ($outcome['is_cosmetic'] ?? false);
        $outcomeType        = strtolower(trim((string) ($outcome['outcome_type'] ?? '')));
        $evidenceRefs       = array_values(array_map('strval', (array) ($outcome['evidence_refs'] ?? [])));
        $wiredCallers       = max(0, (int) ($outcome['wired_callers_count'] ?? 0));
        $downstreamServices = max(0, (int) ($outcome['downstream_services_enabled'] ?? 0));

        $hasIntegrationEvidence = $this->hasIntegrationEvidence($evidenceRefs);
        $hasDownstreamProof     = $hasIntegrationEvidence || $wiredCallers > 0 || $downstreamServices > 0;
        $isNewCapability        = in_array($outcomeType, self::NEW_CAPABILITY_TYPES, true);

        // 1. Maintenance.
        if (in_array($outcomeType, self::MAINTENANCE_TYPES, true)) {
            return $this->result(self::BUCKET_MAINTENANCE, 0.05, 'outcome_type_is_maintenance', false, $hasDownstreamProof);
        }

        // 2. Cosmetic wrapper or cosmetic type (AC3).
        if ($isCosmetic || in_array($outcomeType, self::COSMETIC_TYPES, true)) {
            return $this->result(self::BUCKET_LOW, 0.1, 'cosmetic_outcome', false, false);
        }

        // 3. Schema-only without wired callers (AC3).
        if ($outcomeType === 'schema_change' && $wiredCallers === 0) {
            return $this->result(self::BUCKET_LOW, 0.15, 'schema_only_no_wired_callers', true, false);
        }

        // 4. No downstream proof → unproven claim (AC2 + AC3).
        if (! $hasDownstreamProof) {
            return $this->result(self::BUCKET_LOW, 0.2, 'no_downstream_evidence_or_wired_callers', true, false);
        }

        // 5. Has downstream proof → compounding tiers (AC2 satisfied).
        if ($isNewCapability && $wiredCallers > 0) {
            return $this->result(self::BUCKET_UNLOCKS_NEW, 0.95, 'new_capability_with_wired_callers_and_downstream_proof', false, true);
        }

        return $this->result(self::BUCKET_COMPOUNDS, 0.7, 'downstream_evidence_confirms_compounding', false, true);
    }

    /**
     * @return array<string,mixed>
     */
    private function result(
        string $bucket, float $score, string $reason,
        bool $requiresDownstreamProof, bool $hasDownstreamProof,
    ): array {
        return [
            'schema_version'           => self::SCHEMA,
            'bucket'                   => $bucket,
            'compounding_score'        => $score,
            'classification_reason'    => $reason,
            'requires_downstream_proof' => $requiresDownstreamProof,
            'has_downstream_proof'     => $hasDownstreamProof,
        ];
    }

    private function hasIntegrationEvidence(array $refs): bool
    {
        foreach ($refs as $ref) {
            foreach (self::INTEGRATION_PREFIXES as $prefix) {
                if (str_starts_with($ref, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
