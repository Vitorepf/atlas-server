<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationProofCoverageIndex;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationProofCoverageIndexTest extends TestCase
{
    public function test_fully_proven_target_is_safe_for_consolidation(): void
    {
        $result = (new AtlasSelfConstructionSimplificationProofCoverageIndex)->build([
            [
                'name' => 'FooOrgan',
                'test_proof' => true,
                'replay_proof' => true,
                'runtime_proof' => false,
                'docs_proof' => true,
                'rollback_proof' => true,
            ],
        ]);

        $this->assertTrue($result['safe_for_consolidation']);
        $this->assertTrue($result['coverage_by_target']['FooOrgan']['safe_for_consolidation']);
        $this->assertSame([], $result['required_next_task']);
    }

    public function test_missing_rollback_proof_blocks_consolidation(): void
    {
        $result = (new AtlasSelfConstructionSimplificationProofCoverageIndex)->build([
            [
                'name' => 'FooOrgan',
                'test_proof' => true,
                'replay_proof' => true,
                'runtime_proof' => true,
                'docs_proof' => true,
                'rollback_proof' => false,
            ],
        ]);

        $this->assertFalse($result['safe_for_consolidation']);
        $this->assertContains('FooOrgan:rollback_proof', $result['missing_proofs']);
        $this->assertContains('compose_rollback_receipt:FooOrgan', $result['required_next_task']);
    }

    public function test_missing_behavior_proof_blocks_consolidation(): void
    {
        $result = (new AtlasSelfConstructionSimplificationProofCoverageIndex)->build([
            [
                'name' => 'FooOrgan',
                'test_proof' => false,
                'replay_proof' => false,
                'runtime_proof' => false,
                'docs_proof' => true,
                'rollback_proof' => true,
            ],
        ]);

        $this->assertFalse($result['safe_for_consolidation']);
        $this->assertFalse($result['coverage_by_target']['FooOrgan']['behavior_proof']);
        $this->assertContains('prove_behavior_equivalence:FooOrgan', $result['required_next_task']);
    }

    public function test_multiple_targets_all_must_be_safe(): void
    {
        $result = (new AtlasSelfConstructionSimplificationProofCoverageIndex)->build([
            [
                'name' => 'SafeOrgan',
                'test_proof' => true,
                'replay_proof' => true,
                'runtime_proof' => true,
                'docs_proof' => true,
                'rollback_proof' => true,
            ],
            [
                'name' => 'UnsafeOrgan',
                'test_proof' => false,
                'replay_proof' => false,
                'runtime_proof' => false,
                'docs_proof' => false,
                'rollback_proof' => false,
            ],
        ]);

        $this->assertFalse($result['safe_for_consolidation']);
        $this->assertTrue($result['coverage_by_target']['SafeOrgan']['safe_for_consolidation']);
        $this->assertFalse($result['coverage_by_target']['UnsafeOrgan']['safe_for_consolidation']);
    }

    public function test_empty_targets_is_not_safe_for_consolidation(): void
    {
        $result = (new AtlasSelfConstructionSimplificationProofCoverageIndex)->build([]);

        $this->assertFalse($result['safe_for_consolidation']);
        $this->assertSame([], $result['coverage_by_target']);
    }

    public function test_delete_candidate_without_behavior_proof_is_blocked_for_simplification(): void
    {
        $result = (new AtlasSelfConstructionSimplificationProofCoverageIndex)->build([
            [
                'name' => 'DeadOrgan',
                'action_type' => 'delete',
                'test_proof' => false,
                'replay_proof' => false,
                'runtime_proof' => false,
                'docs_proof' => true,
                'rollback_proof' => true,
            ],
        ]);

        $this->assertTrue($result['coverage_by_target']['DeadOrgan']['blocked_for_simplification']);
    }

    public function test_merge_candidate_with_behavior_proof_is_not_blocked_for_simplification(): void
    {
        $result = (new AtlasSelfConstructionSimplificationProofCoverageIndex)->build([
            [
                'name' => 'MergeableOrgan',
                'action_type' => 'merge',
                'test_proof' => true,
                'replay_proof' => false,
                'runtime_proof' => false,
                'docs_proof' => true,
                'rollback_proof' => true,
            ],
        ]);

        $this->assertFalse($result['coverage_by_target']['MergeableOrgan']['blocked_for_simplification']);
    }

    public function test_non_simplification_action_is_never_blocked_for_simplification(): void
    {
        $result = (new AtlasSelfConstructionSimplificationProofCoverageIndex)->build([
            [
                'name' => 'KeepOrgan',
                'action_type' => 'keep',
                'test_proof' => false,
                'replay_proof' => false,
                'runtime_proof' => false,
                'docs_proof' => false,
                'rollback_proof' => false,
            ],
        ]);

        $this->assertFalse($result['coverage_by_target']['KeepOrgan']['blocked_for_simplification']);
    }

    // ── risk_floor: single-source behavior proof or missing rollback proof ──────

    public function test_single_source_behavior_proof_yields_blocked_risk_floor(): void
    {
        $result = (new AtlasSelfConstructionSimplificationProofCoverageIndex)->build([
            [
                'name' => 'SingleSourceOrgan',
                'test_proof' => true,
                'replay_proof' => false,
                'runtime_proof' => false,
                'docs_proof' => true,
                'rollback_proof' => true,
            ],
        ]);

        $riskFloor = $result['coverage_by_target']['SingleSourceOrgan']['risk_floor'];
        $this->assertSame('blocked', $riskFloor['status']);
        $this->assertSame('behavior_proof_single_source', $riskFloor['reason']);
        $this->assertSame(1, $riskFloor['behavior_proof_source_count']);
        $this->assertFalse($result['coverage_by_target']['SingleSourceOrgan']['safe_for_consolidation']);
        $this->assertContains('diversify_behavior_proof_beyond_single_source:SingleSourceOrgan', $result['required_next_task']);
    }

    public function test_multi_source_behavior_proof_yields_clear_risk_floor(): void
    {
        $result = (new AtlasSelfConstructionSimplificationProofCoverageIndex)->build([
            [
                'name' => 'MultiSourceOrgan',
                'test_proof' => true,
                'replay_proof' => true,
                'runtime_proof' => false,
                'docs_proof' => true,
                'rollback_proof' => true,
            ],
        ]);

        $riskFloor = $result['coverage_by_target']['MultiSourceOrgan']['risk_floor'];
        $this->assertSame('clear', $riskFloor['status']);
        $this->assertNull($riskFloor['reason']);
        $this->assertSame(2, $riskFloor['behavior_proof_source_count']);
        $this->assertTrue($result['coverage_by_target']['MultiSourceOrgan']['safe_for_consolidation']);
    }

    public function test_missing_rollback_proof_yields_blocked_risk_floor_with_named_reason(): void
    {
        $result = (new AtlasSelfConstructionSimplificationProofCoverageIndex)->build([
            [
                'name' => 'NoRollbackOrgan',
                'test_proof' => true,
                'replay_proof' => true,
                'runtime_proof' => true,
                'docs_proof' => true,
                'rollback_proof' => false,
            ],
        ]);

        $riskFloor = $result['coverage_by_target']['NoRollbackOrgan']['risk_floor'];
        $this->assertSame('blocked', $riskFloor['status']);
        $this->assertSame('missing_rollback_proof', $riskFloor['reason']);
    }

    public function test_single_source_and_missing_rollback_yields_combined_risk_floor_reason(): void
    {
        $result = (new AtlasSelfConstructionSimplificationProofCoverageIndex)->build([
            [
                'name' => 'DoublyRiskyOrgan',
                'test_proof' => true,
                'replay_proof' => false,
                'runtime_proof' => false,
                'docs_proof' => false,
                'rollback_proof' => false,
            ],
        ]);

        $riskFloor = $result['coverage_by_target']['DoublyRiskyOrgan']['risk_floor'];
        $this->assertSame('blocked', $riskFloor['status']);
        $this->assertSame('missing_rollback_proof_and_single_source_behavior_proof', $riskFloor['reason']);
        $this->assertContains('diversify_behavior_proof_beyond_single_source:DoublyRiskyOrgan', $result['required_next_task']);
        $this->assertContains('compose_rollback_receipt:DoublyRiskyOrgan', $result['required_next_task']);
    }

    public function test_zero_source_behavior_proof_risk_floor_is_clear_since_missing_proof_already_covered_elsewhere(): void
    {
        $result = (new AtlasSelfConstructionSimplificationProofCoverageIndex)->build([
            [
                'name' => 'NoProofOrgan',
                'test_proof' => false,
                'replay_proof' => false,
                'runtime_proof' => false,
                'docs_proof' => true,
                'rollback_proof' => true,
            ],
        ]);

        $riskFloor = $result['coverage_by_target']['NoProofOrgan']['risk_floor'];
        $this->assertSame(0, $riskFloor['behavior_proof_source_count']);
        $this->assertSame('clear', $riskFloor['status']);
        $this->assertContains('prove_behavior_equivalence:NoProofOrgan', $result['required_next_task']);
    }
}
