<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Compounding;

use App\Services\Ai\SelfConstruction\Compounding\AtlasSelfConstructionNextFrontierSelector;
use Tests\TestCase;

final class AtlasSelfConstructionNextFrontierSelectorTest extends TestCase
{
    public function test_no_signals_yields_empty_frontier(): void
    {
        $verdict = (new AtlasSelfConstructionNextFrontierSelector)->select([], [], [], []);
        $this->assertSame([], $verdict['frontier']);
    }

    public function test_blocker_removal_comes_before_coverage_completion(): void
    {
        $verdict = (new AtlasSelfConstructionNextFrontierSelector)->select(
            ['deltas' => ['capability_coverage' => 2]],
            [['organ' => 'verification_court', 'blocker_id' => 'flaky_test']],
            ['cortex'],
            [],
        );

        $this->assertSame(AtlasSelfConstructionNextFrontierSelector::KIND_BLOCKER_REMOVAL, $verdict['frontier'][0]['kind']);
        $this->assertSame(AtlasSelfConstructionNextFrontierSelector::KIND_COVERAGE_COMPLETION, $verdict['frontier'][1]['kind']);
    }

    public function test_blocker_rationale_signals_high_leverage_when_capability_grew(): void
    {
        $verdict = (new AtlasSelfConstructionNextFrontierSelector)->select(
            ['deltas' => ['capability_coverage' => 5]],
            [['organ' => 'verification_court', 'blocker_id' => 'b1']],
            [],
            [],
        );

        $this->assertStringContainsString('high-leverage removal', $verdict['frontier'][0]['rationale']);
    }

    public function test_coverage_completion_includes_required_gate(): void
    {
        $verdict = (new AtlasSelfConstructionNextFrontierSelector)->select([], [], ['cortex'], []);
        $this->assertContains('organ_contract_gate', $verdict['frontier'][0]['required_gates']);
        $this->assertSame('cortex', $verdict['frontier'][0]['owner_organ']);
    }

    public function test_lesson_consolidation_requires_repeat_count_at_least_two(): void
    {
        $verdict = (new AtlasSelfConstructionNextFrontierSelector)->select(
            [],
            [],
            [],
            [['class' => 'scope_gap', 'repeat_count' => 1]],
        );
        $this->assertSame([], $verdict['frontier'], 'single repeat is not yet a frontier');

        $verdict2 = (new AtlasSelfConstructionNextFrontierSelector)->select(
            [],
            [],
            [],
            [['class' => 'scope_gap', 'repeat_count' => 3]],
        );
        $this->assertSame(AtlasSelfConstructionNextFrontierSelector::KIND_LESSON_CONSOLIDATION, $verdict2['frontier'][0]['kind']);
        $this->assertSame('learning_transfer', $verdict2['frontier'][0]['owner_organ']);
    }

    public function test_priority_order_is_deterministic(): void
    {
        $verdict = (new AtlasSelfConstructionNextFrontierSelector)->select(
            ['deltas' => ['capability_coverage' => 1]],
            [['organ' => 'a', 'blocker_id' => 'b1'], ['organ' => 'b', 'blocker_id' => 'b2']],
            ['z_organ', 'a_organ'],
            [['class' => 'forbidden_target', 'repeat_count' => 5]],
        );

        $priorities = array_column($verdict['frontier'], 'priority_class');
        $sorted = $priorities;
        sort($sorted);
        $this->assertSame($sorted, $priorities, 'priority_class must be non-decreasing across the frontier');
    }

    public function test_each_frontier_row_has_rationale_required_evidence_and_next_packet_lane(): void
    {
        $verdict = (new AtlasSelfConstructionNextFrontierSelector)->select(
            ['deltas' => ['capability_coverage' => 2]],
            [['organ' => 'verif', 'blocker_id' => 'b1']],
            ['cortex'],
            [['class' => 'scope_gap', 'repeat_count' => 2]],
        );

        foreach ($verdict['frontier'] as $row) {
            $this->assertNotEmpty($row['rationale'], 'rationale must be non-empty');
            $this->assertIsArray($row['required_evidence']);
            $this->assertNotEmpty($row['required_evidence'], 'required_evidence must be non-empty');
            $this->assertIsString($row['next_packet_lane']);
            $this->assertNotEmpty($row['next_packet_lane'], 'next_packet_lane must be non-empty');
        }

        $byKind = array_column($verdict['frontier'], 'next_packet_lane', 'kind');
        $this->assertSame('self_construction_blocker_removal', $byKind[AtlasSelfConstructionNextFrontierSelector::KIND_BLOCKER_REMOVAL]);
        $this->assertSame('self_construction_coverage', $byKind[AtlasSelfConstructionNextFrontierSelector::KIND_COVERAGE_COMPLETION]);
        $this->assertSame('self_construction_lesson_consolidation', $byKind[AtlasSelfConstructionNextFrontierSelector::KIND_LESSON_CONSOLIDATION]);
    }

    public function test_selector_never_creates_executable_packets(): void
    {
        $verdict = (new AtlasSelfConstructionNextFrontierSelector)->select(
            [],
            [['organ' => 'x', 'blocker_id' => 'y']],
            [],
            [],
        );
        foreach ($verdict['frontier'] as $row) {
            $this->assertArrayNotHasKey('task_packet_id', $row, 'frontier rows must NOT carry executable packet ids');
            $this->assertArrayNotHasKey('execute', $row);
        }
    }

    // --- chain_frontier_proof tests ---

    public function test_blocker_removal_row_has_chain_frontier_proof(): void
    {
        $verdict = (new AtlasSelfConstructionNextFrontierSelector)->select(
            ['deltas' => ['capability_coverage' => 2]],
            [['organ' => 'Task Fabric', 'blocker_id' => 'BLK-001']],
            [], [],
        );

        $row   = $verdict['frontier'][0];
        $proof = $row['chain_frontier_proof'];

        $this->assertArrayHasKey('upstream_signal',           $proof);
        $this->assertArrayHasKey('downstream_unlock',         $proof);
        $this->assertArrayHasKey('why_not_cosmetic',          $proof);
        $this->assertArrayHasKey('expected_compounding_effect', $proof);
        $this->assertArrayHasKey('next_task_family',          $proof);

        $this->assertStringContainsString('BLK-001', $proof['upstream_signal']);
        $this->assertStringContainsString('Task Fabric', $proof['upstream_signal']);
        $this->assertNotEmpty($proof['why_not_cosmetic']);
        $this->assertSame('self_construction_blocker_removal', $proof['next_task_family']);
    }

    public function test_coverage_completion_row_has_chain_frontier_proof(): void
    {
        $verdict = (new AtlasSelfConstructionNextFrontierSelector)->select(
            [], [], ['Maestro'], [],
        );

        $row   = $verdict['frontier'][0];
        $proof = $row['chain_frontier_proof'];

        $this->assertStringContainsString('Maestro', $proof['upstream_signal']);
        $this->assertStringContainsString('Maestro', $proof['downstream_unlock']);
        $this->assertNotEmpty($proof['why_not_cosmetic']);
        $this->assertSame('self_construction_coverage', $proof['next_task_family']);
    }

    public function test_lesson_consolidation_row_has_chain_frontier_proof(): void
    {
        $verdict = (new AtlasSelfConstructionNextFrontierSelector)->select(
            [], [], [],
            [['class' => 'missing_impl_file', 'repeat_count' => 3]],
        );

        $row   = $verdict['frontier'][0];
        $proof = $row['chain_frontier_proof'];

        $this->assertStringContainsString('missing_impl_file', $proof['upstream_signal']);
        $this->assertStringContainsString('3', $proof['upstream_signal']);
        $this->assertNotEmpty($proof['why_not_cosmetic']);
        $this->assertSame('self_construction_lesson_consolidation', $proof['next_task_family']);
    }

    public function test_no_signals_returns_empty_frontier_no_fabricated_cosmetic(): void
    {
        $verdict = (new AtlasSelfConstructionNextFrontierSelector)->select([], [], [], []);

        $this->assertSame([], $verdict['frontier']);
    }

    public function test_chain_proof_next_task_family_never_empty(): void
    {
        $selector = new AtlasSelfConstructionNextFrontierSelector;

        $verdictB = $selector->select([], [['organ' => 'Forge', 'blocker_id' => 'x']], [], []);
        $verdictC = $selector->select([], [], ['SomeOrgan'], []);
        $verdictL = $selector->select([], [], [], [['class' => 'foo', 'repeat_count' => 5]]);

        foreach ([$verdictB, $verdictC, $verdictL] as $v) {
            foreach ($v['frontier'] as $row) {
                $this->assertNotEmpty($row['chain_frontier_proof']['next_task_family']);
                $this->assertNotEmpty($row['chain_frontier_proof']['why_not_cosmetic']);
            }
        }
    }
}
