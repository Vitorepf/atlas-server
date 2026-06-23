<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasTaskSwarmProofService;
use PHPUnit\Framework\TestCase;

/**
 * FROZEN proof of the conflict-free X-RAY analyzer — the crystallized judging logic, exercised on synthetic
 * observations (no subprocesses). It decides, deterministically, whether an observed multi-client run stayed
 * conflict-free / never failed (R2) / never invented or lost a packet.
 */
final class AtlasTaskSwarmProofAnalysisTest extends TestCase
{
    private function served(string $client, string $packetId, array $allowedFiles = []): array
    {
        return [
            'client_id' => $client,
            'exit_code' => 0,
            'envelope' => ['status' => 'served', 'task' => ['task_packet_id' => $packetId, 'allowed_files' => $allowedFiles]],
        ];
    }

    private function empty(string $client): array
    {
        return ['client_id' => $client, 'exit_code' => 0, 'envelope' => ['status' => 'no_claimable_task']];
    }

    private function spec(string $id, array $write, array $read = []): array
    {
        return ['task_packet_id' => $id, 'write_set' => $write, 'read_set' => $read];
    }

    public function test_clean_disjoint_run_passes(): void
    {
        $xray = (new AtlasTaskSwarmProofService)->analyze([[
            'round' => 0,
            'enqueued' => [
                $this->spec('p1', ['app/A/One.php']),
                $this->spec('p2', ['app/B/Two.php']),
            ],
            'observations' => [
                $this->served('c1', 'p1', ['app/A/One.php']),
                $this->served('c2', 'p2', ['app/B/Two.php']),
                $this->empty('c3'),
            ],
        ]]);

        $this->assertTrue($xray['passed'], 'a clean disjoint run is conflict-free and breach-free');
        $this->assertTrue($xray['conflict_free']);
        $this->assertSame(2, $xray['totals']['served']);
        $this->assertSame(1, $xray['totals']['no_claimable_task']);
        $this->assertSame([], $xray['double_claims']);
        $this->assertSame([], $xray['r2_breaches']);
    }

    public function test_double_claim_is_caught(): void
    {
        // The SAME packet served to two clients in one round = the catastrophic A1/A2 failure.
        $xray = (new AtlasTaskSwarmProofService)->analyze([[
            'round' => 0,
            'enqueued' => [$this->spec('p1', ['app/A/One.php'])],
            'observations' => [
                $this->served('c1', 'p1', ['app/A/One.php']),
                $this->served('c2', 'p1', ['app/A/One.php']),
            ],
        ]]);

        $this->assertFalse($xray['passed']);
        $this->assertFalse($xray['conflict_free']);
        $this->assertCount(1, $xray['double_claims']);
        $this->assertSame('p1', $xray['double_claims'][0]['task_packet_id']);
        $this->assertEqualsCanonicalizing(['c1', 'c2'], $xray['double_claims'][0]['clients']);
    }

    public function test_held_overlap_between_concurrently_served_packets_is_caught(): void
    {
        // Two DIFFERENT packets, but their write-sets collide (dir vs file): never legal to hold both at once.
        $xray = (new AtlasTaskSwarmProofService)->analyze([[
            'round' => 0,
            'enqueued' => [
                $this->spec('p1', ['app/Shared/']),
                $this->spec('p2', ['app/Shared/Thing.php']),
            ],
            'observations' => [
                $this->served('c1', 'p1'),
                $this->served('c2', 'p2'),
            ],
        ]]);

        $this->assertFalse($xray['passed']);
        $this->assertFalse($xray['conflict_free']);
        $this->assertCount(1, $xray['held_overlaps']);
        $this->assertNotEmpty($xray['held_overlaps'][0]['colliding_paths']);
    }

    public function test_read_vs_write_overlap_is_caught(): void
    {
        // p1 writes a file p2 reads — the read-vs-write hole MF-07: holding both concurrently is a conflict.
        $xray = (new AtlasTaskSwarmProofService)->analyze([[
            'round' => 0,
            'enqueued' => [
                $this->spec('p1', ['app/X/Core.php'], []),
                $this->spec('p2', ['app/Y/Other.php'], ['app/X/Core.php']),
            ],
            'observations' => [$this->served('c1', 'p1'), $this->served('c2', 'p2')],
        ]]);

        $this->assertFalse($xray['conflict_free'], 'A writes what B reads ⇒ conflict');
        $this->assertCount(1, $xray['held_overlaps']);
    }

    public function test_r2_breach_on_nonzero_exit_and_dishonest_status(): void
    {
        $xray = (new AtlasTaskSwarmProofService)->analyze([[
            'round' => 0,
            'enqueued' => [$this->spec('p1', ['app/A/One.php'])],
            'observations' => [
                ['client_id' => 'c1', 'exit_code' => 1, 'envelope' => ['status' => 'served', 'task' => ['task_packet_id' => 'p1']]],
                ['client_id' => 'c2', 'exit_code' => 0, 'envelope' => ['status' => 'disabled']],
                ['client_id' => 'c3', 'exit_code' => 0, 'envelope' => ['status' => 'error']],
            ],
        ]]);

        $this->assertFalse($xray['passed']);
        $this->assertCount(3, $xray['r2_breaches']);
        $reasons = array_column($xray['r2_breaches'], 'reason');
        $this->assertContains('nonzero_exit', $reasons);
        $this->assertContains('dishonest_status', $reasons);
    }

    public function test_phantom_serve_of_unenqueued_packet_is_caught(): void
    {
        $xray = (new AtlasTaskSwarmProofService)->analyze([[
            'round' => 0,
            'enqueued' => [$this->spec('p1', ['app/A/One.php'])],
            'observations' => [$this->served('c1', 'ghost-packet')],
        ]]);

        $this->assertFalse($xray['passed']);
        $this->assertCount(1, $xray['phantom_serves']);
        $this->assertSame('ghost-packet', $xray['phantom_serves'][0]['task_packet_id']);
    }

    public function test_analysis_is_deterministic(): void
    {
        $rounds = [[
            'round' => 0,
            'enqueued' => [$this->spec('p1', ['app/A/One.php']), $this->spec('p2', ['app/B/Two.php'])],
            'observations' => [$this->served('c1', 'p1'), $this->served('c2', 'p2'), $this->empty('c3')],
        ]];
        $svc = new AtlasTaskSwarmProofService;

        $this->assertSame(json_encode($svc->analyze($rounds)), json_encode($svc->analyze($rounds)));
    }
}
