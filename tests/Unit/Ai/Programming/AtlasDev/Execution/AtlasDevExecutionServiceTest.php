<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Execution;

use App\Services\Ai\Programming\AtlasDev\Execution\ConfirmedDevRun;
use App\Services\Ai\Programming\AtlasDev\Execution\DevIntent;
use App\Services\Ai\Programming\AtlasDev\Execution\DevKernelExecutionPort;
use App\Services\Ai\Programming\AtlasDev\Execution\DevPlan;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecision;
use App\Services\Ai\EngineeringKernel\EngineeringOutcome;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AtlasDevExecutionServiceTest extends TestCase
{
    public function test_dev_intent_is_immutable_and_binds_product_spec_world_and_authority(): void
    {
        $intent = DevIntent::fromArray([
            'raw_goal' => 'Add a bounded backend behavior',
            'workspace' => '/tmp/example-repo',
            'operator_id' => 'operator-1',
            'product_intent_hash' => str_repeat('a', 64),
            'spec_hash' => str_repeat('b', 64),
            'world_model_snapshot_hash' => str_repeat('c', 64),
            'authority_hash' => str_repeat('d', 64),
            'risk_class' => 'R5',
            'duration_regime' => 'interactive',
            'topology' => 'single',
        ]);

        $this->assertSame('R5', $intent->riskClass);
        $this->assertSame('interactive', $intent->durationRegime);
        $this->assertSame('single', $intent->topology);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $intent->intentHash);
        $this->assertSame($intent->intentHash, DevIntent::fromArray($intent->toArray())->intentHash);
    }

    public function test_unconfirmed_run_cannot_be_created_from_a_different_authority(): void
    {
        $intent = DevIntent::fromArray($this->validIntent());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dev_run_authority_mismatch');

        ConfirmedDevRun::fromIntent($intent, 'operator-1', str_repeat('e', 64));
    }

    public function test_r5_requires_explicit_operator_authority_and_confirmation_token(): void
    {
        $intent = DevIntent::fromArray($this->validIntent());

        $run = ConfirmedDevRun::fromIntent($intent, 'operator-1', str_repeat('d', 64));

        $this->assertSame($intent->intentHash, $run->intentHash);
        $this->assertSame($intent->authorityHash, $run->authorityHash);
        $this->assertSame('operator-1', $run->operatorId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $run->runHash);
    }

    public function test_market_decision_hash_is_bound_to_dev_intent(): void
    {
        $intent = DevIntent::fromArray(array_replace($this->validIntent(), ['market_decision_hash' => str_repeat('e', 64)]));

        self::assertSame(str_repeat('e', 64), $intent->marketDecisionHash);
        self::assertSame($intent->marketDecisionHash, DevIntent::fromArray($intent->toArray())->marketDecisionHash);
    }

    public function test_dev_intent_rejects_unknown_duration_regime_and_topology(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dev_intent_duration_regime_invalid');

        DevIntent::fromArray(array_replace($this->validIntent(), ['duration_regime' => 'instant_patch']));
    }

    public function test_dev_intent_rejects_unknown_topology(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dev_intent_topology_invalid');

        DevIntent::fromArray(array_replace($this->validIntent(), ['topology' => 'unbounded_fanout']));
    }

    public function test_blocked_plan_never_enters_the_shared_kernel(): void
    {
        $calls = 0;
        $kernel = new class($calls) implements DevKernelExecutionPort
        {
            public function __construct(private int &$calls) {}

            public function execute(ConfirmedDevRun $run, DevPlan $plan): EngineeringOutcome
            {
                $this->calls++;
                throw new \LogicException('kernel must not be called for a blocked plan');
            }
        };

        $intent = DevIntent::fromArray($this->validIntent());
        $result = $this->service($kernel)->run(
            ConfirmedDevRun::fromIntent($intent, 'operator-1', str_repeat('d', 64)),
            $this->plan($intent, RoutingDecision::BLOCKED),
        );

        self::assertSame('blocked', $result->status);
        self::assertSame('dev_plan_blocked', $result->reason);
        self::assertSame(0, $calls);
    }

    public function test_plan_bound_to_another_intent_is_rejected_before_kernel(): void
    {
        $calls = 0;
        $kernel = new class($calls) implements DevKernelExecutionPort
        {
            public function __construct(private int &$calls) {}

            public function execute(ConfirmedDevRun $run, DevPlan $plan): EngineeringOutcome
            {
                $this->calls++;
                throw new \LogicException('mismatched plan must not reach kernel');
            }
        };

        $intent = DevIntent::fromArray($this->validIntent());
        $otherIntent = DevIntent::fromArray(array_replace($this->validIntent(), ['raw_goal' => 'different intent']));
        $result = $this->service($kernel)->run(
            ConfirmedDevRun::fromIntent($intent, 'operator-1', str_repeat('d', 64)),
            $this->plan($otherIntent, RoutingDecision::ATLAS_DEV_FAST_PATH),
        );

        self::assertSame('blocked', $result->status);
        self::assertSame('dev_plan_intent_mismatch', $result->reason);
        self::assertSame(0, $calls);
    }

    public function test_forge_handoff_is_deterministic_and_does_not_enter_the_kernel(): void
    {
        $calls = 0;
        $kernel = new class($calls) implements DevKernelExecutionPort
        {
            public function __construct(private int &$calls) {}

            public function execute(ConfirmedDevRun $run, DevPlan $plan): EngineeringOutcome
            {
                $this->calls++;
                throw new \LogicException('forge handoff must not enter the Dev kernel');
            }
        };

        $intent = DevIntent::fromArray($this->validIntent());
        $run = ConfirmedDevRun::fromIntent($intent, 'operator-1', str_repeat('d', 64));
        $plan = $this->plan($intent, RoutingDecision::FORGE_PROMOTION_PREVIEW);
        $service = $this->service($kernel);

        $first = $service->run($run, $plan);
        $second = $service->run($run, $plan);

        self::assertSame('forge_handoff_required', $first->status);
        self::assertSame($first->runHash, $second->runHash);
        self::assertSame($first->planHash, $second->planHash);
        self::assertSame($first->details, $second->details);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($first->details, 'handoff.handoff_hash'));
        self::assertSame(0, $calls);
    }

    public function test_shared_kernel_failure_is_blocked_with_a_stable_failure_reason(): void
    {
        $kernel = new class implements DevKernelExecutionPort
        {
            public function execute(ConfirmedDevRun $run, DevPlan $plan): EngineeringOutcome
            {
                throw new \RuntimeException('provider temporarily unavailable');
            }
        };

        $intent = DevIntent::fromArray($this->validIntent());
        $result = $this->service($kernel)->run(
            ConfirmedDevRun::fromIntent($intent, 'operator-1', str_repeat('d', 64)),
            $this->plan($intent, RoutingDecision::ATLAS_DEV_FAST_PATH),
        );

        self::assertSame('blocked', $result->status);
        self::assertSame('shared_kernel_execution_failed', $result->reason);
        self::assertSame('RuntimeException', $result->details['exception']);
    }

    /** @return array<string,mixed> */
    private function validIntent(): array
    {
        return [
            'raw_goal' => 'Change a backend behavior', 'workspace' => '/tmp/example-repo', 'operator_id' => 'operator-1',
            'product_intent_hash' => str_repeat('a', 64), 'spec_hash' => str_repeat('b', 64),
            'world_model_snapshot_hash' => str_repeat('c', 64), 'authority_hash' => str_repeat('d', 64),
            'risk_class' => 'R5', 'duration_regime' => 'interactive', 'topology' => 'single',
        ];
    }

    private function service(DevKernelExecutionPort $kernel): \App\Services\Ai\Programming\AtlasDev\Execution\AtlasDevExecutionService
    {
        $orchestrator = new class extends AtlasDevFastPathOrchestrator
        {
            public function __construct() {}

            public function planOnly(string $surfaceId, string $workspace, string $rawIntent, array $userConstraints = [], array $surfaceHints = []): PlanOnlyResult
            {
                throw new \LogicException('plan must be supplied by the confirmed caller in this test');
            }
        };

        return new \App\Services\Ai\Programming\AtlasDev\Execution\AtlasDevExecutionService($orchestrator, $kernel);
    }

    private function plan(DevIntent $intent, string $routingKind): DevPlan
    {
        $result = (new ReflectionClass(PlanOnlyResult::class))->newInstanceWithoutConstructor();
        $routing = new RoutingDecision($routingKind, ['fixture'], $routingKind === RoutingDecision::BLOCKED ? ['fixture_blocker'] : []);
        $routingProperty = (new ReflectionClass(PlanOnlyResult::class))->getProperty('routing');
        $routingProperty->setValue($result, $routing);
        (new ReflectionClass(PlanOnlyResult::class))->getProperty('blockers')->setValue(
            $result,
            $routingKind === RoutingDecision::BLOCKED ? ['fixture_blocker'] : [],
        );

        $plan = (new ReflectionClass(DevPlan::class))->newInstanceWithoutConstructor();
        foreach (['intent' => $intent, 'result' => $result, 'planHash' => hash('sha256', $routingKind)] as $property => $value) {
            (new ReflectionClass(DevPlan::class))->getProperty($property)->setValue($plan, $value);
        }

        return $plan;
    }
}
