<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainValueDensityWaveBudgetRunner;
use PHPUnit\Framework\TestCase;

/**
 * Proves the ValueDensityWaveBudgetRunner composes six orphan value-density
 * and wave-budget organs into a single priority-optimization plan.
 *
 * AC1: a decayed low-value packet is demoted below a high-density one.
 * AC2: the wave proof+risk budget caps how much risky work is admitted.
 */
final class AtlasExternalBrainValueDensityWaveBudgetRunnerTest extends TestCase
{
    private AtlasExternalBrainValueDensityWaveBudgetRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new AtlasExternalBrainValueDensityWaveBudgetRunner;
    }

    public function test_decayed_low_value_packet_is_demoted_below_high_density_one(): void
    {
        // A high-impact task with low decay signals, and a low-impact task
        // with strong decay signals (age decay + no value proof).
        $result = $this->runner->plan([
            'decay_facts' => [
                'tasks' => [
                    [
                        'id' => 'high-value-task',
                        'queued_at_days_ago' => 2,
                        'has_value_proof' => true,
                        'impact_evidence' => 0.9,
                        'autonomy_gain' => 0.8,
                    ],
                    [
                        'id' => 'decayed-task',
                        'queued_at_days_ago' => 60,
                        'has_value_proof' => false,
                        'impact_evidence' => 0.1,
                        'autonomy_gain' => 0.1,
                        'repeated_family_count' => 5,
                    ],
                ],
            ],
            'ranking_input' => [
                'candidates' => [
                    ['task_id' => 'high-value-task', 'impact' => 0.9, 'implementation_size' => 30.0],
                    ['task_id' => 'decayed-task', 'impact' => 0.1, 'implementation_size' => 30.0],
                ],
                'claimable_count' => 2,
                'capacity' => 5,
            ],
            'budget_facts' => ['wave_tasks' => [], 'muscle_count' => 1],
            'risk_wave' => ['has_runnable_tests' => true, 'rollback_ready' => true],
            'scoreboard_facts' => [],
            'proof_samples' => [],
        ]);

        $this->assertSame(
            AtlasExternalBrainValueDensityWaveBudgetRunner::SCHEMA,
            $result['schema'],
        );

        // Decay: the high-value task should be kept; the decayed task should be demoted.
        $decayPerTask = $result['decay']['per_task'];
        $decayedTask = current(array_filter($decayPerTask, fn (array $t): bool => $t['task_id'] === 'decayed-task'));
        $this->assertNotFalse($decayedTask, 'decayed-task must have a decay entry');
        $this->assertGreaterThan(0.3, $decayedTask['value_decay'], 'decayed-task must have high value_decay');

        // Ranking: high-value task should be enqueued, decayed task likely deferred.
        $ranked = $result['ranking']['ranked_candidates'];
        $highValueCandidate = current(array_filter($ranked, fn (array $c): bool => $c['task_id'] === 'high-value-task'));
        $decayedCandidate = current(array_filter($ranked, fn (array $c): bool => $c['task_id'] === 'decayed-task'));
        $this->assertNotFalse($highValueCandidate, 'high-value-task must be ranked');
        $this->assertNotFalse($decayedCandidate, 'decayed-task must be ranked');

        // High value must be ranked above decayed (higher value_density or same rank order).
        foreach ($ranked as $entry) {
            // First entry (highest rank) should be the high-value task.
        }
        $this->assertSame(
            'high-value-task',
            $ranked[0]['task_id'],
            'high-value-task must be ranked first (above decayed-task)',
        );

        // The decayed task should be deferred (value_density below cutoff).
        $this->assertSame('defer', $decayedCandidate['decision']);
    }

    public function test_wave_proof_and_risk_budget_caps_risky_work(): void
    {
        // Wide blast radius + no runnable tests + no rollback readiness → budget caps.
        $result = $this->runner->plan([
            'decay_facts' => ['tasks' => []],
            'ranking_input' => [
                'candidates' => [],
                'claimable_count' => 0,
                'capacity' => 1,
            ],
            'budget_facts' => [
                'wave_tasks' => [
                    [
                        'task_id' => 'risky-task',
                        'risk_level' => 'high',
                        'acceptance_criteria' => ['ac-1', 'ac-2', 'ac-3', 'ac-4', 'ac-5', 'ac-6'],
                        'required_evidence' => ['ev-1', 'ev-2', 'ev-3'],
                        'refactor_blast_radius' => 10,
                        'model_weakness_score' => 0.9,
                        'expected_leverage' => 0.8,
                    ],
                ],
                'muscle_count' => 1,
            ],
            'risk_wave' => [
                'blast_radius' => 0.9,
                'worker_pressure' => 0.6,
                'has_runnable_tests' => false,
                'rollback_ready' => false,
                'high_value' => true,
            ],
            'scoreboard_facts' => [],
            'proof_samples' => [],
        ]);

        // Proof budget: high risk + wide blast + weak model → defer (compounding risk).
        $this->assertArrayHasKey('proof_budget', $result);
        $recommendations = $result['proof_budget']['proof_slimming_recommendations'] ?? [];
        $deferRecommendation = current(array_filter(
            $recommendations,
            static fn (array $r): bool => ($r['recommended_action'] ?? null) === 'defer',
        ));
        $this->assertNotFalse(
            $deferRecommendation,
            'risky task must be deferred by proof budget (compounding risk)',
        );

        // Risk budget: no runnable tests + no rollback → hold.
        $this->assertArrayHasKey('risk_budget', $result);
        $this->assertSame('hold', $result['risk_budget']['decision']);
        $this->assertContains(
            'missing_runnable_test_proof',
            $result['risk_budget']['reasons'],
        );
        $this->assertContains(
            'rollback_not_ready',
            $result['risk_budget']['reasons'],
        );
    }
}
