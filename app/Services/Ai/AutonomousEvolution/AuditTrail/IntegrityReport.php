<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\AuditTrail;

/**
 * Immutable report of a chain integrity verification pass. Pure data carrier.
 */
final class IntegrityReport
{
    public const ANOMALY_BROKEN_LINK = 'BROKEN_LINK';
    public const ANOMALY_SEQUENCE_GAP = 'SEQUENCE_GAP';
    public const ANOMALY_ORPHAN_REF = 'ORPHAN_REF';
    public const ANOMALY_STALE_CONTENT_HASH = 'STALE_CONTENT_HASH';

    /**
     * @param  list<array<string,mixed>>  $anomalies each entry: {type, source_ledger, event_id, detail, affected_event_ids?}
     */
    public function __construct(public readonly array $anomalies) {}

    public function isIntact(): bool
    {
        return $this->anomalies === [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function ofType(string $type): array
    {
        return array_values(array_filter($this->anomalies, static fn (array $a): bool => (string) ($a['type'] ?? '') === $type));
    }

    public function countOfType(string $type): int
    {
        return count($this->ofType($type));
    }
}
