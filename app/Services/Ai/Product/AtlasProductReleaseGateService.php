<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;

class AtlasProductReleaseGateService
{
    public const SCHEMA_VERSION = 'atlas.product_delivery.release_gate.v1';

    public function __construct(
        private readonly AtlasProductDeliveryControlPlaneService $controlPlane = new AtlasProductDeliveryControlPlaneService,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function decide(array $options = []): array
    {
        $snapshot = is_array($options['control_plane'] ?? null)
            ? $options['control_plane']
            : $this->controlPlane->snapshot($options);
        $blockers = $this->blockers($snapshot);
        $allowed = $blockers === [];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $allowed ? 'release_candidate_allowed' : 'blocked',
            'mode' => 'provider_free_read_only',
            'release_candidate_allowed' => $allowed,
            'release_level' => $allowed ? 'candidate' : 'none',
            'control_plane' => [
                'status' => $snapshot['status'] ?? null,
                'hash' => $snapshot['control_plane_hash'] ?? null,
            ],
            'required_green_signals' => [
                'control_plane_healthy',
                'product_delivery_certification_ready',
                'risk_governor_allowed',
                'evidence_replay_ready',
                'runtime_receipts_safe',
                'no_critical_blockers',
            ],
            'signals' => [
                'route' => data_get($snapshot, 'delivery.route'),
                'delivery_status' => data_get($snapshot, 'delivery.status'),
                'proof_status' => data_get($snapshot, 'delivery.proof_status'),
                'risk_status' => data_get($snapshot, 'risk_governor.status'),
                'risk_band' => data_get($snapshot, 'risk_governor.risk_band'),
                'replay_status' => data_get($snapshot, 'replay.status'),
                'unsafe_write_receipt_count' => (int) data_get($snapshot, 'replay.unsafe_write_receipt_count', 0),
                'certification_status' => data_get($snapshot, 'certification.status'),
                'certification_passed' => (int) data_get($snapshot, 'certification.passed', 0),
                'certification_total' => (int) data_get($snapshot, 'certification.total', 0),
                'control_plane_blocker_count' => count($this->list($snapshot['blockers'] ?? [])),
            ],
            'blockers' => $blockers,
            'release_contract' => [
                'may_publish_or_merge' => false,
                'may_create_release_candidate' => $allowed,
                'requires_operator_release_approval' => true,
                'requires_receipt_persistence' => true,
                'requires_post_release_outcome_memory' => true,
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'release_gate_is_not_deployment' => true,
                'external_superiority_claim' => false,
            ],
        ];
        $payload['release_gate_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return list<array<string,mixed>>
     */
    private function blockers(array $snapshot): array
    {
        $blockers = [];
        if (($snapshot['status'] ?? null) !== 'healthy') {
            $blockers[] = ['id' => 'control_plane_not_healthy', 'severity' => 'critical'];
        }
        if (($snapshot['blockers'] ?? []) !== []) {
            $blockers[] = ['id' => 'control_plane_blockers_present', 'severity' => 'critical'];
        }
        if (data_get($snapshot, 'certification.status') !== 'ready') {
            $blockers[] = ['id' => 'product_delivery_certification_not_ready', 'severity' => 'critical'];
        }
        if (data_get($snapshot, 'risk_governor.status') !== 'allowed') {
            $blockers[] = ['id' => 'risk_governor_not_allowed', 'severity' => 'critical'];
        }
        if (data_get($snapshot, 'replay.status') !== 'ready') {
            $blockers[] = ['id' => 'evidence_replay_not_ready', 'severity' => 'critical'];
        }
        if ((int) data_get($snapshot, 'replay.unsafe_write_receipt_count', 0) > 0) {
            $blockers[] = ['id' => 'unsafe_write_receipts_detected', 'severity' => 'critical'];
        }
        if (data_get($snapshot, 'delivery.proof_status') !== 'ready') {
            $blockers[] = ['id' => 'proof_not_ready', 'severity' => 'critical'];
        }

        return array_values(array_unique($blockers, SORT_REGULAR));
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
