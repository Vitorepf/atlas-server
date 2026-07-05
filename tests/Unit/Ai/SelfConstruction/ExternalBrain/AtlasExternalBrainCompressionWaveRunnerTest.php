<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionWaveRunner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionOpportunityMiner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionMutationRiskModel;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionChangeBudget;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionNorthStarScorecard;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionRegressionGuard;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionControlPlane;
use Tests\TestCase;

final class AtlasExternalBrainCompressionWaveRunnerTest extends TestCase
{
    private function runner(): AtlasExternalBrainCompressionWaveRunner
    {
        return new AtlasExternalBrainCompressionWaveRunner;
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->runner()->run([]);

        $this->assertArrayHasKey('schema', $result);
        $this->assertArrayHasKey('wave_id', $result);
        $this->assertArrayHasKey('opportunities', $result);
        $this->assertArrayHasKey('risk', $result);
        $this->assertArrayHasKey('budget', $result);
        $this->assertArrayHasKey('scorecard', $result);
        $this->assertArrayHasKey('regression_guard', $result);
        $this->assertArrayHasKey('control_plane', $result);
        $this->assertArrayHasKey('verdict', $result);
    }

    public function test_wave_id_is_auto_generated_when_not_provided(): void
    {
        $result = $this->runner()->run([]);

        $this->assertStringStartsWith('wave_', $result['wave_id']);
        $this->assertNotEmpty($result['wave_id']);
    }

    public function test_wave_id_uses_provided_value(): void
    {
        $result = $this->runner()->run(['wave_id' => 'my-custom-wave']);

        $this->assertSame('my-custom-wave', $result['wave_id']);
    }

    public function test_verdict_is_go_when_regression_guard_passes_and_control_plane_says_go(): void
    {
        // Build candidates that the control plane will approve (all gates pass).
        $candidates = [
            [
                'id'                      => 'cand-1',
                'allowed_files'           => ['src/Foo.php'],
                'entropy_score'           => 0.8,
                'hotspot_score'           => 0.7,
                'duplicate_score'         => 0.6,
                'reachability_score'      => 0.5,
                'proof_debt_score'        => 0.1,
                'required_proof_prework'  => [],
                'proof_prework_done'      => [],
            ],
        ];

        // Actions that the mutation risk model will approve.
        $actions = [
            [
                'action_id'              => 'act-1',
                'action_type'            => 'simplify',
                'branch_coverage'        => 0.85,
                'capability_criticality' => 'low',
                'consumer_count'         => 1,
                'guards_present'         => ['mutation_test'],
            ],
        ];

        // Budget facts that allow proceed.
        $budgetFacts = [
            'risk_level'       => 'low',
            'proof_coverage'   => 0.9,
            'has_rollback_path' => true,
            'worker_capacity'  => 2,
        ];

        // Scorecard input that yields accelerate.
        $scorecardInput = [
            'fitness_gain'    => 0.8,
            'autonomy_gain'   => 0.7,
            'deletion_gain'   => 0.6,
            'proof_health'    => 0.9,
            'regression_rate' => 0.05,
            'blockers'        => [],
        ];

        // Before/after floors that the regression guard will approve.
        $beforeFloors = [
            'test_count'           => 100,
            'docs_sync'            => true,
            'capability_coverage'  => 0.8,
            'worker_yield'         => 0.9,
        ];
        $afterFloors = [
            'test_count'           => 120,
            'docs_sync'            => true,
            'capability_coverage'  => 0.85,
            'worker_yield'         => 0.92,
        ];

        // Control candidates that the control plane will approve (behavior lock + proof gates).
        $controlCandidates = [
            [
                'candidate_id'            => 'cand-1',
                'action'                  => 'simplify',
                'area'                    => 'src',
                'risk_level'              => 'low',
                'expected_line_reduction' => 50,
                'behavior_lock_present'   => true,
                'proof_gates_passed'      => true,
                'hotspot_score'           => 0.7,
            ],
        ];

        $result = $this->runner()->run([
            'candidates'         => $candidates,
            'actions'            => $actions,
            'budget_facts'       => $budgetFacts,
            'scorecard_input'    => $scorecardInput,
            'before_floors'      => $beforeFloors,
            'after_floors'       => $afterFloors,
            'control_candidates' => $controlCandidates,
        ]);

        $this->assertSame(AtlasExternalBrainCompressionRegressionGuard::DECISION_APPROVE, $result['regression_guard']['decision']);
        $this->assertSame(AtlasExternalBrainCompressionControlPlane::DECISION_GO, $result['control_plane']['readiness']);
        $this->assertSame(AtlasExternalBrainCompressionWaveRunner::VERDICT_GO, $result['verdict']);
        $this->assertSame(AtlasExternalBrainCompressionWaveRunner::SCHEMA, $result['schema']);

        // Verify the wave has safe waves.
        $this->assertNotEmpty($result['control_plane']['safe_waves']);

        // Verify budget proceeded.
        $this->assertSame(AtlasExternalBrainCompressionChangeBudget::DECISION_PROCEED, $result['budget']['decision']);

        // Verify north star scorecard produced a recommendation.
        $this->assertNotEmpty($result['scorecard']['recommendation']);
    }

    public function test_verdict_is_regressed_when_regression_guard_holds(): void
    {
        // Build candidates that pass opportunity mining.
        $candidates = [
            [
                'id'                      => 'cand-1',
                'allowed_files'           => ['src/Foo.php'],
                'entropy_score'           => 0.8,
                'hotspot_score'           => 0.7,
                'duplicate_score'         => 0.6,
                'reachability_score'      => 0.5,
                'proof_debt_score'        => 0.1,
                'required_proof_prework'  => [],
                'proof_prework_done'      => [],
            ],
        ];

        // Before floors with healthy values.
        $beforeFloors = [
            'test_count'           => 100,
            'docs_sync'            => true,
            'capability_coverage'  => 0.8,
            'worker_yield'         => 0.9,
        ];

        // After floors with a regression: test_count dropped.
        $afterFloors = [
            'test_count'           => 80,   // regressed: 80 < 100
            'docs_sync'            => true,
            'capability_coverage'  => 0.8,
            'worker_yield'         => 0.9,
        ];

        $result = $this->runner()->run([
            'candidates'    => $candidates,
            'before_floors' => $beforeFloors,
            'after_floors'  => $afterFloors,
        ]);

        $this->assertSame(AtlasExternalBrainCompressionRegressionGuard::DECISION_HOLD, $result['regression_guard']['decision']);
        $this->assertContains('test_count', $result['regression_guard']['violated_floors']);
        $this->assertNull($result['control_plane'], 'Control plane result should be null when regressed');
        $this->assertSame(AtlasExternalBrainCompressionWaveRunner::VERDICT_REGRESSED, $result['verdict']);
    }

    public function test_verdict_is_hold_when_regression_guard_passes_but_control_plane_holds(): void
    {
        // All candidates have missing behavior locks — control plane will hold them.
        $candidates = [
            [
                'id'                      => 'cand-1',
                'allowed_files'           => ['src/Foo.php'],
                'entropy_score'           => 0.8,
                'hotspot_score'           => 0.7,
                'duplicate_score'         => 0.6,
                'reachability_score'      => 0.5,
                'proof_debt_score'        => 0.1,
                'required_proof_prework'  => [],
                'proof_prework_done'      => [],
            ],
        ];

        $beforeFloors = [
            'test_count'           => 100,
            'docs_sync'            => true,
            'capability_coverage'  => 0.8,
            'worker_yield'         => 0.9,
        ];
        $afterFloors = [
            'test_count'           => 100,
            'docs_sync'            => true,
            'capability_coverage'  => 0.8,
            'worker_yield'         => 0.9,
        ];

        // Control candidates with missing behavior lock — will be held.
        $controlCandidates = [
            [
                'candidate_id'            => 'cand-1',
                'action'                  => 'delete',
                'area'                    => 'src',
                'risk_level'              => 'low',
                'expected_line_reduction' => 50,
                'behavior_lock_present'   => false,   // missing lock → hold
                'proof_gates_passed'      => false,   // missing proof → hold
                'hotspot_score'           => 0.7,
            ],
        ];

        $result = $this->runner()->run([
            'candidates'         => $candidates,
            'before_floors'      => $beforeFloors,
            'after_floors'       => $afterFloors,
            'control_candidates' => $controlCandidates,
        ]);

        $this->assertSame(AtlasExternalBrainCompressionRegressionGuard::DECISION_APPROVE, $result['regression_guard']['decision']);
        $this->assertSame(AtlasExternalBrainCompressionControlPlane::DECISION_HOLD, $result['control_plane']['readiness']);
        $this->assertSame(AtlasExternalBrainCompressionWaveRunner::VERDICT_HOLD, $result['verdict']);

        // Verify control plane has blocked deletions.
        $this->assertNotEmpty($result['control_plane']['blocked_deletions']);
        $this->assertEmpty($result['control_plane']['safe_waves']);
    }

    public function test_missing_after_floors_causes_regressed_verdict(): void
    {
        // Regression guard returns hold when before snapshot exists but after is missing.
        $beforeFloors = [
            'test_count'           => 100,
            'docs_sync'            => true,
            'capability_coverage'  => 0.8,
            'worker_yield'         => 0.9,
        ];

        $result = $this->runner()->run([
            'before_floors' => $beforeFloors,
            // after_floors intentionally omitted
        ]);

        $this->assertSame(AtlasExternalBrainCompressionRegressionGuard::DECISION_HOLD, $result['regression_guard']['decision']);
        $this->assertContains('after_snapshot', $result['regression_guard']['violated_floors']);
        $this->assertNull($result['control_plane']);
        $this->assertSame(AtlasExternalBrainCompressionWaveRunner::VERDICT_REGRESSED, $result['verdict']);
    }
}
