<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativePatchPlanner;
use Tests\TestCase;

/**
 * Proves AtlasSelfConstructionNativePatchPlanner::plan synthesizes a runnable
 * test file into target_files when the packet requires test generation.
 */
final class AtlasSelfConstructionNativePatchPlannerTestGenTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function genPacket(array $overrides = []): array
    {
        return array_merge([
            'objective' => 'Implement FooBarValidator with test coverage',
            'allowed_files' => ['app/Services/Foo/Bar/FooBarValidator.php'],
            'scope_files' => ['app/Services/Foo/Bar/FooBarValidator.php'],
            'task_shape' => ['kind' => 'service', 'side_effects' => 'none'],
            'provider_reasoning_required' => false,
            'context' => ['namespace' => 'App\\Services\\Foo\\Bar', 'class_name' => 'FooBarValidator'],
            'acceptance_criteria' => ['php artisan test tests/Unit/Services/Foo/Bar/FooBarValidatorTest.php'],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
            'implementation_target' => 'app/Services/Foo/Bar/FooBarValidator.php',
        ], $overrides);
    }

    public function test_plan_synthesizes_test_file_when_test_gen_required(): void
    {
        // Objective contains "test", required_evidence includes tests_or_gates_result,
        // and no test_files supplied → test generation is required.
        $plan = (new AtlasSelfConstructionNativePatchPlanner)->plan($this->genPacket());

        $this->assertNotEmpty($plan['target_files']);
        $this->assertContains('app/Services/Foo/Bar/FooBarValidator.php', $plan['target_files']);

        // Must include the inferred test file.
        $this->assertContains('tests/Unit/Services/Foo/Bar/FooBarValidatorTest.php', $plan['target_files']);

        // Test plan must reference the synthesized test.
        $this->assertContains(
            'tests/Unit/Services/Foo/Bar/FooBarValidatorTest.php',
            $plan['test_plan']['test_files'],
        );
    }

    public function test_no_synthesis_when_test_files_already_supplied(): void
    {
        // When test_files are already provided, the planner must not re-synthesize.
        $existingTest = 'tests/Existing/ValidatorTest.php';
        $plan = (new AtlasSelfConstructionNativePatchPlanner)->plan($this->genPacket([
            'test_files' => [$existingTest],
        ]));

        // The existing test must appear unchanged.
        $this->assertContains($existingTest, $plan['test_plan']['test_files']);
        // The synthesized path should NOT appear (no requirement to add it).
        $this->assertNotContains(
            'tests/Unit/Services/Foo/Bar/FooBarValidatorTest.php',
            $plan['test_plan']['test_files'],
        );
    }

    public function test_no_synthesis_when_objective_lacks_test_indicator(): void
    {
        // Objective does not contain "test" or "Test" → no synthesis even with
        // tests_or_gates_result in required_evidence.
        $plan = (new AtlasSelfConstructionNativePatchPlanner)->plan($this->genPacket([
            'objective' => 'Implement FooBarValidator refactoring',
        ]));

        $inferred = 'tests/Unit/Services/Foo/Bar/FooBarValidatorTest.php';
        $this->assertNotContains($inferred, $plan['test_plan']['test_files']);
    }

    public function test_no_synthesis_when_required_evidence_lacks_tests_or_gates(): void
    {
        $plan = (new AtlasSelfConstructionNativePatchPlanner)->plan($this->genPacket([
            'required_evidence' => ['implementation_notes'],
        ]));

        $inferred = 'tests/Unit/Services/Foo/Bar/FooBarValidatorTest.php';
        $this->assertNotContains($inferred, $plan['test_plan']['test_files']);
    }

    public function test_plan_id_is_stable_with_synthesized_test(): void
    {
        // Identical input must produce identical plan_id.
        $a = (new AtlasSelfConstructionNativePatchPlanner)->plan($this->genPacket());
        $b = (new AtlasSelfConstructionNativePatchPlanner)->plan($this->genPacket());

        $this->assertSame($a['plan_id'], $b['plan_id']);
        $this->assertSame($a['target_files'], $b['target_files']);
    }
}
