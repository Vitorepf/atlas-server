<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroLearningPolicyGuard;
use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroLearningPolicyViolation;
use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroReplenisherFeedback;
use Tests\TestCase;

/**
 * Proves the pétreo learning-policy guard: it throws for each of the 5 forbidden patterns, accepts a clean
 * fact-only artifact, and is enforced from AtlasMaestroReplenisherFeedback::renderFactsBlock() so a violating
 * artifact never reaches the rendered output.
 */
final class AtlasMaestroLearningPolicyGuardTest extends TestCase
{
    private function guard(): AtlasMaestroLearningPolicyGuard
    {
        return new AtlasMaestroLearningPolicyGuard;
    }

    private function assertViolation(string $reason, array $artifact): void
    {
        try {
            $this->guard()->assertSafe($artifact);
            $this->fail("expected violation '{$reason}' but none thrown");
        } catch (AtlasMaestroLearningPolicyViolation $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_refuses_composite_score(): void
    {
        $this->assertViolation('composite_score', ['facts' => [['bucket' => 'orphan', 'rank' => 1, 'total' => 20]]]);
    }

    public function test_refuses_imperative_advice(): void
    {
        $this->assertViolation('imperative_advice', ['note' => 'you should prefer orphan packets']);
    }

    public function test_refuses_sub_support_bucket(): void
    {
        $this->assertViolation('sub_support_bucket', ['facts' => [['bucket' => 'docgap', 'total' => 3, 'insufficient_support' => true]]]);
    }

    public function test_refuses_forbidden_scope(): void
    {
        $this->assertViolation('forbidden_scope', ['facts' => [['origin' => 'App\\Services\\Ai\\MarketingDomain\\Foo', 'total' => 20]]]);
    }

    public function test_refuses_task_field_override(): void
    {
        $this->assertViolation('task_field_override', ['acceptance_criteria' => ['must pass']]);
    }

    public function test_accepts_clean_fact_only_artifact(): void
    {
        $this->guard()->assertSafe([
            'facts' => [['dimension' => 'origin_kind', 'bucket' => 'orphan', 'delivered' => 14, 'total' => 22, 'insufficient_support' => false]],
            'block' => 'origin_kind=orphan: 14 delivered / 22 total (rate 0.64, support>=8)',
        ]);
        $this->addToAssertionCount(1); // no exception ⇒ clean artifact accepted
    }

    public function test_feedback_routes_through_guard_and_blocks_a_violating_artifact(): void
    {
        config(['atlas.maestro.closed_loop.feedback_enabled' => true]);

        // A supported bucket whose value references a FORBIDDEN scope ⇒ the guard must block the render.
        $miner = new class
        {
            public function mine(): array
            {
                return ['origin_kind' => ['App\\Services\\Ai\\Forge\\Thing' => ['dimension' => 'origin_kind', 'bucket' => 'App\\Services\\Ai\\Forge\\Thing', 'delivered' => 9, 'total' => 12, 'insufficient_support' => false, 'delivery_rate' => 0.75]]];
            }
        };

        $this->expectException(AtlasMaestroLearningPolicyViolation::class);
        (new AtlasMaestroReplenisherFeedback($miner))->renderFactsBlock();
    }
}
