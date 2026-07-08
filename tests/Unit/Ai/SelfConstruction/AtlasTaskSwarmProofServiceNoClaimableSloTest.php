<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskSwarmProofService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the no_claimable_task SLO-breach signal: when the originator expected a non-empty worker
 * floor (expected_worker_floor > 0) and a concurrent worker observed no_claimable_task, that is
 * reported as a no_claimable_slo_breach — worker starvation surfaced as proof feedback — while
 * still NOT counting as an R2 serving error (the envelope status is honest).
 */
final class AtlasTaskSwarmProofServiceNoClaimableSloTest extends TestCase
{
    private function served(string $client, string $packetId): array
    {
        return [
            'client_id' => $client,
            'exit_code' => 0,
            'envelope' => ['status' => 'served', 'task' => ['task_packet_id' => $packetId]],
        ];
    }

    private function empty(string $client): array
    {
        return ['client_id' => $client, 'exit_code' => 0, 'envelope' => ['status' => 'no_claimable_task']];
    }

    private function spec(string $id, array $write = []): array
    {
        return ['task_packet_id' => $id, 'write_set' => $write, 'read_set' => []];
    }

    public function test_no_claimable_with_expected_worker_floor_reports_slo_breach_without_r2_error(): void
    {
        $xray = (new AtlasTaskSwarmProofService)->analyze([[
            'round' => 0,
            'enqueued' => [$this->spec('p1')],
            'observations' => [
                $this->served('c1', 'p1'),
                $this->empty('c2'),
                $this->empty('c3'),
            ],
        ]], expectedWorkerFloor: 3);

        $this->assertTrue($xray['no_claimable_slo_breach']);
        $this->assertCount(2, $xray['no_claimable_slo_breaches']);
        $this->assertSame([], $xray['r2_breaches']);
        $this->assertTrue($xray['passed']);
    }

    public function test_no_expected_worker_floor_preserves_existing_honest_behavior(): void
    {
        $xray = (new AtlasTaskSwarmProofService)->analyze([[
            'round' => 0,
            'enqueued' => [$this->spec('p1')],
            'observations' => [
                $this->served('c1', 'p1'),
                $this->empty('c2'),
            ],
        ]]);

        $this->assertFalse($xray['no_claimable_slo_breach']);
        $this->assertSame([], $xray['no_claimable_slo_breaches']);
        $this->assertSame(0, $xray['expected_worker_floor']);
        $this->assertSame([], $xray['r2_breaches']);
        $this->assertTrue($xray['passed']);
        $this->assertSame(1, $xray['totals']['no_claimable_task']);
    }

    public function test_no_claimable_with_zero_breaches_when_floor_expected_but_all_served(): void
    {
        $xray = (new AtlasTaskSwarmProofService)->analyze([[
            'round' => 0,
            'enqueued' => [$this->spec('p1'), $this->spec('p2')],
            'observations' => [
                $this->served('c1', 'p1'),
                $this->served('c2', 'p2'),
            ],
        ]], expectedWorkerFloor: 2);

        $this->assertFalse($xray['no_claimable_slo_breach']);
        $this->assertSame([], $xray['no_claimable_slo_breaches']);
    }

    public function test_slo_breach_records_round_and_client(): void
    {
        $xray = (new AtlasTaskSwarmProofService)->analyze([[
            'round' => 7,
            'enqueued' => [],
            'observations' => [$this->empty('c9')],
        ]], expectedWorkerFloor: 5);

        $this->assertSame(7, $xray['no_claimable_slo_breaches'][0]['round']);
        $this->assertSame('c9', $xray['no_claimable_slo_breaches'][0]['client_id']);
    }

    public function test_analyze_with_expected_worker_floor_is_deterministic(): void
    {
        $service = new AtlasTaskSwarmProofService;
        $rounds = [[
            'round' => 0,
            'enqueued' => [$this->spec('p1')],
            'observations' => [$this->served('c1', 'p1'), $this->empty('c2')],
        ]];

        $this->assertSame(
            $service->analyze($rounds, expectedWorkerFloor: 2),
            $service->analyze($rounds, expectedWorkerFloor: 2),
        );
    }
}
