<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\CapabilityRouteLifecycle;
use PHPUnit\Framework\TestCase;

final class CapabilityRouteLifecycleTest extends TestCase
{
    public function test_preregistration_is_immutable_and_promotion_before_real_outcome_is_blocked(): void
    {
        $lifecycle = new CapabilityRouteLifecycle;
        $assignment = $lifecycle->preregister($this->assignment());

        $blocked = $lifecycle->advance($assignment, 'promoted', [
            'causal_evaluation' => true, 'rollback' => 'route-v1', 'real_outcome' => false,
        ]);
        self::assertSame('held', $blocked['status']);
        self::assertContains('real_outcome_required', $blocked['blockers']);

        $mutated = $this->assignment();
        $mutated['arms'] = ['route-a', 'route-mutated'];
        $this->expectException(\InvalidArgumentException::class);
        $lifecycle->advance(array_merge($assignment, ['arms' => $mutated['arms']]), 'limited_traffic', []);
    }

    public function test_promotion_requires_preregistered_causal_evidence_and_rollback(): void
    {
        $lifecycle = new CapabilityRouteLifecycle;
        $assignment = $lifecycle->preregister($this->assignment());
        $limited = $lifecycle->advance($assignment, 'limited_traffic', ['real_execution' => true]);
        $causal = $lifecycle->advance($limited, 'causal_evaluation', ['outcome_observed' => true]);

        $promoted = $lifecycle->advance($causal, 'promoted', [
            'causal_evaluation' => true, 'real_outcome' => true,
            'outcome_hash' => str_repeat('d', 64), 'rollback' => 'route-v1',
        ]);
        self::assertSame('promoted', $promoted['state']);
        self::assertFalse($promoted['claim_eligible']);
    }

    public function test_intent_to_treat_counts_failures_and_late_regression_revokes_route(): void
    {
        $lifecycle = new CapabilityRouteLifecycle;
        $summary = $lifecycle->summarizeOutcomes([
            ['status' => 'success'], ['status' => 'timeout'], ['status' => 'refused'], ['status' => 'rollback'],
        ]);
        self::assertSame(4, $summary['denominator']);
        self::assertSame(3, $summary['failure_count']);

        $assignment = $lifecycle->preregister($this->assignment());
        $promoted = $lifecycle->advance(
            $lifecycle->advance($lifecycle->advance($assignment, 'limited_traffic', ['real_execution' => true]), 'causal_evaluation', ['outcome_observed' => true]),
            'promoted', ['causal_evaluation' => true, 'real_outcome' => true, 'outcome_hash' => str_repeat('d', 64), 'rollback' => 'route-v1'],
        );
        $revoked = $lifecycle->advance($promoted, 'revoked', ['late_regression' => true, 'regression_outcome_hash' => str_repeat('e', 64)]);

        self::assertSame('revoked', $revoked['state']);
        self::assertContains('late_regression', $revoked['reasons']);
    }

    public function test_preregistration_rejects_unequal_snapshots_resources_and_missing_bare_control(): void
    {
        $lifecycle = new CapabilityRouteLifecycle;

        $unequalSnapshot = $this->assignment();
        $unequalSnapshot['arm_specs'][1]['snapshot_hash'] = str_repeat('c', 64);
        $this->expectExceptionMessage('capability_assignment_unequal_snapshots');
        $lifecycle->preregister($unequalSnapshot);
    }

    public function test_preregistration_rejects_unequal_resources_provider_fork_and_missing_bare_control(): void
    {
        $lifecycle = new CapabilityRouteLifecycle;

        $unequalResources = $this->assignment();
        $unequalResources['arm_specs'][1]['resources'] = ['cpu' => 2];
        try {
            $lifecycle->preregister($unequalResources);
            self::fail('Expected unequal resources to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('capability_assignment_unequal_resources', $exception->getMessage());
        }

        $missingBare = $this->assignment();
        $missingBare['arm_specs'][1]['harness'] = 'atlas';
        try {
            $lifecycle->preregister($missingBare);
            self::fail('Expected missing bare control to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('capability_assignment_bare_control_required', $exception->getMessage());
        }
    }

    public function test_promotion_rejects_best_run_cherry_pick_and_provider_specific_fork(): void
    {
        $lifecycle = new CapabilityRouteLifecycle;
        $assignment = $lifecycle->preregister($this->assignment());
        $limited = $lifecycle->advance($assignment, 'limited_traffic', ['real_execution' => true]);
        $causal = $lifecycle->advance($limited, 'causal_evaluation', ['outcome_observed' => true]);

        $held = $lifecycle->advance($causal, 'promoted', [
            'causal_evaluation' => true, 'real_outcome' => true, 'rollback' => 'route-v1',
            'outcome_hash' => str_repeat('d', 64), 'best_run_only' => true,
            'provider_specific_fork' => true,
        ]);
        self::assertSame('held', $held['status']);
        self::assertContains('intent_to_treat_required', $held['blockers']);
        self::assertContains('shared_provider_port_required', $held['blockers']);
    }

    /** @return array<string,mixed> */
    private function assignment(): array
    {
        return [
            'route_id' => 'route-a', 'workspace_id' => 'atlas-server',
            'snapshot_hash' => str_repeat('a', 64), 'order_hash' => str_repeat('b', 64),
            'arms' => ['route-a', 'control'], 'metric' => 'success_rate',
            'observation_window' => ['from' => '2026-07-12T00:00:00Z', 'until' => '2026-07-19T00:00:00Z'],
            'resources' => ['cpu' => 1], 'provider_version' => 'native-v1',
            'arm_specs' => [
                ['arm_id' => 'route-a', 'snapshot_hash' => str_repeat('a', 64), 'resources' => ['cpu' => 1], 'model_version' => 'model-v1', 'provider_version' => 'native-v1', 'harness' => 'atlas', 'adapter_port' => 'capability-port-v1'],
                ['arm_id' => 'control', 'snapshot_hash' => str_repeat('a', 64), 'resources' => ['cpu' => 1], 'model_version' => 'model-v1', 'provider_version' => 'native-v1', 'harness' => 'bare', 'adapter_port' => 'capability-port-v1'],
            ],
        ];
    }
}
