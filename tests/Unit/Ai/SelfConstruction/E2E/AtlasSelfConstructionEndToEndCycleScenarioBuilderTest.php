<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\E2E;

use App\Services\Ai\SelfConstruction\E2E\AtlasSelfConstructionEndToEndCycleScenarioBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionEndToEndCycleScenarioBuilder: a complete cycle yields status=ready with
 * 13 steps in canonical order, each owner=atlas_native, each with expected evidence + rollback +
 * next-step dependency; missing organ facts ⇒ status=blocked with missing_organ_facts:<organ>; cortex
 * autonomy_owner != 'atlas_native' ⇒ status=blocked with autonomy_owner_mismatch:<value>.
 */
final class AtlasSelfConstructionEndToEndCycleScenarioBuilderTest extends TestCase
{
    private function allOrgans(): array
    {
        $out = [];
        foreach (AtlasSelfConstructionEndToEndCycleScenarioBuilder::STEP_ORDER as $organ) {
            $spec = AtlasSelfConstructionEndToEndCycleScenarioBuilder::STEP_SPEC[$organ];
            $facts = [$spec['evidence'] => 'test-value-'.$organ];
            if (! str_starts_with($spec['rollback'], 'none_required')) {
                $facts['rollback_artifact'] = 'rollback-plan-'.$organ;
            }
            $out[$organ] = $facts;
        }

        return $out;
    }

    public function test_complete_cycle_emits_ready_status_with_canonical_step_order(): void
    {
        $r = (new AtlasSelfConstructionEndToEndCycleScenarioBuilder)->build($this->allOrgans());
        $this->assertSame(AtlasSelfConstructionEndToEndCycleScenarioBuilder::STATUS_READY, $r['status']);
        $this->assertCount(13, $r['steps']);
        $organs = array_column($r['steps'], 'organ');
        $this->assertSame(AtlasSelfConstructionEndToEndCycleScenarioBuilder::STEP_ORDER, $organs);
    }

    public function test_every_step_owner_is_atlas_native(): void
    {
        $r = (new AtlasSelfConstructionEndToEndCycleScenarioBuilder)->build($this->allOrgans());
        foreach ($r['steps'] as $s) {
            $this->assertSame('atlas_native', $s['owner']);
        }
    }

    public function test_every_step_carries_evidence_rollback_and_next_dependency(): void
    {
        $r = (new AtlasSelfConstructionEndToEndCycleScenarioBuilder)->build($this->allOrgans());
        foreach ($r['steps'] as $i => $s) {
            $this->assertArrayHasKey('output_evidence', $s);
            $this->assertArrayHasKey('evidence_present', $s);
            $this->assertArrayHasKey('rollback_expectation', $s);
            $this->assertArrayHasKey('rollback_present', $s);
            $this->assertArrayHasKey('blockers', $s);
            $this->assertArrayHasKey('next_step_dependency', $s);
            $this->assertTrue($s['evidence_present'], "step {$s['organ']} must have evidence_present=true");
            $this->assertTrue($s['rollback_present'], "step {$s['organ']} must have rollback_present=true");
            $this->assertSame([], $s['blockers'], "step {$s['organ']} must have no blockers");
            if ($i === count($r['steps']) - 1) {
                $this->assertNull($s['next_step_dependency']);
            }
        }
    }

    public function test_missing_evidence_keys_block_with_missing_step_evidence_reason(): void
    {
        $organs = $this->allOrgans();
        // Remove the expected evidence key from strategy so it's missing
        unset($organs['strategy']['decision_hash']);

        $r = (new AtlasSelfConstructionEndToEndCycleScenarioBuilder)->build($organs);
        $this->assertSame(AtlasSelfConstructionEndToEndCycleScenarioBuilder::STATUS_BLOCKED, $r['status']);
        $this->assertContains('missing_step_evidence:strategy:decision_hash', $r['blockers']);

        $strategyStep = array_values(array_filter($r['steps'], static fn ($s) => $s['organ'] === 'strategy'))[0];
        $this->assertFalse($strategyStep['evidence_present']);
        $this->assertContains('missing_step_evidence:strategy:decision_hash', $strategyStep['blockers']);
    }

    public function test_rollback_gap_blocks_mutation_capable_but_not_read_only_steps(): void
    {
        $organs = $this->allOrgans();
        // Remove rollback_artifact from a mutation-capable step (worker_swarm)
        unset($organs['worker_swarm']['rollback_artifact']);

        $r = (new AtlasSelfConstructionEndToEndCycleScenarioBuilder)->build($organs);
        $this->assertSame(AtlasSelfConstructionEndToEndCycleScenarioBuilder::STATUS_BLOCKED, $r['status']);
        $this->assertContains('rollback_gap:worker_swarm', $r['blockers']);

        // Read-only steps (cortex, goal_value, receipts) should still have rollback_present=true
        foreach ($r['steps'] as $s) {
            if (str_starts_with((string) ($s['rollback_expectation'] ?? ''), 'none_required')) {
                $this->assertTrue($s['rollback_present'], "read-only step {$s['organ']} must never have rollback gap");
                $this->assertNotContains('rollback_gap:'.$s['organ'], $r['blockers']);
            }
        }
    }

    public function test_no_numeric_readiness_score_is_emitted(): void
    {
        $r = (new AtlasSelfConstructionEndToEndCycleScenarioBuilder)->build($this->allOrgans());
        $this->assertArrayNotHasKey('score', $r);
        $this->assertArrayNotHasKey('readiness_score', $r);
        $this->assertArrayNotHasKey('readiness_pct', $r);
        foreach ($r['steps'] as $s) {
            $this->assertArrayNotHasKey('score', $s);
            $this->assertArrayNotHasKey('readiness_score', $s);
        }
    }

    public function test_missing_organ_facts_yield_blocked_status_with_named_blocker(): void
    {
        $organs = $this->allOrgans();
        unset($organs['verification_court'], $organs['merge_governor']);
        $r = (new AtlasSelfConstructionEndToEndCycleScenarioBuilder)->build($organs);
        $this->assertSame(AtlasSelfConstructionEndToEndCycleScenarioBuilder::STATUS_BLOCKED, $r['status']);
        $this->assertContains('missing_organ_facts:verification_court', $r['blockers']);
        $this->assertContains('missing_organ_facts:merge_governor', $r['blockers']);
    }

    public function test_autonomy_owner_mismatch_yields_blocked_status(): void
    {
        $organs = $this->allOrgans();
        $organs['cortex']['autonomy_owner'] = 'external_provider';
        $r = (new AtlasSelfConstructionEndToEndCycleScenarioBuilder)->build($organs);
        $this->assertSame(AtlasSelfConstructionEndToEndCycleScenarioBuilder::STATUS_BLOCKED, $r['status']);
        $this->assertContains('autonomy_owner_mismatch:external_provider', $r['blockers']);
    }

    public function test_envelope_declares_autonomy_owner_atlas_native(): void
    {
        $r = (new AtlasSelfConstructionEndToEndCycleScenarioBuilder)->build($this->allOrgans());
        $this->assertSame('atlas_native', $r['autonomy_owner']);
    }
}
