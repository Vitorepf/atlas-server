<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;

class AtlasProductDeliveryEnforcementService
{
    public const SCHEMA_VERSION = 'atlas.product_delivery.enforcement.v1';

    /**
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $proof
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $delivery, array $proof = [], array $options = []): array
    {
        $phase = $this->phase($options['phase'] ?? null);
        $highRisk = $this->highRisk($delivery);
        $blockers = [];

        if (($delivery['status'] ?? null) !== 'ready_for_delivery') {
            $blockers[] = $this->blocker('delivery_not_ready', 'critical', 'APDR delivery contract is not ready.');
        }
        if (data_get($delivery, 'aedpds.gate.status') !== 'passed') {
            $blockers[] = $this->blocker('aedpds_gate_not_passed', 'critical', 'AEDPDS gate must pass before product delivery execution.');
        }

        if (($delivery['product_truth']['schema_version'] ?? null) !== AtlasProductTruthCompilerService::SCHEMA_VERSION) {
            $blockers[] = $this->blocker('missing_product_truth_contract', 'critical', 'APTC Product Truth Contract is missing.');
        }

        if ($phase === 'post_execution') {
            if ($highRisk && (($proof['status'] ?? null) !== 'ready')) {
                $blockers[] = $this->blocker('apfpr_not_ready_for_high_risk_delivery', 'critical', 'High-risk delivery needs APFPR ready proof before completion.');
            }
            if (($options['outcome_memory_recorded'] ?? false) !== true) {
                $blockers[] = $this->blocker('missing_product_delivery_outcome_memory', 'critical', 'Product delivery outcome memory must be recorded before completion.');
            }
        }

        $critical = array_values(array_filter(
            $blockers,
            static fn (array $blocker): bool => ($blocker['severity'] ?? 'critical') === 'critical',
        ));

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => $phase,
            'status' => $critical === [] ? 'allowed' : 'blocked',
            'high_risk' => $highRisk,
            'provider_execution_allowed' => $phase === 'pre_provider' && $critical === [],
            'completion_allowed' => $phase === 'post_execution' && $critical === [],
            'blockers' => $blockers,
            'required_next_proof' => $highRisk
                ? ['apfpr_ready', 'outcome_memory_recorded', 'evidence_mapped_to_acceptance']
                : ['focused_tests_or_skip_reason', 'outcome_memory_recorded'],
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'external_superiority_claim' => false,
            ],
        ];
        $payload['enforcement_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    private function phase(mixed $phase): string
    {
        return $phase === 'post_execution' ? 'post_execution' : 'pre_provider';
    }

    /**
     * @param  array<string,mixed>  $delivery
     */
    private function highRisk(array $delivery): bool
    {
        $required = $this->list(data_get($delivery, 'product_truth.execution_lenses.required', []));

        return data_get($delivery, 'proof_requirements.apfpr_required') === true
            || array_intersect($required, ['security_driven', 'performance_driven', 'add']) !== [];
    }

    /**
     * @return array<string,string>
     */
    private function blocker(string $id, string $severity, string $message): array
    {
        return ['id' => $id, 'severity' => $severity, 'message' => $message];
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_scalar($item) && trim((string) $item) !== '' ? trim((string) $item) : null,
            $value,
        )));
    }
}
