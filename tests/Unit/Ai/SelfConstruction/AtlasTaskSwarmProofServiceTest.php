<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasTaskSwarmProofService;
use PHPUnit\Framework\TestCase;

/**
 * Proves waiting_on_dependencies is an honest R2 next-call outcome: AtlasTaskServingService
 * intentionally emits it when claimable packets are dependency-gated, so the swarm analyzer
 * must count it separately instead of flagging it as a serving breach.
 */
final class AtlasTaskSwarmProofServiceTest extends TestCase
{
    private function waiting(string $client): array
    {
        return ['client_id' => $client, 'exit_code' => 0, 'envelope' => ['status' => 'waiting_on_dependencies']];
    }

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

    public function test_waiting_on_dependencies_passes_r2_honesty_without_breach(): void
    {
        $xray = (new AtlasTaskSwarmProofService)->analyze([[
            'round' => 0,
            'enqueued' => [],
            'observations' => [$this->waiting('c1')],
        ]]);

        $this->assertSame([], $xray['r2_breaches']);
        $this->assertTrue($xray['passed']);
    }

    public function test_waiting_on_dependencies_is_in_honest_next_statuses(): void
    {
        $this->assertContains('waiting_on_dependencies', AtlasTaskSwarmProofService::HONEST_NEXT_STATUSES);
    }

    public function test_totals_include_waiting_on_dependencies_count(): void
    {
        $xray = (new AtlasTaskSwarmProofService)->analyze([[
            'round' => 0,
            'enqueued' => [$this->spec('p1')],
            'observations' => [
                $this->served('c1', 'p1'),
                $this->empty('c2'),
                $this->waiting('c3'),
                $this->waiting('c4'),
            ],
        ]]);

        $this->assertSame(2, $xray['totals']['waiting_on_dependencies']);
        $this->assertSame(1, $xray['totals']['served']);
        $this->assertSame(1, $xray['totals']['no_claimable_task']);
    }

    public function test_waiting_on_dependencies_does_not_inflate_no_claimable_task_count(): void
    {
        $xray = (new AtlasTaskSwarmProofService)->analyze([[
            'round' => 0,
            'enqueued' => [],
            'observations' => [$this->waiting('c1')],
        ]]);

        $this->assertSame(0, $xray['totals']['no_claimable_task']);
        $this->assertSame(1, $xray['totals']['waiting_on_dependencies']);
    }
}
