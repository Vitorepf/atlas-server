<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroWorkloadProjectionFactEmitter;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class AtlasMaestroWorkloadProjectionFactEmitterTest extends TestCase
{
    public function test_emit_returns_projection_shape_with_per_worker_rows(): void
    {
        $now = CarbonImmutable::parse('2026-06-24T07:00:00Z');

        $fact = (new AtlasMaestroWorkloadProjectionFactEmitter)->emit(
            $this->consumptionFact(10.0, [
                ['client_id' => 'worker-a', 'tasks_per_hour' => 4.0],
                ['client_id' => 'worker-b', 'tasks_per_hour' => 6.0],
            ], $now),
            $this->registrySnapshot(),
            $now
        );

        $this->assertSame('atlas.maestro.projection.time_to_empty.v1', $fact['schema']);
        $this->assertSame(25, $fact['queue_remaining_count']);
        $this->assertEqualsWithDelta(2.5, $fact['queue_empty_in_hours'], 1e-9);
        $this->assertSame('2026-06-24T09:30:00+00:00', $fact['queue_empty_at_iso']);
        $this->assertSame('worker-a', $fact['per_worker'][0]['client_id']);
        $this->assertSame('worker-b', $fact['per_worker'][1]['client_id']);
    }

    public function test_emit_handles_divide_by_zero_honestly(): void
    {
        $now = CarbonImmutable::parse('2026-06-24T07:00:00Z');

        $fact = (new AtlasMaestroWorkloadProjectionFactEmitter)->emit(
            $this->consumptionFact(0.0, [
                ['client_id' => 'worker-a', 'tasks_per_hour' => 0.0],
            ], $now),
            $this->registrySnapshot(),
            $now
        );

        $this->assertNull($fact['queue_empty_in_hours']);
        $this->assertSame('insufficient_throughput', $fact['reason']);
        $this->assertNull($fact['queue_empty_at_iso']);
    }

    public function test_emit_projects_queue_empty_at_known_rate(): void
    {
        $now = CarbonImmutable::parse('2026-06-24T07:00:00Z');

        $fact = (new AtlasMaestroWorkloadProjectionFactEmitter)->emit(
            $this->consumptionFact(10.0, [
                ['client_id' => 'worker-a', 'tasks_per_hour' => 4.0],
                ['client_id' => 'worker-b', 'tasks_per_hour' => 6.0],
            ], $now),
            $this->registrySnapshot(),
            $now
        );

        $this->assertEqualsWithDelta(2.5, $fact['queue_empty_in_hours'], 1e-9);
        $this->assertSame('2026-06-24T09:30:00+00:00', $fact['queue_empty_at_iso']);
    }

    public function test_producer_shaped_packets_with_nested_metadata_client_id_yield_non_null_idle_hours(): void
    {
        $now = CarbonImmutable::parse('2026-06-29T10:00:00Z');

        // Producer nests client_id under metadata — flat client_id is absent.
        $packets = [
            ['task_packet_id' => 'p1', 'status' => 'claimable', 'metadata' => ['client_id' => 'worker-x']],
            ['task_packet_id' => 'p2', 'status' => 'claimable', 'metadata' => ['client_id' => 'worker-x']],
        ];
        $registrySnapshot = ['packets' => $packets];

        $consumptionFact = $this->consumptionFact(4.0, [
            ['client_id' => 'worker-x', 'tasks_per_hour' => 4.0],
        ], $now);

        $fact = (new AtlasMaestroWorkloadProjectionFactEmitter)->emit($consumptionFact, $registrySnapshot, $now);

        $this->assertNotEmpty($fact['per_worker'], 'per_worker must have rows when consumption rows exist');
        $workerRow = $fact['per_worker'][0];
        $this->assertSame('worker-x', $workerRow['client_id']);
        $this->assertNotNull($workerRow['worker_idle_in_hours'],
            'worker_idle_in_hours must not be null when producer-shaped packets nest client_id under metadata');
        $this->assertEqualsWithDelta(0.5, $workerRow['worker_idle_in_hours'], 1e-9,
            '2 claimable / 4 tasks_per_hour = 0.5 hours idle');
    }

    public function test_emit_marks_replenish_now_when_queue_exhausts_before_minimum_horizon(): void
    {
        $now = CarbonImmutable::parse('2026-06-24T07:00:00Z');
        // 2 queued packets / 10 tasks_per_hour = 0.2 h < HORIZON_REPLENISH_HOURS (1.0) → replenish_now
        $snapshot = ['packets' => [
            ['task_packet_id' => 'p1', 'status' => 'queued'],
            ['task_packet_id' => 'p2', 'status' => 'queued'],
        ]];

        $fact = (new AtlasMaestroWorkloadProjectionFactEmitter)->emit(
            $this->consumptionFact(10.0, [], $now),
            $snapshot,
            $now,
        );

        $this->assertSame(AtlasMaestroWorkloadProjectionFactEmitter::RISK_REPLENISH_NOW, $fact['risk']);
        $this->assertNotEmpty($fact['bottleneck_reason']);
        $this->assertStringContainsString('queue_exhausts_before_replenish_horizon', $fact['bottleneck_reason']);
    }

    public function test_emit_healthy_when_stock_is_sufficient_and_no_external_calls(): void
    {
        $now = CarbonImmutable::parse('2026-06-24T07:00:00Z');
        // 20 queued packets / 1 task_per_hour = 20 h > HORIZON_LOW_BUFFER_HOURS (4.0) → healthy
        $packets = array_map(
            static fn (int $i): array => ['task_packet_id' => "p{$i}", 'status' => 'queued'],
            range(1, 20),
        );

        $fact = (new AtlasMaestroWorkloadProjectionFactEmitter)->emit(
            $this->consumptionFact(1.0, [], $now),
            ['packets' => $packets],
            $now,
        );

        $this->assertSame(AtlasMaestroWorkloadProjectionFactEmitter::RISK_HEALTHY, $fact['risk']);
        $this->assertNull($fact['bottleneck_reason']);
        $this->assertNull($fact['reason']); // no insufficient_throughput flag either
    }

    /**
     * @param  list<array{client_id:string,tasks_per_hour:float}>  $workers
     * @return array<string,mixed>
     */
    private function consumptionFact(float $fleetRate, array $workers, CarbonImmutable $now): array
    {
        $rows = [];
        foreach ($workers as $worker) {
            $rows[] = [
                'client_id' => $worker['client_id'],
                'tasks_per_hour' => $worker['tasks_per_hour'],
            ];
        }

        $rows[] = [
            'client_id' => 'fleet',
            'tasks_per_hour' => $fleetRate,
        ];

        return [
            'schema' => 'atlas.maestro.projection.consumption_rate.v1',
            'observed_at_iso' => $now->toIso8601String(),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{packets:list<array<string,string>>}
     */
    private function registrySnapshot(): array
    {
        return [
            'packets' => [
                ['task_packet_id' => 'p1', 'status' => 'claimable', 'client_id' => 'worker-a'],
                ['task_packet_id' => 'p2', 'status' => 'claimable', 'client_id' => 'worker-a'],
                ['task_packet_id' => 'p3', 'status' => 'claimable', 'client_id' => 'worker-b'],
                ['task_packet_id' => 'p4', 'status' => 'queued', 'client_id' => 'worker-a'],
                ['task_packet_id' => 'p5', 'status' => 'queued', 'client_id' => 'worker-b'],
                ['task_packet_id' => 'p6', 'status' => 'queued', 'client_id' => 'worker-b'],
                ['task_packet_id' => 'p7', 'status' => 'queued', 'client_id' => 'worker-a'],
                ['task_packet_id' => 'p8', 'status' => 'queued', 'client_id' => 'worker-b'],
                ['task_packet_id' => 'p9', 'status' => 'queued', 'client_id' => 'worker-a'],
                ['task_packet_id' => 'p10', 'status' => 'queued', 'client_id' => 'worker-b'],
                ['task_packet_id' => 'p11', 'status' => 'queued', 'client_id' => 'worker-a'],
                ['task_packet_id' => 'p12', 'status' => 'queued', 'client_id' => 'worker-b'],
                ['task_packet_id' => 'p13', 'status' => 'queued', 'client_id' => 'worker-a'],
                ['task_packet_id' => 'p14', 'status' => 'queued', 'client_id' => 'worker-b'],
                ['task_packet_id' => 'p15', 'status' => 'queued', 'client_id' => 'worker-a'],
                ['task_packet_id' => 'p16', 'status' => 'queued', 'client_id' => 'worker-b'],
                ['task_packet_id' => 'p17', 'status' => 'queued', 'client_id' => 'worker-a'],
                ['task_packet_id' => 'p18', 'status' => 'queued', 'client_id' => 'worker-b'],
                ['task_packet_id' => 'p19', 'status' => 'queued', 'client_id' => 'worker-a'],
                ['task_packet_id' => 'p20', 'status' => 'queued', 'client_id' => 'worker-b'],
                ['task_packet_id' => 'p21', 'status' => 'queued', 'client_id' => 'worker-a'],
                ['task_packet_id' => 'p22', 'status' => 'queued', 'client_id' => 'worker-b'],
                ['task_packet_id' => 'p23', 'status' => 'queued', 'client_id' => 'worker-a'],
                ['task_packet_id' => 'p24', 'status' => 'queued', 'client_id' => 'worker-b'],
                ['task_packet_id' => 'p25', 'status' => 'queued', 'client_id' => 'worker-a'],
            ],
        ];
    }
}
