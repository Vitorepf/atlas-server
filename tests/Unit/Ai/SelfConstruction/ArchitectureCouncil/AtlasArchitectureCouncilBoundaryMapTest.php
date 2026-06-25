<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ArchitectureCouncil;

use App\Services\Ai\SelfConstruction\ArchitectureCouncil\AtlasArchitectureCouncilBoundaryMap;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasArchitectureCouncilBoundaryMap: a clean government map produces allowed_edges + zero
 * forbidden_edges; a worker→merge edge is forbidden (workers_may_not_merge); a Strategy→Worker
 * create_packet edge is forbidden; a missing organ contract surfaces as boundary risk; edges and
 * organs are sorted byte-stably.
 */
final class AtlasArchitectureCouncilBoundaryMapTest extends TestCase
{
    public function test_valid_government_map_produces_allowed_edges_only(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Task Fabric', 'non_authority' => ['cannot verify'], 'integrations' => [
                ['from' => 'Task Fabric', 'to' => 'Worker Swarm', 'action' => 'enqueue_packet'],
            ]],
            ['organ' => 'Worker Swarm', 'non_authority' => ['cannot merge'], 'integrations' => [
                ['from' => 'Worker Swarm', 'to' => 'Verification Court', 'action' => 'submit_evidence'],
            ]],
        ]);
        $this->assertSame([], $r['forbidden_edges']);
        $this->assertNotEmpty($r['allowed_edges']);
    }

    public function test_worker_merge_edge_is_forbidden_with_named_reason(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Worker Swarm', 'non_authority' => ['x'], 'integrations' => [
                ['from' => 'Worker Swarm', 'to' => 'Merge Governor', 'action' => 'execute_merge'],
            ]],
        ]);
        $this->assertCount(1, $r['forbidden_edges']);
        $this->assertSame('workers_may_not_merge', $r['forbidden_edges'][0]['reason']);
    }

    public function test_worker_grant_verified_edge_is_forbidden(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Worker Swarm', 'non_authority' => ['x'], 'integrations' => [
                ['from' => 'Worker Swarm', 'to' => 'Verification Court', 'action' => 'grant_verified'],
            ]],
        ]);
        $this->assertSame('workers_may_not_grant_final_verification', $r['forbidden_edges'][0]['reason']);
    }

    public function test_task_fabric_approve_merit_edge_is_forbidden(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Task Fabric', 'non_authority' => ['x'], 'integrations' => [
                ['from' => 'Task Fabric', 'to' => 'Verification Court', 'action' => 'approve_merit'],
            ]],
        ]);
        $this->assertSame('task_fabric_may_not_approve_merit', $r['forbidden_edges'][0]['reason']);
    }

    public function test_strategy_to_worker_create_packet_edge_is_forbidden(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Strategy Council', 'non_authority' => ['x'], 'integrations' => [
                ['from' => 'Strategy Council', 'to' => 'Worker Swarm', 'action' => 'create_packet'],
            ]],
        ]);
        $this->assertSame('strategy_may_not_create_executable_packets_directly', $r['forbidden_edges'][0]['reason']);
    }

    public function test_missing_organ_contract_surfaces_as_boundary_risk(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => '', 'integrations' => []],
        ]);
        $this->assertNotEmpty($r['boundary_risks']);
        $this->assertStringContainsString('missing_organ', $r['boundary_risks'][0]);
    }

    public function test_edges_sorted_byte_stably(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Z', 'non_authority' => ['x'], 'integrations' => [
                ['from' => 'Z', 'to' => 'B', 'action' => 'x'],
                ['from' => 'A', 'to' => 'B', 'action' => 'x'],
            ]],
        ]);
        $froms = array_column($r['allowed_edges'], 'from');
        $this->assertSame(['A', 'Z'], $froms);
    }

    public function test_envelope_carries_shared_artifacts_list(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([]);
        $this->assertContains('evidence_ledger', $r['shared_artifacts']);
        $this->assertContains('receipts_index', $r['shared_artifacts']);
    }
}
