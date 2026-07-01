<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Decay;

use App\Services\Ai\SelfConstruction\Maestro\Decay\AtlasMaestroPacketAgeFactReporter;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroPacketAgeFactReporterTest extends TestCase
{
    private function reporter(array $packets): AtlasMaestroPacketAgeFactReporter
    {
        return new AtlasMaestroPacketAgeFactReporter(
            static fn (): array => $packets,
            static fn (): string => '2026-06-25T12:00:00Z',
            static fn (): bool => true,
        );
    }

    public function test_master_off_returns_empty_list(): void
    {
        $reporter = new AtlasMaestroPacketAgeFactReporter(
            static fn (): array => [['task_packet_id' => 'p-1', 'enqueued_at' => '2026-06-25T11:00:00Z']],
            static fn (): string => '2026-06-25T12:00:00Z',
            static fn (): bool => false,
        );

        $this->assertSame([], $reporter->report());
    }

    // ── AC: old low-value packets are reported as stale_low_value ─────────────

    public function test_old_low_value_packet_is_reported_as_stale_low_value(): void
    {
        $facts = $this->reporter([
            [
                'task_packet_id' => 'p-old-low',
                'enqueued_at' => '2026-06-25T10:00:00Z', // 2h old → critical bucket
                'expected_value' => 1.0,
            ],
        ])->report();

        $this->assertSame(AtlasMaestroPacketAgeFactReporter::FRESHNESS_STALE_LOW_VALUE, $facts[0]['freshness_status']);
        $this->assertSame(AtlasMaestroPacketAgeFactReporter::VALUE_CLASS_LOW, $facts[0]['value_class']);
    }

    // ── AC: old high-value packets are reported as rescue_candidate ───────────

    public function test_old_high_value_packet_is_reported_as_rescue_candidate(): void
    {
        $facts = $this->reporter([
            [
                'task_packet_id' => 'p-old-high',
                'enqueued_at' => '2026-06-25T10:00:00Z', // 2h old → critical bucket
                'expected_value' => 8.0,
            ],
        ])->report();

        $this->assertSame(AtlasMaestroPacketAgeFactReporter::FRESHNESS_RESCUE_CANDIDATE, $facts[0]['freshness_status']);
        $this->assertSame(AtlasMaestroPacketAgeFactReporter::VALUE_CLASS_HIGH, $facts[0]['value_class']);
    }

    // ── AC: report output includes age_seconds, value_class and freshness_status ──

    public function test_report_output_includes_age_seconds_value_class_and_freshness_status(): void
    {
        $facts = $this->reporter([
            [
                'task_packet_id' => 'p-fresh',
                'enqueued_at' => '2026-06-25T11:59:50Z', // 10s old → fresh
                'expected_value' => 2.0,
            ],
        ])->report();

        $this->assertArrayHasKey('age_seconds', $facts[0]);
        $this->assertArrayHasKey('value_class', $facts[0]);
        $this->assertArrayHasKey('freshness_status', $facts[0]);
        $this->assertSame(10, $facts[0]['age_seconds']);
        $this->assertSame($facts[0]['age_seconds'], $facts[0]['time_in_queue_seconds']);
        $this->assertSame(AtlasMaestroPacketAgeFactReporter::FRESHNESS_WORKER_READY, $facts[0]['freshness_status']);
    }

    public function test_fresh_packet_is_worker_ready_regardless_of_value_class(): void
    {
        $facts = $this->reporter([
            [
                'task_packet_id' => 'p-fresh-highvalue',
                'enqueued_at' => '2026-06-25T11:59:50Z', // 10s old → fresh
                'expected_value' => 9.0,
            ],
        ])->report();

        $this->assertSame(AtlasMaestroPacketAgeFactReporter::FRESHNESS_WORKER_READY, $facts[0]['freshness_status']);
    }

    public function test_missing_expected_value_defaults_to_low_value_class(): void
    {
        $facts = $this->reporter([
            ['task_packet_id' => 'p-novalue', 'enqueued_at' => '2026-06-25T10:00:00Z'],
        ])->report();

        $this->assertSame(AtlasMaestroPacketAgeFactReporter::VALUE_CLASS_LOW, $facts[0]['value_class']);
    }
}
