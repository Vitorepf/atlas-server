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

    public function test_task_fabric_merge_execution_edge_is_forbidden(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Task Fabric', 'non_authority' => ['x'], 'integrations' => [
                ['from' => 'Task Fabric', 'to' => 'Merge Governor', 'action' => 'execute_merge'],
            ]],
        ]);
        $this->assertSame('task_fabric_may_not_merge', $r['forbidden_edges'][0]['reason']);
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

    public function test_duplicate_allowed_edges_collapse_to_one(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Task Fabric', 'non_authority' => ['x'], 'integrations' => [
                ['from' => 'Task Fabric', 'to' => 'Task Fabric', 'action' => 'self_notify'],
                ['from' => 'Task Fabric', 'to' => 'Task Fabric', 'action' => 'self_notify'],
            ]],
        ]);
        $this->assertCount(1, $r['allowed_edges']);
    }

    public function test_duplicate_forbidden_edges_collapse_to_one_preserving_reason(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Worker Swarm', 'non_authority' => ['x'], 'integrations' => [
                ['from' => 'Worker Swarm', 'to' => 'Merge Governor', 'action' => 'execute_merge'],
                ['from' => 'Worker Swarm', 'to' => 'Merge Governor', 'action' => 'execute_merge'],
            ]],
        ]);
        $this->assertCount(1, $r['forbidden_edges']);
        $this->assertSame('workers_may_not_merge', $r['forbidden_edges'][0]['reason']);
    }

    public function test_integration_referencing_undeclared_organ_adds_boundary_risk(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Task Fabric', 'non_authority' => ['x'], 'integrations' => [
                ['from' => 'Task Fabric', 'to' => 'Ghost Organ', 'action' => 'call'],
            ]],
        ]);
        $risksStr = implode('|', $r['boundary_risks']);
        $this->assertStringContainsString('Ghost Organ', $risksStr);
    }

    public function test_organ_profiles_keyed_by_organ_with_all_required_fields(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Task Fabric', 'non_authority' => ['cannot verify'], 'responsibilities' => ['originate packets'], 'integrations' => []],
        ]);
        $this->assertArrayHasKey('organ_profiles', $r);
        $this->assertArrayHasKey('Task Fabric', $r['organ_profiles']);
        $profile = $r['organ_profiles']['Task Fabric'];
        foreach (['responsibilities', 'non_authority', 'inbound_edges', 'outbound_edges', 'shared_artifacts_touched', 'boundary_risks', 'next_repair_hint'] as $key) {
            $this->assertArrayHasKey($key, $profile, "organ_profiles[Task Fabric] must have {$key}");
        }
        $this->assertSame(['originate packets'], $profile['responsibilities']);
        $this->assertSame(['cannot verify'], $profile['non_authority']);
        $this->assertIsString($profile['next_repair_hint']);
    }

    public function test_organ_profiles_inbound_and_outbound_edges_are_correct(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Task Fabric', 'non_authority' => ['x'], 'integrations' => [
                ['from' => 'Task Fabric', 'to' => 'Worker Swarm', 'action' => 'enqueue'],
            ]],
            ['organ' => 'Worker Swarm', 'non_authority' => ['y'], 'integrations' => []],
        ]);
        $tf = $r['organ_profiles']['Task Fabric'];
        $ws = $r['organ_profiles']['Worker Swarm'];

        $this->assertCount(1, $tf['outbound_edges']);
        $this->assertSame('enqueue', $tf['outbound_edges'][0]['action']);
        $this->assertSame([], $tf['inbound_edges']);

        $this->assertCount(1, $ws['inbound_edges']);
        $this->assertSame('enqueue', $ws['inbound_edges'][0]['action']);
        $this->assertSame([], $ws['outbound_edges']);
    }

    public function test_organ_profiles_boundary_risks_includes_forbidden_edge_for_organ(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Worker Swarm', 'non_authority' => ['x'], 'integrations' => [
                ['from' => 'Worker Swarm', 'to' => 'Merge Governor', 'action' => 'execute_merge'],
            ]],
        ]);
        $profile = $r['organ_profiles']['Worker Swarm'];
        $risksStr = implode('|', $profile['boundary_risks']);
        $this->assertStringContainsString('forbidden_edge:', $risksStr);
        $this->assertStringContainsString('execute_merge', $risksStr);
    }

    public function test_organ_profiles_missing_non_authority_surfaces_as_boundary_risk_and_repair_hint(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Task Fabric', 'integrations' => []],
        ]);
        $profile = $r['organ_profiles']['Task Fabric'];
        $this->assertContains('missing_non_authority', $profile['boundary_risks']);
        $this->assertStringContainsString('non_authority', $profile['next_repair_hint']);
    }

    public function test_organ_profiles_clean_organ_has_no_repair_needed_hint(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Task Fabric', 'non_authority' => ['cannot merge'], 'integrations' => []],
        ]);
        $this->assertSame('no immediate repair needed', $r['organ_profiles']['Task Fabric']['next_repair_hint']);
    }

    // ── AC1: deterministic boundary entries with owners/shared artifacts across many boundaries ──

    public function test_contracts_crossing_many_boundaries_produce_owners_and_shared_artifacts_per_organ(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Brain', 'non_authority' => ['cannot execute'], 'responsibilities' => ['originate context_pack'], 'integrations' => [
                ['from' => 'Brain', 'to' => 'Task Fabric', 'action' => 'produce_context_pack'],
            ]],
            ['organ' => 'Task Fabric', 'non_authority' => ['cannot merge'], 'responsibilities' => ['build packets'], 'integrations' => [
                ['from' => 'Task Fabric', 'to' => 'Worker Swarm', 'action' => 'enqueue_packet'],
            ]],
            ['organ' => 'Maestro', 'non_authority' => ['cannot self-approve'], 'responsibilities' => ['schedule waves'], 'integrations' => [
                ['from' => 'Maestro', 'to' => 'Worker Swarm', 'action' => 'assign_lane'],
            ]],
            ['organ' => 'Worker Swarm', 'non_authority' => ['cannot merge'], 'responsibilities' => ['implement'], 'integrations' => [
                ['from' => 'Worker Swarm', 'to' => 'Verification Court', 'action' => 'submit_evidence_ledger'],
            ]],
            ['organ' => 'Verification Court', 'non_authority' => ['cannot originate'], 'responsibilities' => ['prove'], 'integrations' => [
                ['from' => 'Verification Court', 'to' => 'Docs', 'action' => 'publish_receipts_index'],
            ]],
            ['organ' => 'Docs', 'non_authority' => ['cannot verify'], 'responsibilities' => ['record'], 'integrations' => []],
        ]);

        foreach (['Brain', 'Task Fabric', 'Maestro', 'Worker Swarm', 'Verification Court', 'Docs'] as $organ) {
            $this->assertArrayHasKey($organ, $r['organ_profiles'], "missing profile for {$organ}");
            $this->assertArrayHasKey('shared_artifacts_touched', $r['organ_profiles'][$organ]);
        }

        $this->assertContains('context_pack', $r['organ_profiles']['Brain']['shared_artifacts_touched']);
        $this->assertContains('evidence_ledger', $r['organ_profiles']['Worker Swarm']['shared_artifacts_touched']);
        $this->assertContains('receipts_index', $r['organ_profiles']['Verification Court']['shared_artifacts_touched']);
    }

    public function test_map_has_no_provider_or_runtime_side_effect_markers_in_source(): void
    {
        $classFile = (new \ReflectionClass(AtlasArchitectureCouncilBoundaryMap::class))->getFileName();
        $src = (string) file_get_contents($classFile);
        foreach (['file_put_contents', 'curl_', 'Http::', 'DB::', 'exec(', 'shell_exec'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "boundary map must not perform I/O: {$forbidden}");
        }
    }

    // ── AC2: forbidden edge violation record has source/target/offending artifact + remediation hint ──

    public function test_forbidden_edge_violation_record_has_source_target_offending_artifact_and_remediation_hint(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Worker Swarm', 'non_authority' => ['x'], 'integrations' => [
                ['from' => 'Worker Swarm', 'to' => 'Merge Governor', 'action' => 'execute_merge'],
            ]],
        ]);

        $violation = $r['forbidden_edges'][0];
        $this->assertSame('Worker Swarm', $violation['source_boundary']);
        $this->assertSame('Merge Governor', $violation['target_boundary']);
        $this->assertSame('execute_merge', $violation['offending_artifact_or_path']);
        $this->assertNotEmpty($violation['remediation_hint']);
        $this->assertStringContainsString('Merge Governor', $violation['remediation_hint']);
    }

    public function test_forbidden_edge_offending_artifact_prefers_shared_artifact_when_action_names_one(): void
    {
        $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Worker Swarm', 'non_authority' => ['x'], 'integrations' => [
                ['from' => 'Worker Swarm', 'to' => 'Verification Court', 'action' => 'grant_verified'],
            ]],
        ]);

        $violation = $r['forbidden_edges'][0];
        $this->assertSame('grant_verified', $violation['offending_artifact_or_path']);
    }

    public function test_all_five_named_forbidden_reasons_have_remediation_hints_preserving_shared_main_execution(): void
    {
        foreach (AtlasArchitectureCouncilBoundaryMap::FORBIDDEN_EDGES as $key => $reason) {
            [$from, $to, $action] = explode('|', $key);
            $r = (new AtlasArchitectureCouncilBoundaryMap)->map([
                ['organ' => $from, 'non_authority' => ['x'], 'integrations' => [
                    ['from' => $from, 'to' => $to, 'action' => $action],
                ]],
            ]);

            $violation = $r['forbidden_edges'][0];
            $this->assertNotEmpty($violation['remediation_hint'], "missing remediation_hint for reason {$reason}");
        }
    }

    // ── AC3: stable ordering regardless of input contract order ─────────────────

    public function test_boundary_map_output_is_identical_regardless_of_contract_input_order(): void
    {
        $contractsInOrderA = [
            ['organ' => 'Worker Swarm', 'non_authority' => ['x'], 'responsibilities' => ['implement'], 'integrations' => [
                ['from' => 'Worker Swarm', 'to' => 'Merge Governor', 'action' => 'execute_merge'],
                ['from' => 'Worker Swarm', 'to' => 'Verification Court', 'action' => 'submit_evidence'],
            ]],
            ['organ' => 'Task Fabric', 'non_authority' => ['y'], 'responsibilities' => ['originate'], 'integrations' => [
                ['from' => 'Task Fabric', 'to' => 'Worker Swarm', 'action' => 'enqueue_packet'],
            ]],
        ];
        $contractsInOrderB = array_reverse($contractsInOrderA);

        $resultA = (new AtlasArchitectureCouncilBoundaryMap)->map($contractsInOrderA);
        $resultB = (new AtlasArchitectureCouncilBoundaryMap)->map($contractsInOrderB);

        $this->assertSame(json_encode($resultA), json_encode($resultB));
    }

    public function test_forbidden_edges_ordering_stable_across_reordered_integrations(): void
    {
        $r1 = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Worker Swarm', 'non_authority' => ['x'], 'integrations' => [
                ['from' => 'Worker Swarm', 'to' => 'Merge Governor', 'action' => 'execute_merge'],
                ['from' => 'Worker Swarm', 'to' => 'Verification Court', 'action' => 'grant_verified'],
            ]],
        ]);
        $r2 = (new AtlasArchitectureCouncilBoundaryMap)->map([
            ['organ' => 'Worker Swarm', 'non_authority' => ['x'], 'integrations' => [
                ['from' => 'Worker Swarm', 'to' => 'Verification Court', 'action' => 'grant_verified'],
                ['from' => 'Worker Swarm', 'to' => 'Merge Governor', 'action' => 'execute_merge'],
            ]],
        ]);

        $this->assertSame(
            array_column($r1['forbidden_edges'], 'reason'),
            array_column($r2['forbidden_edges'], 'reason'),
        );
    }
}
