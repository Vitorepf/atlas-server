<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\AuditTrail;

use App\Services\Ai\AutonomousEvolution\AuditTrail\AtlasLoopAuditTrailReplayer;
use App\Services\Ai\AutonomousEvolution\AuditTrail\AuditEvent;
use App\Services\Ai\AutonomousEvolution\AuditTrail\ReplayReport;
use App\Services\Ai\AutonomousEvolution\AuditTrail\TimelineWindow;
use Tests\TestCase;

class AtlasLoopAuditTrailReplayerTest extends TestCase
{
    private function event(string $id, string $ts, string $source = 's1', string $kind = 'k', array $refs = [], array $facts = []): AuditEvent
    {
        return new AuditEvent(
            event_id: $id,
            ts_utc: $ts,
            source_ledger: $source,
            kind: $kind,
            refs: $refs,
            facts: $facts,
        );
    }

    private function sourceOf(array $events): callable
    {
        return function (TimelineWindow $w) use ($events): array {
            $out = [];
            foreach ($events as $e) {
                if ($w->contains($e->ts_utc)) {
                    $out[] = $e;
                }
            }

            return $out;
        };
    }

    public function test_t2_before_t1_returns_empty_report_no_errors(): void
    {
        $called = 0;
        $source = function () use (&$called) {
            $called++;

            return [];
        };
        $report = (new AtlasLoopAuditTrailReplayer($source))->replay('2026-06-25T01:00:00Z', '2026-06-25T00:00:00Z');

        self::assertInstanceOf(ReplayReport::class, $report);
        self::assertSame([], $report->chronological_events);
        self::assertSame([], $report->per_source_counts);
        self::assertSame([], $report->causal_chains);
        self::assertSame([], $report->gaps);
        self::assertSame(0, $called, 'event source must not be touched for boundary T2<T1');
    }

    public function test_causal_chain_follows_only_explicit_refs(): void
    {
        // A → B → C via refs; D is a sibling with NO ref — must not be chained.
        $events = [
            $this->event('A', '2026-06-25T00:00:00Z'),
            $this->event('B', '2026-06-25T00:00:01Z', refs: ['A']),
            $this->event('C', '2026-06-25T00:00:02Z', refs: ['B']),
            $this->event('D', '2026-06-25T00:00:03Z'),
        ];
        $report = (new AtlasLoopAuditTrailReplayer($this->sourceOf($events)))
            ->replay('2026-06-25T00:00:00Z', '2026-06-25T00:00:10Z');

        self::assertCount(1, $report->causal_chains, 'D must NOT be a separate chain — it has no descendants');
        self::assertSame('A', $report->causal_chains[0]['root_event_id']);
        self::assertSame(['A', 'B', 'C'], $report->causal_chains[0]['chain']);
    }

    public function test_gap_detection_exact_with_threshold_20(): void
    {
        $events = [
            $this->event('e0', '2026-06-25T00:00:00Z'),
            $this->event('e10', '2026-06-25T00:00:10Z'),
            $this->event('e60', '2026-06-25T00:01:00Z'),
        ];
        $report = (new AtlasLoopAuditTrailReplayer($this->sourceOf($events)))
            ->replay('2026-06-25T00:00:00Z', '2026-06-25T00:02:00Z', null, idleThresholdSeconds: 20);

        self::assertCount(1, $report->gaps);
        self::assertSame('2026-06-25T00:00:10Z', $report->gaps[0]['from_ts_utc']);
        self::assertSame('2026-06-25T00:01:00Z', $report->gaps[0]['to_ts_utc']);
        self::assertSame(50, $report->gaps[0]['idle_seconds']);
    }

    public function test_gap_detection_with_threshold_5_yields_two_gaps(): void
    {
        $events = [
            $this->event('e0', '2026-06-25T00:00:00Z'),
            $this->event('e10', '2026-06-25T00:00:10Z'),
            $this->event('e60', '2026-06-25T00:01:00Z'),
        ];
        $report = (new AtlasLoopAuditTrailReplayer($this->sourceOf($events)))
            ->replay('2026-06-25T00:00:00Z', '2026-06-25T00:02:00Z', null, idleThresholdSeconds: 5);

        self::assertCount(2, $report->gaps);
        self::assertSame(10, $report->gaps[0]['idle_seconds']);
        self::assertSame(50, $report->gaps[1]['idle_seconds']);
    }

    public function test_replayer_is_deterministic_across_two_consecutive_invocations(): void
    {
        $events = [
            $this->event('A', '2026-06-25T00:00:00Z', source: 's1', refs: []),
            $this->event('B', '2026-06-25T00:00:01Z', source: 's2', refs: ['A']),
            $this->event('C', '2026-06-25T00:00:02Z', source: 's1', refs: ['B']),
        ];
        $replayer = new AtlasLoopAuditTrailReplayer($this->sourceOf($events));
        $a = $replayer->replay('2026-06-25T00:00:00Z', '2026-06-25T00:00:10Z');
        $b = $replayer->replay('2026-06-25T00:00:00Z', '2026-06-25T00:00:10Z');

        self::assertSame(serialize($a), serialize($b));
        self::assertSame(['s1' => 2, 's2' => 1], $a->per_source_counts);
    }

    public function test_filter_restricts_events_by_source_and_kind(): void
    {
        $events = [
            $this->event('a', '2026-06-25T00:00:00Z', source: 's1', kind: 'k1'),
            $this->event('b', '2026-06-25T00:00:01Z', source: 's1', kind: 'k2'),
            $this->event('c', '2026-06-25T00:00:02Z', source: 's2', kind: 'k1'),
        ];
        $report = (new AtlasLoopAuditTrailReplayer($this->sourceOf($events)))
            ->replay('2026-06-25T00:00:00Z', '2026-06-25T00:00:10Z', filter: ['kind' => ['k1']]);

        self::assertCount(2, $report->chronological_events);
        self::assertSame(['a', 'c'], array_column($report->chronological_events, 'event_id'));
    }
}
