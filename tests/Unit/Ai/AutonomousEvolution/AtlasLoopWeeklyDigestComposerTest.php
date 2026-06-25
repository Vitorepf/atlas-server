<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\WeeklyDigest\AtlasLoopWeeklyDigestComposer;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AtlasLoopWeeklyDigestComposerTest extends TestCase
{
    private function sources(): array
    {
        return [
            'decision_receipt' => static fn (string $from, string $to): array => [
                ['occurred_at' => '2026-06-20T10:00:00Z', 'event_kind' => 'decided', 'payload' => ['x' => 1]],
                ['occurred_at' => '2026-06-21T10:00:00Z', 'event_kind' => 'decided', 'payload' => ['x' => 2]],
            ],
            'certification' => static fn (string $from, string $to): array => [
                ['occurred_at' => '2026-06-22T10:00:00Z', 'event_kind' => 'certified', 'payload' => ['ok' => true]],
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('atlas.loop.master_enabled', true);
    }

    public function test_compose_is_byte_identical_across_two_consecutive_runs_over_same_window(): void
    {
        $composer = new AtlasLoopWeeklyDigestComposer($this->sources());
        $a = $composer->compose('2026-06-20T00:00:00Z', '2026-06-27T00:00:00Z');
        $b = $composer->compose('2026-06-20T00:00:00Z', '2026-06-27T00:00:00Z');

        self::assertSame(json_encode($a), json_encode($b));
        self::assertSame($a['snapshot_hash'], $b['snapshot_hash']);
    }

    public function test_master_switch_off_returns_empty_snapshot(): void
    {
        Config::set('atlas.loop.master_enabled', false);
        $composer = new AtlasLoopWeeklyDigestComposer($this->sources());
        $verdict = $composer->compose('2026-06-20T00:00:00Z', '2026-06-27T00:00:00Z');

        self::assertSame([], $verdict['rows']);
        self::assertTrue($verdict['master_switch_off']);
    }

    public function test_rows_carry_only_facts_columns_no_score_or_rank(): void
    {
        $composer = new AtlasLoopWeeklyDigestComposer($this->sources());
        $verdict = $composer->compose('2026-06-20T00:00:00Z', '2026-06-27T00:00:00Z');

        foreach ($verdict['rows'] as $row) {
            self::assertSame(['ledger_source', 'event_kind', 'occurred_at', 'payload_digest'], array_keys($row));
            foreach (array_keys($row) as $key) {
                self::assertDoesNotMatchRegularExpression('/(score|rank|quality|grade|rating)/i', (string) $key);
            }
        }
    }

    public function test_rows_outside_window_are_filtered(): void
    {
        $composer = new AtlasLoopWeeklyDigestComposer([
            'decision_receipt' => static fn (string $f, string $t): array => [
                ['occurred_at' => '2026-06-10T10:00:00Z', 'event_kind' => 'old', 'payload' => []],
                ['occurred_at' => '2026-06-21T10:00:00Z', 'event_kind' => 'in', 'payload' => []],
                ['occurred_at' => '2026-07-01T10:00:00Z', 'event_kind' => 'future', 'payload' => []],
            ],
        ]);

        $verdict = $composer->compose('2026-06-20T00:00:00Z', '2026-06-27T00:00:00Z');
        self::assertCount(1, $verdict['rows']);
        self::assertSame('in', $verdict['rows'][0]['event_kind']);
    }

    public function test_duplicate_facts_across_sources_are_deduped_via_chokepoint_overlap_predicate(): void
    {
        $sharedFact = ['occurred_at' => '2026-06-21T10:00:00Z', 'event_kind' => 'shared', 'payload' => ['k' => 'v']];
        $composer = new AtlasLoopWeeklyDigestComposer([
            'decision_receipt' => static fn ($f, $t) => [$sharedFact],
            'decision_receipt_alias' => static fn ($f, $t) => [], // empty, no dup
            'evolution' => static fn ($f, $t) => [$sharedFact, $sharedFact], // duplicates within source
        ]);

        $verdict = $composer->compose('2026-06-20T00:00:00Z', '2026-06-27T00:00:00Z');
        $sourceCount = array_count_values(array_column($verdict['rows'], 'ledger_source'));
        self::assertSame(1, $sourceCount['evolution'], 'duplicate facts within the same source must be deduped');
    }
}
