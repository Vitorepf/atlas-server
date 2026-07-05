<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskServing;

use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskServingBatchAtomicityVerifier;
use PHPUnit\Framework\TestCase;

final class AtlasTaskServingBatchAtomicityVerifierTest extends TestCase
{
    private AtlasTaskServingBatchAtomicityVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new AtlasTaskServingBatchAtomicityVerifier;
    }

    public function test_all_enqueued_is_atomic(): void
    {
        $result = $this->verifier->verify([
            ['task_packet_id' => 't1', 'status' => 'enqueued'],
            ['task_packet_id' => 't2', 'status' => 'enqueued'],
        ]);

        $this->assertSame('all_enqueued', $result['outcome']);
        $this->assertTrue($result['atomic']);
        $this->assertSame(2, $result['credited_count']);
    }

    public function test_partial_conflict_reports_credited_below_requested(): void
    {
        $result = $this->verifier->verify([
            ['task_packet_id' => 't1', 'status' => 'enqueued'],
            ['task_packet_id' => 't2', 'status' => 'rejected'],
            ['task_packet_id' => 't3', 'status' => 'enqueued'],
        ]);

        $this->assertSame('partial_conflict', $result['outcome']);
        $this->assertFalse($result['atomic']);
        $this->assertSame(2, $result['credited_count']);
        $this->assertSame(3, $result['requested_count']);
        $this->assertLessThan($result['requested_count'], $result['credited_count']);
    }

    public function test_partial_conflict_includes_rejected_packet_ids(): void
    {
        $result = $this->verifier->verify([
            ['task_packet_id' => 't1', 'status' => 'enqueued'],
            ['task_packet_id' => 't2', 'status' => 'duplicate_id'],
        ]);

        $this->assertSame('partial_conflict', $result['outcome']);
        $this->assertNotEmpty($result['rejected_packets']);
        $this->assertSame('t2', $result['rejected_packets'][0]['task_packet_id']);
    }

    public function test_all_rejected(): void
    {
        $result = $this->verifier->verify([
            ['task_packet_id' => 't1', 'status' => 'rejected'],
            ['task_packet_id' => 't2', 'status' => 'rejected'],
        ]);

        $this->assertSame('all_rejected', $result['outcome']);
        $this->assertSame(0, $result['credited_count']);
    }

    public function test_empty_batch_is_not_atomic(): void
    {
        $result = $this->verifier->verify([]);

        $this->assertFalse($result['atomic']);
    }

    public function test_schema_present(): void
    {
        $result = $this->verifier->verify([]);
        $this->assertSame(AtlasTaskServingBatchAtomicityVerifier::SCHEMA, $result['schema']);
    }
}
