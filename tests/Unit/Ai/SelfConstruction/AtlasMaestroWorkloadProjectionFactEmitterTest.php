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
