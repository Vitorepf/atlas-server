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

    /** @return array<string,mixed> */
    private function assignment(): array
    {
        return [
            'route_id' => 'route-a', 'workspace_id' => 'atlas-server',
            'snapshot_hash' => str_repeat('a', 64), 'order_hash' => str_repeat('b', 64),
            'arms' => ['route-a', 'control'], 'metric' => 'success_rate',
            'observation_window' => ['from' => '2026-07-12T00:00:00Z', 'until' => '2026-07-19T00:00:00Z'],
            'resources' => ['cpu' => 1], 'provider_version' => 'native-v1',
        ];
    }
}
