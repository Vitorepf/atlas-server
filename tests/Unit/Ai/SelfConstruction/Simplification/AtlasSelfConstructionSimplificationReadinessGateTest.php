<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationReadinessGate;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationReadinessGateTest extends TestCase
{
    private function completeFacts(): array
    {
        return [
            'cluster' => ['merge_ready' => true],
            'parity' => ['replacement_allowed' => true],
            'shadow_plan' => ['promotion_allowed' => true],
            'cohesion' => ['consolidation_improves_circuit' => true],
            'rollback' => ['reversible' => true],
            'docs_sync' => ['status' => 'synced'],
            'boundary_confidence' => 0.9,
            'consumer_impact' => ['breaking_changes' => []],
            'proof_coverage' => 0.9,
            'safe_allowed_files' => ['app/New/Merged.php'],
        ];
    }

    public function test_complete_facts_allow_consolidation(): void
    {
        $result = (new AtlasSelfConstructionSimplificationReadinessGate)->evaluate($this->completeFacts());

        self::assertSame(AtlasSelfConstructionSimplificationReadinessGate::DECISION_ALLOW, $result['decision']);
        self::assertSame([], $result['blockers']);
        self::assertSame(['app/New/Merged.php'], $result['safe_allowed_files']);
        self::assertSame('proceed_with_consolidation', $result['next_action']);
    }

    public function test_low_boundary_confidence_holds_for_more_evidence(): void
    {
        $facts = $this->completeFacts();
        $facts['boundary_confidence'] = 0.3;

        $result = (new AtlasSelfConstructionSimplificationReadinessGate)->evaluate($facts);

        self::assertSame(AtlasSelfConstructionSimplificationReadinessGate::DECISION_HOLD, $result['decision']);
        self::assertSame('gather_more_evidence', $result['next_action']);
        self::assertSame([], $result['safe_allowed_files']);
    }

    public function test_missing_core_section_rejects(): void
    {
        $facts = $this->completeFacts();
        unset($facts['parity']);

        $result = (new AtlasSelfConstructionSimplificationReadinessGate)->evaluate($facts);

        self::assertSame(AtlasSelfConstructionSimplificationReadinessGate::DECISION_REJECT, $result['decision']);
        self::assertContains('missing_section:parity', $result['blockers']);
        self::assertSame('refuse_consolidation', $result['next_action']);
    }

    public function test_irreversible_rollback_rejects(): void
    {
        $facts = $this->completeFacts();
        $facts['rollback'] = ['reversible' => false];

        $result = (new AtlasSelfConstructionSimplificationReadinessGate)->evaluate($facts);

        self::assertSame(AtlasSelfConstructionSimplificationReadinessGate::DECISION_REJECT, $result['decision']);
        self::assertContains('rollback_not_reversible', $result['blockers']);
        self::assertContains('organ_deletion_irreversible', $result['irreversible_risks']);
    }

    public function test_cohesion_worse_rejects(): void
    {
        $facts = $this->completeFacts();
        $facts['cohesion'] = ['consolidation_improves_circuit' => false];

        $result = (new AtlasSelfConstructionSimplificationReadinessGate)->evaluate($facts);

        self::assertSame(AtlasSelfConstructionSimplificationReadinessGate::DECISION_REJECT, $result['decision']);
        self::assertContains('cohesion_does_not_improve_circuit', $result['blockers']);
    }

    public function test_breaking_consumer_changes_reject(): void
    {
        $facts = $this->completeFacts();
        $facts['consumer_impact'] = ['breaking_changes' => ['CallerX depends on removed method']];

        $result = (new AtlasSelfConstructionSimplificationReadinessGate)->evaluate($facts);

        self::assertSame(AtlasSelfConstructionSimplificationReadinessGate::DECISION_REJECT, $result['decision']);
        self::assertContains('consumer_breaking_changes_present', $result['blockers']);
    }

    public function test_destructive_delete_without_regression_replay_plan_rejects(): void
    {
        $facts = $this->completeFacts();
        $facts['action_type'] = 'delete';

        $result = (new AtlasSelfConstructionSimplificationReadinessGate)->evaluate($facts);

        self::assertSame(AtlasSelfConstructionSimplificationReadinessGate::DECISION_REJECT, $result['decision']);
        self::assertContains('regression_replay_plan_missing', $result['blockers']);
    }

    public function test_destructive_merge_with_regression_replay_plan_not_ready_rejects(): void
    {
        $facts = $this->completeFacts();
        $facts['action_type'] = 'merge';
        $facts['regression_replay_plan'] = ['ready' => false];

        $result = (new AtlasSelfConstructionSimplificationReadinessGate)->evaluate($facts);

        self::assertSame(AtlasSelfConstructionSimplificationReadinessGate::DECISION_REJECT, $result['decision']);
        self::assertContains('regression_replay_not_ready', $result['blockers']);
    }

    public function test_non_destructive_simplify_can_still_hold_for_low_proof_coverage_without_replay_plan(): void
    {
        $facts = $this->completeFacts();
        $facts['action_type'] = 'simplify';
        $facts['proof_coverage'] = 0.3;
        unset($facts['regression_replay_plan']);

        $result = (new AtlasSelfConstructionSimplificationReadinessGate)->evaluate($facts);

        self::assertSame(AtlasSelfConstructionSimplificationReadinessGate::DECISION_HOLD, $result['decision']);
        self::assertNotContains('regression_replay_plan_missing', $result['blockers']);
    }

    public function test_docs_sync_blocked_rejects(): void
    {
        $facts = $this->completeFacts();
        $facts['docs_sync'] = ['status' => 'blocked'];

        $result = (new AtlasSelfConstructionSimplificationReadinessGate)->evaluate($facts);

        self::assertSame(AtlasSelfConstructionSimplificationReadinessGate::DECISION_REJECT, $result['decision']);
        self::assertContains('docs_sync_blocked', $result['blockers']);
    }

    public function test_shadow_plan_promotion_not_allowed_rejects(): void
    {
        $facts = $this->completeFacts();
        $facts['shadow_plan'] = ['promotion_allowed' => false];

        $result = (new AtlasSelfConstructionSimplificationReadinessGate)->evaluate($facts);

        self::assertSame(AtlasSelfConstructionSimplificationReadinessGate::DECISION_REJECT, $result['decision']);
        self::assertContains('shadow_plan_promotion_not_allowed', $result['blockers']);
    }

    // ── AC3: blocker ids (reject-tier) separated from advisory ids (hold-tier) ──

    public function test_reject_tier_ids_land_in_blocker_ids_not_advisory_ids(): void
    {
        $facts = $this->completeFacts();
        $facts['rollback'] = ['reversible' => false];

        $result = (new AtlasSelfConstructionSimplificationReadinessGate)->evaluate($facts);

        self::assertContains('rollback_not_reversible', $result['blocker_ids']);
        self::assertSame([], $result['advisory_ids']);
    }

    public function test_hold_tier_ids_land_in_advisory_ids_not_blocker_ids(): void
    {
        $facts = $this->completeFacts();
        $facts['boundary_confidence'] = 0.1;
        $facts['proof_coverage'] = 0.1;

        $result = (new AtlasSelfConstructionSimplificationReadinessGate)->evaluate($facts);

        self::assertSame([], $result['blocker_ids']);
        self::assertNotEmpty($result['advisory_ids']);
        self::assertSame($result['advisory_ids'], $result['blockers']);
    }
}
