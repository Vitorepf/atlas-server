<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskServing;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskSimplicityContractAuditor;
use Tests\TestCase;

final class AtlasTaskSimplicityContractAuditorTest extends TestCase
{
    private function conformingRecord(string $id = 'pkt-conforming', string $wave = 'w1', array $tags = ['ok']): array
    {
        return [
            'wave' => $wave,
            'tags' => $tags,
            'task_packet' => [
                'task_packet_id' => $id,
                'simplicity_contract' => AgentControlPlaneTaskPacketBuilder::defaultSimplicityContract(),
            ],
        ];
    }

    public function test_conforming_record_is_classified_conforming(): void
    {
        $auditor = new AtlasTaskSimplicityContractAuditor;
        $result = $auditor->audit([$this->conformingRecord()]);

        $this->assertSame(1, $result['inspected_count']);
        $this->assertSame(1, $result['conforming_count']);
        $this->assertSame(0, $result['missing_count']);
        $this->assertSame(0, $result['drift_count']);
        $this->assertSame('conforming', $result['findings'][0]['status']);
        $this->assertSame('no_action', $result['findings'][0]['recommended_action']);
        $this->assertSame('pkt-conforming', $result['findings'][0]['task_packet_id']);
        $this->assertSame('w1', $result['findings'][0]['wave']);
        $this->assertSame(['ok'], $result['findings'][0]['tags']);
    }

    public function test_missing_contract_is_classified_missing(): void
    {
        $auditor = new AtlasTaskSimplicityContractAuditor;
        $result = $auditor->audit([
            ['task_packet' => ['task_packet_id' => 'pkt-missing']],
        ]);

        $this->assertSame(1, $result['missing_count']);
        $this->assertSame('missing', $result['findings'][0]['status']);
        $this->assertSame('simplicity_contract_absent', $result['findings'][0]['finding_type']);
        $this->assertSame('rebuild_packet_with_default_contract', $result['findings'][0]['recommended_action']);
        $this->assertNotEmpty($result['findings'][0]['drift_fields']);
    }

    public function test_drifted_record_lists_only_drifted_fields(): void
    {
        $drifted = AgentControlPlaneTaskPacketBuilder::defaultSimplicityContract();
        $drifted['final_runtime_owner'] = 'external_provider';
        $drifted['external_provider_dependency_allowed'] = true;

        $auditor = new AtlasTaskSimplicityContractAuditor;
        $result = $auditor->audit([
            ['task_packet' => ['task_packet_id' => 'pkt-drift', 'simplicity_contract' => $drifted]],
        ]);

        $this->assertSame(1, $result['drift_count']);
        $finding = $result['findings'][0];
        $this->assertSame('drifted', $finding['status']);
        $this->assertSame('drifted_from_default_contract', $finding['finding_type']);
        $this->assertSame('reset_drifted_fields_to_default', $finding['recommended_action']);
        $this->assertEqualsCanonicalizing(['final_runtime_owner', 'external_provider_dependency_allowed'], $finding['drift_fields']);
    }

    public function test_malformed_record_is_skipped(): void
    {
        $auditor = new AtlasTaskSimplicityContractAuditor;
        $result = $auditor->audit(['not-an-array', ['no_task_packet_here' => true]]);

        $this->assertSame(0, $result['inspected_count']);
        $this->assertSame(2, $result['skipped_count']);
        $this->assertSame('skipped', $result['findings'][0]['status']);
        $this->assertSame('malformed_record', $result['findings'][0]['finding_type']);
    }

    public function test_mixed_batch_summary(): void
    {
        $drifted = AgentControlPlaneTaskPacketBuilder::defaultSimplicityContract();
        $drifted['steady_state_requires_operator'] = true;

        $auditor = new AtlasTaskSimplicityContractAuditor;
        $result = $auditor->audit([
            $this->conformingRecord('a'),
            ['task_packet' => ['task_packet_id' => 'b']],
            ['task_packet' => ['task_packet_id' => 'c', 'simplicity_contract' => $drifted]],
            'malformed',
        ]);

        $this->assertSame(3, $result['inspected_count']);
        $this->assertSame(1, $result['conforming_count']);
        $this->assertSame(1, $result['missing_count']);
        $this->assertSame(1, $result['drift_count']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame(4, $result['proof_summary']['records_total']);
        $this->assertSame(AtlasTaskSimplicityContractAuditor::SCHEMA, $result['schema_version']);
    }
}
