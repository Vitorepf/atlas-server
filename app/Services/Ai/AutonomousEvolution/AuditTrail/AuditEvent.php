<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\AuditTrail;

/**
 * Normalized audit event yielded by AtlasLoopAuditTrailComposer. The composer NEVER edits the underlying
 * `facts` map — it is carried through as-is from the source ledger row. Only the envelope (event_id, ts_utc,
 * source_ledger, kind, refs) is supplied by the composer.
 */
final readonly class AuditEvent
{
    /**
     * @param  list<string>  $refs
     * @param  array<string,mixed>  $facts
     */
    public function __construct(
        public string $event_id,
        public string $ts_utc,
        public string $source_ledger,
        public string $kind,
        public array $refs,
        public array $facts,
    ) {}
}
