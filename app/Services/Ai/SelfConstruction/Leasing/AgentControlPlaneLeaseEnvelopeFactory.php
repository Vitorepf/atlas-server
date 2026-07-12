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

    public const EVENT_ACQUIRED   = 'acquired';
    public const EVENT_RENEWED    = 'renewed';
    public const EVENT_RELEASED   = 'released';
    public const EVENT_EXPIRED    = 'expired';
    public const EVENT_CONTENTION = 'contention';
    public const EVENT_ERROR      = 'error';

    private const CANONICAL_EVENTS = [
        self::EVENT_ACQUIRED,
        self::EVENT_RENEWED,
        self::EVENT_RELEASED,
        self::EVENT_EXPIRED,
        self::EVENT_CONTENTION,
        self::EVENT_ERROR,
    ];

    /** Events that necessarily refer to an existing lease — contention/error may fire before one exists. */
    private const EVENTS_REQUIRING_LEASE_ID = [
        self::EVENT_ACQUIRED,
        self::EVENT_RENEWED,
        self::EVENT_RELEASED,
        self::EVENT_EXPIRED,
    ];

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
            'lease_integrity_hash' => $this->computeLeaseIntegrityHash($lease),
            'authority_nonce' => (string) ($lease['authority_nonce'] ?? ''),
            'authority_revoked' => (bool) ($lease['authority_revoked'] ?? false),
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
        ], $extra);
    }

    private function computeLeaseIntegrityHash(array $lease): string
    {
        $allowedFiles = array_values(array_map('strval', (array) ($lease['allowed_files'] ?? [])));
        sort($allowedFiles);
        $payload = [
            'allowed_files_hash' => hash('sha256', (string) json_encode($allowedFiles, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'client_id' => (string) ($lease['client_id'] ?? ''),
            'expires_at_unix' => (int) ($lease['expires_at_unix'] ?? 0),
            'authority_nonce' => (string) ($lease['authority_nonce'] ?? ''),
            'authority_revoked' => (bool) ($lease['authority_revoked'] ?? false),
            'lease_id' => (string) ($lease['lease_id'] ?? ''),
            'task_packet_id' => (string) ($lease['task_packet_id'] ?? ''),
        ];

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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

    /**
     * Canonical receipt for one of the six lease lifecycle events (acquired, renewed, released,
     * expired, contention, error). Built on top of {@see self::buildReceipt} — inherits its
     * (kind, data) hashing contract verbatim — but GUARANTEES task_packet_id, agent_id, lease_id,
     * event, and reason are always present, instead of leaving them to the caller's $data.
     *
     * lease_id may be omitted ('') only for contention/error, since those can fire before any
     * lease exists; every other event requires it.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException  when the event is not canonical, or a required identity
     *                                     field (task_packet_id, agent_id, or lease_id where required) is missing.
     */
    public function buildLeaseEventReceipt(
        string $event,
        string $taskPacketId,
        string $agentId,
        string $leaseId = '',
        string $reason = '',
        array $extra = [],
    ): array {
        if (! in_array($event, self::CANONICAL_EVENTS, true)) {
            throw new \InvalidArgumentException("Unknown lease event kind: {$event}");
        }
        if ($taskPacketId === '') {
            throw new \InvalidArgumentException('task_packet_id is required to build a lease event receipt');
        }
        if ($agentId === '') {
            throw new \InvalidArgumentException('agent_id is required to build a lease event receipt');
        }
        if ($leaseId === '' && in_array($event, self::EVENTS_REQUIRING_LEASE_ID, true)) {
            throw new \InvalidArgumentException("lease_id is required for the '{$event}' lease event");
        }

        $data = array_merge([
            'task_packet_id' => $taskPacketId,
            'agent_id' => $agentId,
            'lease_id' => $leaseId,
            'event' => $event,
            'reason' => $reason,
        ], $extra);

        return $this->buildReceipt("lease_{$event}", $data);
    }
}
