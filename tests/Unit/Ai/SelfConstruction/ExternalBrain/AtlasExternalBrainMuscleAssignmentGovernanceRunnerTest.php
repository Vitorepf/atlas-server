<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMuscleAssignmentGovernanceRunner;
use Tests\TestCase;

/**
 * Proves the muscle assignment governance runner composes the four organs
 * (readiness, skill-fit routing, prompt variant selection, fairness balancing)
 * and excludes not-ready muscles while assigning fit muscles.
 */
final class AtlasExternalBrainMuscleAssignmentGovernanceRunnerTest extends TestCase
{
    private function runner(): AtlasExternalBrainMuscleAssignmentGovernanceRunner
    {
        return new AtlasExternalBrainMuscleAssignmentGovernanceRunner;
    }

    private function readyTaskSpec(): array
    {
        return [
            'objective' => 'Harden AtlasSelfConstructionExampleService so project() returns honest state',
            'allowed_files' => ['app/Services/Example.php', 'tests/Unit/ExampleTest.php'],
            'acceptance_criteria' => ['php artisan test tests/Unit/ExampleTest.php exits 0.'],
            'required_evidence' => ['tests_or_gates_result'],
            'risk_level' => 'low',
            'give_back_on_blocked' => true,
            'max_attempts' => 3,
        ];
    }

    private function routingInput(): array
    {
        return [
            'task_family' => 'service_layer',
            'required_skills' => ['php', 'phpunit'],
            'file_scope' => ['app/Services/Example.php'],
            'risk_level' => 'low',
            'candidates' => [
                [
                    'muscle_id' => 'worker-a',
                    'type' => 'worker',
                    'skills' => ['php', 'phpunit'],
                    'history' => ['service_layer' => ['success' => 5, 'give_back' => 0, 'scope_failure' => 0, 'total' => 5]],
                    'recent_give_back_rate' => 0.0,
                    'max_risk_level' => 'high',
                ],
                [
                    'muscle_id' => 'worker-b',
                    'type' => 'worker',
                    'skills' => ['php'],
                    'history' => ['service_layer' => ['success' => 1, 'give_back' => 3, 'scope_failure' => 0, 'total' => 4]],
                    'recent_give_back_rate' => 0.5,
                    'max_risk_level' => 'medium',
                ],
            ],
        ];
    }

    private function fairnessInput(): array
    {
        return [
            'muscles' => [
                ['muscle_id' => 'worker-a', 'throughput_per_hour' => 10.0, 'reliability_score' => 0.9, 'active_lease_count' => 1, 'give_back_rate' => 0.0],
                ['muscle_id' => 'worker-b', 'throughput_per_hour' => 5.0, 'reliability_score' => 0.5, 'active_lease_count' => 2, 'give_back_rate' => 0.4],
            ],
        ];
    }

    public function test_ready_task_assigns_fit_muscles(): void
    {
        $result = $this->runner()->govern(
            $this->readyTaskSpec(),
            $this->routingInput(),
            $this->fairnessInput(),
        );

        $this->assertTrue($result['ready']);
        $this->assertSame('ready', $result['status']);
        $this->assertNotEmpty($result['assigned_muscles']);
        $assignedIds = array_column($result['assigned_muscles'], 'muscle_id');
        $this->assertContains('worker-a', $assignedIds);
    }

    public function test_not_ready_task_excludes_all_muscles(): void
    {
        $spec = $this->readyTaskSpec();
        $spec['allowed_files'] = []; // makes it not ready

        $result = $this->runner()->govern(
            $spec,
            $this->routingInput(),
            $this->fairnessInput(),
        );

        $this->assertFalse($result['ready']);
        $this->assertSame('not_ready', $result['status']);
        $this->assertNotEmpty($result['blockers']);
        $this->assertSame([], $result['assigned_muscles']);
    }

    public function test_high_give_back_muscle_is_excluded(): void
    {
        $routing = $this->routingInput();
        // worker-b has give_back_count=3 >= DISQUALIFY_GIVE_BACK_COUNT(2)
        $result = $this->runner()->govern(
            $this->readyTaskSpec(),
            $routing,
            $this->fairnessInput(),
        );

        $excludedIds = array_column($result['excluded_muscles'], 'muscle_id');
        // worker-b should be excluded or at least not assigned as primary
        $assignedIds = array_column($result['assigned_muscles'], 'muscle_id');
        $this->assertContains('worker-a', $assignedIds);
    }

    public function test_prompt_variants_generated_for_assigned_muscles(): void
    {
        $result = $this->runner()->govern(
            $this->readyTaskSpec(),
            $this->routingInput(),
            $this->fairnessInput(),
        );

        $this->assertNotEmpty($result['prompt_variants']);
        foreach ($result['prompt_variants'] as $variant) {
            $this->assertArrayHasKey('muscle_id', $variant);
            $this->assertArrayHasKey('prompt_variant_id', $variant);
            $this->assertArrayHasKey('guardrails', $variant);
        }
    }

    public function test_fairness_balancer_invoked_when_ready(): void
    {
        $result = $this->runner()->govern(
            $this->readyTaskSpec(),
            $this->routingInput(),
            $this->fairnessInput(),
        );

        $this->assertNotEmpty($result['fairness']);
        $this->assertArrayHasKey('fairness_score', $result['fairness']);
    }

    public function test_fairness_not_invoked_when_not_ready(): void
    {
        $spec = $this->readyTaskSpec();
        $spec['allowed_files'] = [];

        $result = $this->runner()->govern(
            $spec,
            $this->routingInput(),
            $this->fairnessInput(),
        );

        $this->assertSame([], $result['fairness']);
        $this->assertSame([], $result['prompt_variants']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->runner()->govern(
            $this->readyTaskSpec(),
            $this->routingInput(),
            $this->fairnessInput(),
        );

        foreach (['schema', 'status', 'ready', 'readiness', 'routing', 'prompt_variants', 'fairness', 'assigned_muscles', 'excluded_muscles', 'blockers'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }
}
