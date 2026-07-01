<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\ControlPlane\TerminalLoopHealthDigestQueueReader;
use Tests\TestCase;

/**
 * Focused unit coverage for TerminalLoopHealthDigestQueueReader: distinguishes servable, leased,
 * recoverable, blocked, malformed, healthy, and dry queue states without guessing — tagged
 * classification counts only include records carrying EVERY requested queue tag, and the digest
 * reports malformed_risk/is_healthy/is_dry deterministically from the classification list.
 */
final class TerminalLoopHealthDigestQueueReaderTest extends TestCase
{
    public function test_record_queue_tags_normalizes_to_a_string_list(): void
    {
        $result = TerminalLoopHealthDigestQueueReader::recordQueueTags(['queue_tags' => ['a', 'b', 'c']]);

        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function test_record_queue_tags_missing_is_empty(): void
    {
        $this->assertSame([], TerminalLoopHealthDigestQueueReader::recordQueueTags([]));
    }

    // ── AC: tagged classification counts only include records carrying all requested tags ──

    public function test_classification_count_requires_every_requested_tag_present(): void
    {
        $items = [
            ['classification' => 'servable', 'queue_tags' => ['a', 'b']],
            ['classification' => 'servable', 'queue_tags' => ['a']],
            ['classification' => 'servable', 'queue_tags' => ['b']],
        ];

        $this->assertSame(1, TerminalLoopHealthDigestQueueReader::classificationCount($items, 'servable', ['a', 'b']));
    }

    public function test_classification_count_matches_when_record_carries_a_superset_of_requested_tags(): void
    {
        $items = [
            ['classification' => 'servable', 'queue_tags' => ['a', 'b', 'c']],
        ];

        $this->assertSame(1, TerminalLoopHealthDigestQueueReader::classificationCount($items, 'servable', ['a', 'b']));
    }

    public function test_classification_count_excludes_records_missing_a_requested_tag(): void
    {
        $items = [
            ['classification' => 'servable', 'queue_tags' => ['a']],
        ];

        $this->assertSame(0, TerminalLoopHealthDigestQueueReader::classificationCount($items, 'servable', ['a', 'b']));
    }

    public function test_classification_count_with_no_tags_ignores_tag_filtering(): void
    {
        $items = [
            ['classification' => 'servable'],
            ['classification' => 'blocked'],
            ['classification' => 'servable'],
        ];

        $this->assertSame(2, TerminalLoopHealthDigestQueueReader::classificationCount($items, 'servable', []));
    }

    public function test_classification_count_skips_records_with_a_different_classification(): void
    {
        $items = [
            ['classification' => 'blocked', 'queue_tags' => ['a']],
            ['classification' => 'servable', 'queue_tags' => ['a']],
        ];

        $this->assertSame(1, TerminalLoopHealthDigestQueueReader::classificationCount($items, 'servable', ['a']));
    }

    // ── AC: digest reports malformed_risk / is_healthy=false / is_dry=true ──────────

    public function test_digest_healthy_sample_reports_servable_and_leased_counts(): void
    {
        $records = [
            ['classification' => 'servable'],
            ['classification' => 'servable'],
            ['classification' => 'leased'],
        ];

        $d = TerminalLoopHealthDigestQueueReader::digest($records);

        $this->assertSame(3, $d['queue_depth']);
        $this->assertSame(2, $d['servable_depth']);
        $this->assertSame(1, $d['active_leases']);
        $this->assertTrue($d['is_healthy']);
        $this->assertFalse($d['is_dry']);
        $this->assertSame(0, $d['malformed_risk']);
    }

    public function test_digest_dry_sample_has_no_servable_work(): void
    {
        $d = TerminalLoopHealthDigestQueueReader::digest([]);

        $this->assertSame(0, $d['servable_depth']);
        $this->assertTrue($d['is_dry']);
        $this->assertFalse($d['is_healthy']);
    }

    public function test_digest_malformed_pressure_sample_marks_unhealthy(): void
    {
        $records = [
            ['classification' => 'servable'],
            ['classification' => 'malformed'],
            ['classification' => 'malformed'],
        ];

        $d = TerminalLoopHealthDigestQueueReader::digest($records);

        $this->assertSame(2, $d['malformed_risk']);
        $this->assertFalse($d['is_healthy'], 'malformed classifications must mark the queue unhealthy even with servable work present');
        $this->assertFalse($d['is_dry'], 'servable work is present, so the queue is not dry');
    }

    public function test_digest_output_is_provider_safe(): void
    {
        $d = TerminalLoopHealthDigestQueueReader::digest([['classification' => 'servable']]);

        $this->assertTrue($d['provider_safe']);
        foreach (['queue_depth', 'servable_depth', 'active_leases', 'recoverables', 'blocked_pressure', 'malformed_risk'] as $key) {
            $this->assertArrayHasKey($key, $d);
        }
    }
}
