<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationReadinessGate;
use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationSignalRunner;
use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationCampaignControlPlane;
use Tests\TestCase;

final class AtlasSelfConstructionSimplificationSignalRunnerTest extends TestCase
{
    // ── SignalRunner::run ──────────────────────────────────────────────

    public function test_runner_assembles_readiness_gate_consumable_facts(): void
    {
        $runner = new AtlasSelfConstructionSimplificationSignalRunner;
        $gate = new AtlasSelfConstructionSimplificationReadinessGate;

        $result = $runner->run([
            'organs' => [
                ['name' => 'A', 'capability_label' => 'cap', 'inputs' => ['i'], 'outputs' => ['o'], 'proof_refs' => ['p'], 'consumers' => ['c']],
                ['name' => 'B', 'capability_label' => 'cap', 'inputs' => ['i'], 'outputs' => ['o'], 'proof_refs' => ['p'], 'consumers' => ['c']],
            ],
            'consumers' => [],
            'before_after' => [
                'before' => ['cohesion' => 0.5, 'coupling' => 0.5, 'duplicated_contracts' => 1, 'entrypoint_count' => 2, 'proof_density' => 0.5],
                'after' => ['cohesion' => 0.5, 'coupling' => 0.5, 'duplicated_contracts' => 1, 'entrypoint_count' => 2, 'proof_density' => 0.5],
            ],
            'targets' => [],
            'rollback' => ['reversible' => true],
            'parity' => ['replacement_allowed' => true],
        ]);

        $this->assertArrayHasKey('facts', $result);
        $this->assertArrayHasKey('cluster', $result['facts']);
        $this->assertArrayHasKey('cohesion', $result['facts']);
        $this->assertArrayHasKey('consumer_impact', $result['facts']);
        $this->assertArrayHasKey('proof_coverage', $result['facts']);
        $this->assertArrayHasKey('rollback', $result['facts']);
        $this->assertArrayHasKey('parity', $result['facts']);

        // ReadinessGate should accept the bundle (no missing_sections)
        $gateResult = $gate->evaluate($result['facts']);
        $this->assertStringNotContainsString('missing_section', implode(' ', $gateResult['blockers']),
            'runner facts must include all core sections ReadinessGate requires');
    }

    public function test_runner_reflects_rollback_and_parity(): void
    {
        $runner = new AtlasSelfConstructionSimplificationSignalRunner;

        $result = $runner->run([
            'rollback' => ['reversible' => true],
            'parity' => ['replacement_allowed' => true],
        ]);

        $this->assertTrue($result['facts']['rollback']['reversible']);
        $this->assertTrue($result['facts']['parity']['replacement_allowed']);
    }

    public function test_runner_reflects_cohesion_worsening(): void
    {
        $runner = new AtlasSelfConstructionSimplificationSignalRunner;

        // Before score should be higher than after → cohesion worsens
        $result = $runner->run([
            'before_after' => [
                'before' => ['cohesion' => 0.9, 'coupling' => 0.1, 'duplicated_contracts' => 0, 'entrypoint_count' => 1, 'proof_density' => 0.8],
                'after' => ['cohesion' => 0.3, 'coupling' => 0.7, 'duplicated_contracts' => 3, 'entrypoint_count' => 5, 'proof_density' => 0.2],
            ],
        ]);

        $this->assertFalse($result['facts']['cohesion']['consolidation_improves_circuit'],
            'worsening cohesion must be reflected in the facts bundle');
        $this->assertLessThan(0, $result['facts']['cohesion']['delta']);
    }

    // ── CampaignControlPlane::planCampaign with simplification signals ─

    public function test_plan_campaign_over_cohesion_worsening_candidate_holds(): void
    {
        $ctrl = new AtlasSelfConstructionSimplificationCampaignControlPlane;

        $result = $ctrl->planCampaign([
            'candidates' => [
                [
                    'id' => 'test-candidate',
                    'action_type' => 'delete',
                    'risk' => 'low',
                    'redundancy_map' => ['clusters' => [['cluster_id' => 'c1', 'members' => ['a', 'b']]]],
                    'equivalence_dossier' => ['behavior_equivalence_proven' => true],
                    'deletion_plan' => ['safe' => true, 'targets' => ['a']],
                    'consumer_impact' => ['unsafe_consumers' => []],
                    'parity_matrix' => ['parity_verified' => true],
                    'rollback_receipts' => ['present' => true],
                    'replay_plan' => ['ready' => true],
                    'docs_sync' => ['required' => false],
                    // Simplification signal: cohesion worsens
                    'before_after' => [
                        'before' => ['cohesion' => 0.9, 'coupling' => 0.1, 'duplicated_contracts' => 0, 'entrypoint_count' => 1, 'proof_density' => 0.8],
                        'after' => ['cohesion' => 0.3, 'coupling' => 0.7, 'duplicated_contracts' => 3, 'entrypoint_count' => 5, 'proof_density' => 0.2],
                    ],
                ],
            ],
        ]);

        $this->assertContains('test-candidate', $result['held_candidates'],
            'cohesion-worsening candidate must be held');
        $this->assertNotContains('test-candidate', $result['deletion_first_candidates'],
            'cohesion-worsening candidate must not proceed to deletion first');
    }

    public function test_plan_campaign_passes_when_cohesion_improves(): void
    {
        // First: verify runner+gate pipeline allows the improving candidate directly
        $runner = new AtlasSelfConstructionSimplificationSignalRunner;
        $gate = new AtlasSelfConstructionSimplificationReadinessGate;

        $improvingCandidate = [
            'organs' => [
                ['name' => 'A', 'capability_label' => 'tc', 'inputs' => ['i'], 'outputs' => ['o'], 'proof_refs' => ['p'], 'consumers' => ['c'], 'responsibility_tags' => ['r'], 'tests' => ['t']],
                ['name' => 'B', 'capability_label' => 'tc', 'inputs' => ['i'], 'outputs' => ['o'], 'proof_refs' => ['p'], 'consumers' => ['c'], 'responsibility_tags' => ['r'], 'tests' => ['t']],
            ],
            'rollback' => ['reversible' => true],
            'parity' => ['replacement_allowed' => true],
            'before_after' => [
                'before' => ['cohesion' => 0.3, 'coupling' => 0.7, 'duplicated_contracts' => 3, 'entrypoint_count' => 5, 'proof_density' => 0.2],
                'after' => ['cohesion' => 0.9, 'coupling' => 0.1, 'duplicated_contracts' => 0, 'entrypoint_count' => 1, 'proof_density' => 0.8],
            ],
            'targets' => [
                ['name' => 't1', 'test_proof' => true, 'replay_proof' => true, 'runtime_proof' => true, 'docs_proof' => true, 'rollback_proof' => true],
            ],
        ];
        $runnerResult = $runner->run($improvingCandidate);
        $gateResult = $gate->evaluate($runnerResult['facts']);
        $this->assertSame('allow', $gateResult['decision'],
            'runner+gate pipeline must allow the improving candidate');

        // Then: verify planCampaign respects the gate
        $ctrl = new AtlasSelfConstructionSimplificationCampaignControlPlane;

        $candidate = array_merge($improvingCandidate, [
            'id' => 'improving-candidate',
            'action_type' => 'delete',
            'risk' => 'low',
            'redundancy_map' => ['clusters' => [['cluster_id' => 'c1', 'members' => ['a', 'b']]]],
            'equivalence_dossier' => ['behavior_equivalence_proven' => true],
            'deletion_plan' => ['safe' => true, 'targets' => ['a']],
            'consumer_impact' => ['unsafe_consumers' => []],
            'parity_matrix' => ['parity_verified' => true],
            'rollback_receipts' => ['present' => true],
            'replay_plan' => ['ready' => true],
            'docs_sync' => ['required' => false],
        ]);

        $result = $ctrl->planCampaign([
            'candidates' => [$candidate],
        ]);

        $this->assertContains('improving-candidate', $result['deletion_first_candidates'],
            'cohesion-improving candidate must proceed to deletion first');
        $this->assertNotContains('improving-candidate', $result['held_candidates']);
    }
}
