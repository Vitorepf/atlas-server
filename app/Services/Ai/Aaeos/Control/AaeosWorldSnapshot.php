<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

/**
 * Immutable world inputs for admission (built by I/O, consumed by pure policy).
 */
final class AaeosWorldSnapshot
{
    public const SCHEMA = 'atlas.aaeos.world_snapshot.v1';

    /**
     * @param  array<string,mixed>  $extra
     */
    public function __construct(
        public readonly bool $incidentOpen = false,
        public readonly int $queueDepth = 0,
        public readonly float $budgetPressure = 0.0,
        public readonly int $recentFailureCount = 0,
        public readonly array $extra = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'schema' => self::SCHEMA,
            'incident_open' => $this->incidentOpen,
            'queue_depth' => $this->queueDepth,
            'budget_pressure' => $this->budgetPressure,
            'recent_failure_count' => $this->recentFailureCount,
        ], $this->extra);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            incidentOpen: (bool) ($data['incident_open'] ?? false),
            queueDepth: max(0, (int) ($data['queue_depth'] ?? 0)),
            budgetPressure: max(0.0, min(1.0, (float) ($data['budget_pressure'] ?? 0.0))),
            recentFailureCount: max(0, (int) ($data['recent_failure_count'] ?? 0)),
            extra: array_diff_key($data, array_flip([
                'schema', 'incident_open', 'queue_depth', 'budget_pressure', 'recent_failure_count',
            ])),
        );
    }
}
