<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\AuditTrail;

/**
 * Deterministic FACTS-only audit-trail replayer.
 *
 * Answers "what happened between T1 and T2?" by consuming AtlasLoopAuditTrailComposer::compose()
 * results (or any iterable<AuditEvent> source). Causality is followed STRICTLY via explicit refs[];
 * no causes are invented.
 *
 * Sources are injected via a callable `eventSource(TimelineWindow): iterable<AuditEvent>` so
 * this replayer never depends on the concrete composer class.
 *
 * @phpstan-type EventSource callable(TimelineWindow): iterable<AuditEvent>
 */
final class AtlasLoopAuditTrailReplayer
{
    public const DEFAULT_IDLE_THRESHOLD_SECONDS = 30;

    /** @var callable */
    private $eventSource;

    /**
     * @param  callable(TimelineWindow): iterable<AuditEvent>  $eventSource
     */
    public function __construct(callable $eventSource)
    {
        $this->eventSource = $eventSource;
    }

    /**
     * @param  array{source_ledger?:list<string>, kind?:list<string>}|null  $filter
     */
    public function replay(string $t1, string $t2, ?array $filter = null, int $idleThresholdSeconds = self::DEFAULT_IDLE_THRESHOLD_SECONDS): ReplayReport
    {
        // Boundary: T2 before T1 → empty report, no events touched.
        if (strcmp($t2, $t1) < 0) {
            return new ReplayReport(
                schema_version: ReplayReport::SCHEMA_VERSION,
                window_from: $t1,
                window_to: $t2,
                per_source_counts: [],
                causal_chains: [],
                gaps: [],
                chronological_events: [],
            );
        }

        $window = new TimelineWindow(fromTsUtc: $t1, toTsUtc: $t2);

        $events = [];
        foreach (($this->eventSource)($window) as $event) {
            if (! $event instanceof AuditEvent) {
                continue;
            }
            if (! $this->matchesFilter($event, $filter)) {
                continue;
            }
            $events[] = $event;
        }

        // Stable chronological order: (ts_utc ASC, event_id ASC).
        usort($events, static function (AuditEvent $a, AuditEvent $b): int {
            $cmp = strcmp($a->ts_utc, $b->ts_utc);

            return $cmp !== 0 ? $cmp : strcmp($a->event_id, $b->event_id);
        });

        $perSource = $this->perSourceCounts($events);
        $chains = $this->causalChains($events);
        $gaps = $this->detectGaps($events, $idleThresholdSeconds);

        $chronological = array_map(static fn (AuditEvent $e): array => [
            'event_id' => $e->event_id,
            'ts_utc' => $e->ts_utc,
            'source_ledger' => $e->source_ledger,
            'kind' => $e->kind,
            'refs' => array_values($e->refs),
            'facts' => $e->facts,
        ], $events);

        return new ReplayReport(
            schema_version: ReplayReport::SCHEMA_VERSION,
            window_from: $t1,
            window_to: $t2,
            per_source_counts: $perSource,
            causal_chains: $chains,
            gaps: $gaps,
            chronological_events: $chronological,
        );
    }

    /**
     * @param  array{source_ledger?:list<string>, kind?:list<string>}|null  $filter
     */
    private function matchesFilter(AuditEvent $event, ?array $filter): bool
    {
        if ($filter === null) {
            return true;
        }
        $sources = isset($filter['source_ledger']) ? (array) $filter['source_ledger'] : null;
        if ($sources !== null && $sources !== [] && ! in_array($event->source_ledger, array_map('strval', $sources), true)) {
            return false;
        }
        $kinds = isset($filter['kind']) ? (array) $filter['kind'] : null;
        if ($kinds !== null && $kinds !== [] && ! in_array($event->kind, array_map('strval', $kinds), true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<AuditEvent>  $events
     * @return array<string,int>
     */
    private function perSourceCounts(array $events): array
    {
        $counts = [];
        foreach ($events as $event) {
            $key = $event->source_ledger;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * Builds chains of (root → child → grandchild) by following explicit refs[] ONLY.
     * Each chain starts at a root event (an event not referenced by any other event in window
     * that itself has descendants) and walks children deterministically.
     *
     * @param  list<AuditEvent>  $events
     * @return list<array{root_event_id:string, chain:list<string>}>
     */
    private function causalChains(array $events): array
    {
        $byId = [];
        foreach ($events as $event) {
            $byId[$event->event_id] = $event;
        }
        // child = event whose refs contains a known parent id; parent = id present in refs of someone.
        $childrenOf = [];
        $isChild = [];
        foreach ($events as $event) {
            foreach ($event->refs as $ref) {
                if (! isset($byId[(string) $ref])) {
                    continue;
                }
                $childrenOf[(string) $ref][] = $event->event_id;
                $isChild[$event->event_id] = true;
            }
        }
        $chains = [];
        foreach ($events as $event) {
            if (isset($isChild[$event->event_id])) {
                continue; // not a root
            }
            if (! isset($childrenOf[$event->event_id])) {
                continue; // root with no descendants: not a chain
            }
            $chain = [$event->event_id];
            $this->walkChildren($event->event_id, $childrenOf, $chain);
            $chains[] = ['root_event_id' => $event->event_id, 'chain' => $chain];
        }
        usort($chains, static fn (array $a, array $b): int => strcmp($a['root_event_id'], $b['root_event_id']));

        return $chains;
    }

    /**
     * @param  array<string,list<string>>  $childrenOf
     * @param  list<string>  $chain
     */
    private function walkChildren(string $eventId, array $childrenOf, array &$chain): void
    {
        $children = $childrenOf[$eventId] ?? [];
        sort($children);
        foreach ($children as $childId) {
            if (in_array($childId, $chain, true)) {
                continue; // guard against cycles
            }
            $chain[] = $childId;
            $this->walkChildren($childId, $childrenOf, $chain);
        }
    }

    /**
     * Gaps are idle stretches between consecutive events with delta > $idleThresholdSeconds.
     *
     * @param  list<AuditEvent>  $events
     * @return list<array{from_ts_utc:string, to_ts_utc:string, idle_seconds:int}>
     */
    private function detectGaps(array $events, int $idleThresholdSeconds): array
    {
        if (count($events) < 2 || $idleThresholdSeconds <= 0) {
            return [];
        }
        $gaps = [];
        $previous = null;
        foreach ($events as $event) {
            if ($previous !== null) {
                $delta = $this->secondsBetween($previous->ts_utc, $event->ts_utc);
                if ($delta > $idleThresholdSeconds) {
                    $gaps[] = [
                        'from_ts_utc' => $previous->ts_utc,
                        'to_ts_utc' => $event->ts_utc,
                        'idle_seconds' => $delta,
                    ];
                }
            }
            $previous = $event;
        }

        return $gaps;
    }

    private function secondsBetween(string $a, string $b): int
    {
        $tsA = strtotime($a);
        $tsB = strtotime($b);
        if ($tsA === false || $tsB === false) {
            return 0;
        }

        return (int) ($tsB - $tsA);
    }
}
