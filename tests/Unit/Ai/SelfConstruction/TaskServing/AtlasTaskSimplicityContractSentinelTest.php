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

    // --- evaluateBatch tests ---

    private function specRecord(string $id, array $allowedFiles, string $objective = 'implement foo'): array
    {
        return ['task_packet' => ['task_packet_id' => $id, 'allowed_files' => $allowedFiles, 'objective' => $objective]];
    }

    public function test_evaluate_batch_flags_broad_allowed_files(): void
    {
        $files = array_map(fn ($i) => "app/Services/Svc{$i}.php", range(1, AtlasTaskSimplicityContractAuditor::OVER_BROAD_FILE_THRESHOLD + 1));
        $result = $this->sentinel()->evaluateBatch([$this->specRecord('broad-1', $files)]);

        $this->assertContains('broad-1', $result['broad_scope']);
        $this->assertSame(0, count($result['test_only']));
    }

    public function test_evaluate_batch_flags_repeated_template_objectives(): void
    {
        $sameObj = 'generate the scaffold class';
        $result = $this->sentinel()->evaluateBatch([
            $this->specRecord('r-1', ['app/A.php', 'tests/ATest.php'], $sameObj),
            $this->specRecord('r-2', ['app/B.php', 'tests/BTest.php'], $sameObj),
        ]);

        $this->assertNotEmpty($result['repeated_objectives']);
        $ids = $result['repeated_objectives'][0];
        $this->assertContains('r-1', $ids);
        $this->assertContains('r-2', $ids);
    }

    public function test_evaluate_batch_flags_test_only_allowed_files(): void
    {
        $result = $this->sentinel()->evaluateBatch([
            $this->specRecord('to-1', ['tests/Unit/FooTest.php']),
        ]);

        $this->assertContains('to-1', $result['test_only']);
    }

    public function test_evaluate_batch_flags_missing_implementation_file(): void
    {
        $result = $this->sentinel()->evaluateBatch([
            $this->specRecord('nim-1', []),
        ]);

        $this->assertContains('nim-1', $result['no_impl_file']);
    }

    public function test_evaluate_batch_passes_diverse_app_plus_test_batch(): void
    {
        $records = [
            $this->specRecord('d-1', ['app/Services/Foo.php', 'tests/Unit/FooTest.php'], 'implement Foo service'),
            $this->specRecord('d-2', ['app/Services/Bar.php', 'tests/Unit/BarTest.php'], 'implement Bar service'),
        ];
        $result = $this->sentinel()->evaluateBatch($records);

        $this->assertSame([], $result['broad_scope']);
        $this->assertSame([], $result['test_only']);
        $this->assertSame([], $result['no_impl_file']);
        $this->assertSame([], $result['repeated_objectives']);
        $this->assertSame(0, $result['defect_count']);
        $this->assertArrayNotHasKey('score', $result);
    }

    public function test_evaluate_batch_returns_no_scalar_score(): void
    {
        $result = $this->sentinel()->evaluateBatch([$this->specRecord('x-1', ['app/X.php', 'tests/XTest.php'])]);
        $this->assertArrayNotHasKey('score', $result);
        $this->assertFalse($result['mutates_queue']);
    }
}
