<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\QualityFoundry;

use App\Services\Ai\EngineeringKernel\Quality\QualityFoundryTemporalProjectionRebuilder;
use App\Services\Ai\Rivals\Core\WorldTrialReadiness;

/**
 * Converts independent runtime facts into the three facts required by the
 * Quality Foundry completion gate. It never promotes a receipt, accepts a
 * synthetic campaign, or fills in an absent temporal observation.
 */
final class QualityFoundryOperationalEvidenceGate
{
    /** @return array<string,mixed> */
    public function evaluate(array $input): array
    {
        $operational = $this->operational($input['operational_evidence'] ?? null);
        $temporal = $this->temporal($input['temporal_projection'] ?? null);
        $rivals = $this->rivals($input['rivals_trial'] ?? null);

        $blockers = array_values(array_unique([
            ...$operational['blockers'],
            ...$temporal['blockers'],
            ...$rivals['blockers'],
        ]));

        return [
            'status' => $blockers === [] ? 'attested' : 'blocked',
            'operational_evidence_attested' => $operational['attested'],
            'temporal_outcomes_complete' => $temporal['complete'],
            'rivals_claim_eligible' => $rivals['eligible'],
            'blockers' => $blockers,
            'evidence' => [
                'operational' => $operational,
                'temporal' => $temporal,
                'rivals' => $rivals,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function operational(mixed $value): array
    {
        $evidence = is_array($value) ? $value : [];
        $blockers = [];
        if (($evidence['source'] ?? null) !== 'production_runtime_receipt') {
            $blockers[] = 'operational_runtime_receipt_required';
        }
        if (($evidence['status'] ?? null) !== 'attested') {
            $blockers[] = 'operational_evidence_not_attested';
        }
        if (($evidence['production_exposure'] ?? false) !== true) {
            $blockers[] = 'production_exposure_not_proven';
        }
        if (($evidence['synthetic'] ?? true) === true) {
            $blockers[] = 'operational_evidence_synthetic';
        }
        if (! is_string($evidence['receipt_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $evidence['receipt_hash']) !== 1) {
            $blockers[] = 'operational_receipt_hash_required';
        }

        return [
            'attested' => $blockers === [],
            'blockers' => $blockers,
            'receipt_hash' => $evidence['receipt_hash'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function temporal(mixed $value): array
    {
        $projection = is_array($value) ? $value : [];
        $blockers = array_values(array_map('strval', (array) ($projection['blockers'] ?? [])));
        if (($projection['schema'] ?? null) !== QualityFoundryTemporalProjectionRebuilder::SCHEMA) {
            $blockers[] = 'temporal_projection_schema_required';
        }
        if (($projection['temporal_state'] ?? null) !== 'complete') {
            $blockers[] = 'temporal_outcomes_not_elapsed';
        }
        foreach (['0h', '24h', '7d', '30d', '90d', '150d'] as $window) {
            if (($projection['windows'][$window]['state'] ?? null) !== 'observed') {
                $blockers[] = 'temporal_window_not_observed:'.$window;
            }
        }

        return [
            'complete' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'projection_hash' => $projection['projection_hash'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function rivals(mixed $value): array
    {
        $manifest = is_array($value) ? $value : [];
        $readiness = (new WorldTrialReadiness)->evaluate($manifest);
        $blockers = array_values(array_map('strval', (array) ($readiness['blockers'] ?? [])));
        if (($readiness['eligible_claim'] ?? false) !== true) {
            $blockers[] = 'rivals_claim_not_eligible';
        }

        return [
            'eligible' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'readiness' => $readiness,
        ];
    }
}
