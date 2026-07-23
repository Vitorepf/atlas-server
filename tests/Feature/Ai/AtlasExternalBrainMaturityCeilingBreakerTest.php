<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMaturityCeilingBreaker;
use Tests\TestCase;

final class AtlasExternalBrainMaturityCeilingBreakerTest extends TestCase
{
    private function breaker(): AtlasExternalBrainMaturityCeilingBreaker
    {
        return new AtlasExternalBrainMaturityCeilingBreaker;
    }

    private function nonUnlockingTasks(int $count): array
    {
        return array_fill(0, $count, ['unlocks_new_capability' => false, 'task_family' => 'family-a']);
    }

    public function test_ceiling_not_declared_from_too_little_data(): void
    {
        $result = $this->breaker()->analyze([
            'recent_tasks' => $this->nonUnlockingTasks(2),
            'stagnation_threshold' => 5,
        ]);

        $this->assertFalse($result['ceiling_detected']);
        $this->assertTrue($result['ceiling_evidence']['insufficient_data']);
    }

    public function test_ceiling_detected_from_stagnation_count(): void
    {
        $result = $this->breaker()->analyze([
            'recent_tasks' => $this->nonUnlockingTasks(5),
            'stagnation_threshold' => 5,
        ]);

        $this->assertTrue($result['ceiling_detected']);
        $this->assertSame(5, $result['ceiling_evidence']['non_unlocking_task_count']);
    }

    public function test_ceiling_detected_from_high_repeated_family_saturation_even_below_stagnation_threshold(): void
    {
        $tasks = [
            ['unlocks_new_capability' => false, 'task_family' => 'family-a'],
            ['unlocks_new_capability' => false, 'task_family' => 'family-a'],
            ['unlocks_new_capability' => false, 'task_family' => 'family-a'],
        ];

        $result = $this->breaker()->analyze([
            'recent_tasks' => $tasks,
            'stagnation_threshold' => 10,
        ]);

        $this->assertTrue($result['ceiling_detected']);
        $this->assertGreaterThanOrEqual(0.60, $result['ceiling_evidence']['saturation_score']);
    }

    public function test_eligible_jumps_require_bounded_blast_radius_risk_gates_and_prerequisites(): void
    {
        $result = $this->breaker()->analyze([
            'recent_tasks' => $this->nonUnlockingTasks(5),
            'proposed_capability_jumps' => [
                [
                    'name' => 'blast-too-high',
                    'blast_radius' => 0.9,
                    'risk_score' => 0.1,
                    'proof_gates' => ['phpunit'],
                    'prerequisites' => ['dep-a'],
                ],
                [
                    'name' => 'risk-too-high',
                    'blast_radius' => 0.1,
                    'risk_score' => 0.9,
                    'proof_gates' => ['phpunit'],
                    'prerequisites' => ['dep-a'],
                ],
                [
                    'name' => 'no-proof-gates',
                    'blast_radius' => 0.1,
                    'risk_score' => 0.1,
                    'proof_gates' => [],
                    'prerequisites' => ['dep-a'],
                ],
                [
                    'name' => 'no-prerequisites',
                    'blast_radius' => 0.1,
                    'risk_score' => 0.1,
                    'proof_gates' => ['phpunit'],
                    'prerequisites' => [],
                ],
                [
                    'name' => 'eligible-via-prerequisites-met',
                    'blast_radius' => 0.2,
                    'risk_score' => 0.2,
                    'proof_gates' => ['phpunit'],
                    'prerequisites' => [],
                    'prerequisites_met' => ['dep-a'],
                ],
            ],
        ]);

        $rejectedByName = [];
        foreach ($result['rejected_jumps'] as $r) {
            $rejectedByName[$r['name']] = $r['reasons'];
        }
        $this->assertContains('blast_radius_exceeds_bound', $rejectedByName['blast-too-high']);
        $this->assertContains('risk_score_exceeds_bound', $rejectedByName['risk-too-high']);
        $this->assertContains('no_proof_gates_defined', $rejectedByName['no-proof-gates']);
        $this->assertContains('no_prerequisites_or_prerequisites_met', $rejectedByName['no-prerequisites']);

        $this->assertNotNull($result['proposed_jump']);
        $this->assertSame('eligible-via-prerequisites-met', $result['proposed_jump']['name']);
    }

    public function test_proposed_jump_selects_lowest_risk_eligible_jump_and_lists_rejected_incremental_tasks(): void
    {
        $result = $this->breaker()->analyze([
            'recent_tasks' => [
                ['unlocks_new_capability' => false, 'task_family' => 'family-a'],
                ['unlocks_new_capability' => false, 'task_family' => 'family-a'],
                ['unlocks_new_capability' => false, 'task_family' => 'family-a'],
                ['unlocks_new_capability' => true, 'task_family' => 'family-b'],
            ],
            'stagnation_threshold' => 3,
            'proposed_capability_jumps' => [
                [
                    'name' => 'higher-risk',
                    'blast_radius' => 0.3,
                    'risk_score' => 0.5,
                    'proof_gates' => ['phpunit'],
                    'prerequisites' => ['dep-a'],
                ],
                [
                    'name' => 'lowest-risk',
                    'blast_radius' => 0.3,
                    'risk_score' => 0.1,
                    'proof_gates' => ['phpunit'],
                    'prerequisites' => ['dep-a'],
                ],
            ],
        ]);

        $this->assertSame('lowest-risk', $result['proposed_jump']['name']);
        $this->assertCount(3, $result['rejected_incremental_tasks']);
        foreach ($result['rejected_incremental_tasks'] as $task) {
            $this->assertFalse($task['unlocks_new_capability']);
        }
    }
}
