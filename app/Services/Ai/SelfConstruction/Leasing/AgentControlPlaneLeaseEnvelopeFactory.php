<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Leasing;

use Carbon\CarbonImmutable;

/**
 * ITEM8 — the cohesive pure-formatting concern the claim-lease repository uses to build its
 * envelope / receipt / encode outputs. Five methods migrated verbatim:
 *
 *  - {@see self::buildReceipt}: stamps a receipt with kind + ISO recorded_at + the no-execution
 *    guarantees, merges arbitrary data, then SHA-256 hashes the (kind, data) tuple into a
 *    `receipt_hash` for audit traceability.
 *  - {@see self::envelopeOk}: the success envelope (status='ok', the lease, runtime guarantees).
 *  - {@see self::envelopeError}: the blocked envelope (status='blocked', reason, task_packet_id +
 *    agent_id, runtime guarantees) for non-mutating refusal paths.
 *  - {@see self::lockContentionEnvelope}: the FAIL-CLOSED result when the exclusive flock cannot
 *    be acquired (no lease written, no dispatch, no ledger write).
 *  - {@see self::encode}: pretty canonical JSON (used by the receipt / registry artefacts).
 *
 * Pure / stateless / zero Laravel surface (CarbonImmutable for the ISO timestamp; the rest is pure
 * array + json). The SCHEMA_VERSION constant is the same string the lease repo used so the byte-
 * identical envelope contract survives the split.
 */
class AgentControlPlaneLeaseEnvelopeFactory
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_claim_lease_runtime.v1';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function buildReceipt(string $kind, array $data): array
    {
        $receipt = array_merge([
            'receipt_kind' => $kind,
            'recorded_at' => CarbonImmutable::now()->toIso8601String(),
            'runtime_execution_allowed' => false,
            'ledger_write_allowed' => false,
        ], $data);
        $receipt['receipt_hash'] = hash('sha256', (string) json_encode(
            ['kind' => $kind, 'data' => $data],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $receipt;
    }

    /**
     * @return array<string, mixed>
     */
    public function lockContentionEnvelope(string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'event' => 'blocked',
            'reason' => $reason,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function encode(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
    }

    /**
     * @param  array<string, mixed>  $lease
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function envelopeOk(string $event, array $lease, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'event' => $event,
            'lease_id' => (string) ($lease['lease_id'] ?? ''),
            'task_packet_id' => (string) ($lease['task_packet_id'] ?? ''),
            'lease_status' => (string) ($lease['lease_status'] ?? ''),
            'lease' => $lease,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function envelopeError(string $reason, string $taskPacketId, string $agentId, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'event' => 'blocked',
            'reason' => $reason,
            'task_packet_id' => $taskPacketId,
            'agent_id' => $agentId,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
        ], $extra);
    }
}
