<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskServing;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskSimplicityContractAuditor;
use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskSimplicityContractSentinel;
use Tests\TestCase;

final class AtlasTaskSimplicityContractSentinelTest extends TestCase
{
    private function sentinel(): AtlasTaskSimplicityContractSentinel
    {
        return new AtlasTaskSimplicityContractSentinel(new AtlasTaskSimplicityContractAuditor);
    }

    private function conformingRecord(string $id): array
    {
        return [
            'task_packet' => [
                'task_packet_id' => $id,
                'simplicity_contract' => AgentControlPlaneTaskPacketBuilder::defaultSimplicityContract(),
            ],
        ];
    }

    private function missingRecord(string $id): array
    {
        return ['task_packet' => ['task_packet_id' => $id]];
    }

    private function driftedRecord(string $id): array
    {
        $contract = AgentControlPlaneTaskPacketBuilder::defaultSimplicityContract();
        $contract['final_runtime_owner'] = 'external_provider';

        return ['task_packet' => ['task_packet_id' => $id, 'simplicity_contract' => $contract]];
    }

    public function test_passes_when_all_records_conform(): void
    {
        $verdict = $this->sentinel()->check([
            $this->conformingRecord('a'),
            $this->conformingRecord('b'),
        ]);

        $this->assertTrue($verdict['passed']);
        $this->assertSame('pass', $verdict['status']);
        $this->assertSame(2, $verdict['inspected_count']);
        $this->assertSame(0, $verdict['missing_count']);
        $this->assertSame(0, $verdict['drift_count']);
        $this->assertSame([], $verdict['blockers']);
        $this->assertSame([], $verdict['sample_findings']);
    }

    public function test_fails_when_contract_missing(): void
    {
        $verdict = $this->sentinel()->check([$this->missingRecord('miss-1')]);

        $this->assertFalse($verdict['passed']);
        $this->assertSame(1, $verdict['missing_count']);
        $this->assertContains('simplicity_contract_missing_in_claimable_packets', $verdict['blockers']);
        $this->assertNotEmpty($verdict['sample_findings']);
    }

    public function test_fails_when_contract_drifted(): void
    {
        $verdict = $this->sentinel()->check([$this->driftedRecord('drift-1')]);

        $this->assertFalse($verdict['passed']);
        $this->assertSame(1, $verdict['drift_count']);
        $this->assertContains('simplicity_contract_drifted_in_claimable_packets', $verdict['blockers']);
    }

    public function test_sample_findings_are_bounded_by_sample_limit(): void
    {
        $records = [];
        for ($i = 0; $i < AtlasTaskSimplicityContractSentinel::SAMPLE_LIMIT + 5; $i++) {
            $records[] = $this->missingRecord('miss-'.$i);
        }

        $verdict = $this->sentinel()->check($records);

        $this->assertFalse($verdict['passed']);
        $this->assertSame(AtlasTaskSimplicityContractSentinel::SAMPLE_LIMIT + 5, $verdict['missing_count']);
        $this->assertCount(AtlasTaskSimplicityContractSentinel::SAMPLE_LIMIT, $verdict['sample_findings']);
        $this->assertSame(AtlasTaskSimplicityContractSentinel::SAMPLE_LIMIT + 5, $verdict['proof_summary']['failure_count']);
    }

    public function test_empty_queue_passes_with_zero_inspected_count(): void
    {
        $verdict = $this->sentinel()->check([]);

        $this->assertTrue($verdict['passed']);
        $this->assertSame(0, $verdict['inspected_count']);
        $this->assertSame(0, $verdict['proof_summary']['records_total']);
        $this->assertSame([], $verdict['blockers']);
    }
}
