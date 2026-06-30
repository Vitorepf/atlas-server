<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\Decay\AtlasMaestroPacketAgeFactReporter;
use Tests\TestCase;

final class AtlasMaestroPacketAgeFactReporterTest extends TestCase
{
    public function test_three_packets_distinct_ages_emit_facts_in_enqueued_at_ascending_order(): void
    {
        $packets = [
            ['task_packet_id' => 'middle', 'enqueued_at' => '2026-06-25T05:01:00Z', 'queue_status' => 'waiting'],
            ['task_packet_id' => 'oldest', 'enqueued_at' => '2026-06-25T05:00:00Z', 'queue_status' => 'served'],
            ['task_packet_id' => 'newest', 'enqueued_at' => '2026-06-25T05:02:00Z', 'queue_status' => 'waiting'],
        ];
        $reporter = new AtlasMaestroPacketAgeFactReporter(
            fn () => $packets,
            fn () => '2026-06-25T05:03:00Z',
            fn () => true,
        );

        $facts = $reporter->report();
        $this->assertCount(3, $facts);
        $this->assertSame(['oldest', 'middle', 'newest'], array_column($facts, 'task_packet_id'));
        $this->assertSame(180, $facts[0]['time_in_queue_seconds']);
        $this->assertSame(120, $facts[1]['time_in_queue_seconds']);
        $this->assertSame(60, $facts[2]['time_in_queue_seconds']);

        foreach ($facts as $f) {
            $this->assertSame(['age_bucket', 'enqueued_at', 'observed_at', 'queue_status', 'stale_risk', 'task_packet_id', 'time_in_queue_seconds'], array_keys($f));
        }
    }

    public function test_master_off_returns_empty_array_byte_identical(): void
    {
        $reporter = new AtlasMaestroPacketAgeFactReporter(
            fn () => [['task_packet_id' => 'x', 'enqueued_at' => '2026-06-25T05:00:00Z', 'queue_status' => 'waiting']],
            fn () => '2026-06-25T05:01:00Z',
            fn () => false,
        );
        $this->assertSame([], $reporter->report());
    }

    public function test_master_on_empty_queue_returns_empty_array(): void
    {
        $reporter = new AtlasMaestroPacketAgeFactReporter(
            fn () => [],
            fn () => '2026-06-25T05:01:00Z',
            fn () => true,
        );
        $this->assertSame([], $reporter->report());
    }

    public function test_age_bucket_fresh_for_sub_60_seconds(): void
    {
        $reporter = new AtlasMaestroPacketAgeFactReporter(
            fn () => [['task_packet_id' => 'p', 'enqueued_at' => '2026-06-25T05:00:00Z', 'queue_status' => 'waiting']],
            fn () => '2026-06-25T05:00:59Z',
            fn () => true,
        );
        $f = $reporter->report()[0];
        $this->assertSame('fresh', $f['age_bucket']);
        $this->assertSame('none', $f['stale_risk']);
    }

    public function test_age_bucket_stale_for_5_to_60_minutes(): void
    {
        $reporter = new AtlasMaestroPacketAgeFactReporter(
            fn () => [['task_packet_id' => 'p', 'enqueued_at' => '2026-06-25T05:00:00Z', 'queue_status' => 'waiting']],
            fn () => '2026-06-25T05:10:00Z',
            fn () => true,
        );
        $f = $reporter->report()[0];
        $this->assertSame('stale', $f['age_bucket']);
        $this->assertSame('medium', $f['stale_risk']);
    }

    public function test_age_bucket_critical_for_over_one_hour(): void
    {
        $reporter = new AtlasMaestroPacketAgeFactReporter(
            fn () => [['task_packet_id' => 'p', 'enqueued_at' => '2026-06-25T04:00:00Z', 'queue_status' => 'waiting']],
            fn () => '2026-06-25T05:00:00Z',
            fn () => true,
        );
        $f = $reporter->report()[0];
        $this->assertSame('critical', $f['age_bucket']);
        $this->assertSame('high', $f['stale_risk']);
    }

    public function test_sort_by_risk_then_age_then_id(): void
    {
        $reporter = new AtlasMaestroPacketAgeFactReporter(
            fn () => [
                ['task_packet_id' => 'aging-b', 'enqueued_at' => '2026-06-25T04:59:00Z', 'queue_status' => 'waiting'],
                ['task_packet_id' => 'critical', 'enqueued_at' => '2026-06-25T03:00:00Z', 'queue_status' => 'waiting'],
                ['task_packet_id' => 'aging-a', 'enqueued_at' => '2026-06-25T04:59:30Z', 'queue_status' => 'waiting'],
                ['task_packet_id' => 'stale', 'enqueued_at' => '2026-06-25T04:50:00Z', 'queue_status' => 'waiting'],
            ],
            fn () => '2026-06-25T05:00:00Z',
            fn () => true,
        );
        $ids = array_column($reporter->report(), 'task_packet_id');
        $this->assertSame(['critical', 'stale', 'aging-b', 'aging-a'], $ids);
    }

    public function test_static_inspection_no_forbidden_symbols_in_reporter_source(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/Maestro/Decay/AtlasMaestroPacketAgeFactReporter.php'));
        foreach (['score', 'grade', 'judge', 'judgement', 'normalize', 'normaliz', 'DB::', 'Hermes', 'http_request', '->insert(', '->update('] as $banned) {
            $this->assertStringNotContainsString($banned, $src, "forbidden symbol present: $banned");
        }
    }
}
