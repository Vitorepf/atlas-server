<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopRegressionSentinel;
use PHPUnit\Framework\TestCase;

/**
 * Absurd-leap 4 — the regression immune system. Attributes a newly-RED check to the latest loop merge that
 * touched the broken surface and emits a fix-forward repair; leaves external breakage unattributed.
 */
final class AtlasLoopRegressionSentinelTest extends TestCase
{
    public function test_attributes_failure_to_overlapping_merge_and_emits_repair(): void
    {
        $r = (new AtlasLoopRegressionSentinel)->triage(
            [['id' => 'ExportTest::test_429', 'related_files' => ['app/Http/Controllers/ExportController.php']]],
            [['commit' => 'abc123def456', 'files' => ['app/Http/Controllers/ExportController.php'], 'merged_at' => '2026-06-15T10:00:00Z', 'proposal_id' => 'p1']],
        );
        $this->assertCount(1, $r['attributed']);
        $this->assertSame('abc123def456', $r['attributed'][0]['commit']);
        $this->assertCount(1, $r['repairs']);
        $this->assertStringContainsString('REPAIR regression', $r['repairs'][0]['objective']);
        $this->assertStringContainsString('fix forward', $r['repairs'][0]['objective']);
        $this->assertSame([], $r['unattributed']);
    }

    public function test_external_breakage_is_left_unattributed(): void
    {
        $r = (new AtlasLoopRegressionSentinel)->triage(
            [['id' => 'UnrelatedTest::test_x', 'related_files' => ['app/Services/Unrelated.php']]],
            [['commit' => 'abc', 'files' => ['app/Http/Controllers/ExportController.php'], 'merged_at' => '2026-06-15T10:00:00Z']],
        );
        $this->assertSame([], $r['attributed'], 'no file overlap => the loop does not blame itself');
        $this->assertSame(['UnrelatedTest::test_x'], $r['unattributed']);
        $this->assertSame([], $r['repairs']);
    }

    public function test_attributes_to_the_latest_overlapping_merge(): void
    {
        $r = (new AtlasLoopRegressionSentinel)->triage(
            [['id' => 'T::t', 'related_files' => ['app/Foo.php']]],
            [
                ['commit' => 'old', 'files' => ['app/Foo.php'], 'merged_at' => '2026-06-15T08:00:00Z'],
                ['commit' => 'new', 'files' => ['app/Foo.php'], 'merged_at' => '2026-06-15T12:00:00Z'],
            ],
        );
        $this->assertSame('new', $r['attributed'][0]['commit'], 'the most-recent change to the broken surface is the culprit');
    }

    public function test_no_failures_is_clean(): void
    {
        $r = (new AtlasLoopRegressionSentinel)->triage([], [['commit' => 'x', 'files' => ['a.php'], 'merged_at' => 't']]);
        $this->assertSame([], $r['attributed']);
        $this->assertSame([], $r['repairs']);
        $this->assertSame([], $r['unattributed']);
    }

    public function test_repair_source_key_is_stable_for_dedup(): void
    {
        $args = [
            [['id' => 'T::t', 'related_files' => ['app/Foo.php']]],
            [['commit' => 'abc', 'files' => ['app/Foo.php'], 'merged_at' => 't']],
        ];
        $a = (new AtlasLoopRegressionSentinel)->triage(...$args);
        $b = (new AtlasLoopRegressionSentinel)->triage(...$args);
        $this->assertSame($a['repairs'][0]['source_key'], $b['repairs'][0]['source_key'], 'same failure+commit => stable dedup key');
    }

    public function test_latest_by_parsed_instant_across_mixed_iso_formats(): void
    {
        // (workflow fix #14) lexicographic compare gets mixed ISO-8601 forms WRONG: '2026-06-15T12:00:00Z'
        // is EARLIER than '2026-06-15T11:30:00-05:00' (= 16:30 UTC). The parsed-instant compare picks the
        // later-UTC merge regardless of input order or format.
        $r = (new AtlasLoopRegressionSentinel)->triage(
            [['id' => 'T::t', 'related_files' => ['app/Foo.php']]],
            [
                ['commit' => 'zulu_earlier', 'files' => ['app/Foo.php'], 'merged_at' => '2026-06-15T12:00:00Z'],
                ['commit' => 'offset_later', 'files' => ['app/Foo.php'], 'merged_at' => '2026-06-15T11:30:00-05:00'],
            ],
        );
        $this->assertSame('offset_later', $r['attributed'][0]['commit'], '11:30-05:00 (16:30Z) is later than 12:00Z');
    }

    public function test_missing_timestamp_loses_to_a_real_one(): void
    {
        // (workflow fix #15) a merge with no merged_at (epoch 0) must lose to any merge with a real one.
        $r = (new AtlasLoopRegressionSentinel)->triage(
            [['id' => 'T::t', 'related_files' => ['app/Foo.php']]],
            [
                ['commit' => 'has_ts', 'files' => ['app/Foo.php'], 'merged_at' => '2026-06-15T10:00:00Z'],
                ['commit' => 'no_ts', 'files' => ['app/Foo.php']],
            ],
        );
        $this->assertSame('has_ts', $r['attributed'][0]['commit'], 'a real timestamp beats a missing one');
    }
}
