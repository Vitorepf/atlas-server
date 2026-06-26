<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroQueueAgeHistogram;
use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

final class AtlasMaestroQueueAgeHistogramTest extends TestCase
{
    public function test_histogram_bins_percentiles_and_read_only_queue_use(): void
    {
        $now = new DateTimeImmutable('2026-06-24T12:00:00+00:00', new DateTimeZone('UTC'));
        $packets = [
            $this->claimableFor($now, 30, 'claimable_since'),
            $this->claimableFor($now, 120, 'created_at'),
            $this->claimableFor($now, 600, 'enqueued_at'),
            $this->claimableFor($now, 1800, 'updated_at'),
            $this->claimableFor($now, 7200, 'claimable_since'),
            $this->claimableFor($now, 30000, 'claimable_since'),
            $this->claimableFor($now, 90000, 'claimable_since'),
        ];
        $queue = new class($packets)
        {
            public int $listCalls = 0;

            public int $enqueueCalls = 0;

            public int $updateStatusCalls = 0;

            public int $claimCalls = 0;

            /** @param list<array<string,mixed>> $packets */
            public function __construct(private readonly array $packets) {}

            /** @return list<array<string,mixed>> */
            public function list(array $filters = []): array
            {
                $this->listCalls++;

                return (string) ($filters['status'] ?? '') === 'claimable' ? $this->packets : [];
            }

            public function enqueue(): void
            {
                $this->enqueueCalls++;
            }

            public function updateStatus(): void
            {
                $this->updateStatusCalls++;
            }

            public function claim(): void
            {
                $this->claimCalls++;
            }

            /** @return array<string,mixed> */
            public function state(): array
            {
                return [
                    'packets' => $this->packets,
                    'list_calls' => $this->listCalls,
                    'enqueue_calls' => $this->enqueueCalls,
                    'update_status_calls' => $this->updateStatusCalls,
                    'claim_calls' => $this->claimCalls,
                ];
            }
        };

        $before = $queue->state();
        $histogram = (new AtlasMaestroQueueAgeHistogram(
            $queue,
            static fn (): DateTimeImmutable => $now,
        ))->histogram();
        $after = $queue->state();

        $this->assertSame('atlas.maestro.health.queue_age_histogram.v1', $histogram['schema']);
        $this->assertSame(7, $histogram['total_claimable']);
        $this->assertSame([
            ['label' => '<1m', 'lower_seconds' => 0, 'upper_seconds' => 60, 'count' => 1],
            ['label' => '1-5m', 'lower_seconds' => 60, 'upper_seconds' => 300, 'count' => 1],
            ['label' => '5-15m', 'lower_seconds' => 300, 'upper_seconds' => 900, 'count' => 1],
            ['label' => '15-60m', 'lower_seconds' => 900, 'upper_seconds' => 3600, 'count' => 1],
            ['label' => '1-6h', 'lower_seconds' => 3600, 'upper_seconds' => 21600, 'count' => 1],
            ['label' => '6-24h', 'lower_seconds' => 21600, 'upper_seconds' => 86400, 'count' => 1],
            ['label' => '>24h', 'lower_seconds' => 86400, 'upper_seconds' => null, 'count' => 1],
        ], $histogram['bins']);
        $this->assertSame(7, array_sum(array_column($histogram['bins'], 'count')));
        $this->assertSame(90000, $histogram['oldest_seconds']);
        $this->assertSame(1800, $histogram['p50_seconds']);
        $this->assertSame(90000, $histogram['p95_seconds']);
        $this->assertSame(0, $before['list_calls']);
        $this->assertSame(1, $after['list_calls']);
        $this->assertSame(0, $after['enqueue_calls']);
        $this->assertSame(0, $after['update_status_calls']);
        $this->assertSame(0, $after['claim_calls']);
        $this->assertSame($before['packets'], $after['packets']);
    }

    public function test_empty_claimable_queue_returns_zeroed_facts(): void
    {
        $queue = new class
        {
            /** @return list<array<string,mixed>> */
            public function list(array $filters = []): array
            {
                return [];
            }
        };

        $histogram = (new AtlasMaestroQueueAgeHistogram($queue))->histogram();

        $this->assertSame(0, $histogram['total_claimable']);
        $this->assertSame(0, array_sum(array_column($histogram['bins'], 'count')));
        $this->assertSame(0, $histogram['oldest_seconds']);
        $this->assertSame(0, $histogram['p50_seconds']);
        $this->assertSame(0, $histogram['p95_seconds']);
    }

    public function test_serving_disk_claimable_packets_are_binned_without_injected_queue(): void
    {
        config()->set('atlas.task_serving.queue_disk', 'atlas-queue-age-test-disk');
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::fake(AtlasTaskServingStack::disk());

        AtlasTaskServingStack::queueRepo()->enqueue([
            'task_packet_id' => 'serving-queue-age-packet',
            'task_packet_hash' => hash('sha256', 'serving-queue-age-packet'),
            'objective' => 'queue age test',
            'operator_id' => 'tester',
            'status' => 'claimable',
            'allowed_files' => ['app/Loop/Queued.php'],
            'scope_in' => ['app/Loop/Queued.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ]);

        $histogram = (new AtlasMaestroQueueAgeHistogram())->histogram();

        $this->assertGreaterThanOrEqual(1, $histogram['total_claimable']);
    }

    /**
     * @return array<string,mixed>
     */
    private function claimableFor(DateTimeImmutable $now, int $seconds, string $timestampField): array
    {
        $claimableAt = $now->sub(new \DateInterval('PT'.$seconds.'S'));

        return [
            'task_packet_id' => 'packet_'.$seconds,
            'status' => 'claimable',
            $timestampField => $claimableAt->format(DATE_ATOM),
        ];
    }
}
