<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\AuditTrail;

/**
 * Deterministic facts-only replay report.
 *
 * Carries: per-source event counts, causal chains (event B follows A only when B.refs contains
 * A.event_id), gap intervals (idle stretches > idle_threshold seconds) and the chronological list.
 */
final readonly class ReplayReport
{
    public const SCHEMA_VERSION = 'atlas.loop.audit_trail.replay_report.v1';

    /**
     * @param  array<string,int>  $per_source_counts
     * @param  list<array{root_event_id:string, chain:list<string>}>  $causal_chains
     * @param  list<array{from_ts_utc:string, to_ts_utc:string, idle_seconds:int}>  $gaps
     * @param  list<array<string,mixed>>  $chronological_events
     */
    public function __construct(
        public string $schema_version,
        public string $window_from,
        public string $window_to,
        public array $per_source_counts,
        public array $causal_chains,
        public array $gaps,
        public array $chronological_events,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schema_version,
            'window_from' => $this->window_from,
            'window_to' => $this->window_to,
            'per_source_counts' => $this->per_source_counts,
            'causal_chains' => $this->causal_chains,
            'gaps' => $this->gaps,
            'chronological_events' => $this->chronological_events,
        ];
    }
}
