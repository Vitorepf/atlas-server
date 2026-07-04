<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionCircuitCollapseAdvisor;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionCircuitCollapseAdvisorTest extends TestCase
{
    private AtlasSelfConstructionCircuitCollapseAdvisor $advisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->advisor = new AtlasSelfConstructionCircuitCollapseAdvisor();
    }

    // AC 2: deletion-first advice beats adapter-layer advice when both preserve behavior
    public function test_deletion_beats_adapter_when_both_preserve_behavior(): void
    {
        $result = $this->advisor->advise([
            'target' => 'OldService',
            'boundary' => ['safe_to_collapse' => true],
            'equivalence_verdict' => 'safe_to_consolidate',
            'candidates' => [
                ['strategy' => 'adapter', 'diff_size' => 5, 'preserves_behavior' => true],
                ['strategy' => 'delete', 'diff_size' => 10, 'preserves_behavior' => true],
            ],
        ]);

        $this->assertSame('delete', $result['strategy']);
    }

    // AC 3: runtime dispatch collapses require rollback_gate and proof_command
    public function test_runtime_dispatch_requires_rollback_gate_and_proof(): void
    {
        $result = $this->advisor->advise([
            'target' => 'DispatchRouter',
            'boundary' => ['safe_to_collapse' => true],
            'touches_runtime_dispatch' => true,
            'equivalence_verdict' => 'safe_to_consolidate',
            'candidates' => [
                ['strategy' => 'delete', 'diff_size' => 3, 'preserves_behavior' => true],
            ],
        ]);

        $this->assertNotNull($result['rollback_gate']);
        $this->assertNotNull($result['proof_command']);
    }

    public function test_non_runtime_dispatch_does_not_require_gate(): void
    {
        $result = $this->advisor->advise([
            'target' => 'SimpleClass',
            'boundary' => ['safe_to_collapse' => true],
            'touches_runtime_dispatch' => false,
            'equivalence_verdict' => 'safe_to_consolidate',
            'candidates' => [
                ['strategy' => 'delete', 'diff_size' => 3, 'preserves_behavior' => true],
            ],
        ]);

        $this->assertNull($result['rollback_gate']);
        $this->assertNull($result['proof_command']);
    }

    // AC 4: collapse rejected when equivalence evidence is inconclusive
    public function test_rejected_when_equivalence_inconclusive(): void
    {
        $result = $this->advisor->advise([
            'target' => 'SomeService',
            'equivalence_verdict' => 'inconclusive',
            'candidates' => [
                ['strategy' => 'delete', 'diff_size' => 3, 'preserves_behavior' => true],
            ],
        ]);

        $this->assertSame('reject', $result['strategy']);
    }

    public function test_rejected_when_equivalence_unsafe(): void
    {
        $result = $this->advisor->advise([
            'target' => 'SomeService',
            'equivalence_verdict' => 'unsafe',
            'candidates' => [
                ['strategy' => 'delete', 'diff_size' => 3, 'preserves_behavior' => true],
            ],
        ]);

        $this->assertSame('reject', $result['strategy']);
    }

    public function test_rejected_when_no_safe_candidates(): void
    {
        $result = $this->advisor->advise([
            'target' => 'SomeService',
            'equivalence_verdict' => 'safe_to_consolidate',
            'candidates' => [
                ['strategy' => 'delete', 'diff_size' => 3, 'preserves_behavior' => false],
            ],
        ]);

        $this->assertSame('reject', $result['strategy']);
    }

    public function test_smallest_diff_among_same_strategy(): void
    {
        $result = $this->advisor->advise([
            'target' => 'SomeService',
            'boundary' => ['safe_to_collapse' => true],
            'equivalence_verdict' => 'safe_to_consolidate',
            'candidates' => [
                ['strategy' => 'adapter', 'diff_size' => 20, 'preserves_behavior' => true],
                ['strategy' => 'adapter', 'diff_size' => 5, 'preserves_behavior' => true],
            ],
        ]);

        $this->assertSame('adapter', $result['strategy']);
        $this->assertStringContainsString('diff_size=5', $result['recommended_diff']);
    }

    // ── AC2: boundary.safe_to_collapse is required — blocks when false or missing ──

    public function test_boundary_safe_to_collapse_false_blocks_even_with_full_evidence(): void
    {
        $result = $this->advisor->advise([
            'target' => 'FullyProvenService',
            'boundary' => ['safe_to_collapse' => false],
            'touches_runtime_dispatch' => true,
            'equivalence_verdict' => 'safe_to_consolidate',
            'candidates' => [
                ['strategy' => 'delete', 'diff_size' => 3, 'preserves_behavior' => true],
            ],
        ]);

        $this->assertSame('reject', $result['strategy']);
        $this->assertContains('boundary_not_safe_to_collapse', $result['reasons']);
        $this->assertNull($result['rollback_gate']);
        $this->assertNull($result['proof_command']);
    }

    public function test_missing_boundary_key_blocks_with_boundary_not_safe_to_collapse(): void
    {
        $result = $this->advisor->advise([
            'target' => 'SomeService',
            'equivalence_verdict' => 'safe_to_consolidate',
            'candidates' => [
                ['strategy' => 'delete', 'diff_size' => 3, 'preserves_behavior' => true],
            ],
        ]);

        $this->assertSame('reject', $result['strategy']);
        $this->assertContains('boundary_not_safe_to_collapse', $result['reasons']);
        $this->assertNull($result['rollback_gate']);
        $this->assertNull($result['proof_command']);
    }

    // ── AC4: safe_to_collapse=true still returns delete/merge with full evidence ──

    public function test_boundary_safe_to_collapse_true_with_full_evidence_returns_delete(): void
    {
        $result = $this->advisor->advise([
            'target' => 'SafeBoundaryService',
            'boundary' => ['safe_to_collapse' => true],
            'touches_runtime_dispatch' => true,
            'equivalence_verdict' => 'safe_to_consolidate',
            'candidates' => [
                ['strategy' => 'adapter', 'diff_size' => 10, 'preserves_behavior' => true],
                ['strategy' => 'delete', 'diff_size' => 5, 'preserves_behavior' => true],
            ],
        ]);

        // Deletion preferred over adapter when both safe.
        $this->assertSame('delete', $result['strategy']);
        $this->assertNotNull($result['rollback_gate']);
        $this->assertNotNull($result['proof_command']);
    }

    public function test_boundary_safe_to_collapse_true_with_full_evidence_and_adapter_only_returns_adapter(): void
    {
        $result = $this->advisor->advise([
            'target' => 'AdapterOnlyService',
            'boundary' => ['safe_to_collapse' => true],
            'touches_runtime_dispatch' => false,
            'equivalence_verdict' => 'safe_to_consolidate',
            'candidates' => [
                ['strategy' => 'adapter', 'diff_size' => 8, 'preserves_behavior' => true],
            ],
        ]);

        $this->assertSame('adapter', $result['strategy']);
        $this->assertNull($result['rollback_gate']);
        $this->assertNull($result['proof_command']);
    }

    public function test_boundary_not_safe_to_collapse_is_reported_as_single_reason_when_all_other_evidence_passes(): void
    {
        $result = $this->advisor->advise([
            'target' => 'ExplicitlyUnsafe',
            'boundary' => ['safe_to_collapse' => false],
            'equivalence_verdict' => 'safe_to_consolidate',
            'candidates' => [
                ['strategy' => 'delete', 'diff_size' => 1, 'preserves_behavior' => true],
            ],
        ]);

        $this->assertSame('reject', $result['strategy']);
        $this->assertSame(['boundary_not_safe_to_collapse'], $result['reasons']);
    }
}
