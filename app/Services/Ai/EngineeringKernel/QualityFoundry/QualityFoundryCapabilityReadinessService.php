<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\QualityFoundry;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;

/**
 * Read-only rollout projection for the Software Twin and capability market.
 * It can authorize only bounded sandbox stages; comparative claims belong to Rivals.
 */
final class QualityFoundryCapabilityReadinessService
{
    public const SCHEMA = 'atlas.quality_foundry.capability_readiness.v1';

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function inspect(array $input): array
    {
        $requiredFamilies = array_values(array_unique(array_map('strval', (array) ($input['required_fact_families'] ?? []))));
        $factFamilies = array_values(array_unique(array_map('strval', (array) ($input['fact_families'] ?? []))));
        $missingFamilies = array_values(array_diff($requiredFamilies, $factFamilies));
        $unknownCritical = array_values(array_filter((array) ($input['unknown_critical_facts'] ?? []), static fn (mixed $fact): bool => is_string($fact) && trim($fact) !== ''));

        $checks = [
            'fact_family_coverage' => $missingFamilies === [],
            'workspace_isolation' => ($input['workspace_isolated'] ?? false) === true,
            'temporal_freshness' => ($input['temporal_freshness'] ?? false) === true,
            'calibration' => is_array($input['calibration'] ?? null)
                && ($input['calibration']['status'] ?? null) === 'calibrated'
                && ($input['calibration']['claim_eligible'] ?? false) === false,
            'snapshot_determinism' => ($input['snapshot_deterministic'] ?? false) === true,
            'no_unknown_critical_fact' => $unknownCritical === [],
            'quality_first_selection' => ($input['quality_first_selection'] ?? false) === true,
            'route_replay' => ($input['route_replay'] ?? false) === true,
            'no_claim_writer' => ($input['no_claim_writer'] ?? false) === true,
        ];

        $blockers = [];
        if (! $checks['fact_family_coverage']) $blockers[] = 'fact_family_coverage_missing';
        if (! $checks['workspace_isolation']) $blockers[] = 'workspace_isolation_invalid';
        if (! $checks['temporal_freshness']) $blockers[] = 'temporal_freshness_invalid';
        if (! $checks['calibration']) $blockers[] = 'calibration_invalid';
        if (! $checks['snapshot_determinism']) $blockers[] = 'snapshot_determinism_invalid';
        if (! $checks['no_unknown_critical_fact']) $blockers[] = 'unknown_critical_facts';
        if (! $checks['quality_first_selection']) $blockers[] = 'quality_first_selection_invalid';
        if (! $checks['route_replay']) $blockers[] = 'route_replay_invalid';
        if (! $checks['no_claim_writer']) $blockers[] = 'claim_writer_present';

        $ready = $blockers === [];
        $payload = [
            'schema' => self::SCHEMA,
            'status' => $ready ? 'ready_for_limited_sandbox' : 'blocked',
            'checks' => $checks,
            'blockers' => array_values(array_unique($blockers)),
            'unresolved_unknowns' => array_values(array_unique(array_merge($missingFamilies, $unknownCritical))),
            'rollout_stage' => $ready ? 'limited_sandbox' : 'read_only_snapshot',
            'promotion_allowed' => $ready,
            'rollout_sequence' => ['read_only_snapshot', 'market_shadow', 'limited_sandbox', 'governed_mode_enablement'],
            'governed_mode_enablement_allowed' => $ready && ($input['mode_parity'] ?? false) === true,
            'comparative_state' => 'world_10x_quality_proof_pending',
            'multiplier_proven' => false,
            'world_leading' => false,
            'world_10x_quality_proven' => false,
            'claim_eligible' => false,
            'rivals_evidence_complete' => ($input['rivals_evidence_complete'] ?? false) === true,
            'verification_refs' => array_values((array) ($input['verification_refs'] ?? [])),
        ];
        $payload['readiness_hash'] = CanonicalKernelPayload::hash($payload);

        return $payload;
    }
}
