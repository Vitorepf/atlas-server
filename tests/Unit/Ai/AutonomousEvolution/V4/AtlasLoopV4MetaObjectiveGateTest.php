<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V4;

use App\Services\Ai\AutonomousEvolution\V4\AtlasLoopV4MetaObjectiveGate;
use PHPUnit\Framework\TestCase;

/**
 * Proves the V4 meta-objective gate: refuses non-originated upstream, refuses citing a confounded capability
 * dimension (enumerating ALL offenders), refuses a dead capability signal, and admits the clean happy path.
 */
final class AtlasLoopV4MetaObjectiveGateTest extends TestCase
{
    private function gate(): AtlasLoopV4MetaObjectiveGate
    {
        return new AtlasLoopV4MetaObjectiveGate;
    }

    public function test_refuses_non_originated_upstream(): void
    {
        $out = $this->gate()->admit(['originated' => false], ['attributed' => true, 'unattributed_dimensions' => []]);

        $this->assertFalse($out['admitted']);
        $this->assertSame('upstream_not_originated', $out['refuse_reason']);
    }

    public function test_refuses_citation_to_unattributed_dimension(): void
    {
        $out = $this->gate()->admit(
            ['originated' => true, 'cited_facts' => ['capability.noisy_dim=0.4', 'evidence.tests=12']],
            ['attributed' => true, 'unattributed_dimensions' => ['noisy_dim']],
        );

        $this->assertFalse($out['admitted']);
        $this->assertSame('cites_confounded_capability_dimension', $out['refuse_reason']);
        $this->assertSame(['capability.noisy_dim=0.4'], $out['confounded_citations']);
    }

    public function test_confounded_citations_enumerates_all_offenders(): void
    {
        $out = $this->gate()->admit(
            ['originated' => true, 'cited_facts' => ['capability.a=1', 'capability.b=2', 'capability.ok=3', 'evidence.x=9']],
            ['attributed' => true, 'unattributed_dimensions' => ['a', 'b']],
        );

        $this->assertSame('cites_confounded_capability_dimension', $out['refuse_reason']);
        $this->assertSame(['capability.a=1', 'capability.b=2'], $out['confounded_citations'], 'ALL offenders, not just the first');
    }

    public function test_refuses_dead_capability_signal(): void
    {
        $out = $this->gate()->admit(
            ['originated' => true, 'target_metric' => 'capability', 'cited_facts' => ['evidence.tests=4']],
            ['attributed' => false, 'unattributed_dimensions' => []],
        );

        $this->assertFalse($out['admitted']);
        $this->assertSame('attribution_signal_dead', $out['refuse_reason']);
    }

    public function test_happy_path_admits(): void
    {
        $out = $this->gate()->admit(
            ['originated' => true, 'target_metric' => 'capability', 'cited_facts' => ['capability.real_dim=0.3']],
            ['attributed' => true, 'unattributed_dimensions' => ['some_other_noise']],
        );

        $this->assertTrue($out['admitted']);
        $this->assertNull($out['refuse_reason']);
        $this->assertSame([], $out['confounded_citations']);
    }
}
