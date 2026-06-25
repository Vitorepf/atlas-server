<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\AuditTrail;

use App\Services\Ai\AutonomousEvolution\AuditTrail\AtlasLoopAuditTrailComposer;
use App\Services\Ai\AutonomousEvolution\AuditTrail\TimelineWindow;
use Tests\TestCase;

final class AtlasLoopAuditTrailComposerTest extends TestCase
{
    private function w(): TimelineWindow
    {
        return new TimelineWindow('2026-06-24T00:00:00Z', '2026-06-25T00:00:00Z');
    }

    public function test_new_sources_appear_simply_by_registering_them(): void
    {
        $composer = new AtlasLoopAuditTrailComposer;
        $composer->register('attempt_ledger', fn (TimelineWindow $w): array => [
            ['ts_utc' => '2026-06-24T10:00:00Z', 'kind' => 'attempt', 'refs' => ['attempt-1'], 'facts' => ['ok' => true]],
        ]);

        // A brand-new sub-ledger discovered tomorrow:
        $composer->register('shiny_new_fake_ledger', fn (TimelineWindow $w): array => [
            ['ts_utc' => '2026-06-24T11:00:00Z', 'kind' => 'shiny', 'refs' => ['s-1'], 'facts' => ['v' => 42]],
        ]);

        $events = iterator_to_array($composer->compose($this->w()), false);

        $this->assertSame(['attempt_ledger', 'shiny_new_fake_ledger'], $composer->registeredSources());
        $sources = array_map(static fn ($e) => $e->source_ledger, $events);
        $this->assertContains('shiny_new_fake_ledger', $sources);
    }

    public function test_global_ordering_is_ts_then_event_id_with_no_drop_or_duplicate(): void
    {
        $composer = new AtlasLoopAuditTrailComposer;
        $composer->register('A', fn () => [
            ['event_id' => 'A-1', 'ts_utc' => '2026-06-24T10:00:00Z', 'kind' => 'k', 'facts' => ['n' => 1]],
            ['event_id' => 'A-2', 'ts_utc' => '2026-06-24T12:00:00Z', 'kind' => 'k', 'facts' => ['n' => 2]],
        ]);
        $composer->register('B', fn () => [
            ['event_id' => 'B-1', 'ts_utc' => '2026-06-24T11:00:00Z', 'kind' => 'k', 'facts' => ['n' => 3]],
            ['event_id' => 'B-2', 'ts_utc' => '2026-06-24T12:00:00Z', 'kind' => 'k', 'facts' => ['n' => 4]],
        ]);
        $composer->register('C', fn () => [
            ['event_id' => 'C-1', 'ts_utc' => '2026-06-24T09:00:00Z', 'kind' => 'k', 'facts' => ['n' => 5]],
        ]);

        $events = iterator_to_array($composer->compose($this->w()), false);

        $this->assertCount(5, $events);
        $this->assertSame(['C-1', 'A-1', 'B-1', 'A-2', 'B-2'], array_map(static fn ($e) => $e->event_id, $events));
        $tses = array_map(static fn ($e) => $e->ts_utc, $events);
        $sorted = $tses;
        sort($sorted);
        $this->assertSame($sorted, $tses, 'ts_utc must be sorted ASC');
    }

    public function test_facts_pass_through_byte_identical(): void
    {
        $composer = new AtlasLoopAuditTrailComposer;
        $strangeFacts = ['z' => 9, 'a' => 1, 'nested' => ['B' => 2, 'A' => 1]];
        $composer->register('A', fn () => [
            ['ts_utc' => '2026-06-24T10:00:00Z', 'kind' => 'k', 'facts' => $strangeFacts],
        ]);

        $event = iterator_to_array($composer->compose($this->w()), false)[0];
        $this->assertSame($strangeFacts, $event->facts, 'facts must be carried through with no key reordering');
        $this->assertSame(array_keys($strangeFacts), array_keys($event->facts));
    }

    public function test_degenerate_window_yields_empty_stream_without_touching_any_source(): void
    {
        $composer = new AtlasLoopAuditTrailComposer;
        $touched = false;
        $composer->register('A', function () use (&$touched) {
            $touched = true;

            return [];
        });

        $degenerate = new TimelineWindow('2026-06-24T12:00:00Z', '2026-06-24T12:00:00Z');
        $events = iterator_to_array($composer->compose($degenerate), false);

        $this->assertSame([], $events);
        $this->assertFalse($touched, 'no source must be invoked when the window is degenerate');
    }

    public function test_events_outside_window_are_dropped(): void
    {
        $composer = new AtlasLoopAuditTrailComposer;
        $composer->register('A', fn () => [
            ['ts_utc' => '2026-06-23T23:59:59Z', 'kind' => 'k', 'facts' => []],
            ['ts_utc' => '2026-06-24T10:00:00Z', 'kind' => 'k', 'facts' => []],
            ['ts_utc' => '2026-06-25T00:00:00Z', 'kind' => 'k', 'facts' => []],
        ]);

        $events = iterator_to_array($composer->compose($this->w()), false);
        $this->assertCount(1, $events);
        $this->assertSame('2026-06-24T10:00:00Z', $events[0]->ts_utc);
    }
}
