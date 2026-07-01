<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionScaffoldRetirementPolicy;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionScaffoldRetirementPolicyTest extends TestCase
{
    private function fullEvidence(): array
    {
        return [
            'consumers_mapped' => true,
            'replacement_capability' => true,
            'replay_proof' => true,
            'rollback_receipt' => true,
            'docs_sync' => true,
        ];
    }

    public function test_keeps_scaffold_that_gates_safety_even_with_full_evidence(): void
    {
        $decision = (new AtlasSelfConstructionScaffoldRetirementPolicy)->decide(
            $this->fullEvidence() + ['gates_safety' => true],
        );

        $this->assertSame('keep', $decision['decision']);
    }

    public function test_keeps_scaffold_that_isolates_risk(): void
    {
        $decision = (new AtlasSelfConstructionScaffoldRetirementPolicy)->decide(['isolates_risk' => true]);

        $this->assertSame('keep', $decision['decision']);
    }

    public function test_keeps_scaffold_with_active_runtime_visibility(): void
    {
        $decision = (new AtlasSelfConstructionScaffoldRetirementPolicy)->decide(['provides_active_runtime_visibility' => true]);

        $this->assertSame('keep', $decision['decision']);
    }

    public function test_retires_when_no_active_role_and_full_evidence_present(): void
    {
        $decision = (new AtlasSelfConstructionScaffoldRetirementPolicy)->decide($this->fullEvidence());

        $this->assertSame('retire', $decision['decision']);
        $this->assertSame([], $decision['missing_evidence']);
    }

    public function test_merges_when_merge_target_supplied_with_full_evidence(): void
    {
        $decision = (new AtlasSelfConstructionScaffoldRetirementPolicy)->decide(
            $this->fullEvidence() + ['mergeable_with' => 'AtlasFooOrgan'],
        );

        $this->assertSame('merge', $decision['decision']);
        $this->assertSame('consolidates_into:AtlasFooOrgan', $decision['reason']);
    }

    public function test_needs_evidence_when_replay_proof_missing(): void
    {
        $evidence = $this->fullEvidence();
        $evidence['replay_proof'] = false;

        $decision = (new AtlasSelfConstructionScaffoldRetirementPolicy)->decide($evidence);

        $this->assertSame('needs_evidence', $decision['decision']);
        $this->assertContains('replay_proof', $decision['missing_evidence']);
    }

    public function test_needs_evidence_lists_all_missing_facts(): void
    {
        $decision = (new AtlasSelfConstructionScaffoldRetirementPolicy)->decide([]);

        $this->assertSame('needs_evidence', $decision['decision']);
        $this->assertSame(
            ['consumers_mapped', 'replacement_capability', 'replay_proof', 'rollback_receipt', 'docs_sync'],
            $decision['missing_evidence'],
        );
    }
}
