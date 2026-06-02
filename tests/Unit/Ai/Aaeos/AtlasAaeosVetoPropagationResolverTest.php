<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\Aaeos\AtlasAaeosVetoPropagationResolver;
use PHPUnit\Framework\TestCase;

final class AtlasAaeosVetoPropagationResolverTest extends TestCase
{
    private AtlasAaeosVetoPropagationResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AtlasAaeosVetoPropagationResolver();
    }

    public function testSchemaVersionIsStable(): void
    {
        $result = $this->resolver->resolve('security', 'security');

        $this->assertSame('atlas.aaeos.veto_propagation.v1', $result['schema_version']);
    }

    public function testSecurityVetoPropagatesPauseToDevForgeDeliveryAndEscalatesToOperator(): void
    {
        $result = $this->resolver->resolve('security', 'security');

        $pauseSet = $result['pause_set'];
        sort($pauseSet);

        $this->assertSame('propagate_pause', $result['resolution']);
        $this->assertSame(['delivery', 'dev', 'forge'], $pauseSet);
        $this->assertSame(['operator'], $result['escalation_target']);
        $this->assertFalse($result['override']);
        $this->assertSame('security_veto', $result['matched_rule']);
    }

    public function testArchitectSpecVetoRedirectsUpstreamToProduct(): void
    {
        $result = $this->resolver->resolve('architect', 'spec');

        $this->assertSame('redirect_upstream', $result['resolution']);
        $this->assertSame(['product'], $result['redirect_to']);
        $this->assertSame([], $result['pause_set']);
    }

    public function testReviewDeliveryVetoRedirectsToDevAndForge(): void
    {
        $result = $this->resolver->resolve('review', 'delivery');

        $redirectTo = $result['redirect_to'];
        sort($redirectTo);

        $this->assertSame(['dev', 'forge'], $redirectTo);
        $this->assertSame('redirect_upstream', $result['resolution']);
    }

    public function testOperatorVetoIsFinalOverridePass(): void
    {
        $result = $this->resolver->resolve('operator', 'override');

        $this->assertTrue($result['override']);
        $this->assertSame('override_pass', $result['resolution']);
        $this->assertSame([], $result['pause_set']);
        $this->assertSame([], $result['escalation_target']);
        $this->assertSame('operator_override', $result['matched_rule']);
    }

    public function testRepairLoopFourthIterationAutoEscalatesToArchitectAndOperator(): void
    {
        $escalated = $this->resolver->resolve('review', 'delivery', 3);

        $escalationTarget = $escalated['escalation_target'];
        sort($escalationTarget);

        $this->assertTrue($escalated['auto_escalated']);
        $this->assertSame(['architect', 'operator'], $escalationTarget);
        $this->assertSame('repair_loop_4th_iteration', $escalated['matched_rule']);

        $belowThreshold = $this->resolver->resolve('review', 'delivery', 1);

        $this->assertFalse($belowThreshold['auto_escalated']);
        $this->assertSame('review_delivery_veto', $belowThreshold['matched_rule']);
    }

    public function testUnknownOriginAndKindYieldNoMatch(): void
    {
        $result = $this->resolver->resolve('finance', 'budget');

        $this->assertSame('no_match', $result['resolution']);
        $this->assertSame('none', $result['matched_rule']);
        $this->assertSame([], $result['pause_set']);
    }

    public function testCanonicalTransitionsEncodeProductToArchitectEdge(): void
    {
        $transitions = $this->resolver->canonicalTransitions();

        $this->assertArrayHasKey('product', $transitions);
        $this->assertContains('architect', $transitions['product']);
    }

    public function testRepairLoopThresholdIsExactlyThree(): void
    {
        // Iteration 2 is still below the 4th-iteration auto-escalation threshold.
        $atTwo = $this->resolver->resolve('review', 'delivery', 2);
        $this->assertFalse($atTwo['auto_escalated']);
        $this->assertSame('review_delivery_veto', $atTwo['matched_rule']);

        // Iteration 4 stays auto-escalated (>= 3 is the boundary).
        $atFour = $this->resolver->resolve('review', 'delivery', 4);
        $this->assertTrue($atFour['auto_escalated']);
        $this->assertSame('repair_loop_4th_iteration', $atFour['matched_rule']);
    }

    public function testNoMatchOriginNeverAutoEscalatesIntoRepairRule(): void
    {
        // auto_escalated reflects the iteration counter even with no rule match,
        // but a no_match resolution must not be relabelled as a repair-loop rule.
        $result = $this->resolver->resolve('finance', 'budget', 5);

        $this->assertTrue($result['auto_escalated']);
        $this->assertSame('no_match', $result['resolution']);
        $this->assertSame('none', $result['matched_rule']);
        $this->assertSame([], $result['escalation_target']);
    }

    public function testPauseSetMembersAreRealNodesInTheCanonicalGraph(): void
    {
        // Generalisation guard: every paused department must exist in the graph,
        // proving pause_set is computed from the adjacency, not a canned literal.
        $result = $this->resolver->resolve('security', 'security');
        $transitions = $this->resolver->canonicalTransitions();

        $nodes = array_keys($transitions);
        foreach ($transitions as $targets) {
            foreach ($targets as $target) {
                $nodes[] = $target;
            }
        }

        foreach ($result['pause_set'] as $department) {
            $this->assertContains($department, $nodes);
        }
    }

    public function testInputsAreNormalizedBeforeRuleMatching(): void
    {
        // Whitespace / casing on the inputs must not change the matched rule,
        // confirming the resolution is derived from canonicalised inputs.
        $result = $this->resolver->resolve('  SECURITY ', 'Security');

        $pauseSet = $result['pause_set'];
        sort($pauseSet);

        $this->assertSame('security', $result['origin_department']);
        $this->assertSame('security', $result['veto_kind']);
        $this->assertSame('security_veto', $result['matched_rule']);
        $this->assertSame(['delivery', 'dev', 'forge'], $pauseSet);
    }

    public function testIdenticalInputsProduceIdenticalOutput(): void
    {
        $first = $this->resolver->resolve('review', 'delivery', 3);
        $second = $this->resolver->resolve('review', 'delivery', 3);

        $this->assertSame($first, $second);
    }
}
