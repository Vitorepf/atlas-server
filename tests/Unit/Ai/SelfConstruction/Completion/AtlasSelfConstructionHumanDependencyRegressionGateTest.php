<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionHumanDependencyRegressionGate;
use Tests\TestCase;

class AtlasSelfConstructionHumanDependencyRegressionGateTest extends TestCase
{
    public function test_clean_atlas_native_facts_pass(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'atlas_native',
            'paths' => [
                ['id' => 'p1', 'kind' => 'ordinary', 'steady_state_required' => ['atlas_native']],
                ['id' => 'p2', 'kind' => 'ordinary', 'steady_state_required' => ['atlas_server']],
            ],
        ]);

        self::assertTrue($verdict['passed']);
        self::assertSame(AtlasSelfConstructionHumanDependencyRegressionGate::STATUS_PASSED, $verdict['status']);
        self::assertSame([], $verdict['blockers']);
    }

    public function test_blocks_when_ordinary_path_requires_non_atlas_actor(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'atlas_native',
            'paths' => [
                ['id' => 'merge_path', 'kind' => 'ordinary', 'steady_state_required' => ['operator']],
            ],
        ]);

        self::assertFalse($verdict['passed']);
        self::assertContains('steady_state_non_atlas_actor:path=merge_path:actor=operator', $verdict['blockers']);
    }

    public function test_advisory_bootstrap_label_does_not_block_under_atlas_native_owner(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'atlas_native',
            'paths' => [
                ['id' => 'init', 'kind' => 'bootstrap', 'steady_state_required' => ['operator']],
            ],
        ]);

        self::assertTrue($verdict['passed']);
        self::assertTrue($verdict['inspected_paths'][0]['advisory_exception_applied']);
    }

    public function test_advisory_emergency_label_does_not_block_under_atlas_native_owner(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'atlas_native',
            'paths' => [
                ['id' => 'pager', 'kind' => 'emergency', 'steady_state_required' => ['oncall_human']],
            ],
        ]);

        self::assertTrue($verdict['passed']);
    }

    public function test_visibility_label_also_advisory(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'atlas_native',
            'paths' => [
                ['id' => 'dashboard', 'kind' => 'visibility', 'steady_state_required' => ['operator']],
            ],
        ]);

        self::assertTrue($verdict['passed']);
    }

    public function test_blocks_when_final_runtime_owner_is_not_atlas_native(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'external_assistant',
            'paths' => [
                ['id' => 'p', 'kind' => 'ordinary', 'steady_state_required' => ['atlas_native']],
            ],
        ]);

        self::assertFalse($verdict['passed']);
        self::assertContains('final_runtime_owner_not_atlas_native:external_assistant', $verdict['blockers']);
    }

    public function test_advisory_label_does_NOT_apply_when_final_owner_is_wrong(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'external_assistant',
            'paths' => [
                ['id' => 'init', 'kind' => 'bootstrap', 'steady_state_required' => ['operator']],
            ],
        ]);

        self::assertFalse($verdict['passed']);
        self::assertContains('final_runtime_owner_not_atlas_native:external_assistant', $verdict['blockers']);
        self::assertContains('steady_state_non_atlas_actor:path=init:actor=operator', $verdict['blockers']);
    }
}
