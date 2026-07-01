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
}
