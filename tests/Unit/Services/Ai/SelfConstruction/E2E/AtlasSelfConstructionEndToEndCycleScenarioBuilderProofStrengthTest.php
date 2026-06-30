<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\E2E;

use App\Services\Ai\SelfConstruction\E2E\AtlasSelfConstructionEndToEndCycleScenarioBuilder;
use Tests\TestCase;

final class AtlasSelfConstructionEndToEndCycleScenarioBuilderProofStrengthTest extends TestCase
{
    private function readyFacts(array $stepOverrides = []): array
    {
        $facts = [];
        foreach (AtlasSelfConstructionEndToEndCycleScenarioBuilder::STEP_SPEC as $organ => $spec) {
            $facts[$organ] = array_merge([
                $spec['evidence'] => 'evidence-value-'.$organ,
                'evidence_source' => 'organ-output:'.$organ,
                'evidence_fresh_at' => '2026-06-30T12:00:00Z',
                'rollback_artifact' => 'rollback-artifact-'.$organ,
            ], $stepOverrides[$organ] ?? []);
        }
        $facts['cortex']['autonomy_owner'] = AtlasSelfConstructionEndToEndCycleScenarioBuilder::REQUIRED_AUTONOMY_OWNER;

        return $facts;
    }

    public function test_ready_fixture_with_fresh_provenance_stays_ready(): void
    {
        $builder = new AtlasSelfConstructionEndToEndCycleScenarioBuilder();
        $result = $builder->build($this->readyFacts(), '2026-06-30T12:30:00Z');

        $this->assertSame(AtlasSelfConstructionEndToEndCycleScenarioBuilder::STATUS_READY, $result['status']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_missing_evidence_source_blocks_with_explicit_per_step_blocker(): void
    {
        $builder = new AtlasSelfConstructionEndToEndCycleScenarioBuilder();
        $facts = $this->readyFacts(['cortex' => ['evidence_source' => null]]);

        $result = $builder->build($facts, '2026-06-30T12:30:00Z');

        $this->assertSame(AtlasSelfConstructionEndToEndCycleScenarioBuilder::STATUS_BLOCKED, $result['status']);
        $this->assertContains('missing_evidence_source:cortex', $result['blockers']);

        $cortexStep = current(array_filter($result['steps'], static fn (array $s): bool => $s['organ'] === 'cortex'));
        $this->assertContains('missing_evidence_source:cortex', $cortexStep['blockers']);
    }

    public function test_stale_evidence_beyond_max_age_blocks_with_explicit_blocker(): void
    {
        $builder = new AtlasSelfConstructionEndToEndCycleScenarioBuilder();
        $facts = $this->readyFacts(['worker_swarm' => ['evidence_fresh_at' => '2026-06-28T12:00:00Z']]);

        $result = $builder->build($facts, '2026-06-30T12:30:00Z');

        $this->assertSame(AtlasSelfConstructionEndToEndCycleScenarioBuilder::STATUS_BLOCKED, $result['status']);
        $this->assertContains('stale_evidence:worker_swarm', $result['blockers']);
    }

    public function test_fresh_evidence_within_max_age_does_not_block(): void
    {
        $builder = new AtlasSelfConstructionEndToEndCycleScenarioBuilder();
        $facts = $this->readyFacts(['worker_swarm' => ['evidence_fresh_at' => '2026-06-30T11:00:00Z']]);

        $result = $builder->build($facts, '2026-06-30T12:00:00Z');

        $this->assertSame(AtlasSelfConstructionEndToEndCycleScenarioBuilder::STATUS_READY, $result['status']);
    }

    public function test_read_only_rollback_exemption_unchanged(): void
    {
        $builder = new AtlasSelfConstructionEndToEndCycleScenarioBuilder();
        $result = $builder->build($this->readyFacts(), '2026-06-30T12:30:00Z');

        $cortexStep = current(array_filter($result['steps'], static fn (array $s): bool => $s['organ'] === 'cortex'));
        $this->assertTrue($cortexStep['rollback_present']);
        $this->assertStringContainsString('none_required', $cortexStep['rollback_expectation']);
    }

    public function test_autonomy_owner_remains_atlas_native(): void
    {
        $builder = new AtlasSelfConstructionEndToEndCycleScenarioBuilder();
        $result = $builder->build($this->readyFacts(), '2026-06-30T12:30:00Z');

        $this->assertSame(AtlasSelfConstructionEndToEndCycleScenarioBuilder::REQUIRED_AUTONOMY_OWNER, $result['autonomy_owner']);
    }

    public function test_missing_evidence_source_without_evidence_present_does_not_double_block(): void
    {
        // When evidence itself is absent, the evidence_source check should not also
        // fire — the missing_step_evidence blocker already covers that case.
        $builder = new AtlasSelfConstructionEndToEndCycleScenarioBuilder();
        $facts = $this->readyFacts();
        unset($facts['cortex']['world_snapshot_hash']);
        $facts['cortex']['evidence_source'] = null;

        $result = $builder->build($facts, '2026-06-30T12:30:00Z');

        $this->assertContains('missing_step_evidence:cortex:world_snapshot_hash', $result['blockers']);
        $this->assertNotContains('missing_evidence_source:cortex', $result['blockers']);
    }
}
