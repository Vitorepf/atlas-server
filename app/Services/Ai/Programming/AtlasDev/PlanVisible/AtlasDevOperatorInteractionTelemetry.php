<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\PlanVisible;

use InvalidArgumentException;

/**
 * Provider-free aggregation of operator interaction events.
 *
 * These measurements describe the experience around a plan. They are never
 * used as a quality, routing, release, or autonomy signal.
 */
final class AtlasDevOperatorInteractionTelemetry
{
    public const SCHEMA_VERSION = 'atlas.dev.operator_interaction_telemetry.v1';

    /** @var list<string> */
    private const COUNTED_KINDS = ['question', 'override', 'cancellation', 'handoff'];

    /**
     * @param  list<array<string,mixed>>  $events
     * @return array{schema_version:string,status:string,counts:array{questions:int,overrides:int,cancellations:int,handoffs:int},active_minutes:float,event_count:int}
     */
    public function aggregate(array $events): array
    {
        $counts = [
            'questions' => 0,
            'overrides' => 0,
            'cancellations' => 0,
            'handoffs' => 0,
        ];
        $activeMinutes = 0.0;
        $acceptedEvents = 0;

        foreach ($events as $index => $event) {
            if (! is_array($event)) {
                throw new InvalidArgumentException("operator_interaction_events[{$index}] must be an array.");
            }

            $kind = trim((string) ($event['kind'] ?? ''));
            if ($kind !== '' && in_array($kind, self::COUNTED_KINDS, true)) {
                $counts[$kind.'s']++;
                $acceptedEvents++;
            } elseif ($kind === 'active_time') {
                $minutes = $event['minutes'] ?? null;
                if (! is_numeric($minutes) || (float) $minutes < 0) {
                    throw new InvalidArgumentException("operator_interaction_events[{$index}].minutes_invalid");
                }
                $activeMinutes += (float) $minutes;
                $acceptedEvents++;
            } else {
                throw new InvalidArgumentException("operator_interaction_events[{$index}].kind_invalid");
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $acceptedEvents === 0 ? 'pending_data' : 'ok',
            'counts' => $counts,
            'active_minutes' => round($activeMinutes, 4),
            'event_count' => $acceptedEvents,
        ];
    }
}
