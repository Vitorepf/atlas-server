<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderEffortPolicy;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderEffortPolicyDriverDecorator;
use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class AtlasLoopProviderEffortPolicyTest extends TestCase
{
    public function test_policy_resolves_task_classes_deterministically(): void
    {
        $policy = new AtlasLoopProviderEffortPolicy;
        $cases = [
            ['verification', '', 0, 0, 'low', 'default_low'],
            ['characterization_test', '', 0, 0, 'low', 'default_low'],
            ['refactor_extract_class', '', 0, 0, 'medium', 'class_medium'],
            ['architect_phase', '', 0, 0, 'high', 'architect_high'],
            ['unknown', '', 2, 0, 'medium', 'retry_escalation'],
            ['unknown', 'merge_readiness', 0, 0, 'high', 'architect_high'],
        ];

        foreach ($cases as [$objectiveKind, $targetKind, $attemptIndex, $priorFailures, $effort, $reason]) {
            $decision = $policy->resolve([
                'objective_kind' => $objectiveKind,
                'target_kind' => $targetKind,
                'attempt_index' => $attemptIndex,
                'prior_failures' => $priorFailures,
                'routed_provider_tier' => 'cheap',
            ]);

            $this->assertSame('atlas.loop.provider_effort.v1', $decision['schema']);
            $this->assertSame($effort, $decision['effort']);
            $this->assertSame($reason, $decision['reason']);
            $this->assertSame('cheap', $decision['routed_provider_tier']);
        }
    }

    public function test_driver_decorator_flag_off_uses_configured_default_effort_for_every_task_class(): void
    {
        config([
            'atlas.loop.provider_effort_policy_enabled' => false,
            'atlas.ai.hermes.default_reasoning_effort' => 'medium',
        ]);
        $inner = new CapturingEffortDriver;
        $driver = new AtlasLoopProviderEffortPolicyDriverDecorator($inner, new AtlasLoopProviderEffortPolicy);

        foreach (['verification', 'characterization', 'refactor', 'architect'] as $kind) {
            $driver->attempt('surface', sys_get_temp_dir(), "Run {$kind} task", [], ['objective_kind' => $kind]);
        }

        $this->assertSame(['medium', 'medium', 'medium', 'medium'], array_column($inner->calls, 'reasoning_effort'));
    }

    public function test_driver_decorator_flag_on_sets_low_for_verification_and_high_for_architect(): void
    {
        config(['atlas.loop.provider_effort_policy_enabled' => true]);
        $inner = new CapturingEffortDriver;
        $driver = new AtlasLoopProviderEffortPolicyDriverDecorator($inner, new AtlasLoopProviderEffortPolicy);

        $driver->attempt('surface', sys_get_temp_dir(), 'verification class task', [], ['routed_provider_tier' => 'cheap']);
        $driver->attempt('surface', sys_get_temp_dir(), 'architect class task', [], ['routed_provider_tier' => 'strong']);

        $this->assertSame('low', $inner->calls[0]['reasoning_effort']);
        $this->assertSame('high', $inner->calls[1]['reasoning_effort']);
        $this->assertSame('atlas.loop.provider_effort.v1', $inner->calls[1]['provider_effort_policy']['schema']);
    }

    public function test_supervisor_applies_effort_payload_with_flag_on_and_off_without_running_a_campaign(): void
    {
        $task = new AtlasLoopTask;
        $task->payload = ['objective_kind' => 'architect_phase', 'provider_tier' => 'strong'];
        $task->attempts = 0;
        $supervisor = (new ReflectionClass(AtlasLoopCampaignSupervisor::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AtlasLoopCampaignSupervisor::class, 'applyProviderEffort');
        $method->setAccessible(true);

        config([
            'atlas.loop.provider_effort_policy_enabled' => false,
            'atlas.ai.hermes.default_reasoning_effort' => 'medium',
        ]);
        $method->invoke($supervisor, $task);
        $this->assertSame('medium', $task->payload['reasoning_effort']);

        config(['atlas.loop.provider_effort_policy_enabled' => true]);
        $method->invoke($supervisor, $task);
        $this->assertSame('high', $task->payload['reasoning_effort']);
        $this->assertTrue($task->payload['provider_effort_policy']['enabled']);
    }
}

final class CapturingEffortDriver implements LoopExecutionDriver
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
    {
        $this->calls[] = $surfaceHints;

        return ['status' => 'completed'];
    }
}
