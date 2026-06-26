<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\Discovery\Supply\AtlasLoopRefillerTaskClassifier;
use Tests\TestCase;

class AtlasLoopRefillerTaskClassifierTest extends TestCase
{
    private function makeTask(array $payload): AtlasLoopTask
    {
        $task = new AtlasLoopTask();
        $task->payload = $payload;

        return $task;
    }

    public function test_is_proxy_refactor_returns_false_for_non_refactor_kind(): void
    {
        $task = $this->makeTask(['objective_kind' => 'feature']);

        self::assertFalse(AtlasLoopRefillerTaskClassifier::isProxyRefactorTask($task));
    }

    public function test_is_proxy_refactor_returns_false_for_coverage_kind(): void
    {
        $task = $this->makeTask(['objective_kind' => 'characterization_test']);

        self::assertFalse(AtlasLoopRefillerTaskClassifier::isProxyRefactorTask($task));
    }

    public function test_is_proxy_refactor_returns_true_when_no_material_proof(): void
    {
        $task = $this->makeTask(['objective_kind' => 'refactor']);

        self::assertTrue(AtlasLoopRefillerTaskClassifier::isProxyRefactorTask($task));
    }

    public function test_is_proxy_refactor_returns_false_when_revert_recheck_true(): void
    {
        $task = $this->makeTask(['objective_kind' => 'refactor', 'revert_recheck' => true]);

        self::assertFalse(AtlasLoopRefillerTaskClassifier::isProxyRefactorTask($task));
    }

    public function test_is_proxy_refactor_returns_false_when_acceptance_revert_recheck_true(): void
    {
        $task = $this->makeTask([
            'objective_kind' => 'refactor',
            'acceptance' => ['revert_recheck' => true],
        ]);

        self::assertFalse(AtlasLoopRefillerTaskClassifier::isProxyRefactorTask($task));
    }

    public function test_is_proxy_refactor_returns_false_when_acceptance_red_required_true(): void
    {
        $task = $this->makeTask([
            'objective_kind' => 'refactor',
            'acceptance' => ['red_required' => true],
        ]);

        self::assertFalse(AtlasLoopRefillerTaskClassifier::isProxyRefactorTask($task));
    }

    public function test_is_proxy_refactor_returns_false_when_governed_triple_all_true(): void
    {
        $task = $this->makeTask([
            'objective_kind' => 'refactor',
            'is_self_improvement' => true,
            'complexity_proof' => true,
            'quality_bar_gate' => true,
        ]);

        self::assertFalse(AtlasLoopRefillerTaskClassifier::isProxyRefactorTask($task));
    }

    public function test_is_proxy_refactor_returns_true_when_triple_incomplete(): void
    {
        $task = $this->makeTask([
            'objective_kind' => 'refactor',
            'is_self_improvement' => true,
            'complexity_proof' => true,
            // missing quality_bar_gate
        ]);

        self::assertTrue(AtlasLoopRefillerTaskClassifier::isProxyRefactorTask($task));
    }

    public function test_is_proxy_refactor_returns_false_when_dedup_proof_pair(): void
    {
        $task = $this->makeTask([
            'objective_kind' => 'refactor',
            'dedup_proof' => true,
            'acceptance' => ['dedup_proof' => true],
        ]);

        self::assertFalse(AtlasLoopRefillerTaskClassifier::isProxyRefactorTask($task));
    }

    public function test_is_proxy_refactor_returns_true_when_dedup_proof_only_payload(): void
    {
        $task = $this->makeTask([
            'objective_kind' => 'refactor',
            'dedup_proof' => true,
        ]);

        self::assertTrue(AtlasLoopRefillerTaskClassifier::isProxyRefactorTask($task));
    }

    public function test_is_proxy_refactor_treats_string_true_as_true(): void
    {
        $task = $this->makeTask([
            'objective_kind' => 'refactor',
            'revert_recheck' => 'yes',
        ]);

        self::assertFalse(AtlasLoopRefillerTaskClassifier::isProxyRefactorTask($task));
    }

    public function test_task_is_coverage_returns_true_for_characterization_shape(): void
    {
        $task = $this->makeTask(['objective_kind' => 'characterization_test']);

        self::assertTrue(AtlasLoopRefillerTaskClassifier::taskIsCoverage($task));
    }

    public function test_task_is_coverage_returns_true_when_contains_characterization(): void
    {
        $task = $this->makeTask(['objective_kind' => 'some_characterization_thing']);

        self::assertTrue(AtlasLoopRefillerTaskClassifier::taskIsCoverage($task));
    }

    public function test_task_is_coverage_returns_false_for_non_coverage(): void
    {
        $task = $this->makeTask(['objective_kind' => 'refactor']);

        self::assertFalse(AtlasLoopRefillerTaskClassifier::taskIsCoverage($task));
    }

    public function test_task_is_coverage_returns_false_for_empty_kind(): void
    {
        $task = $this->makeTask([]);

        self::assertFalse(AtlasLoopRefillerTaskClassifier::taskIsCoverage($task));
    }
}
