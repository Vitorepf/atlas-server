<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationLiveShadowPlan;
use Tests\TestCase;

final class AtlasSelfConstructionSimplificationLiveShadowPlanTest extends TestCase
{
    private function plan(): AtlasSelfConstructionSimplificationLiveShadowPlan
    {
        return new AtlasSelfConstructionSimplificationLiveShadowPlan;
    }

    private function readyCandidate(array $overrides = []): array
    {
        return array_merge([
            'candidate_id' => 'cand-1',
            'kind' => 'deletion',
            'samples_compared' => 200,
            'divergences' => 0,
            'min_samples' => 100,
            'max_divergences' => 0,
            'runtime_evidence' => ['execution_trace' => 'ok'],
            'rollback_observations' => ['revert_commit' => 'abc123'],
        ], $overrides);
    }

    // ── AC: candidates below minimum sample count remain shadow_more instead of promote ──

    public function test_below_minimum_samples_remains_shadow_more(): void
    {
        $result = $this->plan()->evaluate($this->readyCandidate([
            'samples_compared' => 50,
            'min_samples' => 100,
        ]));

        $this->assertSame('shadow_more', $result['decision']);
        $this->assertFalse($result['promote_ready']);
    }

    // ── AC: missing equivalence, runtime or rollback evidence blocks promotion ──

    public function test_missing_equivalence_blocks_promotion(): void
    {
        $result = $this->plan()->evaluate($this->readyCandidate([
            'divergences' => 5,
            'max_divergences' => 0,
        ]));

        $this->assertSame('blocked', $result['decision']);
        $this->assertFalse($result['promote_ready']);
        $this->assertContains('equivalence_not_proven', $result['blockers']);
        $this->assertContains('divergences_exceed_tolerance:5/0', $result['evidence_gaps']);
    }

    public function test_missing_runtime_evidence_blocks_promotion(): void
    {
        $result = $this->plan()->evaluate($this->readyCandidate([
            'runtime_evidence' => null,
        ]));

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('runtime_evidence_required', $result['blockers']);
        $this->assertContains('missing_runtime_evidence', $result['evidence_gaps']);
    }

    public function test_missing_rollback_observations_blocks_promotion(): void
    {
        $result = $this->plan()->evaluate($this->readyCandidate([
            'rollback_observations' => null,
        ]));

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('rollback_observations_required', $result['blockers']);
        $this->assertContains('missing_rollback_observations', $result['evidence_gaps']);
    }

    // ── AC: sufficient equivalent shadow runs emit promote_ready=true and preserve rollback_observations ──

    public function test_sufficient_equivalent_shadow_runs_emit_promote_ready(): void
    {
        $result = $this->plan()->evaluate($this->readyCandidate());

        $this->assertSame('promote', $result['decision']);
        $this->assertTrue($result['promote_ready']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['evidence_gaps']);
    }

    public function test_promote_ready_preserves_rollback_observations(): void
    {
        $rollbackObs = ['revert_commit' => 'abc123', 'soak_hours' => 24];
        $result = $this->plan()->evaluate($this->readyCandidate([
            'rollback_observations' => $rollbackObs,
        ]));

        $this->assertTrue($result['promote_ready']);
        $this->assertSame($rollbackObs, $result['rollback_observations']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->plan()->evaluate($this->readyCandidate());

        foreach (['schema', 'candidate_id', 'decision', 'promote_ready', 'evidence_gaps', 'blockers'] as $key) {
            $this->assertArrayHasKey($key, $result, "missing key: {$key}");
        }
    }

    public function test_result_is_deterministic(): void
    {
        $candidate = $this->readyCandidate();
        $a = $this->plan()->evaluate($candidate);
        $b = $this->plan()->evaluate($candidate);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
