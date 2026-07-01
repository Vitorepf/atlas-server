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
            'canonical_owner' => 'AtlasCanonicalOrgan',
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
            ['canonical_owner', 'consumers_mapped', 'replacement_capability', 'replay_proof', 'rollback_receipt', 'docs_sync'],
            $decision['missing_evidence'],
        );
    }

    public function test_wrapper_scaffold_with_no_canonical_owner_is_blocked(): void
    {
        $evidence = $this->fullEvidence();
        $evidence['canonical_owner'] = '';

        $decision = (new AtlasSelfConstructionScaffoldRetirementPolicy)->decide($evidence);

        $this->assertSame('needs_evidence', $decision['decision']);
        $this->assertContains('canonical_owner', $decision['missing_evidence']);
        $this->assertFalse($decision['retire_now']);
    }

    public function test_scaffold_with_owner_parity_rewrite_plan_and_replay_gates_emits_retire_now_and_deletion_evidence(): void
    {
        $decision = (new AtlasSelfConstructionScaffoldRetirementPolicy)->decide($this->fullEvidence());

        $this->assertSame('retire', $decision['decision']);
        $this->assertTrue($decision['retire_now']);
        $this->assertNotEmpty($decision['deletion_evidence']);
    }

    public function test_dormant_organ_without_runtime_proof_is_marked_review_not_auto_delete(): void
    {
        $decision = (new AtlasSelfConstructionScaffoldRetirementPolicy)->decide(
            $this->fullEvidence() + ['dormant' => true, 'runtime_proof' => false],
        );

        $this->assertSame('review', $decision['decision']);
        $this->assertFalse($decision['retire_now']);
    }

    public function test_dormant_organ_with_runtime_proof_follows_normal_evidence_path(): void
    {
        $decision = (new AtlasSelfConstructionScaffoldRetirementPolicy)->decide(
            $this->fullEvidence() + ['dormant' => true, 'runtime_proof' => true],
        );

        $this->assertSame('retire', $decision['decision']);
    }

    // ── AC: live usage ceiling — active scaffolds are not retired blindly ──────

    public function test_scaffold_above_live_usage_ceiling_waits_despite_full_evidence(): void
    {
        $decision = (new AtlasSelfConstructionScaffoldRetirementPolicy)->decide(
            $this->fullEvidence() + ['live_usage_count' => 500, 'live_usage_ceiling' => 100],
        );

        $this->assertSame('wait', $decision['decision']);
        $this->assertFalse($decision['retire_now']);
        $this->assertStringContainsString('live_usage_count=500', $decision['reason']);
    }

    public function test_scaffold_below_live_usage_ceiling_retires_normally(): void
    {
        $decision = (new AtlasSelfConstructionScaffoldRetirementPolicy)->decide(
            $this->fullEvidence() + ['live_usage_count' => 5, 'live_usage_ceiling' => 100],
        );

        $this->assertSame('retire', $decision['decision']);
    }

    public function test_live_usage_fields_are_no_op_when_omitted(): void
    {
        $decision = (new AtlasSelfConstructionScaffoldRetirementPolicy)->decide($this->fullEvidence());

        $this->assertSame('retire', $decision['decision']);
    }

    // ── AC: mature replacements can retire scaffolds ────────────────────────────

    public function test_replacement_below_maturity_floor_waits_despite_full_evidence(): void
    {
        $decision = (new AtlasSelfConstructionScaffoldRetirementPolicy)->decide(
            $this->fullEvidence() + ['replacement_maturity_days' => 3],
        );

        $this->assertSame('wait', $decision['decision']);
        $this->assertStringContainsString('replacement_maturity_days=3', $decision['reason']);
    }

    public function test_mature_replacement_retires_normally(): void
    {
        $decision = (new AtlasSelfConstructionScaffoldRetirementPolicy)->decide(
            $this->fullEvidence() + ['replacement_maturity_days' => 30],
        );

        $this->assertSame('retire', $decision['decision']);
    }

    // ── AC: missing rollback blocks destructive advice ──────────────────────────

    public function test_only_rollback_receipt_missing_blocks_instead_of_generic_needs_evidence(): void
    {
        $evidence = $this->fullEvidence();
        $evidence['rollback_receipt'] = false;

        $decision = (new AtlasSelfConstructionScaffoldRetirementPolicy)->decide($evidence);

        $this->assertSame('block', $decision['decision']);
        $this->assertFalse($decision['retire_now']);
        $this->assertSame(['rollback_receipt'], $decision['missing_evidence']);
        $this->assertSame('missing_rollback_receipt_blocks_destructive_retirement', $decision['reason']);
    }

    public function test_rollback_receipt_missing_alongside_other_evidence_stays_needs_evidence(): void
    {
        // Multiple gaps: the generic needs_evidence bucket still applies (frozen behavior).
        $decision = (new AtlasSelfConstructionScaffoldRetirementPolicy)->decide([]);

        $this->assertSame('needs_evidence', $decision['decision']);
        $this->assertContains('rollback_receipt', $decision['missing_evidence']);
    }
}
