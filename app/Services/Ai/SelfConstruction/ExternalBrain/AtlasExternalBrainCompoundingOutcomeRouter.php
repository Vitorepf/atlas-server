<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure router. Classifies a completed muscle outcome into a compounding bucket.
 *
 * Buckets (detection order):
 *   regression            — regression_detected=true OR outcome_type=regression
 *   maintenance           — doc, formatting, whitespace, comment types
 *   proxy                 — is_cosmetic=true OR cosmetic_wrapper/naming_refactor/style_fix type
 *   low_compounding       — schema-only no wired callers; unit-only proof; unproven claimed impact
 *   compounds_existing    — downstream proof, not a new-capability type
 *   unlocks_new_capability — new-capability type + wired callers + downstream proof
 *
 * AC2: compounding buckets require EXPLICIT downstream evidence:
 *   integration:/e2e:/wired_callers:/downstream: prefix in evidence_refs,
 *   OR wired_callers_count > 0, OR downstream_services_enabled > 0.
 *   Without any → low_compounding.
 *
 * AC3: proxy for cosmetic; low_compounding for schema-only/unit-only/unproven.
 *
 * Output includes: bucket, compounding_score, classification_reason,
 *   requires_downstream_proof, has_downstream_proof, recommended_followup,
 *   risk_reduction_credit.
 *
 * Deterministic. No I/O. No providers.
 */
final class AtlasExternalBrainCompoundingOutcomeRouter
{
    public const SCHEMA = 'atlas.external_brain.compounding_outcome_router.v1';

    public const BUCKET_UNLOCKS_NEW  = 'unlocks_new_capability';
    public const BUCKET_COMPOUNDS    = 'compounds_existing_capability';
    public const BUCKET_LOW          = 'low_compounding';
    public const BUCKET_PROXY        = 'proxy';
    public const BUCKET_MAINTENANCE  = 'maintenance';
    public const BUCKET_REGRESSION   = 'regression';

    private const MAINTENANCE_TYPES      = ['doc_update', 'documentation', 'formatting', 'whitespace', 'comment'];
    private const COSMETIC_TYPES         = ['cosmetic_wrapper', 'naming_refactor', 'style_fix'];
    private const NEW_CAPABILITY_TYPES   = ['new_service', 'new_capability', 'new_organ'];
    private const INTEGRATION_PREFIXES   = ['integration:', 'e2e:', 'wired_callers:', 'downstream:'];

    private const FOLLOWUP = [
        self::BUCKET_REGRESSION   => 'revert_and_investigate',
        self::BUCKET_PROXY        => 'eliminate_proxy_or_prove_impact',
        self::BUCKET_MAINTENANCE  => 'none_required',
        self::BUCKET_LOW          => 'add_downstream_proof',
        self::BUCKET_COMPOUNDS    => 'measure_compounding_impact',
        self::BUCKET_UNLOCKS_NEW  => 'wire_downstream_consumers',
    ];

    private const RISK_CREDIT = [
        self::BUCKET_REGRESSION   => 0.0,
        self::BUCKET_PROXY        => 0.0,
        self::BUCKET_MAINTENANCE  => 0.1,
        self::BUCKET_LOW          => 0.1,
        self::BUCKET_COMPOUNDS    => 0.5,
        self::BUCKET_UNLOCKS_NEW  => 0.4,
    ];

    private const SCORE = [
        self::BUCKET_REGRESSION   => 0.0,
        self::BUCKET_PROXY        => 0.05,
        self::BUCKET_MAINTENANCE  => 0.10,
        self::BUCKET_LOW          => 0.20,
        self::BUCKET_COMPOUNDS    => 0.70,
        self::BUCKET_UNLOCKS_NEW  => 0.95,
    ];

    /**
     * @param  array<string,mixed>  $outcome
     * @return array<string,mixed>
     */
    public function route(array $outcome): array
    {
        $isCosmetic         = (bool)  ($outcome['is_cosmetic']             ?? false);
        $regressionDetected = (bool)  ($outcome['regression_detected']     ?? false);
        $outcomeType        = strtolower(trim((string) ($outcome['outcome_type'] ?? '')));
        $evidenceRefs       = array_values(array_map('strval', (array) ($outcome['evidence_refs'] ?? [])));
        $wiredCallers       = max(0, (int) ($outcome['wired_callers_count']          ?? 0));
        $downstreamServices = max(0, (int) ($outcome['downstream_services_enabled']   ?? 0));

        $hasIntegrationEvidence = $this->hasIntegrationEvidence($evidenceRefs);
        $hasDownstreamProof     = $hasIntegrationEvidence || $wiredCallers > 0 || $downstreamServices > 0;
        $isNewCapability        = in_array($outcomeType, self::NEW_CAPABILITY_TYPES, true);

        // 1. Regression — highest priority.
        if ($regressionDetected || $outcomeType === 'regression') {
            return $this->result(self::BUCKET_REGRESSION, 'regression_detected', false, false);
        }

        // 2. Maintenance.
        if (in_array($outcomeType, self::MAINTENANCE_TYPES, true)) {
            return $this->result(self::BUCKET_MAINTENANCE, 'outcome_type_is_maintenance', false, $hasDownstreamProof);
        }

        // 3. Proxy — cosmetic flag or cosmetic type (AC3).
        if ($isCosmetic || in_array($outcomeType, self::COSMETIC_TYPES, true)) {
            return $this->result(self::BUCKET_PROXY, 'cosmetic_outcome', false, false);
        }

        // 4. Schema-only without wired callers → low_compounding (AC3).
        if ($outcomeType === 'schema_change' && $wiredCallers === 0) {
            return $this->result(self::BUCKET_LOW, 'schema_only_no_wired_callers', true, false);
        }

        // 5. No downstream proof → unproven claim → low_compounding (AC2 + AC3).
        if (! $hasDownstreamProof) {
            return $this->result(self::BUCKET_LOW, 'no_downstream_evidence_or_wired_callers', true, false);
        }

        // 6. Has downstream proof → compounding tiers (AC2 satisfied).
        if ($isNewCapability && $wiredCallers > 0) {
            return $this->result(self::BUCKET_UNLOCKS_NEW, 'new_capability_with_wired_callers_and_downstream_proof', false, true);
        }

        return $this->result(self::BUCKET_COMPOUNDS, 'downstream_evidence_confirms_compounding', false, true);
    }

    /**
     * @return array<string,mixed>
     */
    private function result(
        string $bucket, string $reason,
        bool $requiresDownstreamProof, bool $hasDownstreamProof,
    ): array {
        return [
            'schema_version'            => self::SCHEMA,
            'bucket'                    => $bucket,
            'compounding_score'         => self::SCORE[$bucket],
            'classification_reason'     => $reason,
            'requires_downstream_proof' => $requiresDownstreamProof,
            'has_downstream_proof'      => $hasDownstreamProof,
            'recommended_followup'      => self::FOLLOWUP[$bucket],
            'risk_reduction_credit'     => self::RISK_CREDIT[$bucket],
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
