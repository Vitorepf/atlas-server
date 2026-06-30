<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainConvergenceCriteriaCompiler;
use Tests\TestCase;

final class AtlasExternalBrainConvergenceCriteriaCompilerTest extends TestCase
{
    private function compiler(): AtlasExternalBrainConvergenceCriteriaCompiler
    {
        return new AtlasExternalBrainConvergenceCriteriaCompiler();
    }

    private function allPassing(): array
    {
        return [
            'final_95_readiness'          => 0.95,
            'brain_health_score'          => 0.70,
            'queue_health_score'          => 0.70,
            'autonomy_independence_score' => 0.80,
            'learning_loop_closedness'    => 0.75,
            'worker_proof_count'          => 5.0,
            'compounding_evidence_score'  => 0.60,
            'simplification_score'        => 0.70,
            'integration_organs_count'    => 5,
        ];
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertSame(AtlasExternalBrainConvergenceCriteriaCompiler::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->compiler()->compile([]);

        foreach (['schema', 'verdict', 'failing_pillars', 'next_action', 'evidence_receipts'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    public function test_evidence_receipts_contains_all_pillars(): void
    {
        $result = $this->compiler()->compile([]);

        foreach (['final_95_readiness', 'brain_health_score', 'queue_health_score',
                  'autonomy_independence_score', 'learning_loop_closedness',
                  'worker_proof_count', 'compounding_evidence_score', 'simplification_score'] as $p) {
            $this->assertArrayHasKey($p, $result['evidence_receipts']);
        }
    }

    public function test_each_receipt_has_score_threshold_passes(): void
    {
        $result = $this->compiler()->compile($this->allPassing());

        foreach ($result['evidence_receipts'] as $receipt) {
            $this->assertArrayHasKey('score', $receipt);
            $this->assertArrayHasKey('threshold', $receipt);
            $this->assertArrayHasKey('passes', $receipt);
        }
    }

    // ── convergence_ready ─────────────────────────────────────────────────────

    public function test_convergence_ready_when_all_pillars_pass(): void
    {
        $result = $this->compiler()->compile($this->allPassing());

        $this->assertSame(AtlasExternalBrainConvergenceCriteriaCompiler::VERDICT_CONVERGENCE_READY, $result['verdict']);
        $this->assertSame([], $result['failing_pillars']);
        $this->assertSame('monitor_and_maintain', $result['next_action']);
    }

    public function test_convergence_ready_requires_all_pillars(): void
    {
        $input = $this->allPassing();
        $input['final_95_readiness'] = 0.94;  // just below threshold

        $result = $this->compiler()->compile($input);

        $this->assertNotSame(AtlasExternalBrainConvergenceCriteriaCompiler::VERDICT_CONVERGENCE_READY, $result['verdict']);
    }

    // ── keep_building ─────────────────────────────────────────────────────────

    public function test_keep_building_when_no_pillars_provided(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertSame(AtlasExternalBrainConvergenceCriteriaCompiler::VERDICT_KEEP_BUILDING, $result['verdict']);
        $this->assertSame('address_failing_pillars', $result['next_action']);
    }

    public function test_keep_building_when_too_few_pillars_pass(): void
    {
        // Only 2 pillars pass (below CONSOLIDATE_PILLAR_FLOOR=3)
        $result = $this->compiler()->compile([
            'brain_health_score' => 0.90,
            'queue_health_score' => 0.90,
            'integration_organs_count' => 5,
        ]);

        $this->assertSame(AtlasExternalBrainConvergenceCriteriaCompiler::VERDICT_KEEP_BUILDING, $result['verdict']);
    }

    public function test_keep_building_includes_failing_pillar_names(): void
    {
        $result = $this->compiler()->compile([
            'brain_health_score' => 0.30,  // fails
        ]);

        $this->assertContains('brain_health_score', $result['failing_pillars']);
    }

    // ── consolidate ───────────────────────────────────────────────────────────

    public function test_consolidate_when_partial_pillars_pass_and_organs_exist(): void
    {
        // Pass 4 pillars, fail 3, but have enough organs
        $result = $this->compiler()->compile([
            'brain_health_score'          => 0.90,
            'queue_health_score'          => 0.90,
            'autonomy_independence_score' => 0.90,
            'learning_loop_closedness'    => 0.90,
            'integration_organs_count'    => 4,
        ]);

        $this->assertSame(AtlasExternalBrainConvergenceCriteriaCompiler::VERDICT_CONSOLIDATE, $result['verdict']);
        $this->assertSame('close_evidence_gaps_and_integrate', $result['next_action']);
    }

    public function test_consolidate_requires_integration_organs_floor(): void
    {
        // 4 pillars passing but no integration organs → keep_building
        $result = $this->compiler()->compile([
            'brain_health_score'          => 0.90,
            'queue_health_score'          => 0.90,
            'autonomy_independence_score' => 0.90,
            'learning_loop_closedness'    => 0.90,
            'integration_organs_count'    => 2,  // below floor of 3
        ]);

        $this->assertSame(AtlasExternalBrainConvergenceCriteriaCompiler::VERDICT_KEEP_BUILDING, $result['verdict']);
    }

    // ── worker_proof_count ────────────────────────────────────────────────────

    public function test_worker_proof_count_must_reach_threshold(): void
    {
        $input = $this->allPassing();
        $input['worker_proof_count'] = 4;  // below threshold of 5

        $result = $this->compiler()->compile($input);

        $this->assertContains('worker_proof_count', $result['failing_pillars']);
        $this->assertNotSame(AtlasExternalBrainConvergenceCriteriaCompiler::VERDICT_CONVERGENCE_READY, $result['verdict']);
    }

    // ── custom thresholds ─────────────────────────────────────────────────────

    public function test_custom_threshold_overrides_default(): void
    {
        $result = $this->compiler()->compile([
            'brain_health_score' => 0.50,
            'thresholds'         => ['brain_health_score' => 0.40],  // easier threshold
            'integration_organs_count' => 0,
        ]);

        $this->assertTrue($result['evidence_receipts']['brain_health_score']['passes']);
    }

    // ── evidence_receipts ─────────────────────────────────────────────────────

    public function test_passing_pillar_receipt_has_passes_true(): void
    {
        $result = $this->compiler()->compile(['brain_health_score' => 0.80]);

        $this->assertTrue($result['evidence_receipts']['brain_health_score']['passes']);
    }

    public function test_failing_pillar_receipt_has_passes_false(): void
    {
        $result = $this->compiler()->compile(['brain_health_score' => 0.30]);

        $this->assertFalse($result['evidence_receipts']['brain_health_score']['passes']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = $this->allPassing();

        $this->assertSame($this->compiler()->compile($input), $this->compiler()->compile($input));
    }

    // ── new output keys ───────────────────────────────────────────────────────

    public function test_output_includes_convergence_status_missing_proof_and_next_verification_step(): void
    {
        $result = $this->compiler()->compile([]);

        foreach (['convergence_status', 'missing_proof', 'next_verification_step'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    public function test_convergence_status_mirrors_verdict(): void
    {
        $result = $this->compiler()->compile($this->allPassing());

        $this->assertSame($result['verdict'], $result['convergence_status']);
        $this->assertSame(AtlasExternalBrainConvergenceCriteriaCompiler::VERDICT_CONVERGENCE_READY, $result['convergence_status']);
    }

    public function test_next_verification_step_is_null_when_fully_converged(): void
    {
        $result = $this->compiler()->compile($this->allPassing());

        $this->assertNull($result['next_verification_step']);
    }

    public function test_next_verification_step_is_concrete_when_pillars_fail(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertIsString($result['next_verification_step']);
        $this->assertNotEmpty($result['next_verification_step']);
    }

    // ── self-declared evidence refused ────────────────────────────────────────

    public function test_self_declared_pillar_fails_regardless_of_score(): void
    {
        $input = $this->allPassing();
        $input['evidence_meta'] = ['brain_health_score' => ['self_declared' => true]];

        $result = $this->compiler()->compile($input);

        $this->assertFalse($result['evidence_receipts']['brain_health_score']['passes']);
        $this->assertContains('brain_health_score', $result['failing_pillars']);
        $this->assertNotSame(AtlasExternalBrainConvergenceCriteriaCompiler::VERDICT_CONVERGENCE_READY, $result['verdict']);

        $missing = collect($result['missing_proof'])->firstWhere('pillar', 'brain_health_score');
        $this->assertContains('self_declared_not_externally_verified', $missing['reasons']);
    }

    // ── stale evidence refused ────────────────────────────────────────────────

    public function test_stale_pillar_fails_regardless_of_score(): void
    {
        $input = $this->allPassing();
        $input['evidence_meta'] = ['queue_health_score' => ['age_days' => 90]];

        $result = $this->compiler()->compile($input);

        $this->assertFalse($result['evidence_receipts']['queue_health_score']['passes']);
        $missing = collect($result['missing_proof'])->firstWhere('pillar', 'queue_health_score');
        $this->assertNotNull($missing);
        $this->assertStringContainsString('evidence_stale', $missing['reasons'][0]);
    }

    public function test_fresh_evidence_within_max_age_passes(): void
    {
        $input = $this->allPassing();
        $input['evidence_meta'] = ['queue_health_score' => ['age_days' => 5]];

        $result = $this->compiler()->compile($input);

        $this->assertTrue($result['evidence_receipts']['queue_health_score']['passes']);
    }

    public function test_custom_max_evidence_age_days_is_respected(): void
    {
        $input = $this->allPassing();
        $input['evidence_meta'] = ['queue_health_score' => ['age_days' => 10]];
        $input['max_evidence_age_days'] = 5;

        $result = $this->compiler()->compile($input);

        $this->assertFalse($result['evidence_receipts']['queue_health_score']['passes']);
    }

    // ── missing critical category ─────────────────────────────────────────────

    public function test_missing_simplification_pillar_blocks_convergence(): void
    {
        $input = $this->allPassing();
        unset($input['simplification_score']);

        $result = $this->compiler()->compile($input);

        $this->assertNotSame(AtlasExternalBrainConvergenceCriteriaCompiler::VERDICT_CONVERGENCE_READY, $result['verdict']);
        $this->assertContains('simplification_score', $result['failing_pillars']);
    }

    // ── missing_proof structure ────────────────────────────────────────────────

    public function test_missing_proof_entries_have_pillar_reasons_and_verification_step(): void
    {
        $result = $this->compiler()->compile([]);

        foreach ($result['missing_proof'] as $entry) {
            $this->assertArrayHasKey('pillar', $entry);
            $this->assertArrayHasKey('reasons', $entry);
            $this->assertArrayHasKey('verification_step', $entry);
            $this->assertNotEmpty($entry['reasons']);
            $this->assertNotEmpty($entry['verification_step']);
        }
    }

    public function test_missing_proof_count_matches_failing_pillars_count(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertSame(count($result['failing_pillars']), count($result['missing_proof']));
    }
}
