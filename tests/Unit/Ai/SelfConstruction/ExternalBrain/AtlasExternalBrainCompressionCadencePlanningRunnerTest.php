<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionCadencePlanningRunner;
use PHPUnit\Framework\TestCase;

/**
 * Proves the CompressionCadencePlanningRunner composes all six orphan
 * compression-planning organs into a single cadence+design plan.
 *
 * AC1: an over-evidence-budget candidate is deferred by the cadence controller
 *      (high worker pressure → pause_for_muscles → null downstream organs).
 * AC2: a within-budget candidate on a valid design path is scheduled with
 *      synthesized invariants (dead code → delete path → required invariants).
 */
final class AtlasExternalBrainCompressionCadencePlanningRunnerTest extends TestCase
{
    private AtlasExternalBrainCompressionCadencePlanningRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new AtlasExternalBrainCompressionCadencePlanningRunner;
    }

    public function test_over_evidence_budget_candidate_is_deferred_by_cadence_controller(): void
    {
        // High worker pressure → pause_for_muscles (not create_batch).
        $result = $this->runner->plan([
            'cadence_facts' => ['worker_pressure' => 0.90],
        ]);

        $this->assertSame(
            AtlasExternalBrainCompressionCadencePlanningRunner::SCHEMA,
            $result['schema'],
        );

        $this->assertSame('pause_for_muscles', $result['cadence']['decision']);
        $this->assertNotEmpty($result['cadence']['reasons']);

        // Downstream organs are null when cadence does not authorise a batch.
        $this->assertNull($result['design_path']);
        $this->assertNull($result['invariants']);
        $this->assertNull($result['evidence_budget']);
        $this->assertNull($result['learning']);
        $this->assertNull($result['benchmark']);
    }

    public function test_within_budget_candidate_on_valid_design_path_is_scheduled(): void
    {
        // High-value distinct count > 0 → create_batch.
        $result = $this->runner->plan([
            'cadence_facts' => ['claimable_high_value_distinct_count' => 3],
            'design_facts'  => ['dead_code' => true],
            'budget_facts'  => ['risk_level' => 'low', 'blast_radius' => 1, 'capability_criticality' => 'low'],
            'benchmark_facts' => [
                'line_reduction_score'            => 0.8,
                'behavior_preservation_score'     => 0.9,
                'test_strength_score'             => 0.7,
                'rollback_readiness_score'        => 0.6,
                'worker_yield_preservation_score' => 0.8,
            ],
            'learning_facts' => [
                'outcomes' => [
                    ['pattern' => 'merge', 'outcome' => 'win', 'gain_score' => 0.8],
                ],
            ],
        ]);

        $this->assertSame('create_batch', $result['cadence']['decision']);

        // Design path: dead_code=true → delete
        $this->assertNotNull($result['design_path']);
        $this->assertSame('delete', $result['design_path']['design_path']);

        // Invariants synthesized from the delete action.
        $this->assertNotNull($result['invariants']);
        $this->assertSame('invariants_required', $result['invariants']['decision']);
        $this->assertSame('delete', $result['invariants']['action']);
        $this->assertContains('zero_active_consumers', $result['invariants']['required_invariants']);
        $this->assertContains('behavior_lock_proof', $result['invariants']['required_invariants']);
        $this->assertContains('rollback_evidence', $result['invariants']['required_invariants']);

        // Evidence budget: low risk + low blast + low criticality → minimal tier.
        $this->assertNotNull($result['evidence_budget']);
        $this->assertSame('minimal', $result['evidence_budget']['evidence_tier']);
        $this->assertContains('unit_tests', $result['evidence_budget']['required_evidence']);

        // Learning: merge pattern win → promote bias.
        $this->assertNotNull($result['learning']);
        $this->assertArrayHasKey('strategy_biases', $result['learning']);
        $this->assertSame('promote', $result['learning']['strategy_biases']['merge']['bias']);

        // Benchmark: all floors at/above 0.6 → approve.
        $this->assertNotNull($result['benchmark']);
        $this->assertSame('approve', $result['benchmark']['decision']);
        $this->assertSame([], $result['benchmark']['failed_floors']);
    }
}
