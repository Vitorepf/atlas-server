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
            $out[$organ] = ['observed_at_unix' => 1];
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
            $this->assertArrayHasKey('rollback_expectation', $s);
            $this->assertArrayHasKey('next_step_dependency', $s);
            // last step has no next
            if ($i === count($r['steps']) - 1) {
                $this->assertNull($s['next_step_dependency']);
            }
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
